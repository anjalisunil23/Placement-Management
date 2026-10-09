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

// Incident 1 — sets 5s deadline
$r1 = ExamMalpracticeService::recordIncident($base);
assertTrue($r1['response']['incremented'] === true, 'first incident increments');
assertTrue($r1['response']['violationCount'] === 1, 'count is 1');
assertTrue($r1['response']['ackRequired'] === true, 'ack required after first');
assertTrue(isset($r1['patch']['malpracticeWarningDeadlineAt']), 'deadline persisted');
$attempt = array_merge($base, $r1['patch']);

// Pending ack — no extra strike
$r1b = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r1b['response']['incremented'] === false, 'no increment while ack pending');
assertTrue($r1b['response']['violationCount'] === 1, 'count stays 1');

// Ack within deadline → auto submit (not resume)
$fin = ExamMalpracticeService::finalizeWarning($attempt, 'ack');
assertTrue($fin['response']['shouldAutoSubmit'] === true, 'ack triggers submit');
assertTrue($fin['response']['terminationReason'] === ExamMalpracticeService::TERMINATION_WARNING_ACK, 'ack termination reason');
$attempt = array_merge($attempt, $fin['patch']);
assertTrue(empty($attempt['malpracticeAckRequired']), 'ack cleared');

// Incident 2
$r2 = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r2['response']['violationCount'] === 2, 'count is 2');
$attempt = array_merge($attempt, $r2['patch']);

// Timeout finalize
$timeout = ExamMalpracticeService::finalizeWarning($attempt, 'timeout');
assertTrue($timeout['response']['shouldAutoSubmit'] === true, 'timeout triggers submit');
$attempt = array_merge($attempt, $timeout['patch']);

// Incident 3 — terminate immediately
$r3 = ExamMalpracticeService::recordIncident($attempt);
assertTrue($r3['response']['shouldAutoSubmit'] === true, 'third incident triggers auto submit');
assertTrue($r3['response']['violationCount'] === 3, 'count is 3');
assertTrue(($r3['patch']['terminationReason'] ?? '') === ExamMalpracticeService::TERMINATION_MALPRACTICE, 'termination reason set');

// Expired deadline while pending
$pending = array_merge($base, ExamMalpracticeService::recordIncident($base)['patch']);
$pending['malpracticeWarningDeadlineAt'] = ExamMalpracticeService::nowMs() - 1000;
$exp = ExamMalpracticeService::checkDeadlineExpired($pending);
assertTrue($exp['response']['shouldAutoSubmit'] === true, 'expired deadline auto submits');

echo "OK exam malpractice unit checks passed.\n";
