<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder career-objective store (relational, not JSON payload).
 */
final class ResumeCareerObjectiveModel
{
    public const MIN_LENGTH = 50;
    public const MAX_LENGTH = 500;

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_CAREER_OBJECTIVES;
        $this->ensureTable();
    }

    /**
     * @return array{id: string, student_id: string, objective_text: string, created_at: string, updated_at: string}|null
     */
    public function findByStudentId(string $studentId): ?array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, student_id, objective_text, created_at, updated_at
             FROM `{$this->table}` WHERE student_id = ? LIMIT 1"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{ok: bool, error: string|null, length: int}
     */
    public static function validateText(string $text): array
    {
        $length = self::textLength($text);
        if ($length < self::MIN_LENGTH) {
            return [
                'ok' => false,
                'error' => 'Career objective must be at least ' . self::MIN_LENGTH . ' characters.',
                'length' => $length,
            ];
        }
        if ($length > self::MAX_LENGTH) {
            return [
                'ok' => false,
                'error' => 'Career objective must be at most ' . self::MAX_LENGTH . ' characters.',
                'length' => $length,
            ];
        }

        return ['ok' => true, 'error' => null, 'length' => $length];
    }

    public static function textLength(string $text): int
    {
        $trimmed = trim($text);
        return function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
    }

    /**
     * @return array{id: string, student_id: string, objective_text: string, created_at: string, updated_at: string}
     */
    public function upsertForStudent(string $studentId, string $objectiveText): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            throw new \InvalidArgumentException('Invalid student.');
        }

        $text = trim($objectiveText);
        $check = self::validateText($text);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }

        $existing = $this->findByStudentId($sid);
        $now = DocumentHelper::now();

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE `{$this->table}` SET objective_text = ?, updated_at = ? WHERE student_id = ?"
            );
            $stmt->execute([$text, $now, $sid]);
            $row = $this->findByStudentId($sid);
            if (!$row) {
                throw new \RuntimeException('Could not update career objective.');
            }
            return $row;
        }

        $id = Security::generateId();
        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` (id, student_id, objective_text, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $sid, $text, $now, $now]);
        $row = $this->findByStudentId($sid);
        if (!$row) {
            throw new \RuntimeException('Could not save career objective.');
        }
        return $row;
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              student_id CHAR(24) NOT NULL,
              objective_text VARCHAR(500) NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uq_resume_career_objectives_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
