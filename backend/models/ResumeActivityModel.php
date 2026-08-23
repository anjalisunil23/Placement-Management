<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder achievements/activities store (relational, not JSON payload).
 */
final class ResumeActivityModel
{
    public const TITLE_MIN = 3;
    public const TITLE_MAX = 150;
    public const DESC_MIN = 20;
    public const DESC_MAX = 1000;
    public const ORG_MAX = 150;
    public const COMPLETE_MIN_COUNT = 1;
    public const RECOMMENDED_COUNT = 2;

    public const TYPES = [
        'Achievement',
        'Leadership',
        'Club Membership',
        'Professional Membership',
        'Volunteer Work',
        'Sports',
        'Arts & Culture',
        'Event Coordination',
        'Competition',
        'Community Service',
        'Other',
    ];

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_ACTIVITIES;
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
            "SELECT id, title, activity_type, organization, description, activity_date, created_at, updated_at
             FROM `{$this->table}`
             WHERE student_id = ?
             ORDER BY COALESCE(activity_date, updated_at) DESC, updated_at DESC"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForStudent(string $activityId, string $studentId): ?array
    {
        $id = Security::toObjectId($activityId);
        $sid = Security::toObjectId($studentId);
        if ($id === null || $sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, title, activity_type, organization, description, activity_date, created_at, updated_at
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
     * @param array{
     *   title?: string,
     *   activity_type?: string,
     *   organization?: string|null,
     *   description?: string,
     *   activity_date?: string|null
     * } $data
     * @return array{ok: bool, error: string|null, data?: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $title = self::normalizeText((string) ($data['title'] ?? ''));
        $type = trim((string) ($data['activity_type'] ?? ''));
        $org = self::normalizeText((string) ($data['organization'] ?? ''));
        $desc = trim((string) ($data['description'] ?? ''));
        $activityDateRaw = trim((string) ($data['activity_date'] ?? ''));

        $titleLen = self::textLength($title);
        if ($titleLen < self::TITLE_MIN) {
            return ['ok' => false, 'error' => 'Title must be at least ' . self::TITLE_MIN . ' characters.'];
        }
        if ($titleLen > self::TITLE_MAX) {
            return ['ok' => false, 'error' => 'Title must be at most ' . self::TITLE_MAX . ' characters.'];
        }

        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Select a valid activity type.'];
        }

        $descLen = self::textLength($desc);
        if ($descLen < self::DESC_MIN) {
            return ['ok' => false, 'error' => 'Description must be at least ' . self::DESC_MIN . ' characters.'];
        }
        if ($descLen > self::DESC_MAX) {
            return ['ok' => false, 'error' => 'Description must be at most ' . self::DESC_MAX . ' characters.'];
        }

        if ($org !== '' && self::textLength($org) > self::ORG_MAX) {
            return ['ok' => false, 'error' => 'Organization must be at most ' . self::ORG_MAX . ' characters.'];
        }

        $activityDate = null;
        if ($activityDateRaw !== '') {
            $activityDate = self::normalizeDate($activityDateRaw);
            if ($activityDate === null) {
                return ['ok' => false, 'error' => 'Enter a valid activity date.'];
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'title' => $title,
                'activity_type' => $type,
                'organization' => $org !== '' ? $org : null,
                'description' => $desc,
                'activity_date' => $activityDate,
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
              (id, student_id, title, activity_type, organization, description, activity_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $sid,
            $clean['title'],
            $clean['activity_type'],
            $clean['organization'],
            $clean['description'],
            $clean['activity_date'],
            $now,
            $now,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not save activity.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateForStudent(string $activityId, string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($activityId);
        if ($sid === null || $id === null) {
            throw new \InvalidArgumentException('Invalid activity.');
        }
        $existing = $this->findByIdForStudent($id, $sid);
        if (!$existing) {
            throw new \RuntimeException('Activity not found.');
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
             SET title = ?, activity_type = ?, organization = ?, description = ?, activity_date = ?, updated_at = ?
             WHERE id = ? AND student_id = ?"
        );
        $stmt->execute([
            $clean['title'],
            $clean['activity_type'],
            $clean['organization'],
            $clean['description'],
            $clean['activity_date'],
            $now,
            $id,
            $sid,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not update activity.');
        }
        return $row;
    }

    public function deleteForStudent(string $activityId, string $studentId): bool
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($activityId);
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
              title VARCHAR(150) NOT NULL,
              activity_type VARCHAR(32) NOT NULL,
              organization VARCHAR(150) NULL,
              description VARCHAR(1000) NOT NULL,
              activity_date DATE NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_resume_activities_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
