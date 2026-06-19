<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_staff_cookie');

function req(string $url, string $method = 'GET', ?array $body = null, bool $useCookie = true): array {
    global $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $useCookie ? $cookie : null,
        CURLOPT_COOKIEFILE     => $useCookie ? $cookie : null,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => $raw, 'json' => json_decode($raw ?: '', true)];
}

echo "PMS Staff smoke test\n";
$login = req($base . '/auth/login', 'POST', ['email' => 'ravi.iyer@college.edu', 'password' => 'Staff@123456']);
echo 'Login: HTTP ' . $login['code'] . ' success=' . (($login['json']['success'] ?? false) ? 'yes' : 'no') . "\n";
if (!($login['json']['success'] ?? false)) {
    echo substr($login['raw'], 0, 400) . "\n";
    echo "Hint: run php backend/scripts/setup.php to seed staff user.\n";
    exit(1);
}

$me = req($base . '/auth/me');
$dept = $me['json']['data']['department'] ?? '';
echo 'Profile dept: ' . ($dept !== '' ? $dept : '(missing)') . "\n";

$paths = [
    '/staff/profile',
    '/staff/dashboard',
    '/staff/students',
    '/staff/drives',
    '/staff/hiring-overview',
    '/staff/recommendations',
];
foreach ($paths as $path) {
    $r = req($base . $path);
    $ok = $r['json']['success'] ?? false;
    $data = $r['json']['data'] ?? null;
    $count = is_array($data) ? count($data) : (isset($data) ? 1 : 0);
    echo ($ok ? 'OK  ' : 'FAIL') . " $path (HTTP {$r['code']}, items=$count)\n";
    if (!$ok) {
        echo '  ' . substr($r['raw'], 0, 160) . "\n";
    }
}

$create = req($base . '/staff/recommendations', 'POST', [
    'companyName' => 'Smoke Test Corp',
    'companyWebsite' => 'https://example.com',
    'category' => 'Software',
    'reason' => 'Smoke test recommendation',
    'contact' => ['name' => 'QA Lead', 'email' => 'qa@example.com', 'phone' => '+91 90000 00000'],
]);
echo ($create['json']['success'] ?? false ? 'OK  ' : 'FAIL') . ' POST /staff/recommendations (HTTP ' . $create['code'] . ")\n";
echo "Done.\n";
