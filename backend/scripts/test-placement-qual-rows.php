<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$api = new PMS\Services\AesApiService();
$placement = $api->fetchStudentPlacementProfile(['admno' => $argv[1] ?? '16777']);
echo json_encode([
    'cgpa' => $placement['cgpa'] ?? null,
    'qualifications' => $placement['qualifications'] ?? [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
