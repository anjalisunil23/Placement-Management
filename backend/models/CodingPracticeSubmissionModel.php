<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class CodingPracticeSubmissionModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::CODING_PRACTICE_SUBMISSIONS;
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
            'CREATE TABLE IF NOT EXISTS `coding_practice_submissions` (
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
    public function record(array $data): string
    {
        $payload = [
            'userId' => (string) ($data['userId'] ?? ''),
            'bankProblemId' => (string) ($data['bankProblemId'] ?? ''),
            'problemTitle' => (string) ($data['problemTitle'] ?? ''),
            'language' => (string) ($data['language'] ?? 'Python'),
            'status' => (string) ($data['status'] ?? 'wrong'),
            'accepted' => !empty($data['accepted']),
            'testsPassed' => max(0, (int) ($data['testsPassed'] ?? 0)),
            'testsTotal' => max(0, (int) ($data['testsTotal'] ?? 0)),
            'score' => (float) ($data['score'] ?? 0),
            'totalMarks' => (float) ($data['totalMarks'] ?? 0),
            'percentage' => (float) ($data['percentage'] ?? 0),
            'timeTakenSeconds' => max(0, (int) ($data['timeTakenSeconds'] ?? 0)),
            'submittedAt' => DocumentHelper::now(),
        ];

        return $this->insert($payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(string $userId, int $limit = 100): array
    {
        if ($userId === '') {
            return [];
        }

        return $this->findAll(['userId' => $userId], $limit, 0, ['submittedAt' => -1]);
    }

    /**
     * @return array<string, array{status: string, attemptCount: int, lastSubmittedAt: string, testsPassed: int, testsTotal: int}>
     */
    public function statusMapForUser(string $userId): array
    {
        $map = [];
        foreach ($this->listByUser($userId, 500) as $row) {
            $bankId = trim((string) ($row['bankProblemId'] ?? ''));
            if ($bankId === '') {
                continue;
            }
            if (!isset($map[$bankId])) {
                $map[$bankId] = [
                    'status' => 'attempted',
                    'attemptCount' => 0,
                    'lastSubmittedAt' => (string) ($row['submittedAt'] ?? ''),
                    'testsPassed' => (int) ($row['testsPassed'] ?? 0),
                    'testsTotal' => (int) ($row['testsTotal'] ?? 0),
                ];
            }
            $map[$bankId]['attemptCount'] += 1;
            if (!empty($row['accepted'])) {
                $map[$bankId]['status'] = 'solved';
            }
        }

        return $map;
    }
}
