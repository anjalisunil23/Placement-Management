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
    /** Columns BaseModel SELECT/INSERT/ORDER BY require. */
    private const BASE_TABLE_COLUMNS = ['payload', 'created_at', 'updated_at'];

    private static bool $tableReady = false;

    private static bool $tableUnavailable = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_PLACEMENTS;
    }

    /**
     * Ensure table matches schema.sql (payload + timestamps for BaseModel reads/writes).
     */
    private function bootstrapTable(): bool
    {
        if (self::$tableUnavailable) {
            return false;
        }
        if (self::$tableReady && $this->hasValidSchema()) {
            return true;
        }

        try {
            $this->execCreateStudentPlacementsTable();
        } catch (\Throwable) {
            // Table may already exist from schema.sql without CREATE privilege.
        }

        if (!$this->hasValidSchema() && !$this->repairStudentPlacementsSchema()) {
            self::$tableUnavailable = true;

            return false;
        }

        if (!$this->tableExists()) {
            self::$tableUnavailable = true;

            return false;
        }

        self::$tableReady = true;

        return true;
    }

    private function execCreateStudentPlacementsTable(): void
    {
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
    }

    private function tableExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM `student_placements` LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasValidSchema(): bool
    {
        foreach (self::BASE_TABLE_COLUMNS as $column) {
            if (!$this->hasColumn($column)) {
                return false;
            }
        }

        return true;
    }

    private function hasColumn(string $field): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SHOW COLUMNS FROM `student_placements` WHERE Field = ?'
            );
            $stmt->execute([$field]);

            return (bool) $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fix partial/wrong student_placements definitions that block BaseModel JSON reads.
     */
    private function repairStudentPlacementsSchema(): bool
    {
        if (!$this->tableExists()) {
            try {
                $this->execCreateStudentPlacementsTable();
            } catch (\Throwable) {
                return false;
            }

            return $this->hasValidSchema();
        }

        if ($this->hasValidSchema()) {
            return true;
        }

        $rowCount = 0;
        try {
            $rowCount = (int) $this->db->query('SELECT COUNT(*) FROM `student_placements`')->fetchColumn();
        } catch (\Throwable) {
            return false;
        }

        if ($rowCount === 0) {
            try {
                $this->db->exec('DROP TABLE IF EXISTS `student_placements`');
                $this->execCreateStudentPlacementsTable();
            } catch (\Throwable) {
                return false;
            }

            return $this->hasValidSchema();
        }

        if (!$this->addMissingBaseColumns()) {
            return false;
        }

        $this->ensureGeneratedColumnsAndIndexes();

        return $this->hasValidSchema();
    }

    private function addMissingBaseColumns(): bool
    {
        try {
            if (!$this->hasColumn('payload')) {
                $this->db->exec(
                    "ALTER TABLE `student_placements`
                     ADD COLUMN `payload` JSON NOT NULL DEFAULT ('{}') AFTER `id`"
                );
            }
            if (!$this->hasColumn('created_at')) {
                $this->db->exec(
                    'ALTER TABLE `student_placements`
                     ADD COLUMN `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'
                );
            }
            if (!$this->hasColumn('updated_at')) {
                $this->db->exec(
                    'ALTER TABLE `student_placements`
                     ADD COLUMN `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                     ON UPDATE CURRENT_TIMESTAMP(6)'
                );
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** Best-effort; queries use JSON payload paths if generated cols are absent. */
    private function ensureGeneratedColumnsAndIndexes(): void
    {
        try {
            if (!$this->hasColumn('student_id')) {
                $this->db->exec(
                    "ALTER TABLE `student_placements`
                     ADD COLUMN student_id CHAR(24)
                       GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.studentId'))) STORED"
                );
            }
            if (!$this->hasColumn('pair_key')) {
                $this->db->exec(
                    "ALTER TABLE `student_placements`
                     ADD COLUMN pair_key VARCHAR(64)
                       GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.pairKey'))) STORED"
                );
            }
        } catch (\Throwable) {
            return;
        }

        try {
            $this->db->exec(
                'ALTER TABLE `student_placements`
                 ADD UNIQUE KEY uniq_student_placement (student_id)'
            );
        } catch (\Throwable) {
            // Index may already exist.
        }

        try {
            $this->db->exec(
                'ALTER TABLE `student_placements`
                 ADD UNIQUE KEY uniq_student_placement_pair (pair_key)'
            );
        } catch (\Throwable) {
            // Index may already exist.
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

        $filter = $this->registryDepartmentFilter($departmentId);
        $batches = [];
        foreach ($this->findAll($filter, max(1, min($limit, 5000))) as $doc) {
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
    /**
     * All roster rows in student_placements for a department (optional filters applied client-side).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRosterRowsForDepartment(string $departmentId, int $limit = 5000): array
    {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $departmentId = trim($departmentId);
        $filter = $departmentId !== '' ? $this->registryDepartmentFilter($departmentId) : [];
        $rows = [];
        foreach ($this->findAll($filter, max(1, min($limit, 5000))) as $doc) {
            $rows[] = self::rosterRowFromDocument($doc);
        }

        return $rows;
    }

    public function listRosterRowsForClass(string $departmentId, string $program, string $batch, int $limit = 5000): array
    {
        if (!$this->bootstrapTable() || trim($batch) === '') {
            return [];
        }

        // Class batch (+ programme) identifies the roster; ignore departmentId so rows
        // from an earlier sync with a blank/other dept still appear for class teachers.
        $rows = [];
        foreach ($this->findAll([], max(1, min($limit, 5000))) as $doc) {
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
            'studRole'       => self::studRoleFromDocument($doc),
            'stud_role'      => trim((string) ($doc['stud_role'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function studRoleFromDocument(array $doc): string
    {
        $role = \PMS\Services\AesApiService::normalizeStudRole($doc);
        if ($role === 'alumni' || $role === 'student') {
            return $role;
        }
        $raw = strtolower(trim((string) ($doc['studRole'] ?? '')));
        if ($raw === 'alumni' || $raw === 'student') {
            return $raw;
        }

        return '';
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

        $role = \PMS\Services\AesApiService::normalizeStudRole($meta);
        if ($role === null) {
            $raw = strtolower(trim((string) ($meta['studRole'] ?? $meta['placementStudRole'] ?? '')));
            if ($raw === 'alumni') {
                $role = 'alumni';
            } elseif ($raw === 'student') {
                $role = 'student';
            }
        }
        if ($role !== null) {
            $out['studRole'] = $role;
            $out['stud_role'] = $role === 'alumni' ? 'Alumni' : 'Student';
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

    /**
     * Include legacy registry rows saved before departmentId was stamped on the payload.
     *
     * @return array<string, mixed>
     */
    private function registryDepartmentFilter(string $departmentId): array
    {
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return [];
        }

        return [
            '$or' => [
                ['departmentId' => $departmentId],
                ['departmentId' => ''],
            ],
        ];
    }

    public static function matchesClassBatchSelection(string $rowBatch, string $wantBatch): bool
    {
        return self::batchMatchesSelection($rowBatch, $wantBatch);
    }

    private static function batchSelectionKey(string $batch): string
    {
        $batch = ClassInchargeRegistry::batchLabelWithoutSemester(trim($batch));

        return strtoupper(preg_replace('/\s+/', '', $batch) ?? '');
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
        if (self::batchSelectionKey($rowBatch) === self::batchSelectionKey($wantBatch)) {
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
            $doc['studRole'],
            $doc['stud_role'],
            $doc['createdAt'],
            $doc['updatedAt']
        );

        return $doc;
    }
}
