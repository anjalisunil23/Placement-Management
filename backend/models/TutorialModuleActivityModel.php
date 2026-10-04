<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Practical activities attached to a Tutorial module (independent of MCQ and Coding).
 */
class TutorialModuleActivityModel extends BaseModel
{
    public const TYPES = [
        'programming_task',
        'sql_query',
        'numerical',
        'short_answer',
        'case_study',
        'analytical_design',
    ];

    public const EVALUATION_MODES = [
        'none',
        'self_check',
        'tutor_review',
        'auto_compare',
    ];

    public const ACADEMIC_FIELDS = [
        'engineering',
        'computer_applications',
        'business_administration',
        'other',
    ];

    public const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ACTIVITIES;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_activities` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              tutorial_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.tutorialId\'))) STORED,
              module_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.moduleId\'))) STORED,
              status VARCHAR(16)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.status\'))) STORED,
              sort_order INT
                GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.sortOrder\')) AS SIGNED)) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_module_activities_module (module_id, sort_order),
              KEY idx_tutorial_module_activities_tutorial (tutorial_id, status)
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
    public function updateActivity(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data, [
            'tutorialId' => (string) ($existing['tutorialId'] ?? ''),
            'moduleId' => (string) ($existing['moduleId'] ?? ''),
            'createdBy' => (string) ($existing['createdBy'] ?? ''),
        ]));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByModule(string $moduleId, bool $includeArchived = false): array
    {
        if (!Security::isValidId($moduleId)) {
            return [];
        }
        $rows = $this->findAll(['moduleId' => $moduleId], 200, 0, ['sortOrder' => 1]);
        if (!$includeArchived) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['archived'] ?? false) !== true
            ));
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['sortOrder'] ?? 0)) <=> ((int) ($b['sortOrder'] ?? 0)));

        return $rows;
    }

    public function archive(string $id): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $existing;
        unset($payload['_id'], $payload['createdAt'], $payload['updatedAt']);
        $payload['archived'] = true;
        $payload['status'] = 'draft';
        $this->update($id, $payload);

        return $this->findById($id);
    }

    public function nextSortOrder(string $moduleId): int
    {
        $max = 0;
        foreach ($this->listByModule($moduleId, true) as $row) {
            $max = max($max, (int) ($row['sortOrder'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        $tutorialId = trim((string) ($data['tutorialId'] ?? ''));
        $moduleId = trim((string) ($data['moduleId'] ?? ''));
        if (!Security::isValidId($tutorialId) || !Security::isValidId($moduleId)) {
            throw new \InvalidArgumentException('Activity tutorialId and moduleId are required.');
        }
        $title = trim(strip_tags((string) ($data['title'] ?? '')));
        if ($title === '') {
            throw new \InvalidArgumentException('Activity title is required.');
        }
        $instructions = trim((string) ($data['instructions'] ?? ''));
        $instructions = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $instructions) ?? $instructions;
        $instructions = preg_replace('/<\/?(script|iframe|object|embed)[^>]*>/iu', '', $instructions) ?? $instructions;
        if (trim(strip_tags($instructions)) === '') {
            throw new \InvalidArgumentException('Activity instructions are required.');
        }
        if (mb_strlen($instructions) > 20000) {
            throw new \InvalidArgumentException('Activity instructions are too long.');
        }

        $type = strtolower(trim((string) ($data['activityType'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported activity type.');
        }
        $field = strtolower(trim((string) ($data['academicField'] ?? 'other')));
        if (!in_array($field, self::ACADEMIC_FIELDS, true)) {
            throw new \InvalidArgumentException('Academic field must be engineering, computer_applications, business_administration, or other.');
        }
        $difficulty = strtolower(trim((string) ($data['difficulty'] ?? 'beginner')));
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new \InvalidArgumentException('Difficulty must be beginner, intermediate, or advanced.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new \InvalidArgumentException('Activity status must be draft or published.');
        }
        $mode = strtolower(trim((string) ($data['evaluationMode'] ?? 'tutor_review')));
        if (!in_array($mode, self::EVALUATION_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported evaluation mode.');
        }
        $this->assertEvaluationModeAllowed($type, $mode);

        $sortOrder = (int) ($data['sortOrder'] ?? 1);
        if ($sortOrder < 1) {
            $sortOrder = 1;
        }
        $createdBy = trim((string) ($data['createdBy'] ?? ''));
        if ($createdBy !== '' && !Security::isValidId($createdBy)) {
            throw new \InvalidArgumentException('Invalid activity createdBy.');
        }

        $config = $this->normalizeConfig($type, is_array($data['config'] ?? null) ? $data['config'] : []);
        $answerKey = $this->normalizeAnswerKey($type, $mode, is_array($data['answerKey'] ?? null) ? $data['answerKey'] : []);

        $lessonBlockId = trim((string) ($data['lessonBlockId'] ?? ''));
        if ($lessonBlockId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonBlockId) !== 1) {
            throw new \InvalidArgumentException('Activity lessonBlockId is invalid.');
        }

        return [
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'title' => mb_substr($title, 0, 160),
            'instructions' => $instructions,
            'activityType' => $type,
            'academicField' => $field,
            'difficulty' => $difficulty,
            'sortOrder' => $sortOrder,
            'status' => $status,
            'evaluationMode' => $mode,
            'config' => $config,
            'answerKey' => $answerKey,
            'archived' => ($data['archived'] ?? false) === true,
            'createdBy' => $createdBy,
            'lessonBlockId' => $lessonBlockId,
        ];
    }

    private function assertEvaluationModeAllowed(string $type, string $mode): void
    {
        $allowed = match ($type) {
            'programming_task', 'sql_query', 'case_study', 'analytical_design' => ['none', 'tutor_review'],
            'numerical' => ['none', 'tutor_review', 'auto_compare'],
            'short_answer' => ['none', 'tutor_review', 'self_check'],
            default => ['tutor_review'],
        };
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException('Evaluation mode is not allowed for this activity type.');
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(string $type, array $config): array
    {
        $json = json_encode($config);
        if (is_string($json) && strlen($json) > 32000) {
            throw new \InvalidArgumentException('Activity configuration is too large.');
        }

        return match ($type) {
            'programming_task' => [
                'language' => mb_substr(trim(strip_tags((string) ($config['language'] ?? 'text'))), 0, 40) ?: 'text',
                'boilerplate' => mb_substr((string) ($config['boilerplate'] ?? ''), 0, 20000),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'sql_query' => [
                'schemaDescription' => mb_substr(trim(strip_tags((string) ($config['schemaDescription'] ?? ''))), 0, 8000),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'numerical' => [
                'unit' => mb_substr(trim(strip_tags((string) ($config['unit'] ?? ''))), 0, 40),
                'tolerance' => max(0.0, (float) ($config['tolerance'] ?? 0)),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'short_answer' => [
                'maxLength' => min(5000, max(50, (int) ($config['maxLength'] ?? 1000))),
                'selfCheckRubric' => mb_substr(trim(strip_tags((string) ($config['selfCheckRubric'] ?? ''))), 0, 4000),
            ],
            'case_study' => [
                'parts' => $this->normalizeParts(is_array($config['parts'] ?? null) ? $config['parts'] : []),
            ],
            'analytical_design' => [
                'maxLength' => min(20000, max(100, (int) ($config['maxLength'] ?? 5000))),
                'deliverableHint' => mb_substr(trim(strip_tags((string) ($config['deliverableHint'] ?? ''))), 0, 2000),
            ],
            default => [],
        };
    }

    /**
     * @param list<mixed> $parts
     * @return list<array<string, string>>
     */
    private function normalizeParts(array $parts): array
    {
        $out = [];
        foreach (array_slice($parts, 0, 12) as $index => $part) {
            if (!is_array($part)) {
                continue;
            }
            $prompt = trim(strip_tags((string) ($part['prompt'] ?? '')));
            if ($prompt === '') {
                continue;
            }
            $id = trim(strip_tags((string) ($part['id'] ?? ('part-' . ($index + 1)))));
            if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) {
                $id = 'part-' . ($index + 1);
            }
            $out[] = [
                'id' => mb_substr($id, 0, 40),
                'prompt' => mb_substr($prompt, 0, 2000),
            ];
        }
        if ($out === []) {
            throw new \InvalidArgumentException('Case study activities need at least one part prompt.');
        }
        $seen = [];
        foreach ($out as $i => $part) {
            $id = $part['id'];
            if (isset($seen[$id])) {
                $id = 'part-' . ($i + 1);
                $out[$i]['id'] = $id;
            }
            $seen[$id] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $key
     * @return array<string, mixed>
     */
    private function normalizeAnswerKey(string $type, string $mode, array $key): array
    {
        if ($type === 'numerical') {
            if ($mode === 'auto_compare' && !array_key_exists('expectedValue', $key)) {
                throw new \InvalidArgumentException('Numerical auto_compare activities require answerKey.expectedValue.');
            }
            $out = [];
            if (array_key_exists('expectedValue', $key)) {
                if (!is_numeric($key['expectedValue'])) {
                    throw new \InvalidArgumentException('Numerical expectedValue must be numeric.');
                }
                $out['expectedValue'] = (float) $key['expectedValue'];
            }
            if (isset($key['modelAnswer'])) {
                $out['modelAnswer'] = mb_substr(trim(strip_tags((string) $key['modelAnswer'])), 0, 4000);
            }

            return $out;
        }
        if ($type === 'short_answer') {
            $out = [];
            if (isset($key['modelAnswer'])) {
                $out['modelAnswer'] = mb_substr(trim(strip_tags((string) $key['modelAnswer'])), 0, 4000);
            }
            $keywords = [];
            foreach (array_values((array) ($key['keywords'] ?? [])) as $word) {
                $text = trim(strip_tags((string) $word));
                if ($text !== '') {
                    $keywords[] = mb_substr($text, 0, 80);
                }
            }
            if ($keywords !== []) {
                $out['keywords'] = array_slice($keywords, 0, 20);
            }
            if ($mode === 'self_check' && ($out['modelAnswer'] ?? '') === '' && $keywords === []) {
                throw new \InvalidArgumentException('Self-check short answers need a modelAnswer or keywords.');
            }

            return $out;
        }
        if (isset($key['modelAnswer'])) {
            return [
                'modelAnswer' => mb_substr(trim(strip_tags((string) $key['modelAnswer'])), 0, 8000),
            ];
        }

        return [];
    }
}
