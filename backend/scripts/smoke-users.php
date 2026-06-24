<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_users_');
$createdUserIds = [];
$createdCompanyIds = [];
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

function assertOk(string $label, array $r, int $expectCode = 200): bool
{
    global $failed;
    $ok = $r['code'] === $expectCode && ($r['json']['success'] ?? false);
    echo ($ok ? 'OK  ' : 'FAIL') . " $label (HTTP {$r['code']})\n";
    if (!$ok) {
        echo '  ' . substr($r['raw'] ?: '', 0, 200) . "\n";
        $failed++;
    }
    return $ok;
}

echo "PMS User management smoke test\n";

$login = req($base . '/auth/login', 'POST', ['email' => 'placements@amaljyothi.ac.in', 'password' => 'Placements@2026']);
if (!assertOk('Admin login', $login)) {
    exit(1);
}

$depts = req($base . '/admin/departments');
if (!assertOk('List departments', $depts)) {
    exit(1);
}
$deptList = $depts['json']['data'] ?? [];
$staffDept = $deptList[0]['id'] ?? $deptList[0]['_id'] ?? null;
$officerDept = null;
foreach ($deptList as $d) {
    if (empty($d['hasOfficer'])) {
        $officerDept = $d['id'] ?? $d['_id'] ?? null;
        break;
    }
}

$suffix = (string) time();

$staff = req($base . '/admin/users', 'POST', [
    'name' => 'Smoke Staff',
    'email' => "smoke.staff.$suffix@college.edu",
    'password' => 'Staff@123456',
    'role' => 'staff',
    'departmentId' => $staffDept,
    'designation' => 'Faculty',
    'approved' => true,
]);
if (assertOk('Create staff', $staff, 201) && !empty($staff['json']['data']['id'])) {
    $createdUserIds[] = $staff['json']['data']['id'];
}

if ($officerDept) {
    $officer = req($base . '/admin/users', 'POST', [
        'name' => 'Smoke Officer',
        'email' => "smoke.officer.$suffix@college.edu",
        'password' => 'Officer@123456',
        'role' => 'placement_officer',
        'departmentId' => $officerDept,
        'designation' => 'Department Placement Officer',
        'approved' => true,
    ]);
    if (assertOk('Create placement officer', $officer, 201) && !empty($officer['json']['data']['id'])) {
        $createdUserIds[] = $officer['json']['data']['id'];
        $officerEmail = "smoke.officer.$suffix@college.edu";
        $poCookie = tempnam(sys_get_temp_dir(), 'pms_po_');
        $adminCookie = $cookie;
        $cookie = $poCookie;
        $poLogin = req($base . '/auth/login', 'POST', ['email' => $officerEmail, 'password' => 'Officer@123456']);
        assertOk('Placement officer login after create', $poLogin);
        $cookie = $adminCookie;
        @unlink($poCookie);
    }
} else {
    echo "SKIP Create placement officer (no free department)\n";
}

$alumni = req($base . '/admin/users', 'POST', [
    'name' => 'Smoke Alumni',
    'email' => "smoke.alumni.$suffix@college.edu",
    'password' => 'Alumni@123456',
    'role' => 'alumni',
    'company' => 'Acme Corp',
    'alumniRole' => 'Engineer',
    'approved' => true,
]);
if (assertOk('Create alumni', $alumni, 201) && !empty($alumni['json']['data']['id'])) {
    $createdUserIds[] = $alumni['json']['data']['id'];
}

$companyUser = req($base . '/admin/users', 'POST', [
    'name' => 'Smoke HR',
    'email' => "smoke.company.$suffix@college.edu",
    'password' => 'Company@123456',
    'role' => 'company',
    'companyName' => "Smoke Co $suffix",
    'category' => 'Product',
    'tier' => 'Tier 2',
    'phone' => '+91 90000 00000',
    'approved' => true,
]);
if (assertOk('Create company user', $companyUser, 201) && !empty($companyUser['json']['data']['id'])) {
    $createdUserIds[] = $companyUser['json']['data']['id'];
}

$companyOnly = req($base . '/admin/companies', 'POST', [
    'companyName' => "Smoke Company Only $suffix",
    'category' => 'Service',
    'tier' => 'Tier 3',
    'contacts' => [['name' => 'HR', 'email' => "hr.$suffix@example.com", 'phone' => '']],
    'associationStatus' => 'active',
]);
if (assertOk('Create company record', $companyOnly, 201) && !empty($companyOnly['json']['data']['id'])) {
    $createdCompanyIds[] = $companyOnly['json']['data']['id'];
}

$users = req($base . '/admin/users');
assertOk('List users', $users);
$companies = req($base . '/admin/companies');
assertOk('List companies', $companies);

if (!empty($createdUserIds[0])) {
    $block = req($base . '/admin/users/' . rawurlencode($createdUserIds[0]) . '/block', 'POST');
    assertOk('Block user', $block);
    $unblock = req($base . '/admin/users/' . rawurlencode($createdUserIds[0]) . '/unblock', 'POST');
    assertOk('Unblock user', $unblock);
}

foreach ($createdCompanyIds as $cid) {
    $del = req($base . '/admin/companies/' . rawurlencode($cid), 'DELETE');
    assertOk("Delete company $cid", $del);
}

foreach (array_reverse($createdUserIds) as $uid) {
    $del = req($base . '/admin/users/' . rawurlencode($uid), 'DELETE');
    assertOk("Delete user $uid", $del);
}

echo $failed === 0 ? "All user management checks passed.\n" : "$failed check(s) failed.\n";
exit($failed > 0 ? 1 : 0);
