<?php

declare(strict_types=1);

/**
 * Education duration formatting checks (mirrors resume-builder.js previewEducationDuration).
 * Usage: php backend/scripts/test-resume-education-duration.php
 */

$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};

$previewEducationDuration = static function (string $year): string {
    $raw = trim($year);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/((?:19|20)\d{2})\s*[-–—−]\s*((?:19|20)\d{2})/u', $raw, $full)) {
        return $full[2];
    }
    if (preg_match('/((?:19|20)\d{2})\s*[-–—−]\s*(\d{2})(?!\d)/u', $raw, $short)) {
        $start = (int) $short[1];
        $end = (int) (floor($start / 100) * 100 + (int) $short[2]);
        if ($end >= $start) {
            return (string) $end;
        }
    }
    if (preg_match('/(19|20)\d{2}/', $raw, $m)) {
        return $m[0];
    }
    return $raw;
};

$cases = [
    ['MCA2025-27-S3', '2027', '1 batch code passout year'],
    ['MCAINT2023-28-S7', '2028', '1 other dept batch passout year'],
    ['2022 - 2025', '2025', '2 completed range passout year'],
    ['2022–2025', '2025', '2 completed en-dash passout year'],
    ['2025', '2025', '3 end year only'],
    ['2022', '2022', '3 plus two end only'],
    ['2020', '2020', '3 sslc end only'],
    ['', '', '5 no start/end'],
    ['May 2024', '2024', '6 single year in text'],
];

foreach ($cases as [$in, $want, $label]) {
    $got = $previewEducationDuration($in);
    $assert($got === $want, "{$label}: '{$in}' => '{$got}'");
}

$bca = $previewEducationDuration('2025');
$mca = $previewEducationDuration('MCA2025-27-S3');
$assert($bca === '2025' && $mca === '2027', '8 records independent (BCA year vs MCA batch passout)');

$js = (string) file_get_contents(dirname(__DIR__, 2) . '/js/resume-builder.js');
$assert(!str_contains($js, 'educationProgramYears'), 'no course-duration invention helper in JS');
$assert(!str_contains($js, 'BCA = 4') && !str_contains($js, "=== 'BCA'"), 'no hardcoded BCA dates');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
