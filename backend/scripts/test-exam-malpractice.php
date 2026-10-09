<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PMS\Services\ExamMalpracticeService;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$base = [
    'status' => 'ACTIVE',
    'malpracticeViolationCount' => 0,
    'malpracticePendingWarning' => 0,
    'malpracticeAckRequired' => false,
    'malpracticeState' => 'ACTIVE',
    'terminationReason' => '',
];

// Incident 1
$r1 = ExamMalpracticeService::recordIncident($base);
assertTrue($r1['response']['incremented'] === true, 'first incident increments');
assertTrue($r1['response']['violationCount'] === 1, 'count is 1');
assertTrue($r1['response']['ackRequired'] === true, 'ack required after first');
$attempt = array_merge($base, $r1['patch']);

// Pending ack — no extra strike
$r1b = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r1b['response']['incremented'] === false, 'no increment while ack pending');
assertTrue($r1b['response']['violationCount'] === 1, 'count stays 1');

// Acknowledge
$ack = ExamMalpracticeService::acknowledge($attempt, 5000);
$attempt = array_merge($attempt, $ack['patch']);
assertTrue($ack['response']['ackRequired'] === false, 'ack clears pending');

// Incident 2
$r2 = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r2['response']['violationCount'] === 2, 'count is 2');
$attempt = array_merge($attempt, $r2['patch']);
$attempt = array_merge($attempt, ExamMalpracticeService::acknowledge($attempt, 0)['patch']);

// Incident 3 — terminate
$r3 = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r3['response']['shouldAutoSubmit'] === true, 'third incident triggers auto submit');
assertTrue($r3['response']['violationCount'] === 3, 'count is 3');
assertTrue(($r3['patch']['terminationReason'] ?? '') === ExamMalpracticeService::TERMINATION_MALPRACTICE, 'termination reason set');

echo "OK exam malpractice unit checks passed.\n";
