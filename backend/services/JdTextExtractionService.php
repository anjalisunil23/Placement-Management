<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Utils\Security;

/**
 * Extract plain text from pasted JD, PDF, or image uploads.
 */
final class JdTextExtractionService
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_TEXT_CHARS = 50000;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /** @var list<string> */
    private const MANUAL_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'txt'];

    private const MANUAL_APTITUDE_OCR_PROMPT =
        'Transcribe this aptitude test page as plain text only (no markdown). '
        . 'Do not include page headers, footers, watermarks, branding lines, website URLs, or page numbers. '
        . 'Include every question number, all option labels (a) b) c) … or A. B. …), and marked answers if visible. '
        . 'For letter/number/symbol arrangement lines, copy each character exactly as printed, preserving spaces between tokens. '
        . 'Use the exact symbols printed (© # $ ₹ % @ & * ( ) + − =, etc.) and every letter/digit — do not substitute look-alikes '
        . '(for example do not replace © with @, or guess a currency symbol). '
        . 'Preserve "Directions (N - M):" blocks and line breaks between questions. No commentary.';

    public function __construct(
        private ?OpenAIService $openai = null
    ) {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @return array{text:string,filename:?string,method:string}
     */
    public function extractFromUpload(array $file): array
    {
        $error = Security::validateUploadedFile($file, self::MAX_FILE_BYTES, self::ALLOWED_EXTENSIONS);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $name = basename((string) ($file['name'] ?? 'jd-upload'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $text = match ($ext) {
            'pdf' => $this->extractPdfText($tmp),
            'jpg', 'jpeg', 'png' => $this->extractImageText($tmp, $ext),
            default => throw new \InvalidArgumentException('Unsupported file type.'),
        };

        $text = $this->sanitizeText($text);
        if ($text === '') {
            throw new \RuntimeException(
                'Unable to extract text from this file. Please upload a clearer PDF/image or paste the JD text manually.'
            );
        }

        $stored = $this->persistUploadedFile($file, $name, $ext);

        return array_merge([
            'text' => $text,
            'filename' => $name,
            'method' => $ext === 'pdf' ? 'pdf' : 'ocr',
        ], $stored);
    }

    /**
     * @return array<string, string>
     */
    private function persistUploadedFile(array $file, string $originalName, string $ext): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $storedName = 'apt_jd_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $uri = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_JD,
                $storedName,
                $file
            );
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude JD] file store failed: ' . $e->getMessage());

            return [];
        }

        $filename = $storage->storedNameFromUri($uri);
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };

        return [
            'jdFile' => $uri,
            'jdFileUrl' => $storage->mediaUrl(ObjectStorageService::FOLDER_JD, $filename),
            'jdMimeType' => $mime,
        ];
    }

    public function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (self::isGarbledExtract($text)) {
            return '';
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }

        return $text;
    }

    /** Preserve line breaks for aptitude manual / OCR parsing. */
    public function sanitizeManualText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/```+/u', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = self::stripManualPageHeaderFooter($text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);
        // Flat OCR blobs: "… hour? a) 6% b) 2% … 2) Find …"
        $text = preg_replace('/(\s)(\d{1,3}\)\s+(?=[A-Za-z(]))/u', "\n$2", $text) ?? $text;
        $text = preg_replace('/(\s)(Directions\s*\(\d+\s*-\s*\d+\)\s*:)/iu', "\n\n$2", $text) ?? $text;
        $text = preg_replace('/(\s)(Directions\s*:)/iu', "\n\n$2", $text) ?? $text;
        // Keep section topic headings on their own line (not glued to option E).
        $text = preg_replace(
            '/(\S)\s+((?:Verbal\s+Ability|Quantitative\s+Aptitude|Reasoning(?:\s+Ability)?|English\s+Language)\s*(?:\([^)]*\))?)/iu',
            "$1\n\n$2",
            $text
        ) ?? $text;
        // Keep symbol-arrangement token rows on their own line before the next question.
        $text = preg_replace(
            '/(arrangement[^\n]{0,160}?)\s+((?:[\p{L}\p{N}@#%©$₹*&□■â]\s+){7,}[\p{L}\p{N}@#%©$₹*&□■â])\s+(?=\d{1,3}\)\s)/iu',
            "$1\n$2\n",
            $text
        ) ?? $text;
        // Option line glued to a directions block (e.g. "e) 17 Directions (7 - 11):").
        $text = preg_replace('/(\))\s*(Directions\s*\(\d+\s*-\s*\d+\)\s*:)/iu', "$1\n\n$2", $text) ?? $text;
        $text = preg_replace('/(\d{1,3}\))\s*(Directions\s*\(\d+\s*-\s*\d+\)\s*:)/iu', "$1\n\n$2", $text) ?? $text;
        $beforeRepair = $text;
        $text = self::repairCommonPdfMojibake($text);
        if (self::isGarbledExtract($text)) {
            // Mojibake repair can smash symbol-arrangement lines (© □ â); keep the pre-repair text.
            if (!self::isGarbledExtract($beforeRepair)) {
                $text = $beforeRepair;
            } else {
                return '';
            }
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }

        return $text;
    }

    /**
     * Drop repeated PDF/OCR header and footer lines (branding, URLs, page numbers) before MCQ parsing.
     */
    public static function stripManualPageHeaderFooter(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $pages = self::splitManualTextIntoPages($text);
        if ($pages === []) {
            return $text;
        }

        $lineCounts = [];
        foreach ($pages as $pb) {
            $seenOnPage = [];
            foreach (preg_split('/\n/u', $pb) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '' || self::isProtectedManualContentLine($trim)) {
                    continue;
                }
                $norm = self::normalizeHeaderFooterLineKey($trim);
                if ($norm === '' || mb_strlen($norm) > 120) {
                    continue;
                }
                if (!isset($seenOnPage[$norm])) {
                    $seenOnPage[$norm] = true;
                    $lineCounts[$norm] = ($lineCounts[$norm] ?? 0) + 1;
                }
            }
        }

        $pageCount = count($pages);
        $repeatThreshold = max(2, (int) ceil($pageCount * 0.5));
        $repeatedLines = [];
        foreach ($lineCounts as $norm => $cnt) {
            if ($cnt >= $repeatThreshold) {
                $repeatedLines[$norm] = true;
            }
        }

        $outPages = [];
        foreach ($pages as $pb) {
            $filtered = [];
            foreach (preg_split('/\n/u', $pb) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '') {
                    continue;
                }
                if (self::isManualHeaderFooterLine($trim)) {
                    continue;
                }
                $norm = self::normalizeHeaderFooterLineKey($trim);
                if ($norm !== '' && isset($repeatedLines[$norm]) && !self::isProtectedManualContentLine($trim)) {
                    continue;
                }
                $filtered[] = $trim;
            }
            $joined = trim(implode("\n", $filtered));
            if ($joined !== '') {
                $outPages[] = $joined;
            }
        }

        return trim(implode("\n\n", $outPages));
    }

    /**
     * @return list<string>
     */
    private static function splitManualTextIntoPages(string $text): array
    {
        $normalized = preg_replace('/---\s*PAGE\s+\d+\s*---\n?/iu', "\f", $text) ?? $text;
        if (!str_contains($normalized, "\f")) {
            $normalized = preg_replace('/\f/u', "\f", $normalized) ?? $normalized;
        }
        $parts = preg_split('/\f/u', $normalized) ?: [];
        $pages = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $pages[] = $part;
            }
        }

        return $pages !== [] ? $pages : [trim($text)];
    }

    private static function normalizeHeaderFooterLineKey(string $line): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

        return mb_strtolower($line);
    }

    private static function isManualHeaderFooterLine(string $line): bool
    {
        if (preg_match('/^page\s+\d+\s*$/iu', $line)) {
            return true;
        }
        if (preg_match('/^-\s*\d+\s*-$/u', $line)) {
            return true;
        }
        if (preg_match('/^https?:\/\//iu', $line) || preg_match('/^www\./iu', $line)) {
            return true;
        }
        if (preg_match('/^\d{1,4}\s*$/u', $line)) {
            return true;
        }

        return false;
    }

    private static function isProtectedManualContentLine(string $line): bool
    {
        if (preg_match('/Directions\s*[:(]/iu', $line)) {
            return true;
        }
        if (preg_match('/Mark\s+your\s+answer/iu', $line)) {
            return true;
        }
        if (preg_match('/^\s*(?<![0-9])(\d{1,3})\)\s+\S/u', $line)) {
            return true;
        }
        if (preg_match('/^\s*[a-eA-E][\.\)]\s+\S/u', $line)) {
            return true;
        }
        if (preg_match('/^\s*\(?i{1,2}\)?[\.\):]\s+\S/iu', $line)) {
            return true;
        }
        if (str_contains($line, '?')) {
            return true;
        }
        if (self::isSymbolArrangementLine($line)) {
            return true;
        }

        return false;
    }

    /** Letter/number/symbol bank-exam arrangement rows must not be treated as headers. */
    public static function isSymbolArrangementLine(string $line): bool
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || mb_strlen($line) < 11) {
            return false;
        }
        // e.g. "T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L"
        if (preg_match('/^(?:[\p{L}\p{N}@#%©®™$₹*&+\-=□■▪▫◊◆âÃÂ?¿¡\/\\\\]\s+){7,}[\p{L}\p{N}@#%©®™$₹*&+\-=□■▪▫◊◆âÃÂ?¿¡\/\\\\]$/u', $line) !== 1) {
            return false;
        }
        $tokens = preg_split('/\s+/u', $line) ?: [];
        if (count($tokens) < 8) {
            return false;
        }
        $short = 0;
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) <= 2) {
                $short++;
            }
        }

        return $short / count($tokens) >= 0.75;
    }

    public static function isGarbledExtract(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $len = mb_strlen($text);
        if ($len < 50) {
            return false;
        }
        if (preg_match('/Quartz PDFContext|endstream|\/Font\b|Microsoft Word - .+\.docx/i', $text) === 1) {
            if (preg_match_all('/[\p{L}]{4,}/u', $text, $m) && count($m[0] ?? []) < 8) {
                return true;
            }
        }
        preg_match_all('/[\p{L}]/u', $text, $letters);
        $letterCount = count($letters[0] ?? []);
        if ($letterCount / max(1, $len) < 0.12) {
            return true;
        }
        if (preg_match_all('/\?\s*\d+\s+\d+\s+obj/i', $text) >= 2) {
            return true;
        }

        return false;
    }

    /**
     * True when pdftotext output is usable for MCQ parsing (not just non-empty).
     */
    public static function isManualExtractSufficient(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || self::isGarbledExtract($text)) {
            return false;
        }
        $len = mb_strlen($text);
        if ($len < 100) {
            return false;
        }
        preg_match_all('/[\p{L}]{3,}/u', $text, $words);
        $wordCount = count($words[0] ?? []);
        if ($wordCount < 20) {
            return false;
        }
        preg_match_all('/[\p{L}]/u', $text, $letters);
        if (count($letters[0] ?? []) / max(1, $len) < 0.07) {
            return false;
        }
        $signals = 0;
        if (preg_match_all('/(?:^|\n)\s*(?:\d{1,3}[\.\):]|\(\s*[a-eA-E]\s*\)|[A-E][\.\)])/mu', $text, $qm) >= 1) {
            $signals += min(15, count($qm[0] ?? []));
        }
        if (preg_match('/\?\s*[\n\r]/u', $text) || preg_match('/\?\s+[A-Za-z(]/u', $text)) {
            $signals += 2;
        }
        if (preg_match('/Directions\s*\(\s*\d+/iu', $text)) {
            $signals += 2;
        }
        if (preg_match('/(?:^|\n)\s*[A-E][\.\)]\s+\S/mu', $text)) {
            $signals += 2;
        }

        return $signals >= 2 || ($wordCount >= 60 && $signals >= 1);
    }

    /** PDF text for aptitude manuals — OCR when text layer is missing or low quality. */
    public function extractPdfTextForManual(string $path): array
    {
        $viaShell = $this->extractPdfViaPdftotext($path, true, true);
        $viaShell = self::repairCommonPdfMojibake($viaShell);
        $shellSufficient = $viaShell !== ''
            && !self::isGarbledExtract($viaShell)
            && self::isManualExtractSufficient($viaShell)
            && !self::hasLikelyFontEncodingIssues($viaShell)
            && !self::symbolArrangementNeedsOcr($viaShell);

        $needsOcr = !$shellSufficient;

        $ocrResult = ['text' => '', 'pageCount' => 0];
        if ($needsOcr && $this->openai->isConfigured()) {
            $ocrResult = $this->extractPdfViaVisionOcrPaged($path, 200, 40);
        }

        $viaOcr = self::repairCommonPdfMojibake((string) ($ocrResult['text'] ?? ''));
        $ocrOk = $viaOcr !== ''
            && !self::isGarbledExtract($viaOcr)
            && self::isManualExtractSufficient($viaOcr);

        if ($ocrOk && (!$shellSufficient || self::manualExtractQuality($viaOcr) > self::manualExtractQuality($viaShell))) {
            return [
                'text' => $viaOcr,
                'method' => 'pdf_ocr',
                'pageCount' => (int) ($ocrResult['pageCount'] ?? 0),
            ];
        }
        if ($shellSufficient) {
            return ['text' => $viaShell, 'method' => 'pdf', 'pageCount' => 0];
        }
        if ($ocrOk) {
            return [
                'text' => $viaOcr,
                'method' => 'pdf_ocr',
                'pageCount' => (int) ($ocrResult['pageCount'] ?? 0),
            ];
        }
        if ($viaShell !== '' && !self::isGarbledExtract($viaShell)) {
            return ['text' => $viaShell, 'method' => 'pdf', 'pageCount' => 0];
        }

        return ['text' => $viaOcr !== '' ? $viaOcr : $viaShell, 'method' => 'pdf', 'pageCount' => 0];
    }

    public static function hasLikelyFontEncodingIssues(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/[\x{FFFD}]/u', $text) === 1) {
            return true;
        }
        if (preg_match('/â(?:\s*[=□◻]|\x{FFFD})/u', $text) === 1) {
            return true;
        }
        if (preg_match_all('/[âÃÂ]/u', $text, $m) >= 3) {
            return true;
        }
        // pdftotext often maps © and # to @ when fonts use custom encodings.
        if (preg_match('/\b@\s+[0-9A-D]\s+@\s+/u', $text) === 1 && preg_match('/©|#|\$|₹/u', $text) !== 1) {
            return true;
        }

        return false;
    }

    /**
     * Symbol-sequence sets often lose © # and other glyphs when extracted with broken font maps.
     */
    public static function symbolArrangementNeedsOcr(string $text): bool
    {
        if (!preg_match('/letter[\s\/]*number[\s\/]*symbol\s+arrangement/iu', $text)) {
            return false;
        }
        if (self::hasLikelyFontEncodingIssues($text)) {
            return true;
        }
        if (preg_match('/(?:^|\n)((?:[\p{L}\p{N}@#%©$₹*]\s+){8,}[\p{L}\p{N}@#%©$₹*])/mu', $text, $m) !== 1) {
            return false;
        }
        $line = (string) ($m[1] ?? '');
        $atCount = substr_count($line, '@');
        $hasCopyright = str_contains($line, '©') || str_contains($text, '©');

        return $atCount >= 2 && !$hasCopyright;
    }

    public static function manualExtractQuality(string $text): int
    {
        $score = mb_strlen(trim($text));
        if (self::hasLikelyFontEncodingIssues($text)) {
            $score -= 800;
        }
        if (preg_match('/©/u', $text)) {
            $score += 120;
        }
        if (preg_match('/#/u', $text)) {
            $score += 80;
        }
        if (preg_match('/[#$₹*]/u', $text)) {
            $score += 40;
        }
        preg_match_all('/[\p{L}]{4,}/u', $text, $words);

        return $score + count($words[0] ?? []) * 3;
    }

    public static function repairCommonPdfMojibake(string $text): string
    {
        if ($text === '' || !preg_match('/[âÃÂ]/u', $text)) {
            return $text;
        }

        // Arrangement lines often contain â / □ as printed (or OCR) tokens. A whole-document
        // Latin-1 iconv would delete © □ and can make a good extract look "garbled".
        $hasArrangement = preg_match('/letter[\s\/]*number[\s\/]*symbol\s+arrangement/iu', $text) === 1;
        if ($hasArrangement || preg_match('/(?:^|\n)\s*(?:[\p{L}\p{N}@#%©$₹*&]\s+){7,}[\p{L}\p{N}@#%©$₹*&□■]/mu', $text) === 1) {
            return str_replace(
                ["â€™", "â€œ", "â€", "â€˜", "â€“", "â€”", "Â ", "Â"],
                ["'", '"', '"', "'", '–', '—', ' ', ''],
                $text
            );
        }

        $fixed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
        if (is_string($fixed) && $fixed !== '' && mb_strlen($fixed) > 0) {
            $origSpecial = preg_match_all('/[©#₹□■▪▫]/u', $text);
            $fixSpecial = preg_match_all('/[©#₹□■▪▫]/u', $fixed);
            if ($fixSpecial < $origSpecial) {
                return $text;
            }
            if (!self::hasLikelyFontEncodingIssues($fixed)
                || self::manualExtractQuality($fixed) > self::manualExtractQuality($text)) {
                return $fixed;
            }
        }

        return $text;
    }

    private function extractPdfText(string $path): string
    {
        $viaShell = $this->extractPdfViaPdftotext($path);
        if ($viaShell !== '' && !self::isGarbledExtract($viaShell)) {
            return $viaShell;
        }

        $viaOcr = $this->extractPdfViaVisionOcr($path, 144);
        if ($viaOcr !== '') {
            return $viaOcr;
        }

        $heuristic = $this->extractPdfTextHeuristic($path);
        if ($heuristic !== '' && !self::isGarbledExtract($heuristic)) {
            return $heuristic;
        }

        return $viaShell !== '' && !self::isGarbledExtract($viaShell) ? $viaShell : '';
    }

    private function extractPdfViaVisionOcr(string $path, int $dpi = 144): string
    {
        if (!$this->openai->isConfigured() || !function_exists('exec')) {
            return '';
        }

        $dpi = max(96, min(300, $dpi));

        $tmpdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_pdf_' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpdir) && !is_dir($tmpdir)) {
            return '';
        }

        $prefix = $tmpdir . DIRECTORY_SEPARATOR . 'page';
        $pngPattern = $prefix . '-*.png';
        $executed = false;

        $commands = [
            'pdftoppm -png -r ' . $dpi . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix),
            'pdftocairo -png -r ' . $dpi . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix),
        ];
        foreach ($commands as $cmd) {
            exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && glob($pngPattern) !== []) {
                $executed = true;
                break;
            }
        }

        if (!$executed) {
            $gsOut = $prefix . '-%d.png';
            $gsCmd = 'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r' . $dpi . ' -dFirstPage=1 -dLastPage=8 '
                . '-sOutputFile=' . escapeshellarg($gsOut) . ' ' . escapeshellarg($path);
            exec($gsCmd . ' 2>&1', $gsOutLines, $gsCode);
            if ($gsCode !== 0 || glob($pngPattern) === []) {
                $this->removeDir($tmpdir);

                return '';
            }
        }

        $paged = $this->ocrRenderedPdfPages($prefix, $pngPattern, 8);
        $this->removeDir($tmpdir);

        return (string) ($paged['text'] ?? '');
    }

    /**
     * @return array{text:string, pageCount:int}
     */
    public function extractPdfViaVisionOcrPaged(string $path, int $dpi = 200, int $maxPages = 40): array
    {
        if (!$this->openai->isConfigured() || !function_exists('exec')) {
            return ['text' => '', 'pageCount' => 0];
        }

        $dpi = max(96, min(300, $dpi));
        $maxPages = max(1, min(60, $maxPages));

        $tmpdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_pdf_' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpdir) && !is_dir($tmpdir)) {
            return ['text' => '', 'pageCount' => 0];
        }

        $prefix = $tmpdir . DIRECTORY_SEPARATOR . 'page';
        $pngPattern = $prefix . '-*.png';
        $executed = false;

        $commands = [
            'pdftoppm -png -r ' . $dpi . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix),
            'pdftocairo -png -r ' . $dpi . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix),
        ];
        foreach ($commands as $cmd) {
            exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && glob($pngPattern) !== []) {
                $executed = true;
                break;
            }
        }

        if (!$executed) {
            $gsOut = $prefix . '-%d.png';
            $gsCmd = 'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r' . $dpi . ' -dFirstPage=1 -dLastPage=' . $maxPages . ' '
                . '-sOutputFile=' . escapeshellarg($gsOut) . ' ' . escapeshellarg($path);
            exec($gsCmd . ' 2>&1', $gsOutLines, $gsCode);
            if ($gsCode !== 0 || glob($pngPattern) === []) {
                $this->removeDir($tmpdir);

                return ['text' => '', 'pageCount' => 0];
            }
        }

        $result = $this->ocrRenderedPdfPages($prefix, $pngPattern, $maxPages);
        $this->removeDir($tmpdir);

        return $result;
    }

    /**
     * @return array{text:string, pageCount:int}
     */
    private function ocrRenderedPdfPages(string $prefix, string $pngPattern, int $maxPages): array
    {
        $files = glob($pngPattern) ?: [];
        sort($files, SORT_NATURAL);
        $files = array_slice($files, 0, $maxPages);
        $chunks = [];
        $pageNum = 0;
        foreach ($files as $png) {
            $pageNum++;
            try {
                $chunk = trim($this->extractManualTextFromImage($png, 'png'));
                if ($chunk !== '') {
                    $chunks[] = '--- PAGE ' . $pageNum . " ---\n" . $chunk;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [
            'text' => trim(implode("\n\n", $chunks)),
            'pageCount' => $pageNum,
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function extractPdfViaPdftotext(string $path, bool $layout = false, bool $cropHeaderFooter = false): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $out = tempnam(sys_get_temp_dir(), 'pms_jd_');
        if ($out === false) {
            return '';
        }
        $txtPath = $out . '.txt';
        @unlink($out);

        $flags = '-enc UTF-8';
        if ($layout) {
            $flags .= ' -layout';
        }
        if ($cropHeaderFooter) {
            // Ignore typical letterhead/footer bands (points, 72 pt ≈ 1 inch).
            $flags .= ' -margint 72 -marginb 54 -marginl 36 -marginr 36';
        }
        $cmd = 'pdftotext ' . $flags . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($txtPath) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_readable($txtPath)) {
            @unlink($txtPath);

            return '';
        }
        $text = (string) file_get_contents($txtPath);
        @unlink($txtPath);

        return trim($text);
    }

    private function extractPdfTextHeuristic(string $path): string
    {
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            return '';
        }

        $parts = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $data, $matches)) {
            foreach ($matches[0] as $raw) {
                $inner = substr($raw, 1, -1);
                $inner = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $inner);
                $inner = trim($inner);
                if ($inner !== '' && preg_match('/[\p{L}\p{N}]/u', $inner)) {
                    $parts[] = $inner;
                }
            }
        }

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams)) {
            foreach ($streams[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = $stream;
                }
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $decoded, $innerMatches)) {
                    foreach ($innerMatches[0] as $raw) {
                        $inner = substr($raw, 1, -1);
                        $inner = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $inner);
                        $inner = trim($inner);
                        if ($inner !== '' && preg_match('/[\p{L}\p{N}]/u', $inner)) {
                            $parts[] = $inner;
                        }
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    public function extractTextFromStoredUri(string $uri, ?string $mimeHint = null): string
    {
        $storage = new ObjectStorageService();
        $body = $storage->getContentsWithFallback($uri, ObjectStorageService::FOLDER_JD);
        if ($body === '') {
            return '';
        }

        $resolved = $storage->resolve($uri);
        $filename = (string) ($resolved['filename'] ?? 'jd.pdf');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' && $mimeHint !== null) {
            $ext = match ($mimeHint) {
                'application/pdf' => 'pdf',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                default => 'pdf',
            };
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pms_stu_jd_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to process document.');
        }
        file_put_contents($tmp, $body);

        try {
            $text = match ($ext) {
                'pdf' => $this->extractPdfText($tmp),
                'jpg', 'jpeg', 'png' => $this->extractImageText($tmp, $ext),
                default => $this->extractPdfText($tmp),
            };
        } finally {
            @unlink($tmp);
        }

        return $this->sanitizeText($text);
    }

    private function extractImageText(string $path, string $ext): string
    {
        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException(
                'Image OCR requires OpenAI to be configured on the server. Paste the JD text manually instead.'
            );
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return $this->openai->extractTextFromImage(base64_encode($bytes), $mime);
    }

    private function extractManualTextFromImage(string $path, string $ext): string
    {
        if (!$this->openai->isConfigured()) {
            return $this->extractImageText($path, $ext);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return $this->openai->extractTextFromImageWithPrompt(
            base64_encode($bytes),
            $mime,
            self::MANUAL_APTITUDE_OCR_PROMPT
        );
    }

    /**
     * Ingest a question manual upload (PDF, image, or plain text). File is always stored when possible;
     * extracted text may be empty (e.g. scanned image without OCR).
     *
     * @return array{text:string,filename:?string,method:string,jdFile?:string,jdFileUrl?:string,jdMimeType?:string}
     */
    public function ingestManualUpload(array $file): array
    {
        $error = Security::validateUploadedFile($file, self::MAX_FILE_BYTES, self::MANUAL_EXTENSIONS);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $name = basename((string) ($file['name'] ?? 'manual-upload'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'text') {
            $ext = 'txt';
        }

        $stored = $this->persistUploadedFile($file, $name, $ext === 'txt' ? 'txt' : $ext);

        $text = '';
        $method = $ext;
        $pageCount = 0;
        $ocrAttempted = false;
        if ($ext === 'txt') {
            $raw = file_get_contents($tmp);
            $text = $this->sanitizeManualText($raw !== false ? (string) $raw : '');
            $method = 'text';
        } elseif ($ext === 'pdf') {
            $pdfExtract = $this->extractPdfTextForManual($tmp);
            $rawPdf = (string) ($pdfExtract['text'] ?? '');
            $text = $this->sanitizeManualText($rawPdf);
            $method = (string) ($pdfExtract['method'] ?? 'pdf');
            $pageCount = (int) ($pdfExtract['pageCount'] ?? 0);
            $ocrAttempted = $method === 'pdf_ocr';
            if ($text === '') {
                $method = $rawPdf !== '' && self::isGarbledExtract($rawPdf) ? 'pdf_unreadable' : $method;
            }
        } else {
            try {
                $text = $this->sanitizeManualText($this->extractManualTextFromImage($tmp, $ext));
                $method = 'ocr';
                $ocrAttempted = true;
            } catch (\Throwable) {
                $text = '';
                $method = 'image';
            }
        }

        return array_merge([
            'text' => $text,
            'filename' => $name,
            'method' => $method,
            'pageCount' => $pageCount,
            'ocrAttempted' => $ocrAttempted,
        ], $stored);
    }
}
