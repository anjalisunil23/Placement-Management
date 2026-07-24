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
use PMS\Models\ReportModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\AesLoginService;
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
use PMS\Services\ObjectStorageService;
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
        RBACMiddleware::requirePlacementDataViewer();
        $lite = isset($_GET['lite']) && (string) $_GET['lite'] !== '0' && (string) $_GET['lite'] !== '';
        Response::success($this->userModel->getDashboardStats(!$lite));
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
        if (array_key_exists('selectionRounds', $input)) {
            $input['selectionRounds'] = DriveModel::normalizeSelectionRounds($input['selectionRounds']);
        }
        if (array_key_exists('roundProgression', $input)) {
            $input['roundProgression'] = DriveModel::normalizeRoundProgression($input['roundProgression']);
        }
        if (array_key_exists('applicationFields', $input)) {
            $input['applicationFields'] = DriveModel::normalizeApplicationFields($input['applicationFields']);
        }
        $companyModel = new CompanyModel();
        $companyId = (string) ($input['companyId'] ?? '');
        if ($companyId === '' || !$companyModel->findById($companyId)) {
            Response::error('A valid registered company is required for this drive.', 422);
        }
        $dup = (new DriveModel())->findDuplicateDrive(
            $companyId,
            (string) ($input['title'] ?? ''),
            (string) ($input['date'] ?? ''),
            null,
            (string) ($input['departmentId'] ?? '')
        );
        if ($dup !== null) {
            Response::error(
                'A drive for this company, role, and date already exists for this department.',
                409,
                ['existingId' => (string) ($dup['_id'] ?? '')]
            );
        }
        $id = (new DriveModel())->createDrive($input, (string) $admin['_id']);
        (new NotificationService())->announceDrive(
            (string) $input['title'],
            (string) ($input['date'] ?? '')
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
        $allowed = ['title','companyId','type','date','time','branches','eligibility','selectionRounds','roundProgression','applicationFields','tier','jdFile','status','departmentId'];
        $update = array_intersect_key($input, array_flip($allowed));
        if (isset($update['eligibility']) && is_array($update['eligibility'])) {
            $update['eligibility'] = array_merge($drive['eligibility'] ?? [], $update['eligibility']);
        }
        if (array_key_exists('selectionRounds', $update)) {
            $update['selectionRounds'] = DriveModel::normalizeSelectionRounds($update['selectionRounds']);
        }
        if (array_key_exists('roundProgression', $update)) {
            $update['roundProgression'] = DriveModel::normalizeRoundProgression($update['roundProgression']);
        }
        if (array_key_exists('applicationFields', $update)) {
            $update['applicationFields'] = DriveModel::normalizeApplicationFields($update['applicationFields']);
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

    /** POST /api/admin/drives/{id}/shortlist-upload */
    public function uploadDriveShortlist(string $id): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $csvContent = '';
        if (isset($_FILES['csv']) && ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $tmp = (string) ($_FILES['csv']['tmp_name'] ?? '');
            if ($tmp !== '' && is_readable($tmp)) {
                $csvContent = (string) file_get_contents($tmp);
            }
        } elseif (isset($_POST['csvText'])) {
            $csvContent = (string) $_POST['csvText'];
        }

        $registerList = (string) ($_POST['registerNumbers'] ?? '');
        $document = isset($_FILES['document']) ? $_FILES['document'] : null;

        $result = (new \PMS\Services\DriveShortlistService())->upload(
            $id,
            $scope['ctx'],
            (string) $scope['user']['_id'],
            is_array($document) ? $document : null,
            $csvContent,
            $registerList
        );

        $parts = [];
        if (!empty($result['documentSaved'])) {
            $parts[] = 'Shortlist document saved.';
        }
        if (!empty($result['updated'])) {
            $parts[] = (int) $result['updated'] . ' student(s) marked shortlisted.';
        }
        if (!empty($result['alreadyShortlisted'])) {
            $parts[] = (int) $result['alreadyShortlisted'] . ' already shortlisted.';
        }
        if ($parts === []) {
            $parts[] = 'Shortlist document saved.';
        }
        Response::success($result, implode(' ', $parts));
    }

    /** GET /api/admin/drives/{id}/shortlist-document */
    public function downloadDriveShortlistDocument(string $id): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $file = (new \PMS\Services\DriveShortlistService())->documentForDrive($id, $scope['ctx']);
        if ($file === null) {
            Response::notFound('No shortlist document uploaded for this drive.');
        }

        $storage = new ObjectStorageService();
        $mime = $storage->guessMime($file['filename']);
        try {
            $storage->streamWithFallback(
                $file['path'],
                $file['filename'],
                $mime,
                true,
                ObjectStorageService::FOLDER_SHORTLISTS
            );
        } catch (\Throwable) {
            Response::notFound('No shortlist document uploaded for this drive.');
        }
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

    /** POST /api/admin/applications/{id}/shortlist */
    public function shortlistApplication(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $app = (new OfficerDataService())->assertApplicationInScope($appId, $scope['ctx']);
        $current = (string) ($app['status'] ?? 'applied');
        if (in_array($current, ['shortlisted', 'selected'], true)) {
            Response::success(null, 'Already shortlisted.');
            return;
        }
        (new ApplicationWorkflowService())->transition(
            $appId,
            'shortlisted',
            (string) $scope['user']['_id'],
            'Shortlisted by campus placement staff'
        );
        $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
        if ($student && !empty($student['userId'])) {
            (new NotificationService())->notifyApplicationUpdate(
                (string) $student['userId'],
                'Shortlisted',
                'You have been shortlisted for a campus drive. Check your applications for details.'
            );
        }
        Response::success(null, 'Student shortlisted.');
    }

    /** POST /api/admin/applications/{id}/round-outcome */
    public function setApplicationRoundOutcome(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->assertApplicationInScope($appId, $scope['ctx']);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $order = (int) ($input['order'] ?? 0);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        if ($order < 1 || !in_array($status, ['waiting', 'selected', 'rejected'], true)) {
            Response::error('Round order and status (waiting|selected|rejected) are required.', 422);
        }
        $result = (new ApplicationModel())->upsertRoundOutcome(
            $appId,
            $order,
            $type,
            $status,
            (string) $scope['user']['_id']
        );
        if (!$result['ok']) {
            Response::error('Could not update round outcome.', 400);
        }
        Response::success([
            'roundOutcomes' => $result['roundOutcomes'],
            'status' => $result['status'],
        ], 'Round outcome saved.');
    }

    /** GET /api/admin/applications/{id}/resume */
    public function downloadApplicationResume(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamApplicationResume($appId, $scope['ctx']);
    }

    /** GET /api/admin/applications/{id}/certificates/{index} */
    public function downloadApplicationCertificate(string $appId, string $index): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamApplicationCertificate($appId, (int) $index, $scope['ctx']);
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
                $row['department'] = $this->formatDepartmentLabel($dept);
                $row['departmentName'] = (string) ($dept['name'] ?? '');
                $row['designation'] = $profile['designation'] ?? '';
            }
        }

        // Admins promoted from staff may still have a staff profile (used for demotion).
        if (($user['role'] ?? '') === 'admin') {
            $profile = (new StaffModel())->findByUserId($userId);
            if ($profile) {
                $dept = (new DepartmentModel())->findById((string) ($profile['departmentId'] ?? ''));
                $row['departmentId'] = (string) ($profile['departmentId'] ?? '');
                $row['department'] = $this->formatDepartmentLabel($dept);
                $row['departmentName'] = (string) ($dept['name'] ?? '');
                $row['designation'] = $profile['designation'] ?? '';
                $row['fromStaff'] = true;
            }
        }

        if (($user['role'] ?? '') === 'placement_officer') {
            $profile = (new PlacementOfficerModel())->findByUserId($userId);
            if ($profile) {
                $dept = (new DepartmentModel())->findById((string) ($profile['departmentId'] ?? ''));
                $row['departmentId'] = (string) ($profile['departmentId'] ?? '');
                $row['department'] = $this->formatDepartmentLabel($dept);
                $row['departmentName'] = (string) ($dept['name'] ?? '');
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

    /**
     * @param array<string, mixed>|null $dept
     */
    private function formatDepartmentLabel(?array $dept): string
    {
        if ($dept === null) {
            return '';
        }
        $name = trim((string) ($dept['name'] ?? ''));
        $code = trim((string) ($dept['code'] ?? ''));
        if ($name !== '' && $code !== '' && strcasecmp($name, $code) !== 0) {
            return $name . ' (' . $code . ')';
        }

        return $name !== '' ? $name : $code;
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
            $companyName = trim((string) $input['companyName']);
            $companyModel = new CompanyModel();
            $existing = $companyModel->findByNormalizedName($companyName);
            if ($existing !== null) {
                $existingUserId = (string) ($existing['userId'] ?? '');
                if ($existingUserId !== '') {
                    $this->userModel->delete($id);
                    Response::error('A company with this name is already registered.', 409, [
                        'existingId' => (string) ($existing['_id'] ?? ''),
                    ]);
                }
                $companyModel->update((string) $existing['_id'], [
                    'userId'            => Security::toObjectId($id),
                    'associationStatus' => $input['associationStatus'] ?? 'active',
                ]);
            } else {
                try {
                    $companyModel->createCompany([
                        'userId'            => $id,
                        'companyName'       => $companyName,
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
                } catch (\InvalidArgumentException $e) {
                    $this->userModel->delete($id);
                    Response::error($e->getMessage(), 409);
                }
            }
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

    /** POST /api/admin/users/{id}/promote-to-admin — promote a staff member to administrator */
    public function promoteStaffToAdmin(string $userId): void
    {
        RBACMiddleware::requireAdmin();
        $user = $this->userModel->findById($userId);
        if (!$user) {
            Response::notFound('User not found.');
        }

        $role = (string) ($user['role'] ?? '');
        if ($role === 'admin') {
            Response::success(['id' => $userId], 'User is already an administrator.');
        }
        if ($role !== 'staff') {
            Response::error('Only staff members can be promoted to administrator.', 422);
        }
        if (($user['status'] ?? '') === 'blocked') {
            Response::error('Unblock this staff member before promoting them to administrator.', 422);
        }

        $staffProfile = (new StaffModel())->findByUserId($userId);
        if ($staffProfile === null) {
            Response::error('Staff profile not found for this user.', 422);
        }

        // Keep the staff profile so they can be returned to staff later.
        $this->userModel->updateUser($userId, [
            'role'     => 'admin',
            'status'   => 'active',
            'approved' => true,
        ]);

        Response::success(
            ['id' => $userId],
            'Staff member promoted to administrator.'
        );
    }

    /** POST /api/admin/users/{id}/demote-from-admin — return an admin to staff */
    public function demoteAdminToStaff(string $userId): void
    {
        $actor = RBACMiddleware::requireAdmin();
        $user = $this->userModel->findById($userId);
        if (!$user) {
            Response::notFound('User not found.');
        }
        if (($user['role'] ?? '') !== 'admin') {
            Response::error('User is not an administrator.', 422);
        }

        $actorId = (string) ($actor['_id'] ?? $actor['id'] ?? '');
        if ($actorId !== '' && $actorId === $userId) {
            Response::error('You cannot remove your own administrator role.', 422);
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email !== '' && (new AesLoginService())->isSuperAdminEmail($email)) {
            Response::error('Built-in super-admin accounts cannot be demoted.', 422);
        }

        $adminCount = count($this->userModel->findAll(['role' => 'admin'], 500));
        if ($adminCount <= 1) {
            Response::error('Cannot remove the last administrator.', 422);
        }

        $staffModel = new StaffModel();
        $staffProfile = $staffModel->findByUserId($userId);
        if ($staffProfile === null) {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
            $departmentId = trim((string) ($input['departmentId'] ?? ''));
            if ($departmentId === '' || !(new DepartmentModel())->findById($departmentId)) {
                Response::error('Select a department to restore this user as staff.', 422);
            }
            try {
                $staffModel->createProfile($userId, [
                    'departmentId' => $departmentId,
                    'designation'  => trim((string) ($input['designation'] ?? 'Staff')) ?: 'Staff',
                ]);
            } catch (\Throwable $e) {
                Response::error('Could not restore staff profile: ' . $e->getMessage(), 422);
            }
        }

        $this->userModel->updateUser($userId, [
            'role'     => 'staff',
            'status'   => 'active',
            'approved' => true,
        ]);

        Response::success(['id' => $userId], 'Administrator role removed. User is now staff.');
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
        foreach ($officerModel->findAll([], 500) as $profile) {
            $rawDeptId = $profile['departmentId'] ?? '';
            $deptOid = Security::toObjectId((string) $rawDeptId);
            $deptId = $deptOid !== null ? (string) $deptOid : trim((string) $rawDeptId);
            if ($deptId === '') {
                continue;
            }
            $user = $userModel->findById((string) ($profile['userId'] ?? ''));
            if ($user === null || (string) ($user['role'] ?? '') !== 'placement_officer') {
                continue;
            }
            $assigned[$deptId] = [
                'userId' => (string) ($profile['userId'] ?? ''),
                'name'   => $user['name'] ?? '',
                'email'  => $user['email'] ?? '',
            ];
        }

        $departments = array_map(function (array $dept) use ($assigned) {
            $oid = Security::toObjectId((string) ($dept['_id'] ?? ''));
            $id = $oid !== null ? (string) $oid : (string) ($dept['_id'] ?? '');
            $serialized = DocumentHelper::serialize($dept);
            $po = $assigned[$id] ?? null;
            $serialized['placementOfficer'] = $po;
            $serialized['hasOfficer'] = $po !== null;
            return $serialized;
        }, $model->findAll([], 500));

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
        $scope = (new OfficerDataService())->requireScopeOrViewer();
        $deptId = $scope['ctx']['isAdmin'] ? ($_GET['departmentId'] ?? null) : $scope['ctx']['departmentId'];
        $service = new ReportService();
        Response::success($service->listHistory($deptId ? (string) $deptId : null));
    }

    /** POST /api/admin/reports/{type} */
    public function generateReport(string $type): void
    {
        $scope = (new OfficerDataService())->requireScopeOrViewer();
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

        Response::success($result, 'Report generated.');
    }

    /** GET /api/admin/students */
    public function listStudents(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        Response::success((new OfficerDataService())->listStudents($scope['ctx'], $query !== '' ? $query : null));
    }

    /** GET /api/admin/students/allfinal-year — campus-wide AES getAllStudInfo4Placement + student table */
    public function listFinalYearStudents(): void
    {
        RBACMiddleware::requireAdmin();
        $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        Response::success(
            (new OfficerDataService())->listCampusFinalYearStudents($query !== '' ? $query : null)
        );
    }

    /** GET /api/admin/students/placed — all placed students campus-wide */
    public function listPlacedStudents(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        Response::success(
            (new OfficerDataService())->listPlacedStudents($scope['ctx'], $query !== '' ? $query : null)
        );
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

    /** GET /api/admin/students/{id}/qualifications */
    public function studentQualifications(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $register = trim((string) ($_GET['registerNumber'] ?? ''));
        Response::success((new OfficerDataService())->getEducationQualifications(
            $studentId,
            $scope['ctx'],
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

    /** GET /api/admin/job-posts/pending */
    public function listPendingJobPosts(): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $ctx = PlacementOfficerContext::resolve($admin);
        Response::success((new \PMS\Services\JobPostApprovalService())->listPending($ctx));
    }

    /** POST /api/admin/job-posts/{id}/approve */
    public function approveJobPost(string $id): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $ctx = PlacementOfficerContext::resolve($admin);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $departmentId = trim((string) ($input['departmentId'] ?? ''));
        $result = (new \PMS\Services\JobPostApprovalService())->approve(
            $id,
            $admin,
            $ctx,
            $departmentId !== '' ? $departmentId : null
        );
        Response::success($result, 'Job post approved and published as a drive.');
    }

    /** POST /api/admin/job-posts/{id}/reject */
    public function rejectJobPost(string $id): void
    {
        $admin = RBACMiddleware::requireAdmin();
        $ctx = PlacementOfficerContext::resolve($admin);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $reason = trim((string) ($input['reason'] ?? ''));
        $result = (new \PMS\Services\JobPostApprovalService())->reject($id, $admin, $ctx, $reason);
        Response::success($result, 'Job post rejected.');
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
        RBACMiddleware::requirePlacementDataViewer();
        Response::success(DocumentHelper::serializeMany((new CompanyModel())->listEnriched(200)));
    }

    /** POST /api/admin/companies — admin or placement officer */
    public function createCompany(): void
    {
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, ['companyName' => 'required']);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $companyName = trim((string) ($input['companyName'] ?? ''));
        $companyModel = new CompanyModel();
        $existing = $companyModel->findByNormalizedName($companyName);
        if ($existing !== null) {
            Response::error(
                'A company with this name is already registered.',
                409,
                ['existingId' => (string) ($existing['_id'] ?? '')]
            );
        }
        $input['companyName'] = $companyName;
        try {
            $id = $companyModel->createCompany($input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 409);
        }
        Response::success(['id' => $id], 'Company created.', 201);
    }

    /** POST /api/admin/companies/resolve — reuse existing or create (admin / PO, drive flow) */
    public function resolveCompany(): void
    {
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, ['companyName' => 'required']);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $companyName = trim((string) ($input['companyName'] ?? ''));
        $companyModel = new CompanyModel();
        $existing = $companyModel->findByNormalizedName($companyName);
        if ($existing !== null) {
            Response::success([
                'id'          => (string) ($existing['_id'] ?? ''),
                'companyId'   => (string) ($existing['_id'] ?? ''),
                'companyName' => (string) ($existing['companyName'] ?? $companyName),
                'created'     => false,
                'reused'      => true,
            ], 'This company is already registered. Using the existing record.');
            return;
        }

        try {
            $id = $companyModel->createCompany([
                'companyName'       => $companyName,
                'category'          => $input['category'] ?? 'Software',
                'tier'              => $input['tier'] ?? 'Tier 2',
                'website'           => $input['website'] ?? $input['companyWebsite'] ?? '',
                'associationStatus' => $input['associationStatus'] ?? 'active',
            ]);
        } catch (\InvalidArgumentException $e) {
            $again = $companyModel->findByNormalizedName($companyName);
            if ($again !== null) {
                Response::success([
                    'id'          => (string) ($again['_id'] ?? ''),
                    'companyId'   => (string) ($again['_id'] ?? ''),
                    'companyName' => (string) ($again['companyName'] ?? $companyName),
                    'created'     => false,
                    'reused'      => true,
                ], 'This company is already registered. Using the existing record.');
                return;
            }
            Response::error($e->getMessage(), 409);
        }

        Response::success([
            'id'          => $id,
            'companyId'   => $id,
            'companyName' => $companyName,
            'created'     => true,
            'reused'      => false,
        ], 'Company added.', 201);
    }

    /** PUT /api/admin/companies/{id} — admin or officer may update current company details */
    public function updateCompany(string $id): void
    {
        RBACMiddleware::requirePlacementOfficer();
        $model = new CompanyModel();
        $company = $model->findById($id);
        if (!$company) {
            Response::notFound();
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (isset($input['companyName'])) {
            $companyName = trim((string) $input['companyName']);
            if ($companyName === '') {
                Response::error('companyName cannot be empty.', 422);
            }
            $existing = $model->findByNormalizedName($companyName, $id);
            if ($existing !== null) {
                Response::error(
                    'A company with this name is already registered.',
                    409,
                    ['existingId' => (string) ($existing['_id'] ?? '')]
                );
            }
            $input['companyName'] = $companyName;
            $input['nameNormalized'] = CompanyModel::normalizeCompanyName($companyName);
        }

        $loginEmail = strtolower(trim((string) ($input['loginEmail'] ?? '')));
        $loginPassword = (string) ($input['loginPassword'] ?? '');
        $manageLogin = array_key_exists('loginEmail', $input) || $loginPassword !== '';
        $linkedUserId = (string) ($company['userId'] ?? '');
        $linkedUser = $linkedUserId !== '' ? $this->userModel->findById($linkedUserId) : null;

        if ($manageLogin) {
            RBACMiddleware::requireAdmin();
            $rules = ['loginEmail' => 'required|email'];
            if (!$linkedUser || $loginPassword !== '') {
                $rules['loginPassword'] = 'required|min:8|max:128';
            }
            $loginInput = ['loginEmail' => $loginEmail, 'loginPassword' => $loginPassword];
            $errors = Validator::validate($loginInput, $rules);
            if (!empty($errors)) {
                Response::error('Login credential validation failed.', 422, $errors);
            }
            $emailOwner = $this->userModel->findByEmail($loginEmail);
            if ($emailOwner && (string) ($emailOwner['_id'] ?? '') !== $linkedUserId) {
                Response::error('This company login email is already in use.', 409);
            }
        }

        $allowed = ['companyName', 'nameNormalized', 'category', 'tier', 'contacts', 'associationStatus', 'comments', 'website', 'description'];
        $companyUpdate = array_intersect_key($input, array_flip($allowed));
        $createdUserId = '';
        if ($manageLogin) {
            $contacts = is_array($input['contacts'] ?? null) ? $input['contacts'] : [];
            $contact = is_array($contacts[0] ?? null) ? $contacts[0] : [];
            $contactName = trim((string) ($contact['name'] ?? $input['contactName'] ?? $company['companyName'] ?? 'Company Recruiter'));
            if ($linkedUser) {
                $userUpdate = [
                    'name'  => $contactName !== '' ? $contactName : 'Company Recruiter',
                    'email' => $loginEmail,
                ];
                if ($loginPassword !== '') {
                    $userUpdate['password'] = $loginPassword;
                }
                $this->userModel->updateUser($linkedUserId, $userUpdate);
            } else {
                $createdUserId = $this->userModel->createUser([
                    'name'     => $contactName !== '' ? $contactName : 'Company Recruiter',
                    'email'    => $loginEmail,
                    'password' => $loginPassword,
                    'role'     => 'company',
                    'status'   => 'active',
                    'approved' => true,
                ]);
                $companyUpdate['userId'] = Security::toObjectId($createdUserId);
            }
        }

        try {
            if ($companyUpdate !== [] && !$model->update($id, $companyUpdate)) {
                throw new \RuntimeException('Company details could not be updated.');
            }
        } catch (\Throwable $e) {
            if ($createdUserId !== '') {
                $this->userModel->delete($createdUserId);
            }
            throw $e;
        }

        Response::success([
            'accountCreated' => $createdUserId !== '',
            'loginEmail'     => $manageLogin ? $loginEmail : (string) ($linkedUser['email'] ?? ''),
        ], $createdUserId !== '' ? 'Company updated and login credentials created.' : 'Company updated.');
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
        RBACMiddleware::requirePlacementDataViewer();
        Response::success((new RecommendationModel())->listEnriched());
    }

    /** PUT /api/admin/recommendations/{id}/status */
    public function updateRecommendationStatus(string $id): void
    {
        RBACMiddleware::requirePlacementOfficer();
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
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!(new RecommendationModel())->updateRecommendation($id, $input)) {
            Response::error('Recommendation not found or invalid data.', 422);
        }
        Response::success(null, 'Recommendation updated.');
    }

    /** DELETE /api/admin/recommendations/{id} */
    public function deleteRecommendation(string $id): void
    {
        RBACMiddleware::requirePlacementOfficer();
        if (!(new RecommendationModel())->deleteRecommendation($id)) {
            Response::notFound('Recommendation not found.');
        }
        Response::success(null, 'Recommendation deleted.');
    }

    /** POST /api/admin/companies/register — admin or placement officer */
    public function registerCompany(): void
    {
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $rules = [
            'companyName'   => 'required',
            'hrName'        => 'required',
            'hrEmail'       => 'required|email',
            'contactNumber' => 'required',
        ];
        $loginEmail = strtolower(trim((string) ($input['loginEmail'] ?? '')));
        $loginPassword = (string) ($input['loginPassword'] ?? '');
        if ($loginEmail !== '') {
            $input['loginEmail'] = $loginEmail;
        }
        $provisionLogin = $loginEmail !== '' || $loginPassword !== '';
        if ($provisionLogin) {
            RBACMiddleware::requireAdmin();
            $rules['loginEmail'] = 'required|email';
            $rules['loginPassword'] = 'required|min:8|max:128';
        }
        $errors = Validator::validate($input, $rules);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        if ($provisionLogin && $this->userModel->findByEmail($loginEmail)) {
            Response::error('This company login email is already in use.', 409);
        }

        $companyName = trim((string) ($input['companyName'] ?? ''));
        $companyModel = new CompanyModel();
        $existing = $companyModel->findByNormalizedName($companyName);
        if ($existing !== null) {
            if (!empty($input['sourceRecommendationId'])) {
                (new RecommendationModel())->updateStatus((string) $input['sourceRecommendationId'], 'registered');
            }
            Response::error(
                'A company with this name is already registered.',
                409,
                ['existingId' => (string) ($existing['_id'] ?? '')]
            );
        }

        $companyUserId = '';
        if ($provisionLogin) {
            $companyUserId = $this->userModel->createUser([
                'name'     => trim((string) $input['hrName']),
                'email'    => $loginEmail,
                'password' => $loginPassword,
                'role'     => 'company',
                'status'   => 'active',
                'approved' => true,
            ]);
        }

        try {
            $companyId = $companyModel->createCompany([
                'userId'            => $companyUserId,
                'companyName'       => $companyName,
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
        } catch (\InvalidArgumentException $e) {
            if ($companyUserId !== '') {
                $this->userModel->delete($companyUserId);
            }
            Response::error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            if ($companyUserId !== '') {
                $this->userModel->delete($companyUserId);
            }
            throw $e;
        }

        if (!empty($input['sourceRecommendationId'])) {
            (new RecommendationModel())->updateStatus((string) $input['sourceRecommendationId'], 'registered');
        }

        Response::success([
            'id'            => $companyId,
            'accountCreated' => $companyUserId !== '',
            'loginUsername' => $companyUserId !== '' ? $loginEmail : '',
        ], $companyUserId !== '' ? 'Company profile and login created.' : 'Company registered.', 201);
    }

    /** GET /api/admin/alumni-referrals */
    public function listAlumniReferrals(): void
    {
        RBACMiddleware::requirePlacementDataViewer();
        Response::success((new AlumniReferralModel())->listEnriched());
    }

    /** PUT /api/admin/alumni-referrals/{id}/status */
    public function updateAlumniReferralStatus(string $id): void
    {
        RBACMiddleware::requirePlacementOfficer();
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
        RBACMiddleware::requirePlacementOfficer();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!(new AlumniReferralModel())->updateReferral($id, $input)) {
            Response::error('Alumni recommendation not found or invalid data.', 422);
        }
        Response::success(null, 'Alumni recommendation updated.');
    }

    /** DELETE /api/admin/alumni-referrals/{id} */
    public function deleteAlumniReferral(string $id): void
    {
        RBACMiddleware::requirePlacementOfficer();
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
        (new OfficerDataService())->requireScopeOrViewer();
        $filename = basename(rawurldecode($filename));
        if (!preg_match('/^[a-z0-9._\-]+\.(pdf|csv|xlsx|xls)$/i', $filename)) {
            Response::error('Invalid filename.', 400);
        }

        $storage = new ObjectStorageService();
        $mime = $storage->guessMime($filename);
        if (!str_contains($mime, 'sheet') && !str_contains($mime, 'excel') && !str_contains($mime, 'csv') && $mime !== 'application/pdf') {
            $mime = str_ends_with(strtolower($filename), '.xls')
                ? 'application/vnd.ms-excel'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        $candidates = [];
        $meta = (new ReportModel())->findOne(['filename' => $filename]);
        if (is_array($meta) && !empty($meta['path'])) {
            $candidates[] = (string) $meta['path'];
        }
        $candidates[] = $storage->uri(ObjectStorageService::FOLDER_REPORTS, $filename);
        $candidates[] = dirname(__DIR__, 2) . '/uploads/reports/' . $filename;
        $candidates[] = dirname(__DIR__, 2) . '/uploads/ajce-placements/reports/' . $filename;

        $lastError = null;
        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            try {
                if (!headers_sent()) {
                    header_remove('Content-Type');
                }
                $storage->streamWithFallback(
                    $candidate,
                    $filename,
                    $mime,
                    false,
                    ObjectStorageService::FOLDER_REPORTS
                );
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        // Direct local stream if storage helpers still fail.
        foreach ([
            dirname(__DIR__, 2) . '/uploads/reports/' . $filename,
            dirname(__DIR__, 2) . '/uploads/ajce-placements/reports/' . $filename,
        ] as $localPath) {
            if (!is_file($localPath) || !is_readable($localPath)) {
                continue;
            }
            $body = file_get_contents($localPath);
            if ($body === false) {
                continue;
            }
            if (!headers_sent()) {
                header_remove('Content-Type');
            }
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
            header('Content-Length: ' . (string) strlen($body));
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }

        Response::notFound('Report file not found.');
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
        if (isset($input['elasticEmailApiKey']) && !is_string($input['elasticEmailApiKey'])) {
            Response::error('Invalid ElasticEmail API key.', 422);
        }
        $saved = (new SystemSettingsModel())->save($input);
        Response::success($saved, 'System settings saved.');
    }

    /** GET /api/admin/mail/status */
    public function mailStatus(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new EmailService())->status());
    }

    /** POST /api/admin/mail/test — body: { to?: string, subject?: string, message?: string } */
    public function testMail(): void
    {
        $user = RBACMiddleware::requireAdmin();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $to = strtolower(trim((string) ($input['to'] ?? ($user['email'] ?? ''))));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('Provide a valid "to" email address.', 422);
        }

        $subject = trim((string) ($input['subject'] ?? 'AJCE Placements mail test'));
        $message = trim((string) ($input['message'] ?? 'This is a test email from the placement portal.'));
        $body = '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#64748B;font-size:13px">Sent from System Settings · AJCE Placements</p>';

        $mail = new EmailService();
        $result = $mail->sendMail([
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
        ]);

        if (!$result['ok']) {
            Response::error($result['error'] ?? 'Email send failed.', 502, [
                'driver'   => $result['driver'] ?? null,
                'response' => $result['response'] ?? null,
                'status'   => $mail->status(),
            ]);
        }

        Response::success([
            'to'       => $to,
            'driver'   => $result['driver'] ?? null,
            'response' => $result['response'] ?? null,
            'status'   => $mail->status(),
        ], 'Test email sent.');
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

    /** POST /api/admin/notifications/delete-selected */
    public function deleteSelectedNotifications(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        $ids = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $ids)));
        if ($ids === []) {
            Response::error('Select at least one notification to delete.', 422);
        }
        $count = (new NotificationModel())->deleteOwned((string) $user['_id'], $ids);
        Response::success(['deleted' => $count], $count === 1 ? 'Notification deleted.' : "{$count} notifications deleted.");
    }

    /** POST /api/admin/notifications/delete-all */
    public function deleteAllNotifications(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $readOnly = !array_key_exists('readOnly', $input) || filter_var($input['readOnly'], FILTER_VALIDATE_BOOL);
        $count = (new NotificationModel())->deleteAllForUser((string) $user['_id'], $readOnly);
        Response::success(
            ['deleted' => $count],
            $readOnly ? 'All read notifications deleted.' : 'All notifications deleted.'
        );
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
        $lite = isset($_GET['lite']) && (string) $_GET['lite'] !== '0' && (string) $_GET['lite'] !== '';
        Response::success((new RecruitingService())->getCampusOverview(null, null, $lite));
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
