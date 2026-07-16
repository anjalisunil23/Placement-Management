<?php

declare(strict_types=1);

require dirname(__DIR__) . '/services/ObjectStorageService.php';

use PMS\Services\ObjectStorageService;

$cfg = [
    's3' => [
        'api_endpoint' => 'https://hep6ztvxpjewibbu6z57hmfijm0czbnu.lambda-url.ap-south-1.on.aws',
        'prefix' => 'ajce-placements',
        'docs_root' => 'Docs',
        'physical_bucket' => 'iqac-docs',
        'timeout' => 60,
    ],
    'uploads' => [],
];

$s = new ObjectStorageService($cfg);
echo 'uri=' . $s->uri('resumes', 'demo.pdf') . PHP_EOL;
echo 'objectKey=' . $s->objectKey('resumes', 'demo.pdf') . PHP_EOL;
echo 'uploadPath=' . $s->uploadPath('resumes') . PHP_EOL;

$uploaded = $s->putContents('resumes', 'hint.pdf', "%PDF-1.4\ntest", 'application/pdf');
echo 'uploaded=' . $uploaded . PHP_EOL;
echo 'storedName=' . $s->storedNameFromUri($uploaded) . PHP_EOL;

$resolved = $s->resolve($uploaded);
echo 'resolved_key=' . $resolved['key'] . PHP_EOL;
echo 'resolved_folder=' . $resolved['folder'] . PHP_EOL;

try {
    $got = $s->getContents($uploaded);
    echo 'downloaded_bytes=' . strlen($got) . PHP_EOL;
} catch (Throwable $e) {
    echo 'download_error=' . $e->getMessage() . PHP_EOL;
}

$s->delete($uploaded);
echo 'deleted=ok' . PHP_EOL;
