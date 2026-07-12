<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$admno = $argv[1] ?? '16777';
$api = new PMS\Services\AesApiService();
$ref = new ReflectionClass($api);
$m = $ref->getMethod('callAESApiWithMethodInQuery');
$m->setAccessible(true);

echo "=== callAESApiWithMethodInQuery (merged query params) ===\n";
$r = $m->invoke($api, 'getStudQual4Placement', ['admno' => $admno]);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
