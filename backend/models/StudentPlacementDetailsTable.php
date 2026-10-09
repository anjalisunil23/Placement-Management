<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;

/**
 * Flat placement / higher-education columns for student_placement_details.
 */
class StudentPlacementDetailsTable
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function isReady(): bool
    {
        try {
            $column = $this->db->query("SHOW COLUMNS FROM `student_placement_details` LIKE 'admno'");

            return (bool) $column->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensure(): bool
    {
        if (!$this->isReady()) {
            $this->createTable();
        }
        if (!$this->isReady()) {
            return false;
        }
        $this->renameCreatedatColumn();
        $this->ensureColumns();

        return $this->hasColumn('createdate') && $this->hasColumn('stud_role');
    }

    private function createTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS student_placement_details (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              student VARCHAR(255) NOT NULL DEFAULT \'\',
              admno VARCHAR(64) NOT NULL DEFAULT \'\',
              stud_role VARCHAR(16) NOT NULL DEFAULT \'\',
              cno VARCHAR(64) NOT NULL DEFAULT \'\',
              email VARCHAR(255) NOT NULL DEFAULT \'\',
              `year` VARCHAR(64) NOT NULL DEFAULT \'\',
              courseid VARCHAR(64) NOT NULL DEFAULT \'\',
              branchid VARCHAR(64) NOT NULL DEFAULT \'\',
              employer VARCHAR(255) NOT NULL DEFAULT \'\',
              empcno VARCHAR(128) NOT NULL DEFAULT \'\',
              empadr VARCHAR(512) NOT NULL DEFAULT \'\',
              payscale VARCHAR(128) NOT NULL DEFAULT \'\',
              status VARCHAR(64) NOT NULL DEFAULT \'\',
              createdBy VARCHAR(128) NOT NULL DEFAULT \'\',
              updatedBy VARCHAR(128) NOT NULL DEFAULT \'\',
              updatedate DATETIME NULL,
              fordvv VARCHAR(16) NOT NULL DEFAULT \'\',
              `type` VARCHAR(64) NOT NULL DEFAULT \'\',
              includedvv VARCHAR(16) NOT NULL DEFAULT \'\',
              createdate DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_student_placement_details_admno (admno),
              KEY idx_student_placement_details_year (`year`),
              KEY idx_student_placement_details_type (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureColumns(): void
    {
        $missing = [
            'stud_role' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'courseid' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'branchid' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'empcno' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'empadr' => "VARCHAR(512) NOT NULL DEFAULT ''",
            'payscale' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'status' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'createdBy' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'updatedBy' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'updatedate' => 'DATETIME NULL',
            'fordvv' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'type' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'includedvv' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'createdate' => 'DATETIME NULL',
        ];
        foreach ($missing as $name => $definition) {
            if ($this->hasColumn($name)) {
                continue;
            }
            $this->db->exec(
                'ALTER TABLE `student_placement_details` ADD COLUMN `' . $name . '` ' . $definition
            );
        }
    }

    private function hasColumn(string $name): bool
    {
        $statement = $this->db->prepare(
            'SHOW COLUMNS FROM `student_placement_details` WHERE Field = ?'
        );
        $statement->execute([$name]);

        return (bool) $statement->fetch();
    }

    /**
     * Copy student_placements into this table when the source has changed.
     */
    public function replaceFromStudentPlacements(): int
    {
        if (!$this->ensure() || !$this->sourceTableExists()) {
            return 0;
        }
        if (!$this->sourceChanged()) {
            return $this->count();
        }

        $columns = $this->sourceColumns();
        if ($columns === []) {
            return 0;
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $chunkSize = 50;
        $offset = 0;
        $total = 0;
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM `student_placement_details`');
            while (true) {
                $statement = $this->db->query(
                    'SELECT ' . $select . ' FROM `student_placements` LIMIT ' . $chunkSize . ' OFFSET ' . $offset
                );
                $chunk = [];
                $fetched = 0;
                while ($source = $statement->fetch()) {
                    $fetched++;
                    if (!is_array($source)) {
                        continue;
                    }
                    $chunk[] = $this->detailRowFromSource($source);
                    unset($source);
                }
                $statement->closeCursor();
                unset($statement);
                if ($chunk !== []) {
                    $this->insertChunk($chunk);
                    $total += count($chunk);
                }
                unset($chunk);
                if ($fetched < $chunkSize) {
                    break;
                }
                $offset += $chunkSize;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $total;
    }

    /**
     * Copy alumni placement rows from student_placements into this table.
     * Existing admission numbers are updated in place so the name list is not duplicated.
     */
    public function importAlumniFromStudentPlacements(): int
    {
        if (!$this->ensure() || !$this->sourceTableExists()) {
            return 0;
        }
        $sourceAlumni = $this->countAlumniSource();
        $storedAlumni = $this->countStudRole('alumni');
        if ($sourceAlumni > 0 && $storedAlumni >= $sourceAlumni) {
            $this->backfillStudRole();

            return $storedAlumni;
        }

        $columns = $this->sourceColumns();
        if ($columns === []) {
            return $storedAlumni;
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $byAdmno = $this->db->prepare(
            'SELECT `id` FROM `student_placement_details` WHERE `admno` = ? AND `admno` <> \'\' LIMIT 1'
        );
        $byName = $this->db->prepare(
            'SELECT `id` FROM `student_placement_details` WHERE `admno` = \'\' AND `student` = ? AND `year` = ? LIMIT 1'
        );
        $update = $this->db->prepare(
            'UPDATE `student_placement_details` SET
                `student` = ?, `stud_role` = ?, `cno` = ?, `email` = ?, `year` = ?,
                `courseid` = ?, `branchid` = ?, `employer` = ?, `empcno` = ?, `empadr` = ?,
                `payscale` = ?, `status` = ?, `createdBy` = ?, `updatedBy` = ?,
                `updatedate` = ?, `fordvv` = ?, `type` = ?, `includedvv` = ?, `createdate` = ?
             WHERE `id` = ?'
        );
        $where = $this->alumniSourceWhere();
        $order = $this->sourceHasColumn('id') ? ' ORDER BY `id` ASC' : '';
        $chunkSize = 50;
        $offset = 0;
        while (true) {
            $sql = 'SELECT ' . $select . ' FROM `student_placements`';
            if ($where !== '') {
                $sql .= ' WHERE ' . $where;
            }
            $statement = $this->db->query($sql . $order . ' LIMIT ' . $chunkSize . ' OFFSET ' . $offset);
            $fetched = 0;
            $inserts = [];
            while ($source = $statement->fetch()) {
                $fetched++;
                if (!is_array($source) || !$this->sourceRowIsAlumni($source)) {
                    unset($source);
                    continue;
                }
                $row = $this->detailRowFromSource($source);
                $row['stud_role'] = 'alumni';
                unset($source);
                $existingId = $this->existingDetailId($byAdmno, $byName, $row);
                if ($existingId !== null) {
                    $update->execute([
                        $row['student'], $row['stud_role'], $row['cno'], $row['email'], $row['year'],
                        $row['courseid'], $row['branchid'], $row['employer'], $row['empcno'], $row['empadr'],
                        $row['payscale'], $row['status'], $row['createdBy'], $row['updatedBy'],
                        $row['updatedate'], $row['fordvv'], $row['type'], $row['includedvv'], $row['createdate'],
                        $existingId,
                    ]);
                    continue;
                }
                $inserts[] = $row;
                if (count($inserts) >= 50) {
                    $this->insertChunk($inserts);
                    $inserts = [];
                }
            }
            $statement->closeCursor();
            unset($statement);
            if ($inserts !== []) {
                $this->insertChunk($inserts);
            }
            unset($inserts);
            if ($fetched < $chunkSize) {
                break;
            }
            $offset += $chunkSize;
        }

        $this->backfillStudRole();

        return $this->countStudRole('alumni');
    }

    /**
     * One student_placement_details row per student_details person.
     * Placement columns are filled only when admno matches an alumni row in student_placements.
     */
    public function syncDirectoryWithAlumniPlacements(): int
    {
        if (!$this->ensure()) {
            return 0;
        }
        $directory = new StudentDirectoryTable();
        if (!$directory->isReady()) {
            return $this->count();
        }
        $directoryCount = $directory->count();
        if ($directoryCount === 0) {
            return $this->count();
        }

        $signature = $this->directorySyncSignature($directoryCount);
        if ($this->count() === $directoryCount && $this->readSyncSignature() === $signature) {
            $this->refreshStudentNamesFromDirectory();

            return $directoryCount;
        }

        $directoryPart = strstr($signature, '|alumni:', true);
        $storedPart = strstr($this->readSyncSignature(), '|alumni:', true);
        $rebuildDirectory = $this->count() !== $directoryCount || $directoryPart === false || $directoryPart !== $storedPart;
        if ($rebuildDirectory) {
            $this->replaceRowsFromDirectory();
        } else {
            $this->clearPlacementColumns();
        }
        $alumniSeen = 0;
        if ($this->sourceTableExists()) {
            $alumniSeen = $this->overlayAlumniPlacementsByAdmno();
        }
        $alumniCount = 0;
        if (preg_match('/\|alumni:(\d+):/', $signature, $match) === 1) {
            $alumniCount = (int) $match[1];
        }
        if (!($alumniCount > 0 && $alumniSeen === 0)) {
            $this->writeSyncSignature($signature);
        }
        $this->refreshStudentNamesFromDirectory();

        return $this->count();
    }

    /**
     * Rows for the placements grid, newest update first.
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(int $limit = 5000): array
    {
        if (!$this->ensure()) {
            return [];
        }
        $limit = max(1, min($limit, 20000));
        $statement = $this->db->query(
            'SELECT `id`, `student`, `admno`, `stud_role`, `cno`, `email`, `year`, `courseid`, `branchid`,
                    `employer`, `empcno`, `empadr`, `payscale`, `status`, `createdBy`, `updatedBy`,
                    `updatedate`, `fordvv`, `type`, `includedvv`, `createdate`
             FROM `student_placement_details`
             ORDER BY `updatedate` DESC, `id` DESC
             LIMIT ' . $limit
        );
        $rows = [];
        while ($row = $statement->fetch()) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Alumni placement rows copied from student_placements.
     *
     * @return list<array<string, mixed>>
     */
    public function listAlumniRows(int $limit = 10000): array
    {
        if (!$this->ensure()) {
            return [];
        }
        $limit = max(1, min($limit, 20000));
        $statement = $this->db->query(
            'SELECT `id`, `student`, `admno`, `stud_role`, `cno`, `email`, `year`, `courseid`, `branchid`,
                    `employer`, `empcno`, `empadr`, `payscale`, `status`, `createdBy`, `updatedBy`,
                    `updatedate`, `fordvv`, `type`, `includedvv`, `createdate`
             FROM `student_placement_details`
             WHERE `stud_role` = \'alumni\'
             ORDER BY `student` ASC, `id` ASC
             LIMIT ' . $limit
        );
        $rows = [];
        while ($row = $statement->fetch()) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Latest placement row for each admission number.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapLatestByAdmno(): array
    {
        $map = [];
        foreach ($this->listRows(20000) as $row) {
            $admno = strtoupper(trim((string) ($row['admno'] ?? '')));
            if ($admno === '' || isset($map[$admno])) {
                continue;
            }
            $map[$admno] = $row;
        }

        return $map;
    }

    public function count(): int
    {
        if (!$this->isReady()) {
            return 0;
        }
        $count = $this->db->query('SELECT COUNT(*) FROM `student_placement_details`')->fetchColumn();

        return (int) $count;
    }

    private function sourceTableExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM `student_placements` LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function sourceChanged(): bool
    {
        $sourceCount = (int) $this->db->query('SELECT COUNT(*) FROM `student_placements`')->fetchColumn();
        $storedCount = $this->count();
        if ($sourceCount !== $storedCount) {
            return true;
        }
        if ($sourceCount === 0 || !$this->sourceHasColumn('updated_at')) {
            return false;
        }
        $sourceTouched = $this->db->query('SELECT MAX(`updated_at`) FROM `student_placements`')->fetchColumn();
        $storedTouched = $this->db->query('SELECT MAX(`updatedate`) FROM `student_placement_details`')->fetchColumn();

        return $this->sqlDateTime($sourceTouched) !== $this->sqlDateTime($storedTouched);
    }

    private function sourceHasColumn(string $name): bool
    {
        $statement = $this->db->prepare('SHOW COLUMNS FROM `student_placements` WHERE Field = ?');
        $statement->execute([$name]);

        return (bool) $statement->fetch();
    }

    /**
     * @return list<string>
     */
    private function sourceColumns(): array
    {
        $columns = [];
        foreach ($this->db->query('SHOW COLUMNS FROM `student_placements`') as $column) {
            $name = trim((string) ($column['Field'] ?? ''));
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string|null>
     */
    private function detailRowFromSource(array $source): array
    {
        $payload = [];
        if (array_key_exists('payload', $source)) {
            $decoded = json_decode((string) ($source['payload'] ?? ''), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        foreach ($source as $key => $value) {
            if (in_array($key, ['id', 'payload', 'student_id', 'pair_key', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$key] = $value;
        }
        $payload['_id'] = (string) ($source['id'] ?? ($payload['studentId'] ?? ''));
        if (trim((string) ($payload['studentId'] ?? '')) === '') {
            $payload['studentId'] = (string) ($source['student_id'] ?? $payload['_id']);
        }
        $payload['createdAt'] = $source['created_at'] ?? ($payload['createdAt'] ?? null);
        $payload['updatedAt'] = $source['updated_at'] ?? ($payload['updatedAt'] ?? null);

        $roster = StudentPlacementModel::rosterRowFromDocument($payload);
        $placement = is_array($roster['placement'] ?? null) ? $roster['placement'] : [];
        $type = trim((string) ($placement['recordType'] ?? $placement['type'] ?? ''));
        $employer = trim((string) ($placement['company'] ?? $placement['employer'] ?? ''));
        if ($type === '' && $employer !== '') {
            $type = 'Placement';
        }

        return [
            'student' => $this->clip((string) ($roster['studentName'] ?? ''), 255),
            'admno' => $this->clip((string) ($roster['admno'] ?? $roster['registerNumber'] ?? ''), 64),
            'stud_role' => $this->alumniRoleFromPayload($payload, (string) ($roster['studRole'] ?? '')),
            'cno' => $this->clip((string) ($roster['phone'] ?? ''), 64),
            'email' => $this->clip((string) ($roster['email'] ?? ''), 255),
            'year' => $this->clip((string) ($roster['classBatch'] ?? ''), 64),
            'courseid' => $this->clip((string) ($roster['courseId'] ?? ''), 64),
            'branchid' => $this->clip((string) ($roster['branchId'] ?? ''), 64),
            'employer' => $this->clip($employer, 255),
            'empcno' => $this->clip((string) ($placement['employerContact'] ?? $placement['empcno'] ?? $placement['empco'] ?? ''), 128),
            'empadr' => $this->clip((string) ($placement['address'] ?? $placement['empadr'] ?? ''), 512),
            'payscale' => $this->clip((string) ($placement['package'] ?? $placement['payscale'] ?? ''), 128),
            'status' => $this->clip((string) ($placement['placementStatus'] ?? $placement['status'] ?? ''), 64),
            'createdBy' => $this->clip((string) ($roster['createdBy'] ?? $placement['createdBy'] ?? ''), 128),
            'updatedBy' => $this->clip((string) ($roster['updatedBy'] ?? $placement['updatedBy'] ?? ''), 128),
            'updatedate' => $this->sqlDateTime($roster['updatedAt'] ?? null),
            'fordvv' => $this->clip((string) ($placement['fordvv'] ?? ''), 16),
            'type' => $this->clip($type, 64),
            'includedvv' => $this->clip((string) ($placement['includedvv'] ?? ''), 16),
            'createdate' => $this->sqlDateTime($roster['createdAt'] ?? null),
        ];
    }

    /**
     * @param list<array<string, string|null>> $rows
     */
    private function insertChunk(array $rows): void
    {
        $placeholders = [];
        $values = [];
        $fields = [
            'student', 'admno', 'stud_role', 'cno', 'email', 'year', 'courseid', 'branchid',
            'employer', 'empcno', 'empadr', 'payscale', 'status', 'createdBy',
            'updatedBy', 'updatedate', 'fordvv', 'type', 'includedvv', 'createdate',
        ];
        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
            foreach ($fields as $field) {
                $values[] = $row[$field];
            }
        }
        $quoted = array_map(static fn (string $field): string => '`' . $field . '`', $fields);
        $sql = 'INSERT INTO `student_placement_details` (' . implode(', ', $quoted) . ') VALUES '
            . implode(', ', $placeholders);
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
    }

    private function renameCreatedatColumn(): void
    {
        if ($this->hasColumn('createdat') && !$this->hasColumn('createdate')) {
            $this->db->exec(
                'ALTER TABLE `student_placement_details` CHANGE `createdat` `createdate` DATETIME NULL'
            );
        }
    }

    private function backfillStudRole(): void
    {
        if (!$this->hasColumn('stud_role')) {
            return;
        }
        try {
            $blank = (int) $this->db->query(
                "SELECT COUNT(*) FROM `student_placement_details` WHERE `stud_role` = ''"
            )->fetchColumn();
            if ($blank === 0) {
                return;
            }
            $this->db->exec(
                "UPDATE `student_placement_details` p
                 INNER JOIN `student_details` d
                   ON d.adm_no <> '' AND d.adm_no = p.admno
                 SET p.stud_role = LOWER(d.stud_role)
                 WHERE p.stud_role = ''
                   AND LOWER(d.stud_role) IN ('student', 'alumni')"
            );
        } catch (\Throwable) {
            // student_details may not be ready yet; the next placements load retries.
        }
    }

    private function normalizeStudRole(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'alumni' || $value === 'student') {
            return $value;
        }

        return '';
    }

    private function clip(string $value, int $length): string
    {
        $value = trim($value);
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length);
    }

    private function sqlDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $text, $match) === 1) {
            return $match[1] . ' ' . $match[2];
        }
        $timestamp = strtotime($text);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function countStudRole(string $role): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM `student_placement_details` WHERE `stud_role` = ?'
        );
        $statement->execute([$role]);

        return (int) $statement->fetchColumn();
    }

    private function countAlumniSource(): int
    {
        $where = $this->alumniSourceWhere();
        if ($where === '') {
            return 0;
        }
        try {
            return (int) $this->db->query(
                'SELECT COUNT(*) FROM `student_placements` WHERE ' . $where
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function alumniSourceWhere(): string
    {
        $parts = [];
        if ($this->sourceHasColumn('payload')) {
            $parts[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.stud_role'))) LIKE '%alumni%'";
            $parts[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.studRole'))) LIKE '%alumni%'";
            $parts[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.placementStudRole'))) LIKE '%alumni%'";
            $parts[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.roster.studRole'))) LIKE '%alumni%'";
            $parts[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.roster.stud_role'))) LIKE '%alumni%'";
        }
        if ($this->sourceHasColumn('stud_role')) {
            $parts[] = "LOWER(`stud_role`) LIKE '%alumni%'";
        }

        return implode(' OR ', $parts);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sourceRowIsAlumni(array $source): bool
    {
        return $this->alumniRoleFromPayload($this->payloadFromSource($source), '') === 'alumni';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function alumniRoleFromPayload(array $payload, string $rosterRole): string
    {
        $role = $this->normalizeStudRole($rosterRole);
        if ($role === '') {
            $detected = \PMS\Services\AesApiService::normalizeStudRole($payload);
            $role = $detected === 'alumni' || $detected === 'student' ? $detected : '';
        }
        if ($role === '' && is_array($payload['roster'] ?? null)) {
            $detected = \PMS\Services\AesApiService::normalizeStudRole($payload['roster']);
            $role = $detected === 'alumni' || $detected === 'student' ? $detected : '';
        }
        if ($role === '') {
            $placementRole = strtolower(trim((string) ($payload['placementStudRole'] ?? '')));
            if ($placementRole === 'alumni' || str_contains($placementRole, 'alumni')) {
                $role = 'alumni';
            } elseif ($placementRole === 'student' || str_contains($placementRole, 'student')) {
                $role = 'student';
            }
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function payloadFromSource(array $source): array
    {
        $payload = [];
        if (array_key_exists('payload', $source)) {
            $decoded = json_decode((string) ($source['payload'] ?? ''), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        foreach ($source as $key => $value) {
            if (in_array($key, ['id', 'payload', 'student_id', 'pair_key', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function existingDetailId(\PDOStatement $byAdmno, \PDOStatement $byName, array $row): ?string
    {
        $admno = (string) ($row['admno'] ?? '');
        if ($admno !== '') {
            $byAdmno->execute([$admno]);
            $id = $byAdmno->fetchColumn();
            $byAdmno->closeCursor();

            return $id === false || $id === null || (string) $id === '' ? null : (string) $id;
        }
        $byName->execute([(string) ($row['student'] ?? ''), (string) ($row['year'] ?? '')]);
        $id = $byName->fetchColumn();
        $byName->closeCursor();

        return $id === false || $id === null || (string) $id === '' ? null : (string) $id;
    }

    private function directorySyncSignature(int $directoryCount): string
    {
        $synced = '';
        try {
            $synced = (string) $this->db->query('SELECT MAX(`synced_at`) FROM `student_details`')->fetchColumn();
        } catch (\Throwable) {
            $synced = '';
        }
        $alumniCount = 0;
        $alumniTouched = '';
        if ($this->sourceTableExists()) {
            $alumniCount = $this->countAlumniSource();
            if ($this->sourceHasColumn('updated_at')) {
                try {
                    $sql = 'SELECT MAX(`updated_at`) FROM `student_placements`';
                    $where = $this->alumniSourceWhere();
                    if ($alumniCount > 0 && $where !== '') {
                        $sql .= ' WHERE ' . $where;
                    }
                    $alumniTouched = (string) $this->db->query($sql)->fetchColumn();
                } catch (\Throwable) {
                    $alumniTouched = '';
                }
            }
        }

        return 'dir:' . $directoryCount . ':' . ($this->sqlDateTime($synced) ?? $synced)
            . '|alumni:' . $alumniCount . ':' . ($this->sqlDateTime($alumniTouched) ?? $alumniTouched);
    }

    private function replaceRowsFromDirectory(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM `student_placement_details`');
            $this->db->exec(
                'INSERT INTO `student_placement_details` (`student`, `admno`, `stud_role`, `year`)
                 SELECT TRIM(`student_name`), TRIM(`adm_no`),
                        CASE
                          WHEN LOWER(`stud_role`) LIKE \'%alumni%\' THEN \'alumni\'
                          WHEN LOWER(`stud_role`) LIKE \'%student%\' THEN \'student\'
                          ELSE LOWER(LEFT(`stud_role`, 16))
                        END,
                        TRIM(`batch`)
                 FROM `student_details`
                 ORDER BY `student_name` ASC, `id` ASC'
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function refreshStudentNamesFromDirectory(): void
    {
        try {
            $this->db->exec(
                'UPDATE `student_placement_details` p
                 INNER JOIN `student_details` d
                   ON d.adm_no <> \'\' AND TRIM(d.adm_no) = TRIM(p.admno)
                 SET p.`student` = TRIM(d.student_name)
                 WHERE TRIM(d.student_name) <> \'\''
            );
        } catch (\Throwable) {
            // student_details may not be ready yet; the next placements load retries.
        }
    }

    private function clearPlacementColumns(): void
    {
        $this->db->exec(
            'UPDATE `student_placement_details` SET
                `cno` = \'\', `email` = \'\', `courseid` = \'\', `branchid` = \'\',
                `employer` = \'\', `empcno` = \'\', `empadr` = \'\', `payscale` = \'\',
                `status` = \'\', `createdBy` = \'\', `updatedBy` = \'\', `updatedate` = NULL,
                `fordvv` = \'\', `type` = \'\', `includedvv` = \'\', `createdate` = NULL'
        );
    }

    private function overlayAlumniPlacementsByAdmno(): int
    {
        $columns = $this->sourceColumns();
        if ($columns === []) {
            return 0;
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $update = $this->db->prepare(
            'UPDATE `student_placement_details` SET
                `cno` = ?, `email` = ?, `courseid` = ?, `branchid` = ?,
                `employer` = ?, `empcno` = ?, `empadr` = ?, `payscale` = ?,
                `status` = ?, `createdBy` = ?, `updatedBy` = ?, `updatedate` = ?,
                `fordvv` = ?, `type` = ?, `includedvv` = ?, `createdate` = ?
             WHERE `admno` <> \'\' AND `admno` = ?'
        );
        $where = $this->alumniSourceWhere();
        $order = '';
        if ($this->sourceHasColumn('updated_at')) {
            $order = ' ORDER BY `updated_at` ASC';
            if ($this->sourceHasColumn('id')) {
                $order .= ', `id` ASC';
            }
        } elseif ($this->sourceHasColumn('id')) {
            $order = ' ORDER BY `id` ASC';
        }
        $chunkSize = 50;
        $offset = 0;
        $seen = 0;
        while (true) {
            $sql = 'SELECT ' . $select . ' FROM `student_placements`';
            if ($where !== '') {
                $sql .= ' WHERE ' . $where;
            }
            $statement = $this->db->query($sql . $order . ' LIMIT ' . $chunkSize . ' OFFSET ' . $offset);
            $fetched = 0;
            while ($source = $statement->fetch()) {
                $fetched++;
                if (!is_array($source) || !$this->sourceRowIsAlumni($source)) {
                    unset($source);
                    continue;
                }
                $row = $this->detailRowFromSource($source);
                unset($source);
                $seen++;
                $admno = (string) ($row['admno'] ?? '');
                if ($admno === '') {
                    continue;
                }
                $update->execute([
                    $row['cno'], $row['email'], $row['courseid'], $row['branchid'],
                    $row['employer'], $row['empcno'], $row['empadr'], $row['payscale'],
                    $row['status'], $row['createdBy'], $row['updatedBy'], $row['updatedate'],
                    $row['fordvv'], $row['type'], $row['includedvv'], $row['createdate'],
                    $admno,
                ]);
            }
            $statement->closeCursor();
            unset($statement);
            if ($fetched < $chunkSize) {
                break;
            }
            $offset += $chunkSize;
        }

        return $seen;
    }

    private function syncSignaturePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_student_placement_details_sync.txt';
    }

    private function readSyncSignature(): string
    {
        $path = $this->syncSignaturePath();
        if (!is_file($path)) {
            return '';
        }
        $text = file_get_contents($path);

        return is_string($text) ? trim($text) : '';
    }

    private function writeSyncSignature(string $signature): void
    {
        try {
            file_put_contents($this->syncSignaturePath(), $signature);
        } catch (\Throwable) {
            // The next placements load repeats the copy if the signature cannot be saved.
        }
    }
}
