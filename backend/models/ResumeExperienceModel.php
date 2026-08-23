<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder experience store (relational, not JSON payload).
 */
final class ResumeExperienceModel
{
    public const ORG_MIN = 3;
    public const ORG_MAX = 150;
    public const POSITION_MIN = 3;
    public const POSITION_MAX = 150;
    public const DESC_MIN = 50;
    public const DESC_MAX = 1000;
    public const LOCATION_MAX = 150;
    public const COMPLETE_MIN_COUNT = 1;
    public const RECOMMENDED_COUNT = 2;

    public const TYPES = [
        'Internship',
        'Industrial Training',
        'Research',
        'Freelance',
        'Volunteer',
        'Part Time',
        'Apprenticeship',
        'Other',
    ];

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_EXPERIENCE;
        $this->ensureTable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByStudentId(string $studentId): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT id, organization_name, position_title, experience_type, location, description,
                    start_date, end_date, currently_working, created_at, updated_at
             FROM `{$this->table}`
             WHERE student_id = ?
             ORDER BY start_date DESC, updated_at DESC"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForStudent(string $experienceId, string $studentId): ?array
    {
        $id = Security::toObjectId($experienceId);
        $sid = Security::toObjectId($studentId);
        if ($id === null || $sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, organization_name, position_title, experience_type, location, description,
                    start_date, end_date, currently_working, created_at, updated_at
             FROM `{$this->table}`
             WHERE id = ? AND student_id = ?
             LIMIT 1"
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function textLength(string $text): int
    {
        $trimmed = trim($text);
        return function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
    }

    public static function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return $text;
    }

    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function parseCurrentlyWorking(array $input): bool
    {
        $raw = $input['currentlyWorking'] ?? $input['currently_working'] ?? false;
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        $text = strtolower(trim((string) $raw));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array{
     *   organization_name?: string,
     *   position_title?: string,
     *   experience_type?: string,
     *   location?: string|null,
     *   description?: string,
     *   start_date?: string,
     *   end_date?: string|null,
     *   currently_working?: bool
     * } $data
     * @return array{ok: bool, error: string|null, data?: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $org = self::normalizeText((string) ($data['organization_name'] ?? ''));
        $position = self::normalizeText((string) ($data['position_title'] ?? ''));
        $type = trim((string) ($data['experience_type'] ?? ''));
        $location = self::normalizeText((string) ($data['location'] ?? ''));
        $desc = trim((string) ($data['description'] ?? ''));
        $start = trim((string) ($data['start_date'] ?? ''));
        $end = trim((string) ($data['end_date'] ?? ''));
        $currentlyWorking = (bool) ($data['currently_working'] ?? false);

        $orgLen = self::textLength($org);
        if ($orgLen < self::ORG_MIN) {
            return ['ok' => false, 'error' => 'Organization name must be at least ' . self::ORG_MIN . ' characters.'];
        }
        if ($orgLen > self::ORG_MAX) {
            return ['ok' => false, 'error' => 'Organization name must be at most ' . self::ORG_MAX . ' characters.'];
        }

        $posLen = self::textLength($position);
        if ($posLen < self::POSITION_MIN) {
            return ['ok' => false, 'error' => 'Position must be at least ' . self::POSITION_MIN . ' characters.'];
        }
        if ($posLen > self::POSITION_MAX) {
            return ['ok' => false, 'error' => 'Position must be at most ' . self::POSITION_MAX . ' characters.'];
        }

        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Select a valid experience type.'];
        }

        $descLen = self::textLength($desc);
        if ($descLen < self::DESC_MIN) {
            return ['ok' => false, 'error' => 'Description must be at least ' . self::DESC_MIN . ' characters.'];
        }
        if ($descLen > self::DESC_MAX) {
            return ['ok' => false, 'error' => 'Description must be at most ' . self::DESC_MAX . ' characters.'];
        }

        if ($location !== '' && self::textLength($location) > self::LOCATION_MAX) {
            return ['ok' => false, 'error' => 'Location must be at most ' . self::LOCATION_MAX . ' characters.'];
        }

        $startDate = self::normalizeDate($start);
        if ($startDate === null) {
            return ['ok' => false, 'error' => 'Enter a valid start date.'];
        }

        $endDate = null;
        if ($currentlyWorking) {
            if ($end !== '') {
                return ['ok' => false, 'error' => 'Clear the end date when currently working here.'];
            }
        } elseif ($end !== '') {
            $endDate = self::normalizeDate($end);
            if ($endDate === null) {
                return ['ok' => false, 'error' => 'Enter a valid end date.'];
            }
            if ($endDate < $startDate) {
                return ['ok' => false, 'error' => 'End date cannot be earlier than start date.'];
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'organization_name' => $org,
                'position_title' => $position,
                'experience_type' => $type,
                'location' => $location !== '' ? $location : null,
                'description' => $desc,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'currently_working' => $currentlyWorking ? 1 : 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createForStudent(string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            throw new \InvalidArgumentException('Invalid student.');
        }
        $check = self::validate($data);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        /** @var array<string, mixed> $clean */
        $clean = $check['data'] ?? [];

        $id = Security::generateId();
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}`
              (id, student_id, organization_name, position_title, experience_type, location, description,
               start_date, end_date, currently_working, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $sid,
            $clean['organization_name'],
            $clean['position_title'],
            $clean['experience_type'],
            $clean['location'],
            $clean['description'],
            $clean['start_date'],
            $clean['end_date'],
            $clean['currently_working'],
            $now,
            $now,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not save experience.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateForStudent(string $experienceId, string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($experienceId);
        if ($sid === null || $id === null) {
            throw new \InvalidArgumentException('Invalid experience.');
        }
        $existing = $this->findByIdForStudent($id, $sid);
        if (!$existing) {
            throw new \RuntimeException('Experience not found.');
        }

        $check = self::validate($data);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        /** @var array<string, mixed> $clean */
        $clean = $check['data'] ?? [];
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}`
             SET organization_name = ?, position_title = ?, experience_type = ?, location = ?, description = ?,
                 start_date = ?, end_date = ?, currently_working = ?, updated_at = ?
             WHERE id = ? AND student_id = ?"
        );
        $stmt->execute([
            $clean['organization_name'],
            $clean['position_title'],
            $clean['experience_type'],
            $clean['location'],
            $clean['description'],
            $clean['start_date'],
            $clean['end_date'],
            $clean['currently_working'],
            $now,
            $id,
            $sid,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not update experience.');
        }
        return $row;
    }

    public function deleteForStudent(string $experienceId, string $studentId): bool
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($experienceId);
        if ($sid === null || $id === null) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ? AND student_id = ?");
        $stmt->execute([$id, $sid]);
        return $stmt->rowCount() > 0;
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              student_id CHAR(24) NOT NULL,
              organization_name VARCHAR(150) NOT NULL,
              position_title VARCHAR(150) NOT NULL,
              experience_type VARCHAR(32) NOT NULL,
              location VARCHAR(150) NULL,
              description VARCHAR(1000) NOT NULL,
              start_date DATE NOT NULL,
              end_date DATE NULL,
              currently_working TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_resume_experience_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
