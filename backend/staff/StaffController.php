<?php

declare(strict_types=1);

namespace PMS\Staff;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\NotificationModel;
use PMS\Models\RecommendationModel;
use PMS\Models\StaffModel;
use PMS\Models\UserModel;
use PMS\Services\StaffContext;
use PMS\Services\StaffService;
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
        $profile = $this->staffModel->findByUserId((string) $user['_id']);
        if (!$profile) {
            Response::notFound('Staff profile not found.');
        }
        return $profile;
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
        $data['user'] = [
            'name'        => (string) ($merged['name'] ?? $user['name'] ?? ''),
            'email'       => (string) ($merged['email'] ?? $user['email'] ?? ''),
            'phone'       => (string) ($merged['phone'] ?? $profile['phone'] ?? ''),
            'designation' => (string) ($merged['designation'] ?? $profile['designation'] ?? ''),
        ];
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
            'phone' => 'required',
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
        RBACMiddleware::requireStaff();
        $drives = (new DriveModel())->findAll([], 100);
        $companyModel = new CompanyModel();
        $serialized = array_map(static function (array $drive) use ($companyModel) {
            $out = DocumentHelper::serialize($drive);
            $company = $companyModel->findById((string) ($drive['companyId'] ?? ''));
            $out['company'] = $company['companyName'] ?? '';
            $out['role'] = $drive['title'] ?? '';
            $out['package'] = $drive['tier'] ?? '';
            return $out;
        }, $drives);
        Response::success($serialized);
    }

    /** GET /api/staff/students */
    public function listStudents(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        $rows = (new StaffService())->listStudents($ctx['departmentId']);
        Response::success($rows);
    }

    /** GET /api/staff/students/{id}/pipeline */
    public function studentPipeline(string $studentId): void
    {
        RBACMiddleware::requireStaff();
        Response::success((new StaffService())->studentPipeline($studentId));
    }

    /** GET /api/staff/hiring-overview */
    public function hiringOverview(): void
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        Response::success((new StaffService())->hiringOverview($ctx['departmentId']));
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeRecommendationInput(array $input): array
    {
        if (!empty($input['hrName']) || !empty($input['hrEmail']) || !empty($input['contactNumber'])) {
            $input['contact'] = [
                'name'  => trim((string) ($input['hrName'] ?? $input['contact']['name'] ?? '')),
                'email' => trim((string) ($input['hrEmail'] ?? $input['contact']['email'] ?? '')),
                'phone' => trim((string) ($input['contactNumber'] ?? $input['contact']['phone'] ?? '')),
            ];
        }
        if (!is_array($input['contact'] ?? null)) {
            $input['contact'] = ['name' => '', 'email' => '', 'phone' => ''];
        }
        $input['category'] = $input['category'] ?? 'General';
        $input['reason'] = $input['reason'] ?? 'Referred by faculty for campus recruitment.';
        return $input;
    }
}
