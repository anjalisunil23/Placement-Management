<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class ApplicationModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::APPLICATIONS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStudent(string $studentId): array
    {
        $oid = Security::toObjectId($studentId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['studentId' => $oid]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByCompany(string $companyId): array
    {
        $oid = Security::toObjectId($companyId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['companyId' => $oid]);
    }

    public function findByStudentAndDrive(string $studentId, string $driveId): ?array
    {
        $sId = Security::toObjectId($studentId);
        $dId = Security::toObjectId($driveId);
        if ($sId === null || $dId === null) {
            return null;
        }
        return $this->findOne(['studentId' => $sId, 'driveId' => $dId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createApplication(array $data): string
    {
        $now = DocumentHelper::now();
        $doc = [
            'studentId' => Security::toObjectId($data['studentId']),
            'driveId'   => Security::toObjectId($data['driveId']),
            'companyId' => Security::toObjectId($data['companyId']),
            'jobId'     => isset($data['jobId']) ? Security::toObjectId($data['jobId']) : null,
            'status'    => $data['status'] ?? 'applied',
            'remarks'   => '',
            'timeline'  => [
                ['status' => 'applied', 'at' => $now, 'by' => $data['studentId']],
            ],
        ];
        if (!empty($data['resume']) && is_array($data['resume'])) {
            $doc['resume'] = $data['resume'];
        }
        return $this->insert($doc);
    }

    public function updateStatus(string $id, string $status, string $by, string $remarks = ''): bool
    {
        $app = $this->findById($id);
        if (!$app) {
            return false;
        }
        $timeline = $app['timeline'] ?? [];
        $timeline[] = [
            'status' => $status,
            'at'     => DocumentHelper::now(),
            'by'     => $by,
        ];
        return $this->update($id, [
            'status'  => $status,
            'remarks' => $remarks,
            'timeline'=> $timeline,
        ]);
    }
}
