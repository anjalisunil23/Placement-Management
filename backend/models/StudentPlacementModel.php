<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Database\QueryHelper;
use PMS\Schemas\Collections;
use PMS\Services\AesApiService;
use PMS\Services\ClassInchargeRegistry;
use PMS\Services\DepartmentProgrammeCatalog;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Staff / class-teacher placement & higher-education registry (one row per student).
 */
class StudentPlacementModel extends BaseModel
{
    /** Max rows returned for staff full-table registry (campus-wide student_placements). */
    public const REGISTRY_TABLE_LIST_MAX = 10000;

    /** Columns BaseModel SELECT/INSERT/ORDER BY require. */
    private const BASE_TABLE_COLUMNS = ['payload', 'created_at', 'updated_at'];

    private static bool $tableReady = false;

    private static bool $tableUnavailable = false;

    /**
     * Production student_placements flat columns (phpMyAdmin / AES export layout).
     * payload is often {}; grid fields are read from these SQL columns.
     */
    private const LEGACY_FLAT_COLUMN_CANDIDATES = [
        'studentId', 'student', 'cno', 'email', 'year', 'courseId', 'branchId',
        'employer', 'empcno', 'empadr', 'payscale', 'status',
        'createdBy', 'crteatedDate', 'updatedBy', 'updatedDate',
        's3file', 'filename', 'fordvv', 'type', 'includedvv',
        'stud_class',
    ];

    /** Placement-owned payload keys — never erased by empty AES values on sync/list. */
    public const PLACEMENT_OWNED_FIELDS = [
        'company', 'companyName', 'employer', 'role', 'address', 'package', 'payscale',
        'employerContact', 'contact', 'joinDate', 'endDate', 'academicDuration',
        'internshipDetails', 'natureOfJob', 'monthlySalary', 'placementStatus', 'status',
        'recordType', 'type', 'offerLetterVerified', 'verificationDate',
        'fordvv', 'includedvv', 'offerLetter', 'joiningLetter', 'companyIdDoc',
        's3file', 'filename',
        'phone', 'email', 'collegeEmail', 'personalEmail', 'cno',
    ];

    /**
     * Blank for merge purposes: null or "" only. Keeps 0, false, and "0".
     */
    public static function isBlankMergeValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Non-destructive merge: keep $base values; fill only from non-blank $incoming.
     * Nested arrays are merged recursively with the same rule.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function mergePreserveFilled(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = (string) $key;
            if (is_array($value)) {
                $childBase = is_array($base[$key] ?? null) ? $base[$key] : [];
                $base[$key] = self::mergePreserveFilled($childBase, $value);
                continue;
            }
            if (self::isBlankMergeValue($value)) {
                continue;
            }
            if (!self::isBlankMergeValue($base[$key] ?? null)) {
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Like mergePreserveFilled, but non-blank incoming always wins (except blank incoming
     * never erases base). Use for profile enrichment when AES is authoritative for empties.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function mergeNonEmptyValues(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = (string) $key;
            if (is_array($value)) {
                $childBase = is_array($base[$key] ?? null) ? $base[$key] : [];
                $base[$key] = self::mergeNonEmptyValues($childBase, $value);
                continue;
            }
            if (self::isBlankMergeValue($value)) {
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    public static function normalizePersonName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name) ?? '';

        return $name;
    }

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

    public function hasLegacyFlatPlacementColumns(): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->hasColumn('employer') && $this->hasColumn('student');
    }

    private function selectRowColumns(): string
    {
        $parts = ['`id`', '`payload`'];
        if ($this->hasColumn('created_at')) {
            $parts[] = '`created_at`';
        }
        if ($this->hasColumn('updated_at')) {
            $parts[] = '`updated_at`';
        }
        if (!$this->hasLegacyFlatPlacementColumns()) {
            return implode(', ', $parts);
        }
        foreach (self::LEGACY_FLAT_COLUMN_CANDIDATES as $column) {
            if ($this->hasColumn($column)) {
                $parts[] = '`' . $column . '`';
            }
        }

        return implode(', ', $parts);
    }

    public function findById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if (!Security::isValidId($id) && !ctype_digit($id)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT ' . $this->selectRowColumns() . ' FROM `' . $this->table . '` WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->rowToDoc($row) : null;
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            if (Security::isValidId($id) || ctype_digit($id)) {
                $clean[$id] = true;
            }
        }
        $ids = array_keys($clean);
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                'SELECT ' . $this->selectRowColumns() . ' FROM `' . $this->table . '` WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute($chunk);
            while ($row = $stmt->fetch()) {
                $doc = $this->rowToDoc($row);
                $map[(string) ($doc['_id'] ?? $row['id'])] = $doc;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function findOne(array $filter, array $options = []): ?array
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $sort = $options['sort'] ?? ['createdAt' => -1];
        $orderBy = QueryHelper::buildOrderBy(is_array($sort) ? $sort : ['createdAt' => -1]);
        $sql = 'SELECT ' . $this->selectRowColumns() . ' FROM `' . $this->table . '` WHERE ' . $where
            . ' ORDER BY ' . $orderBy . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ? $this->rowToDoc($row) : null;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $filter = [], int $limit = 100, int $skip = 0, array $sort = ['createdAt' => -1]): array
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $orderBy = QueryHelper::buildOrderBy($sort);
        $sql = 'SELECT ' . $this->selectRowColumns() . ' FROM `' . $this->table . '` WHERE ' . $where
            . ' ORDER BY ' . $orderBy . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $skip;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->rowToDoc($row);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function rowToDoc(array $row): array
    {
        $doc = parent::rowToDoc($row);

        return self::mergeLegacyFlatRowIntoDoc($row, $doc);
    }

    /**
     * @param array<string, mixed> $row SQL row (may include legacy flat columns)
     * @param array<string, mixed> $doc Decoded payload document
     * @return array<string, mixed>
     */
    public static function mergeLegacyFlatRowIntoDoc(array $row, array $doc): array
    {
        $name = trim((string) ($row['student'] ?? ''));
        $legacyStudentId = trim((string) ($row['studentId'] ?? ''));
        $phone = trim((string) ($row['cno'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $employer = trim((string) ($row['employer'] ?? ''));
        $employerContact = trim((string) ($row['empcno'] ?? ''));
        $address = trim((string) ($row['empadr'] ?? ''));
        $package = trim((string) ($row['payscale'] ?? ''));
        $year = trim((string) ($row['year'] ?? ''));
        $studClass = trim((string) ($row['stud_class'] ?? ''));
        $courseId = trim((string) ($row['courseId'] ?? ''));
        $branchId = trim((string) ($row['branchId'] ?? ''));
        $status = trim((string) ($row['status'] ?? ''));
        $type = trim((string) ($row['type'] ?? ''));
        $fordvv = trim((string) ($row['fordvv'] ?? ''));
        $includedvv = trim((string) ($row['includedvv'] ?? ''));
        $s3file = trim((string) ($row['s3file'] ?? ''));
        $filename = trim((string) ($row['filename'] ?? ''));

        if ($name === '' && $legacyStudentId === '' && $employer === '' && $phone === '' && $email === '') {
            return $doc;
        }

        if ($legacyStudentId !== '') {
            $doc['studentId'] = $legacyStudentId;
            $doc['pairKey'] = self::pairKey($legacyStudentId);
        }
        if ($name !== '') {
            $doc['studentName'] = $name;
            $doc['displayName'] = $name;
        }
        if ($phone !== '') {
            $doc['phone'] = $phone;
        }
        if ($email !== '') {
            $doc['email'] = $email;
            $doc['collegeEmail'] = $email;
        }
        if ($studClass !== '') {
            $doc['classBatch'] = $studClass;
            $doc['stud_class'] = $studClass;
        } elseif ($year !== '') {
            $doc['classBatch'] = $year;
            $doc['stud_class'] = $year;
            $doc['placementYear'] = $year;
        }
        if ($courseId !== '') {
            $doc['courseId'] = $courseId;
        }
        if ($branchId !== '') {
            $doc['branchId'] = $branchId;
        }

        $createdBy = trim((string) ($row['createdBy'] ?? ''));
        $updatedBy = trim((string) ($row['updatedBy'] ?? ''));
        $createdDate = trim((string) ($row['crteatedDate'] ?? $row['createdDate'] ?? ''));
        $updatedDate = trim((string) ($row['updatedDate'] ?? ''));
        if ($createdBy !== '') {
            $doc['createdBy'] = $createdBy;
        }
        if ($updatedBy !== '') {
            $doc['updatedBy'] = $updatedBy;
        }
        if ($createdDate !== '') {
            $doc['createdDate'] = $createdDate;
        }
        if ($updatedDate !== '') {
            $doc['updatedDate'] = $updatedDate;
        }

        $placement = is_array($doc['placement'] ?? null) ? $doc['placement'] : [];
        if ($employer !== '') {
            $placement['company'] = $employer;
            $doc['employer'] = $employer;
            $doc['company'] = $employer;
        }
        if ($employerContact !== '') {
            $placement['employerContact'] = $employerContact;
        }
        if ($address !== '') {
            $placement['address'] = $address;
        }
        if ($package !== '') {
            $placement['package'] = $package;
            $doc['payscale'] = $package;
        }

        $recordType = self::normalizeLegacyRecordType($type);
        if ($recordType !== '') {
            $placement['recordType'] = $recordType;
            $placement['type'] = $recordType;
            $doc['recordType'] = $recordType;
            $doc['type'] = $recordType;
        } elseif ($employer !== '' && trim((string) ($placement['recordType'] ?? '')) === '') {
            $placement['recordType'] = 'Placement';
            $placement['type'] = 'Placement';
        }

        $placementStatus = self::normalizeLegacyPlacementStatus($status, $employer !== '');
        if ($placementStatus !== '') {
            $placement['placementStatus'] = $placementStatus;
            $doc['placementStatus'] = $placementStatus;
            $doc['status'] = $status !== '' ? $status : $placementStatus;
        }

        if ($fordvv !== '') {
            $placement['fordvv'] = $fordvv;
            $doc['fordvv'] = $fordvv;
        }
        if ($includedvv !== '') {
            $placement['includedvv'] = $includedvv;
            $doc['includedvv'] = $includedvv;
        }
        if ($s3file !== '') {
            $placement['offerLetter'] = $s3file;
            $doc['s3file'] = $s3file;
        }
        if ($filename !== '') {
            $doc['filename'] = $filename;
            if (trim((string) ($placement['offerLetter'] ?? '')) === '') {
                $placement['offerLetter'] = $filename;
            }
        }

        $doc['placement'] = $placement;
        $doc['studRole'] = $doc['studRole'] ?? 'alumni';
        $doc['stud_role'] = $doc['stud_role'] ?? 'Alumni';
        $doc['legacyFlatRow'] = true;

        return $doc;
    }

    private static function normalizeLegacyRecordType(string $type): string
    {
        $raw = strtolower(trim($type));
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, 'higher') || str_contains($raw, 'education') || $raw === 'he') {
            return 'Higher Education';
        }
        if (str_contains($raw, 'research')) {
            return 'Research';
        }
        if (str_contains($raw, 'place') || $raw === 'job' || $raw === '1') {
            return 'Placement';
        }

        return trim($type);
    }

    private static function normalizeLegacyPlacementStatus(string $status, bool $hasEmployer): string
    {
        $raw = trim($status);
        if ($raw === '') {
            return $hasEmployer ? 'Placed' : '';
        }
        $lower = strtolower($raw);
        if (in_array($lower, ['1', 'placed', 'joined', 'yes', 'true'], true)) {
            return 'Placed';
        }
        if (in_array($lower, ['offered', 'offer'], true)) {
            return 'Offered';
        }
        if (str_contains($lower, 'not') && str_contains($lower, 'join')) {
            return 'Not Joined';
        }
        if (str_contains($lower, 'higher')) {
            return 'Higher Education';
        }
        if (str_contains($lower, 'research')) {
            return 'Research';
        }

        return $raw;
    }

    /**
     * Resolve AES/export courseId values used on legacy student_placements.courseId.
     * Production Computer Applications rows use courseId=1001 even when departments.aesId differs.
     *
     * @return list<string>
     */
    private function legacyCourseIdCandidates(string $departmentId): array
    {
        $departmentId = trim($departmentId);
        $ids = [];
        $dept = null;
        if ($departmentId !== '') {
            $dept = (new DepartmentModel())->findById($departmentId);
        }
        if (is_array($dept)) {
            foreach (['aesId', 'aes_id', 'code', 'parentAesId', 'parent_aes_id', 'courseId', 'course_id'] as $key) {
                $v = trim((string) ($dept[$key] ?? ''));
                if ($v !== '' && ctype_digit($v)) {
                    $ids[$v] = true;
                }
            }
            $name = strtolower(trim((string) ($dept['name'] ?? '')));
            $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($dept['code'] ?? '')) ?? '');
            // Observed on production appsamaljyothi_placements_db.student_placements
            if (
                str_contains($name, 'computer application')
                || str_contains($name, 'mca')
                || $code === 'CA'
                || $code === 'MCA'
                || $code === 'COMPUTERAPPLICATIONS'
            ) {
                $ids['1001'] = true;
            }
        }

        $env = trim((string) ($_ENV['STAFF_PLACEMENT_LEGACY_COURSE_IDS'] ?? ''));
        if ($env !== '') {
            foreach (preg_split('/[\s,;]+/', $env) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '' && ctype_digit($part)) {
                    $ids[$part] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLegacyFlatRows(string $departmentId, string $program, string $batch, int $limit): array
    {
        if (!$this->hasLegacyFlatPlacementColumns()) {
            return [];
        }

        $departmentId = trim($departmentId);
        $program = trim($program);
        $batch = trim($batch);
        $limit = max(1, min($limit, self::REGISTRY_TABLE_LIST_MAX));
        $courseIds = $this->legacyCourseIdCandidates($departmentId);

        $rows = $this->executeLegacyFlatSelect($courseIds, $program, $batch, $limit, $courseIds !== []);
        // departments.aesId often ≠ student_placements.courseId (e.g. CA → 1001).
        if ($rows === [] && $departmentId !== '' && $courseIds !== []) {
            $rows = $this->executeLegacyFlatSelect($courseIds, $program, $batch, $limit, false);
            // Keep only rows whose courseId is in candidates when we had to drop SQL filter
            // (executeLegacyFlatSelect without filter); if still empty, return unfiltered
            // program/batch-scoped rows so overlay can match by email/name.
        }
        if ($rows === [] && $departmentId !== '') {
            $rows = $this->executeLegacyFlatSelect([], $program, $batch, $limit, false);
        }

        return $rows;
    }

    /**
     * @param list<string> $courseIds
     * @return array<int, array<string, mixed>>
     */
    private function executeLegacyFlatSelect(
        array $courseIds,
        string $program,
        string $batch,
        int $limit,
        bool $applyCourseFilter
    ): array {
        $sql = 'SELECT ' . $this->selectRowColumns() . ' FROM `' . $this->table . '` WHERE 1=1';
        $params = [];
        if ($applyCourseFilter && $courseIds !== [] && $this->hasColumn('courseId')) {
            $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
            $sql .= ' AND CAST(`courseId` AS CHAR) IN (' . $placeholders . ')';
            foreach ($courseIds as $cid) {
                $params[] = $cid;
            }
        }
        $orderCol = $this->hasColumn('student') ? '`student`' : '`id`';
        $sql .= ' ORDER BY ' . $orderCol . ' ASC LIMIT ' . $limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable) {
            return [];
        }

        $courseIdSet = [];
        foreach ($courseIds as $cid) {
            $courseIdSet[(string) $cid] = true;
        }

        $rows = [];
        while ($row = $stmt->fetch()) {
            $doc = $this->rowToDoc($row);
            if (
                !$applyCourseFilter
                && $courseIdSet !== []
                && $this->hasColumn('courseId')
            ) {
                $rowCourse = trim((string) ($doc['courseId'] ?? ''));
                // When SQL IN-filter was skipped after a miss, prefer candidate courseIds
                // but do not drop everything if courseId column is blank on a row.
                if ($rowCourse !== '' && !isset($courseIdSet[$rowCourse])) {
                    continue;
                }
            }
            $rowBatch = trim((string) ($doc['classBatch'] ?? ''));
            $rowProgramme = trim((string) ($doc['programme'] ?? ''));
            if ($program !== '') {
                $want = DepartmentProgrammeCatalog::resolveProgrammeCode($program);
                $fromRow = DepartmentProgrammeCatalog::resolveProgrammeCode($rowProgramme);
                if ($fromRow !== '' && $want !== '' && strcasecmp($fromRow, $want) !== 0) {
                    continue;
                }
            }
            if ($batch !== '' && $rowBatch !== '' && !self::matchesClassBatchSelection($rowBatch, $batch)) {
                continue;
            }
            $rows[] = $doc;
        }

        return $rows;
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

        $existing = $this->findOne(['pairKey' => self::pairKey($studentId)]);
        if (!$existing && $register !== '') {
            $byReg = $this->findOne(['registerNumber' => $register]);
            if (is_array($byReg)) {
                $existing = $byReg;
            }
        }

        if (is_array($existing)) {
            $existingPlacement = self::placementFieldsFromDoc($existing);
            // SQL placement fields win; AES/incoming only fills blanks.
            $placement = self::mergePreserveFilled($existingPlacement, $placement);
            $rosterMeta = self::mergePreserveFilled(
                self::normalizeRosterMeta($existing),
                $rosterMeta
            );
        }

        $set = array_merge($placement, self::normalizeRosterMeta($rosterMeta), [
            'pairKey'        => self::pairKey($studentId),
            'studentId'      => $studentId,
            'registerNumber' => $register,
            'updatedAt'      => $now,
        ]);
        if ($departmentId !== null && trim($departmentId) !== '') {
            $set['departmentId'] = trim($departmentId);
        }

        // Drop blank scalars so BaseModel::update array_merge cannot erase stored values.
        foreach ($set as $key => $value) {
            if (is_scalar($value) && self::isBlankMergeValue($value)
                && in_array((string) $key, self::PLACEMENT_OWNED_FIELDS, true)) {
                unset($set[$key]);
            }
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

        if ($this->hasLegacyFlatPlacementColumns()) {
            $batches = [];
            foreach ($this->fetchLegacyFlatRows($departmentId, $program, '', max(1, min($limit, 5000))) as $doc) {
                $batch = trim((string) ($doc['classBatch'] ?? ''));
                if ($batch !== '') {
                    $batches[] = $batch;
                }
            }
            $batches = array_values(array_unique($batches));
            sort($batches, SORT_STRING);

            return $batches;
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
     * All roster-shaped rows stored in student_placements (filters applied by registry service).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllRosterRows(int $limit = self::REGISTRY_TABLE_LIST_MAX): array
    {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $cap = max(1, min($limit, self::REGISTRY_TABLE_LIST_MAX));
        $rows = [];
        foreach ($this->findAll([], $cap) as $doc) {
            $rows[] = self::rosterRowFromDocument($doc);
        }

        return $rows;
    }

    /**
     * All roster rows in student_placements for a department (optional filters applied client-side).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRosterRowsForDepartment(string $departmentId, int $limit = 5000): array
    {
        return $this->listRosterRowsForRegistryScope($departmentId, '', '', $limit, true);
    }

    public function listRosterRowsForClass(string $departmentId, string $program, string $batch, int $limit = 5000): array
    {
        return $this->listRosterRowsForRegistryScope($departmentId, $program, $batch, $limit, true);
    }

    /**
     * Scoped load for staff registry — avoids scanning the first N rows campus-wide.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRosterRowsForRegistryScope(
        string $departmentId,
        string $program,
        string $batch,
        int $limit = 5000,
        bool $includeLegacyBlankDept = false
    ): array {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $departmentId = trim($departmentId);
        $program = trim($program);
        $batch = trim($batch);
        $limit = max(1, min($limit, self::REGISTRY_TABLE_LIST_MAX));

        if ($this->hasLegacyFlatPlacementColumns()) {
            $rows = [];
            foreach ($this->fetchLegacyFlatRows($departmentId, $program, $batch, $limit) as $doc) {
                if ($departmentId !== '' && trim((string) ($doc['departmentId'] ?? '')) === '') {
                    $doc['departmentId'] = $departmentId;
                }
                $rows[] = self::rosterRowFromDocument($doc);
            }

            return $rows;
        }

        $filter = $this->registryScopeFilter($departmentId, $batch, $includeLegacyBlankDept);
        $batchFilter = $batch !== '' ? $this->registryClassBatchFilter($batch) : [];
        if ($batchFilter !== []) {
            $filter = $filter === [] ? $batchFilter : ['$and' => [$filter, $batchFilter]];
        }

        $rows = [];
        foreach ($this->findAll($filter, $limit, 0, ['studentName' => 1]) as $doc) {
            if ($program !== '' && $batch === '') {
                $rowBatch = trim((string) ($doc['classBatch'] ?? ''));
                if (!self::programmeMatchesBatch($program, $rowBatch, (string) ($doc['programme'] ?? ''))) {
                    continue;
                }
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
        $doc = array_merge($doc, self::normalizeRosterMeta($doc));
        $studentId = trim((string) ($doc['studentId'] ?? $doc['_id'] ?? ''));
        $placement = self::placementFieldsFromDoc($doc);
        $snapshot = self::rosterSnapshotFromPayload($doc, $placement);
        $company = trim((string) ($placement['company'] ?? $doc['employer'] ?? $doc['company'] ?? ''));

        return [
            '_id'            => $studentId,
            'id'             => $studentId,
            'studentId'      => $studentId,
            'studentName'    => $snapshot['studentName'],
            'registerNumber' => $snapshot['registerNumber'],
            'admno'          => $snapshot['admissionNo'],
            'displayName'    => $snapshot['studentName'],
            'personal'       => [
                'fullName'     => $snapshot['studentName'],
                'phone'        => $snapshot['phone'],
                'collegeEmail' => $snapshot['email'],
            ],
            'phone'          => $snapshot['phone'],
            'collegeEmail'   => $snapshot['email'],
            'email'          => $snapshot['email'],
            'classBatch'     => $snapshot['classBatch'],
            'stud_class'     => $snapshot['classBatch'],
            'programme'      => $snapshot['programme'],
            'stud_course'    => $snapshot['programme'],
            'branch'         => $snapshot['branch'],
            'courseId'       => $snapshot['courseId'],
            'branchId'       => $snapshot['branchId'],
            'departmentId'   => $snapshot['departmentId'],
            'company'        => $company,
            'employer'       => $company,
            'role'           => trim((string) ($placement['role'] ?? '')),
            'package'        => trim((string) ($placement['package'] ?? '')),
            'address'        => trim((string) ($placement['address'] ?? '')),
            'employerContact'=> trim((string) ($placement['employerContact'] ?? '')),
            'placementStatus'=> trim((string) ($placement['placementStatus'] ?? '')),
            'placement'      => $placement,
            'placed'         => $company !== '',
            'source'         => 'student_placements',
            'studRole'       => self::studRoleFromDocument($doc),
            'stud_role'      => trim((string) ($doc['stud_role'] ?? '')),
            'legacyFlatRow'  => !empty($doc['legacyFlatRow']),
        ];
    }

    /**
     * Resolve grid columns from flat payload and leftover AES/placement keys.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $placementFields
     * @return array{
     *   studentName:string,
     *   registerNumber:string,
     *   admissionNo:string,
     *   phone:string,
     *   email:string,
     *   classBatch:string,
     *   programme:string,
     *   branch:string,
     *   courseId:string,
     *   branchId:string,
     *   departmentId:string
     * }
     */
    public static function rosterSnapshotFromPayload(array $doc, array $placementFields = []): array
    {
        $personal = is_array($doc['personal'] ?? null) ? $doc['personal'] : [];
        $roster = is_array($doc['roster'] ?? null) ? $doc['roster'] : [];
        $pick = static function (array $sources, string ...$keys): string {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                foreach ($keys as $key) {
                    $value = trim((string) ($source[$key] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            return '';
        };

        $name = $pick(
            [$doc, $roster, $personal, $placementFields],
            'studentName',
            'displayName',
            'stud_name',
            'name',
            'fullName'
        );
        $register = strtoupper($pick(
            [$doc, $roster, $placementFields],
            'registerNumber',
            'registerno',
            'register_number',
            'admno',
            'stud_admno',
            'admissionNo'
        ));
        if ($register === '') {
            $studentId = trim((string) ($doc['studentId'] ?? ''));
            if ($studentId !== '' && !Security::isValidId($studentId)) {
                $register = strtoupper($studentId);
            }
        }
        $phone = $pick([$doc, $roster, $personal, $placementFields], 'phone', 'mobile', 'stud_mobile', 'contactPhone');
        $email = $pick([$doc, $roster, $personal, $placementFields], 'email', 'collegeEmail', 'personalEmail', 'stud_email');
        $classBatch = $pick([$doc, $roster, $placementFields], 'classBatch', 'stud_class', 'batch');
        $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($pick(
            [$doc, $roster, $placementFields],
            'programme',
            'program',
            'stud_course',
            'stud_cource_short',
            'course'
        ));
        if ($programme === '' && $classBatch !== '') {
            $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($classBatch);
        }
        $branch = $pick([$doc, $roster, $placementFields], 'branch', 'stud_branch', 'branchName', 'branch_name');

        return [
            'studentName'    => $name,
            'registerNumber' => $register,
            'admissionNo'    => $pick([$doc, $placementFields], 'admissionNo', 'admno', 'stud_admno') ?: $register,
            'phone'          => $phone,
            'email'          => $email,
            'classBatch'     => $classBatch,
            'programme'      => $programme,
            'branch'         => $branch,
            'courseId'       => $pick([$doc, $placementFields], 'courseId', 'course_id', 'stud_courseid'),
            'branchId'       => $pick([$doc, $placementFields], 'branchId', 'branch_id', 'stud_branchid'),
            'departmentId'   => trim((string) ($doc['departmentId'] ?? '')),
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
            'studentName' => ['studentName', 'displayName', 'stud_name', 'name', 'fullName'],
            'courseId'    => ['courseId', 'course_id'],
            'branchId'    => ['branchId', 'branch_id'],
            'phone'       => ['phone'],
            'email'       => ['email', 'collegeEmail', 'personalEmail'],
            'admissionNo' => ['admissionNo', 'admno', 'stud_admno', 'registerNumber', 'registerno'],
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
    private function registryDepartmentFilter(string $departmentId, bool $includeLegacyBlank = true): array
    {
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return [];
        }

        if (!$includeLegacyBlank) {
            return ['departmentId' => $departmentId];
        }

        return [
            '$or' => [
                ['departmentId' => $departmentId],
                ['departmentId' => ''],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registryScopeFilter(string $departmentId, string $batch, bool $includeLegacyBlankDept): array
    {
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return [];
        }

        return $this->registryDepartmentFilter($departmentId, $includeLegacyBlankDept || $batch !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function registryClassBatchFilter(string $wantBatch): array
    {
        $wantBatch = trim($wantBatch);
        if ($wantBatch === '') {
            return [];
        }

        $labels = array_values(array_unique(array_filter([
            $wantBatch,
            ClassInchargeRegistry::batchLabelWithoutSemester($wantBatch),
        ], static fn (string $v): bool => $v !== '')));

        $cohort = ClassInchargeRegistry::cohortKey($wantBatch);
        if ($cohort !== '' && !in_array($cohort, $labels, true)) {
            $labels[] = $cohort;
        }

        $branches = [['classBatch' => ['$in' => $labels]]];
        if ($cohort !== '' && !in_array($cohort, $labels, true)) {
            $branches[] = ['classBatch' => ['$regex' => $cohort . '%']];
        }

        $batchMatch = count($branches) === 1 ? $branches[0] : ['$or' => $branches];

        return [
            '$and' => [
                ['classBatch' => ['$ne' => '']],
                $batchMatch,
            ],
        ];
    }

    public static function matchesClassBatchSelection(string $rowBatch, string $wantBatch): bool
    {
        return self::batchMatchesSelection($rowBatch, $wantBatch);
    }

    /**
     * Legacy SQL rows store placement year (2020-2021) in `year`, not AES stud_class labels
     * (e.g. MCALE2016-18). Treat year rows as in-scope for AES class-batch filters so the
     * grid is not emptied when department/programme already scoped the query.
     */
    public static function legacyBatchFilterMatches(string $wantBatch, string $rowBatch): bool
    {
        $wantBatch = trim($wantBatch);
        $rowBatch = trim($rowBatch);
        if ($wantBatch === '' || $rowBatch === '') {
            return true;
        }
        if (strcasecmp($rowBatch, $wantBatch) === 0) {
            return true;
        }
        if (self::matchesClassBatchSelection($rowBatch, $wantBatch)) {
            return true;
        }
        // Placement-year rows vs AES admission / LE batch labels.
        if (preg_match('/^\d{4}\s*[-–]\s*\d{2,4}$/', $rowBatch) === 1
            && preg_match('/\b(LE|INT|MCA|BCA|MBA|B\.?\s*TECH|M\.?\s*TECH)/i', $wantBatch) === 1) {
            return true;
        }

        return false;
    }

    /**
     * All legacy flat rows for a department (ignores batch — used to overlay placement onto AES roster).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLegacyFlatRosterRows(string $departmentId, string $program = '', int $limit = self::REGISTRY_TABLE_LIST_MAX): array
    {
        if (!$this->bootstrapTable() || !$this->hasLegacyFlatPlacementColumns()) {
            return [];
        }

        $rows = [];
        foreach ($this->fetchLegacyFlatRows($departmentId, $program, '', max(1, min($limit, self::REGISTRY_TABLE_LIST_MAX))) as $doc) {
            $rows[] = self::rosterRowFromDocument($doc);
        }

        return $rows;
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
        if ($wantCohort !== ''
            && strcasecmp(ClassInchargeRegistry::cohortKey($rowBatch), $wantCohort) === 0) {
            return true;
        }

        return ClassInchargeRegistry::batchesSameAdmissionCohort($rowBatch, $wantBatch);
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
        $placement = is_array($doc['placement'] ?? null) ? $doc['placement'] : [];
        $fromAes = (new AesApiService())->placementFieldsFromStudInfoDirectoryRecord(
            array_merge($doc, $placement)
        );

        return array_merge($placement, $fromAes);
    }
}
