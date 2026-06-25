<?php

declare(strict_types=1);

/**
 * Smoke test: student self-placement submit keeps session and notifies admin.
 * Usage: php backend/scripts/smoke-self-placement.php
 */

$base = getenv('PMS_API_BASE') ?: 'http://127.0.0.1:8080/backend/api';
$studentEmail = getenv('PMS_STUDENT_EMAIL') ?: 'rahul.v@college.edu';
$studentPass = getenv('PMS_STUDENT_PASSWORD') ?: 'Student@123456';
$adminEmail = getenv('PMS_ADMIN_EMAIL') ?: 'admin@college.edu';
$adminPass = getenv('PMS_ADMIN_PASSWORD') ?: 'Admin@123456';

function reqJson(string $url, string $method = 'GET', ?array $body = null, ?string $cookie = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'json' => json_decode($raw ?: '', true), 'raw' => $raw];
}

function reqMultipart(string $url, array $fields, string $fileField, string $filePath, ?string $cookie = null): array
{
    $post = $fields;
    $post[$fileField] = new CURLFile($filePath, 'application/pdf', basename($filePath));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'json' => json_decode($raw ?: '', true), 'raw' => $raw];
}

echo "Self-placement session + admin notification smoke test\nBase: {$base}\n\n";

$pdf = tempnam(sys_get_temp_dir(), 'pms_offer_') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

$studentCookie = tempnam(sys_get_temp_dir(), 'pms_stu_');
$adminCookie = tempnam(sys_get_temp_dir(), 'pms_adm_');

$failed = 0;
$assert = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL') . " {$label}\n";
    if (!$ok) {
        $failed++;
    }
};

reqJson($base . '/auth/logout', 'POST', [], $studentCookie);
$login = reqJson($base . '/auth/login', 'POST', ['email' => $studentEmail, 'password' => $studentPass], $studentCookie);
$assert(($login['json']['success'] ?? false) === true, "student login ({$studentEmail})");

$me = reqJson($base . '/auth/me', 'GET', null, $studentCookie);
$assert($me['code'] === 200 && ($me['json']['data']['role'] ?? '') === 'student', 'student session /auth/me');

$profile = reqJson($base . '/student/profile', 'GET', null, $studentCookie);
$assert($profile['code'] === 200 && ($profile['json']['success'] ?? false), 'student profile before submit');

$company = 'SmokeTest Co ' . date('His');
$submit = reqMultipart(
    $base . '/student/self-placement',
    [
        'companyName'    => $company,
        'companyAddress' => 'AJCE Campus, Kanjirappally',
        'role'           => 'Software Engineer',
    ],
    'offerLetter',
    $pdf,
    $studentCookie
);

if (($submit['json']['success'] ?? false) && in_array($submit['code'], [200, 201], true)) {
    $assert(true, 'student self-placement submit HTTP ' . $submit['code']);
} elseif ($submit['code'] === 409) {
    $msg = (string) ($submit['json']['message'] ?? '');
    $assert(str_contains($msg, 'under review') || str_contains($msg, 'already marked'), 'student self-placement blocked as expected (' . $msg . ')');
} else {
    $assert(false, 'student self-placement submit HTTP ' . $submit['code'] . ' — ' . substr((string) ($submit['json']['message'] ?? $submit['raw']), 0, 120));
}

$meAfter = reqJson($base . '/auth/me', 'GET', null, $studentCookie);
$assert($meAfter['code'] === 200 && ($meAfter['json']['success'] ?? false), 'student session still valid after submit');

reqJson($base . '/auth/logout', 'POST', [], $adminCookie);
$adminLogin = reqJson($base . '/auth/login', 'POST', ['email' => $adminEmail, 'password' => $adminPass], $adminCookie);
$assert(($adminLogin['json']['success'] ?? false) === true, "admin login ({$adminEmail})");

$notifs = reqJson($base . '/admin/notifications', 'GET', null, $adminCookie);
$assert($notifs['code'] === 200 && ($notifs['json']['success'] ?? false), 'admin notifications list');

$found = false;
foreach (($notifs['json']['data'] ?? []) as $item) {
    $type = (string) ($item['type'] ?? '');
    $title = (string) ($item['title'] ?? '');
    $message = (string) ($item['message'] ?? '');
    if ($type === 'self_placement_submitted' || str_contains($message, $company) || str_contains($title, 'placement report')) {
        $found = true;
        break;
    }
}
$assert($found, 'admin received self-placement notification');

@unlink($pdf);
@unlink($studentCookie);
@unlink($adminCookie);

echo "\n" . ($failed === 0 ? "All checks passed.\n" : "{$failed} check(s) failed.\n");
exit($failed > 0 ? 1 : 0);
