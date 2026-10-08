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
        if ($this->isReady()) {
            return true;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS student_placement_details (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              student VARCHAR(255) NOT NULL DEFAULT \'\',
              admno VARCHAR(64) NOT NULL DEFAULT \'\',
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
              createdat DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_student_placement_details_admno (admno),
              KEY idx_student_placement_details_year (`year`),
              KEY idx_student_placement_details_type (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return $this->isReady();
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

        $rows = $this->rowsFromStudentPlacements();
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM `student_placement_details`');
            $chunk = [];
            foreach ($rows as $row) {
                $chunk[] = $row;
                if (count($chunk) >= 200) {
                    $this->insertChunk($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->insertChunk($chunk);
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
     * Rows for the placements grid, newest update first.
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(int $limit = 5000): array
    {
        if (!$this->isReady()) {
            return [];
        }
        $limit = max(1, min($limit, 10000));
        $statement = $this->db->query(
            'SELECT `id`, `student`, `admno`, `cno`, `email`, `year`, `courseid`, `branchid`,
                    `employer`, `empcno`, `empadr`, `payscale`, `status`, `createdBy`, `updatedBy`,
                    `updatedate`, `fordvv`, `type`, `includedvv`, `createdat`
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
     * @return list<array<string, string|null>>
     */
    private function rowsFromStudentPlacements(): array
    {
        $columns = $this->sourceColumns();
        if ($columns === []) {
            return [];
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $statement = $this->db->query('SELECT ' . $select . ' FROM `student_placements`');
        $rows = [];
        while ($source = $statement->fetch()) {
            if (!is_array($source)) {
                continue;
            }
            $rows[] = $this->detailRowFromSource($source);
        }

        return $rows;
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
            'createdat' => $this->sqlDateTime($roster['createdAt'] ?? null),
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
            'student', 'admno', 'cno', 'email', 'year', 'courseid', 'branchid',
            'employer', 'empcno', 'empadr', 'payscale', 'status', 'createdBy',
            'updatedBy', 'updatedate', 'fordvv', 'type', 'includedvv', 'createdat',
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
}
