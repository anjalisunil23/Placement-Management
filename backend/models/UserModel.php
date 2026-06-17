<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * User model — core authentication entity.
 */
class UserModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::USERS;
    }

    public function findByEmail(string $email): ?array
    {
        $doc = $this->collection->findOne(['email' => strtolower(trim($email))]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createUser(array $data): string
    {
        $doc = [
            'name'      => $data['name'],
            'email'     => strtolower(trim($data['email'])),
            'password'  => Security::hashPassword($data['password']),
            'role'      => $data['role'],
            'status'    => $data['status'] ?? 'pending',
            'approved'  => $data['approved'] ?? false,
        ];
        return $this->insert($doc);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUser(string $id, array $data): bool
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = Security::hashPassword($data['password']);
        } else {
            unset($data['password']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        return $this->update($id, $data);
    }

    public function blockUser(string $id): bool
    {
        return $this->update($id, ['status' => 'blocked']);
    }

    public function unblockUser(string $id): bool
    {
        return $this->update($id, ['status' => 'active']);
    }

    public function approveUser(string $id): bool
    {
        return $this->update($id, ['approved' => true, 'status' => 'active']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByRole(string $role, int $limit = 100, int $skip = 0): array
    {
        return $this->findAll(['role' => $role], $limit, $skip);
    }

    public function getDashboardStats(): array
    {
        $studentModel = new StudentModel();
        $companyModel = new CompanyModel();

        return [
            'totalStudents'      => $studentModel->count([]),
            'totalCompanies'     => $companyModel->count([]),
            'placedStudents'     => $studentModel->count(['placed' => true]),
            'pendingApprovals'   => $this->count(['approved' => false]),
            'blockedUsers'       => $this->count(['status' => 'blocked']),
        ];
    }

    /**
     * @return array<string, mixed>|null safe user (no password)
     */
    public function safeUser(?array $user): ?array
    {
        return DocumentHelper::serialize($user);
    }
}
