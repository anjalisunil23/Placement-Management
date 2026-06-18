<?php
declare(strict_types=1);

$base = 'http://localhost:8080/backend/api';
$cookie = tempnam(sys_get_temp_dir(), 'pms_po_cookie');

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

echo "PMS Placement Officer smoke test\n";
$login = req($base . '/auth/login', 'POST', ['email' => 'riya@college.edu', 'password' => 'Officer@123456']);
echo 'Login: HTTP ' . $login['code'] . ' success=' . (($login['json']['success'] ?? false) ? 'yes' : 'no') . "\n";
if (!($login['json']['success'] ?? false)) {
    echo substr($login['raw'], 0, 400) . "\n";
    echo "Hint: run php backend/scripts/setup.php to seed PO user.\n";
    exit(1);
}

$paths = [
    '/officer/profile',
    '/officer/dashboard',
    '/officer/students',
    '/officer/students/pending',
    '/officer/applications',
    '/officer/applications/pending',
    '/officer/resumes/pending',
    '/officer/results',
    '/officer/drives',
    '/officer/analytics',
    '/admin/applications',
    '/admin/students',
    '/admin/reports',
];
foreach ($paths as $path) {
    $r = req($base . $path);
    $ok = $r['json']['success'] ?? false;
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : (isset($r['json']['data']) ? 1 : 0);
    echo ($ok ? 'OK  ' : 'FAIL') . " $path (HTTP {$r['code']}, items=$count)\n";
    if (!$ok) {
        echo '  ' . substr($r['raw'], 0, 160) . "\n";
    }
}
echo "Done.\n";
