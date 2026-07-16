<?php

declare(strict_types=1);

/**
 * Migrate existing local uploads/ files into the S3 bucket via the campus Lambda.
 *
 * Usage:
 *   php backend/scripts/migrate-uploads-to-s3.php
 *   php backend/scripts/migrate-uploads-to-s3.php --dry-run
 *   php backend/scripts/migrate-uploads-to-s3.php --delete-local
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php — run composer install first.\n");
    exit(1);
}
require_once $autoload;

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();
if (is_readable($root . '/.env.local')) {
    Dotenv\Dotenv::createMutable($root, '.env.local')->safeLoad();
}

use PMS\Config\Database;
use PMS\Services\ObjectStorageService;

$dryRun = in_array('--dry-run', $argv, true);
$deleteLocal = in_array('--delete-local', $argv, true);

$config = require dirname(__DIR__) . '/config/app.php';
$storage = new ObjectStorageService($config);

if (!$storage->isConfigured()) {
    fwrite(STDERR, "S3 Lambda is not configured (AWS_S3_API_ENDPOINT).\n");
    exit(1);
}

/** @var array<string, string> localDirKey => S3 folder */
$folderMap = [
    'resume_dir' => ObjectStorageService::FOLDER_RESUMES,
    'certificate_dir' => ObjectStorageService::FOLDER_CERTIFICATES,
    'reports_dir' => ObjectStorageService::FOLDER_REPORTS,
    'jd_dir' => ObjectStorageService::FOLDER_JD,
    'shortlist_dir' => ObjectStorageService::FOLDER_SHORTLISTS,
    'signed_dir' => ObjectStorageService::FOLDER_SIGNED_REPORTS,
    'offer_letter_dir' => ObjectStorageService::FOLDER_OFFER_LETTERS,
    'self_placement_dir' => ObjectStorageService::FOLDER_SELF_PLACEMENT,
    'alumni_employment_dir' => ObjectStorageService::FOLDER_ALUMNI_EMPLOYMENT,
    'photo_dir' => ObjectStorageService::FOLDER_PHOTOS,
    'job_poster_dir' => ObjectStorageService::FOLDER_JOB_POSTERS,
];

$legacyRoots = [
    $root . '/uploads/resumes' => ObjectStorageService::FOLDER_RESUMES,
    $root . '/uploads/reports' => ObjectStorageService::FOLDER_REPORTS,
    $root . '/uploads/certificates' => ObjectStorageService::FOLDER_CERTIFICATES,
    $root . '/uploads/jd' => ObjectStorageService::FOLDER_JD,
    $root . '/uploads/shortlists' => ObjectStorageService::FOLDER_SHORTLISTS,
    $root . '/uploads/signed_reports' => ObjectStorageService::FOLDER_SIGNED_REPORTS,
    $root . '/uploads/offer_letters' => ObjectStorageService::FOLDER_OFFER_LETTERS,
    $root . '/uploads/self_placement' => ObjectStorageService::FOLDER_SELF_PLACEMENT,
    $root . '/uploads/alumni_employment' => ObjectStorageService::FOLDER_ALUMNI_EMPLOYMENT,
    $root . '/uploads/photos' => ObjectStorageService::FOLDER_PHOTOS,
    $root . '/uploads/job-posters' => ObjectStorageService::FOLDER_JOB_POSTERS,
    $root . '/uploads/ajce-placements/resumes' => ObjectStorageService::FOLDER_RESUMES,
    $root . '/uploads/ajce-placements/reports' => ObjectStorageService::FOLDER_REPORTS,
];

echo $dryRun ? "=== DRY RUN (no uploads / DB writes) ===\n" : "=== Migrating local uploads to S3 ===\n";
echo 'Lambda: ' . $storage->apiEndpoint() . "\n";
echo 'Prefix: ' . $storage->bucket() . "\n\n";

/** @var array<string, array{uri:string,storedName:string,folder:string}> */
$map = [];
$uploaded = 0;
$skipped = 0;
$failed = 0;

$scanDirs = [];
foreach ($folderMap as $key => $folder) {
    $dir = (string) ($config['uploads'][$key] ?? '');
    if ($dir !== '') {
        $scanDirs[$dir] = $folder;
    }
}
foreach ($legacyRoots as $dir => $folder) {
    $scanDirs[$dir] = $folder;
}

foreach ($scanDirs as $dir => $folder) {
    if (!is_dir($dir)) {
        continue;
    }
    $files = scandir($dir) ?: [];
    foreach ($files as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep') {
            continue;
        }
        $localPath = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($localPath)) {
            continue;
        }

        $norm = str_replace('\\', '/', $localPath);
        echo "[{$folder}] {$name} ... ";

        if ($dryRun) {
            echo "would upload\n";
            $skipped++;
            continue;
        }

        try {
            $uri = $storage->putLocalFile($folder, $name, $localPath);
            $storedName = $storage->storedNameFromUri($uri);
            $map[$name] = ['uri' => $uri, 'storedName' => $storedName, 'folder' => $folder];
            $map[$norm] = $map[$name];
            $map[str_replace('/', '\\', $localPath)] = $map[$name];
            echo "OK → {$uri}\n";
            $uploaded++;

            if ($deleteLocal) {
                @unlink($localPath);
            }
        } catch (Throwable $e) {
            echo 'FAIL: ' . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

if ($dryRun) {
    echo "\nDry run complete. Re-run without --dry-run to upload.\n";
    exit(0);
}

if ($map === []) {
    echo "\nNo local files found to migrate.\n";
    exit($failed > 0 ? 1 : 0);
}

echo "\nUpdating MariaDB payload paths...\n";
$pdo = Database::pdo();
$tables = [
    'students',
    'resumes',
    'applications',
    'drives',
    'reports',
    'companies',
    'alumni',
    'alumni_job_posts',
    'jobs',
];

$updatedRows = 0;
foreach ($tables as $table) {
    try {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            continue;
        }
    } catch (Throwable) {
        continue;
    }

    $stmt = $pdo->query("SELECT id, payload FROM `{$table}`");
    if ($stmt === false) {
        continue;
    }

    $update = $pdo->prepare("UPDATE `{$table}` SET payload = ?, updated_at = NOW(6) WHERE id = ?");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payloadJson = (string) ($row['payload'] ?? '');
        if ($payloadJson === '') {
            continue;
        }
        $doc = json_decode($payloadJson, true);
        if (!is_array($doc)) {
            continue;
        }

        $changed = rewritePaths($doc, $map);
        if (!$changed) {
            continue;
        }

        $update->execute([
            json_encode($doc, JSON_THROW_ON_ERROR),
            $row['id'],
        ]);
        $updatedRows++;
        echo "  updated {$table}/{$row['id']}\n";
    }
}

echo "\nDone.\n";
echo "  Uploaded: {$uploaded}\n";
echo "  Failed:   {$failed}\n";
echo "  DB rows:  {$updatedRows}\n";
if ($deleteLocal) {
    echo "  Local copies deleted after successful upload.\n";
}

/**
 * @param array<string, mixed> $doc
 * @param array<string, array{uri:string,storedName:string,folder:string}> $map
 */
function rewritePaths(array &$doc, array $map): bool
{
    $changed = false;
    foreach ($doc as $key => &$value) {
        if (is_array($value)) {
            if (rewritePaths($value, $map)) {
                $changed = true;
            }
            continue;
        }
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (str_starts_with($value, 's3://')) {
            continue;
        }

        $norm = str_replace('\\', '/', $value);
        if (isset($map[$value])) {
            $hit = $map[$value];
            $doc[$key] = in_array($key, ['storedName', 'file', 'filename', 'fileName'], true)
                ? $hit['storedName']
                : $hit['uri'];
            $changed = true;
            continue;
        }
        if (isset($map[$norm])) {
            $hit = $map[$norm];
            $doc[$key] = in_array($key, ['storedName', 'file', 'filename', 'fileName'], true)
                ? $hit['storedName']
                : $hit['uri'];
            $changed = true;
            continue;
        }

        $base = basename($norm);
        if (isset($map[$base]) && (
            str_contains($norm, '/uploads/')
            || in_array($key, ['storedName', 'path', 'signedReport', 'offerLetter', 'joiningLetter', 'companyIdDoc', 'salarySlip', 'jdFile', 'shortlistDocument', 'url'], true)
        )) {
            $hit = $map[$base];
            if (in_array($key, ['storedName', 'file', 'filename', 'fileName'], true)) {
                $doc[$key] = $hit['storedName'];
            } elseif ($key === 'url' && str_starts_with($value, '/uploads/')) {
                $doc[$key] = '/backend/api/media/' . rawurlencode($hit['folder']) . '/' . rawurlencode($hit['storedName']);
            } else {
                $doc[$key] = $hit['uri'];
            }
            $changed = true;
        }
    }
    unset($value);

    return $changed;
}
