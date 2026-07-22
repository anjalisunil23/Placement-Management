<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Drive registration / lifecycle helpers (deadline → Closed, completed stays completed).
 */
final class DriveLifecycle
{
    /**
     * Explicit registration deadline only (do not fall back to recruitment date).
     *
     * @param array<string, mixed> $drive
     */
    public static function registrationDeadline(array $drive): string
    {
        $eligibility = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
        foreach ([
            $eligibility['deadline'] ?? '',
            $drive['registrationDeadline'] ?? '',
            // Top-level deadline when saved outside eligibility (not recruitment `date`).
            $drive['deadline'] ?? '',
        ] as $raw) {
            $parsed = self::parseDeadlineDate(trim((string) $raw));
            if ($parsed !== '') {
                return $parsed;
            }
        }

        return '';
    }

    private static function parseDeadlineDate(string $deadline): string
    {
        if ($deadline === '' || $deadline === '—' || strtoupper($deadline) === 'TBD') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $deadline, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $deadline, $m) === 1) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $drive
     */
    public static function effectiveStatus(array $drive): string
    {
        $raw = strtolower(trim((string) ($drive['status'] ?? 'scheduled')));
        if ($raw === 'open') {
            $raw = 'scheduled';
        }
        if ($raw === 'completed') {
            return 'completed';
        }
        if ($raw === 'closed') {
            return 'closed';
        }

        $deadline = self::registrationDeadline($drive);
        if ($deadline !== '' && date('Y-m-d') > $deadline) {
            return 'closed';
        }

        return in_array($raw, ['scheduled', 'ongoing'], true) ? $raw : 'scheduled';
    }

    /**
     * @param array<string, mixed> $drive
     */
    public static function isRegistrationOpen(array $drive): bool
    {
        return !in_array(self::effectiveStatus($drive), ['completed', 'closed'], true);
    }
}
