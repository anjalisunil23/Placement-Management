<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\ApplicationModel;
use PMS\Models\AptitudeTestModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Aptitude mock RBAC — authorization is enforced here / in AptitudeService,
 * never by trusting client-supplied studentId / companyId / departmentId / class.
 *
 * Rules:
 * - admin: all aptitude data
 * - placement_officer: students in their department only
 * - staff: students in assigned class only; manage department tests (no self-take)
 * - student / job-seeking alumni: own data only
 * - company: applicants linked via applications for the authenticated company only
 */
final class AptitudeAccessService
{
    /** Application statuses that grant a company visibility into applicant aptitude. */
    private const COMPANY_VISIBLE_STATUSES = [
        'applied',
        'resume_pending',
        'resume_verified',
        'officer_approved',
        'company_review',
        'shortlisted',
        'selected',
    ];

    /**
     * @param array<string, mixed> $user
     */
    public static function canTake(array $user): bool
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'student') {
            return true;
        }
        if ($role === 'alumni') {
            return !self::alumniIsWorking($user);
        }
        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canManage(array $user): bool
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);

            return !empty($ctx['departmentId']);
        }
        if ($role === 'staff' || ($user['role'] ?? '') === 'staff') {
            $ctx = StaffContext::resolve($user);

            return !empty($ctx['departmentId']) && StaffContext::assignedClassBatches($ctx) !== [];
        }

        return false;
    }

    /**
     * Weekly / monthly aptitude contests — admin and department PO only.
     *
     * @param array<string, mixed> $user
     */
    public static function canManageContests(array $user): bool
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);

            return !empty($ctx['departmentId']);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizeContestFields(array $user, array $data): array
    {
        if (!self::canManageContests($user)) {
            unset($data['contestType'], $data['contestWeekday'], $data['contestMonthDay']);

            return $data;
        }
        $type = AptitudeTestModel::normalizeContestType((string) ($data['contestType'] ?? 'none'));
        $data['contestType'] = $type;
        if ($type === 'weekly') {
            $data['contestWeekday'] = max(1, min(7, (int) ($data['contestWeekday'] ?? 1)));
            unset($data['contestMonthDay']);
        } elseif ($type === 'monthly') {
            $data['contestMonthDay'] = max(1, min(28, (int) ($data['contestMonthDay'] ?? 1)));
            unset($data['contestWeekday']);
        } else {
            unset($data['contestWeekday'], $data['contestMonthDay']);
        }

        return $data;
    }

    /**
     * Can browse scoped progress of others (not company — company uses applicant endpoint).
     *
     * @param array<string, mixed> $user
     */
    public static function canViewDirectory(array $user): bool
    {
        $role = AuthMiddleware::resolvedRole($user);
        if (in_array($role, ['admin', 'placement_officer'], true)) {
            return true;
        }
        return ($user['role'] ?? '') === 'staff' || $role === 'staff';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireTaker(array $user): void
    {
        if (!self::canTake($user)) {
            Response::forbidden('You are not allowed to take aptitude mock tests.');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireManager(array $user): void
    {
        if (!self::canManage($user)) {
            Response::forbidden('You are not allowed to manage aptitude mock tests.');
        }
    }

    /**
     * Whether assignment rules match this taker (department / course / batch / student ids).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    public static function assignmentMatches(array $user, array $test): bool
    {
        if (!self::canTake($user)) {
            return false;
        }
        $testDept = (string) ($test['departmentId'] ?? '');
        $ctx = self::subjectContext($user);
        $viewerDept = (string) ($ctx['departmentId'] ?? '');
        if ($testDept !== '' && ($viewerDept === '' || $viewerDept !== $testDept)) {
            return false;
        }
        $mode = AptitudeTestModel::normalizeAssignmentMode((string) ($test['assignmentMode'] ?? 'all'));
        if ($mode === 'all') {
            return true;
        }
        if ($mode === 'department') {
            return $viewerDept !== '' && ($testDept === '' || $viewerDept === $testDept);
        }
        if ($mode === 'course') {
            $want = array_map('strtolower', array_map('strval', (array) ($test['assignmentCourses'] ?? [])));
            $course = strtolower(trim((string) ($ctx['course'] ?? '')));
            return $course !== '' && in_array($course, $want, true);
        }
        if ($mode === 'batch') {
            $want = array_map('strtolower', array_map('strval', (array) ($test['assignmentBatches'] ?? [])));
            $batch = strtolower(trim((string) ($ctx['classBatch'] ?? $ctx['batch'] ?? '')));
            return $batch !== '' && in_array($batch, $want, true);
        }
        if ($mode === 'students') {
            $ids = array_map('strval', (array) ($test['assignmentStudentIds'] ?? []));
            $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
            $sid = (string) ($ctx['studentId'] ?? '');
            return ($uid !== '' && in_array($uid, $ids, true)) || ($sid !== '' && in_array($sid, $ids, true));
        }
        return false;
    }

    /**
     * Assigned published/scheduled tests a student may see on the hub (not necessarily start yet).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    public static function testListedForTaker(array $user, array $test): bool
    {
        if (!self::assignmentMatches($user, $test)) {
            return false;
        }
        $life = AptitudeTestModel::lifecycleStatus($test);
        if (!in_array($life, ['published', 'scheduled'], true)) {
            return false;
        }
        $contest = AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($contest !== 'none') {
            return AptitudeTestModel::isContestOpen($test);
        }
        return true;
    }

    /**
     * Published tests with no assignment default to institution-wide (or matching department).
     * Explicit assignmentMode further restricts who may take the test.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    public static function testVisibleToTaker(array $user, array $test): bool
    {
        return self::testListedForTaker($user, $test) && AptitudeTestModel::isOpenForTaking($test);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    public static function assertTestManageable(array $user, array $test): void
    {
        self::requireManager($user);
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            return;
        }
        if ($role === 'staff' || ($user['role'] ?? '') === 'staff') {
            $ctx = StaffContext::resolve($user);
            $testDept = (string) ($test['departmentId'] ?? '');
            if ($testDept === '' || (string) ($ctx['departmentId'] ?? '') !== $testDept) {
                Response::forbidden('You can only manage aptitude tests for your department.');
            }

            return;
        }
        $ctx = PlacementOfficerContext::resolve($user);
        $testDept = (string) ($test['departmentId'] ?? '');
        if ($testDept === '' || (string) ($ctx['departmentId'] ?? '') !== $testDept) {
            Response::forbidden('You can only manage aptitude tests for your department.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function applyTestDepartmentScope(array $user, array $data): array
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            if (isset($data['departmentId']) && trim((string) $data['departmentId']) !== '') {
                $data['departmentId'] = trim((string) $data['departmentId']);
            } else {
                unset($data['departmentId']);
            }

            return $data;
        }
        if ($role === 'staff' || ($user['role'] ?? '') === 'staff') {
            $ctx = StaffContext::resolve($user);
            if (empty($ctx['departmentId']) || StaffContext::assignedClassBatches($ctx) === []) {
                Response::forbidden('No class assignment — cannot create aptitude tests.');
            }
            $data['departmentId'] = (string) $ctx['departmentId'];

            return $data;
        }
        $ctx = PlacementOfficerContext::resolve($user);
        if (empty($ctx['departmentId'])) {
            Response::forbidden('No department assigned — cannot create aptitude tests.');
        }
        $data['departmentId'] = (string) $ctx['departmentId'];

        return $data;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizeTestUpdate(array $user, array $data): array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'admin') {
            unset($data['departmentId']);
        }

        return self::sanitizeContestFields($user, $data);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function requireDirectoryViewer(array $user): void
    {
        if (!self::canViewDirectory($user)) {
            Response::forbidden('You cannot browse aptitude progress.');
        }
    }

    /**
     * @param array<string, mixed> $viewer
     */
    public static function requireCanViewSubject(array $viewer, string $subjectUserId): void
    {
        if (!self::canViewSubject($viewer, $subjectUserId)) {
            Response::forbidden('You cannot view this aptitude progress.');
        }
    }

    /**
     * Subject context for the authenticated user only (never from request body).
     *
     * @param array<string, mixed> $viewer
     * @return array{subjectType:string,studentId:?string,alumniId:?string,departmentId:?string,classBatch:string,course:string,semester:string,batch:string}
     */
    public static function subjectContext(array $viewer): array
    {
        $role = AuthMiddleware::resolvedRole($viewer);
        $userId = (string) ($viewer['_id'] ?? $viewer['id'] ?? '');

        if ($role === 'student') {
            $student = (new StudentModel())->findOne(['userId' => Security::toObjectId($userId)]);
            return [
                'subjectType' => 'student',
                'studentId' => $student ? (string) $student['_id'] : null,
                'alumniId' => null,
                'departmentId' => $student ? (string) ($student['departmentId'] ?? '') : null,
                'classBatch' => $student ? (string) StaffContext::studentClassBatch($student) : '',
                'course' => $student ? trim((string) ($student['academic']['course'] ?? $student['course'] ?? '')) : '',
                'semester' => $student ? trim((string) ($student['academic']['semester'] ?? $student['semester'] ?? '')) : '',
                'batch' => $student ? trim((string) ($student['batch'] ?? $student['academic']['batch'] ?? '')) : '',
            ];
        }

        if ($role === 'alumni') {
            $alumni = (new AlumniModel())->findOne(['userId' => Security::toObjectId($userId)]);
            return [
                'subjectType' => 'alumni',
                'studentId' => null,
                'alumniId' => $alumni ? (string) $alumni['_id'] : null,
                'departmentId' => $alumni ? (string) ($alumni['departmentId'] ?? '') : null,
                'classBatch' => '',
                'course' => '',
                'semester' => '',
                'batch' => $alumni ? trim((string) ($alumni['batch'] ?? '')) : '',
            ];
        }

        return [
            'subjectType' => 'staff',
            'studentId' => null,
            'alumniId' => null,
            'departmentId' => null,
            'classBatch' => '',
            'course' => '',
            'semester' => '',
            'batch' => '',
        ];
    }

    /**
     * Canonical department label from the departments collection.
     *
     * @param array<string, mixed> $fallbackUser
     */
    public static function departmentDisplayName(?string $departmentId, array $fallbackUser = []): string
    {
        $id = trim((string) $departmentId);
        if ($id !== '') {
            $dept = (new DepartmentModel())->findById($id);
            if (is_array($dept)) {
                $name = trim((string) ($dept['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
                $code = trim((string) ($dept['code'] ?? ''));
                if ($code !== '') {
                    return $code;
                }
            }
        }

        $name = trim((string) ($fallbackUser['departmentName'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($fallbackUser['department'] ?? ''));
    }

    /**
     * Scope metadata for UI (department / assigned classes). Derived from auth profile only.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function scopeInfo(array $user): array
    {
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            return [
                'role' => 'admin',
                'scope' => 'institution',
                'label' => 'All students and job-seeking alumni',
                'departmentId' => null,
                'assignedClassBatches' => [],
            ];
        }
        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            $deptId = (string) ($ctx['departmentId'] ?? '');
            $deptName = self::departmentDisplayName($deptId, $user);
            return [
                'role' => 'placement_officer',
                'scope' => 'department',
                'label' => $deptId !== ''
                    ? ($deptName !== '' ? "Students in {$deptName}" : 'Students in your department only')
                    : 'No department assigned — no student aptitude visible',
                'departmentId' => $deptId,
                'departmentName' => $deptName,
                'assignedClassBatches' => [],
            ];
        }
        if (($user['role'] ?? '') === 'staff' || $role === 'staff') {
            $ctx = StaffContext::resolve($user);
            $batches = StaffContext::assignedClassBatches($ctx);
            $deptId = (string) ($ctx['departmentId'] ?? '');
            $deptName = self::departmentDisplayName($deptId, $user);
            return [
                'role' => 'staff',
                'scope' => 'class',
                'label' => $batches === []
                    ? 'No class assignment — no student aptitude visible'
                    : 'Students in your assigned class(es) only',
                'departmentId' => $deptId,
                'departmentName' => $deptName,
                'assignedClassBatches' => $batches,
            ];
        }
        if ($role === 'company') {
            return [
                'role' => 'company',
                'scope' => 'applicants',
                'label' => 'Only students who applied to your jobs/drives',
                'departmentId' => null,
                'assignedClassBatches' => [],
            ];
        }
        return [
            'role' => $role,
            'scope' => 'self',
            'label' => 'Your own aptitude progress',
            'departmentId' => null,
            'assignedClassBatches' => [],
        ];
    }

    /**
     * Authorized subject user IDs for directory / bulk progress.
     * null = unrestricted (admin); [] = none; list = exact allow-set from server-side relationships.
     *
     * @param array<string, mixed> $viewer
     * @return string[]|null
     */
    public static function authorizedSubjectUserIds(array $viewer): ?array
    {
        $role = AuthMiddleware::resolvedRole($viewer);
        if ($role === 'admin') {
            return null;
        }

        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($viewer);
            if (!empty($ctx['isAdmin'])) {
                return null;
            }
            if (empty($ctx['departmentId'])) {
                return [];
            }
            $ids = [];
            foreach (PlacementOfficerContext::userIdsInDepartment($ctx) as $uid) {
                $uid = trim((string) $uid);
                if ($uid !== '') {
                    $ids[$uid] = true;
                }
            }
            return array_keys($ids);
        }

        if (($viewer['role'] ?? '') === 'staff' || $role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            if (StaffContext::assignedClassBatches($ctx) === [] || empty($ctx['departmentId'])) {
                return [];
            }
            $students = (new StudentModel())->findAll(StaffContext::studentCollectionFilter($ctx), 5000);
            $ids = [];
            foreach ($students as $student) {
                if (!StaffContext::studentMatchesScope($student, $ctx)) {
                    continue;
                }
                $uid = trim((string) ($student['userId'] ?? ''));
                if ($uid !== '') {
                    $ids[$uid] = true;
                }
            }
            return array_keys($ids);
        }

        // Students, alumni, companies: no multi-subject directory.
        return [];
    }

    /**
     * DB filter for completed attempts visible to this viewer (privacy at query layer).
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>|null null when caller should return empty without querying
     */
    public static function completedAttemptsFilter(array $viewer): ?array
    {
        $allowed = self::authorizedSubjectUserIds($viewer);
        if ($allowed === null) {
            return ['status' => 'completed'];
        }
        if ($allowed === []) {
            return null;
        }
        $oids = [];
        foreach ($allowed as $uid) {
            $oid = Security::toObjectId((string) $uid);
            if ($oid !== null) {
                $oids[] = (string) $oid;
            }
        }
        if ($oids === []) {
            return null;
        }
        return [
            'status' => 'completed',
            'userId' => ['$in' => $oids],
        ];
    }

    /**
     * Clamp client filters so they cannot expand scope beyond auth-derived rights.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function sanitizeDirectoryFilters(array $viewer, array $filters): array
    {
        $role = AuthMiddleware::resolvedRole($viewer);
        $out = [
            'batch' => trim((string) ($filters['batch'] ?? '')),
            'class' => trim((string) ($filters['class'] ?? $filters['classBatch'] ?? '')),
            'course' => trim((string) ($filters['course'] ?? '')),
            'semester' => trim((string) ($filters['semester'] ?? '')),
            'department' => '',
            'test' => trim((string) ($filters['test'] ?? $filters['testId'] ?? '')),
            'category' => trim((string) ($filters['category'] ?? '')),
            'userType' => trim((string) ($filters['userType'] ?? '')),
            'resultType' => self::normalizeProgressResultType($filters),
        ];

        if ($role === 'admin') {
            $out['department'] = trim((string) ($filters['department'] ?? $filters['departmentId'] ?? ''));
            return $out;
        }

        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($viewer);
            // Never trust client departmentId — always force officer's department.
            $out['department'] = (string) ($ctx['departmentId'] ?? '');
            $out['userType'] = 'student';
            return $out;
        }

        if (($viewer['role'] ?? '') === 'staff' || $role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            $batches = StaffContext::assignedClassBatches($ctx);
            $out['department'] = (string) ($ctx['departmentId'] ?? '');
            $out['userType'] = 'student';
            if ($out['class'] !== '') {
                if (!StaffContext::classBatchMatchesAssigned($out['class'], $batches)) {
                    // Force empty result rather than leaking another class.
                    $out['class'] = '__unauthorized_class__';
                }
            }
            return $out;
        }

        // Non-directory roles: ignore all client filters.
        return [
            'batch' => '',
            'class' => '',
            'course' => '',
            'semester' => '',
            'department' => '',
            'test' => '',
            'category' => '',
            'userType' => '',
            'resultType' => '',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function normalizeProgressResultType(array $filters): string
    {
        $raw = strtolower(trim((string) ($filters['resultType'] ?? '')));

        return in_array($raw, ['tests', 'contests'], true) ? $raw : '';
    }

    /**
     * Whether viewer may see subject user's aptitude progress.
     *
     * @param array<string, mixed> $viewer
     */
    public static function canViewSubject(array $viewer, string $subjectUserId): bool
    {
        $subjectUserId = trim($subjectUserId);
        if ($subjectUserId === '' || !Security::isValidId($subjectUserId)) {
            return false;
        }

        $viewerId = (string) ($viewer['_id'] ?? $viewer['id'] ?? '');
        $role = AuthMiddleware::resolvedRole($viewer);
        $sameUser = $viewerId !== '' && (
            $viewerId === $subjectUserId
            || (string) (Security::toObjectId($viewerId) ?: '') === (string) (Security::toObjectId($subjectUserId) ?: '')
        );

        // Own data: student / job-seeking alumni.
        if ($sameUser) {
            if ($role === 'admin') {
                return true;
            }
            if ($role === 'student') {
                return true;
            }
            if ($role === 'alumni') {
                return !self::alumniIsWorking($viewer);
            }
            return false;
        }

        if ($role === 'admin') {
            return true;
        }

        // Students / alumni never see anyone else.
        if (in_array($role, ['student', 'alumni'], true)) {
            return false;
        }

        if ($role === 'placement_officer' || $role === 'staff' || ($viewer['role'] ?? '') === 'staff') {
            $allowed = self::authorizedSubjectUserIds($viewer);
            if ($allowed === null) {
                // Admin-equivalent PO context only.
                $subject = self::loadSubjectProfile($subjectUserId);
                return $subject !== null && ($subject['type'] ?? '') === 'student';
            }
            if ($allowed === [] || !self::userIdInList($subjectUserId, $allowed)) {
                return false;
            }
            $subject = self::loadSubjectProfile($subjectUserId);
            return $subject !== null && ($subject['type'] ?? '') === 'student';
        }

        if ($role === 'company') {
            $subject = self::loadSubjectProfile($subjectUserId);
            if ($subject === null || ($subject['type'] ?? '') !== 'student' || empty($subject['studentId'])) {
                return false;
            }
            return self::companyCanViewStudent($viewer, (string) $subject['studentId']);
        }

        return false;
    }

    /**
     * @param string[] $allowed
     */
    private static function userIdInList(string $userId, array $allowed): bool
    {
        $want = (string) (Security::toObjectId($userId) ?: $userId);
        foreach ($allowed as $uid) {
            $have = (string) (Security::toObjectId((string) $uid) ?: $uid);
            if ($have === $want || (string) $uid === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Attempt access: owner OR scoped viewer. Company may view applicant attempts.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $attempt
     */
    public static function canViewAttempt(array $viewer, array $attempt): bool
    {
        $owner = (string) ($attempt['userId'] ?? '');
        if ($owner === '') {
            return false;
        }
        return self::canViewSubject($viewer, $owner);
    }

    /**
     * Company resolved from authenticated session only — never from request params.
     *
     * @param array<string, mixed> $viewer
     */
    public static function companyCanViewStudent(array $viewer, string $studentId): bool
    {
        if (AuthMiddleware::resolvedRole($viewer) !== 'company') {
            return false;
        }
        $company = self::companyForUser($viewer);
        if ($company === null) {
            return false;
        }
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return false;
        }
        foreach ((new ApplicationModel())->findByCompany((string) $company['_id']) as $app) {
            if ((string) ($app['studentId'] ?? '') !== (string) $sid) {
                continue;
            }
            $status = (string) ($app['status'] ?? '');
            if (in_array($status, self::COMPANY_VISIBLE_STATUSES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $viewer
     * @return string[]
     */
    public static function companyVisibleStudentIds(array $viewer): array
    {
        $company = self::companyForUser($viewer);
        if ($company === null) {
            return [];
        }
        $ids = [];
        foreach ((new ApplicationModel())->findByCompany((string) $company['_id']) as $app) {
            $status = (string) ($app['status'] ?? '');
            if (!in_array($status, self::COMPANY_VISIBLE_STATUSES, true)) {
                continue;
            }
            $sid = trim((string) ($app['studentId'] ?? ''));
            if ($sid !== '') {
                $ids[$sid] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function alumniIsWorking(array $user): bool
    {
        if (array_key_exists('isWorking', $user)) {
            return (bool) $user['isWorking'];
        }
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ($userId === '') {
            return false;
        }
        $alumni = (new AlumniModel())->findOne(['userId' => Security::toObjectId($userId)]);
        if (!$alumni) {
            return false;
        }
        if (array_key_exists('isWorking', $alumni)) {
            return (bool) $alumni['isWorking'];
        }
        return trim((string) ($alumni['company'] ?? '')) !== '';
    }

    /**
     * @return array{type:string,userId:string,studentId:?string,alumniId:?string,departmentId:string,student?:array<string,mixed>}|null
     */
    public static function loadSubjectProfile(string $userId): ?array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return null;
        }
        $student = (new StudentModel())->findOne(['userId' => $oid]);
        if ($student) {
            return [
                'type' => 'student',
                'userId' => $userId,
                'studentId' => (string) $student['_id'],
                'alumniId' => null,
                'departmentId' => (string) ($student['departmentId'] ?? ''),
                'student' => $student,
            ];
        }
        $alumni = (new AlumniModel())->findOne(['userId' => $oid]);
        if ($alumni) {
            return [
                'type' => 'alumni',
                'userId' => $userId,
                'studentId' => null,
                'alumniId' => (string) $alumni['_id'],
                'departmentId' => (string) ($alumni['departmentId'] ?? ''),
            ];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public static function companyForUser(array $user): ?array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'company') {
            return null;
        }
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ($userId === '') {
            return null;
        }
        return (new CompanyModel())->findByUserId($userId);
    }

    /**
     * Authenticate + require ability to open aptitude pages (take, manage, or directory).
     *
     * @return array<string, mixed>
     */
    public static function requirePortalUser(): array
    {
        $user = AuthMiddleware::authenticate();
        if (self::canTake($user) || self::canManage($user) || self::canViewDirectory($user)) {
            return $user;
        }
        Response::forbidden('You do not have access to aptitude mocks.');
    }
}
