<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DriveModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Utils\DocumentHelper;

/**
 * When admin/PO records a drive result, sync it to the student's application and notify them.
 */
final class RecruitmentResultService
{
    /**
     * @param array<string, mixed> $input Saved result payload (registerNumber, company, status, driveId?, package?, joiningDate?)
     */
    public function syncAfterSave(array $input, string $resultId, string $recordedByUserId): void
    {
        $register = strtoupper(trim((string) ($input['registerNumber'] ?? '')));
        $company = trim((string) ($input['company'] ?? ''));
        $status = ($input['status'] ?? 'selected') === 'rejected' ? 'rejected' : 'selected';
        $driveId = trim((string) ($input['driveId'] ?? ''));
        $package = trim((string) ($input['package'] ?? ''));

        if ($register === '') {
            return;
        }

        $student = (new StudentModel())->findByRegisterNumber($register);
        if (!$student) {
            return;
        }

        $studentId = (string) $student['_id'];
        $userId = (string) ($student['userId'] ?? '');
        $app = $this->findMatchingApplication($studentId, $driveId, $company);

        if ($app) {
            $appId = (string) $app['_id'];
            $remarks = 'Recruitment result recorded by the placement cell.';
            if ($package !== '') {
                $remarks .= ' Package: ' . $package;
            }

            (new ApplicationWorkflowService())->forceFinalStatus($appId, $status, $recordedByUserId, $remarks);

            $patch = [
                'resultId'          => $resultId,
                'resultPackage'     => $package,
                'resultJoiningDate' => trim((string) ($input['joiningDate'] ?? '')),
            ];
            (new ApplicationModel())->update($appId, $patch);

            if ($status === 'selected') {
                (new PlacementChanceService())->consumeOnSelection(
                    $studentId,
                    (string) ($app['driveId'] ?? $driveId),
                    [
                        'companyId'     => (string) ($app['companyId'] ?? ''),
                        'driveId'       => (string) ($app['driveId'] ?? $driveId),
                        'applicationId' => $appId,
                        'company'       => $company,
                        'package'       => $package,
                        'resultId'      => $resultId,
                    ]
                );
            }
        }

        if ($userId === '') {
            return;
        }

        $notifier = new NotificationService();
        if ($status === 'selected') {
            $title = 'Congratulations — you are selected!';
            $message = "You have been selected for {$company}";
            if ($package !== '') {
                $message .= " ({$package})";
            }
            $message .= '.';
        } else {
            $title = 'Drive result published';
            $message = "Your result for {$company} has been updated: not selected.";
        }

        $notifier->notifyApplicationUpdate($userId, $title, $message, [
            'driveId'  => $driveId,
            'company'  => $company,
            'status'   => $status,
            'resultId' => $resultId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForStudent(string $registerNumber): array
    {
        $register = strtoupper(trim($registerNumber));
        if ($register === '') {
            return [];
        }

        $results = (new RecruitmentResultModel())->list(['registerNumber' => $register], 100);

        return DocumentHelper::serializeMany($results);
    }

    /**
     * Merge recorded recruitment results into enriched application rows for student/alumni views.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function mergeIntoApplicationRows(array $rows, string $registerNumber): array
    {
        if ($registerNumber === '') {
            return $rows;
        }

        $results = (new RecruitmentResultModel())->list([
            'registerNumber' => strtoupper(trim($registerNumber)),
        ], 100);

        $byDrive = [];
        $byCompany = [];
        foreach ($results as $result) {
            $driveId = (string) ($result['driveId'] ?? '');
            if ($driveId !== '') {
                $byDrive[$driveId] = $result;
            }
            $companyKey = strtolower(trim((string) ($result['company'] ?? '')));
            if ($companyKey !== '') {
                $byCompany[$companyKey] = $result;
            }
        }

        foreach ($rows as &$row) {
            $driveId = (string) ($row['driveId'] ?? '');
            $companyKey = strtolower(trim((string) ($row['company'] ?? '')));
            $result = $byDrive[$driveId] ?? $byCompany[$companyKey] ?? null;
            if (!$result) {
                continue;
            }

            $finalStatus = ($result['status'] ?? '') === 'selected' ? 'selected' : 'rejected';
            $row['resultStatus'] = $finalStatus;
            $row['resultPackage'] = (string) ($result['package'] ?? '');
            $row['resultJoiningDate'] = (string) ($result['joiningDate'] ?? '');
            $row['resultId'] = (string) ($result['_id'] ?? $result['id'] ?? '');
            $row['status'] = $finalStatus;
            if ($row['resultPackage'] !== '') {
                $row['package'] = $row['resultPackage'];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMatchingApplication(string $studentId, string $driveId, string $company): ?array
    {
        $appModel = new ApplicationModel();

        if ($driveId !== '') {
            $app = $appModel->findByStudentAndDrive($studentId, $driveId);
            if ($app) {
                return $app;
            }
        }

        if ($company === '') {
            return null;
        }

        $companyModel = new CompanyModel();
        $driveModel = new DriveModel();
        $companyKey = strtolower($company);

        foreach ($appModel->findByStudent($studentId) as $app) {
            $co = $companyModel->findById((string) ($app['companyId'] ?? ''));
            $coName = strtolower(trim((string) ($co['companyName'] ?? '')));
            if ($coName !== '' && $coName === $companyKey) {
                return $app;
            }

            $drive = $driveModel->findById((string) ($app['driveId'] ?? ''));
            if (!$drive) {
                continue;
            }

            $title = (string) ($drive['title'] ?? '');
            if (str_contains($title, '—')) {
                $driveCompany = strtolower(trim((string) (explode('—', $title, 2)[1] ?? '')));
                if ($driveCompany !== '' && $driveCompany === $companyKey) {
                    return $app;
                }
            }
        }

        return null;
    }
}
