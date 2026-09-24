<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use TCPDF;

/**
 * Resume Builder PDF export — converts Jake-style preview HTML to PDF via TCPDF.
 */
final class ResumeBuilderPdfService
{
    private StudentModel $studentModel;

    public function __construct(?StudentModel $studentModel = null)
    {
        $this->studentModel = $studentModel ?? new StudentModel();
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function assertGenerationAllowed(array $profile): void
    {
        if (!$this->isPersonalComplete($profile)) {
            Response::error('Complete required sections first.', 422);
        }
        if ($this->extractEducationRows($profile) === []) {
            Response::error('Complete required sections first.', 422);
        }
    }

    public function buildFilename(array $profile): string
    {
        $fullName = $this->extractFullName($profile);
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            $first = $this->sanitizeFilenamePart($parts[0]);
            $last = $this->sanitizeFilenamePart($parts[count($parts) - 1]);
            if ($first !== '' && $last !== '') {
                return $first . '_' . $last . '_Resume.pdf';
            }
        }
        if (count($parts) === 1) {
            $single = $this->sanitizeFilenamePart($parts[0]);
            if ($single !== '') {
                return $single . '_Resume.pdf';
            }
        }

        return 'Resume.pdf';
    }

    public function sanitizeDocumentHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            Response::error('Resume content is required.', 422);
        }
        if (strlen($html) > 250000) {
            Response::error('Resume content is too large.', 422);
        }
        if (!preg_match('/rb-resume-(doc|header|section|empty-doc)/', $html)) {
            Response::error('Invalid resume content.', 422);
        }
        if (str_contains($html, 'rb-resume-empty-doc')) {
            Response::error('Add resume content before generating a PDF.', 422);
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/i', '', $html) ?? $html;
        $html = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:.*?\2/i', '', $html) ?? $html;

        return $html;
    }

    public function streamPdf(string $documentHtml, string $filename): void
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        if (!preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $safeName)) {
            $safeName = 'Resume.pdf';
        }

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetAuthor('PlaceHub PMS');
        $pdf->SetTitle('Resume');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->writeHTML($this->wrapDocumentHtml($documentHtml), true, false, true, false, '');

        if (!headers_sent()) {
            header_remove('Content-Type');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf->Output($safeName, 'S');
        exit;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function extractFullName(array $profile): string
    {
        $user = is_array($profile['user'] ?? null) ? $profile['user'] : [];
        foreach ([
            $user['stud_name'] ?? '',
            $user['name'] ?? '',
            $profile['displayName'] ?? '',
            $profile['stud_name'] ?? '',
        ] as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isPersonalComplete(array $profile): bool
    {
        $user = is_array($profile['user'] ?? null) ? $profile['user'] : [];
        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
        $dept = null;
        $deptId = trim((string) ($profile['departmentId'] ?? ''));
        if ($deptId !== '') {
            $dept = (new DepartmentModel())->findById($deptId);
        }

        $fullName = $this->extractFullName($profile);
        $registerNumber = trim((string) ($profile['registerNumber'] ?? ''));
        $collegeEmail = trim((string) ($user['collegeEmail'] ?? $user['email'] ?? ''));
        $mobile = trim((string) ($personal['phone'] ?? $user['phone'] ?? ''));
        $department = '';
        if (is_array($dept)) {
            $department = trim((string) ($dept['name'] ?? $dept['code'] ?? ''));
        }
        if ($department === '') {
            $department = trim((string) ($profile['departmentName'] ?? $profile['programme'] ?? ''));
        }

        return $fullName !== ''
            && $registerNumber !== ''
            && $collegeEmail !== ''
            && $mobile !== ''
            && $department !== '';
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<array<string, mixed>>
     */
    private function extractEducationRows(array $profile): array
    {
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $rawRows = [];
        if (is_array($profile['qualifications'] ?? null) && $profile['qualifications'] !== []) {
            $rawRows = $profile['qualifications'];
        } elseif (is_array($academic['qualifications'] ?? null)) {
            $rawRows = $academic['qualifications'];
        }

        $rows = [];
        foreach ($rawRows as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $qualification = trim((string) ($raw['qualification'] ?? $raw['qual'] ?? $raw['degree'] ?? ''));
            $institution = trim((string) ($raw['institution'] ?? $raw['instname'] ?? $raw['inst_name'] ?? ''));
            $university = trim((string) ($raw['university'] ?? $raw['board'] ?? ''));
            $year = trim((string) ($raw['monthYear'] ?? $raw['monthyear'] ?? $raw['passedYear'] ?? $raw['year'] ?? ''));
            $score = $this->formatEducationScore($raw);
            if ($qualification === '' && $institution === '' && $university === '' && $year === '' && $score === '') {
                continue;
            }
            $rows[] = [
                'qualification' => $qualification,
                'institution' => $institution,
                'university' => $university,
                'year' => $year,
                'score' => $score,
            ];
        }

        if ($rows !== []) {
            return $rows;
        }

        $program = trim((string) ($profile['programme'] ?? $profile['program'] ?? $academic['course'] ?? 'Current Degree'));
        $cgpa = (float) ($profile['cgpa'] ?? $academic['cgpa'] ?? 0);
        $marks12 = (float) ($academic['marks12th'] ?? $profile['marks12th'] ?? $academic['ugMarks'] ?? 0);
        $marks10 = (float) ($academic['marks10th'] ?? $profile['marks10th'] ?? 0);

        if ($cgpa > 0 && $cgpa <= 10) {
            $rows[] = ['qualification' => $program, 'score' => $this->formatEducationScore(['mark' => $cgpa, 'maxMark' => 10])];
        }
        if ($marks12 > 0 && $marks12 <= 100) {
            $rows[] = ['qualification' => 'Plus Two / Higher Secondary', 'score' => round($marks12, 2) . '%'];
        }
        if ($marks10 > 0 && $marks10 <= 100) {
            $rows[] = ['qualification' => 'SSLC / 10th', 'score' => round($marks10, 2) . '%'];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatEducationScore(array $row): string
    {
        $mark = isset($row['mark']) && is_numeric($row['mark']) ? (float) $row['mark'] : 0.0;
        $maxMark = isset($row['maxMark']) && is_numeric($row['maxMark'])
            ? (float) $row['maxMark']
            : (isset($row['maxmark']) && is_numeric($row['maxmark']) ? (float) $row['maxmark'] : 0.0);
        $pct = isset($row['percentage']) && is_numeric($row['percentage']) ? (float) $row['percentage'] : 0.0;

        if ($mark > 0 && $mark <= 10 && ($maxMark <= 0 || $maxMark <= 10)) {
            $shown = fmod($mark, 1.0) === 0.0 ? (string) (int) $mark : (string) round($mark, 2);

            return $shown . ' CGPA';
        }
        if ($pct > 0 && $pct <= 100) {
            return (string) round($pct, 2) . '%';
        }
        if ($mark > 0 && $maxMark > 0) {
            return (string) round(($mark / $maxMark) * 100, 2) . '%';
        }
        if ($mark > 0) {
            return (string) $mark;
        }

        return '';
    }

    private function sanitizeFilenamePart(string $value): string
    {
        $clean = preg_replace('/[^\w.-]+/u', '_', trim($value)) ?? '';
        $clean = preg_replace('/_+/', '_', $clean) ?? '';
        $clean = trim($clean, '_');

        return $clean;
    }

    private function wrapDocumentHtml(string $documentHtml): string
    {
        $css = <<<'CSS'
body { margin: 0; padding: 0; color: #000; }
.rb-resume-doc { font-family: "times"; font-size: 10.5pt; line-height: 1.35; color: #000; }
.rb-resume-header { text-align: center; margin: 0 0 4mm; }
.rb-resume-name { margin: 0 0 1mm; font-size: 26pt; font-weight: bold; line-height: 1.12; color: #000; }
.rb-resume-contact { margin: 0; font-size: 10.5pt; line-height: 1.4; color: #000; }
.rb-resume-section { margin-top: 3mm; }
.rb-resume-h2 { margin: 0; font-size: 12pt; font-weight: bold; letter-spacing: 0.14em; text-transform: uppercase; line-height: 1.2; color: #000; }
.rb-resume-rule { margin: 0.5mm 0 1.5mm; border-top: 0.35mm solid #000; height: 0; }
.rb-resume-right-bold { font-weight: bold; }
.rb-resume-para { margin: 0; font-size: 10.5pt; line-height: 1.38; color: #000; text-align: justify; }
.rb-resume-bullets, .rb-resume-list { margin: 0.5mm 0 0; padding-left: 4mm; }
.rb-resume-bullets li, .rb-resume-list li { margin: 0 0 0.5mm; font-size: 10.5pt; line-height: 1.35; color: #000; }
.rb-resume-edu { margin-bottom: 1.5mm; }
.rb-resume-edu-row, .rb-resume-entry-top { width: 100%; }
.rb-resume-edu-degree, .rb-resume-entry-title { font-weight: bold; font-size: 10.5pt; line-height: 1.3; }
.rb-resume-edu-inst, .rb-resume-entry-org { font-size: 10pt; font-style: italic; line-height: 1.3; color: #000; }
.rb-resume-edu-year, .rb-resume-edu-score, .rb-resume-entry-right { font-size: 10.5pt; text-align: right; white-space: nowrap; }
.rb-resume-edu-score { font-size: 10pt; font-style: normal; font-weight: normal; }
.rb-resume-entry { margin-bottom: 1.5mm; }
.rb-resume-tech { font-size: 10pt; font-style: italic; color: #000; }
.rb-resume-strong { font-weight: bold; }
.rb-resume-skill-line { font-size: 10.5pt; line-height: 1.35; color: #000; }
.rb-resume-skill-label { font-weight: bold; }
table.rb-resume-row { width: 100%; border-collapse: collapse; margin: 0; padding: 0; }
table.rb-resume-row td { vertical-align: top; padding: 0; border: 0; }
table.rb-resume-row td.rb-resume-right { text-align: right; white-space: nowrap; }
CSS;

        return '<style>' . $css . '</style><body>' . $documentHtml . '</body>';
    }
}
