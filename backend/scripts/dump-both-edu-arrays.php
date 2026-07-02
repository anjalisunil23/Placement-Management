<?php

declare(strict_types=1);

/**
 * Side-by-side dump of edu rows from getStudInfo4Placement vs getStudQual4Placement.
 * Usage: php backend/scripts/dump-both-edu-arrays.php [admno]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$admno = $argv[1] ?? '17228';
$api = new AesApiService();

$ref = new ReflectionClass($api);
$extract = $ref->getMethod('extractRecord');
$extract->setAccessible(true);
$matchKey = $ref->getMethod('qualificationMatchKey');
$matchKey->setAccessible(true);
$parseEdu = $ref->getMethod('parseEducationQualifications');
$parseEdu->setAccessible(true);
$normalizeInfo = $ref->getMethod('normalizePlacementStudentRecord');
$normalizeInfo->setAccessible(true);

$infoHttp = $api->postStudInfo4Placement(['admno' => $admno], $admno);
$qualHttp = $api->postStudQual4Placement(['admno' => $admno, 'stud_admno' => $admno], $admno);

$infoRecord = $normalizeInfo->invoke($api, $extract->invoke($api, $infoHttp));
$qualRecord = $extract->invoke($api, $qualHttp);

echo "=== getStudInfo4Placement: raw edu source ===\n";
$infoEduRaw = $infoRecord['edu'] ?? null;
if ($infoEduRaw === null) {
    echo "edu: null (no per-row edu array on this student)\n";
    echo "top-level registerno: " . ($infoRecord['registerno'] ?? '') . "\n";
    echo "top-level stud_class: " . ($infoRecord['stud_class'] ?? '') . "\n";
} else {
    echo json_encode($infoEduRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== getStudQual4Placement: raw edu rows ===\n";
$qualEduRaw = is_array($qualRecord) && array_is_list($qualRecord) ? $qualRecord : ($qualRecord['edu'] ?? $qualRecord);
echo json_encode($qualEduRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$infoParsed = $parseEdu->invoke($api, $infoRecord);
$qualParsed = $parseEdu->invoke($api, is_array($qualRecord) && array_is_list($qualRecord) ? ['edu' => $qualRecord] : $qualRecord);

echo "\n=== Side-by-side qualification labels (parsed) ===\n";
printf("%-12s %-20s %-30s %-14s %-12s %-10s\n", 'matchKey', 'qualification', 'institution', 'registerNumber', 'monthYear', 'source');
$printRow = static function (string $key, array $row, string $source) {
    $inst = (string) ($row['institution'] ?? '');
    if (strlen($inst) > 28) {
        $inst = substr($inst, 0, 25) . '...';
    }
    printf(
        "%-12s %-20s %-30s %-14s %-12s %-10s\n",
        $key,
        (string) (($row['qualification'] ?? '') !== '' ? $row['qualification'] : '(empty)'),
        $inst,
        (string) ($row['registerNumber'] ?? ''),
        (string) ($row['monthYear'] ?? ''),
        $source
    );
};
foreach ($qualParsed as $row) {
    $key = $matchKey->invoke(
        $api,
        (string) ($row['qualification'] ?? ''),
        isset($row['mark']) ? (float) $row['mark'] : null,
        isset($row['maxMark']) ? (float) $row['maxMark'] : null
    );
    $printRow($key, $row, 'qual-API');
}
foreach ($infoParsed as $row) {
    $key = $matchKey->invoke(
        $api,
        (string) ($row['qualification'] ?? ''),
        isset($row['mark']) ? (float) $row['mark'] : null,
        isset($row['maxMark']) ? (float) $row['maxMark'] : null
    );
    $printRow($key, $row, 'info-API');
}

echo "\n=== Merged fetchStudentQualificationProfile ===\n";
$merged = $api->fetchStudentQualificationProfile(['admno' => $admno]);
echo json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== Merged row completeness ===\n";
foreach ($merged['qualifications'] ?? [] as $i => $row) {
    $missing = [];
    foreach (['institution', 'registerNumber', 'monthYear'] as $field) {
        if (trim((string) ($row[$field] ?? '')) === '') {
            $missing[] = $field;
        }
    }
    $hasMarks = ($row['mark'] ?? null) !== null || ($row['percentage'] ?? null) !== null;
    $label = (string) (($row['qualification'] ?? '') !== '' ? $row['qualification'] : '(CGPA)');
    if ($missing === [] && $hasMarks) {
        echo "Row {$i} ({$label}): OK\n";
    } else {
        echo "Row {$i} ({$label}): missing " . implode(', ', $missing ?: ['(none)']);
        if (!$hasMarks) {
            echo '; marks empty';
        }
        echo "\n";
    }
}
