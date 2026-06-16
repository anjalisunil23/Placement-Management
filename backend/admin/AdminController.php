<?php

declare(strict_types=1);

namespace PMS\Admin;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\BlacklistModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\EmailService;
use PMS\Services\NotificationService;
use PMS\Services\ReportService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;
use PMS\Schemas\Collections;

/**
 * Admin module — user management, departments, rules, reports.
 */
final class AdminController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    /** GET /api/admin/dashboard */
    public function dashboard(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success($this->userModel->getDashboardStats());
    }

    /** GET /api/admin/users */
    public function listUsers(): void
    {
        RBACMiddleware::requireAdmin();
        $role = $_GET['role'] ?? null;
        $filter = $role ? ['role' => $role] : [];
        $users = $this->userModel->findAll($filter, 200);
        Response::success(DocumentHelper::serializeMany($users));
    }

    /** POST /api/admin/users */
    public function createUser(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        $errors = Validator::validate($input, [
            'name'     => 'required|min:2',
            'email'    => 'required|email',
            'password' => 'required|min:8',
            'role'     => 'required|in:admin,student,staff,company,alumni,placement_officer',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        if ($this->userModel->findByEmail($input['email'])) {
            Response::error('Email already exists.', 409);
        }

        $id = $this->userModel->createUser([
            'name'     => $input['name'],
            'email'    => $input['email'],
            'password' => $input['password'],
            'role'     => $input['role'],
            'status'   => $input['status'] ?? 'active',
            'approved' => $input['approved'] ?? true,
        ]);

        if ($input['role'] === 'placement_officer') {
            if (empty($input['departmentId'])) {
                Response::error('departmentId is required when creating a placement officer.', 422);
            }
            try {
                (new PlacementOfficerModel())->createProfile($id, $input);
            } catch (\InvalidArgumentException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 422);
            } catch (\RuntimeException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 409);
            }
        }

        Response::success(['id' => $id], 'User created.', 201);
    }

    /** PUT /api/admin/users/{id} */
    public function updateUser(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        if (!$this->userModel->findById($id)) {
            Response::notFound('User not found.');
        }

        $allowed = ['name', 'email', 'password', 'role', 'status', 'approved'];
        $update = array_intersect_key($input, array_flip($allowed));
        if (empty($update)) {
            Response::error('No valid fields to update.', 422);
        }

        $this->userModel->updateUser($id, $update);
        Response::success(null, 'User updated.');
    }

    /** DELETE /api/admin/users/{id} */
    public function deleteUser(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $user = $this->userModel->findById($id);
        if (!$user) {
            Response::notFound('User not found.');
        }
        if (($user['role'] ?? '') === 'placement_officer') {
            (new PlacementOfficerModel())->deleteByUserId($id);
        }
        if (!$this->userModel->delete($id)) {
            Response::notFound('User not found.');
        }
        Response::success(null, 'User deleted.');
    }

    /** POST /api/admin/users/{id}/block */
    public function blockUser(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $this->userModel->blockUser($id);
        Response::success(null, 'User blocked.');
    }

    /** POST /api/admin/users/{id}/unblock */
    public function unblockUser(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $this->userModel->unblockUser($id);
        Response::success(null, 'User unblocked.');
    }

    /** POST /api/admin/users/{id}/approve */
    public function approveUser(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $this->userModel->approveUser($id);
        Response::success(null, 'User approved.');
    }

    /** GET /api/admin/placement-officers */
    public function listPlacementOfficers(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new PlacementOfficerModel())->listEnriched());
    }

    // --- Departments ---

    /** GET /api/admin/departments */
    public function listDepartments(): void
    {
        RBACMiddleware::requireAdmin();
        $model = new DepartmentModel();
        $officerModel = new PlacementOfficerModel();
        $userModel = $this->userModel;

        $assigned = [];
        foreach ($officerModel->findAll([], 200) as $profile) {
            $deptId = (string) ($profile['departmentId'] ?? '');
            if ($deptId === '') {
                continue;
            }
            $user = $userModel->findById((string) ($profile['userId'] ?? ''));
            $assigned[$deptId] = [
                'userId' => (string) ($profile['userId'] ?? ''),
                'name'   => $user['name'] ?? '',
                'email'  => $user['email'] ?? '',
            ];
        }

        $departments = array_map(function (array $dept) use ($assigned) {
            $id = (string) $dept['_id'];
            $serialized = DocumentHelper::serialize($dept);
            $serialized['placementOfficer'] = $assigned[$id] ?? null;
            $serialized['hasOfficer'] = isset($assigned[$id]);
            return $serialized;
        }, $model->findAll([], 100));

        Response::success($departments);
    }

    /** POST /api/admin/departments */
    public function createDepartment(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'name' => 'required',
            'code' => 'required|max:10',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $model = new DepartmentModel();
        if ($model->findByCode($input['code'])) {
            Response::error('Department code already exists.', 409);
        }

        $id = $model->createDepartment($input);
        Response::success(['id' => $id], 'Department created.', 201);
    }

    /** PUT /api/admin/departments/{id} */
    public function updateDepartment(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $model = new DepartmentModel();
        if (!$model->findById($id)) {
            Response::notFound();
        }
        $model->update($id, $input);
        Response::success(null, 'Department updated.');
    }

    /** DELETE /api/admin/departments/{id} */
    public function deleteDepartment(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if ((new PlacementOfficerModel())->findByDepartment($id)) {
            Response::error('Cannot delete department while a placement officer is assigned. Remove the officer first.', 409);
        }
        $model = new DepartmentModel();
        if (!$model->delete($id)) {
            Response::notFound();
        }
        Response::success(null, 'Department deleted.');
    }

    // --- Placement Rules ---

    /** GET /api/admin/rules */
    public function listRules(): void
    {
        RBACMiddleware::requireAdmin();
        $model = new RuleModel();
        Response::success(DocumentHelper::serializeMany($model->findAll([], 50)));
    }

    /** POST /api/admin/rules */
    public function createRule(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $id = (new RuleModel())->createRule($input);
        Response::success(['id' => $id], 'Rule created.', 201);
    }

    // --- Student Control ---

    /** POST /api/admin/students/{id}/verify-resume */
    public function verifyResume(string $studentId): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $model = new StudentModel();
        $student = $model->findById($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        $resume = $student['resume'] ?? [];
        $resume['verified'] = true;
        $model->update($studentId, ['resume' => $resume]);
        (new ApplicationWorkflowService())->onResumeVerified($studentId, (string) $admin['_id']);

        $userId = (string) ($student['userId'] ?? '');
        if ($userId) {
            (new NotificationService())->notifyUser(
                $userId,
                'resume_verified',
                'Resume Verified',
                'Your resume has been verified. Your application will proceed to placement officer review.'
            );
        }
        Response::success(null, 'Resume verified.');
    }

    /** POST /api/admin/students/{id}/blacklist */
    public function blacklistStudent(string $studentId): void
    {
        RBACMiddleware::requireAdmin();
        $user = RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $reason = $input['reason'] ?? 'Administrative action';
        (new BlacklistModel())->blacklist($studentId, $reason, (string) $user['_id']);
        Response::success(null, 'Student blacklisted.');
    }

    /** POST /api/admin/students/{id}/unblacklist */
    public function unblacklistStudent(string $studentId): void
    {
        RBACMiddleware::requireAdmin();
        (new BlacklistModel())->removeBlacklist($studentId);
        Response::success(null, 'Blacklist removed.');
    }

    // --- Reports ---

    /** POST /api/admin/reports/{type} */
    public function generateReport(string $type): void
    {
        RBACMiddleware::requireAdmin();
        $service = new ReportService();
        $filename = match ($type) {
            'student' => $service->generateStudentReport(),
            'company' => $service->generateCompanyReport(),
            'monthly' => $service->generateMonthlyReport(
                (int) ($_GET['month'] ?? date('n')),
                (int) ($_GET['year'] ?? date('Y'))
            ),
            default   => null,
        };

        if ($filename === null) {
            Response::error('Invalid report type.', 400);
        }

        // Email to management and staff
        $email = new EmailService();
        $config = require dirname(__DIR__) . '/config/app.php';
        $reportPath = $config['uploads']['reports_dir'] . '/' . $filename;
        $recipients = array_filter([
            $_ENV['MAIL_FROM'] ?? 'admin@college.edu',
            $_ENV['MAIL_STAFF'] ?? '',
        ]);
        $email->sendReportToManagement($recipients, $reportPath, ucfirst($type));

        Response::success(['filename' => $filename, 'downloadUrl' => '/backend/api/admin/reports/download/' . rawurlencode($filename)], 'Report generated.');
    }

    /** GET /api/admin/students */
    public function listStudents(): void
    {
        RBACMiddleware::requireAdmin();
        $students = (new StudentModel())->findAll([], 300);
        Response::success(DocumentHelper::serializeMany($students));
    }

    // --- Company management ---

    /** GET /api/admin/companies */
    public function listCompanies(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success(DocumentHelper::serializeMany((new CompanyModel())->findAll([], 200)));
    }

    /** POST /api/admin/companies */
    public function createCompany(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, ['companyName' => 'required']);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $id = (new CompanyModel())->createCompany($input);
        Response::success(['id' => $id], 'Company created.', 201);
    }

    /** PUT /api/admin/companies/{id} */
    public function updateCompany(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $model = new CompanyModel();
        if (!$model->findById($id)) {
            Response::notFound();
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $allowed = ['companyName', 'category', 'tier', 'contacts', 'associationStatus', 'comments', 'website', 'description'];
        $model->update($id, array_intersect_key($input, array_flip($allowed)));
        Response::success(null, 'Company updated.');
    }

    /** DELETE /api/admin/companies/{id} */
    public function deleteCompany(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new CompanyModel())->delete($id)) {
            Response::notFound();
        }
        Response::success(null, 'Company deleted.');
    }

    /** GET /api/admin/reports/download/{filename} */
    public function downloadReport(string $filename): void
    {
        RBACMiddleware::requireAdmin();
        $filename = basename($filename);
        $config = require dirname(__DIR__) . '/config/app.php';
        $path = $config['uploads']['reports_dir'] . '/' . $filename;
        if (!is_file($path)) {
            Response::notFound('Report file not found.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path);
        exit;
    }
}
