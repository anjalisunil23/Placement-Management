<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Services\AesApiService;
use PMS\Services\DepartmentProgrammeCatalog;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;
use PMS\Models\PlacementPolicySettingsModel;
use PMS\Models\StudentModel;

/**
 * AES student master / directory — local source of truth after synchronization.
 */
class StudentDetailsModel extends BaseModel
{
    /** 0 = no SQL row cap (full campus directory). Override via STUDENT_DETAILS_LIST_MAX. */
    public const LIST_MAX = 0;

    /** @var list<string> AES-owned fields updated on sync (never placement data). */
    public const AES_OWNED_SCALAR_KEYS = [
        'aesAdmno', 'registerNumber', 'studentId', 'studentName', 'displayName',
        'classBatch', 'programme', 'branch', 'year', 'semester',
        'courseId', 'branchId', 'deptAesId', 'departmentId', 'departmentName',
        'phone', 'collegeEmail', 'personalEmail', 'email', 'photoUrl',
        'studRole', 'stud_role', 'stud_status', 'status', 'studying',
        'admno', 'stud_admno', 'registerno', 'stud_name', 'stud_class',
        'stud_course', 'stud_cource_short', 'stud_branch', 'stud_deptcode',
        'stud_year', 'stud_semester', 'stud_mobiles', 'stud_ajce_mails',
        'stud_personal_mails', 'stud_photo', 'parentDepartmentCode', 'parentDepartmentName',
    ];

    private static bool $tableReady = false;

    private static bool $tableUnavailable = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_DETAILS;
    }

    private function bootstrapTable(): bool
    {
        if (self::$tableUnavailable) {
            return false;
        }
        if (self::$tableReady) {
            return true;
        }
        try {
            $migration = dirname(__DIR__) . '/database/migrations/001_create_student_details.sql';
            if (is_readable($migration)) {
                $sql = (string) file_get_contents($migration);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '' && stripos($stmt, 'CREATE TABLE') !== false) {
                        $this->db->exec($stmt);
                        break;
                    }
                }
            }
            $this->db->query('SELECT 1 FROM `student_details` LIMIT 1');
            $this->ensureRegistrationStatusColumn();
            self::$tableReady = true;

            return true;
        } catch (\Throwable) {
            self::$tableUnavailable = true;

            return false;
        }
    }

    private function ensureRegistrationStatusColumn(): void
    {
        try {
            $stmt = $this->db->query(
                "SHOW COLUMNS FROM `student_details` LIKE 'registration_status'"
            );
            if ($stmt->fetch()) {
                return;
            }
            $migration = dirname(__DIR__) . '/database/migrations/002_add_registration_status_to_student_details.sql';
            if (!is_readable($migration)) {
                return;
            }
            $sql = (string) file_get_contents($migration);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmtSql) {
                if ($stmtSql !== '' && stripos($stmtSql, 'ALTER TABLE') !== false) {
                    $this->db->exec($stmtSql);
                }
            }
        } catch (\Throwable) {
            // Column may already exist or host may restrict DDL at runtime.
        }
    }

    public function isAvailable(): bool
    {
        return $this->bootstrapTable();
    }

    /**
     * Stable AES admission number used as the unique upsert key.
     */
    public static function resolveAesAdmno(array $record): string
    {
        foreach (['aesAdmno', 'admno', 'stud_admno', 'registerNumber', 'registerno'] as $key) {
            $v = strtoupper(trim((string) ($record[$key] ?? '')));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $record AES directory row or normalized payload
     * @param array<string, mixed> $options syncSource, studRole, departmentId
     * @return array{action:string,id:string,aesAdmno:string}
     */
    public function upsertFromAesRecord(array $record, array $options = []): array
    {
        if (!$this->bootstrapTable()) {
            throw new \RuntimeException('student_details table is not available.');
        }

        $incoming = self::payloadFromAesRecord($record, $options);
        $aesAdmno = self::resolveAesAdmno($incoming);
        if ($aesAdmno === '') {
            return ['action' => 'skipped', 'id' => '', 'aesAdmno' => ''];
        }
        $incoming['aesAdmno'] = $aesAdmno;

        $existing = $this->findByAesAdmno($aesAdmno);
        $incoming = self::guardStudRoleTransition($record, $existing, $incoming);
        $now = DocumentHelper::now();
        $incoming['syncedAt'] = $now;
        if (!empty($options['syncSource'])) {
            $incoming['syncSource'] = (string) $options['syncSource'];
        }

        if ($existing === null) {
            $incoming['createdAt'] = $now;
            $this->applyRegistrationStatusToPayload($incoming);
            $id = $this->insert($incoming);

            return ['action' => 'inserted', 'id' => $id, 'aesAdmno' => $aesAdmno];
        }

        $merged = StudentPlacementModel::mergeNonEmptyValues($existing, $incoming);
        unset($merged['_id']);
        $beforeHash = self::contentHash($existing);
        $afterHash = self::contentHash($merged);
        $this->applyRegistrationStatusToPayload($merged);
        $registrationChanged = (string) ($merged['registrationStatus'] ?? '')
            !== (string) ($existing['registrationStatus'] ?? '');
        if ($beforeHash === $afterHash && !$registrationChanged) {
            return ['action' => 'unchanged', 'id' => (string) ($existing['_id'] ?? ''), 'aesAdmno' => $aesAdmno];
        }

        $merged['updatedAt'] = $now;
        $id = (string) ($existing['_id'] ?? '');
        $this->update($id, $merged);

        return ['action' => 'updated', 'id' => $id, 'aesAdmno' => $aesAdmno];
    }

    /**
     * Portal registration status from placement policy acceptance (not AES).
     *
     * @return 'registered'|'non_registered'|null null for alumni
     */
    public static function registrationStatusFromStudentProfile(array $student, string $studRole): ?string
    {
        if ($studRole === 'alumni') {
            return null;
        }

        $registration = (new PlacementPolicySettingsModel())->registrationState($student);

        return !empty($registration['policyAccepted']) ? 'registered' : 'non_registered';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyRegistrationStatusToPayload(array &$payload, ?array $studentProfile = null): void
    {
        $studRole = (string) ($payload['studRole'] ?? '');
        if ($studRole === 'alumni') {
            unset($payload['registrationStatus']);

            return;
        }

        if ($studentProfile === null) {
            $admno = self::resolveAesAdmno($payload);
            if ($admno !== '') {
                $studentProfile = (new StudentModel())->findByRegisterNumber($admno);
            }
        }

        if (!is_array($studentProfile)) {
            $payload['registrationStatus'] = 'non_registered';

            return;
        }

        $status = self::registrationStatusFromStudentProfile($studentProfile, 'student');
        if ($status === null) {
            unset($payload['registrationStatus']);

            return;
        }

        $payload['registrationStatus'] = $status;
    }

    public function updateRegistrationStatusForAdmno(string $aesAdmno, ?string $status = null): bool
    {
        if (!$this->bootstrapTable()) {
            return false;
        }
        $aesAdmno = strtoupper(trim($aesAdmno));
        if ($aesAdmno === '') {
            return false;
        }

        $doc = $this->findByAesAdmno($aesAdmno);
        if ($doc === null) {
            return false;
        }

        if ((string) ($doc['studRole'] ?? '') === 'alumni') {
            return false;
        }

        if ($status === null) {
            $student = (new StudentModel())->findByRegisterNumber($aesAdmno);
            $status = is_array($student)
                ? self::registrationStatusFromStudentProfile($student, 'student')
                : 'non_registered';
        }

        if ($status !== 'registered' && $status !== 'non_registered') {
            return false;
        }

        if ((string) ($doc['registrationStatus'] ?? '') === $status) {
            return true;
        }

        $id = (string) ($doc['_id'] ?? '');
        unset($doc['_id']);
        $doc['registrationStatus'] = $status;
        $doc['updatedAt'] = DocumentHelper::now();
        if ($id !== '') {
            $this->update($id, $doc);
        }

        return true;
    }

    /**
     * Refresh registration_status for studying rows from the students table.
     *
     * @param list<string> $aesAdmnos empty = all studying rows
     * @return array{updated:int,unchanged:int,skipped:int}
     */
    public function refreshRegistrationStatuses(array $aesAdmnos = []): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        if (!$this->bootstrapTable()) {
            return $stats;
        }

        $studentModel = new StudentModel();
        $targets = [];
        if ($aesAdmnos !== []) {
            foreach ($aesAdmnos as $admno) {
                $key = strtoupper(trim((string) $admno));
                if ($key !== '') {
                    $targets[$key] = true;
                }
            }
        } else {
            foreach ($this->listDirectoryRecords('', true, 'student', self::LIST_MAX) as $row) {
                $key = self::resolveAesAdmno($row);
                if ($key !== '') {
                    $targets[$key] = true;
                }
            }
        }

        foreach (array_keys($targets) as $admno) {
            $doc = $this->findByAesAdmno($admno);
            if ($doc === null || (string) ($doc['studRole'] ?? '') === 'alumni') {
                $stats['skipped']++;
                continue;
            }
            $before = (string) ($doc['registrationStatus'] ?? '');
            $this->applyRegistrationStatusToPayload($doc, $studentModel->findByRegisterNumber($admno));
            $after = (string) ($doc['registrationStatus'] ?? '');
            if ($before === $after) {
                $stats['unchanged']++;
                continue;
            }
            $id = (string) ($doc['_id'] ?? '');
            unset($doc['_id']);
            $doc['updatedAt'] = DocumentHelper::now();
            if ($id !== '') {
                $this->update($id, $doc);
            }
            $stats['updated']++;
        }

        return $stats;
    }

    public function findByAesAdmno(string $aesAdmno): ?array
    {
        if (!$this->bootstrapTable()) {
            return null;
        }
        $aesAdmno = strtoupper(trim($aesAdmno));
        if ($aesAdmno === '') {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, payload, created_at, updated_at FROM `student_details` WHERE aes_admno = ? LIMIT 1'
            );
            $stmt->execute([$aesAdmno]);
            $row = $stmt->fetch();
            if (!$row) {
                return $this->findOne(['aesAdmno' => $aesAdmno]);
            }

            return $this->rowToDoc($row);
        } catch (\Throwable) {
            return $this->findOne(['aesAdmno' => $aesAdmno]);
        }
    }

    /**
     * @return list<array<string, mixed>> slim directory rows for OfficerDataService
     */
    public function listDirectoryRecords(
        string $deptAesId = '',
        bool $campusWide = true,
        string $studRole = '',
        int $limit = self::LIST_MAX,
        string $registrationStatus = ''
    ): array {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $sqlLimit = self::resolveSqlListLimit($limit);
        $sql = 'SELECT id, payload, created_at, updated_at FROM `student_details` WHERE 1=1';
        $params = [];

        if (!$campusWide && $deptAesId !== '') {
            $sql .= ' AND dept_aes_id = ?';
            $params[] = $deptAesId;
        }
        if ($studRole !== '' && $studRole !== 'all') {
            $sql .= ' AND stud_role = ?';
            $params[] = strtolower($studRole) === 'alumni' ? 'alumni' : 'student';
        }
        $registrationStatus = strtolower(trim($registrationStatus));
        if ($registrationStatus === 'registered' || $registrationStatus === 'non_registered') {
            $sql .= ' AND registration_status = ?';
            $params[] = $registrationStatus;
        }

        $sql .= ' ORDER BY JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.studentName\')) ASC';
        if ($sqlLimit !== null) {
            $sql .= ' LIMIT ' . $sqlLimit;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable) {
            $filter = [];
            if ($studRole !== '' && $studRole !== 'all') {
                $filter['studRole'] = strtolower($studRole) === 'alumni' ? 'alumni' : 'student';
            }
            if (!$campusWide && $deptAesId !== '') {
                $filter['deptAesId'] = $deptAesId;
            }

            $fallbackLimit = $sqlLimit ?? PHP_INT_MAX;

            return array_map(
                fn (array $doc): array => self::toDirectoryRecord($doc),
                $this->findAll($filter, $fallbackLimit)
            );
        }

        $out = [];
        while ($row = $stmt->fetch()) {
            $doc = $this->rowToDoc($row);
            $out[] = self::toDirectoryRecord($doc);
        }

        return $out;
    }

    /**
     * Roster rows for staff registry (master fields only).
     *
     * @return list<array<string, mixed>>
     */
    public function listRosterRowsForRegistryScope(
        string $departmentId,
        string $program,
        string $batch,
        int $limit = 5000
    ): array {
        $records = $this->listDirectoryRecords('', true, 'all', $limit);
        $program = trim($program);
        $batch = trim($batch);
        $deptModel = new DepartmentModel();
        $deptAesId = '';
        if ($departmentId !== '') {
            $dept = $deptModel->findById($departmentId);
            $deptAesId = trim((string) ($dept['aesId'] ?? $dept['aes_id'] ?? ''));
        }

        $rows = [];
        foreach ($records as $record) {
            if ($deptAesId !== '') {
                $recordDept = trim((string) ($record['stud_deptcode'] ?? $record['deptAesId'] ?? ''));
                if ($recordDept !== '' && strcasecmp($recordDept, $deptAesId) !== 0) {
                    continue;
                }
            }
            if ($program !== '') {
                $rowProgram = DepartmentProgrammeCatalog::resolveProgrammeCode(
                    (string) ($record['stud_course'] ?? $record['programme'] ?? '')
                );
                if ($rowProgram === '') {
                    $rowProgram = DepartmentProgrammeCatalog::resolveProgrammeCode(
                        (string) ($record['classBatch'] ?? $record['stud_class'] ?? '')
                    );
                }
                if ($rowProgram !== '' && strcasecmp($rowProgram, $program) !== 0) {
                    continue;
                }
            }
            if ($batch !== '' && !StudentPlacementModel::matchesClassBatchSelection(
                (string) ($record['stud_class'] ?? $record['classBatch'] ?? ''),
                $batch
            )) {
                continue;
            }
            $rows[] = self::toRosterRow($record, $departmentId);
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function countByRegistrationStatus(string $studRole, string $registrationStatus): int
    {
        if (!$this->bootstrapTable()) {
            return 0;
        }
        $studRole = strtolower($studRole) === 'alumni' ? 'alumni' : 'student';
        $registrationStatus = strtolower(trim($registrationStatus));
        if ($registrationStatus !== 'registered' && $registrationStatus !== 'non_registered') {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM `student_details` WHERE stud_role = ? AND registration_status = ?'
            );
            $stmt->execute([$studRole, $registrationStatus]);

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * After campus sync, demote alumni rows that AES still lists as studying students.
     *
     * @param list<array<string, mixed>> $authoritativeStudentRecords
     * @return array{demoted:int}
     */
    public function reconcileMisclassifiedStudRoles(array $authoritativeStudentRecords): array
    {
        $stats = ['demoted' => 0];
        if (!$this->bootstrapTable()) {
            return $stats;
        }

        $studentAdmnos = [];
        foreach ($authoritativeStudentRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $key = self::resolveAesAdmno($record);
            if ($key === '') {
                continue;
            }
            if (AesApiService::resolveStudRoleForStorage($record, 'student') === 'student') {
                $studentAdmnos[$key] = true;
            }
        }

        if ($studentAdmnos === []) {
            return $stats;
        }

        foreach (array_keys($studentAdmnos) as $admno) {
            $doc = $this->findByAesAdmno($admno);
            if ($doc === null || (string) ($doc['studRole'] ?? '') !== 'alumni') {
                continue;
            }
            $id = (string) ($doc['_id'] ?? '');
            unset($doc['_id']);
            $doc['studRole'] = 'student';
            $doc['stud_role'] = 'Student';
            $this->applyRegistrationStatusToPayload($doc);
            $doc['updatedAt'] = DocumentHelper::now();
            if ($id !== '') {
                $this->update($id, $doc);
                $stats['demoted']++;
            }
        }

        return $stats;
    }

    /**
     * Demote alumni rows absent from the latest AES alumni fetch (upsert-only sync leaves stale rows).
     *
     * @param list<array<string, mixed>> $authoritativeAlumniRecords
     * @return array{demoted:int}
     */
    public function reconcileStaleAlumniRows(array $authoritativeAlumniRecords): array
    {
        $stats = ['demoted' => 0];
        if (!$this->bootstrapTable()) {
            return $stats;
        }

        $authoritative = [];
        foreach ($authoritativeAlumniRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $key = self::resolveAesAdmno($record);
            if ($key !== '' && AesApiService::qualifiesAsAlumniDirectoryRecord($record)) {
                $authoritative[$key] = true;
            }
        }

        try {
            $stmt = $this->db->query(
                'SELECT id, payload FROM `student_details` WHERE stud_role = \'alumni\''
            );
        } catch (\Throwable) {
            return $stats;
        }

        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $doc = $this->rowToDoc($row);
            $admno = self::resolveAesAdmno($doc);
            if ($admno === '' || isset($authoritative[$admno])) {
                continue;
            }

            $id = (string) ($doc['_id'] ?? $row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            unset($doc['_id']);
            $doc['studRole'] = 'student';
            $doc['stud_role'] = 'Student';
            $this->applyRegistrationStatusToPayload($doc);
            $doc['updatedAt'] = DocumentHelper::now();
            $this->update($id, $doc);
            $stats['demoted']++;
        }

        return $stats;
    }

    /**
     * Demote alumni rows stored without explicit AES stud_role = Alumni.
     *
     * @return array{demoted:int}
     */
    public function reconcileAlumniWithoutExplicitAesRole(): array
    {
        $stats = ['demoted' => 0];
        if (!$this->bootstrapTable()) {
            return $stats;
        }

        try {
            $stmt = $this->db->query(
                'SELECT id, payload FROM `student_details` WHERE stud_role = \'alumni\''
            );
        } catch (\Throwable) {
            return $stats;
        }

        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $doc = $this->rowToDoc($row);
            $aesRecord = is_array($doc['aesRecord'] ?? null) ? $doc['aesRecord'] : $doc;
            if (AesApiService::normalizeStudRole($aesRecord) === 'alumni') {
                continue;
            }

            $id = (string) ($doc['_id'] ?? $row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            unset($doc['_id']);
            $doc['studRole'] = 'student';
            $doc['stud_role'] = 'Student';
            $this->applyRegistrationStatusToPayload($doc);
            $doc['updatedAt'] = DocumentHelper::now();
            $this->update($id, $doc);
            $stats['demoted']++;
        }

        return $stats;
    }

    /**
     * @return int|null SQL LIMIT value, or null for no cap
     */
    public static function resolveSqlListLimit(int $requested = 0): ?int
    {
        $envRaw = $_ENV['STUDENT_DETAILS_LIST_MAX'] ?? getenv('STUDENT_DETAILS_LIST_MAX');
        $configured = $envRaw !== false && $envRaw !== null && $envRaw !== ''
            ? (int) $envRaw
            : self::LIST_MAX;
        if ($configured <= 0 && $requested <= 0) {
            return null;
        }
        $cap = $configured > 0 ? $configured : PHP_INT_MAX;
        if ($requested <= 0) {
            return $cap >= PHP_INT_MAX ? null : $cap;
        }

        return min(max(1, $requested), $cap >= PHP_INT_MAX ? $requested : $cap);
    }

    public function countByRole(string $studRole = ''): int
    {
        if (!$this->bootstrapTable()) {
            return 0;
        }
        try {
            if ($studRole === '' || $studRole === 'all') {
                $stmt = $this->db->query('SELECT COUNT(*) FROM `student_details`');
            } else {
                $role = strtolower($studRole) === 'alumni' ? 'alumni' : 'student';
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM `student_details` WHERE stud_role = ?');
                $stmt->execute([$role]);
            }

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable) {
            $fallbackLimit = self::resolveSqlListLimit() ?? PHP_INT_MAX;

            return count($this->findAll(
                $studRole !== '' && $studRole !== 'all'
                    ? ['studRole' => strtolower($studRole) === 'alumni' ? 'alumni' : 'student']
                    : [],
                $fallbackLimit
            ));
        }
    }

    /**
     * @return array<string, mixed>|null sync metadata for admin UI
     */
    public function latestSyncMeta(): ?array
    {
        if (!$this->bootstrapTable()) {
            return null;
        }
        $rows = $this->findAll([], 1, 0, ['syncedAt' => -1]);
        if ($rows === []) {
            return null;
        }
        $latest = $rows[0];

        return [
            'syncedAt' => (string) ($latest['syncedAt'] ?? $latest['updatedAt'] ?? ''),
            'syncSource' => (string) ($latest['syncSource'] ?? ''),
            'studentRecordCount' => $this->countByRole('student'),
            'alumniRecordCount' => $this->countByRole('alumni'),
            'recordCount' => $this->countByRole('all'),
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function payloadFromAesRecord(array $record, array $options = []): array
    {
        $admno = self::resolveAesAdmno($record);
        $reg = strtoupper(trim((string) (
            $record['registerno']
            ?? $record['registerNumber']
            ?? $admno
        )));
        $name = trim((string) (
            $record['stud_name']
            ?? $record['studentName']
            ?? $record['displayName']
            ?? $record['student']
            ?? ''
        ));
        $classBatch = trim((string) (
            $record['stud_class']
            ?? $record['classBatch']
            ?? $record['year']
            ?? ''
        ));
        $programme = trim((string) (
            $record['stud_course']
            ?? $record['stud_cource_short']
            ?? $record['programme']
            ?? ''
        ));
        $branch = trim((string) ($record['stud_branch'] ?? $record['branch'] ?? ''));
        $deptAesId = trim((string) (
            $record['stud_deptcode']
            ?? $record['deptAesId']
            ?? $record['parentDepartmentCode']
            ?? ''
        ));
        $email = trim((string) (
            $record['stud_ajce_mails']
            ?? $record['collegeEmail']
            ?? $record['email']
            ?? ''
        ));
        if ($email === '') {
            $email = trim((string) ($record['stud_personal_mails'] ?? $record['personalEmail'] ?? ''));
        }
        $phone = trim((string) ($record['stud_mobiles'] ?? $record['phone'] ?? $record['cno'] ?? ''));
        $studRole = AesApiService::resolveStudRoleForStorage(
            $record,
            (string) ($options['studRole'] ?? '')
        );

        $studentId = trim((string) ($record['studentId'] ?? $record['id'] ?? ''));
        if ($studentId === '' || (!Security::isValidId($studentId) && !ctype_digit($studentId))) {
            $studentId = $admno !== '' ? $admno : $reg;
        }

        $payload = [
            'aesAdmno'       => $admno,
            'registerNumber' => $reg !== '' ? $reg : $admno,
            'studentId'      => $studentId,
            'studentName'    => $name,
            'displayName'    => $name !== '' ? $name : $reg,
            'classBatch'     => $classBatch,
            'programme'      => $programme,
            'branch'         => $branch,
            'year'           => trim((string) ($record['stud_year'] ?? $record['year'] ?? '')),
            'semester'       => trim((string) ($record['stud_semester'] ?? $record['semester'] ?? '')),
            'courseId'       => trim((string) (
                $record['courseId']
                ?? $record['course_id']
                ?? $record['stud_courseid']
                ?? $record['stud_course_id']
                ?? ''
            )),
            'branchId'       => trim((string) (
                $record['branchId']
                ?? $record['branch_id']
                ?? $record['stud_branchid']
                ?? $record['stud_branch_id']
                ?? ''
            )),
            'deptAesId'      => $deptAesId,
            'departmentId'   => trim((string) ($options['departmentId'] ?? $record['departmentId'] ?? '')),
            'phone'          => $phone,
            'collegeEmail'   => trim((string) ($record['stud_ajce_mails'] ?? $record['collegeEmail'] ?? '')),
            'personalEmail'  => trim((string) ($record['stud_personal_mails'] ?? $record['personalEmail'] ?? '')),
            'email'          => $email,
            'photoUrl'       => trim((string) ($record['stud_photo'] ?? $record['photoUrl'] ?? '')),
            'studRole'       => $studRole,
            'stud_role'      => $studRole === 'alumni' ? 'Alumni' : 'Student',
        ];
        if ($studRole === 'alumni') {
            unset($payload['registrationStatus']);
        }

        if (!empty($options['placeHubStudentId'])) {
            $payload['placeHubStudentId'] = (string) $options['placeHubStudentId'];
        }

        $payload['aesRecord'] = self::compactAesRecordForStorage($record);

        return $payload;
    }

    /**
     * Preserve the full AES row inside payload without breaking indexed scalar fields.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function compactAesRecordForStorage(array $record): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = (string) $key;
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $doc stored student_details document
     * @return array<string, mixed> slim directory row
     */
    public static function toDirectoryRecord(array $doc): array
    {
        $admno = self::resolveAesAdmno($doc);
        $reg = strtoupper(trim((string) ($doc['registerNumber'] ?? $admno)));

        return [
            'admno'              => $admno,
            'stud_admno'         => $admno,
            'registerno'         => $reg,
            'registerNumber'     => $reg,
            'stud_name'          => (string) ($doc['studentName'] ?? $doc['displayName'] ?? ''),
            'stud_class'         => (string) ($doc['classBatch'] ?? ''),
            'classBatch'         => (string) ($doc['classBatch'] ?? ''),
            'stud_course'        => (string) ($doc['programme'] ?? ''),
            'stud_cource_short'  => (string) ($doc['programme'] ?? ''),
            'stud_branch'        => (string) ($doc['branch'] ?? ''),
            'stud_deptcode'      => (string) ($doc['deptAesId'] ?? ''),
            'stud_year'          => (string) ($doc['year'] ?? ''),
            'stud_semester'      => (string) ($doc['semester'] ?? ''),
            'stud_photo'         => (string) ($doc['photoUrl'] ?? ''),
            'photoUrl'           => (string) ($doc['photoUrl'] ?? ''),
            'stud_mobiles'       => (string) ($doc['phone'] ?? ''),
            'stud_ajce_mails'    => (string) ($doc['collegeEmail'] ?? $doc['email'] ?? ''),
            'stud_personal_mails'=> (string) ($doc['personalEmail'] ?? ''),
            'stud_role'          => (string) ($doc['stud_role'] ?? ''),
            'studRole'           => (string) ($doc['studRole'] ?? ''),
            'registrationStatus' => (string) ($doc['registrationStatus'] ?? ''),
            'studentId'          => (string) ($doc['studentId'] ?? $admno),
            'departmentId'       => (string) ($doc['departmentId'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $record directory row
     * @return array<string, mixed>
     */
    public static function toRosterRow(array $record, string $departmentId = ''): array
    {
        $row = self::toDirectoryRecord($record);
        $row['id'] = (string) ($record['studentId'] ?? $record['id'] ?? $row['admno'] ?? '');
        $row['studentId'] = $row['id'];
        if ($departmentId !== '') {
            $row['departmentId'] = $departmentId;
        }

        return $row;
    }

    /**
     * Build master payload from legacy student_placements row (backfill).
     *
     * @param array<string, mixed> $doc merged placement doc
     * @return array<string, mixed>|null
     */
    public static function payloadFromLegacyPlacementDoc(array $doc): ?array
    {
        $admno = self::resolveAesAdmno($doc);
        if ($admno === '') {
            $admno = strtoupper(trim((string) ($doc['registerNumber'] ?? '')));
        }
        if ($admno === '') {
            return null;
        }

        return self::payloadFromAesRecord($doc, ['syncSource' => 'backfill_student_placements']);
    }

    /**
     * Do not flip an existing studying student to alumni without explicit AES alumni proof.
     *
     * @param array<string, mixed> $record raw AES row
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private static function guardStudRoleTransition(array $record, ?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        $existingRole = (string) ($existing['studRole'] ?? '');
        $incomingRole = (string) ($incoming['studRole'] ?? '');
        if ($incomingRole === 'alumni'
            && AesApiService::normalizeStudRole($record) === 'student') {
            $incoming['studRole'] = 'student';
            $incoming['stud_role'] = 'Student';
        }

        return $incoming;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function contentHash(array $doc): string
    {
        $subset = [];
        foreach (self::AES_OWNED_SCALAR_KEYS as $key) {
            if (array_key_exists($key, $doc) && !is_array($doc[$key])) {
                $subset[$key] = $doc[$key];
            }
        }
        if (isset($doc['aesRecord']) && is_array($doc['aesRecord'])) {
            $subset['aesRecord'] = md5((string) json_encode($doc['aesRecord'], JSON_UNESCAPED_UNICODE));
        }
        ksort($subset);

        return md5((string) json_encode($subset, JSON_UNESCAPED_UNICODE));
    }
}
