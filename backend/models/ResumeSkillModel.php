<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder skills store (relational, not JSON payload).
 */
final class ResumeSkillModel
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 50;
    public const COMPLETE_MIN_COUNT = 3;

    public const CATEGORIES = [
        'Technical',
        'Tools',
        'Soft Skills',
        'Domain Skills',
        'Languages',
    ];

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_SKILLS;
        $this->ensureTable();
    }

    /**
     * @return list<array{id: string, skill_name: string, skill_category: string, created_at: string, updated_at: string}>
     */
    public function listByStudentId(string $studentId): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT id, skill_name, skill_category, created_at, updated_at
             FROM `{$this->table}`
             WHERE student_id = ?
             ORDER BY skill_category ASC, skill_name ASC"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{id: string, skill_name: string, skill_category: string, created_at: string, updated_at: string}|null
     */
    public function findByIdForStudent(string $skillId, string $studentId): ?array
    {
        $id = Security::toObjectId($skillId);
        $sid = Security::toObjectId($studentId);
        if ($id === null || $sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, skill_name, skill_category, created_at, updated_at
             FROM `{$this->table}`
             WHERE id = ? AND student_id = ?
             LIMIT 1"
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return $name;
    }

    public static function textLength(string $text): int
    {
        $trimmed = self::normalizeName($text);
        return function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public static function validate(string $name, string $category): array
    {
        $name = self::normalizeName($name);
        $length = self::textLength($name);
        if ($length < self::MIN_LENGTH) {
            return ['ok' => false, 'error' => 'Skill name must be at least ' . self::MIN_LENGTH . ' characters.'];
        }
        if ($length > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => 'Skill name must be at most ' . self::MAX_LENGTH . ' characters.'];
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['ok' => false, 'error' => 'Select a valid skill category.'];
        }

        return ['ok' => true, 'error' => null];
    }

    public function hasDuplicate(string $studentId, string $skillName, ?string $exceptId = null): bool
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return false;
        }
        $name = self::normalizeName($skillName);
        $sql = "SELECT id FROM `{$this->table}` WHERE student_id = ? AND skill_name = ?";
        $params = [$sid, $name];
        if ($exceptId !== null && Security::toObjectId($exceptId) !== null) {
            $sql .= ' AND id <> ?';
            $params[] = Security::toObjectId($exceptId);
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{id: string, skill_name: string, skill_category: string, created_at: string, updated_at: string}
     */
    public function createForStudent(string $studentId, string $skillName, string $category): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            throw new \InvalidArgumentException('Invalid student.');
        }

        $name = self::normalizeName($skillName);
        $check = self::validate($name, $category);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        if ($this->hasDuplicate($sid, $name)) {
            throw new \InvalidArgumentException('This skill is already added.');
        }

        $id = Security::generateId();
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` (id, student_id, skill_name, skill_category, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $sid, $name, $category, $now, $now]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not save skill.');
        }
        return $row;
    }

    /**
     * @return array{id: string, skill_name: string, skill_category: string, created_at: string, updated_at: string}
     */
    public function updateForStudent(string $skillId, string $studentId, string $skillName, string $category): array
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($skillId);
        if ($sid === null || $id === null) {
            throw new \InvalidArgumentException('Invalid skill.');
        }

        $existing = $this->findByIdForStudent($id, $sid);
        if (!$existing) {
            throw new \RuntimeException('Skill not found.');
        }

        $name = self::normalizeName($skillName);
        $check = self::validate($name, $category);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        if ($this->hasDuplicate($sid, $name, $id)) {
            throw new \InvalidArgumentException('This skill is already added.');
        }

        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}` SET skill_name = ?, skill_category = ?, updated_at = ? WHERE id = ? AND student_id = ?"
        );
        $stmt->execute([$name, $category, $now, $id, $sid]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not update skill.');
        }
        return $row;
    }

    public function deleteForStudent(string $skillId, string $studentId): bool
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($skillId);
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
              skill_name VARCHAR(50) NOT NULL,
              skill_category VARCHAR(32) NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uq_resume_skills_student_name (student_id, skill_name),
              KEY idx_resume_skills_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
