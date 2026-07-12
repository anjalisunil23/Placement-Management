<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$api = new PMS\Services\AesApiService();
$rows = $api->loadDepartmentsFromApi();
foreach ($rows as $r) {
    $line = json_encode($r);
    if (stripos($line, 'computer') !== false || stripos($line, 'MCA') !== false || stripos($line, '30') !== false) {
        echo $line . PHP_EOL;
    }
}
echo 'total: ' . count($rows) . PHP_EOL;
