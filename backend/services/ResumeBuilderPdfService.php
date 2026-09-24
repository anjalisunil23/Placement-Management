<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Utils\Response;

/**
 * Resume Builder PDF export — Chromium print of the Live Preview HTML/CSS.
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
        $standalone = $this->wrapLivePreviewDocument($documentHtml);
        return $this->printHtmlToPdfWithChromium($standalone);
    }

    public function streamPdf(string $documentHtml, string $filename): void
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        if (!preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $safeName)) {
            $safeName = 'Resume.pdf';
        }

        $bytes = $this->renderPdfBytes($documentHtml);
        if ($bytes === '' || strncmp($bytes, '%PDF', 4) !== 0) {
            throw new \RuntimeException('Chromium did not return a PDF.');
        }

        if (!headers_sent()) {
            header_remove('Content-Type');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    /**
     * Wrap Live Preview article in a standalone A4 document that reuses resume-builder.css.
     */
    public function wrapLivePreviewDocument(string $documentHtml): string
    {
        $cssPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'resume-builder.css';
        $resumeCss = is_readable($cssPath) ? (string) file_get_contents($cssPath) : '';

        $wrapperCss = <<<'CSS'
@page { size: A4 portrait; margin: 0; }
html, body {
  margin: 0;
  padding: 0;
  background: #fff;
  width: 210mm;
  min-height: 297mm;
}
#resumeBuilderDashboard {
  margin: 0;
  padding: 0;
  background: #fff;
}
#resumeBuilderDashboard .rb-preview-paper {
  width: 210mm;
  min-height: 297mm;
  margin: 0;
  background: #fff;
  color: #000;
  box-shadow: none;
  border: 0;
}
CSS;

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Resume</title>'
            . '<style>' . $wrapperCss . "\n" . $resumeCss . '</style>'
            . '</head><body>'
            . '<div id="resumeBuilderDashboard">'
            . '<div class="rb-preview-paper">'
            . $documentHtml
            . '</div></div>'
            . '<script>document.fonts && document.fonts.ready && document.fonts.ready.then(function(){ document.documentElement.setAttribute("data-fonts-ready","1"); });</script>'
            . '</body></html>';
    }

    private function printHtmlToPdfWithChromium(string $html): string
    {
        $chrome = $this->resolveChromeBinary();
        if ($chrome === null || !function_exists('proc_open')) {
            error_log('Resume PDF: Chromium is not available; using TCPDF fallback.');
            return $this->printHtmlToPdfWithTcpdf($html);
        }

        try {
            return $this->runChromePrintToPdf($chrome, $html);
        } catch (\Throwable $e) {
            error_log('Resume PDF Chromium failed: ' . $e->getMessage() . ' — using TCPDF fallback.');
            return $this->printHtmlToPdfWithTcpdf($html);
        }
    }

    private function runChromePrintToPdf(string $chrome, string $html): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $htmlFile = $tempDir . DIRECTORY_SEPARATOR . 'pms-resume-' . bin2hex(random_bytes(8)) . '.html';
        $pdfFile = $tempDir . DIRECTORY_SEPARATOR . 'pms-resume-' . bin2hex(random_bytes(8)) . '.pdf';
        $userData = $tempDir . DIRECTORY_SEPARATOR . 'pms-chrome-' . bin2hex(random_bytes(6));

        if (file_put_contents($htmlFile, $html) === false) {
            throw new \RuntimeException('Could not write resume HTML for PDF export.');
        }

        try {
            if (!mkdir($userData, 0700) && !is_dir($userData)) {
                throw new \RuntimeException('Could not create Chromium profile.');
            }

            $htmlUrl = 'file:///' . str_replace(DIRECTORY_SEPARATOR, '/', $htmlFile);
            $cmd = [
                $chrome,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--allow-file-access-from-files',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--disable-background-networking',
                '--hide-scrollbars',
                '--virtual-time-budget=8000',
                '--run-all-compositor-stages-before-draw',
                '--no-pdf-header-footer',
                '--user-data-dir=' . $userData,
                '--print-to-pdf=' . $pdfFile,
                $htmlUrl,
            ];

            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptor, $pipes, $tempDir, null, [
                'bypass_shell' => true,
            ]);
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start Chromium.');
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0 && !is_file($pdfFile)) {
                throw new \RuntimeException('Chromium PDF export failed: ' . substr($stderr, 0, 400));
            }
            if (!is_readable($pdfFile)) {
                throw new \RuntimeException('Chromium PDF was not created.');
            }
            $bytes = (string) file_get_contents($pdfFile);
            if ($bytes === '') {
                throw new \RuntimeException('Chromium PDF was empty.');
            }
            return $bytes;
        } finally {
            if (is_file($htmlFile)) {
                @unlink($htmlFile);
            }
            if (is_file($pdfFile)) {
                @unlink($pdfFile);
            }
            $this->removeDirectory($userData);
        }
    }

    private function printHtmlToPdfWithTcpdf(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetTitle('Resume');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->AddPage();
        $pdf->SetFont('times', '', 10.5);
        $pdf->writeHTML($html, true, false, true, false, '');
        $bytes = $pdf->Output('', 'S');
        if ($bytes === '' || strncmp($bytes, '%PDF', 4) !== 0) {
            throw new \RuntimeException('TCPDF did not return a PDF.');
        }
        return $bytes;
    }

    private function resolveChromeBinary(): ?string
    {
        $candidates = [];
        $configPath = trim((string) ($_ENV['CHROME_PATH'] ?? getenv('CHROME_PATH') ?: ''));
        if ($configPath !== '') {
            $candidates[] = $configPath;
        }

        $home = (string) (getenv('LOCALAPPDATA') ?: getenv('HOME') ?: '');
        $candidates = array_merge($candidates, [
            $home . '\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
        ]);

        $usersRoot = (getenv('SystemDrive') ?: 'C:') . '\\Users';
        if (is_dir($usersRoot)) {
            foreach (glob($usersRoot . '\\*\\AppData\\Local\\Google\\Chrome\\Application\\chrome.exe') ?: [] as $userChrome) {
                $candidates[] = $userChrome;
            }
        }

        foreach ($candidates as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
            if ($path !== '' && is_file($path) && str_ends_with(strtolower($path), '.exe')) {
                return $path;
            }
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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

        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
        foreach ([$personal['fullName'] ?? '', $profile['name'] ?? ''] as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
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
}
