<?php

declare(strict_types=1);

/**
 * Same-origin proxy for login.aesajce.in/aes-login.js — avoids browser CORS blocks
 * when the landing page loads the AES widget as an ES module from another domain.
 */
$root = __DIR__;
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '/* AES login proxy: server dependencies missing */';
    exit;
}

require $autoload;

$aes = require $root . '/backend/config/aes.php';
$authKey = trim((string) ($aes['auth_key'] ?? ''));
$refHost = trim((string) ($aes['ref_host'] ?? ''));

if ($refHost === '') {
    $refHost = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

if ($authKey === '') {
    http_response_code(404);
    exit;
}

$upstream = 'https://login.aesajce.in/aes-login.js?api=' . rawurlencode($authKey);
$referer = 'https://' . $refHost . '/public-stats.html';

$body = fetchAesLoginScript($upstream, $referer);

if (!isValidAesLoginScript($body)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $body;

function fetchAesLoginScript(string $url, string $referer): string
{
    if (!function_exists('curl_init')) {
        return '';
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: ' . $referer,
            'User-Agent: Mozilla/5.0 (compatible; AJCE-Placements/1.0; +' . $referer . ')',
        ],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 300) {
        return '';
    }

    return $body;
}

function isValidAesLoginScript(string $body): bool
{
    $trim = ltrim($body);
    if ($trim === '') {
        return false;
    }

    $lower = strtolower(substr($trim, 0, 64));
    if (str_starts_with($lower, '<!doctype') || str_starts_with($lower, '<html')) {
        return false;
    }
    if (str_contains($trim, 'Unsupported Media Type')) {
        return false;
    }

    return true;
}
