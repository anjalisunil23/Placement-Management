<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DriveApplicationExceptionModel;
use PMS\Models\DriveModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;

/**
 * Officer-granted exceptions so Tier-3 placed students can apply to a specific drive.
 */
final class DriveApplicationExceptionService
{
    /**
     * @param array<string, mixed> $ctx PlacementOfficerContext::resolve()
     * @return array<string, mixed>
     */
    public function grantForDrive(
        string $driveId,
        string $studentRef,
        string $reason,
        array $ctx,
        string $grantedByUserId,
        ?string $expiresAt = null
    ): array {
        $driveId = trim($driveId);
        $studentRef = trim($studentRef);
        $reason = trim($reason);
        if ($driveId === '' || $studentRef === '' || $reason === '') {
            Response::error('driveId, student (register number or id), and reason are required.', 422);
        }

        $drive = (new DriveModel())->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }
        if (!DriveLifecycle::isRegistrationOpen($drive)
            || DriveLifecycle::effectiveStatus($drive) === 'closed'
            || DriveLifecycle::effectiveStatus($drive) === 'completed'
        ) {
            Response::error('Registration for this drive is closed. Open or reopen registration before granting an exception.', 422);
        }

        $officer = new OfficerDataService();
        $student = $officer->resolveStudentRef($studentRef);
        if (!$student) {
            Response::notFound('Student not found for that register number / id.');
        }
        $studentId = (string) ($student['_id'] ?? '');
        if ($studentId === '') {
            Response::notFound('Student not found.');
        }

        PlacementOfficerContext::assertStudentInDepartment($studentId, $ctx);

        if (empty($student['placed'])) {
            Response::error('This exception is only for students who are already placed (Tier 3 / Category C).', 422);
        }

        $categories = new PlacementCategoryService();
        if (!$categories->studentIsTier3Placed($student)) {
            Response::error(
                'This exception is only for students already placed in a Tier 3 (or Category C) company. Contact admin for other cases.',
                422
            );
        }

        $company = null;
        if (!empty($drive['companyId'])) {
            $company = (new \PMS\Models\CompanyModel())->findById((string) $drive['companyId']);
        }
        $gate = $categories->mayAttemptDrive($student, $drive, is_array($company) ? $company : null);
        if ($gate['allowed']) {
            Response::error(
                'This student is already allowed to attempt this drive under placement category rules. No exception is needed.',
                422
            );
        }

        $appModel = new \PMS\Models\ApplicationModel();
        if ($appModel->findByStudentAndDrive($studentId, $driveId)) {
            Response::error('Student has already applied to this drive.', 409);
        }

        $expires = null;
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $ts = strtotime(trim($expiresAt));
            if ($ts === false) {
                Response::error('Invalid expiresAt date.', 422);
            }
            $expires = date('c', $ts);
        }

        $result = (new DriveApplicationExceptionModel())->grant(
            $studentId,
            $driveId,
            $grantedByUserId,
            $reason,
            $expires
        );

        $userId = (string) ($student['userId'] ?? '');
        if ($userId !== '') {
            $driveTitle = trim((string) ($drive['title'] ?? 'campus drive'));
            try {
                (new NotificationModel())->notify(
                    $userId,
                    'drive_exception',
                    'Drive opened for you',
                    'The placement cell opened “‘ . $driveTitle . ’” for you. You can now apply through the portal.',
                    [
                        'driveId' => $driveId,
                        'exceptionId' => $result['id'],
                    ]
                );
            } catch (\Throwable) {
                // Non-fatal.
            }
        }

        return $this->serializeException(
            (new DriveApplicationExceptionModel())->findById($result['id']) ?? [
                '_id' => $result['id'],
                'studentId' => $studentId,
                'driveId' => $driveId,
                'reason' => $reason,
                'grantedAt' => DocumentHelper::now(),
            ],
            $student,
            $drive
        );
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listForDrive(string $driveId, array $ctx): array
    {
        $drive = (new DriveModel())->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }

        $rows = (new DriveApplicationExceptionModel())->listActiveForDrive($driveId);
        $studentModel = new StudentModel();
        $out = [];
        foreach ($rows as $row) {
            $student = $studentModel->findById((string) ($row['studentId'] ?? ''));
            if (!is_array($student)) {
                continue;
            }
            if (empty($ctx['isAdmin']) && !empty($ctx['departmentId'])) {
                if ((string) ($student['departmentId'] ?? '') !== (string) $ctx['departmentId']) {
                    continue;
                }
            }
            $out[] = $this->serializeException($row, $student, $drive);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function revoke(string $exceptionId, array $ctx, string $revokedByUserId): void
    {
        $model = new DriveApplicationExceptionModel();
        $row = $model->findById($exceptionId);
        if (!$row || !empty($row['revokedAt'])) {
            Response::notFound('Exception not found.');
        }

        $studentId = (string) ($row['studentId'] ?? '');
        if ($studentId !== '') {
            PlacementOfficerContext::assertStudentInDepartment($studentId, $ctx);
        }

        if (!$model->revoke($exceptionId, $revokedByUserId)) {
            Response::error('Could not revoke exception.', 500);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $student
     * @param array<string, mixed>|null $drive
     * @return array<string, mixed>
     */
    private function serializeException(array $row, ?array $student, ?array $drive): array
    {
        $user = null;
        if (is_array($student) && !empty($student['userId'])) {
            $user = (new UserModel())->findById((string) $student['userId']);
        }

        return [
            'id'             => (string) ($row['_id'] ?? $row['id'] ?? ''),
            'driveId'        => (string) ($row['driveId'] ?? ''),
            'driveTitle'     => is_array($drive) ? (string) ($drive['title'] ?? '') : '',
            'studentId'      => (string) ($row['studentId'] ?? ''),
            'studentName'    => is_array($user) ? (string) ($user['name'] ?? '') : '',
            'registerNumber' => is_array($student) ? (string) ($student['registerNumber'] ?? '') : '',
            'reason'         => (string) ($row['reason'] ?? ''),
            'grantedAt'      => $row['grantedAt'] ?? null,
            'expiresAt'      => $row['expiresAt'] ?? null,
            'placementCategory' => is_array($student)
                ? (new PlacementCategoryService())->studentPlacementCategory($student)
                : null,
        ];
    }
}
