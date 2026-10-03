<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Tutor reviews for practical activity submissions (foundation; workflows in later phases).
 */
class TutorialModuleActivityReviewModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ACTIVITY_REVIEWS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_activity_reviews` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              submission_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.submissionId\'))) STORED,
              activity_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.activityId\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_module_activity_review_submission (submission_id),
              KEY idx_tutorial_module_activity_reviews_activity (activity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createReview(array $data): array
    {
        $payload = $this->validate($data);
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateReview(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data, [
            'submissionId' => (string) ($existing['submissionId'] ?? ''),
            'activityId' => (string) ($existing['activityId'] ?? ''),
        ]));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    public function findBySubmission(string $submissionId): ?array
    {
        if (!Security::isValidId($submissionId)) {
            return null;
        }

        return $this->findOne(['submissionId' => $submissionId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $submissionId = trim((string) ($data['submissionId'] ?? ''));
        $activityId = trim((string) ($data['activityId'] ?? ''));
        $reviewerUserId = trim((string) ($data['reviewerUserId'] ?? ''));
        if (!Security::isValidId($submissionId) || !Security::isValidId($activityId) || !Security::isValidId($reviewerUserId)) {
            throw new \InvalidArgumentException('Review submissionId, activityId and reviewerUserId are required.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'reviewed'], true)) {
            throw new \InvalidArgumentException('Review status must be pending or reviewed.');
        }
        $score = array_key_exists('score', $data) && $data['score'] !== null ? (float) $data['score'] : null;
        $maxScore = array_key_exists('maxScore', $data) && $data['maxScore'] !== null ? (float) $data['maxScore'] : null;
        if ($score !== null && ($score < 0 || $score > 1000)) {
            throw new \InvalidArgumentException('Review score is out of range.');
        }
        if ($maxScore !== null && ($maxScore < 0 || $maxScore > 1000)) {
            throw new \InvalidArgumentException('Review maxScore is out of range.');
        }
        $passed = null;
        if (array_key_exists('passed', $data) && $data['passed'] !== null) {
            $passed = ($data['passed'] === true || $data['passed'] === 1 || $data['passed'] === '1');
        }

        return [
            'submissionId' => $submissionId,
            'activityId' => $activityId,
            'reviewerUserId' => $reviewerUserId,
            'score' => $score,
            'maxScore' => $maxScore,
            'passed' => $passed,
            'feedback' => mb_substr(trim(strip_tags((string) ($data['feedback'] ?? ''))), 0, 8000),
            'privateNotes' => mb_substr(trim(strip_tags((string) ($data['privateNotes'] ?? ''))), 0, 8000),
            'status' => $status,
        ];
    }
}
