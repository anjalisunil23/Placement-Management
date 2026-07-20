<?php

declare(strict_types=1);

namespace PMS\Staff;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\DepartmentModel;
use PMS\Models\NotificationModel;
use PMS\Models\RecommendationModel;
use PMS\Models\StaffModel;
use PMS\Models\UserModel;
use PMS\Services\OfficerDataService;
use PMS\Services\StaffContext;
use PMS\Services\StaffDataService;
use PMS\Services\SelfPlacementService;
use PMS\Services\PlacementFilterService;
use PMS\Services\StaffPlacementRegistryService;
use PMS\Services\StaffService;
use PMS\Services\RecruitingService;
use PMS\Services\AesLoginService;
use PMS\Services\NotificationService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;

/**
 * Staff module ? recommendations, department insights, student overview.
 */
final class StaffController
{
    private StaffModel $staffModel;

    public function __construct()
    {
        $this->staffModel = new StaffModel();
    }

    private function getProfile(array $user): array
    {
        return StaffContext::ensureProfile($user);
    }

    /** GET /api/staff/profile */
    public function profile(): void
    {
        $user = RBACMiddleware::requireStaff();
        $profile = $this->getProfile($user);
        $dept = !empty($profile['departmentId'])
            ? (new DepartmentModel())->findById((string) $profile['departmentId'])
            : null;
        $data = DocumentHelper::serialize($profile) ?? [];
        $data['department'] = $dept ? (string) ($dept['code'] ?? '') : '';
        $data['departmentName'] = $dept ? (string) ($dept['name'] ?? '') : '';

        $merged = (new AesLoginService())->applyAesSessionToUserFields(array_merge(
            StaffModel::profileToUserFields($profile, $dept),
            [
                'name'  => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
            ]
        ));
        $photo = (new AesLoginService())->resolveProfilePhoto($profile, $user);
        $data['user'] = [
            'name'        => (string) ($merged['name'] ?? $user['name'] ?? ''),
            'email'       => (string) ($merged['email'] ?? $user['email'] ?? ''),
            'phone'       => (string) ($merged['phone'] ?? $profile['phone'] ?? ''),
            'designation' => (string) ($merged['designation'] ?? $profile['designation'] ?? ''),
            'photoUrl'    => (string) ($photo['photoUrl'] ?? ''),
            'photo'       => $photo['photo'],
        ];
        if (!empty($photo['photoUrl'])) {
            $data['photoUrl'] = (string) $photo['photoUrl'];
            $data['photo'] = $photo['photo'];
        }
        if ($data['department'] === '' && !empty($merged['department'])) {
            $data['department'] = (string) $merged['department'];
            $data['departmentName'] = (string) $merged['department'];
        }
        if (!empty($merged['designation'])) {
            $data['designation'] = (string) $merged['designation'];
        }
        if (!empty($merged['phone'])) {
            $data['phone'] = (string) $merged['phone'];
        }
        $departmentId = (string) ($profile['departmentId'] ?? '');
        $staffCtx = [
            'profile' => $profile,
            'departmentId' => $departmentId,
            'department' => $dept,
            'user' => $user,
        ];
        $assigned = StaffContext::assignedClassBatches($staffCtx);
        if ($assigned === []) {
            $assigned = (new StaffService())->refreshAssignedClassBatchesFromAes($staffCtx);
        }
        if ($assigned !== []) {
            $data['assignedClassBatches'] = $assigned;
        }

        Response::success(DocumentHelper::jsonSafe($data));
    }

    /** PUT /api/staff/profile */
    public function updateProfile(): void
    {
        $user = RBACMiddleware::requireStaff();
        $profile = $this->getProfile($user);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!$this->staffModel->updateProfile((string) $profile['_id'], $input)) {
            Response::error('No valid fields to update.', 422);
        }
        $this->profile();
    }

    /** GET /api/staff/dashboard */
    public function dashboard(): void
    {
        $user = RBACMiddleware::requireStaff();
        Response::success((new StaffService())->getDashboard($user));
    }

    /** GET /api/staff/recommendations */
    public function listRecommendations(): void
    {
        $user = RBACMiddleware::requireStaff();
        $recs = (new RecommendationModel())->findByStaffUserId((string) $user['_id']);
        $serialized = array_map(
            static fn (array $rec) => RecommendationModel::serializeForStaff($rec, $user),
            $recs
        );
        Response::success($serialized);
    }

    /** POST /api/staff/recommendations */
    public function createRecommendation(): void
    {
        $user = RBACMiddleware::requireStaff();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $input = $this->normalizeRecommendationInput($input);

        $errors = Validator::validate($input, [
            'companyName' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $contactErrors = Validator::validate($input['contact'] ?? [], [
            'name'  => 'required',
            'email' => 'required|email',
            'phone' => 'required|phone',
        ]);
        if (!empty($contactErrors)) {
            Response::error('Contact validation failed.', 422, $contactErrors);
        }

        $id = (new RecommendationModel())->createRecommendation((string) $user['_id'], $input);
        (new NotificationService())->notifyAdmins(
            'recommendation_update',
            'New staff company recommendation',
            (string) ($user['name'] ?? 'Staff') . ' recommended ' . (string) ($input['companyName'] ?? 'a company') . ' for campus recruitment.',
            ['recommendationId' => $id]
        );
        Response::success(['id' => $id], 'Company recommended.', 201);
    }

    /** GET /api/staff/drives */
    public function listDrives(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $drives = (new StaffDataService())->listDrives($ctx);
        Response::success(DocumentHelper::jsonSafe(
            (new OfficerDataService())->enrichDrivesWithCompany($drives)
        ));
    }

    /** GET /api/staff/students */
    public function listStudents(): void
    {
        $user = RBACMiddleware::requireStaff();
        try {
            $ctx = StaffContext::resolve($user);
            StaffContext::requireDepartmentScope($ctx);
            $officerCtx = StaffContext::officerCompatible($ctx);
            $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
            $rows = (new OfficerDataService())->listStudents(
                $officerCtx,
                $query !== '' ? $query : null
            );
            Response::success(DocumentHelper::jsonSafe($rows));
        } catch (\Throwable $e) {
            $message = 'Could not load students.';
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $message = $e->getMessage();
            }
            Response::error($message, 500);
        }
    }

    /** GET /api/staff/students/final-year — department final-year (registered / non-registered) */
    public function listFinalYearStudents(): void
    {
        $user = RBACMiddleware::requireStaff();
        try {
            $ctx = StaffContext::resolve($user);
            StaffContext::requireDepartmentScope($ctx);
            $officerCtx = StaffContext::officerCompatible($ctx);
            $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
            $rows = (new OfficerDataService())->listFinalYearStudentsForScope(
                $officerCtx,
                $query !== '' ? $query : null
            );
            Response::success(DocumentHelper::jsonSafe($rows));
        } catch (\Throwable $e) {
            $message = 'Could not load final-year students.';
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $message = $e->getMessage();
            }
            Response::error($message, 500);
        }
    }

    /** GET /api/staff/students/{id}/pipeline */
    public function studentPipeline(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $officerCtx = StaffContext::officerCompatible($ctx);
        Response::success((new OfficerDataService())->studentPipelineForScope($studentId, $officerCtx));
    }

    /** GET /api/staff/students/{id}/profile */
    public function studentProfile(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $officerCtx = StaffContext::officerCompatible($ctx);
        $register = trim((string) ($_GET['registerNumber'] ?? ''));
        Response::success((new OfficerDataService())->getStudentOverview(
            $studentId,
            $officerCtx,
            'staff',
            $register !== '' ? $register : null
        ));
    }

    /** GET /api/staff/students/{id}/qualifications */
    public function studentQualifications(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $officerCtx = StaffContext::officerCompatible($ctx);
        $register = trim((string) ($_GET['registerNumber'] ?? ''));
        Response::success((new OfficerDataService())->getEducationQualifications(
            $studentId,
            $officerCtx,
            $register !== '' ? $register : null
        ));
    }

    /** GET /api/staff/students/{id}/photo */
    public function studentPhoto(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $officerCtx = StaffContext::officerCompatible($ctx);
        (new OfficerDataService())->streamStudentPhoto($studentId, $officerCtx);
    }

    /** GET /api/staff/placement-filters */
    public function placementFilters(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $program = trim((string) ($_GET['program'] ?? ''));
        $branch = trim((string) ($_GET['branch'] ?? ''));
        $svc = new PlacementFilterService();
        Response::success(DocumentHelper::jsonSafe([
            'departments' => $svc->fetchDepartmentOptions($ctx),
            'programs' => $svc->fetchProgramOptions($ctx),
            'branches' => $program !== '' ? $svc->fetchBranchOptions($ctx, $program) : [],
            'batches'  => $svc->fetchBatchOptions($ctx, $program, $branch, true),
        ]));
    }

    /** GET /api/staff/placements-higher-education */
    public function placementsHigherEducation(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $filters = [
            'program' => (string) ($_GET['program'] ?? ''),
            'branch'  => (string) ($_GET['branch'] ?? ''),
            'batch'   => (string) ($_GET['batch'] ?? ''),
            'type'    => (string) ($_GET['type'] ?? ''),
            'q'       => (string) ($_GET['q'] ?? $_GET['search'] ?? ''),
        ];
        Response::success(DocumentHelper::jsonSafe(
            (new StaffPlacementRegistryService())->list($ctx, $filters)
        ));
    }

    /** GET /api/staff/students/{id}/self-placement/offer-letter */
    public function downloadSelfPlacementOfferLetter(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $officerCtx = StaffContext::officerCompatible($ctx);
        (new SelfPlacementService())->streamOfferLetter($studentId, $officerCtx);
    }

    /** PUT /api/staff/students/{id}/placement */
    public function updateStudentPlacement(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!is_array($input)) {
            $input = [];
        }
        Response::success(DocumentHelper::jsonSafe(
            (new StaffPlacementRegistryService())->updatePlacement($ctx, $studentId, $input)
        ), 'Placement details updated.');
    }

    /** POST /api/staff/students/{id}/placement/documents */
    public function uploadStudentPlacementDocuments(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        Response::success(DocumentHelper::jsonSafe(
            (new StaffPlacementRegistryService())->uploadPlacementDocuments($ctx, $studentId)
        ), 'Documents uploaded.');
    }

    /** GET /api/staff/recruiting — campus snapshot; UI filters by department (same as placement officer). */
    public function recruitingOverview(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        Response::success(DocumentHelper::jsonSafe((new RecruitingService())->getCampusOverview(null)));
    }

    /** GET /api/staff/dashboard-stats — department-scoped analytics (hiring trend, placement stats). */
    public function dashboardStats(): void
    {
        $scope = (new StaffDataService())->requireScope();
        Response::success(DocumentHelper::jsonSafe(
            (new StaffDataService())->dashboardStats($scope['officerCtx'], (string) $scope['user']['_id'])
        ));
    }

    /** GET /api/staff/hiring-overview */
    public function hiringOverview(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $batch = trim((string) ($_GET['batch'] ?? ''));
        $branch = trim((string) ($_GET['branch'] ?? ''));
        $data = (new StaffService())->hiringOverview(
            $ctx,
            $batch !== '' ? $batch : null,
            $branch !== '' ? $branch : null
        );

        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : null;
        $data['department'] = [
            'code' => (string) ($dept['code'] ?? ''),
            'name' => (string) ($dept['name'] ?? ''),
        ];

        Response::success(DocumentHelper::jsonSafe($data));
    }

    /** GET /api/staff/notifications */
    public function notifications(): void
    {
        $user = RBACMiddleware::requireStaff();
        $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($notifs));
    }

    /** POST /api/staff/notifications/{id}/read */
    public function markNotificationRead(string $id): void
    {
        $user = RBACMiddleware::requireStaff();
        $notif = (new NotificationModel())->findById($id);
        if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
            Response::notFound();
        }
        (new NotificationModel())->markRead($id);
        Response::success(null, 'Notification marked as read.');
    }

    /** POST /api/staff/notifications/read-all */
    public function markAllNotificationsRead(): void
    {
        $user = RBACMiddleware::requireStaff();
        $count = (new NotificationModel())->markAllRead((string) $user['_id']);
        Response::success(['updated' => $count], 'All notifications marked as read.');
    }

    /** POST /api/staff/notifications/delete-selected */
    public function deleteSelectedNotifications(): void
    {
        $user = RBACMiddleware::requireStaff();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
        $ids = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $ids)));
        if ($ids === []) {
            Response::error('Select at least one notification to delete.', 422);
        }
        $count = (new NotificationModel())->deleteOwned((string) $user['_id'], $ids);
        Response::success(['deleted' => $count], $count === 1 ? 'Notification deleted.' : "{$count} notifications deleted.");
    }

    /** POST /api/staff/notifications/delete-all */
    public function deleteAllNotifications(): void
    {
        $user = RBACMiddleware::requireStaff();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $readOnly = !array_key_exists('readOnly', $input) || filter_var($input['readOnly'], FILTER_VALIDATE_BOOL);
        $count = (new NotificationModel())->deleteAllForUser((string) $user['_id'], $readOnly);
        Response::success(
            ['deleted' => $count],
            $readOnly ? 'All read notifications deleted.' : 'All notifications deleted.'
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeRecommendationInput(array $input): array
    {
        if (!empty($input['hrName']) || !empty($input['hrEmail']) || !empty($input['contactNumber']) || !empty($input['contactRole'])) {
            $input['contact'] = [
                'name'  => trim((string) ($input['hrName'] ?? $input['contact']['name'] ?? '')),
                'email' => trim((string) ($input['hrEmail'] ?? $input['contact']['email'] ?? '')),
                'phone' => trim((string) ($input['contactNumber'] ?? $input['contact']['phone'] ?? '')),
                'role'  => trim((string) ($input['contactRole'] ?? $input['contact']['role'] ?? '')),
            ];
        }
        if (!is_array($input['contact'] ?? null)) {
            $input['contact'] = ['name' => '', 'email' => '', 'phone' => '', 'role' => ''];
        }
        $input['category'] = $input['category'] ?? 'General';
        $input['reason'] = $input['reason'] ?? 'Referred by faculty for campus recruitment.';
        return $input;
    }

    /** GET /api/staff/job-posts */
    public function listJobPosts(): void
    {
        $user = RBACMiddleware::requireStaff();
        $posts = (new \PMS\Models\AlumniJobPostModel())->findByOwner((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($posts));
    }

    /** POST /api/staff/job-posts */
    public function createJobPost(): void
    {
        $user = RBACMiddleware::requireStaff();
        $profile = $this->getProfile($user);
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);

        $errors = Validator::validate($input, [
            'title'   => 'required',
            'company' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $departmentId = trim((string) ($input['departmentId'] ?? ''));
        if ($departmentId === '') {
            $departmentId = trim((string) ($profile['departmentId'] ?? ''));
        }
        if ($departmentId === '') {
            Response::error('Select a department for this job post.', 422);
        }
        $department = (new DepartmentModel())->findById($departmentId);
        if (!$department) {
            Response::error('Select a valid department for this job post.', 422);
        }

        $branchCodes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            [$department['code'] ?? '', $department['shortName'] ?? '']
        ))));
        $input['departmentId'] = $departmentId;
        $input['eligibility'] = [
            'branches' => $branchCodes,
            'departments' => [$departmentId],
        ];
        $input['status'] = 'pending';
        $input['audience'] = $input['audience'] ?? 'student';

        $savedPosterPath = '';
        $posterFile = $_FILES['poster'] ?? $_FILES['image'] ?? null;
        if (is_array($posterFile) && ($posterFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $savedPoster = $this->storeJobPoster($posterFile, 'staff_' . (string) $user['_id']);
            $input['posterUrl'] = $savedPoster['url'];
            $input['posterType'] = $savedPoster['type'];
            if ($savedPoster['type'] === 'image') {
                $input['imageUrl'] = $savedPoster['url'];
            }
            $savedPosterPath = $savedPoster['path'];
        }

        try {
            $id = (new \PMS\Models\AlumniJobPostModel())->createPost((string) $user['_id'], $input, 'staff');
        } catch (\Throwable $e) {
            if ($savedPosterPath !== '') {
                (new \PMS\Services\ObjectStorageService())->delete($savedPosterPath);
            }
            throw $e;
        }

        $created = (new \PMS\Models\AlumniJobPostModel())->findById($id);
        if (!$created) {
            $created = array_merge($input, ['_id' => $id, 'sourceType' => 'staff']);
        }
        (new \PMS\Services\JobPostApprovalService())->notifyReviewers($created, $user);
        Response::success(['id' => $id, 'status' => 'pending'], 'Job post submitted for approval.', 201);
    }

    /**
     * @param array<string, mixed> $file
     * @return array{url:string,path:string,type:string}
     */
    private function storeJobPoster(array $file, string $prefix): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $error = \PMS\Utils\Security::validateUploadedFile(
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
        $storage = new \PMS\Services\ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(
                \PMS\Services\ObjectStorageService::FOLDER_JOB_POSTERS,
                $filename,
                $file
            );
        } catch (\Throwable $e) {
            Response::error('Failed to save the job poster to S3: ' . $e->getMessage(), 500);
        }
        $storedName = $storage->storedNameFromUri($path);
        return [
            'url' => $storage->mediaUrl(\PMS\Services\ObjectStorageService::FOLDER_JOB_POSTERS, $storedName),
            'path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
        ];
    }
}
