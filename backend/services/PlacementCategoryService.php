<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Campus CTC categories (A / B / C) and post-placement dream-offer rules.
 *
 * Category A: CTC > ₹5 LPA, or Tier-1 as listed by Placement Cell
 * Category B: CTC from ₹3 LPA to less than ₹5 LPA
 * Category C: CTC < ₹3 LPA
 *
 * After Category C placement: only Category A Tier-1 drives are allowed
 * (Tier 3 and other categories are hidden/blocked).
 * After Category A placement: no further campus attempts.
 * After Category B placement: only Category A drives (including Tier-1).
 */
final class PlacementCategoryService
{
    public const CATEGORY_A = 'A';
    public const CATEGORY_B = 'B';
    public const CATEGORY_C = 'C';

    /**
     * Parse package text (e.g. "₹4.5 LPA", "3.2") into LPA float.
     */
    public function parseCtcLpa(mixed $package): float
    {
        if (is_numeric($package)) {
            return (float) $package;
        }
        $raw = trim((string) $package);
        if ($raw === '') {
            return 0.0;
        }
        if (preg_match('/([\d]+(?:\.[\d]+)?)\s*(?:-|to)\s*([\d]+(?:\.[\d]+)?)/i', $raw, $range)) {
            return max((float) $range[1], (float) $range[2]);
        }
        if (preg_match('/([\d]+(?:\.[\d]+)?)/', $raw, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }

    public function normalizeTier(mixed $tier): string
    {
        $raw = strtoupper(trim((string) $tier));
        if ($raw === '') {
            return 'Tier 2';
        }
        if (preg_match('/\b(TIER\s*)?1\b/', $raw) || str_contains($raw, 'T1')) {
            return 'Tier 1';
        }
        if (preg_match('/\b(TIER\s*)?3\b/', $raw) || str_contains($raw, 'T3')) {
            return 'Tier 3';
        }
        if (preg_match('/\b(TIER\s*)?2\b/', $raw) || str_contains($raw, 'T2')) {
            return 'Tier 2';
        }

        return 'Tier 2';
    }

    public function isTier1(mixed $tier): bool
    {
        return $this->normalizeTier($tier) === 'Tier 1';
    }

    /**
     * Classify an offer / drive from CTC + tier.
     *
     * @return self::CATEGORY_A|self::CATEGORY_B|self::CATEGORY_C|null
     */
    public function classify(mixed $package, mixed $tier = null): ?string
    {
        if ($this->isTier1($tier)) {
            return self::CATEGORY_A;
        }

        $lpa = $this->parseCtcLpa($package);
        if ($lpa <= 0) {
            return null;
        }
        if ($lpa > 5.0) {
            return self::CATEGORY_A;
        }
        if ($lpa >= 3.0) {
            return self::CATEGORY_B;
        }

        return self::CATEGORY_C;
    }

    /**
     * @param array<string, mixed> $drive
     * @param array<string, mixed>|null $company
     */
    public function classifyDrive(array $drive, ?array $company = null): ?string
    {
        $eligibility = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
        $package = $drive['package']
            ?? $eligibility['package']
            ?? ($company['package'] ?? '');
        $tier = $drive['tier'] ?? ($company['tier'] ?? 'Tier 2');

        return $this->classify($package, $tier);
    }

    /**
     * Highest category the student has already secured (A > B > C).
     *
     * @param array<string, mixed> $student
     * @return self::CATEGORY_A|self::CATEGORY_B|self::CATEGORY_C|null
     */
    public function studentPlacementCategory(array $student): ?string
    {
        if (empty($student['placed'])) {
            return null;
        }

        $stored = strtoupper(trim((string) ($student['placementCategory'] ?? '')));
        if (in_array($stored, [self::CATEGORY_A, self::CATEGORY_B, self::CATEGORY_C], true)) {
            // Still prefer highest from history if better (e.g. later A upgrade).
        }

        $best = in_array($stored, [self::CATEGORY_A, self::CATEGORY_B, self::CATEGORY_C], true) ? $stored : null;
        $rank = static function (?string $cat): int {
            return match ($cat) {
                self::CATEGORY_A => 3,
                self::CATEGORY_B => 2,
                self::CATEGORY_C => 1,
                default => 0,
            };
        };

        $history = is_array($student['placementHistory'] ?? null) ? $student['placementHistory'] : [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $cat = strtoupper(trim((string) ($entry['placementCategory'] ?? '')));
            if (!in_array($cat, [self::CATEGORY_A, self::CATEGORY_B, self::CATEGORY_C], true)) {
                $cat = (string) ($this->classify($entry['package'] ?? '', $entry['tier'] ?? null) ?? '');
            }
            if ($rank($cat !== '' ? $cat : null) > $rank($best)) {
                $best = $cat;
            }
        }

        if ($best !== null) {
            return $best;
        }

        $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
        $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : [];
        $cat = $this->classify(
            $placement['package'] ?? $self['package'] ?? '',
            $placement['tier'] ?? $self['tier'] ?? null
        );

        return $cat;
    }

    /**
     * Whether a placed student may still attempt this drive under A/B/C rules.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $drive
     * @param array<string, mixed>|null $company
     * @return array{allowed: bool, reason: string, studentCategory: ?string, driveCategory: ?string}
     */
    public function mayAttemptDrive(array $student, array $drive, ?array $company = null): array
    {
        $studentCategory = $this->studentPlacementCategory($student);
        $driveCategory = $this->classifyDrive($drive, $company);
        $driveTier = $this->normalizeTier($drive['tier'] ?? ($company['tier'] ?? 'Tier 2'));

        if ($studentCategory === null) {
            return [
                'allowed' => true,
                'reason' => '',
                'studentCategory' => null,
                'driveCategory' => $driveCategory,
            ];
        }

        if ($studentCategory === self::CATEGORY_A) {
            return [
                'allowed' => false,
                'reason' => 'You already have a Category A offer and cannot attempt further campus drives.',
                'studentCategory' => $studentCategory,
                'driveCategory' => $driveCategory,
            ];
        }

        // Category C: only Category A Tier-1 (do not show Tier 3 or other companies).
        if ($studentCategory === self::CATEGORY_C) {
            $ok = $driveCategory === self::CATEGORY_A && $driveTier === 'Tier 1';
            return [
                'allowed' => $ok,
                'reason' => $ok
                    ? ''
                    : 'After a Category C offer you may attempt only Category A Tier-1 companies.',
                'studentCategory' => $studentCategory,
                'driveCategory' => $driveCategory,
            ];
        }

        // Category B: only Category A (Tier-1 or CTC > 5 LPA); hide Tier 3 unless CTC qualifies as A.
        if ($studentCategory === self::CATEGORY_B) {
            $ok = $driveCategory === self::CATEGORY_A && $driveTier !== 'Tier 3';
            return [
                'allowed' => $ok,
                'reason' => $ok
                    ? ''
                    : 'After a Category B offer you may attempt only Category A drives (not Tier 3).',
                'studentCategory' => $studentCategory,
                'driveCategory' => $driveCategory,
            ];
        }

        return [
            'allowed' => true,
            'reason' => '',
            'studentCategory' => $studentCategory,
            'driveCategory' => $driveCategory,
        ];
    }
}
