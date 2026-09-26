<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Whether a completed platform certification is included in the student's resume.
 * Stores a reference only. Certification name and URL stay on the certification record.
 */
final class ResumePlatformCertSelectionModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->ensureTable();
    }

    /**
     * @return array<string, bool> certificationId => selected
     */
    public function mapForStudent(string $studentId): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT certification_id, selected FROM resume_platform_cert_selections WHERE student_id = ?'
        );
        $stmt->execute([$sid]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(string) ($row['certification_id'] ?? '')] = ((int) ($row['selected'] ?? 0)) === 1;
        }

        return $map;
    }

    public function setSelected(string $studentId, string $certificationId, bool $selected): void
    {
        $sid = Security::toObjectId($studentId);
        $cid = Security::toObjectId($certificationId);
        if ($sid === null || $cid === null) {
            throw new \InvalidArgumentException('Certification not found.');
        }
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            'INSERT INTO resume_platform_cert_selections
                (id, student_id, certification_id, selected, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE selected = VALUES(selected), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            Security::generateId(),
            $sid,
            $cid,
            $selected ? 1 : 0,
            $now,
            $now,
        ]);
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS resume_platform_cert_selections (
              id CHAR(24) NOT NULL PRIMARY KEY,
              student_id CHAR(24) NOT NULL,
              certification_id CHAR(24) NOT NULL,
              selected TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_resume_platform_cert (student_id, certification_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
