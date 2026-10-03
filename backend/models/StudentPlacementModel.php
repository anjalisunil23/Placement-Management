<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Services\ClassInchargeRegistry;
use PMS\Services\DepartmentProgrammeCatalog;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

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
     * @param array<string, mixed> $rosterMeta classBatch, programme, branch, studentName, …
     */
    public function upsertForStudent(
        string $studentId,
        string $registerNumber,
        array $placement,
        ?string $departmentId = null,
        array $rosterMeta = []
    ): string {
        if (!$this->bootstrapTable()) {
            return '';
        }

        $register = strtoupper(trim($registerNumber));
        $now = DocumentHelper::now();
        $set = array_merge($placement, self::normalizeRosterMeta($rosterMeta), [
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
     * Distinct classBatch labels saved on registry rows (includes passed-out cohorts off AES).
     *
     * @return list<string>
     */
    public function findDistinctClassBatches(string $departmentId = '', string $program = '', int $limit = 3000): array
    {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $filter = $this->departmentFilter($departmentId);
        $rows = $this->findAll($filter, max(1, min($limit, 5000)));
        $batches = [];
        foreach ($rows as $doc) {
            $batch = trim((string) ($doc['classBatch'] ?? ''));
            if ($batch === '') {
                continue;
            }
            if ($program !== '' && !self::programmeMatchesBatch($program, $batch, (string) ($doc['programme'] ?? ''))) {
                continue;
            }
            $batches[] = $batch;
        }

        $batches = array_values(array_unique($batches));
        sort($batches, SORT_STRING);

        return $batches;
    }

    /**
     * Roster-shaped student rows from student_placements for a selected class batch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRosterRowsForClass(string $departmentId, string $program, string $batch, int $limit = 500): array
    {
        if (!$this->bootstrapTable() || trim($batch) === '') {
            return [];
        }

        $filter = $this->departmentFilter($departmentId);
        $rows = [];
        foreach ($this->findAll($filter, max(1, min($limit, 5000))) as $doc) {
            $rowBatch = trim((string) ($doc['classBatch'] ?? ''));
            if ($rowBatch === '' || !self::batchMatchesSelection($rowBatch, $batch)) {
                continue;
            }
            if ($program !== '' && !self::programmeMatchesBatch($program, $rowBatch, (string) ($doc['programme'] ?? ''))) {
                continue;
            }
            $rows[] = self::rosterRowFromDocument($doc);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rosterRowFromDocument(array $doc): array
    {
        $studentId = trim((string) ($doc['studentId'] ?? $doc['_id'] ?? ''));
        $register = strtoupper(trim((string) ($doc['registerNumber'] ?? '')));
        $placement = self::placementFieldsFromDoc($doc);
        $personal = [
            'fullName' => trim((string) ($doc['studentName'] ?? '')),
            'phone'    => trim((string) ($doc['phone'] ?? '')),
            'collegeEmail' => trim((string) ($doc['email'] ?? '')),
        ];

        return [
            '_id'            => $studentId,
            'id'             => $studentId,
            'studentId'      => $studentId,
            'registerNumber' => $register,
            'admno'          => trim((string) ($doc['admissionNo'] ?? $register)),
            'displayName'    => $personal['fullName'],
            'personal'       => $personal,
            'phone'          => $personal['phone'],
            'collegeEmail'   => $personal['collegeEmail'],
            'email'          => $personal['email'] ?? $personal['collegeEmail'],
            'classBatch'     => trim((string) ($doc['classBatch'] ?? '')),
            'stud_class'     => trim((string) ($doc['classBatch'] ?? '')),
            'programme'      => trim((string) ($doc['programme'] ?? '')),
            'branch'         => trim((string) ($doc['branch'] ?? '')),
            'courseId'       => trim((string) ($doc['courseId'] ?? '')),
            'branchId'       => trim((string) ($doc['branchId'] ?? '')),
            'departmentId'   => trim((string) ($doc['departmentId'] ?? '')),
            'placement'      => $placement,
            'placed'         => trim((string) ($placement['company'] ?? '')) !== '',
            'source'         => 'student_placements',
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function normalizeRosterMeta(array $meta): array
    {
        $out = [];
        $map = [
            'classBatch'  => ['classBatch', 'stud_class', 'batch'],
            'programme'   => ['programme', 'program', 'stud_course'],
            'branch'      => ['branch', 'stud_branch'],
            'studentName' => ['studentName', 'displayName', 'name'],
            'courseId'    => ['courseId', 'course_id'],
            'branchId'    => ['branchId', 'branch_id'],
            'phone'       => ['phone'],
            'email'       => ['email', 'collegeEmail', 'personalEmail'],
            'admissionNo' => ['admissionNo', 'admno', 'registerNumber'],
        ];
        foreach ($map as $target => $keys) {
            foreach ($keys as $key) {
                $value = trim((string) ($meta[$key] ?? ''));
                if ($value !== '') {
                    $out[$target] = $target === 'admissionNo' && preg_match('/^\d+$/', $value) === 1
                        ? $value
                        : ($target === 'admissionNo' ? strtoupper($value) : $value);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentFilter(string $departmentId): array
    {
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return [];
        }

        return ['departmentId' => $departmentId];
    }

    private static function batchMatchesSelection(string $rowBatch, string $wantBatch): bool
    {
        $rowBatch = trim($rowBatch);
        $wantBatch = trim($wantBatch);
        if ($rowBatch === '' || $wantBatch === '') {
            return false;
        }
        if (strcasecmp($rowBatch, $wantBatch) === 0) {
            return true;
        }
        if (strcasecmp(
            DepartmentProgrammeCatalog::normalizeCode($rowBatch),
            DepartmentProgrammeCatalog::normalizeCode($wantBatch)
        ) === 0) {
            return true;
        }
        $wantCohort = ClassInchargeRegistry::cohortKey($wantBatch);

        return $wantCohort !== ''
            && strcasecmp(ClassInchargeRegistry::cohortKey($rowBatch), $wantCohort) === 0;
    }

    private static function programmeMatchesBatch(string $wantProgram, string $batchLabel, string $rowProgramme): bool
    {
        $want = DepartmentProgrammeCatalog::resolveProgrammeCode($wantProgram);
        if ($want === '') {
            return true;
        }
        $fromRow = DepartmentProgrammeCatalog::resolveProgrammeCode($rowProgramme);
        if ($fromRow !== '' && strcasecmp($fromRow, $want) === 0) {
            return true;
        }
        $norm = DepartmentProgrammeCatalog::normalizeCode($batchLabel);
        $fromBatch = '';
        if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
            $fromBatch = 'INMCA';
        } elseif (str_starts_with($norm, 'MCA')) {
            $fromBatch = 'MCA';
        } elseif (str_contains($norm, 'BCA')) {
            $fromBatch = 'BCA';
        } else {
            $fromBatch = DepartmentProgrammeCatalog::resolveProgrammeCode($batchLabel);
        }

        return $fromBatch !== '' && strcasecmp($fromBatch, $want) === 0;
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function placementFieldsFromDoc(array $doc): array
    {
        unset(
            $doc['_id'],
            $doc['pairKey'],
            $doc['studentId'],
            $doc['registerNumber'],
            $doc['departmentId'],
            $doc['classBatch'],
            $doc['programme'],
            $doc['branch'],
            $doc['studentName'],
            $doc['courseId'],
            $doc['branchId'],
            $doc['phone'],
            $doc['email'],
            $doc['admissionNo'],
            $doc['createdAt'],
            $doc['updatedAt']
        );

        return $doc;
    }
}
