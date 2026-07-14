<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class SuccessStoryModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::SUCCESS_STORIES;
    }

    /**
     * Normalize alumni user id for storage and lookup.
     */
    private function normalizeAlumniUserId(string $alumniUserId): ?string
    {
        $oid = Security::toObjectId($alumniUserId);
        if ($oid !== null) {
            return $oid;
        }
        $raw = strtolower(trim($alumniUserId));
        return Security::isValidId($raw) ? $raw : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function published(int $limit = 12): array
    {
        return $this->findAll(['status' => 'published'], $limit, 0, ['createdAt' => -1]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAlumni(string $alumniUserId, int $limit = 50): array
    {
        $oid = $this->normalizeAlumniUserId($alumniUserId);
        if ($oid === null) {
            return [];
        }
        $raw = trim($alumniUserId);
        $candidates = array_values(array_unique(array_filter([
            $oid,
            $raw,
            strtolower($raw),
        ], static fn ($v) => is_string($v) && $v !== '')));

        return $this->findAll(
            ['alumniUserId' => ['$in' => $candidates]],
            $limit,
            0,
            ['createdAt' => -1]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createStory(string $alumniUserId, string $alumniName, array $data): string
    {
        $oid = $this->normalizeAlumniUserId($alumniUserId);
        if ($oid === null) {
            throw new \InvalidArgumentException('Invalid alumni user id.');
        }

        return $this->insert([
            'alumniUserId' => $oid,
            'alumniName'   => $alumniName,
            'name'         => trim((string) ($data['name'] ?? $alumniName)),
            'company'      => trim((string) ($data['company'] ?? '')),
            'role'         => trim((string) ($data['role'] ?? '')),
            'package'      => trim((string) ($data['package'] ?? '')),
            'quote'        => trim((string) ($data['quote'] ?? '')),
            'status'       => 'published',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStory(string $id, string $alumniUserId, array $data): bool
    {
        $story = $this->findById($id);
        if (!$story || !$this->ownsStory($story, $alumniUserId)) {
            return false;
        }
        $update = [];
        foreach (['name', 'company', 'role', 'package', 'quote'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = trim((string) $data[$field]);
            }
        }
        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (in_array($status, ['published', 'draft', 'hidden'], true)) {
                $update['status'] = $status;
            }
        }
        if (empty($update)) {
            return false;
        }
        if (!$this->update($id, $update)) {
            // MySQL may report 0 changed rows when payload is identical; treat owned record as updated.
            return $this->findById($id) !== null;
        }
        return true;
    }

    public function deleteStory(string $id, string $alumniUserId): bool
    {
        $story = $this->findById($id);
        if (!$story || !$this->ownsStory($story, $alumniUserId)) {
            return false;
        }
        return $this->delete($id);
    }

    /**
     * @param array<string, mixed> $story
     */
    private function ownsStory(array $story, string $alumniUserId): bool
    {
        $ownerId = $this->normalizeAlumniUserId($alumniUserId);
        $storyOwner = $this->normalizeAlumniUserId((string) ($story['alumniUserId'] ?? ''));
        if ($ownerId === null || $storyOwner === null) {
            return strtolower(trim((string) ($story['alumniUserId'] ?? '')))
                === strtolower(trim($alumniUserId));
        }
        return $ownerId === $storyOwner;
    }

    /**
     * @param array<string, mixed> $story
     * @return array{name: string, role: string, package: string, quote: string}
     */
    public static function toPublicCard(array $story): array
    {
        $name = trim((string) ($story['name'] ?? $story['alumniName'] ?? 'Alumni'));
        $company = trim((string) ($story['company'] ?? ''));
        $roleTitle = trim((string) ($story['role'] ?? ''));
        $role = $company !== '' && $roleTitle !== ''
            ? "{$company} · {$roleTitle}"
            : ($company !== '' ? $company : ($roleTitle !== '' ? $roleTitle : 'Alumni'));

        return [
            'name'    => $name,
            'role'    => $role,
            'package' => self::formatPackageLabel((string) ($story['package'] ?? '')),
            'quote'   => trim((string) ($story['quote'] ?? '')),
        ];
    }

    public static function formatPackageLabel(string $package): string
    {
        if (preg_match('/[\d.]+/', $package, $m)) {
            return '₹' . $m[0] . ' LPA';
        }
        return $package !== '' ? $package : '—';
    }
}
