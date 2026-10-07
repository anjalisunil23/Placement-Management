<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class CodingAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    public static function normalizeStatus(mixed $status): string
    {
        $raw = strtoupper(trim((string) $status));
        return match ($raw) {
            'ACTIVE', 'IN_PROGRESS' => 'ACTIVE',
            'SUBMITTED', 'COMPLETED' => 'SUBMITTED',
            'EXPIRED' => 'EXPIRED',
            default => $raw,
        };
    }

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
            'testKind' => (string) ($data['testKind'] ?? 'regular'),
            'companyId' => trim((string) ($data['companyId'] ?? '')) !== '' ? (string) $data['companyId'] : null,
            'problemItemId' => trim((string) ($data['problemItemId'] ?? '')) !== '' ? (string) $data['problemItemId'] : null,
            'contestStartTime' => (string) ($data['contestStartTime'] ?? '09:00'),
            'periodKey' => (string) ($data['periodKey'] ?? ''),
            'contestWindowBounds' => is_array($data['contestWindowBounds'] ?? null) ? $data['contestWindowBounds'] : [],
            'status' => 'ACTIVE',
            'startedAt' => DocumentHelper::now(),
            'endsAt' => $data['endsAt'] ?? null,
            'lastSavedAt' => DocumentHelper::now(),
            'answers' => is_array($data['answers'] ?? null) ? $data['answers'] : [],
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function saveDraft(string $id, array $draft): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }
        $attempt = $this->findById($id);
        if (!$attempt || self::normalizeStatus($attempt['status'] ?? '') !== 'ACTIVE') {
            return false;
        }
        $answers = is_array($attempt['answers'] ?? null) ? $attempt['answers'] : [];
        $questionId = trim((string) ($draft['questionId'] ?? ''));
        if ($questionId === '') {
            return false;
        }
        $current = is_array($answers[$questionId] ?? null) ? $answers[$questionId] : [];
        if (array_key_exists('language', $draft)) {
            $current['language'] = (string) $draft['language'];
        }
        if (array_key_exists('code', $draft)) {
            $current['code'] = (string) $draft['code'];
        }
        if (array_key_exists('customInput', $draft)) {
            $current['customInput'] = (string) $draft['customInput'];
        }
        if (array_key_exists('codes', $draft) && is_array($draft['codes'])) {
            $current['codes'] = $draft['codes'];
        }
        if (array_key_exists('lastRuns', $draft) && is_array($draft['lastRuns'])) {
            $current['lastRuns'] = $draft['lastRuns'];
        }
        $current['lastSavedAt'] = DocumentHelper::now();
        $answers[$questionId] = $current;

        return $this->update($id, [
            'answers' => $answers,
            'lastSavedAt' => DocumentHelper::now(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function findLatestForUserTest(string $userId, string $testId): ?array
    {
        if (!Security::isValidId($userId) || !Security::isValidId($testId)) {
            return null;
        }
        return $this->findOne(['userId' => $userId, 'testId' => $testId], ['sort' => ['updatedAt' => -1]]);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(string $id, array $result, string $status = 'SUBMITTED'): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }
        $status = self::normalizeStatus($status);
        if (!in_array($status, ['SUBMITTED', 'EXPIRED'], true)) {
            $status = 'SUBMITTED';
        }
        $result['resultStatus'] = $status;
        $result['status'] = $status;
        $result['submittedAt'] = DocumentHelper::now();
        $result['completedAt'] = DocumentHelper::now();
        $result['lastSavedAt'] = DocumentHelper::now();
        if ($status === 'EXPIRED') {
            $result['expiredAt'] = $result['completedAt'];
        }
        return $this->update($id, $result);
    }
}
