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
     * @return array<int, array<string, mixed>>
     */
    public function findByDrive(string $driveId, int $limit = 5000): array
    {
        $dId = Security::toObjectId($driveId);
        if ($dId === null) {
            return [];
        }
        return $this->findAll(['driveId' => $dId], $limit);
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
        if (!empty($data['certificates']) && is_array($data['certificates'])) {
            $doc['certificates'] = $data['certificates'];
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

    /**
     * Upsert a company selection-round outcome for an application.
     *
     * @return array{ok:bool,roundOutcomes:list<array<string,mixed>>,status:string}
     */
    public function upsertRoundOutcome(string $id, int $order, string $type, string $roundStatus, string $by): array
    {
        $app = $this->findById($id);
        if (!$app) {
            return ['ok' => false, 'roundOutcomes' => [], 'status' => ''];
        }

        $roundStatus = strtolower(trim($roundStatus));
        if (!in_array($roundStatus, ['waiting', 'selected', 'rejected'], true)) {
            return ['ok' => false, 'roundOutcomes' => [], 'status' => (string) ($app['status'] ?? '')];
        }

        $type = strtolower(trim($type));
        $outcomes = is_array($app['roundOutcomes'] ?? null) ? $app['roundOutcomes'] : [];
        $found = false;
        foreach ($outcomes as &$row) {
            if ((int) ($row['order'] ?? 0) === $order) {
                $row['order'] = $order;
                $row['type'] = $type !== '' ? $type : (string) ($row['type'] ?? '');
                $row['status'] = $roundStatus;
                $row['updatedAt'] = DocumentHelper::now();
                $row['updatedBy'] = $by;
                $found = true;
                break;
            }
        }
        unset($row);

        if (!$found) {
            $outcomes[] = [
                'order' => $order,
                'type' => $type,
                'status' => $roundStatus,
                'updatedAt' => DocumentHelper::now(),
                'updatedBy' => $by,
            ];
        }

        usort($outcomes, static fn ($a, $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        $patch = ['roundOutcomes' => array_values($outcomes)];
        $appStatus = (string) ($app['status'] ?? 'shortlisted');
        if ($roundStatus === 'rejected' && in_array($appStatus, ['shortlisted', 'selected'], true)) {
            $patch['status'] = 'rejected';
            $timeline = is_array($app['timeline'] ?? null) ? $app['timeline'] : [];
            $timeline[] = [
                'status' => 'rejected',
                'at' => DocumentHelper::now(),
                'by' => $by,
                'remarks' => 'Rejected at Round ' . $order,
            ];
            $patch['timeline'] = $timeline;
            $appStatus = 'rejected';
        } elseif (in_array($roundStatus, ['waiting', 'selected'], true) && $appStatus === 'rejected') {
            $stillRejected = false;
            foreach ($outcomes as $o) {
                if (strtolower((string) ($o['status'] ?? '')) === 'rejected') {
                    $stillRejected = true;
                    break;
                }
            }
            if (!$stillRejected) {
                $patch['status'] = 'shortlisted';
                $timeline = is_array($app['timeline'] ?? null) ? $app['timeline'] : [];
                $timeline[] = [
                    'status' => 'shortlisted',
                    'at' => DocumentHelper::now(),
                    'by' => $by,
                    'remarks' => 'Restored after Round ' . $order . ' outcome change',
                ];
                $patch['timeline'] = $timeline;
                $appStatus = 'shortlisted';
            }
        } elseif ($roundStatus === 'selected' && $appStatus === 'shortlisted') {
            // Keep shortlisted until final package/joining result is recorded.
            $appStatus = 'shortlisted';
        }

        $ok = $this->update($id, $patch);
        return [
            'ok' => $ok,
            'roundOutcomes' => array_values($outcomes),
            'status' => $appStatus,
        ];
    }
}
