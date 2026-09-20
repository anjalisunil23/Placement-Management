<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Department placement representative assignments (students nominated by placement officers).
 */
class StudentVolunteerModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_VOLUNTEERS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    /** Create table if production DB was set up before placement representatives existed. */
    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `student_volunteers` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public function findActiveByStudentId(string $studentId): ?array
    {
        $id = Security::toObjectId($studentId);
        if ($id === null) {
            return null;
        }
        return $this->findOne([
            'studentId' => $id,
            'status'    => 'active',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(array $filter = [], int $limit = 500): array
    {
        return $this->findAll(array_merge(['status' => 'active'], $filter), $limit);
    }
}
