<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Department-scoped data access and enrichment for placement officers.
 */
final class OfficerDataService
{
    /**
     * @return array{user: array<string, mixed>, ctx: array<string, mixed>}
     */
    public function requireScope(): array
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $ctx = PlacementOfficerContext::resolve($user);
        return ['user' => $user, 'ctx' => $ctx];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function applicationFilter(array $ctx, array $filter = []): array
    {
        if ($ctx['isAdmin']) {
            return $filter;
        }

        $studentIds = PlacementOfficerContext::studentIdsInDepartment($ctx);
        if ($studentIds === []) {
            return ['studentId' => ['$in' => []]];
        }

        $oids = array_values(array_filter(array_map(
            fn (string $id) => Security::toObjectId($id),
            $studentIds
        )));
        $filter['studentId'] = ['$in' => $oids];
        return $filter;
    }

    /**
     * @param array<int, array<string, mixed>> $apps
     * @return array<int, array<string, mixed>>
     */
    public function enrichApplications(array $apps): array
    {
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $companyModel = new CompanyModel();
        $deptModel = new DepartmentModel();
        $driveModel = new DriveModel();

        $rows = [];
        foreach ($apps as $app) {
            $student = $studentModel->findById((string) ($app['studentId'] ?? ''));
            $user = $student ? $userModel->findById((string) ($student['userId'] ?? '')) : null;
            $company = $companyModel->findById((string) ($app['companyId'] ?? ''));
            $drive = $driveModel->findById((string) ($app['driveId'] ?? ''));
            $dept = $student ? $deptModel->findById((string) ($student['departmentId'] ?? '')) : null;

            $status = $app['status'] ?? 'applied';
            $stage = match ($status) {
                'applied', 'resume_pending' => 'resume_verification',
                'resume_verified' => 'resume_verification',
                'officer_approved' => 'approval',
                'company_review', 'shortlisted' => 'company_selection',
                'selected' => 'company_selection',
                'rejected' => 'rejected',
                default => $status,
            };

            $studentResume = is_array($student['resume'] ?? null) ? $student['resume'] : [];
            $appResume = is_array($app['resume'] ?? null) ? $app['resume'] : [];
            $resumePath = (string) ($appResume['path'] ?? $studentResume['path'] ?? '');
            $resumeFile = (string) ($appResume['fileName'] ?? $studentResume['filename'] ?? '');
            if ($resumeFile === '' && $resumePath !== '') {
                $resumeFile = basename($resumePath);
            }

            $companyName = (string) ($company['companyName'] ?? '');
            if ($companyName === '' && is_array($drive)) {
                $title = (string) ($drive['title'] ?? '');
                if (str_contains($title, '—')) {
                    $companyName = trim((string) (explode('—', $title, 2)[1] ?? ''));
                }
            }

            $row = DocumentHelper::serialize($app) ?? [];
            $row['driveId'] = (string) ($app['driveId'] ?? '');
            $row['studentId'] = (string) ($app['studentId'] ?? '');
            $row['studentName'] = is_array($user) ? (string) ($user['name'] ?? '') : '';
            $row['registerNumber'] = is_array($student) ? (string) ($student['registerNumber'] ?? '') : '';
            $row['department'] = is_array($dept) ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '';
            $row['company'] = $companyName;
            $row['role'] = $drive['title'] ?? '';
            $row['stage'] = $stage;
            $row['appliedAt'] = $row['createdAt'] ?? null;
            $row['resumeLabel'] = (string) ($appResume['label'] ?? '');
            $row['resumeFileName'] = $resumeFile;
            $row['resumePath'] = $resumePath;
            $row['hasResume'] = $resumePath !== '';
            $certs = is_array($app['certificates'] ?? null) ? $app['certificates'] : [];
            $row['certificateCount'] = count($certs);
            $row['certificates'] = array_map(static function (array $cert) {
                return [
                    'fileName' => (string) ($cert['fileName'] ?? ''),
                    'label'    => (string) ($cert['label'] ?? ''),
                ];
            }, $certs);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function listApplications(array $ctx, array $filter = []): array
    {
        $filter = $this->applicationFilter($ctx, $filter);
        if (isset($filter['studentId']['$in']) && $filter['studentId']['$in'] === []) {
            return [];
        }

        $apps = (new ApplicationModel())->findAll($filter, 500);
        return $this->enrichApplications($apps);
    }

    /**
     * Resolve filesystem path for an application's resume (application snapshot or student profile).
     *
     * @param array<string, mixed> $app
     * @return array{path: string, filename: string}|null
     */
    public function resumeFileForApplication(array $app): ?array
    {
        $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
        $studentResume = is_array($student['resume'] ?? null) ? $student['resume'] : [];
        $appResume = is_array($app['resume'] ?? null) ? $app['resume'] : [];

        $path = (string) ($appResume['path'] ?? $studentResume['path'] ?? '');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'uploads://')) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $path = rtrim($config['uploads']['resume_dir'], '/\\') . '/' . basename($path);
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $filename = (string) ($appResume['fileName'] ?? $studentResume['filename'] ?? basename($path));

        return ['path' => $path, 'filename' => $filename];
    }

    public function streamApplicationResume(string $appId, array $ctx): void
    {
        $app = $this->assertApplicationInScope($appId, $ctx);
        $file = $this->resumeFileForApplication($app);
        if ($file === null) {
            Response::notFound('Resume file not found for this application.');
        }

        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $mime = 'application/pdf';
        } elseif (in_array($ext, ['doc', 'docx'], true)) {
            $mime = 'application/msword';
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($file['filename']) . '"');
        readfile($file['path']);
        exit;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(array $ctx, ?string $query = null): array
    {
        $studentModel = new StudentModel();
        $deptModel = new DepartmentModel();
        $userModel = new UserModel();

        $departments = [];
        foreach ($deptModel->findAll([], 200) as $d) {
            $departments[(string) $d['_id']] = $d;
        }

        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $rows = [];
        foreach ($studentModel->findAll($filter, 500) as $s) {
            $userId = (string) ($s['userId'] ?? '');
            $deptId = (string) ($s['departmentId'] ?? '');
            $u = $userId ? $userModel->findById($userId) : null;
            $dept = $departments[$deptId] ?? null;

            $row = DocumentHelper::serialize($s) ?? [];
            $row['user'] = $u ? DocumentHelper::serialize($u) : null;
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : null;
            $rows[] = $row;
        }

        return $this->filterStudentRows($rows, $query);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterStudentRows(array $rows, ?string $query): array
    {
        $query = trim((string) ($query ?? ''));
        if ($query === '') {
            return $rows;
        }
        $tokens = preg_split('/\s+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->studentRowMatchesQuery($row, $tokens)
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $tokens
     */
    private function studentRowMatchesQuery(array $row, array $tokens): bool
    {
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $dept = is_array($row['department'] ?? null) ? $row['department'] : [];
        $hay = strtolower(implode(' ', array_filter([
            (string) ($row['registerNumber'] ?? ''),
            (string) ($user['name'] ?? ''),
            (string) ($user['email'] ?? ''),
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? ''),
            (string) ($row['classBatch'] ?? ''),
        ], static fn (string $v): bool => $v !== '')));

        foreach ($tokens as $token) {
            if (!str_contains($hay, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listPendingResumes(array $ctx): array
    {
        return $this->listResumeQueue($ctx, 'pending');
    }

    /**
     * Resume verification queue — one row per application (plus profile-only uploads).
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listResumeQueue(array $ctx, ?string $statusFilter = null): array
    {
        $filter = $this->applicationFilter($ctx, [
            'status' => ['$in' => [
                'applied', 'resume_pending', 'resume_verified', 'officer_approved',
                'company_review', 'shortlisted', 'selected', 'rejected',
            ]],
        ]);
        if (isset($filter['studentId']['$in']) && $filter['studentId']['$in'] === []) {
            return $this->appendProfileOnlyResumeRows($ctx, [], $statusFilter);
        }

        $apps = (new ApplicationModel())->findAll($filter, 500);
        $enriched = $this->enrichApplications($apps);
        $rows = [];
        $studentsWithAppRows = [];

        foreach ($enriched as $row) {
            $appStatus = (string) ($row['status'] ?? 'applied');
            if ($appStatus === 'withdrawn') {
                continue;
            }

            $queueStatus = $this->resumeQueueStatus($appStatus);
            if ($statusFilter !== null && $queueStatus !== $statusFilter) {
                continue;
            }

            if (empty($row['hasResume']) && empty($row['resumeFileName'])) {
                continue;
            }

            $studentId = (string) ($row['studentId'] ?? '');
            if ($studentId !== '') {
                $studentsWithAppRows[$studentId] = true;
            }

            $appId = (string) ($row['_id'] ?? $row['id'] ?? '');
            $fileName = (string) ($row['resumeFileName'] ?? '');
            $rows[] = [
                'id'                => $appId,
                'applicationId'     => $appId,
                'studentId'         => $studentId,
                'studentName'       => (string) ($row['studentName'] ?? ''),
                'registerNumber'    => (string) ($row['registerNumber'] ?? ''),
                'department'        => (string) ($row['department'] ?? ''),
                'company'           => (string) ($row['company'] ?? ''),
                'role'              => (string) ($row['role'] ?? ''),
                'fileName'          => $fileName,
                'validFormat'       => $this->resumeNameValid($row['studentName'] ?? '', $row['registerNumber'] ?? '', $fileName),
                'status'            => $queueStatus,
                'applicationStatus' => $appStatus,
                'submittedAt'       => $row['appliedAt'] ?? $row['createdAt'] ?? null,
                'hasResume'         => true,
            ];
        }

        return $this->appendProfileOnlyResumeRows($ctx, $rows, $statusFilter, $studentsWithAppRows);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, bool> $skipStudentIds
     * @return array<int, array<string, mixed>>
     */
    private function appendProfileOnlyResumeRows(
        array $ctx,
        array $rows,
        ?string $statusFilter,
        array $skipStudentIds = []
    ): array {
        if ($statusFilter !== null && $statusFilter !== 'pending') {
            return $rows;
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);

        foreach ($studentModel->findAll($filter, 500) as $student) {
            $studentId = (string) $student['_id'];
            if (isset($skipStudentIds[$studentId])) {
                continue;
            }

            $resume = $student['resume'] ?? null;
            if (!$resume || empty($resume['path']) || !empty($resume['verified'])) {
                continue;
            }

            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
            $fileName = (string) ($resume['filename'] ?? basename((string) ($resume['path'] ?? '')));

            $rows[] = [
                'id'                => 'profile-' . $studentId,
                'applicationId'     => null,
                'studentId'         => $studentId,
                'studentName'       => (string) ($user['name'] ?? ''),
                'registerNumber'    => (string) ($student['registerNumber'] ?? ''),
                'department'        => (string) ($dept['code'] ?? $dept['name'] ?? ''),
                'company'           => '—',
                'role'              => '—',
                'fileName'          => $fileName,
                'validFormat'       => $this->resumeNameValid($user['name'] ?? '', $student['registerNumber'] ?? '', $fileName),
                'status'            => 'pending',
                'applicationStatus' => null,
                'submittedAt'       => $resume['uploadedAt'] ?? null,
                'hasResume'         => true,
            ];
        }

        return $rows;
    }

    private function resumeQueueStatus(string $applicationStatus): string
    {
        return match ($applicationStatus) {
            'applied', 'resume_pending' => 'pending',
            'rejected' => 'rejected',
            default => 'approved',
        };
    }

    private function resumeNameValid(string $name, string $registerNumber, string $fileName): bool
    {
        if ($fileName === '' || $registerNumber === '') {
            return false;
        }
        return Security::validateResumeFilename($fileName, $name, $registerNumber);
    }

    public function streamStudentResume(string $studentId, array $ctx): void
    {
        PlacementOfficerContext::assertStudentInDepartment($studentId, $ctx);
        $file = $this->resumeFileForApplication(['studentId' => $studentId, 'resume' => []]);
        if ($file === null) {
            Response::notFound('Resume file not found for this student.');
        }

        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $mime = 'application/pdf';
        } elseif (in_array($ext, ['doc', 'docx'], true)) {
            $mime = 'application/msword';
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($file['filename']) . '"');
        readfile($file['path']);
        exit;
    }

    /**
     * Build Mongo filter for recruitment results from query string.
     *
     * @return array<string, mixed>
     */
    public function resultFilterFromRequest(): array
    {
        $filter = [];
        if (!empty($_GET['status'])) {
            $filter['status'] = $_GET['status'];
        }
        if (!empty($_GET['registerNumber'])) {
            $filter['registerNumber'] = strtoupper(trim((string) $_GET['registerNumber']));
        }
        if (!empty($_GET['driveId'])) {
            $driveId = (string) $_GET['driveId'];
            $company = trim((string) ($_GET['company'] ?? ''));
            $role = trim((string) ($_GET['role'] ?? ''));
            if ($company !== '' && $role !== '') {
                $filter['$or'] = [
                    ['driveId' => $driveId],
                    [
                        'company' => $company,
                        'role'    => $role,
                        '$or'     => [
                            ['driveId' => ['$exists' => false]],
                            ['driveId' => null],
                            ['driveId' => ''],
                        ],
                    ],
                ];
            } else {
                $filter['driveId'] = $driveId;
            }
        }
        return $filter;
    }

    /**
     * @param array<int, array<string, mixed>> $drives
     * @return array<int, array<string, mixed>>
     */
    public function enrichDrivesWithCompany(array $drives): array
    {
        $companyModel = new CompanyModel();
        $rows = [];
        foreach ($drives as $drive) {
            $row = DocumentHelper::serialize($drive);
            if ($row === null) {
                continue;
            }
            $companyId = (string) ($row['companyId'] ?? '');
            if ($companyId !== '') {
                $company = $companyModel->findById($companyId);
                $row['companyName'] = is_array($company) ? (string) ($company['companyName'] ?? '') : '';
            }

            $elig = is_array($row['eligibility'] ?? null) ? $row['eligibility'] : [];
            $package = trim((string) ($elig['package'] ?? $row['package'] ?? ''));
            $deadline = trim((string) ($elig['deadline'] ?? $row['deadline'] ?? ''));
            $jobType = trim((string) ($elig['jobType'] ?? $row['jobType'] ?? ''));
            if ($deadline === '' && !empty($row['date'])) {
                $deadline = (string) $row['date'];
            }
            $row['package'] = $package;
            $row['deadline'] = $deadline;
            $row['jobType'] = $jobType;
            $row['eligibility'] = array_merge($elig, [
                'package'  => $package,
                'deadline' => $deadline,
                'jobType'  => $jobType,
            ]);

            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function listResults(array $ctx, array $filter = []): array
    {
        $results = (new RecruitmentResultModel())->list($filter, 500);
        if ($ctx['isAdmin']) {
            return DocumentHelper::serializeMany($results);
        }

        $allowed = array_flip(PlacementOfficerContext::registerNumbersInDepartment($ctx));
        $filtered = array_values(array_filter(
            $results,
            fn (array $r) => isset($allowed[strtoupper((string) ($r['registerNumber'] ?? ''))])
        ));

        return DocumentHelper::serializeMany($filtered);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function dashboardStats(array $ctx): array
    {
        $studentModel = new StudentModel();
        $applicationModel = new ApplicationModel();
        $driveModel = new DriveModel();

        $studentFilter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $totalStudents = $studentModel->count($studentFilter);
        $placedStudents = $studentModel->count(array_merge($studentFilter, ['placed' => true]));
        $pendingStudents = 0;

        if ($ctx['isAdmin']) {
            $pendingStudents = (new UserModel())->count(['role' => 'student', 'approved' => false]);
        } else {
            $userIds = PlacementOfficerContext::userIdsInDepartment($ctx);
            if ($userIds !== []) {
                $oids = array_values(array_filter(array_map(
                    fn (string $id) => Security::toObjectId($id),
                    $userIds
                )));
                $pendingStudents = (new UserModel())->count([
                    'role'     => 'student',
                    'approved' => false,
                    '_id'      => ['$in' => $oids],
                ]);
            }
        }

        $appFilter = $this->applicationFilter($ctx, [
            'status' => ['$in' => ['applied', 'resume_verified', 'resume_pending']],
        ]);
        $pendingApplications = isset($appFilter['studentId']['$in']) && $appFilter['studentId']['$in'] === []
            ? 0
            : $applicationModel->count($appFilter);

        $pendingResumes = count($this->listPendingResumes($ctx));

        if ($ctx['isAdmin']) {
            $activeDrives = $driveModel->count(['status' => ['$ne' => 'closed']]);
        } else {
            $filter = PlacementOfficerContext::driveCollectionFilter($ctx);
            $candidates = $driveModel->findAll(array_merge($filter, ['status' => ['$ne' => 'closed']]), 200);
            $activeDrives = count(array_filter(
                $candidates,
                static fn (array $drive): bool => PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)
            ));
        }

        $deptId = $ctx['isAdmin'] ? null : ($ctx['departmentId'] ?? null);
        $analytics = (new AnalyticsService())->getDashboardAnalytics($deptId);
        $extended = (new AnalyticsService())->getExtendedAnalytics($deptId);
        $userModel = new UserModel();

        return [
            'totalStudents'       => $totalStudents,
            'placedStudents'      => $placedStudents,
            'placementPercentage' => $totalStudents > 0
                ? round(($placedStudents / $totalStudents) * 100, 1)
                : 0,
            'pendingApprovals'    => $pendingStudents + $pendingResumes,
            'pendingStudents'     => $pendingStudents,
            'pendingApplications' => $pendingApplications,
            'pendingResumes'      => $pendingResumes,
            'activeDrives'        => $activeDrives,
            'totalCompanies'      => $analytics['totals']['companies'] ?? (new CompanyModel())->count([]),
            'totalStaff'          => $userModel->count(['role' => 'staff']),
            'totalAlumni'         => $userModel->count(['role' => 'alumni']),
            'salaryAnalytics'     => $analytics['salaryAnalytics'],
            'branchStatistics'    => $analytics['branchStatistics'],
            'companyStatistics'   => $analytics['companyStatistics'],
            'hiringTrend'         => $extended['hiringTrend'],
            'department'          => $ctx['department']
                ? DocumentHelper::serialize($ctx['department'])
                : null,
        ];
    }

    public function assertApplicationInScope(string $appId, array $ctx): array
    {
        $app = (new ApplicationModel())->findById($appId);
        if (!$app) {
            Response::notFound('Application not found.');
        }
        PlacementOfficerContext::assertStudentInDepartment((string) ($app['studentId'] ?? ''), $ctx);
        return $app;
    }

    public function assertResultRegisterInScope(string $registerNumber, array $ctx): void
    {
        if ($ctx['isAdmin']) {
            return;
        }
        $student = (new StudentModel())->findByRegisterNumber($registerNumber);
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }

    /**
     * Resolve a student profile by Mongo id or linked user id.
     *
     * @return array<string, mixed>|null
     */
    public function resolveStudentRef(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        $studentModel = new StudentModel();
        $student = $studentModel->findById($ref);
        if ($student) {
            return $student;
        }

        return $studentModel->findByUserId($ref);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function studentPipelineForScope(string $studentRef, array $ctx): array
    {
        $student = $this->resolveStudentRef($studentRef);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        PlacementOfficerContext::assertStudentInDepartment((string) ($student['_id'] ?? ''), $ctx);

        return $this->buildStudentPipeline($student);
    }

    /**
     * @param array<string, mixed> $student
     * @return array<int, array<string, mixed>>
     */
    public function buildStudentPipeline(array $student): array
    {
        $studentId = (string) ($student['_id'] ?? '');
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        $placed = ($student['placed'] ?? false) === true;

        $apps = (new ApplicationModel())->findByStudent($studentId);
        $pipeline = [];
        foreach ($this->enrichApplications($apps) as $row) {
            $pipeline[] = [
                'id'             => (string) ($row['id'] ?? $row['_id'] ?? ''),
                'company'        => (string) ($row['company'] ?? ''),
                'role'           => (string) ($row['role'] ?? ''),
                'stage'          => (string) ($row['stage'] ?? ''),
                'status'         => (string) ($row['status'] ?? ''),
                'appliedAt'      => (string) ($row['appliedAt'] ?? $row['createdAt'] ?? ''),
                'registerNumber' => (string) ($row['registerNumber'] ?? $register),
                'source'         => 'campus',
            ];
        }

        $self = $student['selfPlacement'] ?? null;
        if (is_array($self) && (string) ($self['companyName'] ?? '') !== '') {
            $pipeline[] = [
                'id'             => 'self-placement',
                'company'        => (string) $self['companyName'],
                'role'           => (string) ($self['role'] ?? ''),
                'stage'          => 'self_reported',
                'status'         => $placed ? 'placed' : (string) ($self['status'] ?? 'pending'),
                'appliedAt'      => $this->pipelineTimestamp($self['submittedAt'] ?? null),
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'source'         => 'self_placement',
                'companyAddress' => (string) ($self['companyAddress'] ?? ''),
            ];
        }

        $history = is_array($student['placementHistory'] ?? null) ? $student['placementHistory'] : [];
        foreach ($history as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (is_array($self) && ($entry['type'] ?? '') === 'self_reported') {
                continue;
            }
            $company = (string) ($entry['company'] ?? '');
            if ($company === '') {
                continue;
            }
            $pipeline[] = [
                'id'             => 'history-' . $idx,
                'company'        => $company,
                'role'           => (string) ($entry['role'] ?? ''),
                'stage'          => (string) ($entry['type'] ?? 'placement'),
                'status'         => (string) ($entry['status'] ?? ($placed ? 'placed' : 'pending')),
                'appliedAt'      => $this->pipelineTimestamp($entry['date'] ?? null),
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'source'         => 'history',
            ];
        }

        $resultFilter = $register !== '' ? ['registerNumber' => $register] : [];
        foreach ((new RecruitmentResultModel())->list($resultFilter, 50) as $result) {
            $status = (string) ($result['status'] ?? 'selected');
            $serialized = DocumentHelper::serialize($result) ?? [];
            $pipeline[] = [
                'id'             => 'result-' . (string) ($result['_id'] ?? ''),
                'company'        => (string) ($result['company'] ?? ''),
                'role'           => (string) ($result['role'] ?? ''),
                'stage'          => 'company_selection',
                'status'         => $status === 'rejected' ? 'rejected' : 'selected',
                'appliedAt'      => (string) ($serialized['updatedAt'] ?? $serialized['createdAt'] ?? ''),
                'registerNumber' => (string) ($result['registerNumber'] ?? $register),
                'source'         => 'recruitment_result',
            ];
        }

        return $pipeline;
    }

    private function pipelineTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            $serialized = DocumentHelper::serialize(['at' => $value]);

            return (string) ($serialized['at'] ?? '');
        }

        return (string) $value;
    }
}
