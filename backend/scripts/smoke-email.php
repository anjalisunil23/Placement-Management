<?php

/**
 * Smoke-test ElasticEmail send (CLI).
 *
 * Usage:
 *   php backend/scripts/smoke-email.php student@gmail.com
 *   php backend/scripts/smoke-email.php student@gmail.com "Test subject" "Hello from AJCE Placements"
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Usage: php backend/scripts/smoke-email.php <email> [subject] [message]\n");
    exit(1);
}

$subject = $argv[2] ?? 'AJCE Placements test';
$message = $argv[3] ?? 'This is a test email from the placement portal.';

$mail = new PMS\Services\EmailService();
echo 'Enabled: ' . ($mail->isEnabled() ? 'yes' : 'no') . PHP_EOL;

$result = $mail->sendMail([
    'to'      => $to,
    'subject' => $subject,
    'body'    => '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>',
]);

echo 'OK: ' . ($result['ok'] ? 'yes' : 'no') . PHP_EOL;
if ($result['error']) {
    echo 'Error: ' . $result['error'] . PHP_EOL;
}
if ($result['response'] !== null) {
    echo 'Response: ' . $result['response'] . PHP_EOL;
}

exit($result['ok'] ? 0 : 1);
