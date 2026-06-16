<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;
use TCPDF;

/**
 * PDF report generation service.
 */
final class ReportService
{
    private StudentModel $studentModel;
    private CompanyModel $companyModel;
    private ApplicationModel $applicationModel;

    public function __construct()
    {
        $this->studentModel     = new StudentModel();
        $this->companyModel     = new CompanyModel();
        $this->applicationModel = new ApplicationModel();
    }

    public function generateStudentReport(array $filters = []): string
    {
        $students = $this->studentModel->findAll($filters, 500);
        $html = $this->buildTableHtml('Student Placement Report', [
            'Register No', 'CGPA', 'Backlogs', 'Placed', 'Department',
        ], array_map(function ($s) {
            $deptModel = new DepartmentModel();
            $dept = $deptModel->findById((string) ($s['departmentId'] ?? ''));
            return [
                $s['registerNumber'] ?? '',
                (string) ($s['academic']['cgpa'] ?? 0),
                (string) ($s['academic']['backlogs'] ?? 0),
                ($s['placed'] ?? false) ? 'Yes' : 'No',
                $dept['name'] ?? 'N/A',
            ];
        }, $students));

        return $this->savePdf('student_report', $html);
    }

    public function generateCompanyReport(): string
    {
        $companies = $this->companyModel->findAll([], 200);
        $html = $this->buildTableHtml('Company Report', [
            'Company', 'Category', 'Tier', 'Status',
        ], array_map(fn ($c) => [
            $c['companyName'] ?? '',
            $c['category'] ?? '',
            $c['tier'] ?? '',
            $c['associationStatus'] ?? '',
        ], $companies));

        return $this->savePdf('company_report', $html);
    }

    public function generateMonthlyReport(int $month, int $year): string
    {
        $start = new \DateTimeImmutable("{$year}-{$month}-01");
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        $allSelected = $this->applicationModel->findAll(['status' => 'selected'], 1000);
        $apps = array_filter($allSelected, function ($app) use ($start, $end) {
            foreach (array_reverse($app['timeline'] ?? []) as $entry) {
                if (($entry['status'] ?? '') !== 'selected') {
                    continue;
                }
                $at = $entry['at'] ?? null;
                if ($at instanceof \MongoDB\BSON\UTCDateTime) {
                    $dt = $at->toDateTime();
                    return $dt >= $start && $dt <= $end;
                }
            }
            return false;
        });

        $rows = array_map(fn ($a) => [
            (string) ($a['studentId'] ?? ''),
            (string) ($a['companyId'] ?? ''),
            (string) ($a['driveId'] ?? ''),
        ], array_values($apps));

        $html = $this->buildTableHtml(
            'Monthly Placement Report — ' . $start->format('F Y'),
            ['Student ID', 'Company ID', 'Drive ID'],
            $rows ?: [['—', '—', '0 selections']]
        );

        return $this->savePdf("monthly_{$year}_{$month}", $html);
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function buildTableHtml(string $title, array $headers, array $rows): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $th = implode('', array_map(fn ($h) => '<th>' . $esc($h) . '</th>', $headers));
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . $esc($c) . '</td>', $row)) . '</tr>';
        }
        return '<h1>' . $esc($title) . '</h1><p>Generated: ' . date('Y-m-d H:i') . '</p>
            <table border="1" cellpadding="4"><thead><tr>' . $th . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function savePdf(string $prefix, string $html): string
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $dir = $config['uploads']['reports_dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $prefix . '_' . date('Ymd_His') . '.pdf';
        $path = $dir . '/' . $filename;

        $pdf = new TCPDF();
        $pdf->SetCreator('PMS');
        $pdf->SetTitle('Placement Report');
        $pdf->AddPage();
        $pdf->writeHTML($html);
        $pdf->Output($path, 'F');

        // Store report record
        Database::collection(Collections::REPORTS)->insertOne([
            'type'        => $prefix,
            'filename'    => $filename,
            'path'        => $path,
            'generatedBy' => null,
            'filters'     => [],
            'createdAt'   => DocumentHelper::now(),
        ]);

        return $filename;
    }
}
