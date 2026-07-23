<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Drive registration / lifecycle helpers (deadline → Closed, completed stays completed).
 */
final class DriveLifecycle
{
    /** College timezone for deadline / recruitment day comparisons. */
    private const TZ = 'Asia/Kolkata';

    public static function todayYmd(): string
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
        } catch (\Throwable) {
            return date('Y-m-d');
        }
    }

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

    /**
     * Company recruitment / drive day (drive.date), not the apply deadline.
     *
     * @param array<string, mixed> $drive
     */
    public static function recruitmentDate(array $drive): string
    {
        foreach ([
            $drive['date'] ?? '',
            $drive['recruitmentDate'] ?? '',
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

        $today = self::todayYmd();

        $deadline = self::registrationDeadline($drive);
        if ($deadline !== '' && $today > $deadline) {
            return 'closed';
        }

        // After the recruitment day, hide from students even when no separate
        // registration deadline was set (status may still be scheduled/open in DB).
        $recruitment = self::recruitmentDate($drive);
        if ($recruitment !== '' && $today > $recruitment) {
            return 'closed';
        }

        return in_array($raw, ['scheduled', 'ongoing'], true) ? $raw : 'scheduled';
    }

    /**
     * Students/alumni may only see drives that are still open for registration.
     *
     * @param array<string, mixed> $drive
     */
    public static function isOpenForStudents(array $drive): bool
    {
        return self::isRegistrationOpen($drive);
    }

    /**
     * Non-admin roles (officer, staff, company, student, alumni) only see open drives.
     * Placement admin (`admin`) keeps closed/completed for oversight.
     *
     * @param array<string, mixed> $drive
     */
    public static function isVisibleToRole(array $drive, string $role): bool
    {
        if (strtolower(trim($role)) === 'admin') {
            return true;
        }

        return self::isOpenForStudents($drive);
    }

    /**
     * @param list<array<string, mixed>> $drives
     * @return list<array<string, mixed>>
     */
    public static function filterForRole(array $drives, string $role): array
    {
        if (strtolower(trim($role)) === 'admin') {
            return array_values($drives);
        }

        return array_values(array_filter(
            $drives,
            static fn (array $drive): bool => self::isOpenForStudents($drive)
        ));
    }

    /**
     * @param array<string, mixed> $drive
     */
    public static function isRegistrationOpen(array $drive): bool
    {
        return !in_array(self::effectiveStatus($drive), ['completed', 'closed'], true);
    }
}
