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
use PMS\Services\PcaOfferLetterService;
use PMS\Services\StaffContext;
use PMS\Services\StaffDataService;
use PMS\Services\SelfPlacementService;
use PMS\Services\PlacementFilterService;
use PMS\Services\StaffPlacementRegistryService;
use PMS\Services\StaffService;
use PMS\Services\StaffCourseQuestionService;
use PMS\Services\AesApiService;
use PMS\Services\AesSyllabusCipher;
use PMS\Services\CourseSyllabusCatalog;
use PMS\Services\RecruitingService;
use PMS\Services\AesLoginService;
use PMS\Services\NotificationService;
use PMS\Services\StudentProfileEditService;
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
            'phone'       => (string) (
                trim((string) ($profile['phone'] ?? '')) !== ''
                    ? $profile['phone']
                    : ($merged['phone'] ?? '')
            ),
            'designation' => (string) ($merged['designation'] ?? $profile['designation'] ?? ''),
            'isHod'       => !empty($profile['isHod'])
                || \PMS\Services\HodDetection::designationLooksLikeHod((string) ($profile['designation'] ?? ''))
                || \PMS\Middleware\AuthMiddleware::isHod($user),
            'photoUrl'    => (string) ($photo['photoUrl'] ?? ''),
            'photo'       => $photo['photo'],
        ];
        $data['isHod'] = $data['user']['isHod'];
        if (!empty($data['user']['designation'])) {
            $data['designation'] = $data['user']['designation'];
        }
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
        $savedPhone = trim((string) ($profile['phone'] ?? ''));
        $data['phone'] = $savedPhone !== ''
            ? $savedPhone
            : (string) ($merged['phone'] ?? $data['user']['phone'] ?? '');
        $data['user']['phone'] = $data['phone'];
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
        if (isset($input['designation'])) {
            $desig = trim((string) $input['designation']);
            $input['designation'] = $desig;
            if (\PMS\Services\HodDetection::designationLooksLikeHod($desig)) {
                $input['isHod'] = true;
                $input['designation'] = \PMS\Services\HodDetection::normalizeDesignationForHod($desig, true);
            }
        }
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

    /** GET /api/staff/volunteers/{id}/offer-letter — published PCA letter for assigned-class students */
    public function getVolunteerOfferLetter(string $id): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        Response::success((new PcaOfferLetterService())->getForStaff($ctx, $id));
    }

    /** GET /api/staff/pca-offer-letters — published PCA letters for assigned-class students */
    public function listPcaOfferLetters(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        Response::success((new PcaOfferLetterService())->listPublishedSummaries($ctx));
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
        $drives = \PMS\Services\DriveLifecycle::filterForRole($drives, 'staff');
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

    /** GET /api/staff/students/placed — dept placed (current year); CT/CoCT = their class only */
    public function listPlacedStudents(): void
    {
        $user = RBACMiddleware::requireStaff();
        try {
            $ctx = StaffContext::resolve($user);
            StaffContext::requireDepartmentScope($ctx);
            $officerCtx = StaffContext::officerCompatible($ctx);
            $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
            $rows = (new OfficerDataService())->listPlacedStudents(
                $officerCtx,
                $query !== '' ? $query : null
            );
            Response::success(DocumentHelper::jsonSafe($rows));
        } catch (\Throwable $e) {
            $message = 'Could not load placed students.';
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
        $overview = (new OfficerDataService())->getStudentOverview(
            $studentId,
            $officerCtx,
            'staff',
            $register !== '' ? $register : null
        );
        $batch = trim((string) ($overview['classBatch'] ?? ''));
        $overview['canEditProfile'] = $batch !== '' && StaffContext::canEditClassBatch($ctx, $batch);
        Response::success($overview);
    }

    /** PUT /api/staff/students/{id}/profile — class teacher / co-class teacher only */
    public function updateStudentProfile(string $studentId): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (!is_array($input)) {
            $input = [];
        }
        Response::success(
            DocumentHelper::jsonSafe((new StudentProfileEditService())->applyStaffUpdate($ctx, $studentId, $input)),
            'Student profile updated.'
        );
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
        PlacementFilterService::clearScopedRowsCache();
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $program = trim((string) ($_GET['program'] ?? ''));
        $branch = trim((string) ($_GET['branch'] ?? ''));
        $studRole = strtolower(trim((string) ($_GET['studRole'] ?? 'all')));
        $registrySvc = new StaffPlacementRegistryService();
        $filterPayload = [
            'departmentId' => (string) ($_GET['departmentId'] ?? ''),
            'program'      => $program,
            'branch'       => $branch,
            'studRole'     => $studRole !== '' ? $studRole : 'all',
        ];
        $filterCtx = $registrySvc->placementFilterContext($ctx, $filterPayload);
        $svc = new PlacementFilterService();
        $assigned = StaffContext::assignedClassBatches($filterCtx);
        if ($assigned === []) {
            $assigned = (new StaffService())->refreshAssignedClassBatchesFromAes($ctx);
        }
        Response::success(DocumentHelper::jsonSafe([
            'departments' => $registrySvc->departmentFilterOptions(),
            'programs' => $svc->fetchProgramOptions($filterCtx),
            'branches' => $program !== '' ? $svc->fetchBranchOptions($filterCtx, $program) : [],
            'batches'  => $svc->fetchBatchOptions($filterCtx, $program, $branch, false),
            'assignedClassBatches' => $assigned,
        ]));
    }

    /** GET /api/staff/placements-higher-education */
    public function placementsHigherEducation(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $filters = [
            'departmentId' => (string) ($_GET['departmentId'] ?? ''),
            'program'      => (string) ($_GET['program'] ?? ''),
            'branch'       => (string) ($_GET['branch'] ?? ''),
            'batch'        => (string) ($_GET['batch'] ?? ''),
            'studRole'     => (string) ($_GET['studRole'] ?? 'all'),
            'type'         => (string) ($_GET['type'] ?? ''),
            'q'            => (string) ($_GET['q'] ?? $_GET['search'] ?? ''),
        ];
        Response::success(DocumentHelper::jsonSafe(
            (new StaffPlacementRegistryService())->list($ctx, $filters)
        ));
    }

    /** POST /api/staff/placements-higher-education/sync-from-aes */
    public function syncPlacementsFromAes(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $filters = [
            'departmentId' => (string) ($_GET['departmentId'] ?? $_POST['departmentId'] ?? ''),
            'program'      => (string) ($_GET['program'] ?? $_POST['program'] ?? ''),
            'branch'       => (string) ($_GET['branch'] ?? $_POST['branch'] ?? ''),
            'batch'        => (string) ($_GET['batch'] ?? $_POST['batch'] ?? ''),
            'studRole'     => 'all',
        ];
        $registrySvc = new StaffPlacementRegistryService();
        $result = $registrySvc->syncFromAes($ctx, $filters);
        $count = (int) ($result['studentsSynced'] ?? 0);
        $studying = (int) ($result['studyingSynced'] ?? 0);
        $alumni = (int) ($result['alumniSynced'] ?? 0);
        $listFilters = [
            'departmentId' => (string) ($filters['departmentId'] ?? ''),
            'program'      => (string) ($filters['program'] ?? ''),
            'branch'       => (string) ($filters['branch'] ?? ''),
            'batch'        => (string) ($filters['batch'] ?? ''),
            'studRole'     => (string) ($filters['studRole'] ?? 'all'),
            'type'         => '',
            'q'            => '',
        ];
        $inTable = (int) (($registrySvc->list($ctx, $listFilters)['totals']['all'] ?? 0));
        $result['rowsInTable'] = $inTable;
        $message = $count > 0
            ? "Synced {$count} record(s) from AES ({$studying} studying, {$alumni} alumni) into student_placements."
            : ($inTable > 0
                ? "AES added no new rows; showing {$inTable} record(s) already in student_placements for these filters."
                : 'No rows in student_placements for these filters yet. AES returned no roster to import — try a specific batch or check AES connectivity.');
        Response::success(
            DocumentHelper::jsonSafe($result),
            $message
        );
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

    /** GET /api/staff/recruiting — department snapshot (same scope as placement officer). */
    public function recruitingOverview(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $deptId = trim((string) ($ctx['departmentId'] ?? ''));
        $filterCtx = StaffContext::officerCompatible($ctx);
        $lite = isset($_GET['lite']) && (string) $_GET['lite'] !== '0' && (string) $_GET['lite'] !== '';
        Response::success(DocumentHelper::jsonSafe(
            (new RecruitingService())->getCampusOverview(
                $deptId !== '' ? $deptId : null,
                $filterCtx,
                $lite
            )
        ));
    }

    /** GET /api/staff/dashboard-stats — department-scoped analytics (hiring trend, placement stats). */
    public function dashboardStats(): void
    {
        $scope = (new StaffDataService())->requireScope();
        $lite = isset($_GET['lite']) && (string) $_GET['lite'] !== '0' && (string) $_GET['lite'] !== '';
        Response::success(DocumentHelper::jsonSafe(
            (new StaffDataService())->dashboardStats($scope['officerCtx'], (string) $scope['user']['_id'], !$lite)
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

    /** GET /api/staff/courses/search?q=26MCA */
    public function searchCourses(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $query = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
        if (strlen($query) > 40) {
            $query = substr($query, 0, 40);
        }
        $dept = $this->staffDepartment($user);
        $campusWide = RBACMiddleware::seesAllSyllabusCourses($user);
        $departmentName = $campusWide ? 'all departments' : ($dept['name'] !== '' ? $dept['name'] : $dept['code']);
        if (strlen($query) < 3) {
            Response::success([
                'courses' => [],
                'total' => 0,
                'departmentName' => $departmentName,
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $response = (new AesApiService())->searchSyllabus4Placement($query);
        if (empty($response['success'])) {
            Response::error((string) ($response['error'] ?? 'Could not search the syllabus.'), 502);
        }
        $courses = CourseSyllabusCatalog::filterSearchRows(
            $response['data'] ?? [],
            $dept['code'],
            $dept['name'],
            $dept['shortName'],
            $campusWide
        );
        Response::success([
            'courses' => array_slice($courses, 0, 40),
            'total' => count($courses),
            'departmentName' => $departmentName,
        ]);
    }

    /** POST /api/staff/courses/get */
    public function getCourse(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $semsubId = trim((string) ($body['semsubId'] ?? $body['id'] ?? ''));
        $courseCode = strtoupper(trim((string) ($body['courseCode'] ?? $body['code'] ?? '')));
        if ($semsubId === '') {
            Response::error('Select a course, then click Get.', 422);
        }
        $dept = $this->staffDepartment($user);
        if ($courseCode !== '' && !RBACMiddleware::seesAllSyllabusCourses($user) && !CourseSyllabusCatalog::subjectVisibleToStaff($courseCode, $dept['code'], $dept['name'], $dept['shortName'])) {
            Response::error('That course is outside your department.', 422);
        }
        $encid = AesSyllabusCipher::encrypt($semsubId);
        if ($encid === '') {
            Response::error('Could not encode that course id.', 500);
        }
        Response::success([
            'semsubId' => $semsubId,
            'encid' => $encid,
            'courseCode' => $courseCode,
            'departmentName' => $dept['name'] !== '' ? $dept['name'] : $dept['code'],
        ]);
    }

    /** POST /api/staff/courses/syllabus/prepare — fetch PDF and extract text for AI (separate from PDF download). */
    public function prepareSyllabusForAi(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $result = (new StaffCourseQuestionService())->prepareSyllabusForAi($user, $body);
            $readable = !empty($result['syllabusReadable']);
            $message = $readable
                ? 'Syllabus text is ready for AI question generation.'
                : 'The PDF was loaded, but the server could not read enough text for AI (often a scanned syllabus).';
            Response::success($result, $message);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 502);
        }
    }

    /** GET /api/staff/courses/syllabus */
    public function downloadSyllabus(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $semsubId = trim((string) ($_GET['semsubId'] ?? $_GET['id'] ?? ''));
        $courseCode = strtoupper(trim((string) ($_GET['courseCode'] ?? $_GET['code'] ?? '')));
        if ($semsubId === '') {
            Response::error('Select a course, then click Get.', 422);
        }
        $dept = $this->staffDepartment($user);
        if ($courseCode !== '' && !RBACMiddleware::seesAllSyllabusCourses($user) && !CourseSyllabusCatalog::subjectVisibleToStaff($courseCode, $dept['code'], $dept['name'], $dept['shortName'])) {
            Response::forbidden('That course is outside your department.');
        }
        $encid = AesSyllabusCipher::encrypt($semsubId);
        if ($encid === '') {
            Response::error('Could not encode that course id.', 500);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        try {
            $pdf = AesSyllabusCipher::fetchPdf($encid);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 502);
        }
        $file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $courseCode !== '' ? $courseCode : 'syllabus') ?: 'syllabus';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    /** GET /api/staff/courses */
    public function listCourses(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $dept = $this->staffDepartment($user);
        $campusWide = RBACMiddleware::seesAllSyllabusCourses($user);
        Response::success([
            'courses' => $campusWide ? [] : CourseSyllabusCatalog::forStaff($dept['code'], $dept['name'], $dept['shortName']),
            'departmentCode' => $dept['code'],
            'departmentName' => $dept['name'] !== '' ? $dept['name'] : $dept['code'],
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{code:string,name:string,shortName:string}
     */
    private function staffDepartment(array $user): array
    {
        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];

        return [
            'code' => (string) ($dept['code'] ?? ''),
            'name' => (string) ($dept['name'] ?? ''),
            'shortName' => (string) ($dept['shortName'] ?? ''),
        ];
    }

    /** GET /api/staff/courses/questions/generate-progress?key=... */
    public function courseQuestionsGenerateProgress(): void
    {
        RBACMiddleware::requireSyllabusAccess();
        $key = (string) ($_GET['key'] ?? '');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $progress = StaffCourseQuestionService::readGenerationProgress($key);
        if ($progress === null) {
            Response::success(['active' => false]);
        }
        Response::success(array_merge(['active' => true], $progress));
    }

    /** POST /api/staff/courses/questions/cancel */
    public function cancelCourseQuestionGeneration(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $result = (new StaffCourseQuestionService())->cancelGeneration($user, $body);
            Response::success($result, 'Generation stopped. You can add the questions generated so far.');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/staff/courses/questions/batch */
    public function generateCourseQuestionsBatch(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $result = (new StaffCourseQuestionService())->generateBatch($user, $body);
            $requested = (int) ($result['requested'] ?? 0);
            $got = (int) ($result['accumulatedCount'] ?? count($result['questions'] ?? []));
            $batchNum = (int) ($result['batchIndex'] ?? 0) + 1;
            $batchTotal = (int) ($result['batchTotal'] ?? 1);
            $complete = !empty($result['batchComplete']);
            if (!$complete) {
                $message = "Batch {$batchNum} of {$batchTotal} complete ({$got} / {$requested} so far).";
            } else {
                $message = 'Questions generated. Select the ones you want to add to the syllabus question bank.';
                if ($requested > 0 && $got < $requested) {
                    $message = "Generated {$got} of {$requested} requested. Select the ones to add, or generate again for more.";
                }
            }
            Response::success($result, $message);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 503);
        }
    }

    /** POST /api/staff/courses/questions */
    public function generateCourseQuestions(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $result = (new StaffCourseQuestionService())->generate($user, $body);
            $requested = (int) ($result['requested'] ?? 0);
            $got = count($result['questions'] ?? []);
            $message = 'Questions generated. Select the ones you want to add to the syllabus question bank.';
            if ($requested > 0 && $got < $requested) {
                $message = "Generated {$got} of {$requested} requested. Select the ones to add, or generate again for more.";
            }
            Response::success($result, $message);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 503);
        }
    }

    /** POST /api/staff/courses/questions/save */
    public function saveCourseQuestions(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $result = (new StaffCourseQuestionService())->saveSelected($user, $body);
            $added = (int) ($result['added'] ?? 0);
            $skipped = (int) ($result['skipped'] ?? 0);
            $code = (string) ($result['courseCode'] ?? '');
            $message = $added > 0
                ? ($added . ' question' . ($added === 1 ? '' : 's') . ' added to ' . $code . '.')
                : 'No new questions were added.';
            if ($skipped > 0) {
                $message .= ' ' . $skipped . ' already in the bank.';
            }
            Response::success($result, $message);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/staff/courses/questions/practice */
    public function startCoursePractice(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            Response::success(
                (new StaffCourseQuestionService())->startPractice($user, $body),
                'MCQ started. Choose an option for each question, then submit.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/staff/courses/questions/submit */
    public function submitCoursePractice(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }
        try {
            Response::success(
                (new StaffCourseQuestionService())->submit($user, $body),
                'Practice submitted.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** GET /api/staff/courses/question-bank?courseCode=26MCAT107 */
    public function listSyllabusQuestionBank(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $courseCode = trim((string) ($_GET['courseCode'] ?? $_GET['code'] ?? ''));
        try {
            Response::success((new StaffCourseQuestionService())->listBank($user, $courseCode));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** GET /api/staff/courses/mcq-tests */
    public function listSyllabusMcqTests(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        Response::success((new StaffCourseQuestionService())->listTests($user));
    }

    /** GET /api/staff/courses/mcq-tests/{id} */
    public function getSyllabusMcqTest(string $id): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        try {
            Response::success((new StaffCourseQuestionService())->getTest($user, $id));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/staff/courses/mcq-tests */
    public function createSyllabusMcqTest(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            Response::success(
                (new StaffCourseQuestionService())->createTest($user, $body),
                'MCQ created.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** DELETE /api/staff/courses/mcq-tests/{id} */
    public function deleteSyllabusMcqTest(string $id): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        try {
            Response::success(
                (new StaffCourseQuestionService())->deleteTest($user, $id),
                'MCQ removed.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/staff/courses/question-bank/bulk-delete */
    public function bulkDeleteSyllabusBankQuestions(): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        try {
            $result = (new StaffCourseQuestionService())->bulkDeleteBankQuestions($user, $ids);
            $deleted = (int) ($result['deleted'] ?? 0);
            Response::success(
                $result,
                $deleted === 1 ? 'Question removed from the syllabus bank.' : "{$deleted} questions removed from the syllabus bank."
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** DELETE /api/staff/courses/question-bank/{id} */
    public function deleteSyllabusBankQuestion(string $id): void
    {
        $user = RBACMiddleware::requireSyllabusAccess();
        try {
            Response::success(
                (new StaffCourseQuestionService())->deleteBankQuestion($user, $id),
                'Question removed from the syllabus bank.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }
}
