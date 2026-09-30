<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialModuleModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULES;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_modules` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              tutorial_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.tutorialId\'))) STORED,
              sort_order INT
                GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.sortOrder\')) AS SIGNED)) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_modules_tutorial_order (tutorial_id, sort_order)
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
    public function updateModule(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByTutorial(string $tutorialId): array
    {
        if (!Security::isValidId($tutorialId)) {
            return [];
        }

        return $this->findAll(['tutorialId' => $tutorialId], 500, 0, ['sortOrder' => 1]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $tutorialId = trim((string) ($data['tutorialId'] ?? ''));
        if (!Security::isValidId($tutorialId) || (new TutorialModel())->findById($tutorialId) === null) {
            throw new \InvalidArgumentException('Module tutorialId is required.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Module title is required.');
        }

        return [
            'tutorialId' => $tutorialId,
            'title' => $title,
            'sortOrder' => self::sortOrder($data['sortOrder'] ?? 1),
            'content' => (string) ($data['content'] ?? ''),
        ];
    }

    public static function sortOrder(mixed $value): int
    {
        $order = (int) $value;
        if ($order < 1) {
            throw new \InvalidArgumentException('sortOrder must be 1 or greater.');
        }

        return $order;
    }
}
