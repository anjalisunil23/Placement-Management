<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialModuleAssessmentModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ASSESSMENTS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_assessments` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              tutorial_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.tutorialId\'))) STORED,
              module_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.moduleId\'))) STORED,
              status VARCHAR(16)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.status\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_module_assessment (module_id),
              KEY idx_tutorial_module_assessments_tutorial (tutorial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $payload = $this->validate($data);
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateAssessment(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data, [
            'tutorialId' => (string) ($existing['tutorialId'] ?? ''),
            'moduleId' => (string) ($existing['moduleId'] ?? ''),
        ]));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    public function findByModule(string $moduleId): ?array
    {
        if (!Security::isValidId($moduleId)) {
            return null;
        }

        return $this->findOne(['moduleId' => $moduleId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $tutorialId = trim((string) ($data['tutorialId'] ?? ''));
        $moduleId = trim((string) ($data['moduleId'] ?? ''));
        if (!Security::isValidId($tutorialId) || !Security::isValidId($moduleId)) {
            throw new \InvalidArgumentException('Assessment tutorialId and moduleId are required.');
        }
        $title = trim(strip_tags((string) ($data['title'] ?? 'Module quiz')));
        if ($title === '') {
            $title = 'Module quiz';
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new \InvalidArgumentException('Assessment status must be draft or published.');
        }
        $passPercent = (int) ($data['passPercent'] ?? 60);
        if ($passPercent < 0 || $passPercent > 100) {
            throw new \InvalidArgumentException('Pass percentage must be between 0 and 100.');
        }
        $maxAttempts = (int) ($data['maxAttempts'] ?? 3);
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new \InvalidArgumentException('Maximum attempts must be between 1 and 20.');
        }

        return [
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'title' => mb_substr($title, 0, 160),
            'status' => $status,
            'passPercent' => $passPercent,
            'maxAttempts' => $maxAttempts,
            'showExplanations' => ($data['showExplanations'] ?? true) === true || ($data['showExplanations'] ?? true) === 1 || ($data['showExplanations'] ?? true) === '1',
            'allowReview' => ($data['allowReview'] ?? true) === true || ($data['allowReview'] ?? true) === 1 || ($data['allowReview'] ?? true) === '1',
        ];
    }
}
