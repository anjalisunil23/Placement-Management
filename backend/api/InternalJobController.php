<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Middleware\RBACMiddleware;
use PMS\Models\DepartmentModel;
use PMS\Models\InternalJobApplicationModel;
use PMS\Models\InternalJobPostModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Services\InternalJobService;
use PMS\Services\ObjectStorageService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Department-scoped internal job posts (part-time jobs and internships).
 */
final class InternalJobController
{
    /** GET /api/internal-jobs */
    public function index(): void
    {
        $user = AuthMiddleware::authenticate();
        $role = AuthMiddleware::resolvedRole($user);
        $posts = new InternalJobPostModel();
        $apps = new InternalJobApplicationModel();

        if ($role === 'student') {
            $student = $this->studentContext($user);
            if ($student['departmentId'] === '' && $student['departmentCode'] === '') {
                Response::success([
                    'access' => $this->accessPayload('student', false, true, null),
                    'posts' => [],
                ], 'Your department is not set, so internal job posts are hidden.');
            }
            $rows = $posts->findAll([], 500);
            $appliedIds = [];
            foreach ($apps->findByStudent((string) $user['_id']) as $app) {
                $appliedIds[(string) ($app['postId'] ?? '')] = true;
            }
            $out = [];
            foreach ($rows as $row) {
                if (!in_array((string) ($row['status'] ?? ''), ['published', 'closed'], true)) {
                    continue;
                }
                if (!InternalJobService::departmentMatches($row, $student['departmentId'], $student['departmentCode'])) {
                    continue;
                }
                $view = $this->presentForStudent($row, $student, isset($appliedIds[(string) ($row['_id'] ?? '')]), $apps);
                if (!$this->passesFilters($view, $row, true)) {
                    continue;
                }
                $out[] = $view;
            }
            Response::success([
                'access' => $this->accessPayload('student', false, true, [
                    'id' => $student['departmentId'],
                    'code' => $student['departmentCode'],
                    'name' => $student['departmentName'],
                ]),
                'posts' => $out,
            ]);
        }

        $scope = $this->managerScope($user);
        if ($scope === null) {
            Response::forbidden('You do not have permission to view internal job posts.');
        }
        $rows = $posts->findAll([], 500);
        $out = [];
        foreach ($rows as $row) {
            if (!$this->managerCanTouch($scope, $row)) {
                continue;
            }
            $view = $this->presentForManager($row, $apps);
            if (!$this->passesFilters($view, $row, false)) {
                continue;
            }
            $out[] = $view;
        }
        Response::success([
            'access' => $this->accessPayload(
                $scope['kind'],
                $scope['canManage'],
                $scope['department'] !== null,
                $scope['department']
            ),
            'posts' => $out,
        ]);
    }

    /** GET /api/internal-jobs/{id} */
    public function show(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $post = (new InternalJobPostModel())->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        $role = AuthMiddleware::resolvedRole($user);
        $apps = new InternalJobApplicationModel();
        if ($role === 'student') {
            $student = $this->studentContext($user);
            if ((string) ($post['status'] ?? '') === 'draft'
                || !InternalJobService::departmentMatches($post, $student['departmentId'], $student['departmentCode'])) {
                Response::notFound('Internal job post not found.');
            }
            $applied = $apps->findByPostAndStudent($id, (string) $user['_id']) !== null;
            Response::success($this->presentForStudent($post, $student, $applied, $apps));
        }
        $scope = $this->managerScope($user);
        if ($scope === null || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to view this post.');
        }
        Response::success($this->presentForManager($post, $apps));
    }

    /** POST /api/internal-jobs */
    public function create(): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        if ($scope === null || !$scope['canManage']) {
            Response::forbidden('Only an admin or a department placement officer can create internal job posts.');
        }
        $input = $this->readInput();
        $publish = $this->wantsPublish($input);
        $department = $this->resolveDepartment($scope, (string) ($input['departmentId'] ?? ''));
        $input['departmentId'] = $department['id'];
        $check = InternalJobService::validate($input, $publish);
        if (!$check['ok']) {
            Response::error($check['message'], 422, $check['errors']);
        }
        $now = DocumentHelper::now();
        $doc = $check['data'];
        $doc['departmentCode'] = $department['code'];
        $doc['departmentName'] = $department['name'];
        $doc['attachment'] = $this->storeAttachment(null, $input);
        $doc['status'] = $publish ? 'published' : 'draft';
        $doc['createdBy'] = (string) $user['_id'];
        $doc['createdByRole'] = $scope['kind'] === 'admin' ? 'admin' : 'placement_officer';
        $doc['publishedAt'] = $publish ? $now : null;
        $doc['closedAt'] = null;
        $id = (new InternalJobPostModel())->insert($doc);
        $saved = (new InternalJobPostModel())->findById($id) ?? [];
        Response::success(
            $this->presentForManager($saved, new InternalJobApplicationModel()),
            $publish ? 'Internal job post published.' : 'Draft saved.',
            201
        );
    }

    /** POST /api/internal-jobs/{id}/save */
    public function save(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        $model = new InternalJobPostModel();
        $post = $model->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        if ($scope === null || !$scope['canManage'] || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to update this post.');
        }
        $input = $this->readInput();
        $publish = $this->wantsPublish($input);
        $current = (string) ($post['status'] ?? 'draft');
        $forPublish = $publish || $current === 'published';
        $department = $this->resolveDepartment($scope, (string) ($input['departmentId'] ?? $post['departmentId'] ?? ''));
        $input['departmentId'] = $department['id'];
        $check = InternalJobService::validate($input, $forPublish);
        if (!$check['ok']) {
            Response::error($check['message'], 422, $check['errors']);
        }
        $doc = $check['data'];
        $doc['departmentCode'] = $department['code'];
        $doc['departmentName'] = $department['name'];
        $doc['attachment'] = $this->storeAttachment(is_array($post['attachment'] ?? null) ? $post['attachment'] : null, $input);
        if ($publish) {
            $doc['status'] = 'published';
            $doc['publishedAt'] = DocumentHelper::now();
            $doc['closedAt'] = null;
        }
        $model->update($id, $doc);
        $saved = $model->findById($id) ?? [];
        Response::success(
            $this->presentForManager($saved, new InternalJobApplicationModel()),
            $publish ? 'Internal job post published.' : 'Internal job post saved.'
        );
    }

    /** POST /api/internal-jobs/{id}/publish */
    public function publish(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        $model = new InternalJobPostModel();
        $post = $model->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        if ($scope === null || !$scope['canManage'] || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to publish this post.');
        }
        $check = InternalJobService::validate($post, true);
        if (!$check['ok']) {
            Response::error($check['message'], 422, $check['errors']);
        }
        $model->update($id, [
            'status' => 'published',
            'publishedAt' => DocumentHelper::now(),
            'closedAt' => null,
        ]);
        $saved = $model->findById($id) ?? [];
        Response::success($this->presentForManager($saved, new InternalJobApplicationModel()), 'Internal job post published.');
    }

    /** POST /api/internal-jobs/{id}/close */
    public function close(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        $model = new InternalJobPostModel();
        $post = $model->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        if ($scope === null || !$scope['canManage'] || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to close this post.');
        }
        if ((string) ($post['status'] ?? '') === 'draft') {
            Response::error('Publish the post before closing it.', 422);
        }
        $model->update($id, [
            'status' => 'closed',
            'closedAt' => DocumentHelper::now(),
        ]);
        $saved = $model->findById($id) ?? [];
        Response::success($this->presentForManager($saved, new InternalJobApplicationModel()), 'Internal job post closed.');
    }

    /** DELETE /api/internal-jobs/{id} */
    public function delete(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        $model = new InternalJobPostModel();
        $post = $model->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        if ($scope === null || !$scope['canManage'] || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to delete this post.');
        }
        $attachment = is_array($post['attachment'] ?? null) ? $post['attachment'] : [];
        if (!empty($attachment['path'])) {
            try {
                (new ObjectStorageService())->delete((string) $attachment['path']);
            } catch (\Throwable) {
                // Removing the post still succeeds if storage cleanup fails.
            }
        }
        $model->delete($id);
        Response::success(null, 'Internal job post deleted.');
    }

    /** POST /api/internal-jobs/{id}/apply */
    public function apply(string $id): void
    {
        $user = RBACMiddleware::requireStudent();
        $model = new InternalJobPostModel();
        $post = $model->findById($id);
        if (!$post || (string) ($post['status'] ?? '') === 'draft') {
            Response::notFound('Internal job post not found.');
        }
        $student = $this->studentContext($user);
        if (!InternalJobService::departmentMatches($post, $student['departmentId'], $student['departmentCode'])) {
            Response::forbidden('This post is for a different department.');
        }
        $apps = new InternalJobApplicationModel();
        $existing = $apps->findByPostAndStudent($id, (string) $user['_id']);
        $decision = InternalJobService::applyDecision($post, [
            'applied' => $existing !== null,
            'departmentId' => $student['departmentId'],
            'departmentCode' => $student['departmentCode'],
            'today' => $this->today(),
            'applicantCount' => $apps->countForPost($id),
            'cgpa' => $student['cgpa'],
        ]);
        if (!$decision['canApply']) {
            Response::error($decision['reason'] !== '' ? $decision['reason'] : 'You cannot apply for this post.', 422);
        }
        $apps->insert([
            'postId' => $id,
            'studentUserId' => (string) $user['_id'],
            'studentName' => (string) ($user['name'] ?? ''),
            'registerNumber' => $student['registerNumber'],
            'departmentId' => $student['departmentId'],
            'departmentCode' => $student['departmentCode'],
            'status' => 'applied',
            'appliedAt' => DocumentHelper::now(),
        ]);
        Response::success(
            $this->presentForStudent($post, $student, true, $apps),
            'Application submitted.',
            201
        );
    }

    /** GET /api/internal-jobs/{id}/applications */
    public function applications(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $scope = $this->managerScope($user);
        $post = (new InternalJobPostModel())->findById($id);
        if (!$post) {
            Response::notFound('Internal job post not found.');
        }
        if ($scope === null || !$scope['canManage'] || !$this->managerCanTouch($scope, $post)) {
            Response::forbidden('You do not have permission to view these applications.');
        }
        $rows = [];
        foreach ((new InternalJobApplicationModel())->findByPost($id) as $app) {
            $rows[] = [
                'id' => (string) ($app['_id'] ?? ''),
                'studentName' => (string) ($app['studentName'] ?? ''),
                'registerNumber' => (string) ($app['registerNumber'] ?? ''),
                'departmentCode' => (string) ($app['departmentCode'] ?? ''),
                'appliedAt' => (string) ($app['appliedAt'] ?? $app['createdAt'] ?? ''),
                'status' => (string) ($app['status'] ?? 'applied'),
            ];
        }
        Response::success($rows);
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $user
     * @return array{kind:string,canManage:bool,department:?array{id:string,code:string,name:string}}|null
     */
    private function managerScope(array $user): ?array
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            return [
                'kind' => 'admin',
                'canManage' => true,
                'department' => null,
            ];
        }
        if ($role !== 'placement_officer') {
            return null;
        }
        $department = $this->officerDepartment($user);
        if ($department === null) {
            return [
                'kind' => 'placement_officer',
                'canManage' => false,
                'department' => null,
            ];
        }

        return [
            'kind' => 'department_officer',
            'canManage' => true,
            'department' => $department,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id:string,code:string,name:string}|null
     */
    private function officerDepartment(array $user): ?array
    {
        $userId = (string) ($user['_id'] ?? '');
        $deptId = '';
        $officer = (new PlacementOfficerModel())->findByUserId($userId);
        if ($officer && !empty($officer['departmentId'])) {
            $deptId = (string) $officer['departmentId'];
        }
        if ($deptId === '') {
            $staff = (new StaffModel())->findByUserId($userId);
            if ($staff && !empty($staff['departmentId'])) {
                $deptId = (string) $staff['departmentId'];
            }
        }
        if ($deptId === '') {
            return null;
        }
        $dept = (new DepartmentModel())->findById($deptId);
        if (!$dept) {
            return null;
        }

        return [
            'id' => (string) ($dept['_id'] ?? $deptId),
            'code' => trim((string) ($dept['code'] ?? '')),
            'name' => trim((string) ($dept['name'] ?? '')),
        ];
    }

    /**
     * @param array{kind:string,canManage:bool,department:?array{id:string,code:string,name:string}} $scope
     * @return array{id:string,code:string,name:string}
     */
    private function resolveDepartment(array $scope, string $requestedId): array
    {
        if ($scope['department'] !== null) {
            return $scope['department'];
        }
        $requestedId = trim($requestedId);
        if ($requestedId === '') {
            Response::error('Select a department.', 422);
        }
        $dept = (new DepartmentModel())->findById($requestedId);
        if (!$dept || !DepartmentModel::isStudentAcademicDepartment(
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? '')
        )) {
            Response::error('Select a valid student department.', 422);
        }

        return [
            'id' => (string) ($dept['_id'] ?? $requestedId),
            'code' => trim((string) ($dept['code'] ?? '')),
            'name' => trim((string) ($dept['name'] ?? '')),
        ];
    }

    /**
     * @param array{kind:string,canManage:bool,department:?array{id:string,code:string,name:string}} $scope
     * @param array<string, mixed> $post
     */
    private function managerCanTouch(array $scope, array $post): bool
    {
        if ($scope['kind'] === 'admin') {
            return true;
        }
        if (!$scope['canManage'] || $scope['department'] === null) {
            return false;
        }

        return InternalJobService::departmentMatches(
            $post,
            $scope['department']['id'],
            $scope['department']['code']
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array{departmentId:string,departmentCode:string,departmentName:string,cgpa:float,registerNumber:string}
     */
    private function studentContext(array $user): array
    {
        $profile = (new StudentModel())->findByUserId((string) ($user['_id'] ?? ''));
        $deptId = $profile ? trim((string) ($profile['departmentId'] ?? '')) : '';
        $dept = $deptId !== '' ? (new DepartmentModel())->findById($deptId) : null;
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];

        return [
            'departmentId' => $dept ? (string) ($dept['_id'] ?? $deptId) : $deptId,
            'departmentCode' => trim((string) ($dept['code'] ?? '')),
            'departmentName' => trim((string) ($dept['name'] ?? '')),
            'cgpa' => (float) ($academic['cgpa'] ?? 0),
            'registerNumber' => trim((string) ($profile['registerNumber'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function storeAttachment(?array $existing, array $input = []): ?array
    {
        $file = $_FILES['attachment'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return $this->storeUploadedFile($file, $existing);
        }

        $encoded = preg_replace('/\s+/', '', (string) ($input['attachmentBase64'] ?? '')) ?? '';
        $name = trim((string) ($input['attachmentName'] ?? ''));
        if ($encoded === '' || $name === '') {
            return $existing;
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            Response::error('The attachment could not be read.', 400);
        }
        if (strlen($binary) > 10 * 1024 * 1024) {
            Response::error('File exceeds maximum allowed size.', 400);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ijob');
        if ($tmp === false) {
            Response::error('Failed to save the attachment.', 500);
        }
        if (file_put_contents($tmp, $binary) === false) {
            unlink($tmp);
            Response::error('Failed to save the attachment.', 500);
        }
        try {
            return $this->storeUploadedFile([
                'name' => $name,
                'type' => '',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($binary),
            ], $existing);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function storeUploadedFile(array $file, ?array $existing): array
    {
        $error = Security::validateUploadedFile(
            $file,
            10 * 1024 * 1024,
            ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp']
        );
        if ($error) {
            Response::error($error, 400);
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $hint = 'internal_job_' . time() . '.' . $ext;
        $config = require dirname(__DIR__) . '/config/app.php';
        $storage = new ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(ObjectStorageService::FOLDER_JD, $hint, $file);
        } catch (\Throwable) {
            Response::error('Failed to save the attachment.', 500);
        }
        if (!empty($existing['path'])) {
            try {
                $storage->delete((string) $existing['path']);
            } catch (\Throwable) {
                // Keep the new file even if the previous one cannot be removed.
            }
        }
        $filename = $storage->storedNameFromUri($path);

        return [
            'name' => (string) ($file['name'] ?? $filename),
            'file' => $filename,
            'path' => $path,
            'url' => $storage->mediaUrl(ObjectStorageService::FOLDER_JD, $filename),
            'uploadedAt' => DocumentHelper::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readInput(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function wantsPublish(array $input): bool
    {
        return strtolower(trim((string) ($input['action'] ?? ''))) === 'publish';
    }

    /**
     * @param array<string, mixed> $post
     * @param array{departmentId:string,departmentCode:string,departmentName:string,cgpa:float,registerNumber:string} $student
     * @return array<string, mixed>
     */
    private function presentForStudent(array $post, array $student, bool $applied, InternalJobApplicationModel $apps): array
    {
        $view = InternalJobService::present($post);
        $count = $apps->countForPost((string) ($post['_id'] ?? ''));
        $decision = InternalJobService::applyDecision($post, [
            'applied' => $applied,
            'departmentId' => $student['departmentId'],
            'departmentCode' => $student['departmentCode'],
            'today' => $this->today(),
            'applicantCount' => $count,
            'cgpa' => $student['cgpa'],
        ]);
        $view['applied'] = $applied;
        $view['canApply'] = $decision['canApply'];
        $view['applyBlockReason'] = $decision['reason'];
        $view['applicantCount'] = $count;

        return $view;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function presentForManager(array $post, InternalJobApplicationModel $apps): array
    {
        $view = InternalJobService::present($post);
        $view['applicantCount'] = $apps->countForPost((string) ($post['_id'] ?? ''));
        $view['applied'] = false;
        $view['canApply'] = false;
        $view['applyBlockReason'] = '';

        return $view;
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, mixed> $post
     */
    private function passesFilters(array $view, array $post, bool $student): bool
    {
        $jobType = strtolower(trim((string) ($_GET['jobType'] ?? '')));
        if ($jobType !== '' && $jobType !== 'all') {
            $normalized = InternalJobService::normalizeJobType($jobType);
            if ($normalized !== '' && (string) ($post['jobType'] ?? '') !== $normalized) {
                return false;
            }
        }
        $compensation = strtolower(trim((string) ($_GET['compensation'] ?? '')));
        if ($compensation !== '' && $compensation !== 'all'
            && !InternalJobService::matchesCompensationFilter($post, $compensation)) {
            return false;
        }
        $mode = strtolower(trim((string) ($_GET['workMode'] ?? '')));
        if ($mode !== '' && $mode !== 'all') {
            $normalized = InternalJobService::normalizeWorkMode($mode);
            if ($normalized !== '' && InternalJobService::normalizeWorkMode((string) ($post['workMode'] ?? '')) !== $normalized) {
                return false;
            }
        }
        $departmentId = trim((string) ($_GET['departmentId'] ?? ''));
        if (!$student && $departmentId !== '' && $departmentId !== 'all'
            && strcasecmp((string) ($post['departmentId'] ?? ''), $departmentId) !== 0) {
            return false;
        }
        $location = strtolower(trim((string) ($_GET['location'] ?? '')));
        if ($location !== '' && !str_contains(strtolower((string) ($post['workLocation'] ?? '')), $location)) {
            return false;
        }
        if ($student) {
            $applicationStatus = strtolower(trim((string) ($_GET['applicationStatus'] ?? 'all')));
            if ($applicationStatus === 'applied' && empty($view['applied'])) {
                return false;
            }
            if ($applicationStatus === 'not_applied' && !empty($view['applied'])) {
                return false;
            }
        } else {
            $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
            if (in_array($status, InternalJobService::STATUSES, true) && (string) ($post['status'] ?? '') !== $status) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{id:string,code:string,name:string}|null $department
     * @return array<string, mixed>
     */
    private function accessPayload(string $kind, bool $canManage, bool $departmentLocked, ?array $department): array
    {
        $departments = [];
        if ($kind === 'admin') {
            foreach ((new DepartmentModel())->findAll([], 400, 0, ['name' => 1]) as $dept) {
                $code = trim((string) ($dept['code'] ?? ''));
                $name = trim((string) ($dept['name'] ?? ''));
                if (!DepartmentModel::isStudentAcademicDepartment($code, $name)) {
                    continue;
                }
                $departments[] = [
                    'id' => (string) ($dept['_id'] ?? ''),
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }

        return [
            'kind' => $kind,
            'canManage' => $canManage,
            'departmentLocked' => $departmentLocked && $kind !== 'admin',
            'departmentId' => $department['id'] ?? '',
            'departmentCode' => $department['code'] ?? '',
            'departmentName' => $department['name'] ?? '',
            'departments' => $departments,
        ];
    }
}
