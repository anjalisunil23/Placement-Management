<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Aptitude mock attempts and scored results.
 */
class AptitudeAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::APTITUDE_ATTEMPTS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    /** Create table if production DB was set up before aptitude attempts existed. */
    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `aptitude_attempts` (
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
    public function startAttempt(array $data): string
    {
        return $this->insert([
            'testId' => Security::toObjectId((string) ($data['testId'] ?? '')),
            'userId' => Security::toObjectId((string) ($data['userId'] ?? '')),
            'subjectType' => (string) ($data['subjectType'] ?? 'student'),
            'studentId' => Security::toObjectId((string) ($data['studentId'] ?? '')) ?: null,
            'alumniId' => Security::toObjectId((string) ($data['alumniId'] ?? '')) ?: null,
            'departmentId' => Security::toObjectId((string) ($data['departmentId'] ?? '')) ?: null,
            'classBatch' => trim((string) ($data['classBatch'] ?? '')),
            'course' => trim((string) ($data['course'] ?? '')),
            'semester' => trim((string) ($data['semester'] ?? '')),
            'batch' => trim((string) ($data['batch'] ?? '')),
            'status' => 'in_progress',
            'startedAt' => DocumentHelper::now(),
            'completedAt' => null,
            'answers' => [],
            'score' => null,
            'percentage' => null,
            'accuracy' => null,
            'correctCount' => null,
            'totalQuestions' => (int) ($data['totalQuestions'] ?? 0),
            'categoryScores' => [],
            'questionAnalysis' => [],
            'markedForReview' => [],
            'timeTakenSeconds' => null,
            'rank' => null,
            'percentile' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function completeAttempt(string $id, array $result): bool
    {
        return $this->update($id, [
            'status' => 'completed',
            'completedAt' => DocumentHelper::now(),
            'submittedAt' => DocumentHelper::now(),
            'answers' => $result['answers'] ?? [],
            'markedForReview' => $result['markedForReview'] ?? [],
            'score' => (float) ($result['score'] ?? 0),
            'marksObtained' => (float) ($result['marksObtained'] ?? $result['score'] ?? 0),
            'totalMarks' => (float) ($result['totalMarks'] ?? 0),
            'percentage' => (float) ($result['percentage'] ?? 0),
            'accuracy' => (float) ($result['accuracy'] ?? 0),
            'correctCount' => (int) ($result['correctCount'] ?? 0),
            'wrongCount' => (int) ($result['wrongCount'] ?? 0),
            'incorrect_count' => (int) ($result['wrongCount'] ?? $result['incorrect_count'] ?? 0),
            'unansweredCount' => (int) ($result['unansweredCount'] ?? 0),
            'totalQuestions' => (int) ($result['totalQuestions'] ?? 0),
            'categoryScores' => $result['categoryScores'] ?? [],
            'questionAnalysis' => $result['questionAnalysis'] ?? [],
            'timeTakenSeconds' => isset($result['timeTakenSeconds']) ? (int) $result['timeTakenSeconds'] : null,
            'time_taken' => isset($result['timeTakenSeconds']) ? (int) $result['timeTakenSeconds'] : null,
            'rank' => $result['rank'] ?? null,
            'percentile' => $result['percentile'] ?? null,
            'autoSubmitted' => !empty($result['autoSubmitted']),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(string $userId, int $limit = 100): array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['userId' => $oid], $limit, 0, ['createdAt' => -1]);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function completed(array $filter = [], int $limit = 500): array
    {
        return $this->findAll(array_merge(['status' => 'completed'], $filter), $limit, 0, ['completedAt' => -1]);
    }
}
