<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Shared malpractice strike logic for timed examination attempts (JSON payload fields).
 */
final class ExamMalpracticeService
{
    public const TERMINATION_MALPRACTICE = 'MALPRACTICE_LIMIT_EXCEEDED';
    public const MAX_WARNINGS = 2;
    public const TERMINATE_AT_COUNT = 3;

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    public static function recordIncident(array $attempt): array
    {
        $count = max(0, (int) ($attempt['malpracticeViolationCount'] ?? 0));
        $ackRequired = !empty($attempt['malpracticeAckRequired']);
        $termination = (string) ($attempt['terminationReason'] ?? '');

        if ($termination === self::TERMINATION_MALPRACTICE || $count >= self::TERMINATE_AT_COUNT) {
            return [
                'patch' => [],
                'response' => [
                    'violationCount' => max($count, self::TERMINATE_AT_COUNT),
                    'ackRequired' => false,
                    'pendingWarning' => 0,
                    'terminated' => true,
                    'shouldAutoSubmit' => $count >= self::TERMINATE_AT_COUNT && ($attempt['status'] ?? '') === 'ACTIVE',
                    'incremented' => false,
                ],
            ];
        }

        if ($ackRequired) {
            $pending = (int) ($attempt['malpracticePendingWarning'] ?? 0);
            return [
                'patch' => [],
                'response' => [
                    'violationCount' => $count,
                    'pendingWarning' => $pending,
                    'ackRequired' => true,
                    'terminated' => false,
                    'shouldAutoSubmit' => false,
                    'incremented' => false,
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
                ],
                'response' => [
                    'violationCount' => self::TERMINATE_AT_COUNT,
                    'pendingWarning' => 0,
                    'ackRequired' => false,
                    'terminated' => true,
                    'shouldAutoSubmit' => true,
                    'incremented' => true,
                ],
            ];
        }

        $state = $newCount === 1 ? 'WARNING_1_PENDING' : 'WARNING_2_PENDING';

        return [
            'patch' => [
                'malpracticeViolationCount' => $newCount,
                'malpracticePendingWarning' => $newCount,
                'malpracticeAckRequired' => true,
                'malpracticeState' => $state,
            ],
            'response' => [
                'violationCount' => $newCount,
                'pendingWarning' => $newCount,
                'ackRequired' => true,
                'terminated' => false,
                'shouldAutoSubmit' => false,
                'incremented' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{patch: array<string, mixed>, response: array<string, mixed>}
     */
    public static function acknowledge(array $attempt, int $pausedMs = 0): array
    {
        if (empty($attempt['malpracticeAckRequired'])) {
            return [
                'patch' => [],
                'response' => [
                    'ackRequired' => false,
                    'violationCount' => (int) ($attempt['malpracticeViolationCount'] ?? 0),
                    'endsAt' => $attempt['endsAt'] ?? null,
                ],
            ];
        }

        $patch = [
            'malpracticeAckRequired' => false,
            'malpracticePendingWarning' => 0,
            'malpracticeState' => 'ACTIVE',
        ];
        $endsAt = $attempt['endsAt'] ?? null;
        if ($pausedMs > 0 && is_numeric($endsAt)) {
            $endsAt = (int) $endsAt + $pausedMs;
            $patch['endsAt'] = $endsAt;
        }

        return [
            'patch' => $patch,
            'response' => [
                'ackRequired' => false,
                'violationCount' => (int) ($attempt['malpracticeViolationCount'] ?? 0),
                'endsAt' => $endsAt,
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

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public static function publicState(array $attempt): array
    {
        return [
            'violationCount' => (int) ($attempt['malpracticeViolationCount'] ?? 0),
            'pendingWarning' => (int) ($attempt['malpracticePendingWarning'] ?? 0),
            'ackRequired' => !empty($attempt['malpracticeAckRequired']),
            'malpracticeState' => (string) ($attempt['malpracticeState'] ?? 'ACTIVE'),
            'terminationReason' => (string) ($attempt['terminationReason'] ?? ''),
        ];
    }
}
