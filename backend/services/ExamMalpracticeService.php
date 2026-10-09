<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Shared malpractice strike logic for timed examination attempts (JSON payload fields).
 */
final class ExamMalpracticeService
{
    public const TERMINATION_MALPRACTICE = 'MALPRACTICE_LIMIT_EXCEEDED';
    public const TERMINATION_WARNING_ACK = 'MALPRACTICE_WARNING_ACKNOWLEDGED';
    public const TERMINATION_WARNING_TIMEOUT = 'MALPRACTICE_WARNING_TIMEOUT';
    public const TERMINATION_WARNING_EXPIRED = 'MALPRACTICE_WARNING_EXPIRED';
    public const MAX_WARNINGS = 2;
    public const TERMINATE_AT_COUNT = 3;
    public const WARNING_ACK_DEADLINE_MS = 5000;

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    public static function recordIncident(array $attempt): array
    {
        $count = max(0, (int) ($attempt['malpracticeViolationCount'] ?? 0));
        $ackRequired = !empty($attempt['malpracticeAckRequired']);
        $termination = (string) ($attempt['terminationReason'] ?? '');

        if ($termination !== '' || !empty($attempt['malpracticeTerminationSubmitDone'])) {
            return self::terminatedResponse($attempt, false);
        }

        if ($count >= self::TERMINATE_AT_COUNT) {
            return self::terminatedResponse($attempt, ($attempt['status'] ?? '') === 'ACTIVE');
        }

        if ($ackRequired) {
            $pending = (int) ($attempt['malpracticePendingWarning'] ?? 0);
            $deadline = (int) ($attempt['malpracticeWarningDeadlineAt'] ?? 0);
            if ($deadline > 0 && self::nowMs() >= $deadline) {
                return self::finalizeWarning($attempt, 'expired');
            }
            return [
                'patch' => [],
                'response' => [
                    'violationCount' => $count,
                    'pendingWarning' => $pending,
                    'ackRequired' => true,
                    'terminated' => false,
                    'shouldAutoSubmit' => false,
                    'incremented' => false,
                    'warningDeadlineAt' => $deadline,
                ],
            ];
        }

        $newCount = $count + 1;
        if ($newCount >= self::TERMINATE_AT_COUNT) {
            return [
                'patch' => [
                    'malpracticeViolationCount' => self::TERMINATE_AT_COUNT,
                    'malpracticePendingWarning' => 0,
                    'malpracticeAckRequired' => false,
                    'malpracticeState' => 'TERMINATING',
                    'terminationReason' => self::TERMINATION_MALPRACTICE,
                    'malpracticeWarningDeadlineAt' => null,
                ],
                'response' => [
                    'violationCount' => self::TERMINATE_AT_COUNT,
                    'pendingWarning' => 0,
                    'ackRequired' => false,
                    'terminated' => true,
                    'shouldAutoSubmit' => true,
                    'finalizeReason' => 'third_strike',
                    'incremented' => true,
                ],
            ];
        }

        $now = self::nowMs();
        $deadline = $now + self::WARNING_ACK_DEADLINE_MS;
        $state = $newCount === 1 ? 'WARNING_1_PENDING' : 'WARNING_2_PENDING';

        return [
            'patch' => [
                'malpracticeViolationCount' => $newCount,
                'malpracticePendingWarning' => $newCount,
                'malpracticeAckRequired' => true,
                'malpracticeState' => $state,
                'malpracticeWarningStartedAt' => $now,
                'malpracticeWarningDeadlineAt' => $deadline,
            ],
            'response' => [
                'violationCount' => $newCount,
                'pendingWarning' => $newCount,
                'ackRequired' => true,
                'terminated' => false,
                'shouldAutoSubmit' => false,
                'incremented' => true,
                'warningDeadlineAt' => $deadline,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    public static function checkDeadlineExpired(array $attempt): array
    {
        if (empty($attempt['malpracticeAckRequired'])) {
            return ['patch' => [], 'response' => ['expired' => false, 'shouldAutoSubmit' => false]];
        }
        $deadline = (int) ($attempt['malpracticeWarningDeadlineAt'] ?? 0);
        if ($deadline <= 0 || self::nowMs() < $deadline) {
            return ['patch' => [], 'response' => ['expired' => false, 'shouldAutoSubmit' => false]];
        }
        return self::finalizeWarning($attempt, 'expired');
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    public static function finalizeWarning(array $attempt, string $reason): array
    {
        $reason = strtolower(trim($reason));
        if (!in_array($reason, ['ack', 'timeout', 'expired'], true)) {
            $reason = 'timeout';
        }

        if (!empty($attempt['malpracticeTerminationSubmitDone'])) {
            return [
                'patch' => [],
                'response' => [
                    'shouldAutoSubmit' => false,
                    'alreadyFinalized' => true,
                    'terminated' => true,
                    'ackRequired' => false,
                ],
            ];
        }

        if (empty($attempt['malpracticeAckRequired'])) {
            return [
                'patch' => [],
                'response' => [
                    'shouldAutoSubmit' => false,
                    'ackRequired' => false,
                    'terminated' => false,
                ],
            ];
        }

        $deadline = (int) ($attempt['malpracticeWarningDeadlineAt'] ?? 0);
        $now = self::nowMs();
        if ($reason === 'ack' && $deadline > 0 && $now > $deadline) {
            $reason = 'expired';
        }

        $terminationReason = match ($reason) {
            'ack' => self::TERMINATION_WARNING_ACK,
            'expired' => self::TERMINATION_WARNING_EXPIRED,
            default => self::TERMINATION_WARNING_TIMEOUT,
        };

        return [
            'patch' => [
                'malpracticeAckRequired' => false,
                'malpracticePendingWarning' => 0,
                'malpracticeState' => 'TERMINATING',
                'terminationReason' => $terminationReason,
                'malpracticeTerminationSubmitDone' => true,
                'malpracticeFinalizeReason' => $reason,
            ],
            'response' => [
                'shouldAutoSubmit' => true,
                'terminated' => true,
                'ackRequired' => false,
                'finalizeReason' => $reason,
                'terminationReason' => $terminationReason,
            ],
        ];
    }

    public static function warningMessage(int $pendingLevel): string
    {
        if ($pendingLevel === 1) {
            return 'WARNING 1 OF 2: You have left the active examination window. Further violations may result in automatic submission.';
        }
        if ($pendingLevel === 2) {
            return 'FINAL WARNING: This is your second malpractice violation. One more violation will automatically submit your examination and end your session.';
        }
        return '';
    }

    public static function warningTitle(int $pendingLevel): string
    {
        return $pendingLevel >= 2 ? 'FINAL MALPRACTICE WARNING' : 'MALPRACTICE WARNING';
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public static function publicState(array $attempt): array
    {
        $deadline = (int) ($attempt['malpracticeWarningDeadlineAt'] ?? 0);
        $remainingMs = $deadline > 0 ? max(0, $deadline - self::nowMs()) : 0;

        return [
            'violationCount' => (int) ($attempt['malpracticeViolationCount'] ?? 0),
            'pendingWarning' => (int) ($attempt['malpracticePendingWarning'] ?? 0),
            'ackRequired' => !empty($attempt['malpracticeAckRequired']),
            'malpracticeState' => (string) ($attempt['malpracticeState'] ?? 'ACTIVE'),
            'terminationReason' => (string) ($attempt['terminationReason'] ?? ''),
            'warningDeadlineAt' => $deadline > 0 ? $deadline : null,
            'warningSecondsRemaining' => $deadline > 0 ? (int) ceil($remainingMs / 1000) : 0,
            'warningExpired' => !empty($attempt['malpracticeAckRequired']) && $deadline > 0 && self::nowMs() >= $deadline,
            'terminationSubmitDone' => !empty($attempt['malpracticeTerminationSubmitDone']),
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    private static function terminatedResponse(array $attempt, bool $shouldAutoSubmit): array
    {
        $count = max(0, (int) ($attempt['malpracticeViolationCount'] ?? 0));
        return [
            'patch' => [],
            'response' => [
                'violationCount' => $count,
                'pendingWarning' => 0,
                'ackRequired' => false,
                'terminated' => true,
                'shouldAutoSubmit' => $shouldAutoSubmit,
                'incremented' => false,
            ],
        ];
    }
}
