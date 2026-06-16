<?php

declare(strict_types=1);

namespace PMS\Company;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\NotificationService;
use PMS\Services\PlacementChanceService;
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
        Response::success(null, 'Company profile updated.');
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
        $jobs = (new JobModel())->findByCompany((string) $company['_id']);
        Response::success(DocumentHelper::serializeMany($jobs));
    }

    /** GET /api/company/applications */
    public function applications(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $apps = (new ApplicationModel())->findByCompany((string) $company['_id']);
        Response::success(DocumentHelper::serializeMany($apps));
    }

    /** GET /api/company/applications/filter */
    public function filterApplicants(): void
    {
        $user = RBACMiddleware::requireCompany();
        $company = $this->getCompany($user);
        $apps = (new ApplicationModel())->findByCompany((string) $company['_id']);
        $studentIds = array_map(fn ($a) => (string) $a['studentId'], $apps);

        $filter = [
            'minCgpa'      => $_GET['minCgpa'] ?? null,
            'maxBacklogs'  => $_GET['maxBacklogs'] ?? null,
            'departmentId' => $_GET['departmentId'] ?? null,
            'skills'       => $_GET['skills'] ?? null,
        ];
        $filter = array_filter($filter, fn ($v) => $v !== null && $v !== '');

        $studentModel = new StudentModel();
        $students = $studentModel->filterStudents($filter, 200);
        $students = array_filter($students, fn ($s) => in_array((string) $s['_id'], $studentIds, true));

        Response::success(DocumentHelper::serializeMany(array_values($students)));
    }

    /** POST /api/company/applications/{id}/review */
    public function startReview(string $appId): void
    {
        $user = RBACMiddleware::requireCompany();
        OwnershipHelper::requireCompanyApplication($appId, $user);
        (new ApplicationWorkflowService())->transition($appId, 'company_review', (string) $user['_id']);
        Response::success(null, 'Application marked under company review.');
    }

    /** POST /api/company/applications/{id}/shortlist */
    public function shortlist(string $appId): void
    {
        $user = RBACMiddleware::requireCompany();
        OwnershipHelper::requireCompanyApplication($appId, $user);
        (new ApplicationWorkflowService())->transition($appId, 'shortlisted', (string) $user['_id']);
        Response::success(null, 'Student shortlisted.');
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
            $notifier = new NotificationService();
            $notifier->notifySelectionUpdate(
                (string) $student['userId'],
                'Company',
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
}
