<?php
declare(strict_types=1);

/**
 * Smoke test notification APIs for all roles.
 * Usage: php backend/scripts/smoke-notifications.php
 */

$base = getenv('PMS_API_BASE') ?: 'http://127.0.0.1:8080/backend/api';
$roles = [
    ['admin@college.edu', 'Admin@123456', 'admin', '/admin/notifications'],
    ['riya@college.edu', 'Officer@123456', 'placement_officer', '/admin/notifications'],
    ['ravi.iyer@college.edu', 'Staff@123456', 'staff', '/staff/notifications'],
    ['rahul.v@college.edu', 'Student@123456', 'student', '/student/notifications'],
    ['neha@acme.io', 'Company@123456', 'company', '/company/notifications'],
    ['rohan.v@alumni.edu', 'Alumni@123456', 'alumni', '/alumni/notifications'],
    ['priya.v@alumni.edu', 'Alumni@123456', 'alumni', '/alumni/notifications'],
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($raw ?: '', true), 'raw' => $raw];
}

echo "Notification API smoke test\nBase: {$base}\n\n";
$failed = 0;

foreach ($roles as [$email, $pass, $role, $path]) {
    $cookie = tempnam(sys_get_temp_dir(), 'pms_notif_');
    req($base . '/auth/logout', 'POST', [], $cookie);
    $login = req($base . '/auth/login', 'POST', ['email' => $email, 'password' => $pass], $cookie);
    if (!($login['json']['success'] ?? false)) {
        echo "FAIL login {$email}\n";
        $failed++;
        @unlink($cookie);
        continue;
    }

    $list = req($base . $path, 'GET', null, $cookie);
    $ok = $list['code'] === 200 && ($list['json']['success'] ?? false);
    $count = is_array($list['json']['data'] ?? null) ? count($list['json']['data']) : -1;
    echo ($ok ? 'OK  ' : 'FAIL') . " {$role} GET {$path} HTTP {$list['code']} count={$count}\n";
    if (!$ok) {
        echo '      ' . substr($list['raw'] ?? '', 0, 160) . "\n";
        $failed++;
        @unlink($cookie);
        continue;
    }

    $items = $list['json']['data'] ?? [];
    $unread = null;
    foreach ($items as $item) {
        if (empty($item['read'])) {
            $unread = $item;
            break;
        }
    }
    if ($unread) {
        $id = $unread['id'] ?? $unread['_id'] ?? '';
        $read = req($base . $path . '/' . rawurlencode((string) $id) . '/read', 'POST', [], $cookie);
        $readOk = $read['code'] === 200 && ($read['json']['success'] ?? false);
        echo ($readOk ? '  OK  ' : '  FAIL') . " mark read id={$id}\n";
        if (!$readOk) {
            $failed++;
        }
    }

    $all = req($base . $path . '/read-all', 'POST', [], $cookie);
    $allOk = $all['code'] === 200 && ($all['json']['success'] ?? false);
    echo ($allOk ? '  OK  ' : '  FAIL') . " mark all read\n";
    if (!$allOk) {
        $failed++;
    }

    @unlink($cookie);
}

exit($failed > 0 ? 1 : 0);
