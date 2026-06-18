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
     * @return array<int, array<string, mixed>>
     */
    public function findByStaffUserId(string $userId, int $limit = 100): array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['staffId' => $oid], $limit);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRecommendation(string $staffId, array $data): string
    {
        return $this->insert([
            'staffId'        => Security::toObjectId($staffId),
            'companyName'    => trim((string) ($data['companyName'] ?? '')),
            'companyWebsite' => trim((string) ($data['companyWebsite'] ?? '')),
            'category'       => trim((string) ($data['category'] ?? 'General')),
            'reason'         => trim((string) ($data['reason'] ?? 'Faculty referral')),
            'contact'        => $data['contact'] ?? [],
            'status'         => 'pending',
        ]);
    }

    public function updateStatus(string $id, string $status): bool
    {
        if (!in_array($status, ['pending', 'contacted', 'registered', 'rejected'], true)) {
            return false;
        }
        return $this->update($id, ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $rec
     * @param array<string, mixed>|null $staffUser
     * @return array<string, mixed>
     */
    public static function serializeForStaff(array $rec, ?array $staffUser = null): array
    {
        $out = DocumentHelper::serialize($rec);
        $contact = is_array($rec['contact'] ?? null) ? $rec['contact'] : [];
        $out['hrName'] = (string) ($contact['name'] ?? '');
        $out['hrEmail'] = (string) ($contact['email'] ?? '');
        $out['contactNumber'] = (string) ($contact['phone'] ?? '');
        $out['submittedAt'] = (string) ($out['createdAt'] ?? '');
        $out['status'] = (string) ($rec['status'] ?? 'pending');
        if ($staffUser !== null) {
            $out['staffName'] = (string) ($staffUser['name'] ?? '');
            $out['staffEmail'] = (string) ($staffUser['email'] ?? '');
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(int $limit = 200): array
    {
        $userModel = new UserModel();
        $staffModel = new StaffModel();
        $deptModel = new DepartmentModel();
        $rows = [];
        foreach ($this->findAll([], $limit) as $rec) {
            $staff = $userModel->findById((string) ($rec['staffId'] ?? ''));
            $out = self::serializeForStaff($rec, $staff);
            if ($staff) {
                $profile = $staffModel->findByUserId((string) $staff['_id']);
                if ($profile && !empty($profile['departmentId'])) {
                    $dept = $deptModel->findById((string) $profile['departmentId']);
                    $out['staffDepartment'] = $dept['code'] ?? '';
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }
}
