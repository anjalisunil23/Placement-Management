<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\ReportModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;
use TCPDF;

/**
 * Excel report generation — campus-wide or department-scoped.
 */
final class ReportService
{
    private StudentModel $studentModel;
    private CompanyModel $companyModel;
    private ApplicationModel $applicationModel;
    private DepartmentModel $departmentModel;
    private UserModel $userModel;

    public function __construct()
    {
        $this->studentModel     = new StudentModel();
        $this->companyModel     = new CompanyModel();
        $this->applicationModel = new ApplicationModel();
        $this->departmentModel  = new DepartmentModel();
        $this->userModel        = new UserModel();
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    public function generate(string $type, ReportContext $ctx): array
    {
        return match ($type) {
            'student'    => $this->generateStudentReport($ctx),
            'department' => $this->generateDepartmentReport($ctx),
            'company'    => $this->generateCompanyReport($ctx),
            'monthly'    => $this->generateMonthlyReport($ctx),
            'annual'     => $this->generateAnnualReport($ctx),
            'selection'  => $this->generateSelectionReport($ctx),
            'applicants' => $this->generateApplicantsReport($ctx),
            default      => throw new \InvalidArgumentException('Invalid report type.'),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listHistory(?string $departmentId = null, int $limit = 50): array
    {
        return (new ReportModel())->listRecent($departmentId, $limit);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateStudentReport(ReportContext $ctx): array
    {
        $students = $this->studentModel->findAll($this->studentFilter($ctx), 1000);
        $headers = ['Register No', 'Name', 'Department', 'CGPA', 'Backlogs', 'Placed', 'Resume'];
        $rows = [];
        foreach ($students as $s) {
            $user = $this->userModel->findById((string) ($s['userId'] ?? ''));
            $dept = $this->departmentModel->findById((string) ($s['departmentId'] ?? ''));
            $resume = $s['resume'] ?? [];
            $rows[] = [
                $s['registerNumber'] ?? '',
                $user['name'] ?? '',
                $dept['code'] ?? $dept['name'] ?? 'N/A',
                (string) ($s['academic']['cgpa'] ?? 0),
                (string) ($s['academic']['backlogs'] ?? 0),
                ($s['placed'] ?? false) ? 'Yes' : 'No',
                !empty($resume['verified']) ? 'Verified' : (!empty($resume['path']) ? 'Pending' : 'Missing'),
            ];
        }

        $title = 'Student Placement Status Report';
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $title .= ' — ' . ($dept['name'] ?? $dept['code'] ?? '');
        }

        return $this->saveReport('student', $title, $headers, $rows, $ctx);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateDepartmentReport(ReportContext $ctx): array
    {
        $departments = $ctx->departmentId
            ? array_filter([$this->departmentModel->findById($ctx->departmentId)])
            : $this->departmentModel->findAll([], 100);

        $headers = ['Department', 'Code', 'Total Students', 'Placed', 'Placement %'];
        $rows = [];
        foreach ($departments as $dept) {
            if (!$dept) {
                continue;
            }
            $total = $this->studentModel->count(['departmentId' => $dept['_id']]);
            $placed = $this->studentModel->count(['departmentId' => $dept['_id'], 'placed' => true]);
            $rows[] = [
                $dept['name'] ?? '',
                $dept['code'] ?? '',
                (string) $total,
                (string) $placed,
                $total > 0 ? (string) round(($placed / $total) * 100, 1) . '%' : '0%',
            ];
        }

        return $this->saveReport('department', 'Department Placement Report', $headers, $rows, $ctx);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateCompanyReport(ReportContext $ctx): array
    {
        $studentIds = $this->scopedStudentObjectIds($ctx);
        $companies = $this->companyModel->findAll([], 200);

        $headers = ['Company', 'Category', 'Tier', 'Applications', 'Selected'];
        $rows = [];
        foreach ($companies as $c) {
            $appFilter = ['companyId' => $c['_id']];
            if ($studentIds !== null) {
                if ($studentIds === []) {
                    $rows[] = [$c['companyName'] ?? '', $c['category'] ?? '', $c['tier'] ?? '', '0', '0'];
                    continue;
                }
                $appFilter['studentId'] = ['$in' => $studentIds];
            }
            $rows[] = [
                $c['companyName'] ?? '',
                $c['category'] ?? '',
                $c['tier'] ?? '',
                (string) $this->applicationModel->count($appFilter),
                (string) $this->applicationModel->count(array_merge($appFilter, ['status' => 'selected'])),
            ];
        }

        $title = 'Company Recruitment Report';
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $title .= ' — ' . ($dept['code'] ?? '');
        }

        return $this->saveReport('company', $title, $headers, $rows, $ctx);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateMonthlyReport(ReportContext $ctx): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ctx->year, $ctx->month));
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        $apps = $this->applicationsInRange($start, $end, $ctx, ['selected', 'shortlisted', 'officer_approved']);
        $headers = ['Student', 'Register No', 'Company', 'Role/Drive', 'Status', 'Date'];
        $rows = $this->enrichApplicationRows($apps);

        $title = 'Monthly Placement Report — ' . $start->format('F Y');
        return $this->saveReport('monthly', $title, $headers, $rows ?: [['—', '—', '—', '—', 'No activity', '—']], $ctx);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateAnnualReport(ReportContext $ctx): array
    {
        $year = $ctx->year;
        $start = new \DateTimeImmutable("{$year}-01-01");
        $end = new \DateTimeImmutable("{$year}-12-31 23:59:59");

        $studentFilter = $this->studentFilter($ctx);
        $total = $this->studentModel->count($studentFilter);
        $placed = $this->studentModel->count(array_merge($studentFilter, ['placed' => true]));

        $apps = $this->applicationsInRange($start, $end, $ctx, ['selected']);
        $results = $this->scopedResults($ctx);

        $headers = ['Metric', 'Value'];
        $rows = [
            ['Placement Year', (string) $year],
            ['Total Students', (string) $total],
            ['Placed Students', (string) $placed],
            ['Placement Rate', $total > 0 ? round(($placed / $total) * 100, 1) . '%' : '0%'],
            ['Selections (applications)', (string) count($apps)],
            ['Recorded Results', (string) count($results)],
            ['Active Companies', (string) $this->companyModel->count([])],
        ];

        $title = "Annual Placement Report — FY {$year}";
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $title .= ' (' . ($dept['code'] ?? '') . ')';
        }

        return $this->saveReport('annual', $title, $headers, $rows, $ctx);
    }

    /**
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateSelectionReport(ReportContext $ctx): array
    {
        $studentIds = $this->scopedStudentObjectIds($ctx);
        $statuses = ['shortlisted', 'selected', 'rejected', 'company_review'];
        $headers = ['Status', 'Count'];
        $rows = [];

        foreach ($statuses as $status) {
            $filter = ['status' => $status];
            if ($studentIds !== null) {
                $filter['studentId'] = $studentIds === [] ? ['$in' => []] : ['$in' => $studentIds];
            }
            $rows[] = [ucfirst(str_replace('_', ' ', $status)), (string) $this->applicationModel->count($filter)];
        }

        $results = $this->scopedResults($ctx);
        $selected = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'selected'));
        $rejected = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'rejected'));
        $rows[] = ['Results — Selected', (string) $selected];
        $rows[] = ['Results — Rejected', (string) $rejected];

        $title = 'Selection Count Report';
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $title .= ' — ' . ($dept['code'] ?? '');
        }

        return $this->saveReport('selection', $title, $headers, $rows, $ctx);
    }

    /**
     * Applicants for one company (or all) — Excel roster with profile details and resume links.
     *
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function generateApplicantsReport(ReportContext $ctx): array
    {
        // Applicants roster is Excel-only.
        $ctx->format = 'xlsx';

        $company = null;
        $companyName = '';
        if ($ctx->companyId) {
            $company = $this->companyModel->findById($ctx->companyId);
            if (!$company) {
                throw new \InvalidArgumentException('Company not found.');
            }
            $companyName = trim((string) ($company['companyName'] ?? 'Company'));
        }

        $filter = [];
        $studentIds = $this->scopedStudentObjectIds($ctx);
        if ($studentIds !== null) {
            $filter['studentId'] = $studentIds === [] ? ['$in' => []] : ['$in' => $studentIds];
        }

        if ($ctx->companyId) {
            $apps = $this->applicationModel->findByCompany($ctx->companyId);
            if ($studentIds !== null) {
                $allow = array_flip($studentIds);
                $apps = array_values(array_filter($apps, static function (array $app) use ($allow): bool {
                    return isset($allow[(string) ($app['studentId'] ?? '')]);
                }));
            }
        } else {
            $apps = $this->applicationModel->findAll($filter, 5000);
        }
        $enriched = (new OfficerDataService())->enrichApplications($apps);

        usort($enriched, static function (array $a, array $b): int {
            $cmp = strcasecmp((string) ($a['role'] ?? ''), (string) ($b['role'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) ($a['status'] ?? ''), (string) ($b['status'] ?? ''));
        });

        $config = require dirname(__DIR__) . '/config/app.php';
        $appBase = rtrim((string) ($config['url'] ?? ''), '/');
        if ($appBase === '') {
            $appBase = 'http://localhost';
        }

        $headers = [
            'Company',
            'Drive / Role',
            'Student Name',
            'Register No',
            'Department',
            'Email',
            'Phone',
            'CGPA',
            'Status',
            'Shortlisted',
            'Selected',
            'Applied On',
            'Resume',
            'Resume Link',
        ];
        $rows = [];
        foreach ($enriched as $app) {
            $appId = (string) ($app['id'] ?? $app['_id'] ?? '');
            $status = strtolower(trim((string) ($app['status'] ?? '')));
            $isShortlisted = in_array($status, ['shortlisted', 'officer_approved', 'company_review', 'selected'], true);
            $isSelected = in_array($status, ['selected', 'placed'], true);
            $hasResume = !empty($app['hasResume']);
            $resumeName = (string) ($app['resumeFileName'] ?? $app['resumeLabel'] ?? '');
            if ($resumeName === '' && $hasResume) {
                $resumeName = 'Resume';
            }
            $resumeLink = '';
            if ($hasResume && $appId !== '') {
                $resumeLink = $appBase . '/backend/api/admin/applications/' . rawurlencode($appId) . '/resume';
            }

            $rows[] = [
                (string) ($app['company'] ?? $companyName),
                (string) ($app['role'] ?? ''),
                (string) ($app['studentName'] ?? ''),
                (string) ($app['registerNumber'] ?? ''),
                (string) ($app['department'] ?? ''),
                (string) ($app['email'] ?? $app['collegeEmail'] ?? ''),
                (string) ($app['phone'] ?? ''),
                isset($app['cgpa']) && (float) $app['cgpa'] > 0 ? (string) $app['cgpa'] : '',
                $this->formatApplicantStatus($status),
                $isShortlisted ? 'Yes' : 'No',
                $isSelected ? 'Yes' : 'No',
                self::formatDate($app['appliedAt'] ?? $app['createdAt'] ?? null),
                $hasResume ? ($resumeName !== '' ? $resumeName : 'Available') : 'Not uploaded',
                $resumeLink,
            ];
        }

        if ($rows === []) {
            $rows[] = [
                $companyName !== '' ? $companyName : '—',
                '—', '—', '—', '—', '—', '—', '—', 'No applicants', '—', '—', '—', '—', '—',
            ];
        }

        $title = $companyName !== ''
            ? "Applicants — {$companyName}"
            : 'Company Applicants Report';
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $title .= ' — ' . ($dept['code'] ?? $dept['name'] ?? '');
        }

        return $this->saveReport('applicants', $title, $headers, $rows, $ctx);
    }

    private function formatApplicantStatus(string $status): string
    {
        return match ($status) {
            'shortlisted' => 'Shortlisted',
            'selected' => 'Selected',
            'placed' => 'Placed',
            'officer_approved' => 'Officer approved',
            'company_review' => 'Company review',
            'rejected' => 'Rejected',
            'resume_pending', 'resume_verified', 'applied' => 'Applied',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    private function applicationsInRange(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ReportContext $ctx,
        array $statuses
    ): array {
        $filter = ['status' => ['$in' => $statuses]];
        $studentIds = $this->scopedStudentObjectIds($ctx);
        if ($studentIds !== null) {
            $filter['studentId'] = $studentIds === [] ? ['$in' => []] : ['$in' => $studentIds];
        }

        $apps = $this->applicationModel->findAll($filter, 2000);
        return array_values(array_filter($apps, function ($app) use ($start, $end) {
            $at = $app['updatedAt'] ?? $app['createdAt'] ?? null;
            $dt = self::parseDateTime($at);
            if ($dt === null) {
                return true;
            }
            return $dt >= $start && $dt <= $end;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $apps
     * @return array<int, string[]>
     */
    private function enrichApplicationRows(array $apps): array
    {
        $rows = [];
        foreach ($apps as $app) {
            $student = $this->studentModel->findById((string) ($app['studentId'] ?? ''));
            $user = $student ? $this->userModel->findById((string) ($student['userId'] ?? '')) : null;
            $company = $this->companyModel->findById((string) ($app['companyId'] ?? ''));
            $date = self::formatDate($app['updatedAt'] ?? $app['createdAt'] ?? null);

            $rows[] = [
                $user['name'] ?? '',
                $student['registerNumber'] ?? '',
                $company['companyName'] ?? '',
                (string) ($app['driveId'] ?? ''),
                (string) ($app['status'] ?? ''),
                $date,
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scopedResults(ReportContext $ctx): array
    {
        $results = (new RecruitmentResultModel())->list([], 1000);
        if (!$ctx->departmentId) {
            return $results;
        }

        $allowed = array_flip($this->registerNumbersForDepartment($ctx->departmentId));

        return array_values(array_filter(
            $results,
            fn ($r) => isset($allowed[strtoupper((string) ($r['registerNumber'] ?? ''))])
        ));
    }

    /**
     * @return string[]
     */
    private function registerNumbersForDepartment(?string $departmentId): array
    {
        if (!$departmentId) {
            return [];
        }
        return PlacementOfficerContext::registerNumbersInDepartment([
            'isAdmin'      => false,
            'departmentId' => $departmentId,
            'department'   => $this->departmentModel->findById($departmentId),
            'profile'      => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function studentFilter(ReportContext $ctx): array
    {
        if (!$ctx->departmentId) {
            return [];
        }
        $oid = Security::toObjectId($ctx->departmentId);
        return $oid ? ['departmentId' => $oid] : [];
    }

    /**
     * @return array<int, string>|null null = all students
     */
    private function scopedStudentObjectIds(ReportContext $ctx): ?array
    {
        if (!$ctx->departmentId) {
            return null;
        }
        $students = $this->studentModel->findAll($this->studentFilter($ctx), 5000);
        $ids = [];
        foreach ($students as $s) {
            if (!empty($s['_id'])) {
                $ids[] = (string) $s['_id'];
            }
        }
        return $ids;
    }

    private static function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function formatDate(mixed $value): string
    {
        $dt = self::parseDateTime($value);
        return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $rows
     * @return array{filename: string, format: string, title: string, downloadUrl: string}
     */
    private function saveReport(string $type, string $title, array $headers, array $rows, ReportContext $ctx): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';

        // Reports download as Excel only.
        $ext = 'xlsx';
        if (!class_exists(\ZipArchive::class)) {
            // Servers without ext-zip still get an Excel-compatible workbook.
            $ext = 'xls';
        }
        $filename = $type . '_report_' . date('Ymd_His') . '.' . $ext;
        $tmp = tempnam(sys_get_temp_dir(), 'pms_report_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp report file.');
        }
        $path = $tmp . '.' . $ext;
        @rename($tmp, $path);
        $uri = '';

        try {
            if ($ext === 'xlsx') {
                $this->writeXlsx($path, $title, $headers, $rows);
            } else {
                $this->writeExcelXml($path, $title, $headers, $rows);
            }

            $storage = new ObjectStorageService($config);
            try {
                $uri = $storage->putLocalFile(
                    ObjectStorageService::FOLDER_REPORTS,
                    $filename,
                    $path
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to save report to S3: ' . $e->getMessage(), 0, $e);
            }

            $storedName = $storage->storedNameFromUri($uri);
            if ($storedName === '') {
                $storedName = $filename;
            }

            // Keep a local copy so download works even if S3 retrieval fails.
            $localDir = dirname(__DIR__, 2) . '/uploads/reports';
            if (!is_dir($localDir) && !@mkdir($localDir, 0775, true) && !is_dir($localDir)) {
                throw new \RuntimeException('Unable to create local reports directory.');
            }
            $localPath = $localDir . DIRECTORY_SEPARATOR . $storedName;
            $copied = @copy($path, $localPath);
            if (!$copied && is_readable($path)) {
                $copied = @file_put_contents($localPath, (string) file_get_contents($path)) !== false;
            }
            if (!$copied || !is_file($localPath)) {
                throw new \RuntimeException('Report was uploaded but could not be saved locally for download.');
            }
        } finally {
            @unlink($path);
        }

        if ($uri === '' || !isset($storedName) || $storedName === '') {
            throw new \RuntimeException('Report was generated but could not be stored.');
        }

        (new ReportModel())->record([
            'type'         => $type,
            'title'        => $title,
            'filename'     => $storedName,
            'path'         => $uri,
            'format'       => $ext,
            'generatedBy'  => $ctx->generatedBy,
            'departmentId' => $ctx->departmentId,
            'filters'      => [
                'dateFrom' => $ctx->dateFrom,
                'dateTo'   => $ctx->dateTo,
                'month'    => $ctx->month,
                'year'     => $ctx->year,
                'companyId'=> $ctx->companyId,
            ],
        ]);

        return [
            'filename'    => $storedName,
            'format'      => $ext,
            'title'       => $title,
            'downloadUrl' => '/backend/api/admin/reports/download/' . rawurlencode($storedName),
        ];
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException('Unable to create CSV file.');
        }
        // UTF-8 BOM so Excel opens special characters correctly.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
    }

    /**
     * Minimal Office Open XML (.xlsx) workbook — opens natively in Microsoft Excel.
     *
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function writeXlsx(string $path, string $title, array $headers, array $rows): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Excel export requires the PHP zip extension.');
        }

        $sheetName = $this->sanitizeSheetName($title !== '' ? $title : 'Report');
        $sheetXml = $this->buildXlsxSheetXml($headers, $rows);

        $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

        $rels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

        $styles = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="2">
    <xf fontId="0" fillId="0" borderId="0"/>
    <xf fontId="1" fillId="0" borderId="0" applyFont="1"/>
  </cellXfs>
</styleSheet>
XML;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
    }

    /**
     * Excel 2003 SpreadsheetML (.xls) — works without the zip extension.
     *
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function writeExcelXml(string $path, string $title, array $headers, array $rows): void
    {
        $sheetName = htmlspecialchars($this->sanitizeSheetName($title !== '' ? $title : 'Report'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $esc = static function (string $value): string {
            return htmlspecialchars(
                str_replace(["\r\n", "\r"], "\n", $value),
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            );
        };

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles>'
            . '<Style ss:ID="Header"><Font ss:Bold="1"/></Style>'
            . '</Styles>'
            . '<Worksheet ss:Name="' . $sheetName . '"><Table>';

        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $esc((string) $header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($headers as $i => $_h) {
                $cell = (string) ($row[$i] ?? '');
                $type = is_numeric($cell) && !preg_match('/^0\d+/', $cell) ? 'Number' : 'String';
                $xml .= '<Cell><Data ss:Type="' . $type . '">' . $esc($cell) . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }

        $xml .= '</Table></Worksheet></Workbook>';

        if (file_put_contents($path, $xml) === false) {
            throw new \RuntimeException('Unable to create Excel file.');
        }
    }

    private function sanitizeSheetName(string $title): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $title) ?? 'Report';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? 'Report');
        if ($name === '') {
            $name = 'Report';
        }
        if (strlen($name) > 31) {
            $name = substr($name, 0, 31);
        }

        return $name;
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function buildXlsxSheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        $xml .= $this->xlsxRowXml(1, $headers, true);
        $rowNum = 2;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $i => $_h) {
                $cells[] = (string) ($row[$i] ?? '');
            }
            $xml .= $this->xlsxRowXml($rowNum, $cells, false);
            $rowNum++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param list<string> $values
     */
    private function xlsxRowXml(int $rowNum, array $values, bool $header): string
    {
        $xml = '<row r="' . $rowNum . '">';
        foreach ($values as $i => $value) {
            $col = $this->xlsxColumnLetter($i + 1);
            $ref = $col . $rowNum;
            $text = htmlspecialchars(
                str_replace(["\r\n", "\r"], "\n", (string) $value),
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            );
            $style = $header ? ' s="1"' : '';
            $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . $text . '</t></is></c>';
        }
        $xml .= '</row>';

        return $xml;
    }

    private function xlsxColumnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter !== '' ? $letter : 'A';
    }

    private function writePdf(string $path, string $html, string $title): void
    {
        $pdf = new TCPDF();
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetTitle($title);
        $pdf->AddPage();
        $pdf->writeHTML($html);
        $pdf->Output($path, 'F');
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $rows
     */
    private function buildTableHtml(string $title, array $headers, array $rows, ReportContext $ctx): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $th = implode('', array_map(fn ($h) => '<th>' . $esc($h) . '</th>', $headers));
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . $esc($c) . '</td>', $row)) . '</tr>';
        }

        $scope = '';
        if ($ctx->departmentId) {
            $dept = $this->departmentModel->findById($ctx->departmentId);
            $scope = '<p><strong>Department:</strong> ' . $esc($dept['name'] ?? $ctx->departmentId) . '</p>';
        }

        return '<h1>' . $esc($title) . '</h1>'
            . $scope
            . '<p>Generated: ' . date('Y-m-d H:i') . '</p>'
            . '<table border="1" cellpadding="4"><thead><tr>' . $th . '</tr></thead><tbody>'
            . $body . '</tbody></table>';
    }
}
