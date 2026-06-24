<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$api = new PMS\Services\AesApiService();
print_r($api->callAESApi('getStudInfo4Placement', [
    'username' => $argv[1] ?? '22MCA047',
    'admission_no' => $argv[1] ?? '22MCA047',
    'un' => $argv[1] ?? '22MCA047',
]));
