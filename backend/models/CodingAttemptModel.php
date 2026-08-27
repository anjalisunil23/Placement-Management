<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class CodingAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::CODING_ATTEMPTS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `coding_attempts` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function start(array $data): string
    {
        return $this->insert([
            'userId' => (string) ($data['userId'] ?? ''),
            'testId' => (string) ($data['testId'] ?? ''),
            'testTitle' => (string) ($data['testTitle'] ?? ''),
            'contestType' => (string) ($data['contestType'] ?? 'none'),
            'status' => 'in_progress',
            'startedAt' => DocumentHelper::now(),
            'endsAt' => $data['endsAt'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(string $id, array $result): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }
        if (isset($result['status']) && $result['status'] !== 'submitted') {
            $result['resultStatus'] = (string) $result['status'];
        }
        $result['status'] = 'submitted';
        $result['submittedAt'] = DocumentHelper::now();
        $result['completedAt'] = DocumentHelper::now();
        return $this->update($id, $result);
    }
}
