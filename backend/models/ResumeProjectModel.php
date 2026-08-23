<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder projects store (relational, not JSON payload).
 */
final class ResumeProjectModel
{
    public const TITLE_MIN = 3;
    public const TITLE_MAX = 150;
    public const DESC_MIN = 50;
    public const DESC_MAX = 1000;
    public const TECH_MAX = 500;
    public const LINK_MAX = 500;
    public const COMPLETE_MIN_COUNT = 1;
    public const RECOMMENDED_COUNT = 2;

    public const TYPES = [
        'Academic',
        'Personal',
        'Internship',
        'Research',
        'Freelance',
        'Other',
    ];

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_PROJECTS;
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
            "SELECT id, project_title, project_type, technologies_used, project_description,
                    project_link, start_date, end_date, created_at, updated_at
             FROM `{$this->table}`
             WHERE student_id = ?
             ORDER BY COALESCE(end_date, start_date) DESC, updated_at DESC"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForStudent(string $projectId, string $studentId): ?array
    {
        $id = Security::toObjectId($projectId);
        $sid = Security::toObjectId($studentId);
        if ($id === null || $sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, project_title, project_type, technologies_used, project_description,
                    project_link, start_date, end_date, created_at, updated_at
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

    /**
     * @param array{
     *   project_title?: string,
     *   project_type?: string,
     *   technologies_used?: string|null,
     *   project_description?: string,
     *   project_link?: string|null,
     *   start_date?: string|null,
     *   end_date?: string|null
     * } $data
     * @return array{ok: bool, error: string|null, data?: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $title = self::normalizeText((string) ($data['project_title'] ?? ''));
        $type = trim((string) ($data['project_type'] ?? ''));
        $tech = self::normalizeText((string) ($data['technologies_used'] ?? ''));
        $desc = trim((string) ($data['project_description'] ?? ''));
        $link = trim((string) ($data['project_link'] ?? ''));
        $start = trim((string) ($data['start_date'] ?? ''));
        $end = trim((string) ($data['end_date'] ?? ''));

        $titleLen = self::textLength($title);
        if ($titleLen < self::TITLE_MIN) {
            return ['ok' => false, 'error' => 'Project title must be at least ' . self::TITLE_MIN . ' characters.'];
        }
        if ($titleLen > self::TITLE_MAX) {
            return ['ok' => false, 'error' => 'Project title must be at most ' . self::TITLE_MAX . ' characters.'];
        }
        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Select a valid project type.'];
        }

        $descLen = self::textLength($desc);
        if ($descLen < self::DESC_MIN) {
            return ['ok' => false, 'error' => 'Description must be at least ' . self::DESC_MIN . ' characters.'];
        }
        if ($descLen > self::DESC_MAX) {
            return ['ok' => false, 'error' => 'Description must be at most ' . self::DESC_MAX . ' characters.'];
        }

        if ($tech !== '' && self::textLength($tech) > self::TECH_MAX) {
            return ['ok' => false, 'error' => 'Technologies used must be at most ' . self::TECH_MAX . ' characters.'];
        }

        if ($link !== '') {
            if (self::textLength($link) > self::LINK_MAX) {
                return ['ok' => false, 'error' => 'Project link must be at most ' . self::LINK_MAX . ' characters.'];
            }
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'error' => 'Enter a valid project URL (including https://).'];
            }
        }

        $startDate = self::normalizeDate($start);
        if ($start !== '' && $startDate === null) {
            return ['ok' => false, 'error' => 'Enter a valid start date.'];
        }
        $endDate = self::normalizeDate($end);
        if ($end !== '' && $endDate === null) {
            return ['ok' => false, 'error' => 'Enter a valid end date.'];
        }
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            return ['ok' => false, 'error' => 'End date cannot be earlier than start date.'];
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'project_title' => $title,
                'project_type' => $type,
                'technologies_used' => $tech !== '' ? $tech : null,
                'project_description' => $desc,
                'project_link' => $link !== '' ? $link : null,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];
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
              (id, student_id, project_title, project_type, technologies_used, project_description,
               project_link, start_date, end_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $sid,
            $clean['project_title'],
            $clean['project_type'],
            $clean['technologies_used'],
            $clean['project_description'],
            $clean['project_link'],
            $clean['start_date'],
            $clean['end_date'],
            $now,
            $now,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not save project.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateForStudent(string $projectId, string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($projectId);
        if ($sid === null || $id === null) {
            throw new \InvalidArgumentException('Invalid project.');
        }
        $existing = $this->findByIdForStudent($id, $sid);
        if (!$existing) {
            throw new \RuntimeException('Project not found.');
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
             SET project_title = ?, project_type = ?, technologies_used = ?, project_description = ?,
                 project_link = ?, start_date = ?, end_date = ?, updated_at = ?
             WHERE id = ? AND student_id = ?"
        );
        $stmt->execute([
            $clean['project_title'],
            $clean['project_type'],
            $clean['technologies_used'],
            $clean['project_description'],
            $clean['project_link'],
            $clean['start_date'],
            $clean['end_date'],
            $now,
            $id,
            $sid,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not update project.');
        }
        return $row;
    }

    public function deleteForStudent(string $projectId, string $studentId): bool
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($projectId);
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
              project_title VARCHAR(150) NOT NULL,
              project_type VARCHAR(32) NOT NULL,
              technologies_used VARCHAR(500) NULL,
              project_description VARCHAR(1000) NOT NULL,
              project_link VARCHAR(500) NULL,
              start_date DATE NULL,
              end_date DATE NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_resume_projects_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
