<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialCategoryModel extends BaseModel
{
    private static bool $tableReady = false;

    /** @var list<array{name:string,description:string}> */
    private const DEFAULTS = [
        ['name' => 'Programming Languages', 'description' => 'Languages used to write programs.'],
        ['name' => 'Tools', 'description' => 'Utilities used while building and shipping software.'],
        ['name' => 'Technologies', 'description' => 'Platforms and technical subjects.'],
        ['name' => 'Frameworks', 'description' => 'Frameworks and libraries.'],
        ['name' => 'Databases', 'description' => 'Data storage systems.'],
        ['name' => 'Computer Science', 'description' => 'Concepts and theory.'],
        ['name' => 'DevOps', 'description' => 'Delivery, operations, and infrastructure practice.'],
        ['name' => 'Other', 'description' => 'Topics that do not fit another category.'],
    ];

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_CATEGORIES;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
        $this->seedDefaults();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `tutorial_categories` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              slug VARCHAR(80)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.slug\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_category_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $payload = $this->validate($data, true);
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateCategory(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data), false, $id);
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->findAll([], 200, 0, ['name' => 1]);
    }

    public function findBySlug(string $slug): ?array
    {
        $slug = self::slugify($slug);
        if ($slug === '') {
            return null;
        }

        return $this->findOne(['slug' => $slug]);
    }

    public function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $row) {
            $slug = self::slugify($row['name']);
            if ($this->findBySlug($slug) !== null) {
                continue;
            }
            $this->insert([
                'name' => $row['name'],
                'slug' => $slug,
                'description' => $row['description'],
                'status' => 'active',
                'createdBy' => '',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $isCreate, string $ignoreId = ''): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required.');
        }
        $slug = self::slugify((string) ($data['slug'] ?? $name));
        if ($slug === '') {
            throw new \InvalidArgumentException('Category slug is required.');
        }
        $existing = $this->findBySlug($slug);
        if ($existing !== null && (string) ($existing['_id'] ?? '') !== $ignoreId) {
            throw new \InvalidArgumentException('A category with this name already exists.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('Category status must be active or inactive.');
        }
        $createdBy = trim((string) ($data['createdBy'] ?? ''));
        if ($createdBy !== '' && !Security::isValidId($createdBy)) {
            throw new \InvalidArgumentException('Category createdBy must be a user id.');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')),
            'status' => $status,
            'createdBy' => $createdBy,
        ];
    }

    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
