<?php
declare(strict_types=1);

$base = getenv('PMS_API_BASE') ?: 'http://localhost:8080/backend/api';
$accounts = [
    ['admin@college.edu', 'Admin@123456', 'admin', '/dashboard.html'],
    ['riya@college.edu', 'Officer@123456', 'placement_officer', '/dashboard.html'],
    ['ravi.iyer@college.edu', 'Staff@123456', 'staff', '/staff-recommend.html'],
    ['rahul.v@college.edu', 'Student@123456', 'student', '/drives.html'],
    ['rohan.v@alumni.edu', 'Alumni@123456', 'alumni', '/dashboard.html'],
    ['neha@acme.io', 'Company@123456', 'company', '/company.html'],
];

function req(string $url, string $method = 'GET', ?array $body = null, ?string $cookie = null): array
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
    curl_close($ch);
    return json_decode($raw ?: '', true) ?? [];
}

echo "Login role routing smoke test\n";
$failed = 0;
foreach ($accounts as [$email, $pass, $expectRole, $expectDash]) {
    $cookie = tempnam(sys_get_temp_dir(), 'pms_role_');
    req($base . '/auth/logout', 'POST', [], $cookie);
    $login = req($base . '/auth/login', 'POST', ['email' => $email, 'password' => $pass], $cookie);
    $me = req($base . '/auth/me', 'GET', null, $cookie);
    $role = $me['data']['role'] ?? '?';
    $dash = $me['data']['dashboard'] ?? '?';
    $ok = ($login['success'] ?? false) && $role === $expectRole && $dash === $expectDash;
    echo ($ok ? 'OK  ' : 'FAIL') . " $email → role=$role dashboard=$dash\n";
    if (!$ok) {
        $failed++;
    }
    @unlink($cookie);
}
exit($failed > 0 ? 1 : 0);
