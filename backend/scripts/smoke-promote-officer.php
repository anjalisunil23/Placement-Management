<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_promote_po_');
$failed = 0;
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

echo "PMS promote staff to placement officer smoke test\n";

$login = req($base . '/auth/login', 'POST', [
    'email'    => 'placements@amaljyothi.ac.in',
    'password' => 'Placements@2026',
]);
if (!assertOk('Admin login', $login)) {
    exit(1);
}

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
$email = "staff.promote.$suffix@college.edu";
$password = 'Staff@123456';

$create = req($base . '/admin/users', 'POST', [
    'name'         => 'Promote Staff PO',
    'email'        => $email,
    'password'     => $password,
    'role'         => 'staff',
    'departmentId' => $deptId,
    'approved'     => true,
]);
if (!assertOk('Create staff', $create, 201)) {
    exit(1);
}
$createdUserId = $create['json']['data']['id'] ?? null;

$promote = req($base . '/admin/users/' . rawurlencode((string) $createdUserId) . '/promote-to-officer', 'POST');
if (!assertOk('Promote staff to officer', $promote)) {
    exit(1);
}

$poCookie = tempnam(sys_get_temp_dir(), 'pms_po_');
$adminCookie = $cookie;
$cookie = $poCookie;
$poLogin = req($base . '/auth/login', 'POST', ['email' => $email, 'password' => $password]);
$cookie = $adminCookie;

if (!assertOk('Promoted staff logs in as PO', $poLogin)) {
    $failed++;
} elseif (($poLogin['json']['data']['role'] ?? '') !== 'placement_officer') {
    echo "FAIL role is not placement_officer\n";
    $failed++;
}

$staff2Email = "staff.replace.$suffix@college.edu";
$create2 = req($base . '/admin/users', 'POST', [
    'name'         => 'Replace Staff PO',
    'email'        => $staff2Email,
    'password'     => $password,
    'role'         => 'staff',
    'departmentId' => $deptId,
    'approved'     => true,
]);
$staff2Id = $create2['json']['data']['id'] ?? null;
if (assertOk('Create second staff', $create2, 201) && $staff2Id) {
    $change = req($base . '/admin/users/' . rawurlencode((string) $staff2Id) . '/promote-to-officer', 'POST');
    if (assertOk('Change PO to second staff', $change)) {
        $oldPo = req($base . '/admin/users?role=placement_officer');
        $oldStillPo = false;
        foreach ($oldPo['json']['data'] ?? [] as $row) {
            if (($row['email'] ?? '') === $email && ($row['role'] ?? '') === 'placement_officer') {
                $oldStillPo = true;
                break;
            }
        }
        if ($oldStillPo) {
            echo "FAIL previous PO was not demoted to staff\n";
            $failed++;
        } else {
            echo "OK previous PO demoted after change\n";
        }
    }
    req($base . '/admin/users/' . rawurlencode((string) $staff2Id), 'DELETE');
}

if ($createdUserId) {
    req($base . '/admin/users/' . rawurlencode((string) $createdUserId), 'DELETE');
}

echo $failed === 0 ? "All promote-to-officer checks passed.\n" : "$failed check(s) failed.\n";
exit($failed > 0 ? 1 : 0);
