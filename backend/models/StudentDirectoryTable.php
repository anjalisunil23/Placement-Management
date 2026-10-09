<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;

/**
 * Students page directory stored in student_details.
 * Columns match the list: student, admission number, department, stud_role, batch, action.
 */
class StudentDirectoryTable
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function isReady(): bool
    {
        try {
            $column = $this->db->query("SHOW COLUMNS FROM `student_details` LIKE 'student_name'");

            return (bool) $column->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensure(): bool
    {
        if ($this->isReady()) {
            return true;
        }
        if ($this->tableExists()) {
            // Older JSON student_details is still present. Do not replace it from a page request.
            return false;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS student_details (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              student_name VARCHAR(255) NOT NULL DEFAULT \'\',
              adm_no VARCHAR(64) NOT NULL DEFAULT \'\',
              department VARCHAR(255) NOT NULL DEFAULT \'\',
              stud_role VARCHAR(16) NOT NULL,
              batch VARCHAR(128) NOT NULL DEFAULT \'\',
              action VARCHAR(191) NOT NULL,
              synced_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_student_details_action (action),
              KEY idx_student_details_role (stud_role),
              KEY idx_student_details_adm_no (adm_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return $this->isReady();
    }

    public function count(): int
    {
        if (!$this->isReady()) {
            return 0;
        }
        $count = $this->db->query('SELECT COUNT(*) FROM `student_details`')->fetchColumn();

        return (int) $count;
    }

    /**
     * @return array{student:int,alumni:int,syncedAt:?string}
     */
    public function counts(): array
    {
        $counts = ['student' => 0, 'alumni' => 0, 'syncedAt' => null];
        if (!$this->isReady()) {
            return $counts;
        }
        $stmt = $this->db->query('SELECT stud_role, COUNT(*) AS n FROM `student_details` GROUP BY stud_role');
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $role = strtolower(trim((string) ($row['stud_role'] ?? '')));
            if ($role === 'student' || $role === 'alumni') {
                $counts[$role] = (int) ($row['n'] ?? 0);
            }
        }
        $synced = $this->db->query('SELECT MAX(synced_at) FROM `student_details`')->fetchColumn();
        $counts['syncedAt'] = is_string($synced) && $synced !== '' ? $synced : null;

        return $counts;
    }

    /**
     * Every studying student and alumni row, for the placements name list.
     *
     * @return list<array{name:string,admno:string,department:string,studRole:string,batch:string,action:string}>
     */
    public function listAll(int $limit = 20000): array
    {
        if (!$this->isReady()) {
            return [];
        }
        $limit = max(1, min($limit, 30000));
        $statement = $this->db->query(
            'SELECT student_name, adm_no, department, stud_role, batch, action
             FROM `student_details`
             ORDER BY student_name ASC, id ASC
             LIMIT ' . $limit
        );
        $rows = [];
        while ($row = $statement->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $role = strtolower(trim((string) ($row['stud_role'] ?? '')));
            $rows[] = [
                'name' => trim((string) ($row['student_name'] ?? '')),
                'admno' => trim((string) ($row['adm_no'] ?? '')),
                'department' => trim((string) ($row['department'] ?? '')),
                'studRole' => $role === 'alumni' ? 'alumni' : 'student',
                'batch' => trim((string) ($row['batch'] ?? '')),
                'action' => trim((string) ($row['action'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByRole(string $role): array
    {
        if (!$this->isReady()) {
            return [];
        }
        $role = strtolower($role) === 'alumni' ? 'alumni' : 'student';
        $stmt = $this->db->prepare(
            'SELECT student_name, adm_no, department, stud_role, batch, action
             FROM `student_details`
             WHERE stud_role = ?
             ORDER BY student_name ASC, id ASC'
        );
        $stmt->execute([$role]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['student_name'] ?? ''));
            $admno = trim((string) ($row['adm_no'] ?? ''));
            $action = trim((string) ($row['action'] ?? ''));
            $department = trim((string) ($row['department'] ?? ''));
            $noAdmno = $admno === '';
            $rows[] = [
                'id'             => $action !== '' ? $action : $admno,
                'registerNumber' => $noAdmno ? $action : $admno,
                'admno'          => $admno,
                'noAdmno'        => $noAdmno,
                'displayName'    => $name !== '' ? $name : '—',
                'name'           => $name,
                'departmentName' => $department,
                'department'     => ['name' => $department, 'code' => ''],
                'classBatch'     => trim((string) ($row['batch'] ?? '')),
                'studRole'       => $role,
                'stud_role'      => $role === 'alumni' ? 'Alumni' : 'Student',
            ];
        }

        return $rows;
    }

    /**
     * Replace the directory with the AES sync snapshot already fetched.
     * Studying rows are stored as student. Alumni rows omit anyone already in the studying list.
     *
     * @param list<array<string, mixed>> $studyingRecords
     * @param list<array<string, mixed>> $alumniRecords
     */
    public function replaceFromAesRecords(array $studyingRecords, array $alumniRecords, string $syncedAt = ''): int
    {
        if (!$this->ensure()) {
            return 0;
        }

        $rows = [];
        $studyingKeys = [];
        foreach ($this->rowsFromRecords($studyingRecords, 'student') as $row) {
            $rows[$row['action']] = $row;
            $studyingKeys[$row['action']] = true;
            if ($row['adm_no'] !== '') {
                $studyingKeys[strtoupper($row['adm_no'])] = true;
            }
        }
        foreach ($this->rowsFromRecords($alumniRecords, 'alumni') as $row) {
            $admKey = strtoupper($row['adm_no']);
            if (($admKey !== '' && isset($studyingKeys[$admKey])) || isset($studyingKeys[$row['action']])) {
                continue;
            }
            $rows[$row['action']] = $row;
        }

        $synced = $this->sqlDateTime($syncedAt);
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM `student_details`');
            $chunk = [];
            foreach ($rows as $row) {
                $chunk[] = $row;
                if (count($chunk) >= 200) {
                    $this->insertChunk($chunk, $synced);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->insertChunk($chunk, $synced);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }

    /**
     * Load the table from a campus snapshot that is already on disk.
     *
     * @param array<string, mixed> $payload
     */
    public function storeSnapshotIfEmpty(array $payload): int
    {
        if (!$this->ensure() || $this->count() > 0) {
            return 0;
        }
        $studying = is_array($payload['studyingRecords'] ?? null) ? $payload['studyingRecords'] : [];
        $alumni = is_array($payload['alumniRecords'] ?? null) ? $payload['alumniRecords'] : [];
        if ($studying === [] && $alumni === []) {
            return 0;
        }

        return $this->replaceFromAesRecords(
            $studying,
            $alumni,
            (string) ($payload['syncedAt'] ?? '')
        );
    }

    private function tableExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM `student_details` LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array{student_name:string,adm_no:string,department:string,stud_role:string,batch:string,action:string}>
     */
    private function rowsFromRecords(array $records, string $role): array
    {
        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $action = StudentDetailsModel::resolveAesAdmno($record);
            $noAdmno = !empty($record['noAdmno']) || str_starts_with($action, 'ALUMNI:');
            if ($action === '') {
                continue;
            }
            $name = trim((string) (
                $record['stud_name']
                ?? $record['name']
                ?? $record['displayName']
                ?? $record['studentName']
                ?? ''
            ));
            $batch = self::blankRoleWord(trim((string) ($record['classBatch'] ?? $record['stud_class'] ?? '')));
            $department = self::blankRoleWord(trim((string) (
                $record['departmentName']
                ?? $record['parentDepartmentName']
                ?? $record['stud_branch']
                ?? $record['stud_course']
                ?? $record['stud_cource_short']
                ?? $record['programme']
                ?? ''
            )));
            if (strlen($action) > 191) {
                $action = 'A' . substr(md5($action), 0, 31);
            }
            $rows[] = [
                'student_name' => $name,
                'adm_no'       => $noAdmno ? '' : $action,
                'department'   => $department,
                'stud_role'    => $role,
                'batch'        => $batch,
                'action'       => $action,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{student_name:string,adm_no:string,department:string,stud_role:string,batch:string,action:string}> $rows
     */
    private function insertChunk(array $rows, ?string $syncedAt): void
    {
        $placeholders = [];
        $values = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            $values[] = $row['student_name'];
            $values[] = $row['adm_no'];
            $values[] = $row['department'];
            $values[] = $row['stud_role'];
            $values[] = $row['batch'];
            $values[] = $row['action'];
            $values[] = $syncedAt;
        }
        $sql = 'INSERT INTO `student_details` (student_name, adm_no, department, stud_role, batch, action, synced_at) VALUES '
            . implode(', ', $placeholders);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    private static function blankRoleWord(string $value): string
    {
        return in_array(strtolower($value), ['student', 'stud', 'alumni', 'alumnus'], true) ? '' : $value;
    }

    private function sqlDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
