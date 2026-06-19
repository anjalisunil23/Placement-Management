<?php
declare(strict_types=1);

$base = getenv('PMS_API_BASE') ?: 'http://127.0.0.1:8080/backend/api';
$roles = [
    ['rahul.v@college.edu', 'Student@123456', 'student', ['/student/drives', '/student/open-drives', '/student/applications', '/auth/me']],
    ['neha@acme.io', 'Company@123456', 'company', ['/company/dashboard', '/company/jobs', '/auth/me']],
    ['rohan.v@alumni.edu', 'Alumni@123456', 'alumni', ['/alumni/dashboard', '/alumni/job-posts', '/auth/me']],
    ['priya.v@alumni.edu', 'Alumni@123456', 'alumni', ['/alumni/drives', '/auth/me']],
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

echo "Role API smoke test\n";
$failed = 0;
foreach ($roles as [$email, $pass, $role, $paths]) {
    $cookie = tempnam(sys_get_temp_dir(), 'pms_');
    req($base . '/auth/logout', 'POST', [], $cookie);
    $login = req($base . '/auth/login', 'POST', ['email' => $email, 'password' => $pass], $cookie);
    echo ($login['json']['success'] ?? false ? 'LOGIN OK' : 'LOGIN FAIL') . " $email\n";
    if (!($login['json']['success'] ?? false)) {
        $failed++;
        @unlink($cookie);
        continue;
    }
    $me = req($base . '/auth/me', 'GET', null, $cookie);
    $fields = array_keys($me['json']['data'] ?? []);
    echo '  me fields: ' . implode(', ', $fields) . "\n";
    foreach ($paths as $p) {
        if ($p === '/auth/me') {
            continue;
        }
        $r = req($base . $p, 'GET', null, $cookie);
        $ok = $r['code'] === 200 && ($r['json']['success'] ?? false);
        echo ($ok ? '  OK  ' : '  FAIL') . " $p HTTP {$r['code']}\n";
        if (!$ok) {
            echo '        ' . substr($r['raw'] ?? '', 0, 120) . "\n";
            $failed++;
        }
    }
    @unlink($cookie);
}
exit($failed > 0 ? 1 : 0);
