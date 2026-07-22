<?php

declare(strict_types=1);

/**
 * Same-origin proxy for login.aesajce.in/api/public_api.php
 *
 * The official AES widget posts checkLogin/emailConnect from the browser.
 * Direct calls fail (CORS / WAF 415), but the live server can reach AES.
 *
 * Returns a plain-text JSON body so jQuery leaves it as a string for JSON.parse().
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo '{"status":false,"title":"Error","message":"POST required"}';
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    // Fallback when PHP already parsed multipart / urlencoded into $_POST
    $raw = http_build_query($_POST);
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'placements.amaljyothi.ac.in');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$endpoint = isset($_GET['endpoint']) ? basename((string) $_GET['endpoint']) : 'public_api.php';
if (!in_array($endpoint, ['public_api.php', 'index.php'], true)) {
    $endpoint = 'public_api.php';
}

$url = 'https://login.aesajce.in/api/' . $endpoint;

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo '{"status":false,"title":"Error","message":"AES proxy unavailable"}';
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*',
        'Origin: ' . $scheme . '://' . $host,
        'Referer: ' . $scheme . '://' . $host . '/public-stats.html',
    ],
]);

$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!is_string($body) || $body === '') {
    http_response_code(502);
    echo json_encode([
        'status'  => false,
        'title'   => 'Error',
        'message' => 'Could not reach AES login server' . ($err !== '' ? (': ' . $err) : ''),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($code >= 400
    || str_contains($body, 'Unsupported Media Type')
    || str_contains($body, '<html')) {
    http_response_code(502);
    echo json_encode([
        'status'  => false,
        'title'   => 'Error',
        'message' => 'AES login server blocked the request (HTTP ' . $code . ')',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);
echo $body;
