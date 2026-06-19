<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_crud_');
$failed = 0;
$createdDeptId = null;
$createdUserId = null;

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

function assertOk(string $label, array $r, int $expectCode = 200): bool
{
    global $failed;
    $ok = $r['code'] === $expectCode && ($r['json']['success'] ?? false);
    echo ($ok ? 'OK  ' : 'FAIL') . " $label (HTTP {$r['code']})\n";
    if (!$ok) {
        echo '  ' . substr($r['raw'] ?: '', 0, 240) . "\n";
        $failed++;
    }
    return $ok;
}

echo "PMS Admin CRUD smoke test\n";

$login = req($base . '/auth/login', 'POST', ['email' => 'admin@college.edu', 'password' => 'Admin@123456']);
if (!assertOk('Admin login', $login)) {
    exit(1);
}

$me = req($base . '/auth/me');
assertOk('Session /auth/me', $me);

$suffix = (string) time();
$dept = req($base . '/admin/departments', 'POST', [
    'name' => "Smoke Dept $suffix",
    'code' => 'SD' . substr($suffix, -4),
]);
if (assertOk('POST department', $dept, 201)) {
    $createdDeptId = $dept['json']['data']['id'] ?? null;
}

$list = req($base . '/admin/departments');
assertOk('GET departments', $list);

$staff = req($base . '/admin/users', 'POST', [
    'name' => 'Smoke Staff CRUD',
    'email' => "smoke.crud.$suffix@college.edu",
    'password' => 'Staff@123456',
    'role' => 'staff',
    'departmentId' => $createdDeptId ?: ($list['json']['data'][0]['id'] ?? ''),
    'approved' => true,
]);
if (assertOk('POST staff user', $staff, 201)) {
    $createdUserId = $staff['json']['data']['id'] ?? null;
}

$news = req($base . '/admin/placement-news', 'POST', [
    'title' => "Smoke news $suffix",
    'summary' => 'CRUD test item',
    'date' => date('Y-m-d'),
    'link' => '',
]);
$newsId = null;
if (assertOk('POST placement news', $news, 201)) {
    $newsId = $news['json']['data']['id'] ?? null;
}

if ($createdUserId) {
    assertOk('DELETE user', req($base . '/admin/users/' . rawurlencode($createdUserId), 'DELETE'));
}
if ($createdDeptId) {
    assertOk('DELETE department', req($base . '/admin/departments/' . rawurlencode($createdDeptId), 'DELETE'));
}
if ($newsId) {
    assertOk('DELETE news', req($base . '/admin/placement-news/' . rawurlencode($newsId), 'DELETE'));
}

echo $failed === 0 ? "All admin CRUD checks passed.\n" : "$failed check(s) failed.\n";
exit($failed > 0 ? 1 : 0);
