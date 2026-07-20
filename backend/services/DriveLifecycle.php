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
        $deadline = trim((string) ($eligibility['deadline'] ?? ''));
        if ($deadline === '') {
            $deadline = trim((string) ($drive['registrationDeadline'] ?? ''));
        }
        // Prefer nested eligibility; allow a distinct registrationDeadline field only.
        // Do not use recruitment `date` here — that would close every drive on drive day.
        if ($deadline === '' || $deadline === '—' || strtoupper($deadline) === 'TBD') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $deadline, $m)) {
            return $m[1];
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
