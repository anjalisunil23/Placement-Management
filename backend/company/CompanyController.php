<?php

declare(strict_types=1);

namespace PMS\Company;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\CompanyModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\CompanyApplicationService;
use PMS\Services\NotificationService;
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
        Response::success(DocumentHelper::serialize($this->getCompany($user)));
    }

    /** PUT /api/company/profile */
    public function updateProfile(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $allowed = ['companyName', 'category', 'tier', 'contacts', 'website', 'description', 'comments'];
        $update = array_intersect_key($input, array_flip($allowed));
        $this->companyModel->update((string) $company['_id'], $update);
        $updated = $this->companyModel->findById((string) $company['_id']);
        Response::success(DocumentHelper::serialize($updated ?? $company), 'Company profile updated.');
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
            'totalJobs'       => count($jobs),
            'activeJobs'      => $activeJobs,
            'totalApplicants' => $counts['total'],
            'shortlisted'     => $counts['shortlisted'],
            'offered'         => $counts['offered'],
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
        $minCgpa = isset($_GET['minCgpa']) ? (float) $_GET['minCgpa'] : 0;
        $maxBacklogs = isset($_GET['maxBacklogs']) ? (int) $_GET['maxBacklogs'] : 99;
        $branches = isset($_GET['branches']) ? explode(',', (string) $_GET['branches']) : [];

        $students = (new StudentModel())->filterStudents([
            'minCgpa'     => $minCgpa > 0 ? $minCgpa : null,
            'maxBacklogs' => $maxBacklogs,
        ], 200);

        $deptModel = new \PMS\Models\DepartmentModel();
        $preview = [];
        foreach ($students as $student) {
            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $deptId ? $deptModel->findById($deptId) : null;
            $code = $dept ? (string) ($dept['code'] ?? '') : '';
            $eligible = true;
            if ($branches !== [] && $branches[0] !== '' && !in_array($code, $branches, true)) {
                $eligible = false;
            }
            $user = (new \PMS\Models\UserModel())->findById((string) $student['userId']);
            $preview[] = [
                'name'      => $user['name'] ?? 'Student',
                'roll'      => $student['registerNumber'] ?? '',
                'dept'      => $code,
                'cgpa'      => (float) ($student['academic']['cgpa'] ?? 0),
                'backlogs'  => (int) ($student['academic']['backlogs'] ?? 0),
                'eligible'  => $eligible,
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
            'title'    => 'required',
            'package'  => 'required',
            'location' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $input['companyId'] = (string) $company['_id'];
        if (isset($input['eligibility']) && is_string($input['eligibility'])) {
            $input['eligibility'] = json_decode($input['eligibility'], true) ?? [];
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
            $dir = $config['uploads']['jd_dir'];
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $jdPath = $dir . '/' . time() . '_' . basename($_FILES['jd']['name']);
            move_uploaded_file($_FILES['jd']['tmp_name'], $jdPath);
            $input['jdFile'] = $jdPath;
        }

        $id = (new JobModel())->createJob($input);
        Response::success(['id' => $id], 'Job posted.', 201);
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
            Response::error('CSV file is required.', 400);
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('File upload failed.', 400);
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            Response::error('Only CSV files are allowed.', 400);
        }

        $content = file_get_contents((string) ($file['tmp_name'] ?? ''));
        if ($content === false || trim($content) === '') {
            Response::error('CSV file is empty.', 400);
        }

        $result = (new CompanyApplicationService())->uploadResultsCsv(
            (string) $company['_id'],
            (string) $user['_id'],
            $content
        );

        $message = $result['updated'] > 0
            ? "Updated {$result['updated']} candidate(s)."
            : 'No candidates were updated.';

        Response::success($result, $message);
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

    /** GET /api/company/recruiting */
    public function recruitingOverview(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        Response::success((new RecruitingService())->getCompanyOverview((string) $company['_id']));
    }
}
