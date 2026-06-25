<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class AlumniReferralModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::ALUMNI_REFERRALS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createReferral(string $alumniUserId, array $data): string
    {
        $contact = is_array($data['contact'] ?? null) ? $data['contact'] : [];
        $hrName = trim((string) ($data['hrName'] ?? $contact['name'] ?? ''));
        $hrEmail = trim((string) ($data['hrEmail'] ?? $contact['email'] ?? ''));
        $hrPhone = trim((string) ($data['contactNumber'] ?? $contact['phone'] ?? ''));

        return $this->insert([
            'alumniUserId'   => Security::toObjectId($alumniUserId),
            'companyName'    => trim((string) ($data['companyName'] ?? '')),
            'companyWebsite' => trim((string) ($data['companyWebsite'] ?? $data['link'] ?? '')),
            'contact'        => [
                'name'  => $hrName,
                'email' => $hrEmail,
                'phone' => $hrPhone,
            ],
            'status'         => 'pending',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAlumni(string $alumniUserId, int $limit = 100): array
    {
        $oid = Security::toObjectId($alumniUserId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['alumniUserId' => $oid], $limit);
    }

    public function countByAlumni(string $alumniUserId): int
    {
        $oid = Security::toObjectId($alumniUserId);
        if ($oid === null) {
            return 0;
        }
        return $this->count(['alumniUserId' => $oid]);
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
    public function updateReferral(string $id, array $data): bool
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
        if ($contact !== ($existing['contact'] ?? [])) {
            $patch['contact'] = $contact;
        }

        if ($patch === []) {
            return true;
        }

        return $this->update($id, $patch);
    }

    public function deleteReferral(string $id): bool
    {
        return $this->delete($id);
    }

    /**
     * @param array<string, mixed> $rec
     * @param array<string, mixed>|null $alumniUser
     * @return array<string, mixed>
     */
    public static function serializeForAlumni(array $rec, ?array $alumniUser = null): array
    {
        $out = DocumentHelper::serialize($rec) ?? [];
        $contact = is_array($rec['contact'] ?? null) ? $rec['contact'] : [];
        $out['companyName'] = (string) ($rec['companyName'] ?? $rec['jobTitle'] ?? '');
        $out['companyWebsite'] = (string) ($rec['companyWebsite'] ?? $rec['link'] ?? '');
        $out['hrName'] = (string) ($contact['name'] ?? '');
        $out['hrEmail'] = (string) ($contact['email'] ?? '');
        $out['contactNumber'] = (string) ($contact['phone'] ?? '');
        $out['submittedAt'] = (string) ($out['createdAt'] ?? '');
        $out['status'] = (string) ($rec['status'] ?? 'pending');
        $out['adminComments'] = (string) ($rec['adminComments'] ?? '');
        if ($alumniUser !== null) {
            $out['alumniName'] = (string) ($alumniUser['name'] ?? '');
            $out['alumniEmail'] = (string) ($alumniUser['email'] ?? '');
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(int $limit = 200): array
    {
        $userModel = new UserModel();
        $rows = [];
        foreach ($this->findAll([], $limit) as $ref) {
            $user = $userModel->findById((string) ($ref['alumniUserId'] ?? ''));
            $rows[] = self::serializeForAlumni($ref, $user);
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnrichedForAlumni(string $alumniUserId, int $limit = 100): array
    {
        $userModel = new UserModel();
        $user = $userModel->findById($alumniUserId);
        $rows = [];
        foreach ($this->findByAlumni($alumniUserId, $limit) as $ref) {
            $rows[] = self::serializeForAlumni($ref, $user);
        }
        return $rows;
    }
}
