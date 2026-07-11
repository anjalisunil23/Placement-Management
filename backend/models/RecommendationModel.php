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
        $sourceRole = trim((string) ($data['sourceRole'] ?? 'staff'));
        if (!in_array($sourceRole, ['staff', 'placement_officer'], true)) {
            $sourceRole = 'staff';
        }
        return $this->insert([
            'staffId'        => Security::toObjectId($staffId),
            'sourceRole'     => $sourceRole,
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
     * @param array<string, mixed> $data
     */
    public function updateRecommendation(string $id, array $data): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return false;
        }

        $patch = [];
        if (array_key_exists('companyName', $data)) {
            $patch['companyName'] = trim((string) $data['companyName']);
        }
        if (array_key_exists('companyWebsite', $data)) {
            $patch['companyWebsite'] = trim((string) $data['companyWebsite']);
        }
        if (array_key_exists('category', $data)) {
            $patch['category'] = trim((string) ($data['category'] ?: 'General'));
        }
        if (array_key_exists('reason', $data)) {
            $patch['reason'] = trim((string) $data['reason']);
        }
        if (array_key_exists('adminComments', $data)) {
            $patch['adminComments'] = trim((string) $data['adminComments']);
        }
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (!in_array($status, ['pending', 'contacted', 'registered', 'rejected'], true)) {
                return false;
            }
            $patch['status'] = $status;
        }

        $contact = is_array($existing['contact'] ?? null) ? $existing['contact'] : [];
        if (array_key_exists('hrName', $data)) {
            $contact['name'] = trim((string) $data['hrName']);
        }
        if (array_key_exists('hrEmail', $data)) {
            $contact['email'] = trim((string) $data['hrEmail']);
        }
        if (array_key_exists('contactNumber', $data)) {
            $contact['phone'] = trim((string) $data['contactNumber']);
        }
        if (array_key_exists('contactRole', $data)) {
            $contact['role'] = trim((string) $data['contactRole']);
        }
        if ($contact !== ($existing['contact'] ?? [])) {
            $patch['contact'] = $contact;
        }

        if ($patch === []) {
            return true;
        }

        return $this->update($id, $patch);
    }

    public function deleteRecommendation(string $id): bool
    {
        return $this->delete($id);
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
        $out['contactRole'] = (string) ($contact['role'] ?? '');
        $out['submittedAt'] = (string) ($out['createdAt'] ?? '');
        $out['status'] = (string) ($rec['status'] ?? 'pending');
        $out['reason'] = (string) ($rec['reason'] ?? '');
        $out['adminComments'] = (string) ($rec['adminComments'] ?? '');
        $out['sourceRole'] = (string) ($rec['sourceRole'] ?? 'staff');
        if ($staffUser !== null) {
            $name = (string) ($staffUser['name'] ?? '');
            if ($out['sourceRole'] === 'placement_officer' && $name !== '') {
                $out['staffName'] = $name . ' (PO)';
            } else {
                $out['staffName'] = $name;
            }
            $out['staffEmail'] = (string) ($staffUser['email'] ?? '');
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStaffId(string $staffUserId, int $limit = 100): array
    {
        $oid = Security::toObjectId($staffUserId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['staffId' => $oid], $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnrichedForStaff(string $staffUserId, int $limit = 100): array
    {
        $userModel = new UserModel();
        $staff = $userModel->findById($staffUserId);
        $rows = [];
        foreach ($this->findByStaffId($staffUserId, $limit) as $rec) {
            $contact = $rec['contact'] ?? [];
            $row = DocumentHelper::serialize($rec) ?? [];
            $row['staffName'] = $staff['name'] ?? '';
            $row['staffEmail'] = $staff['email'] ?? '';
            $row['hrName'] = $contact['name'] ?? '';
            $row['hrEmail'] = $contact['email'] ?? '';
            $row['contactNumber'] = $contact['phone'] ?? '';
            $row['contactRole'] = $contact['role'] ?? '';
            $row['submittedAt'] = $row['createdAt'] ?? null;
            $rows[] = $row;
        }
        return $rows;
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
