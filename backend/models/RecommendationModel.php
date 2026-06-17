<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Staff company recommendations.
 */
class RecommendationModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RECOMMENDATIONS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRecommendation(string $staffId, array $data): string
    {
        return $this->insert([
            'staffId'         => Security::toObjectId($staffId),
            'companyName'     => $data['companyName'],
            'companyWebsite'  => $data['companyWebsite'] ?? '',
            'category'        => $data['category'] ?? '',
            'reason'          => $data['reason'] ?? '',
            'contact'         => $data['contact'] ?? [],
            'status'          => 'pending',
        ]);
    }

    public function updateStatus(string $id, string $status): bool
    {
        if (!in_array($status, ['pending', 'contacted', 'registered'], true)) {
            return false;
        }
        return $this->update($id, ['status' => $status]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(int $limit = 200): array
    {
        $userModel = new UserModel();
        $rows = [];
        foreach ($this->findAll([], $limit) as $rec) {
            $staff = $userModel->findById((string) ($rec['staffId'] ?? ''));
            $contact = $rec['contact'] ?? [];
            $row = DocumentHelper::serialize($rec) ?? [];
            $row['staffName'] = $staff['name'] ?? '';
            $row['staffEmail'] = $staff['email'] ?? '';
            $row['hrName'] = $contact['name'] ?? '';
            $row['hrEmail'] = $contact['email'] ?? '';
            $row['contactNumber'] = $contact['phone'] ?? '';
            $row['submittedAt'] = $row['createdAt'] ?? null;
            $rows[] = $row;
        }
        return $rows;
    }
}

