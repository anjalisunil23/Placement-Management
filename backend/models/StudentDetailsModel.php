<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Services\AesApiService;
use PMS\Services\DepartmentProgrammeCatalog;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * AES student master / directory — local source of truth after synchronization.
 */
class StudentDetailsModel extends BaseModel
{
    public const LIST_MAX = 10000;

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
            self::$tableReady = true;

            return true;
        } catch (\Throwable) {
            self::$tableUnavailable = true;

            return false;
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
        $now = DocumentHelper::now();
        $incoming['syncedAt'] = $now;
        if (!empty($options['syncSource'])) {
            $incoming['syncSource'] = (string) $options['syncSource'];
        }

        if ($existing === null) {
            $incoming['createdAt'] = $now;
            $id = $this->insert($incoming);

            return ['action' => 'inserted', 'id' => $id, 'aesAdmno' => $aesAdmno];
        }

        $merged = StudentPlacementModel::mergeNonEmptyValues($existing, $incoming);
        unset($merged['_id']);
        $beforeHash = self::contentHash($existing);
        $afterHash = self::contentHash($merged);
        if ($beforeHash === $afterHash) {
            return ['action' => 'unchanged', 'id' => (string) ($existing['_id'] ?? ''), 'aesAdmno' => $aesAdmno];
        }

        $merged['updatedAt'] = $now;
        $id = (string) ($existing['_id'] ?? '');
        $this->update($id, $merged);

        return ['action' => 'updated', 'id' => $id, 'aesAdmno' => $aesAdmno];
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
        int $limit = self::LIST_MAX
    ): array {
        if (!$this->bootstrapTable()) {
            return [];
        }

        $limit = max(1, min($limit, self::LIST_MAX));
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

        $sql .= ' ORDER BY JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.studentName\')) ASC LIMIT ' . $limit;

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

            return array_map(
                fn (array $doc): array => self::toDirectoryRecord($doc),
                $this->findAll($filter, $limit)
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
        $records = $this->listDirectoryRecords('', true, 'all', max(1, min($limit, self::LIST_MAX)));
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
            return count($this->findAll(
                $studRole !== '' && $studRole !== 'all'
                    ? ['studRole' => strtolower($studRole) === 'alumni' ? 'alumni' : 'student']
                    : [],
                self::LIST_MAX
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
        $studRole = AesApiService::normalizeStudRole($record)
            ?? (strtolower(trim((string) ($options['studRole'] ?? ''))) === 'alumni' ? 'alumni' : 'student');

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
            'courseId'       => trim((string) ($record['courseId'] ?? '')),
            'branchId'       => trim((string) ($record['branchId'] ?? '')),
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

        if (!empty($options['placeHubStudentId'])) {
            $payload['placeHubStudentId'] = (string) $options['placeHubStudentId'];
        }

        return $payload;
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
        ksort($subset);

        return md5((string) json_encode($subset, JSON_UNESCAPED_UNICODE));
    }
}
