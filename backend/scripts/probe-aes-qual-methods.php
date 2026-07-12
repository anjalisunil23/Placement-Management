<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$methods = [
    'getStudentInfo', 'getStudInfo', 'getStudDetails', 'getStudentDetails',
    'getStudProfile', 'getStudentProfile', 'getStudAcademic', 'getStudentAcademic',
    'getStudMarks', 'getStudentMarks', 'getStudEducation', 'getStudEdu',
    'getStudQual', 'getStudentQual', 'getStudQualification', 'getStudentQualification',
    'getStudPrevEducation', 'getStudSchoolMarks', 'getStudSSLC', 'getStudHSC',
    'getStudPrevQual', 'getStudBackground', 'getStudBioData', 'getStudBiodata',
];

foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => $method,
            'admno' => $admno,
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 500) {
        echo "{$method}: HTTP 500\n";
        continue;
    }
    if (!str_contains($raw, 'Invalid Method') && trim($raw) !== '' && !str_contains($raw, '"data":false')) {
        $j = json_decode($raw, true);
        $data = is_array($j['data'] ?? null) ? $j['data'] : [];
        $keys = is_array($data) ? implode(',', array_keys($data)) : '';
        $hasEdu = isset($data['edu']) ? ' HAS_EDU' : '';
        $hasSslc = (isset($data['sslc']) || isset($data['marks10th']) || isset($data['stud_sslc'])) ? ' HAS_10' : '';
        $hasHsc = (isset($data['hsc']) || isset($data['marks12th']) || isset($data['stud_hsc'])) ? ' HAS_12' : '';
        echo "{$method}: HTTP {$code} keys={$keys}{$hasEdu}{$hasSslc}{$hasHsc}\n";
        if ($hasEdu || $hasSslc || $hasHsc) {
            echo substr($raw, 0, 800) . "\n---\n";
        }
    }
}
