<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Admin / placement-officer review of student self-reported placements.
 */
final class SelfPlacementService
{
    private StudentModel $studentModel;
    private OfficerDataService $officerData;

    public function __construct()
    {
        $this->studentModel = new StudentModel();
        $this->officerData  = new OfficerDataService();
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function getReport(string $studentRef, array $ctx): array
    {
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $self = $student['selfPlacement'] ?? null;
        if (!is_array($self) || (string) ($self['companyName'] ?? '') === '') {
            Response::notFound('No self-placement report found for this student.');
        }

        $user = null;
        $userId = (string) ($student['userId'] ?? '');
        if ($userId !== '') {
            $user = (new UserModel())->findById($userId);
        }

        $dept = null;
        $deptId = (string) ($student['departmentId'] ?? '');
        if ($deptId !== '') {
            $dept = (new DepartmentModel())->findById($deptId);
        }

        $displayName = $this->officerData->enrichStudentListRow([], $student, $user)['displayName'] ?? '';
        if ($displayName === '' && is_array($user)) {
            $displayName = (string) ($user['name'] ?? '');
        }

        $serialized = DocumentHelper::serialize($self) ?? [];
        unset($serialized['offerLetterPath']);

        return [
            'studentId'      => (string) ($student['_id'] ?? ''),
            'registerNumber' => (string) ($student['registerNumber'] ?? ''),
            'studentName'    => $displayName,
            'department'     => $dept ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '',
            'placed'         => ($student['placed'] ?? false) === true,
            'report'         => $serialized,
            'hasOfferLetter' => (string) ($self['offerLetter'] ?? '') !== '',
            'offerLetterName'=> (string) ($self['offerLetter'] ?? ''),
            'hasCompanyIdDoc'=> (string) ($self['companyIdDoc'] ?? '') !== '',
            'hasSalarySlip'  => (string) ($self['salarySlip'] ?? '') !== '',
            'hasJoiningLetter'=> (string) ($self['joiningLetter'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function streamCompanyIdDoc(string $studentRef, array $ctx): void
    {
        $this->streamDocument($studentRef, $ctx, 'companyIdDoc');
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function streamSalarySlip(string $studentRef, array $ctx): void
    {
        $this->streamDocument($studentRef, $ctx, 'salarySlip');
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function streamDocument(string $studentRef, array $ctx, string $field): void
    {
        $allowed = ['offerLetter', 'companyIdDoc', 'salarySlip', 'joiningLetter'];
        if (!in_array($field, $allowed, true)) {
            Response::notFound('Document not found.');
        }

        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $path = $this->selfPlacementDocPath($student, $field);
        if ($path === null) {
            Response::notFound('Document not found.');
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function streamOfferLetter(string $studentRef, array $ctx): void
    {
        $this->streamDocument($studentRef, $ctx, 'offerLetter');
    }

    /**
     * Officer / admin records a self-placement for a student and marks them placed.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $reviewer
     * @param array<string, mixed> $input companyName, role, companyAddress (+ optional $_FILES)
     * @return array<string, mixed>
     */
    public function createForStudent(string $studentRef, array $ctx, array $reviewer, array $input = []): array
    {
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $studentId = (string) ($student['_id'] ?? '');

        if (($student['placed'] ?? false) === true) {
            Response::error('This student is already marked as placed.', 409);
        }

        $existing = $student['selfPlacement'] ?? null;
        if (is_array($existing) && (string) ($existing['status'] ?? '') === 'pending') {
            Response::error('A self-placement report is already under review. Approve or reject it first.', 409);
        }

        $companyName = trim((string) ($input['companyName'] ?? $_POST['companyName'] ?? ''));
        $companyAddress = trim((string) ($input['companyAddress'] ?? $_POST['companyAddress'] ?? ''));
        $jobRole = trim((string) ($input['role'] ?? $_POST['role'] ?? ''));

        if ($companyName === '' || $jobRole === '') {
            Response::error('Company name and role are required.', 422);
        }
        if ($companyAddress === '') {
            Response::error('Company address is required.', 422);
        }

        $registerNo = (string) ($student['registerNumber'] ?? 'student');
        $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $companyName) ?: 'company';
        $savedPaths = [];
        $offerLetter = $this->saveOptionalUpload('offerLetter', $registerNo, $safeCompany, 'offer', ['pdf'], $savedPaths);
        $companyIdDoc = $this->saveOptionalUpload('companyIdDoc', $registerNo, $safeCompany, 'company_id', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'], $savedPaths);
        $salarySlip = $this->saveOptionalUpload('salarySlip', $registerNo, $safeCompany, 'salary_slip', ['pdf', 'doc', 'docx'], $savedPaths);

        $now = DocumentHelper::now();
        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $reviewerName = (string) ($reviewer['name'] ?? 'Placement cell');

        $self = [
            'companyName'     => $companyName,
            'companyAddress'  => $companyAddress,
            'role'            => $jobRole,
            'status'          => 'approved',
            'submittedAt'     => $now,
            'verifiedAt'      => $now,
            'verifiedBy'      => $reviewerId,
            'verifiedByName'  => $reviewerName,
            'recordedByStaff' => true,
        ];
        if ($offerLetter !== null) {
            $self['offerLetter'] = $offerLetter;
        }
        if ($companyIdDoc !== null) {
            $self['companyIdDoc'] = $companyIdDoc;
        }
        if ($salarySlip !== null) {
            $self['salarySlip'] = $salarySlip;
        }

        $history = is_array($student['placementHistory'] ?? null) ? $student['placementHistory'] : [];
        $history[] = [
            'type'       => 'self_reported',
            'company'    => $companyName,
            'address'    => $companyAddress,
            'role'       => $jobRole,
            'status'     => 'placed',
            'date'       => $now,
            'verifiedAt' => $now,
            'verifiedBy' => $reviewerId,
            'source'     => 'staff_recorded',
        ];

        $placement = [
            'company'  => $companyName,
            'role'     => $jobRole,
            'address'  => $companyAddress,
            'source'   => 'self_reported',
            'placedAt' => $now,
        ];

        $saved = $this->studentModel->update($studentId, [
            'placed'           => true,
            'selfPlacement'    => $self,
            'placementHistory' => $history,
            'placement'        => $placement,
        ]);
        if (!$saved) {
            foreach ($savedPaths as $path) {
                @unlink($path);
            }
            Response::error('Could not save placement.', 500);
        }

        $user = null;
        $userId = (string) ($student['userId'] ?? '');
        if ($userId !== '') {
            $user = (new UserModel())->findById($userId);
        }
        $studentName = is_array($user) ? (string) ($user['name'] ?? '') : '';
        if ($studentName === '') {
            $studentName = (string) ($this->officerData->enrichStudentListRow([], $student, $user)['displayName'] ?? $student['registerNumber'] ?? 'Student');
        }

        try {
            (new RecruitmentResultModel())->upsertByRegisterCompany([
                'studentName'    => $studentName,
                'registerNumber' => $registerNo,
                'company'        => $companyName,
                'role'           => $jobRole,
                'status'         => 'selected',
                'departmentId'   => (string) ($student['departmentId'] ?? ''),
            ]);
        } catch (\Throwable) {
        }

        if ($userId !== '') {
            try {
                (new NotificationService())->notifyUser(
                    $userId,
                    'placement_approved',
                    'Placement recorded',
                    "The placement cell recorded your placement with {$companyName} as {$jobRole}.",
                    [
                        'companyName' => $companyName,
                        'role'        => $jobRole,
                        'studentId'   => $studentId,
                    ],
                    false
                );
            } catch (\Throwable) {
            }
        }

        return [
            'studentId'      => $studentId,
            'registerNumber' => $registerNo,
            'placed'         => true,
            'placement'      => DocumentHelper::serialize($placement),
            'report'         => DocumentHelper::serialize($self),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $reviewer
     * @return array<string, mixed>
     */
    public function approve(string $studentRef, array $ctx, array $reviewer): array
    {
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $studentId = (string) ($student['_id'] ?? '');
        $self = $student['selfPlacement'] ?? null;

        if (!is_array($self) || (string) ($self['companyName'] ?? '') === '') {
            Response::notFound('No self-placement report found for this student.');
        }

        $status = (string) ($self['status'] ?? '');
        if ($status === 'approved' && ($student['placed'] ?? false) === true) {
            Response::error('This placement report is already approved.', 409);
        }
        if ($status !== 'pending' && $status !== 'placed') {
            Response::error('Only pending placement reports can be approved.', 422);
        }

        $companyName = (string) ($self['companyName'] ?? '');
        $role = (string) ($self['role'] ?? '');
        $address = (string) ($self['companyAddress'] ?? '');
        $package = (string) ($self['package'] ?? '');
        $joinDate = (string) ($self['joinDate'] ?? '');
        $endDate = (string) ($self['endDate'] ?? '');
        $now = DocumentHelper::now();
        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $reviewerName = (string) ($reviewer['name'] ?? 'Placement cell');

        $self['status'] = 'approved';
        $self['verifiedAt'] = $now;
        $self['verifiedBy'] = $reviewerId;
        $self['verifiedByName'] = $reviewerName;

        $history = is_array($student['placementHistory'] ?? null) ? $student['placementHistory'] : [];
        $categories = new PlacementCategoryService();
        $placementCategory = $categories->classify($package, null);
        foreach ($history as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'self_reported' && in_array((string) ($entry['status'] ?? ''), ['pending', 'placed'], true)) {
                $history[$idx]['status'] = 'placed';
                $history[$idx]['verifiedAt'] = $now;
                $history[$idx]['verifiedBy'] = $reviewerId;
                if ($package !== '') {
                    $history[$idx]['package'] = $package;
                }
                if ($placementCategory !== null) {
                    $history[$idx]['placementCategory'] = $placementCategory;
                }
                if ($joinDate !== '') {
                    $history[$idx]['joinDate'] = $joinDate;
                }
                if ($endDate !== '') {
                    $history[$idx]['endDate'] = $endDate;
                }
            }
        }

        $placement = [
            'company'       => $companyName,
            'role'          => $role,
            'address'       => $address,
            'package'       => $package,
            'placementCategory' => $placementCategory,
            'joinDate'      => $joinDate,
            'endDate'       => $endDate,
            'offerLetter'   => (string) ($self['offerLetter'] ?? ''),
            'joiningLetter' => (string) ($self['joiningLetter'] ?? ''),
            'companyIdDoc'  => (string) ($self['companyIdDoc'] ?? ''),
            'source'        => 'self_reported',
            'placedAt'      => $now,
        ];

        $this->studentModel->update($studentId, [
            'placed'              => true,
            'placementCategory'   => $placementCategory,
            'selfPlacement'       => $self,
            'placementHistory'    => $history,
            'placement'           => $placement,
        ]);

        $user = null;
        $userId = (string) ($student['userId'] ?? '');
        if ($userId !== '') {
            $user = (new UserModel())->findById($userId);
        }
        $studentName = is_array($user) ? (string) ($user['name'] ?? '') : '';
        if ($studentName === '') {
            $studentName = (string) ($this->officerData->enrichStudentListRow([], $student, $user)['displayName'] ?? $student['registerNumber'] ?? 'Student');
        }

        try {
            (new RecruitmentResultModel())->upsertByRegisterCompany([
                'studentName'    => $studentName,
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'company'        => $companyName,
                'role'           => $role,
                'package'        => $package,
                'status'         => 'selected',
                'departmentId'   => (string) ($student['departmentId'] ?? ''),
                'joiningDate'    => $joinDate,
            ]);
        } catch (\Throwable) {
            // Placement is saved; recruitment tally is best-effort.
        }

        if ($userId !== '') {
            try {
                (new NotificationService())->notifyUser(
                    $userId,
                    'placement_approved',
                    'Placement verified',
                    "Your placement with {$companyName} as {$role} has been verified by the placement cell.",
                    [
                        'companyName' => $companyName,
                        'role'        => $role,
                        'studentId'   => $studentId,
                    ],
                    false
                );
            } catch (\Throwable) {
            }
        }

        return [
            'studentId'      => $studentId,
            'registerNumber' => (string) ($student['registerNumber'] ?? ''),
            'placed'         => true,
            'placement'      => DocumentHelper::serialize($placement),
            'report'         => DocumentHelper::serialize($self),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $reviewer
     * @return array<string, mixed>
     */
    public function reject(string $studentRef, array $ctx, array $reviewer, string $reason = ''): array
    {
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $studentId = (string) ($student['_id'] ?? '');
        $self = $student['selfPlacement'] ?? null;

        if (!is_array($self) || (string) ($self['status'] ?? '') !== 'pending') {
            Response::error('Only pending placement reports can be rejected.', 422);
        }

        $now = DocumentHelper::now();
        $self['status'] = 'rejected';
        $self['rejectedAt'] = $now;
        $self['rejectedBy'] = (string) ($reviewer['_id'] ?? '');
        $self['rejectionReason'] = trim($reason);

        $history = is_array($student['placementHistory'] ?? null) ? $student['placementHistory'] : [];
        foreach ($history as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'self_reported' && ($entry['status'] ?? '') === 'pending') {
                $history[$idx]['status'] = 'rejected';
                $history[$idx]['rejectedAt'] = $now;
            }
        }

        $this->studentModel->update($studentId, [
            'selfPlacement'    => $self,
            'placementHistory' => $history,
        ]);

        $userId = (string) ($student['userId'] ?? '');
        if ($userId !== '') {
            $companyName = (string) ($self['companyName'] ?? 'the company');
            $msg = "Your placement report for {$companyName} was not approved.";
            if ($self['rejectionReason'] !== '') {
                $msg .= ' Reason: ' . $self['rejectionReason'];
            }
            try {
                (new NotificationService())->notifyUser(
                    $userId,
                    'placement_rejected',
                    'Placement report not approved',
                    $msg,
                    ['studentId' => $studentId, 'companyName' => $companyName],
                    false
                );
            } catch (\Throwable) {
            }
        }

        return [
            'studentId' => $studentId,
            'report'    => DocumentHelper::serialize($self),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function resolveScopedStudent(string $studentRef, array $ctx): array
    {
        $student = $this->officerData->resolveStudentRef($studentRef);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        PlacementOfficerContext::assertStudentInDepartment((string) ($student['_id'] ?? ''), $ctx);

        return $student;
    }

    /**
     * @param list<string> $savedPaths
     */
    private function saveOptionalUpload(
        string $field,
        string $registerNo,
        string $safeCompany,
        string $prefix,
        array $extensions,
        array &$savedPaths
    ): ?string {
        if (!isset($_FILES[$field])) {
            return null;
        }

        $uploadError = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ' upload failed.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $error = Security::validateUploadedFile(
            $_FILES[$field],
            $config['uploads']['max_resume'],
            $extensions
        );
        if ($error) {
            Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ': ' . $error, 400);
        }

        $dir = $field === 'offerLetter'
            ? ($config['uploads']['offer_letter_dir'] ?? ($config['uploads']['reports_dir'] . '/offer_letters'))
            : ($config['uploads']['self_placement_dir'] ?? ($config['uploads']['offer_letter_dir'] . '/../self_placement'));
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Response::error('Server upload folder is not writable.', 500);
        }

        $ext = strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
        $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_' . $prefix . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
            Response::error('Failed to save ' . str_replace('_', ' ', $prefix) . '.', 500);
        }

        $savedPaths[] = $path;

        return basename($path);
    }

    /**
     * @param array<string, mixed> $student
     */
    private function selfPlacementDocPath(array $student, string $field): ?string
    {
        $self = $student['selfPlacement'] ?? null;
        if (!is_array($self)) {
            return null;
        }

        $file = (string) ($self[$field] ?? '');
        if ($file === '') {
            return null;
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $dirs = [
            $config['uploads']['self_placement_dir'] ?? '',
            $config['uploads']['offer_letter_dir'] ?? ($config['uploads']['reports_dir'] . '/offer_letters'),
        ];
        $basename = basename($file);
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $path = rtrim($dir, '/\\') . '/' . $basename;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
