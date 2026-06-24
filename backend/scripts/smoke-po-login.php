<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_po_login_');
$failed = 0;

function req(string $url, string $method = 'GET', ?array $body = null): array
{
    global $cookie;
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => $raw, 'json' => json_decode($raw ?: '', true)];
}

$login = req($base . '/auth/login', 'POST', [
    'email'    => 'placements@amaljyothi.ac.in',
    'password' => 'Placements@2026',
]);
if (!($login['json']['success'] ?? false)) {
    echo "FAIL admin login\n";
    exit(1);
}
echo "OK admin login\n";

$depts = req($base . '/admin/departments');
$deptId = null;
foreach ($depts['json']['data'] ?? [] as $dept) {
    $deptId = $dept['id'] ?? $dept['_id'] ?? null;
    if ($deptId) {
        break;
    }
}
if (!$deptId) {
    echo "FAIL no departments\n";
    exit(1);
}

$suffix = (string) time();
$email = "po.smoke.$suffix@college.edu";
$password = 'Officer@123456';

$create = req($base . '/admin/users', 'POST', [
    'name'         => 'Smoke PO',
    'email'        => $email,
    'password'     => $password,
    'role'         => 'placement_officer',
    'departmentId' => $deptId,
    'approved'     => true,
]);
if (!($create['json']['success'] ?? false)) {
    echo "FAIL create PO: " . ($create['raw'] ?? '') . "\n";
    exit(1);
}
echo "OK create PO $email\n";
$userId = $create['json']['data']['id'] ?? '';

$poCookie = tempnam(sys_get_temp_dir(), 'pms_po_');
$adminCookie = $cookie;
$cookie = $poCookie;
$poLogin = req($base . '/auth/login', 'POST', ['email' => $email, 'password' => $password]);
$cookie = $adminCookie;

if (!($poLogin['json']['success'] ?? false) || ($poLogin['json']['data']['role'] ?? '') !== 'placement_officer') {
    echo "FAIL PO login: " . ($poLogin['raw'] ?? '') . "\n";
    $failed++;
} else {
    echo "OK PO login with admin-set password\n";
}

if ($userId !== '') {
    req($base . '/admin/users/' . rawurlencode($userId), 'DELETE');
}

exit($failed > 0 ? 1 : 0);
