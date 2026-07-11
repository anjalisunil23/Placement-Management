<?php

declare(strict_types=1);

namespace PMS\Admin;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\BlacklistModel;
use PMS\Models\BroadcastLogModel;
use PMS\Models\NotificationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\ApplicationModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\PlacementNewsModel;
use PMS\Models\RecommendationModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\EmailService;
use PMS\Services\NotificationService;
use PMS\Services\OfficerDataService;
use PMS\Services\RecruitmentResultService;
use PMS\Services\SelfPlacementService;
use PMS\Services\AnalyticsService;
use PMS\Services\RecruitingService;
use PMS\Services\TrackingService;
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
        Response::success((new OfficerDataService())->enrichDrivesWithCompany((new DriveModel())->findAll($filter, 300)));
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
        if (isset($input['eligibility']) && is_string($input['eligibility'])) {
            $input['eligibility'] = json_decode($input['eligibility'], true) ?? [];
        }
        if (isset($input['branches']) && is_string($input['branches'])) {
            $input['branches'] = json_decode($input['branches'], true) ?? [];
        }
        $companyModel = new CompanyModel();
        $companyId = (string) ($input['companyId'] ?? '');
        if ($companyId === '' || !$companyModel->findById($companyId)) {
            Response::error('A valid registered company is required for this drive.', 422);
        }
        $id = (new DriveModel())->createDrive($input, (string) $admin['_id']);
        (new NotificationService())->announceDrive(
            (string) $input['title'],
            (string) $input['date']
        );
        Response::success(['id' => $id], 'Drive created.', 201);
    }

    /** PUT /api/admin/drives/{id} */
    public function updateDrive(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $model = new DriveModel();
        $drive = $model->findById($id);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (isset($input['eligibility']) && is_string($input['eligibility'])) {
            $input['eligibility'] = json_decode($input['eligibility'], true) ?? [];
        }
        if (isset($input['branches']) && is_string($input['branches'])) {
            $input['branches'] = json_decode($input['branches'], true) ?? [];
        }
        $allowed = ['title','companyId','type','date','time','branches','eligibility','tier','jdFile','status','departmentId'];
        $update = array_intersect_key($input, array_flip($allowed));
        if (isset($update['eligibility']) && is_array($update['eligibility'])) {
            $update['eligibility'] = array_merge($drive['eligibility'] ?? [], $update['eligibility']);
        }
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
        if (!empty($_GET['driveId'])) {
            $driveOid = Security::toObjectId((string) $_GET['driveId']);
            if ($driveOid) {
                $filter['driveId'] = $driveOid;
            }
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

    /** GET /api/admin/applications/{id}/resume */
    public function downloadApplicationResume(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamApplicationResume($appId, $scope['ctx']);
    }

    /** GET /api/admin/users */
    public function listUsers(): void
    {
        RBACMiddleware::requireAdmin();
        $role = $_GET['role'] ?? null;
        $filter = $role ? ['role' => $role] : [];
        $users = $this->userModel->findAll($filter, 200);
        $enriched = array_map(fn (array $u) => $this->enrichUserRow($u), $users);
        Response::success(DocumentHelper::serializeMany($enriched));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function enrichUserRow(array $user): array
    {
        $row = $user;
        $userId = (string) ($user['_id'] ?? '');

        if (($user['role'] ?? '') === 'staff') {
            $profile = (new StaffModel())->findByUserId($userId);
            if ($profile) {
                $dept = (new DepartmentModel())->findById((string) ($profile['departmentId'] ?? ''));
                $row['departmentId'] = (string) ($profile['departmentId'] ?? '');
                $row['department'] = $dept['code'] ?? $dept['name'] ?? '';
                $row['designation'] = $profile['designation'] ?? '';
            }
        }

        if (($user['role'] ?? '') === 'placement_officer') {
            $profile = (new PlacementOfficerModel())->findByUserId($userId);
            if ($profile) {
                $dept = (new DepartmentModel())->findById((string) ($profile['departmentId'] ?? ''));
                $row['departmentId'] = (string) ($profile['departmentId'] ?? '');
                $row['department'] = $dept['code'] ?? $dept['name'] ?? '';
                $row['designation'] = $profile['designation'] ?? '';
            }
        }

        if (($user['role'] ?? '') === 'alumni') {
            $profile = (new AlumniModel())->findByUserId($userId);
            if ($profile) {
                $row['company'] = $profile['company'] ?? '';
                $row['alumniRole'] = $profile['role'] ?? '';
            }
        }

        if (($user['role'] ?? '') === 'company') {
            $profile = (new CompanyModel())->findByUserId($userId);
            if ($profile) {
                $contact = is_array($profile['contacts'] ?? null) ? ($profile['contacts'][0] ?? []) : [];
                $row['companyId'] = (string) ($profile['_id'] ?? '');
                $row['companyName'] = $profile['companyName'] ?? '';
                $row['category'] = $profile['category'] ?? '';
                $row['tier'] = $profile['tier'] ?? '';
                $row['associationStatus'] = $profile['associationStatus'] ?? '';
                $row['phone'] = $contact['phone'] ?? '';
            }
        }

        return $row;
    }

    /** POST /api/admin/users */
    public function createUser(): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (isset($input['email'])) {
            $input['email'] = strtolower(trim((string) $input['email']));
        }

        $errors = Validator::validate($input, [
            'name'     => 'required|min:2',
            'email'    => 'required|email',
            'password' => 'required|min:8',
            'role'     => 'required|in:admin,student,staff,company,alumni,placement_officer',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $existing = $this->userModel->findByEmail($input['email']);
        if ($existing) {
            if (
                ($input['role'] ?? '') === 'placement_officer'
                && in_array((string) ($existing['role'] ?? ''), ['staff', 'placement_officer'], true)
            ) {
                $id = (string) $existing['_id'];
                $this->assertValidPlacementOfficerDepartment($input);
                $this->userModel->updateUser($id, [
                    'name'     => $input['name'],
                    'password' => $input['password'],
                    'role'     => 'placement_officer',
                    'status'   => 'active',
                    'approved' => true,
                ]);
                try {
                    $this->assignPlacementOfficerProfile($id, $input);
                } catch (\InvalidArgumentException $e) {
                    Response::error($e->getMessage(), 422);
                } catch (\RuntimeException $e) {
                    Response::error($e->getMessage(), 409);
                }
                Response::success(['id' => $id], 'Placement officer updated.', 200);
            }
            Response::error('Email already exists.', 409);
        }

        if (($input['role'] ?? '') === 'placement_officer') {
            $this->assertValidPlacementOfficerDepartment($input);
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
            try {
                $this->assignPlacementOfficerProfile($id, $input);
            } catch (\InvalidArgumentException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 422);
            } catch (\RuntimeException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 409);
            }
        }

        if ($input['role'] === 'staff') {
            if (empty($input['departmentId'])) {
                Response::error('departmentId is required when creating staff.', 422);
            }
            try {
                (new StaffModel())->createProfile($id, $input);
            } catch (\InvalidArgumentException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 422);
            }
        }

        if ($input['role'] === 'alumni') {
            try {
                (new AlumniModel())->createProfile($id, $input);
            } catch (\InvalidArgumentException $e) {
                $this->userModel->delete($id);
                Response::error($e->getMessage(), 422);
            }
        }

        if ($input['role'] === 'company') {
            if (empty($input['companyName'])) {
                $this->userModel->delete($id);
                Response::error('companyName is required when creating a company user.', 422);
            }
            (new CompanyModel())->createCompany([
                'userId'            => $id,
                'companyName'       => trim((string) $input['companyName']),
                'category'          => $input['category'] ?? 'Software',
                'tier'              => $input['tier'] ?? 'Tier 2',
                'website'           => $input['website'] ?? $input['companyWebsite'] ?? '',
                'description'       => $input['description'] ?? '',
                'contacts'          => [[
                    'name'  => $input['name'],
                    'email' => $input['email'],
                    'phone' => $input['phone'] ?? $input['contactNumber'] ?? '',
                ]],
                'associationStatus' => $input['associationStatus'] ?? 'active',
            ]);
        }

        Response::success(['id' => $id], 'User created.', 201);
    }

    /** POST /api/admin/users/{id}/promote-to-officer */
    public function promoteStaffToPlacementOfficer(string $userId): void
    {
        RBACMiddleware::requireAdmin();
        $user = $this->userModel->findById($userId);
        if (!$user) {
            Response::notFound('User not found.');
        }

        $role = (string) ($user['role'] ?? '');
        if ($role === 'placement_officer') {
            Response::success(['id' => $userId], 'User is already a placement officer.');
        }
        if ($role !== 'staff') {
            Response::error('Only staff members in a department can be assigned as placement officers.', 422);
        }

        $staffProfile = (new StaffModel())->findByUserId($userId);
        $departmentId = (string) ($staffProfile['departmentId'] ?? '');
        if ($staffProfile === null || $departmentId === '') {
            Response::error('Staff member must be assigned to a department first.', 422);
        }
        if (!(new DepartmentModel())->findById($departmentId)) {
            Response::error('Staff department not found.', 422);
        }

        $this->userModel->updateUser($userId, [
            'role'     => 'placement_officer',
            'status'   => 'active',
            'approved' => true,
        ]);

        try {
            $this->assignPlacementOfficerProfile($userId, [
                'departmentId' => $departmentId,
                'designation'  => $staffProfile['designation'] ?? 'Placement Officer',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->userModel->updateUser($userId, ['role' => 'staff']);
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->userModel->updateUser($userId, ['role' => 'staff']);
            Response::error($e->getMessage(), 409);
        }

        Response::success(
            ['id' => $userId, 'departmentId' => $departmentId],
            'Staff member assigned as placement officer.'
        );
    }

    /** POST /api/admin/users/{id}/demote-from-officer */
    public function demotePlacementOfficer(string $userId): void
    {
        RBACMiddleware::requireAdmin();
        $user = $this->userModel->findById($userId);
        if (!$user) {
            Response::notFound('User not found.');
        }
        if (($user['role'] ?? '') !== 'placement_officer') {
            Response::error('User is not a placement officer.', 422);
        }

        $this->demotePlacementOfficerToStaff($userId);
        Response::success(['id' => $userId], 'Placement officer role removed.');
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
        if (($user['role'] ?? '') === 'staff') {
            (new StaffModel())->deleteByUserId($id);
        }
        if (($user['role'] ?? '') === 'alumni') {
            (new AlumniModel())->deleteByUserId($id);
        }
        if (($user['role'] ?? '') === 'company') {
            (new CompanyModel())->deleteByUserId($id);
        }
        if (($user['role'] ?? '') === 'student') {
            (new StudentModel())->deleteByUserId($id);
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
            Response::notFound('User not found.');
        }

        $role = (string) ($user['role'] ?? '');
        $staffProfile = null;
        if ($role === 'staff') {
            $staffProfile = (new StaffModel())->findByUserId($userId);
            $staffDeptId = (string) ($staffProfile['departmentId'] ?? '');
            if ($staffProfile === null || $staffDeptId === '') {
                Response::error('Staff member must be assigned to a department first.', 422);
            }
            if ((string) Security::toObjectId($staffDeptId) !== (string) Security::toObjectId($departmentId)) {
                Response::error('Staff member must belong to this department.', 422);
            }
            $this->userModel->updateUser($userId, [
                'role'     => 'placement_officer',
                'status'   => 'active',
                'approved' => true,
            ]);
            try {
                $this->assignPlacementOfficerProfile($userId, [
                    'departmentId' => $departmentId,
                    'designation'  => $input['designation'] ?? ($staffProfile['designation'] ?? 'Placement Officer'),
                ]);
            } catch (\InvalidArgumentException $e) {
                $this->userModel->updateUser($userId, ['role' => 'staff']);
                Response::error($e->getMessage(), 422);
            } catch (\RuntimeException $e) {
                $this->userModel->updateUser($userId, ['role' => 'staff']);
                Response::error($e->getMessage(), 409);
            }
            Response::success(null, 'Placement officer assigned.');
        }
        if ($role !== 'placement_officer') {
            Response::error('User must be staff or a placement officer.', 422);
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
        $poModel = new PlacementOfficerModel();
        $existing = $poModel->findByDepartment($departmentId);
        if ($existing) {
            $userId = (string) ($existing['userId'] ?? '');
            $poModel->deleteByDepartment($departmentId);
            if ($userId !== '') {
                $this->demotePlacementOfficerToStaff($userId, $departmentId);
            }
        }
        Response::success(null, 'Placement officer unassigned.');
    }

    // --- Departments ---

    /** GET /api/admin/departments */
    public function listDepartments(): void
    {
        RBACMiddleware::requireAdmin();
        try {
            (new \PMS\Services\AesApiService())->syncDepartmentsToLocal();
        } catch (\Throwable) {
            // Serve local departments when AES API is unreachable.
        }

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
        $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        Response::success((new OfficerDataService())->listStudents($scope['ctx'], $query !== '' ? $query : null));
    }

    /** GET /api/admin/students/{id}/profile */
    public function studentProfile(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $register = trim((string) ($_GET['registerNumber'] ?? ''));
        Response::success((new OfficerDataService())->getStudentOverview(
            $studentId,
            $scope['ctx'],
            'admin',
            $register !== '' ? $register : null
        ));
    }

    /** GET /api/admin/students/{id}/photo */
    public function studentPhoto(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamStudentPhoto($studentId, $scope['ctx']);
    }

    /** GET /api/admin/students/{id}/pipeline */
    public function studentPipeline(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->studentPipelineForScope($studentId, $scope['ctx']));
    }

    /** GET /api/admin/students/{id}/self-placement */
    public function getSelfPlacement(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new SelfPlacementService())->getReport($studentId, $scope['ctx']));
    }

    /** POST /api/admin/students/{id}/self-placement — record self-placement and mark placed */
    public function createSelfPlacement(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);
        $result = (new SelfPlacementService())->createForStudent($studentId, $scope['ctx'], $scope['user'], is_array($input) ? $input : []);
        Response::success($result, 'Self-placement recorded and student marked as placed.', 201);
    }

    /** GET /api/admin/students/{id}/self-placement/offer-letter */
    public function downloadSelfPlacementOfferLetter(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new SelfPlacementService())->streamOfferLetter($studentId, $scope['ctx']);
    }

    /** GET /api/admin/students/{id}/self-placement/company-id */
    public function downloadSelfPlacementCompanyId(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new SelfPlacementService())->streamCompanyIdDoc($studentId, $scope['ctx']);
    }

    /** GET /api/admin/students/{id}/self-placement/salary-slip */
    public function downloadSelfPlacementSalarySlip(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new SelfPlacementService())->streamSalarySlip($studentId, $scope['ctx']);
    }

    /** POST /api/admin/students/{id}/self-placement/approve */
    public function approveSelfPlacement(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $result = (new SelfPlacementService())->approve($studentId, $scope['ctx'], $scope['user']);
        Response::success($result, 'Placement verified and student marked as placed.');
    }

    /** POST /api/admin/students/{id}/self-placement/reject */
    public function rejectSelfPlacement(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $reason = trim((string) ($input['reason'] ?? ''));
        $result = (new SelfPlacementService())->reject($studentId, $scope['ctx'], $scope['user'], $reason);
        Response::success($result, 'Placement report rejected.');
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
        $filter = (new OfficerDataService())->resultFilterFromRequest();
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

        (new RecruitmentResultService())->syncAfterSave($input, $id, (string) $scope['user']['_id']);
        Response::success(['id' => $id], 'Result saved.');
    }

    /** DELETE /api/admin/results/{id} */
    public function deleteResult(string $id): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $result = (new RecruitmentResultModel())->findById($id);
        if (!$result) {
            Response::notFound();
        }
        if (!$scope['ctx']['isAdmin']) {
            (new OfficerDataService())->assertResultRegisterInScope(
                (string) ($result['registerNumber'] ?? ''),
                $scope['ctx']
            );
        }
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
        Response::success(DocumentHelper::serializeMany((new CompanyModel())->listEnriched(200)));
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
        $model = new CompanyModel();
        $company = $model->findById($id);
        if (!$company) {
            Response::notFound();
        }
        $userId = (string) ($company['userId'] ?? '');
        $model->delete($id);
        if ($userId !== '') {
            $this->userModel->delete($userId);
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
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $status = (string) ($input['status'] ?? '');
        if (!(new RecommendationModel())->updateStatus($id, $status)) {
            Response::error('Invalid status or recommendation not found.', 422);
        }
        Response::success(null, 'Recommendation status updated.');
    }

    /** PUT /api/admin/recommendations/{id} */
    public function updateRecommendation(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!(new RecommendationModel())->updateRecommendation($id, $input)) {
            Response::error('Recommendation not found or invalid data.', 422);
        }
        Response::success(null, 'Recommendation updated.');
    }

    /** DELETE /api/admin/recommendations/{id} */
    public function deleteRecommendation(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new RecommendationModel())->deleteRecommendation($id)) {
            Response::notFound('Recommendation not found.');
        }
        Response::success(null, 'Recommendation deleted.');
    }

    /** POST /api/admin/companies/register */
    public function registerCompany(): void
    {
        RBACMiddleware::requireAdmin();
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
        RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        Response::success((new AlumniReferralModel())->listEnriched());
    }

    /** PUT /api/admin/alumni-referrals/{id}/status */
    public function updateAlumniReferralStatus(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $status = (string) ($input['status'] ?? '');
        if (!(new AlumniReferralModel())->updateStatus($id, $status)) {
            Response::error('Invalid status or alumni recommendation not found.', 422);
        }
        Response::success(null, 'Alumni recommendation status updated.');
    }

    /** PUT /api/admin/alumni-referrals/{id} */
    public function updateAlumniReferral(string $id): void
    {
        RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!(new AlumniReferralModel())->updateReferral($id, $input)) {
            Response::error('Alumni recommendation not found or invalid data.', 422);
        }
        Response::success(null, 'Alumni recommendation updated.');
    }

    /** DELETE /api/admin/alumni-referrals/{id} */
    public function deleteAlumniReferral(string $id): void
    {
        RBACMiddleware::requireAdmin();
        if (!(new AlumniReferralModel())->deleteReferral($id)) {
            Response::notFound('Alumni recommendation not found.');
        }
        Response::success(null, 'Alumni recommendation deleted.');
    }

    /** GET /api/admin/resumes/pending */
    public function listPendingResumes(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listPendingResumes($scope['ctx']));
    }

    /** GET /api/admin/resumes */
    public function listResumes(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listResumeQueue($scope['ctx']));
    }

    /** GET /api/admin/students/{id}/resume */
    public function downloadStudentResume(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamStudentResume($studentId, $scope['ctx']);
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

    /** POST /api/admin/broadcast */
    public function broadcast(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'title'   => 'required',
            'message' => 'required',
            'audience'=> 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $audience = (string) $input['audience'];
        $departmentId = !empty($input['departmentId']) ? (string) $input['departmentId'] : null;
        $sendEmail = array_key_exists('sendEmail', $input) ? (bool) $input['sendEmail'] : true;
        $role = (string) ($user['role'] ?? '');

        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            if (!$ctx['isAdmin']) {
                if (!in_array($audience, ['students'], true)) {
                    Response::error('Placement officers can only broadcast to students in their department.', 403);
                }
                $departmentId = $ctx['departmentId'];
            }
        } elseif ($role === 'admin' && $audience === 'students' && $departmentId) {
            // Admin may target a specific department.
        } elseif ($role === 'admin' && $audience === 'everyone') {
            // Admin-only audience.
        } elseif ($role === 'admin') {
            // Other admin audiences allowed.
        }

        if ($role !== 'admin' && in_array($audience, ['everyone', 'officers', 'companies'], true)) {
            Response::error('You do not have permission to broadcast to this audience.', 403);
        }

        $result = (new NotificationService())->broadcast(
            $user,
            trim((string) $input['title']),
            trim((string) $input['message']),
            $audience,
            $departmentId,
            $sendEmail
        );

        Response::success($result, "Broadcast sent to {$result['recipientCount']} recipient(s).", 201);
    }

    /** GET /api/admin/broadcasts */
    public function listBroadcasts(): void
    {
        RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $rows = (new BroadcastLogModel())->recent(100);
        Response::success(DocumentHelper::serializeMany($rows));
    }

    /** GET /api/admin/tracking */
    public function placementTracking(): void
    {
        RBACMiddleware::requireAdmin();
        $limit = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 100;
        Response::success((new TrackingService())->getOverviewForContext([
            'isAdmin'      => true,
            'departmentId' => null,
            'department'   => null,
            'profile'      => null,
        ], $limit));
    }

    /** GET /api/admin/analytics/extended */
    public function extendedAnalytics(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new AnalyticsService())->getExtendedAnalytics(null));
    }

    /** GET /api/admin/placement-console */
    public function placementConsole(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new AnalyticsService())->getPlacementConsole(null));
    }

    /** GET /api/admin/recruiting */
    public function recruitingOverview(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new RecruitingService())->getCampusOverview(null));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assertValidPlacementOfficerDepartment(array $input): void
    {
        $deptId = (string) ($input['departmentId'] ?? '');
        if ($deptId === '' || Security::toObjectId($deptId) === null) {
            Response::error('A valid department is required when creating a placement officer.', 422);
        }
        if (!(new DepartmentModel())->findById($deptId)) {
            Response::error('Department not found. Refresh the page and select a department from the list.', 422);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assignPlacementOfficerProfile(string $userId, array $input): void
    {
        $deptId = (string) ($input['departmentId'] ?? '');
        if ($deptId === '') {
            throw new \InvalidArgumentException('departmentId is required when creating a placement officer.');
        }

        $poModel = new PlacementOfficerModel();
        $existingDept = $poModel->findByDepartment($deptId);
        if ($existingDept && (string) ($existingDept['userId'] ?? '') !== (string) Security::toObjectId($userId)) {
            $replacedUserId = (string) ($existingDept['userId'] ?? '');
            $poModel->deleteByDepartment($deptId);
            if ($replacedUserId !== '') {
                $this->demotePlacementOfficerToStaff($replacedUserId, $deptId);
            }
        }

        if (!$poModel->findByUserId($userId)) {
            $poModel->createProfile($userId, $input);
        }
    }

    private function demotePlacementOfficerToStaff(string $userId, string $departmentId = ''): void
    {
        $user = $this->userModel->findById($userId);
        if ($user === null || ($user['role'] ?? '') !== 'placement_officer') {
            return;
        }

        $poModel = new PlacementOfficerModel();
        $profile = $poModel->findByUserId($userId);
        $deptId = $departmentId !== ''
            ? $departmentId
            : (string) ($profile['departmentId'] ?? '');
        if ($profile !== null) {
            $poModel->deleteByUserId($userId);
        }

        $staffModel = new StaffModel();
        if ($staffModel->findByUserId($userId) === null && $deptId !== '') {
            try {
                $staffModel->createProfile($userId, [
                    'departmentId' => $deptId,
                    'designation'  => 'Staff',
                ]);
            } catch (\Throwable) {
                // Profile may already exist from a concurrent update.
            }
        }

        $this->userModel->updateUser($userId, [
            'role'     => 'staff',
            'status'   => 'active',
            'approved' => true,
        ]);
    }
}
