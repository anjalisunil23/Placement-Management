<?php

declare(strict_types=1);

namespace PMS\Admin;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\BlacklistModel;
use PMS\Models\NotificationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\ApplicationModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\PlacementNewsModel;
use PMS\Models\RecommendationModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\RuleModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\EmailService;
use PMS\Services\NotificationService;
use PMS\Services\OfficerDataService;
use PMS\Services\PlacementOfficerContext;
use PMS\Services\ReportContext;
use PMS\Services\ReportService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;
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

    // --- Drives (Admin manages all drives) ---

    /** GET /api/admin/drives */
    public function listDrives(): void
    {
        RBACMiddleware::requireAdmin();
        $filter = [];
        if (!empty($_GET['status'])) {
            $filter['status'] = $_GET['status'];
        }
        Response::success(DocumentHelper::serializeMany((new DriveModel())->findAll($filter, 300)));
    }

    /** POST /api/admin/drives */
    public function createDrive(): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'title'     => 'required',
            'companyId' => 'required',
            'type'      => 'required|in:exclusive,pooled,direct',
            'date'      => 'required',
            'time'      => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $id = (new DriveModel())->createDrive($input, (string) $admin['_id']);
        Response::success(['id' => $id], 'Drive created.', 201);
    }

    /** PUT /api/admin/drives/{id} */
    public function updateDrive(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $model = new DriveModel();
        if (!$model->findById($id)) {
            Response::notFound('Drive not found.');
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $allowed = ['title','companyId','type','date','time','branches','eligibility','tier','jdFile','status','departmentId'];
        $update = array_intersect_key($input, array_flip($allowed));
        $model->update($id, $update);
        Response::success(null, 'Drive updated.');
    }

    /** DELETE /api/admin/drives/{id} */
    public function deleteDrive(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new DriveModel())->delete($id)) {
            Response::notFound('Drive not found.');
        }
        Response::success(null, 'Drive deleted.');
    }

    // --- Applications (Admin can view and transition any application) ---

    /** GET /api/admin/applications */
    public function listApplications(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $filter = [];
        if (!empty($_GET['status'])) {
            $filter['status'] = $_GET['status'];
        }
        Response::success((new OfficerDataService())->listApplications($scope['ctx'], $filter));
    }

    /** POST /api/admin/applications/{id}/transition */
    public function transitionApplication(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'status' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        (new OfficerDataService())->assertApplicationInScope($appId, $scope['ctx']);
        (new ApplicationWorkflowService())->transition(
            $appId,
            (string) $input['status'],
            (string) $scope['user']['_id'],
            (string) ($input['remarks'] ?? '')
        );
        Response::success(null, 'Application updated.');
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

        if ($input['role'] === 'alumni') {
            (new AlumniModel())->createProfile($id, $input);
        }

        if ($input['role'] === 'company') {
            if (empty($input['companyName'])) {
                $this->userModel->delete($id);
                Response::error('companyName is required when creating a company recruiter.', 422);
            }
            (new CompanyModel())->createCompany([
                'userId'            => $id,
                'companyName'       => trim((string) $input['companyName']),
                'category'          => $input['category'] ?? 'Software',
                'tier'              => $input['tier'] ?? 'Tier 2',
                'associationStatus' => $input['associationStatus'] ?? 'active',
                'website'           => $input['website'] ?? '',
                'description'       => $input['description'] ?? '',
            ]);
        }

        if ($input['role'] === 'staff') {
            (new StaffModel())->createProfile($id, $input);
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

    /** PUT /api/admin/departments/{id}/placement-officer */
    public function assignPlacementOfficer(string $departmentId): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $userId = (string) ($input['userId'] ?? '');
        if ($userId === '') {
            Response::error('userId is required.', 422);
        }

        $deptModel = new DepartmentModel();
        if (!$deptModel->findById($departmentId)) {
            Response::notFound('Department not found.');
        }

        $user = $this->userModel->findById($userId);
        if (!$user) {
            Response::notFound('Placement officer user not found.');
        }
        if (($user['role'] ?? '') !== 'placement_officer') {
            Response::error('User must have role placement_officer.', 422);
        }

        $po = new PlacementOfficerModel();

        // If department already has an officer and it's not this user, replace assignment.
        $existingDept = $po->findByDepartment($departmentId);
        if ($existingDept && (string) ($existingDept['userId'] ?? '') !== (string) Security::toObjectId($userId)) {
            $po->deleteByDepartment($departmentId);
        }

        // If user already assigned to a different department, block.
        $existingUser = $po->findByUserId($userId);
        if ($existingUser && (string) ($existingUser['departmentId'] ?? '') !== (string) Security::toObjectId($departmentId)) {
            Response::error('This placement officer is already assigned to another department.', 409);
        }

        if (!$existingUser) {
            try {
                $po->createProfile($userId, ['departmentId' => $departmentId, 'designation' => $input['designation'] ?? null]);
            } catch (\Throwable $e) {
                Response::error($e->getMessage(), 422);
            }
        }

        Response::success(null, 'Placement officer assigned.');
    }

    /** DELETE /api/admin/departments/{id}/placement-officer */
    public function unassignPlacementOfficer(string $departmentId): void
    {
        RBACMiddleware::requireAdmin();
        (new PlacementOfficerModel())->deleteByDepartment($departmentId);
        Response::success(null, 'Placement officer unassigned.');
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

    /** PUT /api/admin/rules/active */
    public function saveActiveRule(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $rule = (new RuleModel())->saveActiveRule($input);
        Response::success(DocumentHelper::serialize($rule), 'Placement rules saved.');
    }

    /** GET /api/admin/rules/active */
    public function getActiveRule(): void
    {
        RBACMiddleware::requireAdmin();
        $rule = (new RuleModel())->getActiveRule();
        Response::success($rule ? DocumentHelper::serialize($rule) : null);
    }

    // --- Student Control ---

    /** POST /api/admin/students/{id}/verify-resume */
    public function verifyResume(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        PlacementOfficerContext::assertStudentInDepartment($studentId, $scope['ctx']);

        $model = new StudentModel();
        $student = $model->findById($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        $resume = $student['resume'] ?? [];
        $resume['verified'] = true;
        $model->update($studentId, ['resume' => $resume]);
        (new ApplicationWorkflowService())->onResumeVerified($studentId, (string) $scope['user']['_id']);

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

    /** GET /api/admin/reports */
    public function listReports(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? ($_GET['departmentId'] ?? null) : $scope['ctx']['departmentId'];
        $service = new ReportService();
        Response::success($service->listHistory($deptId ? (string) $deptId : null));
    }

    /** POST /api/admin/reports/{type} */
    public function generateReport(string $type): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        $forcedDept = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        $ctx = ReportContext::fromInput($input, $forcedDept, (string) $scope['user']['_id']);

        if (!empty($input['dateFrom'])) {
            $parts = explode('-', (string) $input['dateFrom']);
            if (count($parts) >= 2) {
                $ctx->month = (int) $parts[1];
                $ctx->year = (int) $parts[0];
            }
        }

        try {
            $result = (new ReportService())->generate($type, $ctx);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }

        if (!empty($input['email'])) {
            $email = new EmailService();
            $config = require dirname(__DIR__) . '/config/app.php';
            $reportPath = $config['uploads']['reports_dir'] . '/' . $result['filename'];
            $recipients = array_filter([
                $_ENV['MAIL_FROM'] ?? 'admin@college.edu',
                $_ENV['MAIL_STAFF'] ?? '',
                $scope['user']['email'] ?? '',
            ]);
            $email->sendReportToManagement($recipients, $reportPath, $result['title']);
        }

        Response::success($result, 'Report generated.');
    }

    /** GET /api/admin/students */
    public function listStudents(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listStudents($scope['ctx']));
    }

    /** GET /api/admin/blacklist */
    public function listBlacklist(): void
    {
        RBACMiddleware::requireAdmin();
        $bl = new BlacklistModel();
        $studentModel = new StudentModel();
        $userModel = new UserModel();

        $rows = [];
        foreach ($bl->active(500) as $r) {
            $studentId = (string) ($r['studentId'] ?? '');
            $student = $studentId ? $studentModel->findById($studentId) : null;
            $userId = $student ? (string) ($student['userId'] ?? '') : '';
            $user = $userId ? $userModel->findById($userId) : null;
            $row = DocumentHelper::serialize($r) ?? [];
            $row['student'] = $student ? DocumentHelper::serialize($student) : null;
            $row['user'] = $user ? DocumentHelper::serialize($user) : null;
            $rows[] = $row;
        }
        Response::success($rows);
    }

    // --- Recruitment Results ---

    /** GET /api/admin/results */
    public function listResults(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $filter = [];
        if (!empty($_GET['status'])) {
            $filter['status'] = $_GET['status'];
        }
        if (!empty($_GET['registerNumber'])) {
            $filter['registerNumber'] = strtoupper(trim((string) $_GET['registerNumber']));
        }
        Response::success((new OfficerDataService())->listResults($scope['ctx'], $filter));
    }

    /** POST /api/admin/results */
    public function upsertResult(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        $errors = Validator::validate($input, [
            'studentName'    => 'required|min:2',
            'registerNumber' => 'required',
            'company'        => 'required',
            'role'           => 'required',
            'status'         => 'required|in:selected,rejected',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        (new OfficerDataService())->assertResultRegisterInScope((string) $input['registerNumber'], $scope['ctx']);
        if (!$scope['ctx']['isAdmin'] && !empty($scope['ctx']['departmentId'])) {
            $input['departmentId'] = $scope['ctx']['departmentId'];
        }

        try {
            $id = (new RecruitmentResultModel())->upsertByRegisterCompany($input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success(['id' => $id], 'Result saved.');
    }

    /** DELETE /api/admin/results/{id} */
    public function deleteResult(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new RecruitmentResultModel())->delete($id)) {
            Response::notFound();
        }
        Response::success(null, 'Result deleted.');
    }

    // --- Company management ---

    /** GET /api/admin/companies */
    public function listCompanies(): void
    {
        RBACMiddleware::requirePlacementOfficer();
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

    // --- Staff recommendations & company registration ---

    /** GET /api/admin/recommendations */
    public function listRecommendations(): void
    {
        RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        Response::success((new RecommendationModel())->listEnriched());
    }

    /** PUT /api/admin/recommendations/{id}/status */
    public function updateRecommendationStatus(string $id): void
    {
        RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $status = (string) ($input['status'] ?? '');
        if (!(new RecommendationModel())->updateStatus($id, $status)) {
            Response::error('Invalid status or recommendation not found.', 422);
        }
        Response::success(null, 'Recommendation status updated.');
    }

    /** POST /api/admin/companies/register */
    public function registerCompany(): void
    {
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'companyName'   => 'required',
            'hrName'        => 'required',
            'hrEmail'       => 'required|email',
            'contactNumber' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $companyId = (new CompanyModel())->createCompany([
            'companyName'       => $input['companyName'],
            'website'           => $input['companyWebsite'] ?? '',
            'category'          => $input['category'] ?? 'Software',
            'tier'              => $input['tier'] ?? 'Tier 2',
            'contacts'          => [[
                'name'  => $input['hrName'],
                'email' => $input['hrEmail'],
                'phone' => $input['contactNumber'],
            ]],
            'associationStatus' => 'active',
        ]);

        if (!empty($input['sourceRecommendationId'])) {
            (new RecommendationModel())->updateStatus((string) $input['sourceRecommendationId'], 'registered');
        }

        Response::success(['id' => $companyId], 'Company registered.', 201);
    }

    /** GET /api/admin/alumni-referrals */
    public function listAlumniReferrals(): void
    {
        RBACMiddleware::requireAdmin();
        $userModel = $this->userModel;
        $rows = [];
        foreach ((new AlumniReferralModel())->findAll([], 200) as $ref) {
            $user = $userModel->findById((string) ($ref['alumniUserId'] ?? ''));
            $row = DocumentHelper::serialize($ref) ?? [];
            $row['alumniName'] = $user['name'] ?? '';
            $row['alumniEmail'] = $user['email'] ?? '';
            $rows[] = $row;
        }
        Response::success($rows);
    }

    /** GET /api/admin/resumes/pending */
    public function listPendingResumes(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listPendingResumes($scope['ctx']));
    }

    /** POST /api/admin/blacklist */
    public function addBlacklist(): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $registerNumber = strtoupper(trim((string) ($input['registerNumber'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($registerNumber === '' || $reason === '') {
            Response::error('registerNumber and reason are required.', 422);
        }

        $student = (new StudentModel())->findByRegisterNumber($registerNumber);
        if (!$student) {
            Response::notFound('Student not found for register number.');
        }

        (new BlacklistModel())->blacklist((string) $student['_id'], $reason, (string) $admin['_id']);
        Response::success(null, 'Student blacklisted.', 201);
    }

    /** DELETE /api/admin/blacklist/{studentId} */
    public function removeBlacklistEntry(string $studentId): void
    {
        RBACMiddleware::requireAdmin();
        (new BlacklistModel())->removeBlacklist($studentId);
        Response::success(null, 'Student removed from blacklist.');
    }

    /** GET /api/admin/reports/download/{filename} */
    public function downloadReport(string $filename): void
    {
        (new OfficerDataService())->requireScope();
        $filename = basename($filename);
        if (!preg_match('/^[a-z0-9_\-]+\.(pdf|csv)$/i', $filename)) {
            Response::error('Invalid filename.', 400);
        }
        $config = require dirname(__DIR__) . '/config/app.php';
        $path = $config['uploads']['reports_dir'] . '/' . $filename;
        if (!is_file($path)) {
            Response::notFound('Report file not found.');
        }
        $mime = str_ends_with(strtolower($filename), '.csv')
            ? 'text/csv'
            : 'application/pdf';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    // --- System settings, public page content, placement news ---

    /** GET /api/admin/settings/system */
    public function getSystemSettings(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new SystemSettingsModel())->get());
    }

    /** PUT /api/admin/settings/system */
    public function updateSystemSettings(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'placementYear' => 'required',
            'emailFrom'     => 'required|email',
            'maxUploadMb'   => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $saved = (new SystemSettingsModel())->save($input);
        Response::success($saved, 'System settings saved.');
    }

    /** GET /api/admin/settings/public */
    public function getPublicPageSettings(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new PublicPageContentModel())->get());
    }

    /** PUT /api/admin/settings/public */
    public function updatePublicPageSettings(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $saved = (new PublicPageContentModel())->save($input);
        Response::success($saved, 'Public page content saved.');
    }

    /** GET /api/admin/placement-news */
    public function listPlacementNews(): void
    {
        RBACMiddleware::requireAdmin();
        $news = (new PlacementNewsModel())->published(100);
        Response::success(DocumentHelper::serializeMany($news));
    }

    /** POST /api/admin/placement-news */
    public function createPlacementNews(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'title'   => 'required',
            'summary' => 'required',
            'date'    => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $id = (new PlacementNewsModel())->createNews($input);
        Response::success(['id' => $id], 'Placement news added.', 201);
    }

    /** PUT /api/admin/placement-news/{id} */
    public function updatePlacementNews(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $model = new PlacementNewsModel();
        if (!$model->findById($id)) {
            Response::notFound('News item not found.');
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'title'   => 'required',
            'summary' => 'required',
            'date'    => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $model->updateNews($id, $input);
        Response::success(null, 'Placement news updated.');
    }

    /** DELETE /api/admin/placement-news/{id} */
    public function deletePlacementNews(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new PlacementNewsModel())->delete($id)) {
            Response::notFound('News item not found.');
        }
        Response::success(null, 'Placement news removed.');
    }

    /** GET /api/admin/notifications */
    public function notifications(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($notifs));
    }

    /** POST /api/admin/notifications/{id}/read */
    public function markNotificationRead(string $id): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $notif = (new NotificationModel())->findById($id);
        if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
            Response::notFound();
        }
        (new NotificationModel())->markRead($id);
        Response::success(null, 'Notification marked as read.');
    }

    /** POST /api/admin/notifications/read-all */
    public function markAllNotificationsRead(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $count = (new NotificationModel())->markAllRead((string) $user['_id']);
        Response::success(['updated' => $count], 'All notifications marked as read.');
    }
}
