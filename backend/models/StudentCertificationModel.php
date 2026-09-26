<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

class StudentCertificationModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_CERTIFICATIONS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `student_certifications` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              pair_key VARCHAR(64)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_student_certification (pair_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $studentId, string $certificationId): string
    {
        return $studentId . ':' . $certificationId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPair(string $studentId, string $certificationId): ?array
    {
        return $this->findOne(['pairKey' => self::pairKey($studentId, $certificationId)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByCertification(string $certificationId): array
    {
        return $this->findAll(['certificationId' => $certificationId], 5000);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStudent(string $studentId): array
    {
        return $this->findAll(['studentId' => $studentId], 500);
    }
}
