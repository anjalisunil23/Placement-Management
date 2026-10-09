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
              source_id VARCHAR(64) NULL DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_student_placement_details_source_id (source_id),
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
            'source_id' => 'VARCHAR(64) NULL DEFAULT NULL',
        ];
        foreach ($missing as $name => $definition) {
            if ($this->hasColumn($name)) {
                continue;
            }
            $this->db->exec(
                'ALTER TABLE `student_placement_details` ADD COLUMN `' . $name . '` ' . $definition
            );
        }
        $this->ensureSourceIdIndex();
        $this->ensurePlacementValueWidths();
    }

    private function ensurePlacementValueWidths(): void
    {
        $widths = [
            'cno' => 255,
            'courseid' => 128,
            'branchid' => 128,
            'employer' => 512,
            'empcno' => 255,
            'payscale' => 255,
            'status' => 128,
        ];
        foreach ($widths as $name => $length) {
            $statement = $this->db->prepare('SHOW COLUMNS FROM `student_placement_details` WHERE Field = ?');
            $statement->execute([$name]);
            $column = $statement->fetch();
            if (!is_array($column)) {
                continue;
            }
            $type = strtolower((string) ($column['Type'] ?? ''));
            if (preg_match('/varchar\((\d+)\)/', $type, $match) !== 1 || (int) $match[1] >= $length) {
                continue;
            }
            $this->db->exec(
                'ALTER TABLE `student_placement_details` MODIFY `' . $name . '` VARCHAR(' . $length . ') NOT NULL DEFAULT \'\''
            );
        }
    }

    private function ensureSourceIdIndex(): void
    {
        if (!$this->hasColumn('source_id')) {
            return;
        }
        $existing = $this->db->query(
            "SHOW INDEX FROM `student_placement_details` WHERE Key_name = 'uq_student_placement_details_source_id'"
        );
        if ($existing !== false && $existing->fetch()) {
            return;
        }
        try {
            $this->db->exec(
                'ALTER TABLE `student_placement_details` ADD UNIQUE KEY `uq_student_placement_details_source_id` (`source_id`)'
            );
        } catch (\Throwable) {
            // The copy still runs if this index cannot be added.
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
     * Placement columns that are still empty are filled from student_placements
     * when the admission number matches. Student name, role, and batch stay from student_details.
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

        $directoryPart = strstr($signature, '|src:', true);
        $storedPart = strstr($this->readSyncSignature(), '|src:', true);
        $rebuildDirectory = $this->count() !== $directoryCount || $directoryPart === false || $directoryPart !== $storedPart;
        if ($rebuildDirectory) {
            $this->replaceRowsFromDirectory();
        } else {
            $this->clearPlacementColumns();
        }
        $alumniSeen = 0;
        if ($this->sourceTableExists()) {
            $alumniSeen = $this->overlayNeededPlacementColumns();
        }
        if ($alumniSeen > 0 || !$this->sourceTableExists()) {
            $this->writeSyncSignature($signature);
        }
        $this->refreshStudentNamesFromDirectory();

        return $this->count();
    }

    /**
     * Keep student_placement_details as the permanent grid store.
     * Copy from student_placements only while this table has no placement values, then drop that table.
     */
    public function ensureDirectoryShell(): int
    {
        if (!$this->ensure()) {
            return 0;
        }
        $directory = new StudentDirectoryTable();
        if ($directory->isReady() && $directory->count() > 0 && $this->count() === 0) {
            $this->replaceRowsFromDirectory();
            $this->writeSyncSignature('');
        }
        if (!$this->sourceTableExists()) {
            return $this->count();
        }
        if (!$this->detailsHoldCopiedPlacements()) {
            try {
                $this->fillEmptyColumnsFromPlacements();
            } catch (\Throwable) {
                // Keep student_placements until the copy has landed in this table.
                return $this->count();
            }
        }
        if ($this->detailsHoldCopiedPlacements()) {
            $this->dropSourceTable();
        }

        return $this->count();
    }

    /**
     * True when this table already holds placement or higher-education values.
     */
    public function detailsHoldCopiedPlacements(): bool
    {
        if (!$this->isReady()) {
            return false;
        }
        try {
            $row = $this->db->query(
                'SELECT 1 FROM `student_placement_details`
                 WHERE TRIM(`employer`) <> \'\' OR TRIM(`type`) <> \'\'
                 LIMIT 1'
            )->fetch();
        } catch (\Throwable) {
            return false;
        }

        return (bool) $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAdmnoOrId(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || !$this->ensure()) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT * FROM `student_placement_details` WHERE UPPER(TRIM(`admno`)) = ? LIMIT 1'
        );
        $statement->execute([strtoupper($key)]);
        $row = $statement->fetch();
        if (is_array($row)) {
            return $row;
        }
        if (preg_match('/^\d+$/', $key) !== 1) {
            return null;
        }
        $byId = $this->db->prepare(
            'SELECT * FROM `student_placement_details` WHERE `id` = ? LIMIT 1'
        );
        $byId->execute([(int) $key]);
        $row = $byId->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Write an edit onto the permanent row for this admission number.
     *
     * @param array<string, mixed> $fields
     */
    public function saveFieldsForAdmno(string $admno, array $fields): bool
    {
        $row = $this->findByAdmnoOrId($admno);
        if ($row === null) {
            return false;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        $allowed = [
            'employer' => 512,
            'empcno' => 255,
            'empadr' => 512,
            'payscale' => 255,
            'status' => 128,
            'fordvv' => 16,
            'type' => 64,
            'includedvv' => 16,
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $column => $limit) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $value = trim((string) $fields[$column]);
            if (strlen($value) > $limit) {
                $value = substr($value, 0, $limit);
            }
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = '`updatedate` = NOW()';
        $params[] = $id;
        $statement = $this->db->prepare(
            'UPDATE `student_placement_details` SET ' . implode(', ', $sets)
            . ' WHERE `id` = ?'
        );
        $statement->execute($params);

        return true;
    }

    private function dropSourceTable(): void
    {
        try {
            $this->db->exec('DROP TABLE `student_placements`');
        } catch (\Throwable) {
            // Another request may already have dropped it.
        }
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
        $limit = max(1, min($limit, 30000));
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
     * @return list<string>
     */
    public function distinctColumnValues(string $column, int $limit = 2000): array
    {
        if (!$this->ensure()) {
            return [];
        }
        $allowed = ['courseid' => true, 'year' => true];
        if (!isset($allowed[$column])) {
            return [];
        }
        $limit = max(1, min($limit, 5000));
        $statement = $this->db->query(
            'SELECT DISTINCT `' . $column . '` AS v FROM `student_placement_details`
             WHERE `' . $column . "` <> '' ORDER BY v ASC LIMIT " . $limit
        );
        $values = [];
        while ($row = $statement->fetch()) {
            $v = trim((string) ($row['v'] ?? ''));
            if ($v !== '') {
                $values[] = $v;
            }
        }

        return $values;
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
        $employer = $this->firstText($payload, $placement, ['employer', 'company', 'companyName']);
        $type = $this->firstText($payload, $placement, ['type', 'recordType']);
        if ($type === '' && $employer !== '') {
            $type = 'Placement';
        }

        return [
            'student' => $this->clip((string) ($roster['studentName'] ?? ''), 255),
            'admno' => $this->clip((string) ($roster['admno'] ?? $roster['registerNumber'] ?? ''), 64),
            'stud_role' => $this->alumniRoleFromPayload($payload, (string) ($roster['studRole'] ?? '')),
            'cno' => $this->clip($this->firstText($payload, $placement, ['cno', 'phone', 'mobile', 'stud_mobile', 'contactPhone']) ?: (string) ($roster['phone'] ?? ''), 64),
            'email' => $this->clip($this->firstText($payload, $placement, ['email', 'collegeEmail', 'personalEmail', 'stud_email']) ?: (string) ($roster['email'] ?? ''), 255),
            'year' => $this->clip((string) ($roster['classBatch'] ?? ''), 64),
            'courseid' => $this->clip($this->firstText($payload, $placement, ['courseid', 'courseId', 'course_id', 'stud_courseid']) ?: (string) ($roster['courseId'] ?? ''), 64),
            'branchid' => $this->clip($this->firstText($payload, $placement, ['branchid', 'branchId', 'branch_id', 'stud_branchid']) ?: (string) ($roster['branchId'] ?? ''), 64),
            'employer' => $this->clip($employer, 255),
            'empcno' => $this->clip($this->firstText($payload, $placement, ['empcno', 'empco', 'employerContact', 'contact', 'emp_contact']), 128),
            'empadr' => $this->clip($this->firstText($payload, $placement, ['empadr', 'address', 'employerAddress']), 512),
            'payscale' => $this->clip($this->firstText($payload, $placement, ['payscale', 'package', 'salary']), 128),
            'status' => $this->clip($this->firstText($payload, $placement, ['status', 'placementStatus']), 64),
            'createdBy' => $this->clip($this->firstText($payload, $placement, ['createdBy']) ?: (string) ($roster['createdBy'] ?? ''), 128),
            'updatedBy' => $this->clip($this->firstText($payload, $placement, ['updatedBy']) ?: (string) ($roster['updatedBy'] ?? ''), 128),
            'updatedate' => $this->sqlDateTime($roster['updatedAt'] ?? null),
            'fordvv' => $this->clip($this->firstText($payload, $placement, ['fordvv']), 16),
            'type' => $this->clip($type, 64),
            'includedvv' => $this->clip($this->firstText($payload, $placement, ['includedvv']), 16),
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

        return 'dir:' . $directoryCount . ':' . ($this->sqlDateTime($synced) ?? $synced)
            . '|src:' . $this->placementSourceSignature()
            . '|cols:2';
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

    private function overlayNeededPlacementColumns(): int
    {
        $columns = $this->sourceColumns();
        if ($columns === []) {
            return 0;
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $textFields = [
            'cno', 'email', 'courseid', 'branchid', 'employer', 'empcno', 'empadr',
            'payscale', 'status', 'createdBy', 'updatedBy', 'fordvv', 'type', 'includedvv',
        ];
        $assignments = [];
        foreach ($textFields as $field) {
            $assignments[] = '`' . $field . '` = IF(`' . $field . '` = \'\' AND ? <> \'\', ?, `' . $field . '`)';
        }
        $assignments[] = '`updatedate` = IF(`updatedate` IS NULL AND ? IS NOT NULL, ?, `updatedate`)';
        $assignments[] = '`createdate` = IF(`createdate` IS NULL AND ? IS NOT NULL, ?, `createdate`)';
        $update = $this->db->prepare(
            'UPDATE `student_placement_details` SET ' . implode(', ', $assignments)
            . ' WHERE `admno` <> \'\' AND `admno` = ?'
        );
        $order = '';
        if ($this->sourceHasColumn('updated_at')) {
            $order = ' ORDER BY `updated_at` DESC';
            if ($this->sourceHasColumn('id')) {
                $order .= ', `id` DESC';
            }
        } elseif ($this->sourceHasColumn('id')) {
            $order = ' ORDER BY `id` DESC';
        }
        $chunkSize = 50;
        $offset = 0;
        $seen = 0;
        while (true) {
            $statement = $this->db->query(
                'SELECT ' . $select . ' FROM `student_placements`' . $order
                . ' LIMIT ' . $chunkSize . ' OFFSET ' . $offset
            );
            $fetched = 0;
            while ($source = $statement->fetch()) {
                $fetched++;
                if (!is_array($source)) {
                    continue;
                }
                $payload = $this->payloadFromSource($source);
                $row = $this->detailRowFromSource($source);
                unset($source);
                $needed = false;
                foreach ($textFields as $field) {
                    if (trim((string) ($row[$field] ?? '')) !== '') {
                        $needed = true;
                        break;
                    }
                }
                if (!$needed) {
                    unset($payload, $row);
                    continue;
                }
                $seen++;
                $params = [];
                foreach ($textFields as $field) {
                    $value = (string) ($row[$field] ?? '');
                    $params[] = $value;
                    $params[] = $value;
                }
                $params[] = $row['updatedate'];
                $params[] = $row['updatedate'];
                $params[] = $row['createdate'];
                $params[] = $row['createdate'];
                foreach ($this->admissionKeys($payload, (string) ($row['admno'] ?? '')) as $admno) {
                    $update->execute([...$params, $admno]);
                }
                unset($payload, $row);
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

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function admissionKeys(array $payload, string $admno): array
    {
        $keys = [];
        $add = static function (mixed $value) use (&$keys): void {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $keys[strtoupper($value)] = $value;
        };
        $add($admno);
        foreach (['admno', 'admissionNo', 'stud_admno', 'registerNumber', 'registerno'] as $key) {
            $add($payload[$key] ?? '');
        }
        foreach (['placement', 'roster', 'personal'] as $nested) {
            if (!is_array($payload[$nested] ?? null)) {
                continue;
            }
            foreach (['admno', 'admissionNo', 'stud_admno', 'registerNumber', 'registerno'] as $key) {
                $add($payload[$nested][$key] ?? '');
            }
        }

        return array_values($keys);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $placement
     * @param list<string> $keys
     */
    private function firstText(array $payload, array $placement, array $keys): string
    {
        $sources = [$payload, $placement];
        foreach (['placement', 'selfPlacement', 'roster'] as $nested) {
            if (is_array($payload[$nested] ?? null)) {
                $sources[] = $payload[$nested];
            }
            if (is_array($placement[$nested] ?? null)) {
                $sources[] = $placement[$nested];
            }
        }
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? '';
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function placementSourceSignature(): string
    {
        if (!$this->sourceTableExists()) {
            return '0:';
        }
        try {
            $count = (int) $this->db->query('SELECT COUNT(*) FROM `student_placements`')->fetchColumn();
            $touched = '';
            if ($this->sourceHasColumn('updated_at')) {
                $touched = (string) $this->db->query('SELECT MAX(`updated_at`) FROM `student_placements`')->fetchColumn();
            }

            return $count . ':' . ($this->sqlDateTime($touched) ?? $touched);
        } catch (\Throwable) {
            return '0:';
        }
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

    /**
     * Compare admission numbers and copy placement columns from student_placements
     * onto the matching student_placement_details row. One indexed pass, then stop.
     */
    private function fillEmptyColumnsFromPlacements(): void
    {
        if (!$this->sourceTableExists()) {
            return;
        }
        $admnoColumn = $this->sourceColumn('admno');
        $typeColumn = $this->sourceColumn('type');
        $idColumn = $this->sourceColumn('id');
        if ($admnoColumn === null || $idColumn === null) {
            return;
        }
        $signature = 'admj:8:' . $this->placementSourceSignature();
        if ($this->readSyncSignature() === $signature) {
            return;
        }
        $fields = $this->resolvedPlacementFields();
        if ($fields === []) {
            return;
        }
        try {
            $this->db->exec('SET SESSION group_concat_max_len = 4096');
        } catch (\Throwable) {
            // The default length still holds several values for one student.
        }
        $aggregate = $this->aggregatedPlacementSql($admnoColumn, $typeColumn, $idColumn, $fields);
        $this->updateDetailsFromMatchedAdmno($fields, $aggregate);
        $this->insertPlacementsForNewAdmno($fields, $aggregate);
        $this->writeSyncSignature($signature);
    }

    /**
     * Latest type=placement row for each admission number.
     */
    private function pickedPlacementJoin(string $admnoColumn, ?string $typeColumn, string $idColumn): string
    {
        $admnoKey = 'TRIM(CAST(p.`' . $admnoColumn . '` AS CHAR))';
        $where = $admnoKey . ' <> \'\'';
        if ($typeColumn !== null) {
            $where .= ' AND ' . $this->placementTypeWhere('p', $typeColumn);
        }

        return 'SELECT src.*
            FROM `student_placements` src
            INNER JOIN (
                SELECT ' . $admnoKey . ' AS admno_key, MAX(p.`' . $idColumn . '`) AS pick_id
                FROM `student_placements` p
                WHERE ' . $where . '
                GROUP BY ' . $admnoKey . '
            ) picked ON src.`' . $idColumn . '` = picked.pick_id';
    }

    private function placementTypeWhere(string $alias, string $typeColumn): string
    {
        $value = 'LOWER(TRIM(CAST(' . $alias . '.`' . $typeColumn . '` AS CHAR)))';

        return '(' . $value . ' IN (\'placement\', \'placements\', \'higher education\', \'higher_education\', \'highereducation\', \'higher ed\', \'higher_ed\')'
            . ' OR ' . $value . ' LIKE \'higher education%\')';
    }

    private function sourceText(string $alias, string $column, int $limit): string
    {
        return 'LEFT(TRIM(IFNULL(CAST(' . $alias . '.`' . $column . '` AS CHAR), \'\')), ' . $limit . ')';
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function updateDetailsFromMatchedAdmno(array $fields, string $aggregate): void
    {
        $sets = [];
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                continue;
            }
            $sets[] = 'd.`' . $field['dest'] . '` = IF(s.`' . $field['dest'] . '` IS NOT NULL AND s.`' . $field['dest'] . '` <> \'\', s.`' . $field['dest'] . '`, d.`' . $field['dest'] . '`)';
        }
        foreach ($this->placementDateCopies('p') as $date) {
            $sets[] = 'd.`' . $date['dest'] . '` = IF(s.`' . $date['dest'] . '` IS NOT NULL, s.`' . $date['dest'] . '`, d.`' . $date['dest'] . '`)';
        }
        if ($sets === []) {
            return;
        }
        $this->db->exec(
            'UPDATE `student_placement_details` d
             INNER JOIN (' . $aggregate . ') s
               ON d.`admno` <> \'\'
              AND d.`admno` = s.`admno_key`
             SET ' . implode(', ', $sets)
        );
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function aggregatedPlacementSql(string $admnoColumn, ?string $typeColumn, string $idColumn, array $fields): string
    {
        $admnoKey = 'TRIM(CAST(p.`' . $admnoColumn . '` AS CHAR))';
        $parts = [
            $admnoKey . ' AS `admno_key`',
            $this->groupedText($this->sourceColumn('student'), $idColumn, 255) . ' AS `student`',
        ];
        foreach ($fields as $field) {
            $parts[] = $this->groupedText($field['source'], $idColumn, $field['limit']) . ' AS `' . $field['dest'] . '`';
        }
        foreach ($this->placementDateCopies('p') as $date) {
            $parts[] = 'MAX(' . $date['expr'] . ') AS `' . $date['dest'] . '`';
        }
        $where = $admnoKey . ' <> \'\'';
        if ($typeColumn !== null) {
            $where .= ' AND ' . $this->placementTypeWhere('p', $typeColumn);
        }

        return 'SELECT ' . implode(', ', $parts)
            . ' FROM `student_placements` p WHERE ' . $where
            . ' GROUP BY ' . $admnoKey;
    }

    private function groupedText(?string $sourceColumn, string $idColumn, int $limit): string
    {
        if ($sourceColumn === null) {
            return '\'\'';
        }
        $value = 'NULLIF(TRIM(CAST(p.`' . $sourceColumn . '` AS CHAR)), \'\')';

        return 'LEFT(GROUP_CONCAT(DISTINCT ' . $value . ' ORDER BY ' . $value . ' SEPARATOR \', \'), ' . $limit . ')';
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function insertPlacementsForNewAdmno(array $fields, string $aggregate): void
    {
        $hasYear = false;
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                $hasYear = true;
                break;
            }
        }
        $pieces = [
            ['col' => 'student', 'expr' => 'IFNULL(s.`student`, \'\')'],
            ['col' => 'admno', 'expr' => 's.`admno_key`'],
            ['col' => 'stud_role', 'expr' => '\'\''],
            ['col' => 'year', 'expr' => $hasYear ? 'IFNULL(s.`year`, \'\')' : '\'\''],
        ];
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                continue;
            }
            $pieces[] = ['col' => $field['dest'], 'expr' => 'IFNULL(s.`' . $field['dest'] . '`, \'\')'];
        }
        foreach ($this->placementDateCopies('p') as $date) {
            $pieces[] = ['col' => $date['dest'], 'expr' => 's.`' . $date['dest'] . '`'];
        }
        $cols = [];
        $inner = [];
        foreach ($pieces as $piece) {
            $cols[] = '`' . $piece['col'] . '`';
            $inner[] = $piece['expr'] . ' AS `' . $piece['col'] . '`';
        }
        $this->db->exec(
            'INSERT INTO `student_placement_details` (' . implode(', ', $cols) . ')
             SELECT ' . implode(', ', $cols) . '
             FROM (
                SELECT ' . implode(', ', $inner) . '
                FROM (' . $aggregate . ') s
                LEFT JOIN `student_placement_details` existing
                  ON existing.`admno` <> \'\'
                 AND existing.`admno` = s.`admno_key`
                WHERE s.`admno_key` <> \'\'
                  AND existing.`admno` IS NULL
             ) incoming'
        );
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function insertEveryPlacementRow(string $admnoColumn, string $typeColumn, string $idColumn, array $fields): void
    {
        $studentColumn = $this->sourceColumn('student');
        $pieces = [
            ['col' => 'student', 'expr' => $studentColumn !== null ? $this->sourceText('s', $studentColumn, 255) : '\'\''],
            ['col' => 'admno', 'expr' => $this->sourceText('s', $admnoColumn, 64)],
            ['col' => 'stud_role', 'expr' => '\'\''],
            ['col' => 'year', 'expr' => '\'\''],
            ['col' => 'source_id', 'expr' => 'LEFT(TRIM(CAST(s.`' . $idColumn . '` AS CHAR)), 64)'],
        ];
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                $pieces[3]['expr'] = $this->sourceText('s', $field['source'], $field['limit']);
                continue;
            }
            $pieces[] = [
                'col' => $field['dest'],
                'expr' => $this->sourceText('s', $field['source'], $field['limit']),
            ];
        }
        foreach ($this->placementDateCopies('s') as $date) {
            $pieces[] = ['col' => $date['dest'], 'expr' => $date['expr']];
        }
        $cols = [];
        $inner = [];
        foreach ($pieces as $piece) {
            $cols[] = '`' . $piece['col'] . '`';
            $inner[] = $piece['expr'] . ' AS `' . $piece['col'] . '`';
        }
        $idKey = 'LEFT(TRIM(CAST(s.`' . $idColumn . '` AS CHAR)), 64)';
        $this->db->exec(
            'INSERT INTO `student_placement_details` (' . implode(', ', $cols) . ')
             SELECT ' . implode(', ', $cols) . '
             FROM (
                SELECT ' . implode(', ', $inner) . '
                FROM `student_placements` s
                LEFT JOIN `student_placement_details` existing
                  ON existing.`source_id` = ' . $idKey . '
                WHERE ' . $this->placementTypeWhere('s', $typeColumn) . '
                  AND ' . $idKey . ' <> \'\'
                  AND existing.`source_id` IS NULL
             ) incoming'
        );
    }

    private function applyDirectoryIdentityToCopies(): void
    {
        $directory = new StudentDirectoryTable();
        if (!$directory->isReady()) {
            return;
        }
        $this->db->exec(
            'UPDATE `student_placement_details` d
             INNER JOIN `student_details` sd
               ON sd.`adm_no` <> \'\' AND sd.`adm_no` = d.`admno`
             SET d.`stud_role` = CASE
                    WHEN LOWER(sd.`stud_role`) LIKE \'%alumni%\' THEN \'alumni\'
                    WHEN LOWER(sd.`stud_role`) LIKE \'%student%\' THEN \'student\'
                    ELSE LOWER(LEFT(sd.`stud_role`, 16))
                 END,
                 d.`student` = IF(TRIM(sd.`student_name`) <> \'\', TRIM(sd.`student_name`), d.`student`)
             WHERE d.`source_id` IS NOT NULL AND d.`source_id` <> \'\''
        );
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function overwriteCopiedPlacementRows(string $idColumn, array $fields): void
    {
        $sets = [];
        foreach ($fields as $field) {
            $sets[] = 'd.`' . $field['dest'] . '` = ' . $this->sourceText('s', $field['source'], $field['limit']);
        }
        foreach ($this->placementDateCopies('s') as $date) {
            $sets[] = 'd.`' . $date['dest'] . '` = ' . $date['expr'];
        }
        if ($sets === []) {
            return;
        }
        $this->db->exec(
            'UPDATE `student_placement_details` d
             INNER JOIN `student_placements` s
               ON d.`source_id` = TRIM(CAST(s.`' . $idColumn . '` AS CHAR))
             SET ' . implode(', ', $sets)
        );
    }

    /**
     * @return list<array{dest:string,expr:string}>
     */
    private function placementDateCopies(string $alias): array
    {
        $map = [
            'updatedate' => ['updatedate', 'updated_at', 'updatedAt'],
            'createdate' => ['createdate', 'createdat', 'created_at', 'createdAt'],
        ];
        $copies = [];
        foreach ($map as $dest => $candidates) {
            if (!$this->hasColumn($dest)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                $source = $this->sourceColumn($candidate);
                if ($source === null) {
                    continue;
                }
                $raw = 'TRIM(CAST(' . $alias . '.`' . $source . '` AS CHAR))';
                $copies[] = [
                    'dest' => $dest,
                    'expr' => 'CASE WHEN ' . $raw . " REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN CAST(" . $raw . ' AS DATETIME) ELSE NULL END',
                ];
                break;
            }
        }

        return $copies;
    }

    private function insertMissingDirectoryRows(): int
    {
        $selectSql = 'SELECT TRIM(d.`student_name`) AS `student`, TRIM(d.`adm_no`) AS `admno`,
                    CASE
                      WHEN LOWER(d.`stud_role`) LIKE \'%alumni%\' THEN \'alumni\'
                      WHEN LOWER(d.`stud_role`) LIKE \'%student%\' THEN \'student\'
                      ELSE LOWER(LEFT(d.`stud_role`, 16))
                    END AS `stud_role`,
                    TRIM(d.`batch`) AS `year`
             FROM `student_details` d
             WHERE NOT EXISTS (
                 SELECT 1 FROM `student_placement_details` p
                 WHERE (TRIM(d.`adm_no`) <> \'\' AND '
                    . $this->admnoMatchKey('p.`admno`') . ' = ' . $this->admnoMatchKey('d.`adm_no`') . ')
                    OR (TRIM(d.`adm_no`) = \'\' AND TRIM(p.`admno`) = \'\'
                        AND TRIM(p.`student`) = TRIM(d.`student_name`)
                        AND TRIM(p.`year`) = TRIM(d.`batch`))
             )';
        $this->db->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_pms_missing_directory`');
        $this->db->exec('CREATE TEMPORARY TABLE `tmp_pms_missing_directory` AS ' . $selectSql);
        $inserted = $this->db->exec(
            'INSERT INTO `student_placement_details` (`student`, `admno`, `stud_role`, `year`)
             SELECT `student`, `admno`, `stud_role`, `year` FROM `tmp_pms_missing_directory`'
        );
        $this->db->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_pms_missing_directory`');

        return $inserted === false ? 0 : (int) $inserted;
    }

    /**
     * Same key for 16549, 016549, and mixed-case admission numbers.
     */
    private function admnoMatchKey(string $expr): string
    {
        $trimmed = 'TRIM(CAST(' . $expr . ' AS CHAR))';

        return 'CASE WHEN ' . $trimmed . " REGEXP '^[0-9]+$' THEN CAST(CAST(" . $trimmed
            . ' AS UNSIGNED) AS CHAR) ELSE UPPER(' . $trimmed . ') END';
    }

    /**
     * @return list<array{dest:string,source:string,limit:int}>
     */
    private function resolvedPlacementFields(): array
    {
        $map = [
            'cno' => [['cno'], 255],
            'email' => [['email'], 255],
            'year' => [['year'], 64],
            'courseid' => [['courseId', 'courseid'], 128],
            'branchid' => [['branchId', 'branchid'], 128],
            'employer' => [['employer'], 512],
            'empcno' => [['empcno', 'empco'], 255],
            'empadr' => [['empadr'], 512],
            'payscale' => [['payscale'], 255],
            'status' => [['status'], 128],
            'createdBy' => [['createdBy'], 128],
            'updatedBy' => [['updatedBy'], 128],
            'fordvv' => [['fordvv'], 16],
            'type' => [['type'], 64],
            'includedvv' => [['includedvv'], 16],
        ];
        $fields = [];
        foreach ($map as $dest => [$candidates, $limit]) {
            foreach ($candidates as $candidate) {
                $source = $this->sourceColumn($candidate);
                if ($source === null) {
                    continue;
                }
                $fields[] = ['dest' => $dest, 'source' => $source, 'limit' => $limit];
                break;
            }
        }

        return $fields;
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function updateMatchedPlacementRows(string $admnoColumn, array $fields): void
    {
        $idColumn = $this->sourceColumn('id');
        if ($idColumn === null) {
            return;
        }
        $sets = [];
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                continue;
            }
            $source = $field['source'];
            $dest = $field['dest'];
            $sets[] = 'd.`' . $dest . '` = IF(d.`' . $dest . '` = \'\' AND TRIM(CAST(s.`' . $source
                . '` AS CHAR)) <> \'\', LEFT(TRIM(CAST(s.`' . $source . '` AS CHAR)), ' . $field['limit']
                . '), d.`' . $dest . '`)';
        }
        if ($sets === []) {
            return;
        }
        $typeColumn = $this->sourceColumn('type');
        $where = 'TRIM(CAST(p.`' . $admnoColumn . '` AS CHAR)) <> \'\'';
        if ($typeColumn !== null) {
            $where .= ' AND LOWER(TRIM(CAST(p.`' . $typeColumn . '` AS CHAR))) IN (\'placement\', \'placements\')';
        }
        $this->db->exec(
            'UPDATE `student_placement_details` d
             INNER JOIN (
                SELECT src.*
                FROM `student_placements` src
                INNER JOIN (
                    SELECT ' . $this->admnoMatchKey('p.`' . $admnoColumn . '`') . ' AS admno_key,
                           MAX(p.`' . $idColumn . '`) AS pick_id
                    FROM `student_placements` p
                    WHERE ' . $where . '
                    GROUP BY admno_key
                ) picked ON src.`' . $idColumn . '` = picked.pick_id
             ) s ON TRIM(d.`admno`) <> \'\'
                AND ' . $this->admnoMatchKey('d.`admno`') . ' = ' . $this->admnoMatchKey('s.`' . $admnoColumn . '`') . '
             SET ' . implode(', ', $sets)
        );
    }

    /**
     * @param list<array{dest:string,source:string,limit:int}> $fields
     */
    private function insertMissingPlacementRows(string $admnoColumn, array $fields): void
    {
        $typeColumn = $this->sourceColumn('type');
        if ($typeColumn === null) {
            return;
        }
        $studentColumn = $this->sourceColumn('student');
        $studentExpr = $studentColumn !== null
            ? 'TRIM(CAST(s.`' . $studentColumn . '` AS CHAR))'
            : '\'\'';
        $sourceKey = $this->admnoMatchKey('s.`' . $admnoColumn . '`');
        $insertCols = ['`student`', '`admno`', '`stud_role`', '`year`'];
        $selects = [
            'COALESCE(NULLIF(TRIM(dir.`student`), \'\'), NULLIF(' . $studentExpr . ', \'\'), \'\') AS `student`',
            'LEFT(TRIM(CAST(s.`' . $admnoColumn . '` AS CHAR)), 64) AS `admno`',
            'COALESCE(dir.`stud_role`, \'\') AS `stud_role`',
        ];
        $yearSelect = '\'\' AS `year`';
        $dataFields = [];
        foreach ($fields as $field) {
            if ($field['dest'] === 'year') {
                $yearSelect = 'LEFT(TRIM(IFNULL(CAST(s.`' . $field['source'] . '` AS CHAR), \'\')), '
                    . $field['limit'] . ') AS `year`';
                continue;
            }
            $dataFields[] = $field;
        }
        $selects[] = $yearSelect;
        $identity = [];
        foreach ($dataFields as $field) {
            $insertCols[] = '`' . $field['dest'] . '`';
            $selects[] = 'LEFT(TRIM(IFNULL(CAST(s.`' . $field['source'] . '` AS CHAR), \'\')), '
                . $field['limit'] . ') AS `' . $field['dest'] . '`';
            if (in_array($field['dest'], ['employer', 'cno', 'email', 'payscale', 'courseid', 'branchid'], true)) {
                $identity[] = 'TRIM(IFNULL(d.`' . $field['dest'] . '`, \'\')) = TRIM(IFNULL(CAST(s.`'
                    . $field['source'] . '` AS CHAR), \'\'))';
            }
        }
        $identitySql = $identity === [] ? '1 = 1' : implode(' AND ', $identity);
        $selectSql = 'SELECT ' . implode(', ', $selects) . '
             FROM `student_placements` s
             LEFT JOIN (
                SELECT ' . $this->admnoMatchKey('p.`admno`') . ' AS admno_key, MIN(p.`id`) AS pick_id
                FROM `student_placement_details` p
                WHERE TRIM(p.`admno`) <> \'\'
                GROUP BY admno_key
             ) picked ON picked.admno_key = ' . $sourceKey . '
             LEFT JOIN `student_placement_details` dir ON dir.`id` = picked.pick_id
             WHERE LOWER(TRIM(CAST(s.`' . $typeColumn . '` AS CHAR))) IN (\'placement\', \'placements\')
               AND (
                    TRIM(CAST(s.`' . $admnoColumn . '` AS CHAR)) <> \'\'
                    OR ' . $studentExpr . ' <> \'\'
               )
               AND NOT EXISTS (
                    SELECT 1 FROM `student_placement_details` d
                    WHERE ' . $identitySql . '
                      AND (
                        (TRIM(CAST(s.`' . $admnoColumn . '` AS CHAR)) <> \'\' AND '
                            . $this->admnoMatchKey('d.`admno`') . ' = ' . $sourceKey . ')
                        OR (
                            TRIM(CAST(s.`' . $admnoColumn . '` AS CHAR)) = \'\'
                            AND TRIM(d.`admno`) = \'\'
                            AND TRIM(d.`student`) = ' . $studentExpr . '
                            AND TRIM(d.`student`) <> \'\'
                        )
                      )
               )';
        $this->db->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_pms_missing_placements`');
        $this->db->exec('CREATE TEMPORARY TABLE `tmp_pms_missing_placements` AS ' . $selectSql);
        $this->db->exec(
            'INSERT INTO `student_placement_details` (' . implode(', ', $insertCols) . ')
             SELECT ' . implode(', ', $insertCols) . ' FROM `tmp_pms_missing_placements`'
        );
        $this->db->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_pms_missing_placements`');
    }

    private function sourceColumn(string $name): ?string
    {
        foreach ($this->sourceColumns() as $column) {
            if (strcasecmp($column, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    private function fillPlacementIdChunk(string $afterId): ?string
    {
        $statement = $this->db->prepare(
            'SELECT `id` FROM `student_placements` WHERE (? = \'\' OR `id` > ?) ORDER BY `id` ASC LIMIT 250'
        );
        $statement->execute([$afterId, $afterId]);
        $ids = [];
        while ($id = $statement->fetchColumn()) {
            $ids[] = (string) $id;
        }
        $statement->closeCursor();
        if ($ids === []) {
            return null;
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        if ($this->sourceHasColumn('payload')) {
            $admno = $this->jsonCoalesce([
                '$.admno', '$.admissionNo', '$.stud_admno', '$.registerNumber', '$.registerno',
                '$.roster.admno', '$.roster.admissionNo', '$.roster.stud_admno',
                '$.personal.admno', '$.personal.admissionNo',
                '$.placement.admno', '$.placement.admissionNo',
            ]);
            $sets = [
                $this->fillIfBlank('cno', $this->jsonCoalesce(['$.cno', '$.phone', '$.mobile', '$.stud_mobile', '$.placement.cno', '$.placement.phone', '$.roster.phone']), 64),
                $this->fillIfBlank('email', $this->jsonCoalesce(['$.email', '$.collegeEmail', '$.personalEmail', '$.stud_email', '$.placement.email', '$.roster.email']), 255),
                $this->fillIfBlank('courseid', $this->jsonCoalesce(['$.courseid', '$.courseId', '$.course_id', '$.stud_courseid', '$.roster.courseId']), 64),
                $this->fillIfBlank('branchid', $this->jsonCoalesce(['$.branchid', '$.branchId', '$.branch_id', '$.stud_branchid', '$.roster.branchId']), 64),
                $this->fillIfBlank('employer', $this->jsonCoalesce(['$.employer', '$.company', '$.companyName', '$.placement.employer', '$.placement.company', '$.placement.companyName']), 255),
                $this->fillIfBlank('empcno', $this->jsonCoalesce(['$.empcno', '$.empco', '$.employerContact', '$.contact', '$.placement.empcno', '$.placement.employerContact', '$.placement.contact']), 128),
                $this->fillIfBlank('empadr', $this->jsonCoalesce(['$.empadr', '$.address', '$.placement.empadr', '$.placement.address']), 512),
                $this->fillIfBlank('payscale', $this->jsonCoalesce(['$.payscale', '$.package', '$.salary', '$.placement.payscale', '$.placement.package']), 128),
                $this->fillIfBlank('status', $this->jsonCoalesce(['$.status', '$.placementStatus', '$.placement.status', '$.placement.placementStatus']), 64),
                $this->fillIfBlank('createdBy', $this->jsonCoalesce(['$.createdBy', '$.placement.createdBy']), 128),
                $this->fillIfBlank('updatedBy', $this->jsonCoalesce(['$.updatedBy', '$.placement.updatedBy']), 128),
                $this->fillIfBlank('fordvv', $this->jsonCoalesce(['$.fordvv', '$.placement.fordvv']), 16),
                $this->fillIfBlank('type', $this->jsonCoalesce(['$.type', '$.recordType', '$.placement.type', '$.placement.recordType']), 64),
                $this->fillIfBlank('includedvv', $this->jsonCoalesce(['$.includedvv', '$.placement.includedvv']), 16),
            ];
            $sql = 'UPDATE `student_placement_details` d
                    INNER JOIN `student_placements` s ON s.`id` IN (' . $marks . ')
                      AND d.`admno` <> \'\'
                      AND TRIM(d.`admno`) = TRIM(' . $admno . ')
                      AND TRIM(' . $admno . ') <> \'\'
                    SET ' . implode(', ', $sets);
            $update = $this->db->prepare($sql);
            $update->execute($ids);
        }
        $this->fillFlatPlacementChunk($ids, $marks);

        return $ids[count($ids) - 1];
    }

    /**
     * @param list<string> $ids
     */
    private function fillFlatPlacementChunk(array $ids, string $marks): void
    {
        if (!$this->sourceHasColumn('admno')) {
            return;
        }
        $map = [
            'cno' => ['cno'],
            'email' => ['email'],
            'courseid' => ['courseid', 'courseId'],
            'branchid' => ['branchid', 'branchId'],
            'employer' => ['employer'],
            'empcno' => ['empcno', 'empco'],
            'empadr' => ['empadr'],
            'payscale' => ['payscale'],
            'status' => ['status'],
            'createdBy' => ['createdBy'],
            'updatedBy' => ['updatedBy'],
            'fordvv' => ['fordvv'],
            'type' => ['type'],
            'includedvv' => ['includedvv'],
        ];
        $limits = [
            'cno' => 64, 'email' => 255, 'courseid' => 64, 'branchid' => 64, 'employer' => 255,
            'empcno' => 128, 'empadr' => 512, 'payscale' => 128, 'status' => 64,
            'createdBy' => 128, 'updatedBy' => 128, 'fordvv' => 16, 'type' => 64, 'includedvv' => 16,
        ];
        $sets = [];
        foreach ($map as $dest => $sources) {
            foreach ($sources as $source) {
                if (!$this->sourceHasColumn($source)) {
                    continue;
                }
                $sets[] = 'd.`' . $dest . '` = IF(d.`' . $dest . '` = \'\' AND TRIM(s.`' . $source . '`) <> \'\', LEFT(TRIM(s.`' . $source . '`), ' . $limits[$dest] . '), d.`' . $dest . '`)';
                break;
            }
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE `student_placement_details` d
                INNER JOIN `student_placements` s ON s.`id` IN (' . $marks . ')
                  AND d.`admno` <> \'\'
                  AND TRIM(d.`admno`) = TRIM(s.`admno`)
                SET ' . implode(', ', $sets);
        $update = $this->db->prepare($sql);
        $update->execute($ids);
    }

    /**
     * @param list<string> $paths
     */
    private function jsonCoalesce(array $paths): string
    {
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.`payload`, '" . $path . "')), 'null')";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    private function fillIfBlank(string $column, string $expression, int $length): string
    {
        return 'd.`' . $column . '` = IF(d.`' . $column . '` = \'\' AND TRIM(' . $expression . ') <> \'\', LEFT(TRIM(' . $expression . '), ' . $length . '), d.`' . $column . '`)';
    }
}
