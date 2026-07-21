<?php

declare(strict_types=1);

namespace PMS\Company;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\CompanyApplicationService;
use PMS\Services\EmailService;
use PMS\Services\JobPostApprovalService;
use PMS\Services\NotificationService;
use PMS\Services\ObjectStorageService;
use PMS\Services\PlacementChanceService;
use PMS\Services\RecruitingService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\OwnershipHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;
use PMS\Utils\Validator;

/**
 * Company module — jobs, applications, recruitment.
 */
final class CompanyController
{
    private CompanyModel $companyModel;

    public function __construct()
    {
        $this->companyModel = new CompanyModel();
    }

    private function getCompany(array $user): array
    {
        $company = $this->companyModel->findByUserId((string) $user['_id']);
        if (!$company) {
            Response::notFound('Company profile not found.');
        }
        return $company;
    }

    /** GET /api/company/profile */
    public function profile(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $contacts = is_array($company['contacts'] ?? null) ? $company['contacts'] : [];
        $contact = is_array($contacts[0] ?? null) ? $contacts[0] : [];
        $photo = is_array($company['recruiterPhoto'] ?? null) ? $company['recruiterPhoto'] : [];
        $contactName = trim((string) ($contact['name'] ?? ''));
        $contactEmail = trim((string) ($contact['email'] ?? ''));
        $contactPhone = trim((string) ($contact['phone'] ?? ''));
        $result = DocumentHelper::serialize($company);
        $result['recruiter'] = [
            'name'          => $contactName !== '' ? $contactName : trim((string) ($user['name'] ?? '')),
            'role'          => 'Company Recruiter',
            'designation'   => trim((string) ($contact['designation'] ?? '')),
            'officialEmail' => $contactEmail !== '' ? $contactEmail : trim((string) ($user['email'] ?? '')),
            'mobile'        => $contactPhone !== '' ? $contactPhone : trim((string) ($user['phone'] ?? '')),
            'location'      => trim((string) ($company['location'] ?? '')),
            'photoUrl'      => trim((string) ($photo['url'] ?? '')),
        ];
        Response::success($result);
    }

    /** PUT /api/company/profile */
    public function updateProfile(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $allowed = ['companyName', 'category', 'tier', 'contacts', 'website', 'description', 'comments', 'location'];
        $update = array_intersect_key($input, array_flip($allowed));
        $this->companyModel->update((string) $company['_id'], $update);
        $updated = $this->companyModel->findById((string) $company['_id']);
        Response::success(DocumentHelper::serialize($updated ?? $company), 'Company profile updated.');
    }

    /** POST /api/company/profile/photo */
    public function uploadProfilePhoto(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        if (!isset($_FILES['photo'])) {
            Response::error('Profile photo is required.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $error = Security::validateUploadedFile(
            $_FILES['photo'],
            2 * 1024 * 1024,
            Security::allowedPhotoExtensions()
        );
        if ($error) {
            Response::error($error, 400);
        }

        $ext = strtolower(pathinfo((string) $_FILES['photo']['name'], PATHINFO_EXTENSION));
        $hintName = 'company_' . (string) $company['_id'] . '_' . time() . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_PHOTOS,
                $hintName,
                $_FILES['photo']
            );
        } catch (\Throwable $e) {
            Response::error('Failed to save profile photo to S3: ' . $e->getMessage(), 500);
        }

        $oldPhoto = is_array($company['recruiterPhoto'] ?? null) ? $company['recruiterPhoto'] : [];
        if (!empty($oldPhoto['path'])) {
            $storage->delete((string) $oldPhoto['path']);
        } elseif (!empty($oldPhoto['file'])) {
            $storage->delete($storage->uri(ObjectStorageService::FOLDER_PHOTOS, basename((string) $oldPhoto['file'])));
        }

        $filename = $storage->storedNameFromUri($path);
        $relative = $storage->mediaUrl(ObjectStorageService::FOLDER_PHOTOS, $filename);
        $this->companyModel->update((string) $company['_id'], [
            'recruiterPhoto' => [
                'file'       => $filename,
                'path'       => $path,
                'url'        => $relative,
                'uploadedAt' => DocumentHelper::now(),
            ],
        ]);
        Response::success(['url' => $relative], 'Profile photo updated.');
    }

    /** GET /api/company/dashboard */
    public function dashboard(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $companyId = (string) $company['_id'];
        $jobModel = new JobModel();
        $appService = new CompanyApplicationService();
        $counts = $appService->statusCounts($companyId);
        $jobs = $jobModel->findByCompany($companyId);
        $activeJobs = count(array_filter(
            $jobs,
            static fn (array $j) => in_array($j['status'] ?? 'open', ['open', 'ongoing', 'reviewing'], true)
        ));

        Response::success([
            'companyName'     => (string) ($company['companyName'] ?? ''),
            'totalJobs'       => count($jobs),
            'activeJobs'      => $activeJobs,
            'totalApplicants' => $counts['total'],
            'shortlisted'     => $counts['shortlisted'],
            'offered'         => $counts['offered'],
            'selected'        => $counts['offered'],
            'funnel'          => [
                'applied'      => $counts['applied'] + $counts['under_review'],
                'shortlisted'  => $counts['shortlisted'],
                'interview'    => $counts['interview'],
                'offered'      => $counts['offered'],
                'joined'       => $counts['offered'],
            ],
        ]);
    }

    /** GET /api/company/drives */
    public function listDrives(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $drives = (new DriveModel())->findByCompanyId((string) $company['_id']);
        Response::success(DocumentHelper::serializeMany($drives));
    }

    /** PUT /api/company/drives/{id}/eligibility */
    public function updateDriveEligibility(string $driveId): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $drive = (new DriveModel())->findById($driveId);
        if (!$drive || (string) ($drive['companyId'] ?? '') !== (string) $company['_id']) {
            Response::notFound('Drive not found.');
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $eligibility = [
            'minCgpa'     => (float) ($input['minCgpa'] ?? 0),
            'maxBacklogs' => (int) ($input['maxBacklogs'] ?? 0),
            'minPercentAllClasses' => (float) ($input['minPercentAllClasses'] ?? 0),
            'min10th'     => (float) ($input['min10th'] ?? 0),
            'min12th'     => (float) ($input['min12th'] ?? 0),
            'minUg'       => (float) ($input['minUg'] ?? 0),
            'minPg'       => (float) ($input['minPg'] ?? 0),
            'gender'      => strtolower(trim((string) ($input['gender'] ?? 'any'))),
            'branches'    => $input['branches'] ?? [],
            'notes'       => trim((string) ($input['notes'] ?? '')),
        ];
        (new DriveModel())->updateEligibility($driveId, $eligibility);
        Response::success(['eligibility' => $eligibility], 'Eligibility criteria saved.');
    }

    /** GET /api/company/eligibility/preview */
    public function eligibilityPreview(): void
    {
        RBACMiddleware::requireCompany();
        $criteria = [
            'minCgpa'     => (float) ($_GET['minCgpa'] ?? 0),
            'maxBacklogs' => (int) ($_GET['maxBacklogs'] ?? 99),
            'minPercentAllClasses' => (float) ($_GET['minPercentAllClasses'] ?? 0),
            'min10th'     => (float) ($_GET['min10th'] ?? 0),
            'min12th'     => (float) ($_GET['min12th'] ?? 0),
            'minUg'       => (float) ($_GET['minUg'] ?? 0),
            'minPg'       => (float) ($_GET['minPg'] ?? 0),
            'gender'      => strtolower(trim((string) ($_GET['gender'] ?? 'any'))),
        ];
        $branches = array_values(array_filter(array_map(
            static fn (string $branch): string => strtoupper(trim($branch)),
            explode(',', (string) ($_GET['branches'] ?? ''))
        )));
        $students = (new StudentModel())->findAll([], 200);
        $deptModel = new \PMS\Models\DepartmentModel();
        $engine = new \PMS\Services\EligibilityEngine();
        $preview = [];
        foreach ($students as $student) {
            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $deptId ? $deptModel->findById($deptId) : null;
            $code = $dept ? (string) ($dept['code'] ?? '') : '';
            $check = $engine->checkStudentAgainstCriteria($student, $criteria, $branches);
            $user = (new \PMS\Models\UserModel())->findById((string) $student['userId']);
            $preview[] = [
                'name'      => $user['name'] ?? 'Student',
                'roll'      => $student['registerNumber'] ?? '',
                'dept'      => $code,
                'cgpa'      => (float) ($student['academic']['cgpa'] ?? 0),
                'backlogs'  => (int) ($student['academic']['backlogs'] ?? 0),
                'eligible'  => $check['eligible'],
                'reasons'   => $check['reasons'],
            ];
        }
        Response::success($preview);
    }

    /** POST /api/company/jobs */
    public function createJob(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);

        $errors = Validator::validate($input, [
            'title' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $rawDepartmentIds = $input['departmentIds'] ?? $input['departmentIds[]'] ?? $input['departmentId'] ?? [];
        if (!is_array($rawDepartmentIds)) {
            $rawDepartmentIds = [$rawDepartmentIds];
        }
        $departmentIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $rawDepartmentIds
        ))));
        if ($departmentIds === []) {
            Response::error('Select at least one department for this job post.', 422);
        }

        $departments = [];
        $branchCodesMap = [];
        foreach ($departmentIds as $departmentId) {
            $department = (new DepartmentModel())->findById($departmentId);
            if (!$department) {
                Response::error('Select valid departments for this job post.', 422);
            }
            $departments[] = $department;
            foreach ([$department['code'] ?? '', $department['shortName'] ?? ''] as $value) {
                $code = strtoupper(trim((string) $value));
                if ($code !== '') {
                    $branchCodesMap[$code] = true;
                }
            }
        }
        $branchCodes = array_keys($branchCodesMap);

        $input['companyId'] = (string) $company['_id'];
        $input['ownerUserId'] = (string) $user['_id'];
        $input['companyName'] = (string) ($company['companyName'] ?? '');
        $input['company'] = $input['companyName'];
        $input['departmentId'] = count($departmentIds) === 1 ? $departmentIds[0] : '';
        $input['departmentIds'] = $departmentIds;
        $input['eligibility'] = [
            'branches' => $branchCodes,
            'departments' => $departmentIds,
        ];
        $input['status'] = 'pending';
        $input['audience'] = $input['audience'] ?? 'both';
        if (!empty($input['type']) && empty($input['jobType'])) {
            $input['jobType'] = $input['type'];
        }

        $savedPosterPath = '';
        $posterFile = $_FILES['poster'] ?? $_FILES['image'] ?? null;
        if (is_array($posterFile) && ($posterFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $savedPoster = $this->storeJobPoster($posterFile, 'company_' . (string) $company['_id']);
            $input['posterUrl'] = $savedPoster['url'];
            $input['posterType'] = $savedPoster['type'];
            if ($savedPoster['type'] === 'image') {
                $input['imageUrl'] = $savedPoster['url'];
            }
            $savedPosterPath = $savedPoster['path'];
        }

        if (isset($_FILES['jd'])) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $err = Security::validateUploadedFile(
                $_FILES['jd'],
                $config['uploads']['max_jd'],
                ['pdf', 'doc', 'docx']
            );
            if ($err) {
                Response::error($err, 400);
            }
            $storedName = time() . '_' . basename((string) $_FILES['jd']['name']);
            $storage = new ObjectStorageService($config);
            try {
                $input['jdFile'] = $storage->putUploadedFile(
                    ObjectStorageService::FOLDER_JD,
                    $storedName,
                    $_FILES['jd']
                );
            } catch (\Throwable $e) {
                Response::error('Failed to save JD to S3: ' . $e->getMessage(), 500);
            }
        }

        try {
            $id = (new JobModel())->createJob($input);
        } catch (\Throwable $e) {
            if ($savedPosterPath !== '') {
                (new ObjectStorageService())->delete($savedPosterPath);
            }
            throw $e;
        }

        $created = (new JobModel())->findById($id);
        if (!$created) {
            $created = array_merge($input, ['_id' => $id, 'sourceType' => 'company']);
        }
        // Normalize fields used by notifyReviewers / pending queue UI.
        if (empty($created['company'])) {
            $created['company'] = (string) ($created['companyName'] ?? $input['companyName'] ?? '');
        }
        $created['sourceType'] = 'company';
        $poster = $user;
        if (trim((string) ($poster['name'] ?? '')) === '') {
            $poster['name'] = (string) ($company['companyName'] ?? 'Company');
        }
        (new JobPostApprovalService())->notifyReviewers($created, $poster);
        Response::success(['id' => $id, 'status' => 'pending'], 'Job post submitted for approval.', 201);
    }

    /**
     * @param array<string, mixed> $file
     * @return array{url:string,path:string,type:string}
     */
    private function storeJobPoster(array $file, string $prefix): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $error = Security::validateUploadedFile(
            $file,
            (int) ($config['uploads']['max_job_poster'] ?? 10 * 1024 * 1024),
            ['jpg', 'jpeg', 'png', 'webp', 'pdf']
        );
        if ($error) {
            Response::error($error, 400);
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix)
            . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_JOB_POSTERS,
                $filename,
                $file
            );
        } catch (\Throwable $e) {
            Response::error('Failed to save the job poster to S3: ' . $e->getMessage(), 500);
        }
        $storedName = $storage->storedNameFromUri($path);
        return [
            'url' => $storage->mediaUrl(ObjectStorageService::FOLDER_JOB_POSTERS, $storedName),
            'path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
        ];
    }

    /** GET /api/company/jobs */
    public function listJobs(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $companyId = (string) $company['_id'];
        $jobModel = new JobModel();
        $jobs = $jobModel->findByCompany($companyId);
        $counts = $jobModel->countApplicantsByJob($companyId);
        $serialized = array_map(static function (array $job) use ($counts) {
            $jobId = (string) $job['_id'];
            $out = DocumentHelper::serialize($job);
            $out['applicantCount'] = $counts[$jobId] ?? 0;
            return $out;
        }, $jobs);
        Response::success($serialized);
    }

    /** PUT /api/company/jobs/{id} */
    public function updateJob(string $jobId): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $job = (new JobModel())->findById($jobId);
        if (!$job || (string) ($job['companyId'] ?? '') !== (string) $company['_id']) {
            Response::notFound('Job not found.');
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!(new JobModel())->updateJob($jobId, $input)) {
            Response::error('No valid fields to update.', 422);
        }
        Response::success(null, 'Job updated.');
    }

    /** GET /api/company/applications */
    public function applications(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $filters = [
            'status'  => $_GET['status'] ?? null,
            'driveId' => $_GET['driveId'] ?? null,
            'minCgpa' => $_GET['minCgpa'] ?? null,
            'branch'  => $_GET['branch'] ?? null,
        ];
        $rows = (new CompanyApplicationService())->listEnriched((string) $company['_id'], $filters);
        Response::success($rows);
    }

    /** GET /api/company/applications/filter */
    public function filterApplicants(): void
    {
        $this->applications();
    }

    /** POST /api/company/applications/{id}/review */
    public function startReview(string $appId): void
    {
        $user = RBACMiddleware::requireCompany();
        $app = OwnershipHelper::requireCompanyApplication($appId, $user);
        $company = $this->getCompany($user);
        $current = (string) ($app['status'] ?? 'applied');
        if (in_array($current, ['company_review', 'shortlisted', 'selected'], true)) {
            Response::success(null, 'Application is already under review or processed.');
            return;
        }

        (new ApplicationWorkflowService())->transition($appId, 'company_review', (string) $user['_id']);
        $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
        if ($student && !empty($student['userId'])) {
            (new NotificationService())->notifyApplicationUpdate(
                (string) $student['userId'],
                'Application Under Review',
                'Your application is now under review at ' . (string) ($company['companyName'] ?? 'the company') . '.'
            );
        }
        Response::success(null, 'Application marked under company review.');
    }

    /** POST /api/company/applications/{id}/shortlist */
    public function shortlist(string $appId): void
    {
        $user = RBACMiddleware::requireCompany();
        $app = OwnershipHelper::requireCompanyApplication($appId, $user);
        $company = $this->getCompany($user);
        $current = (string) ($app['status'] ?? 'applied');
        if ($current === 'shortlisted') {
            Response::success(null, 'Already shortlisted.');
            return;
        }
        if ($current === 'selected') {
            Response::success(null, 'Applicant already offered.');
            return;
        }

        (new ApplicationWorkflowService())->transition($appId, 'shortlisted', (string) $user['_id']);
        $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
        if ($student && !empty($student['userId'])) {
            (new NotificationService())->notifyApplicationUpdate(
                (string) $student['userId'],
                'Shortlisted',
                'Congratulations! You have been shortlisted by ' . (string) ($company['companyName'] ?? 'the company') . '.'
            );
        }
        Response::success(null, 'Student shortlisted.');
    }

    /** POST /api/company/applications/upload-results */
    public function uploadResults(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('Selected-list file is required.', 400);
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('File upload failed.', 400);
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedExtensions = ['csv', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($ext, $allowedExtensions, true)) {
            Response::error('Only CSV, PDF, Word, and Excel files are allowed.', 400);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || !is_uploaded_file($tmpPath)) {
            Response::error('Uploaded file is empty or invalid.', 400);
        }
        if ($fileSize > 10 * 1024 * 1024) {
            Response::error('Selected-list file must be 10 MB or smaller.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $recipients = is_array($config['super_admin_emails'] ?? null)
            ? $config['super_admin_emails']
            : [];
        foreach ((new UserModel())->findByRole('admin', 100) as $admin) {
            $recipients[] = (string) ($admin['email'] ?? '');
        }
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn (mixed $email): string => strtolower(trim((string) $email)),
            $recipients
        ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
        if ($recipients === []) {
            Response::error('No admin email address is configured.', 503);
        }

        $companyName = trim((string) ($company['companyName'] ?? $user['companyName'] ?? 'Company'));
        $safeCompanyName = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
        $safeFileName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $body = "<p><strong>{$safeCompanyName}</strong> submitted a selected-candidate list.</p>"
            . "<p>File: {$safeFileName}</p>"
            . '<p>The selected-list file is attached to this email.</p>';

        $attachmentBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', $companyName) ?: 'company';
        $attachmentPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . trim($attachmentBase, '-') . '-selected-list-' . date('Ymd-His') . '.' . $ext;
        $hasAttachment = @copy($tmpPath, $attachmentPath);
        if (!$hasAttachment) {
            Response::error('Selected-list attachment could not be prepared.', 500);
        }

        $mail = new EmailService();
        $sent = 0;
        foreach ($recipients as $recipient) {
            if ($mail->send(
                $recipient,
                "{$companyName} — Selected Candidate List",
                $body,
                true,
                $attachmentPath
            )) {
                $sent++;
            }
        }
        @unlink($attachmentPath);
        if ($sent === 0) {
            Response::error('The selected list could not be emailed to the admin.', 502);
        }

        (new NotificationService())->notifyAdmins(
            'selected_list_submitted',
            'Selected list submitted',
            "{$companyName} emailed a selected-candidate list to the placement admin.",
            ['companyId' => (string) ($company['_id'] ?? ''), 'fileName' => $name]
        );

        Response::success(
            ['sentTo' => $sent],
            "Selected list emailed to {$sent} admin address(es)."
        );
    }

    /** POST /api/company/applications/{id}/result */
    public function updateResult(string $appId): void
    {
        $user = RBACMiddleware::requireCompany();
        $app = OwnershipHelper::requireCompanyApplication($appId, $user);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $status = $input['status'] ?? 'rejected';
        if (!in_array($status, ['selected', 'rejected'], true)) {
            Response::error('Invalid status.', 400);
        }

        (new ApplicationWorkflowService())->transition(
            $appId,
            $status,
            (string) $user['_id'],
            $input['remarks'] ?? ''
        );

        $student = (new StudentModel())->findById((string) $app['studentId']);
        if ($student) {
            $company = $this->getCompany($user);
            $notifier = new NotificationService();
            $notifier->notifySelectionUpdate(
                (string) $student['userId'],
                (string) ($company['companyName'] ?? 'Company'),
                $status
            );
            if ($status === 'selected') {
                (new PlacementChanceService())->consumeOnSelection(
                    (string) $student['_id'],
                    (string) $app['driveId'],
                    [
                        'companyId' => (string) $app['companyId'],
                        'driveId'   => (string) $app['driveId'],
                        'applicationId' => $appId,
                    ]
                );
            }
        }

        Response::success(null, 'Result updated.');
    }

    /** GET /api/company/notifications */
    public function notifications(): void
    {
        $user = RBACMiddleware::requireCompany();
        $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($notifs));
    }

    /** POST /api/company/notifications/{id}/read */
    public function markNotificationRead(string $id): void
    {
        $user = RBACMiddleware::requireCompany();
        $notif = (new NotificationModel())->findById($id);
        if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
            Response::notFound();
        }
        (new NotificationModel())->markRead($id);
        Response::success(null, 'Notification marked as read.');
    }

    /** POST /api/company/notifications/read-all */
    public function markAllNotificationsRead(): void
    {
        $user = RBACMiddleware::requireCompany();
        $count = (new NotificationModel())->markAllRead((string) $user['_id']);
        Response::success(['updated' => $count], 'All notifications marked as read.');
    }

    /** POST /api/company/notifications/delete-selected */
    public function deleteSelectedNotifications(): void
    {
        $user = RBACMiddleware::requireCompany();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        $ids = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $ids)));
        if ($ids === []) {
            Response::error('Select at least one notification to delete.', 422);
        }
        $count = (new NotificationModel())->deleteOwned((string) $user['_id'], $ids);
        Response::success(['deleted' => $count], $count === 1 ? 'Notification deleted.' : "{$count} notifications deleted.");
    }

    /** POST /api/company/notifications/delete-all */
    public function deleteAllNotifications(): void
    {
        $user = RBACMiddleware::requireCompany();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $readOnly = !array_key_exists('readOnly', $input) || filter_var($input['readOnly'], FILTER_VALIDATE_BOOL);
        $count = (new NotificationModel())->deleteAllForUser((string) $user['_id'], $readOnly);
        Response::success(
            ['deleted' => $count],
            $readOnly ? 'All read notifications deleted.' : 'All notifications deleted.'
        );
    }

    /** GET /api/company/recruiting */
    public function recruitingOverview(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        Response::success((new RecruitingService())->getCompanyOverview((string) $company['_id']));
    }
}
