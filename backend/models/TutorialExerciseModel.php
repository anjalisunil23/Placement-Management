<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialExerciseModel extends BaseModel
{
    private static bool $tableReady = false;

    /** @var array<string, string> */
    private const LANGUAGE_ALIASES = [
        'c' => 'c',
        'cpp' => 'cpp',
        'c++' => 'cpp',
        'java' => 'java',
        'python' => 'python',
        'py' => 'python',
        'javascript' => 'javascript',
        'js' => 'javascript',
        'php' => 'php',
    ];

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_EXERCISES;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_exercises` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              module_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.moduleId\'))) STORED,
              sort_order INT
                GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.sortOrder\')) AS SIGNED)) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_exercises_module_order (module_id, sort_order)
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
    public function updateExercise(string $id, array $data): ?array
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
    public function listByModule(string $moduleId): array
    {
        if (!Security::isValidId($moduleId)) {
            return [];
        }

        return $this->findAll(['moduleId' => $moduleId], 500, 0, ['sortOrder' => 1]);
    }

    public function countForTutorial(string $tutorialId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `tutorial_exercises` AS exercises
             INNER JOIN `tutorial_modules` AS modules ON modules.`id` = exercises.`module_id`
             WHERE modules.`tutorial_id` = ?'
        );
        $stmt->execute([$tutorialId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $moduleId = trim((string) ($data['moduleId'] ?? ''));
        if (!Security::isValidId($moduleId) || (new TutorialModuleModel())->findById($moduleId) === null) {
            throw new \InvalidArgumentException('Exercise moduleId is required.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Exercise title is required.');
        }
        $language = self::normalizeLanguage((string) ($data['language'] ?? ''));
        if ($language === '') {
            throw new \InvalidArgumentException('Exercise language is required.');
        }

        $lessonBlockId = trim((string) ($data['lessonBlockId'] ?? ''));
        if ($lessonBlockId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonBlockId) !== 1) {
            throw new \InvalidArgumentException('Exercise lessonBlockId is invalid.');
        }

        return [
            'moduleId' => $moduleId,
            'title' => $title,
            'instructions' => (string) ($data['instructions'] ?? ''),
            'language' => $language,
            'boilerplate' => (string) ($data['boilerplate'] ?? ''),
            'timeLimitMs' => self::positiveInt($data['timeLimitMs'] ?? 5000, 'timeLimitMs'),
            'memoryLimitKb' => self::positiveInt($data['memoryLimitKb'] ?? 128000, 'memoryLimitKb'),
            'sortOrder' => TutorialModuleModel::sortOrder($data['sortOrder'] ?? 1),
            'lessonBlockId' => $lessonBlockId,
        ];
    }

    public static function normalizeLanguage(string $language): string
    {
        $key = strtolower(trim($language));
        if ($key === '') {
            return '';
        }
        if (isset(self::LANGUAGE_ALIASES[$key])) {
            return self::LANGUAGE_ALIASES[$key];
        }
        if (preg_match('/^[a-z][a-z0-9]{0,31}$/', $key) === 1) {
            return $key;
        }

        return '';
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value != $value || (int) $value < 1) {
            throw new \InvalidArgumentException($field . ' must be a positive whole number.');
        }

        return (int) $value;
    }
}
