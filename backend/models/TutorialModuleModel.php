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

        // Order by the numeric generated column — JSON string sort yields 1,10,11,2…
        $stmt = $this->db->prepare(
            'SELECT id, payload, created_at, updated_at FROM `tutorial_modules`
             WHERE `tutorial_id` = ?
             ORDER BY `sort_order` ASC, `id` ASC
             LIMIT 500'
        );
        $stmt->execute([$tutorialId]);
        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->rowToDoc($row);
        }

        return $results;
    }

    public function countForTutorial(string $tutorialId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `tutorial_modules` WHERE `tutorial_id` = ?');
        $stmt->execute([$tutorialId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Current module ids for catalogue progress. Old completion rows stay in history,
     * but only modules that still exist count.
     *
     * @param list<string> $tutorialIds
     * @return array<string, array<string, true>>
     */
    public function currentModuleIds(array $tutorialIds): array
    {
        $ids = [];
        foreach ($tutorialIds as $tutorialId) {
            $tutorialId = trim((string) $tutorialId);
            if (Security::isValidId($tutorialId)) {
                $ids[$tutorialId] = $tutorialId;
            }
        }
        $grouped = [];
        foreach ($ids as $tutorialId) {
            $grouped[$tutorialId] = [];
        }
        if ($ids === []) {
            return $grouped;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT `id`, `tutorial_id` FROM `tutorial_modules` WHERE `tutorial_id` IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($ids));
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tutorialId = (string) ($row['tutorial_id'] ?? '');
            $moduleId = (string) ($row['id'] ?? '');
            if ($tutorialId !== '' && $moduleId !== '' && isset($grouped[$tutorialId])) {
                $grouped[$tutorialId][$moduleId] = true;
            }
        }

        return $grouped;
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

        $subtitle = trim(strip_tags((string) ($data['subtitle'] ?? '')));
        if (strlen($subtitle) > 240) {
            $subtitle = substr($subtitle, 0, 240);
        }

        return [
            'tutorialId' => $tutorialId,
            'title' => $title,
            'subtitle' => $subtitle,
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
