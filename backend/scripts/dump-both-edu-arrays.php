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
printf("%-12s %-28s %-14s %-12s %-10s\n", 'matchKey', 'qualification', 'registerNumber', 'monthYear', 'source');
foreach ($qualParsed as $row) {
    $key = $matchKey->invoke(
        $api,
        (string) ($row['qualification'] ?? ''),
        isset($row['mark']) ? (float) $row['mark'] : null,
        isset($row['maxMark']) ? (float) $row['maxMark'] : null
    );
    printf(
        "%-12s %-28s %-14s %-12s %-10s\n",
        $key,
        (string) ($row['qualification'] ?? '(empty)'),
        (string) ($row['registerNumber'] ?? ''),
        (string) ($row['monthYear'] ?? ''),
        'qual-API'
    );
}
foreach ($infoParsed as $row) {
    $key = $matchKey->invoke(
        $api,
        (string) ($row['qualification'] ?? ''),
        isset($row['mark']) ? (float) $row['mark'] : null,
        isset($row['maxMark']) ? (float) $row['maxMark'] : null
    );
    printf(
        "%-12s %-28s %-14s %-12s %-10s\n",
        $key,
        (string) ($row['qualification'] ?? '(empty)'),
        (string) ($row['registerNumber'] ?? ''),
        (string) ($row['monthYear'] ?? ''),
        'info-API'
    );
}

echo "\n=== Merged fetchStudentQualificationProfile ===\n";
echo json_encode($api->fetchStudentQualificationProfile(['admno' => $admno]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
