<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

/**
 * Staff / class-teacher placement & higher-education registry (one row per student).
 */
class StudentPlacementModel extends BaseModel
{
    private static bool $tableReady = false;

    private static bool $tableUnavailable = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_PLACEMENTS;
    }

    /**
     * Ensure table exists or confirm it is readable (schema.sql on hosts without CREATE privilege).
     */
    private function bootstrapTable(): bool
    {
        if (self::$tableUnavailable) {
            return false;
        }
        if (self::$tableReady) {
            return true;
        }

        try {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS `student_placements` (
                  id CHAR(24) NOT NULL PRIMARY KEY,
                  payload JSON NOT NULL,
                  student_id CHAR(24)
                    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
                  pair_key VARCHAR(64)
                    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
                  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                  UNIQUE KEY uniq_student_placement (student_id),
                  UNIQUE KEY uniq_student_placement_pair (pair_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            self::$tableReady = true;

            return true;
        } catch (\Throwable) {
            try {
                $this->db->query('SELECT 1 FROM `student_placements` LIMIT 1');
                self::$tableReady = true;

                return true;
            } catch (\Throwable) {
                self::$tableUnavailable = true;

                return false;
            }
        }
    }

    public static function pairKey(string $studentId): string
    {
        return $studentId;
    }

    /**
     * @return array<string, mixed>|null Placement fields (company, role, …) without row metadata.
     */
    public function findPlacementByStudent(string $studentId): ?array
    {
        if (!$this->bootstrapTable()) {
            return null;
        }

        $doc = $this->findOne(['pairKey' => self::pairKey($studentId)]);
        if (!$doc) {
            return null;
        }

        return self::placementFieldsFromDoc($doc);
    }

    /**
     * @param array<int, string> $studentIds
     * @return array<string, array<string, mixed>> studentId => placement fields
     */
    public function findPlacementMapByStudentIds(array $studentIds): array
    {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $clean = [];
        foreach ($studentIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $clean[$id] = true;
            }
        }
        $ids = array_keys($clean);
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            $rows = $this->findAll(['studentId' => ['$in' => $chunk]], count($chunk));
            foreach ($rows as $doc) {
                $sid = trim((string) ($doc['studentId'] ?? ''));
                if ($sid === '') {
                    continue;
                }
                $map[$sid] = self::placementFieldsFromDoc($doc);
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $registerNumbers
     * @return array<string, array<string, mixed>> UPPERCASE register => placement fields
     */
    public function findPlacementMapByRegisterNumbers(array $registerNumbers): array
    {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $clean = [];
        foreach ($registerNumbers as $reg) {
            $reg = strtoupper(trim((string) $reg));
            if ($reg !== '') {
                $clean[$reg] = true;
            }
        }
        $regs = array_keys($clean);
        if ($regs === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($regs, 300) as $chunk) {
            $rows = $this->findAll(['registerNumber' => ['$in' => $chunk]], count($chunk));
            foreach ($rows as $doc) {
                $reg = strtoupper(trim((string) ($doc['registerNumber'] ?? '')));
                if ($reg === '') {
                    continue;
                }
                $map[$reg] = self::placementFieldsFromDoc($doc);
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $placement Same shape as students.placement
     */
    public function upsertForStudent(string $studentId, string $registerNumber, array $placement, ?string $departmentId = null): string
    {
        if (!$this->bootstrapTable()) {
            return '';
        }

        $register = strtoupper(trim($registerNumber));
        $now = DocumentHelper::now();
        $set = array_merge($placement, [
            'pairKey'        => self::pairKey($studentId),
            'studentId'      => $studentId,
            'registerNumber' => $register,
            'updatedAt'      => $now,
        ]);
        if ($departmentId !== null && trim($departmentId) !== '') {
            $set['departmentId'] = trim($departmentId);
        }

        return $this->upsert(
            ['pairKey' => self::pairKey($studentId)],
            $set,
            ['createdAt' => $now]
        );
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function placementFieldsFromDoc(array $doc): array
    {
        unset($doc['_id'], $doc['pairKey'], $doc['studentId'], $doc['registerNumber'], $doc['departmentId']);
        unset($doc['createdAt'], $doc['updatedAt']);

        return $doc;
    }
}
