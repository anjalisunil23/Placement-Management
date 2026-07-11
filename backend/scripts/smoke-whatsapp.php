<?php

/**
 * Smoke-test WhatsApp placement send (CLI).
 *
 * Usage:
 *   php backend/scripts/smoke-whatsapp.php 9496440324
 *   php backend/scripts/smoke-whatsapp.php 9496440324 "Placement verified" "Your placement with Acme has been verified."
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Usage: php backend/scripts/smoke-whatsapp.php <phone> [title] [message]\n");
    exit(1);
}

$title = $argv[2] ?? 'AJCE Placements';
$message = $argv[3] ?? 'This is a test placement update from PlaceHub.';

$wa = new PMS\Services\WhatsAppService();
echo 'Enabled: ' . ($wa->isEnabled() ? 'yes' : 'no') . PHP_EOL;
echo 'Normalized: ' . $wa->normalizePhone($to) . PHP_EOL;

$result = $wa->sendPlacementUpdate($to, $title, $message);
echo 'OK: ' . ($result['ok'] ? 'yes' : 'no') . PHP_EOL;
if ($result['error']) {
    echo 'Error: ' . $result['error'] . PHP_EOL;
}
if ($result['response'] !== null) {
    echo 'Response: ' . $result['response'] . PHP_EOL;
}

exit($result['ok'] ? 0 : 1);
