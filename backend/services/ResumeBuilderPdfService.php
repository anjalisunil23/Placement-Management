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
     * PDF eligibility follows the submitted preview HTML, not the 7/8 completion score.
     * Minimum: name, contact line, and an education section.
     */
    public function assertGenerationAllowed(string $documentHtml): void
    {
        if (!$this->documentMeetsPdfMinimum($documentHtml)) {
            Response::error(
                'Please complete your basic profile and education details before generating your resume.',
                422
            );
        }
    }

    private function documentMeetsPdfMinimum(string $html): bool
    {
        $hasName = (bool) preg_match('/class="rb-resume-name"[^>]*>\s*[^<\s]/', $html);
        $hasContact = (bool) preg_match('/class="rb-resume-contact"[^>]*>\s*[^<\s]/', $html);
        $hasEducation = (bool) preg_match('/class="rb-resume-h2"[^>]*>\s*Education\s*<\/h2>/i', $html)
            || str_contains($html, 'class="rb-resume-edu"');

        return $hasName && $hasContact && $hasEducation;
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

    public function renderPdfBytes(string $documentHtml): string
    {
        $pdfHtml = $this->prepareDocumentHtmlForPdf($documentHtml);
        $pdf = $this->createPdfInstance();
        $pdf->AddPage();
        $pdf->writeHTML($this->wrapDocumentHtml($pdfHtml), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public function streamPdf(string $documentHtml, string $filename): void
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        if (!preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $safeName)) {
            $safeName = 'Resume.pdf';
        }

        if (!headers_sent()) {
            header_remove('Content-Type');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $this->renderPdfBytes($documentHtml);
        exit;
    }

    private function createPdfInstance(): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetAuthor('PlaceHub PMS');
        $pdf->SetTitle('Resume');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);
        $pdf->setCellHeightRatio(1.35);
        $zeroVSpace = ['h' => 0, 'n' => 0];
        $pdf->setHtmlVSpace([
            'table' => [0 => $zeroVSpace, 1 => $zeroVSpace],
            'tr' => [0 => $zeroVSpace, 1 => $zeroVSpace],
            'td' => [0 => $zeroVSpace, 1 => $zeroVSpace],
            'thead' => [0 => $zeroVSpace, 1 => $zeroVSpace],
            'tbody' => [0 => $zeroVSpace, 1 => $zeroVSpace],
        ]);
        $pdf->SetFont('times', '', 10);

        return $pdf;
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

    /**
     * TCPDF cannot render flexbox rows from Live Preview — convert to table rows invisibly.
     */
    private function prepareDocumentHtmlForPdf(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return $this->prepareDocumentHtmlForPdfRegex($html);
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="rb-pdf-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        if (!$loaded) {
            return $this->prepareDocumentHtmlForPdfRegex($html);
        }

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//hr[contains(@class,"rb-resume-rule")]') as $hr) {
            if (!$hr instanceof \DOMElement || !$hr->parentNode instanceof \DOMNode) {
                continue;
            }
            $div = $dom->createElement('div');
            $div->setAttribute('class', 'rb-resume-rule');
            $hr->parentNode->replaceChild($div, $hr);
        }

        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " rb-resume-edu ")]') as $edu) {
            if ($edu instanceof \DOMElement) {
                $this->replaceEducationBlockWithTable($dom, $edu);
            }
        }

        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " rb-resume-entry-top ")]') as $row) {
            if ($row instanceof \DOMElement) {
                $this->replaceFlexRowWithTable($dom, $row);
            }
        }

        foreach ($xpath->query('//section') as $section) {
            if (!$section instanceof \DOMElement || !$section->parentNode instanceof \DOMNode) {
                continue;
            }
            $div = $dom->createElement('div');
            $class = trim($section->getAttribute('class'));
            if ($class !== '') {
                $div->setAttribute('class', $class);
            } else {
                $div->setAttribute('class', 'rb-resume-section');
            }
            while ($section->firstChild) {
                $div->appendChild($section->firstChild);
            }
            $section->parentNode->replaceChild($div, $section);
        }

        $root = $dom->getElementById('rb-pdf-root');
        if (!$root) {
            return $this->prepareDocumentHtmlForPdfRegex($html);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private function replaceEducationBlockWithTable(\DOMDocument $dom, \DOMElement $edu): void
    {
        $rowElements = [];
        foreach ($edu->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $class = ' ' . trim($child->getAttribute('class')) . ' ';
            if (!str_contains($class, ' rb-resume-edu-row ')) {
                continue;
            }
            $columns = [];
            foreach ($child->childNodes as $column) {
                if ($column instanceof \DOMElement) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $rowElements[] = $columns;
            }
        }

        if ($rowElements === [] || !$edu->parentNode instanceof \DOMNode) {
            return;
        }

        $table = $dom->createElement('table');
        $table->setAttribute('class', 'rb-resume-edu rb-resume-edu-table');
        $this->applyInvisibleTableAttributes($table);

        foreach ($rowElements as $columns) {
            $tr = $dom->createElement('tr');
            $this->applyInvisibleTrAttributes($tr);
            $table->appendChild($tr);
            $tr->appendChild($this->createInvisibleTdFromElement($dom, $columns[0], '72%'));
            if (isset($columns[1])) {
                $tr->appendChild($this->createInvisibleTdFromElement($dom, $columns[1], '28%', true));
            }
        }

        $edu->parentNode->replaceChild($table, $edu);
    }

    private function replaceFlexRowWithTable(\DOMDocument $dom, \DOMElement $row): void
    {
        $children = [];
        foreach ($row->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === [] || !$row->parentNode instanceof \DOMNode) {
            return;
        }

        $table = $dom->createElement('table');
        $table->setAttribute('class', 'rb-resume-row');
        $this->applyInvisibleTableAttributes($table);

        $tr = $dom->createElement('tr');
        $this->applyInvisibleTrAttributes($tr);
        $table->appendChild($tr);
        $tr->appendChild($this->createInvisibleTdFromElement($dom, $children[0], '72%'));

        if (isset($children[1])) {
            $tr->appendChild($this->createInvisibleTdFromElement($dom, $children[1], '28%', true));
        }

        $row->parentNode->replaceChild($table, $row);
    }

    private function applyInvisibleTableAttributes(\DOMElement $table): void
    {
        $table->setAttribute('cellpadding', '0');
        $table->setAttribute('cellspacing', '0');
        $table->setAttribute('width', '100%');
        $table->setAttribute('border', '0');
        $this->appendInlineStyle($table, 'border:none;border-collapse:collapse;margin:0;padding:0;background:transparent;');
    }

    private function applyInvisibleTrAttributes(\DOMElement $tr): void
    {
        $this->appendInlineStyle($tr, 'border:none;margin:0;padding:0;background:transparent;');
    }

    private function createInvisibleTdFromElement(
        \DOMDocument $dom,
        \DOMElement $source,
        string $width,
        bool $rightAlign = false
    ): \DOMElement {
        $td = $dom->createElement('td');
        $td->setAttribute('width', $width);
        $td->setAttribute('valign', 'top');
        if ($rightAlign) {
            $td->setAttribute('align', 'right');
        }
        $class = trim($source->getAttribute('class'));
        if ($rightAlign) {
            $class = trim($class . ' rb-resume-right');
        }
        if ($class !== '') {
            $td->setAttribute('class', $class);
        }
        $style = 'border:none;outline:none;margin:0;padding:0;vertical-align:top;background:transparent;';
        if ($rightAlign) {
            $style .= 'text-align:right;white-space:nowrap;';
        }
        $this->appendInlineStyle($td, $style);
        while ($source->firstChild) {
            $td->appendChild($source->firstChild);
        }

        return $td;
    }

    private function appendInlineStyle(\DOMElement $element, string $style): void
    {
        $existing = trim($element->getAttribute('style'));
        $element->setAttribute('style', $existing === '' ? $style : $existing . $style);
    }

    private function prepareDocumentHtmlForPdfRegex(string $html): string
    {
        $html = preg_replace('/<hr class="rb-resume-rule"[^>]*\/?>/', '<div class="rb-resume-rule"></div>', $html) ?? $html;

        $invisibleTable = 'style="border:none;border-collapse:collapse;margin:0;padding:0;background:transparent;" cellpadding="0" cellspacing="0" width="100%" border="0"';
        $invisibleTr = 'style="border:none;margin:0;padding:0;background:transparent;"';
        $invisibleTd = 'style="border:none;outline:none;margin:0;padding:0;vertical-align:top;background:transparent;"';
        $invisibleTdRight = 'style="border:none;outline:none;margin:0;padding:0;vertical-align:top;background:transparent;text-align:right;white-space:nowrap;"';

        $html = preg_replace_callback(
            '/<div class="rb-resume-edu">\s*((?:<div class="rb-resume-edu-row">.*?<\/div>\s*)+)<\/div>/s',
            static function (array $m) use ($invisibleTable, $invisibleTr, $invisibleTd, $invisibleTdRight): string {
                $rowsHtml = '';
                if (preg_match_all(
                    '/<div class="rb-resume-edu-row">\s*<div class="([^"]+)">(.*?)<\/div>\s*<div class="([^"]+)">(.*?)<\/div>\s*<\/div>/s',
                    $m[1],
                    $rows,
                    PREG_SET_ORDER
                )) {
                    foreach ($rows as $row) {
                        $rowsHtml .= '<tr ' . $invisibleTr . '>'
                            . '<td class="' . $row[1] . '" ' . $invisibleTd . ' width="72%" valign="top">' . $row[2] . '</td>'
                            . '<td class="' . $row[3] . ' rb-resume-right" ' . $invisibleTdRight . ' width="28%" align="right" valign="top">' . $row[4] . '</td>'
                            . '</tr>';
                    }
                }

                return '<table class="rb-resume-edu rb-resume-edu-table" ' . $invisibleTable . '>' . $rowsHtml . '</table>';
            },
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/<div class="rb-resume-entry-top">\s*<div class="rb-resume-entry-title">(.*?)<\/div>\s*<div class="([^"]+)">(.*?)<\/div>\s*<\/div>/s',
            static function (array $m) use ($invisibleTable, $invisibleTr, $invisibleTd, $invisibleTdRight): string {
                return '<table class="rb-resume-row" ' . $invisibleTable . '><tr ' . $invisibleTr . '>'
                    . '<td class="rb-resume-entry-title" ' . $invisibleTd . ' width="72%" valign="top">' . $m[1] . '</td>'
                    . '<td class="' . $m[2] . ' rb-resume-right" ' . $invisibleTdRight . ' width="28%" align="right" valign="top">' . $m[3] . '</td>'
                    . '</tr></table>';
            },
            $html
        ) ?? $html;
    }

    private function wrapDocumentHtml(string $documentHtml): string
    {
        $css = <<<'CSS'
body { margin: 0; padding: 0; color: #000000; }
.rb-resume-doc { font-family: times; font-size: 10.5pt; line-height: 1.35; color: #000000; }
.rb-resume-header { text-align: center; margin: 0 0 6pt 0; padding: 0; }
.rb-resume-name { margin: 0 0 2pt 0; padding: 0; font-size: 26pt; font-weight: bold; line-height: 1.12; color: #000000; }
.rb-resume-contact { margin: 0; padding: 0; font-size: 10.5pt; line-height: 1.4; color: #000000; }
.rb-resume-section { margin: 8pt 0 0 0; padding: 0; }
.rb-resume-h2 { margin: 0; padding: 0; font-size: 12pt; font-weight: bold; letter-spacing: 1.5pt; text-transform: uppercase; line-height: 1.2; color: #000000; }
.rb-resume-rule { margin: 1pt 0 3pt 0; padding: 0; height: 0; line-height: 0; font-size: 0; border-top: 0.75pt solid #000000; }
.rb-resume-right-bold { font-weight: bold; }
.rb-resume-para { margin: 0; padding: 0; font-size: 10.5pt; line-height: 1.38; color: #000000; text-align: justify; }
.rb-resume-bullets, .rb-resume-list { margin: 1pt 0 0 0; padding-left: 12pt; }
.rb-resume-bullets li, .rb-resume-list li { margin: 0; padding: 0 0 1pt 0; font-size: 10.5pt; line-height: 1.35; color: #000000; }
.rb-resume-edu, table.rb-resume-edu-table { margin: 0 0 3pt 0; padding: 0; }
.rb-resume-edu-degree, .rb-resume-entry-title { font-weight: bold; font-size: 10.5pt; line-height: 1.3; color: #000000; }
.rb-resume-edu-inst, .rb-resume-entry-org { font-size: 10pt; font-style: italic; font-weight: normal; line-height: 1.3; color: #000000; }
.rb-resume-edu-year, .rb-resume-entry-right { font-size: 10.5pt; font-weight: bold; color: #000000; }
.rb-resume-edu-score { font-size: 10pt; font-style: normal; font-weight: normal; color: #000000; }
.rb-resume-entry { margin: 0 0 3pt 0; padding: 0; }
.rb-resume-tech { margin: 0; padding: 0; font-size: 10pt; font-style: italic; color: #000000; }
.rb-resume-strong { font-weight: bold; }
.rb-resume-skill-line { margin: 0; padding: 0; font-size: 10.5pt; line-height: 1.35; color: #000000; }
.rb-resume-skill-label { font-weight: bold; }
table.rb-resume-edu-table, table.rb-resume-row { width: 100%; border-collapse: collapse; margin: 0; padding: 0; border: none; background: transparent; }
table.rb-resume-edu-table tr, table.rb-resume-row tr { margin: 0; padding: 0; border: none; background: transparent; }
table.rb-resume-edu-table td, table.rb-resume-row td { vertical-align: top; margin: 0; padding: 0; border: none; outline: none; background: transparent; }
table.rb-resume-edu-table td.rb-resume-right, table.rb-resume-row td.rb-resume-right { text-align: right; white-space: nowrap; }
CSS;

        return '<style>' . $css . '</style><body>' . $documentHtml . '</body>';
    }
}
