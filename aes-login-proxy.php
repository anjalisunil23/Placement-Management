<?php

declare(strict_types=1);

/**
 * Same-origin proxy for login.aesajce.in/aes-login.js
 *
 * Browsers cannot load that script cross-origin from our site (415/CORS),
 * but the live server can fetch it and serve it so the official AES widget runs.
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$api = isset($_GET['api']) ? preg_replace('/[^a-fA-F0-9]/', '', (string) $_GET['api']) : '';
$url = 'https://login.aesajce.in/aes-login.js';
if ($api !== '') {
    $url .= '?api=' . rawurlencode($api);
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'placements.amaljyothi.ac.in');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(502);
    echo "console.error('AES login widget proxy: curl unavailable');";
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: */*',
        'Referer: ' . $scheme . '://' . $host . '/public-stats.html',
        'Origin: ' . $scheme . '://' . $host,
    ],
]);

$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if (!is_string($body) || $body === '' || $code >= 400) {
    http_response_code(502);
    echo 'console.error(' . json_encode(
        'AES login widget proxy failed: HTTP ' . $code . ' ' . $err,
        JSON_UNESCAPED_SLASHES
    ) . ');';
    exit;
}

if (str_contains($body, '<html')
    || str_contains($body, 'Unsupported Media Type')
    || str_contains($body, 'One moment')) {
    http_response_code(502);
    echo "console.error('AES login widget proxy got a non-JS response from login.aesajce.in');";
    exit;
}

// Browser cannot POST to login.aesajce.in (CORS/WAF). Route widget API calls
// through our same-origin proxy so checkLogin / emailConnect can succeed.
$body = str_replace(
    'https://login.aesajce.in/api/public_api.php',
    '/aes-public-api.php',
    $body
);

echo $body;
