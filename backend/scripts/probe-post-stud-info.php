<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$api = new PMS\Services\AesApiService();
$r = $api->postStudInfo4Placement(['admno' => '16777'], '16777');
echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
