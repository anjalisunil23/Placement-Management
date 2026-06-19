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
        $oid = Security::toObjectId($alumniUserId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['alumniUserId' => $oid], $limit, 0, ['createdAt' => -1]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createStory(string $alumniUserId, string $alumniName, array $data): string
    {
        return $this->insert([
            'alumniUserId' => Security::toObjectId($alumniUserId),
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
        if (!$story || (string) ($story['alumniUserId'] ?? '') !== $alumniUserId) {
            return false;
        }
        $update = [];
        foreach (['name', 'company', 'role', 'package', 'quote'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = trim((string) $data[$field]);
            }
        }
        if (empty($update)) {
            return false;
        }
        return $this->update($id, $update);
    }

    public function deleteStory(string $id, string $alumniUserId): bool
    {
        $story = $this->findById($id);
        if (!$story || (string) ($story['alumniUserId'] ?? '') !== $alumniUserId) {
            return false;
        }
        return $this->delete($id);
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
