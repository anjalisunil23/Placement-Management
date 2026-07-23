<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class DriveModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::DRIVES;
    }

    /**
     * Normalize drive titles for duplicate detection.
     */
    public static function normalizeDriveTitle(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * Status-lookup search: match title (and raw payload) without loading every drive.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByTitleContains(string $needle, int $limit = 100): array
    {
        $needle = strtolower(trim($needle));
        if (strlen($needle) < 2) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';

        $sql = "SELECT id, payload, created_at, updated_at FROM `{$this->table}`
            WHERE LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.title')), '')) LIKE ?
               OR LOWER(payload) LIKE ?
            ORDER BY created_at DESC
            LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$like, $like]);

        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->rowToDoc($row);
        }

        return $results;
    }

    /**
     * Find a drive with the same company, title, date, and department scope.
     *
     * @return array<string, mixed>|null
     */
    public function findDuplicateDrive(
        string $companyId,
        string $title,
        string $date,
        ?string $excludeId = null,
        ?string $departmentId = null
    ): ?array {
        $companyId = trim($companyId);
        $titleNorm = self::normalizeDriveTitle($title);
        $date = trim($date);
        if ($companyId === '' || $titleNorm === '' || $date === '') {
            return null;
        }

        $companyOid = Security::toObjectId($companyId);
        if ($companyOid === null) {
            return null;
        }

        $scopeDept = trim((string) ($departmentId ?? ''));

        foreach ($this->findAll([], 3000) as $drive) {
            $id = (string) ($drive['_id'] ?? '');
            if ($excludeId !== null && $excludeId !== '' && $id === $excludeId) {
                continue;
            }
            $driveCompany = (string) ($drive['companyId'] ?? '');
            if ($driveCompany !== $companyId && $driveCompany !== (string) $companyOid) {
                continue;
            }
            if (self::normalizeDriveTitle((string) ($drive['title'] ?? '')) !== $titleNorm) {
                continue;
            }
            if (trim((string) ($drive['date'] ?? '')) !== $date) {
                continue;
            }

            $driveDept = trim((string) ($drive['departmentId'] ?? ''));
            if ($scopeDept !== '') {
                if ($driveDept !== $scopeDept) {
                    continue;
                }
            } elseif ($driveDept !== '') {
                continue;
            }

            return $drive;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDrive(array $data, string $createdBy): string
    {
        $doc = [
            'title'       => $data['title'],
            'companyId'   => Security::toObjectId($data['companyId']),
            'type'        => $data['type'],
            'date'        => $data['date'],
            'time'        => $data['time'],
            'branches'    => $data['branches'] ?? [],
            'eligibility' => $data['eligibility'] ?? [],
            'selectionRounds' => self::normalizeSelectionRounds($data['selectionRounds'] ?? []),
            'roundProgression' => self::normalizeRoundProgression($data['roundProgression'] ?? null),
            'applicationFields' => self::normalizeApplicationFields($data['applicationFields'] ?? []),
            'tier'        => $data['tier'] ?? 'Tier 2',
            'jdFile'      => $data['jdFile'] ?? null,
            'attendance'  => [],
            'results'     => [],
            'status'      => 'scheduled',
            'createdBy'   => Security::toObjectId($createdBy),
            'departmentId'=> isset($data['departmentId']) ? Security::toObjectId($data['departmentId']) : null,
        ];
        return $this->insert($doc);
    }

    /**
     * Company shortlist selection rounds for a drive.
     *
     * @param mixed $raw
     * @return list<array{order:int,type:string,label:string}>
     */
    public static function normalizeSelectionRounds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $allowed = [
            'interview' => 'Interview',
            'gd' => 'GD',
            'coding' => 'Coding',
            'aptitude' => 'Aptitude',
            'technical' => 'Technical Test',
            'hr' => 'HR Round',
            'other' => 'Other',
        ];
        $out = [];
        $order = 1;
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if ($type === '' || !isset($allowed[$type])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $allowed[$type];
            }
            $out[] = [
                'order' => $order,
                'type' => $type,
                'label' => $label,
            ];
            $order++;
            if ($order > 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * How company round outcomes advance candidates.
     * - sequential: only Select on round N unlocks round N+1
     * - parallel: every round can be marked independently
     */
    public static function normalizeRoundProgression(mixed $raw): string
    {
        $value = strtolower(trim((string) ($raw ?? '')));
        if (in_array($value, ['sequential', 'gated', 'type1', 'type_1'], true)) {
            return 'sequential';
        }
        if (in_array($value, ['parallel', 'independent', 'type2', 'type_2'], true)) {
            return 'parallel';
        }
        // Existing drives without a value keep independent behaviour.
        return 'parallel';
    }

    /**
     * Extra text fields students fill when applying to this drive.
     *
     * @param mixed $raw
     * @return list<array{id:string,title:string,required:bool}>
     */
    public static function normalizeApplicationFields(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        $n = 1;
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['label'] ?? $row['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $id)) {
                $id = 'field_' . $n;
            }
            if (isset($seen[$id])) {
                $id = $id . '_' . $n;
            }
            $seen[$id] = true;
            $requiredRaw = $row['required'] ?? false;
            $required = $requiredRaw === true
                || $requiredRaw === 1
                || $requiredRaw === '1'
                || strtolower((string) $requiredRaw) === 'true';
            $out[] = [
                'id' => $id,
                'title' => mb_substr($title, 0, 120),
                'required' => $required,
            ];
            $n++;
            if ($n > 20) {
                break;
            }
        }

        return $out;
    }

    public function markAttendance(string $driveId, string $studentId, bool $present): bool
    {
        $drive = $this->findById($driveId);
        if (!$drive) {
            return false;
        }
        $attendance = $drive['attendance'] ?? [];
        $studentId = Security::toObjectId($studentId);
        $found = false;
        foreach ($attendance as &$entry) {
            if ((string) ($entry['studentId'] ?? '') === (string) $studentId) {
                $entry['present'] = $present;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (!$found) {
            $attendance[] = [
                'studentId' => Security::toObjectId($studentId),
                'present'   => $present,
            ];
        }
        return $this->update($driveId, ['attendance' => $attendance]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByCompanyId(string $companyId, int $limit = 100): array
    {
        $oid = Security::toObjectId($companyId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['companyId' => $oid], $limit);
    }

    public function updateEligibility(string $driveId, array $eligibility): bool
    {
        return $this->update($driveId, ['eligibility' => $eligibility]);
    }
}
