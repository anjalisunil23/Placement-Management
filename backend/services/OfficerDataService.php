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
use PMS\Services\AesApiService;
use PMS\Services\AesLoginService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Department-scoped data access and enrichment for placement officers.
 */
final class OfficerDataService
{
    /** @var array<string, string> */
    private static array $placementNameCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $placementProfileCache = [];

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
            $row = $this->enrichStudentListRow($row, $s, $u);
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : null;
            if (!$this->isPlacementStudentListCandidate($s, $u, $row)) {
                continue;
            }
            $rows[] = $row;
        }

        return $this->filterStudentRows($rows, $query);
    }

    /**
     * Full student profile for staff / placement officer / admin overview.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function getStudentOverview(string $studentId, array $ctx, string $photoRoutePrefix = 'officer', ?string $expectedRegister = null): array
    {
        $student = $this->resolveStudentRef($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        if ($expectedRegister !== null && $expectedRegister !== '') {
            $expected = strtoupper(trim($expectedRegister));
            $actual = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            if ($actual === '' || $expected !== $actual) {
                Response::notFound('Student register number does not match.');
            }
        }
        if (!$this->studentInScope($student, $ctx)) {
            Response::forbidden('Student is outside your department scope.');
        }

        $placement = [];
        $mapped = [];
        try {
            $placementCtx = (new AesLoginService())->refreshStudentWithPlacementContext($student);
            $student = $placementCtx['student'];
            $placement = $placementCtx['placement'];
            $mapped = $placementCtx['mapped'];
        } catch (\Throwable) {
            // Serve stored profile when AES is unreachable.
        }

        $userId = (string) ($student['userId'] ?? '');
        $user = $userId ? (new UserModel())->findById($userId) : null;
        $dept = !empty($student['departmentId'])
            ? (new DepartmentModel())->findById((string) $student['departmentId'])
            : null;

        $enriched = $this->enrichStudentListRow([], $student, $user);
        $displayName = (string) ($enriched['displayName'] ?? ($user['name'] ?? 'Student'));
        $photoUrl = (string) ($enriched['photoUrl'] ?? '');

        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $chances = is_array($student['placementChances'] ?? null) ? $student['placementChances'] : [];
        $selfPlacement = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : null;
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));

        $aesCtx = $this->trustedAesContextForRegister($register);
        if ($placement === [] && $aesCtx['placement'] !== []) {
            $placement = $aesCtx['placement'];
            $mapped = $aesCtx['mapped'];
        }

        $contact = $this->contactFieldsFromSources($user, $personal, $placement, $mapped);
        $collegeEmail = $contact['collegeEmail'];
        $personalEmail = $contact['personalEmail'];
        $phone = $contact['phone'];

        $gender = trim((string) ($personal['gender'] ?? $mapped['gender'] ?? ''));

        $classBatch = trim((string) ($mapped['classBatch'] ?? $placement['classBatch'] ?? ''));
        if ($classBatch === '') {
            $classBatch = trim((string) ($student['classBatch'] ?? ''));
        }

        $cgpa = (float) ($academic['cgpa'] ?? 0);
        if ($cgpa <= 0 && !empty($mapped['cgpa']) && (float) $mapped['cgpa'] > 0) {
            $cgpa = (float) $mapped['cgpa'];
        }
        $marks10 = (float) ($academic['marks10th'] ?? 0);
        if ($marks10 <= 0 && !empty($mapped['marks10th']) && (float) $mapped['marks10th'] > 0) {
            $marks10 = (float) $mapped['marks10th'];
        }
        $marks12 = (float) ($academic['marks12th'] ?? $academic['ugMarks'] ?? 0);
        if ($marks12 <= 0 && !empty($mapped['marks12th']) && (float) $mapped['marks12th'] > 0) {
            $marks12 = (float) $mapped['marks12th'];
        }
        $backlogs = isset($academic['backlogs'])
            ? (int) $academic['backlogs']
            : (isset($mapped['backlogs']) ? (int) $mapped['backlogs'] : 0);

        $qualifications = [];
        $qualFromPlacement = (new AesApiService())->extractQualificationFromPlacement($placement);
        if (!empty($qualFromPlacement['qualifications']) && is_array($qualFromPlacement['qualifications'])) {
            $qualifications = $qualFromPlacement['qualifications'];
        } elseif ($register !== '') {
            $aesApi = new AesApiService();
            $qualAdmno = $aesApi->resolveQualificationAdmissionNumber($placement, $register);
            if ($qualAdmno !== '' && ctype_digit($qualAdmno)) {
                try {
                    $qualParams = ['admno' => $qualAdmno, 'stud_admno' => $qualAdmno];
                    $regNo = trim((string) ($placement['registerno'] ?? $placement['registerNumber'] ?? ''));
                    if ($regNo !== '' && $regNo !== $qualAdmno) {
                        $qualParams['registerno'] = $regNo;
                        $qualParams['registerNumber'] = $regNo;
                    }
                    $qual = $aesApi->fetchStudentQualificationProfile($qualParams);
                    $qualifications = is_array($qual['qualifications'] ?? null) ? $qual['qualifications'] : [];
                } catch (\Throwable) {
                    $qualifications = [];
                }
            }
        }

        $aesDeptName = trim((string) (
            $mapped['departmentName']
            ?? $mapped['branch']
            ?? $placement['departmentName']
            ?? $placement['deptName']
            ?? $placement['branch']
            ?? ''
        ));
        $aesDeptCode = trim((string) (
            $mapped['department']
            ?? $placement['deptCode']
            ?? $placement['department']
            ?? ''
        ));

        if ($photoUrl === '') {
            $photoUrl = (new AesApiService())->resolvePhotoUrl(trim((string) (
                $placement['photoUrl']
                ?? $placement['stud_photo']
                ?? $mapped['photoUrl']
                ?? ''
            )));
        }

        $resume = is_array($student['resume'] ?? null) ? $student['resume'] : null;
        $resumeStatus = 'none';
        if ($resume && !empty($resume['path'])) {
            $resumeStatus = !empty($resume['verified']) ? 'approved' : 'pending';
        }

        $isPlaced = ($student['placed'] ?? false) === true;
        $placementStatus = $isPlaced
            ? 'placed'
            : ((is_array($selfPlacement) && ($selfPlacement['status'] ?? '') === 'pending') ? 'pending_placement' : 'registered');

        $overview = [
            'id'              => (string) ($student['_id'] ?? ''),
            'studentId'       => (string) ($student['_id'] ?? ''),
            'registerNumber'  => (string) ($student['registerNumber'] ?? ''),
            'name'            => $displayName,
            'email'           => $collegeEmail !== '' ? $collegeEmail : $personalEmail,
            'collegeEmail'    => $collegeEmail,
            'personalEmail'   => $personalEmail,
            'phone'           => $phone,
            'gender'          => $gender,
            'department'      => $aesDeptCode !== '' ? $aesDeptCode : (string) ($dept['code'] ?? ''),
            'departmentName'  => $aesDeptName !== '' ? $aesDeptName : (string) ($dept['name'] ?? $dept['code'] ?? ''),
            'classBatch'      => $classBatch,
            'cgpa'            => $cgpa > 0 ? $cgpa : null,
            'marks10th'       => $marks10 > 0 ? $marks10 : null,
            'marks12th'       => $marks12 > 0 ? $marks12 : null,
            'ugMarks'         => $marks12 > 0 ? $marks12 : ((float) ($academic['ugMarks'] ?? 0) ?: null),
            'backlogs'        => $backlogs,
            'photoUrl'        => $photoUrl,
            'photoProxyUrl'   => $photoUrl !== ''
                ? '/backend/api/' . trim($photoRoutePrefix, '/') . '/students/' . rawurlencode($studentId) . '/photo'
                : '',
            'photo'           => $enriched['photo'] ?? null,
            'status'          => !empty($user['approved']) ? 'approved' : 'pending',
            'blocked'         => ($user['status'] ?? '') === 'blocked',
            'placed'          => $isPlaced,
            'selfPlacement'   => $selfPlacement ? (DocumentHelper::serialize($selfPlacement) ?? []) : null,
            'placementStatus' => $placementStatus,
            'resumeStatus'    => $resumeStatus,
            'chancesUsed'     => (int) ($chances['used'] ?? 0),
            'chancesMax'      => (int) (($chances['used'] ?? 0) + ($chances['remaining'] ?? 0)),
            'qualifications'  => $qualifications !== [] ? $qualifications : null,
        ];

        return $this->mergeAesPlacementIntoOverview($student, $user, $overview);
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $overview
     * @return array<string, mixed>
     */
    private function mergeAesPlacementIntoOverview(array $student, ?array $user, array $overview): array
    {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register === '') {
            return $overview;
        }

        $placement = $this->placementProfileForRegister($register);
        if ($placement === []) {
            return $overview;
        }

        $mapped = (new AesLoginService())->mapAesDetailsToUserFields($placement);
        $aesReg = strtoupper(trim((string) ($mapped['registerNumber'] ?? '')));
        if ($aesReg !== '' && $aesReg !== $register) {
            return $overview;
        }

        $name = trim((string) ($mapped['name'] ?? ''));
        if ($name !== '' && $this->isUsablePersonName($name, $register)) {
            $overview['name'] = $name;
            if (is_array($user) && !empty($user['_id']) && trim((string) ($user['name'] ?? '')) !== $name) {
                (new UserModel())->updateUser((string) $user['_id'], ['name' => $name]);
            }
        } else {
            $aesName = trim((new AesLoginService())->displayNameFromAesProfile($placement, $register));
            if ($aesName !== '' && $this->isUsablePersonName($aesName, $register)) {
                $overview['name'] = $aesName;
                if (is_array($user) && !empty($user['_id']) && trim((string) ($user['name'] ?? '')) !== $aesName) {
                    (new UserModel())->updateUser((string) $user['_id'], ['name' => $aesName]);
                }
            }
        }

        $phone = trim((string) ($mapped['phone'] ?? ''));
        if ($phone !== '') {
            $overview['phone'] = $phone;
        }

        $gender = trim((string) ($mapped['gender'] ?? ''));
        if ($gender !== '') {
            $overview['gender'] = $gender;
        }

        $aesApi = new AesApiService();
        $qual = $aesApi->extractQualificationFromPlacement($placement);
        if ($qual === [] && $register !== '') {
            $qualAdmno = $aesApi->resolveQualificationAdmissionNumber($placement, $register);
            if ($qualAdmno !== '' && ctype_digit($qualAdmno)) {
                try {
                    $qualParams = ['admno' => $qualAdmno, 'stud_admno' => $qualAdmno];
                    $regNo = trim((string) ($placement['registerno'] ?? $placement['registerNumber'] ?? ''));
                    if ($regNo !== '' && $regNo !== $qualAdmno) {
                        $qualParams['registerno'] = $regNo;
                        $qualParams['registerNumber'] = $regNo;
                    }
                    $qual = $aesApi->fetchStudentQualificationProfile($qualParams);
                } catch (\Throwable) {
                    $qual = [];
                }
            }
        }
        if ($qual !== []) {
            $qualMapped = (new AesLoginService())->mapAesDetailsToUserFields($qual);
            if (!empty($qualMapped['cgpa']) && (float) $qualMapped['cgpa'] > 0) {
                $overview['cgpa'] = (float) $qualMapped['cgpa'];
            }
            if (!empty($qualMapped['marks10th']) && (float) $qualMapped['marks10th'] > 0) {
                $overview['marks10th'] = (float) $qualMapped['marks10th'];
            }
            if (!empty($qualMapped['marks12th']) && (float) $qualMapped['marks12th'] > 0) {
                $overview['marks12th'] = (float) $qualMapped['marks12th'];
                $overview['ugMarks'] = (float) $qualMapped['marks12th'];
            }
            $quals = is_array($qual['qualifications'] ?? null) ? $qual['qualifications'] : [];
            if ($quals !== []) {
                $overview['qualifications'] = $quals;
            }
        }
        if (isset($mapped['backlogs'])) {
            $overview['backlogs'] = (int) $mapped['backlogs'];
        }

        $batch = trim((string) ($mapped['classBatch'] ?? ''));
        if ($batch !== '') {
            $overview['classBatch'] = $batch;
        }

        $collegeEmail = '';
        $personalEmail = trim((string) ($overview['personalEmail'] ?? ''));
        $email = strtolower(trim((string) ($mapped['email'] ?? '')));
        if ($email !== '') {
            if ($this->isCollegeEmailAddress($email)) {
                $collegeEmail = $email;
            } elseif ($personalEmail === '') {
                $personalEmail = $email;
            }
        }
        $collegeFromAes = strtolower(trim((string) ($mapped['collegeEmail'] ?? '')));
        if ($collegeFromAes !== '' && $this->isCollegeEmailAddress($collegeFromAes)) {
            $collegeEmail = $collegeFromAes;
        }
        $personalFromAes = trim((string) ($mapped['personalEmail'] ?? ''));
        if ($personalFromAes !== '') {
            $personalEmail = $personalFromAes;
        }
        if ($collegeEmail !== '') {
            $overview['collegeEmail'] = $collegeEmail;
            $overview['email'] = $collegeEmail;
        } elseif ($personalEmail !== '') {
            $overview['personalEmail'] = $personalEmail;
            if (empty($overview['email'])) {
                $overview['email'] = $personalEmail;
            }
        }

        $photoUrl = trim((string) ($mapped['photoUrl'] ?? $placement['stud_photo'] ?? ''));
        if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $overview['photoUrl'] = $photoUrl;
            $overview['photo'] = ['url' => $photoUrl, 'source' => 'aes'];
        }

        $deptName = trim((string) ($placement['departmentName'] ?? $placement['deptName'] ?? $placement['branch'] ?? $mapped['departmentName'] ?? $mapped['branch'] ?? ''));
        if ($deptName !== '') {
            $overview['departmentName'] = $deptName;
        }
        $deptCode = trim((string) ($placement['deptCode'] ?? $placement['department'] ?? $mapped['department'] ?? ''));
        if ($deptCode !== '') {
            $overview['department'] = $deptCode;
        } elseif ($deptName !== '') {
            $overview['department'] = $deptName;
        }

        return $overview;
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $personal Stored MariaDB personal JSON (fallback only).
     * @param array<string, mixed> $placement
     * @param array<string, mixed> $mapped
     * @return array{collegeEmail: string, personalEmail: string, phone: string}
     */
    private function contactFieldsFromSources(?array $user, array $personal, array $placement, array $mapped): array
    {
        $collegeEmail = strtolower(trim((string) ($mapped['collegeEmail'] ?? $placement['collegeEmail'] ?? '')));
        $personalEmail = trim((string) ($mapped['personalEmail'] ?? $placement['personalEmail'] ?? ''));
        $phone = trim((string) ($mapped['phone'] ?? $placement['phone'] ?? $placement['stud_mobiles'] ?? ''));

        $mappedEmail = strtolower(trim((string) ($mapped['email'] ?? '')));
        if ($collegeEmail === '' && $mappedEmail !== '' && $this->isCollegeEmailAddress($mappedEmail)) {
            $collegeEmail = $mappedEmail;
        } elseif ($personalEmail === '' && $mappedEmail !== '' && !$this->isCollegeEmailAddress($mappedEmail)) {
            $personalEmail = $mappedEmail;
        }

        $userEmail = strtolower(trim((string) ($user['email'] ?? '')));
        if ($collegeEmail === '' && $userEmail !== '' && $this->isCollegeEmailAddress($userEmail)) {
            $collegeEmail = $userEmail;
        }
        if ($personalEmail === '' && $userEmail !== '' && !$this->isCollegeEmailAddress($userEmail)) {
            $personalEmail = $userEmail;
        }

        if ($collegeEmail === '') {
            $collegeEmail = strtolower(trim((string) ($personal['collegeEmail'] ?? '')));
        }
        if ($personalEmail === '') {
            $personalEmail = trim((string) ($personal['personalEmail'] ?? $personal['email'] ?? ''));
        }
        if ($phone === '') {
            $phone = trim((string) ($personal['phone'] ?? ''));
        }

        return [
            'collegeEmail'  => $collegeEmail,
            'personalEmail' => $personalEmail,
            'phone'         => $phone,
        ];
    }

    /**
     * @return array{placement: array<string, mixed>, mapped: array<string, mixed>, register: string}
     */
    private function trustedAesContextForRegister(string $register): array
    {
        $register = strtoupper(trim($register));
        if ($register === '') {
            return ['placement' => [], 'mapped' => [], 'register' => ''];
        }

        $placement = $this->placementProfileForRegister($register);
        if ($placement === []) {
            return ['placement' => [], 'mapped' => [], 'register' => $register];
        }

        $mapped = (new AesLoginService())->mapAesDetailsToUserFields($placement);
        $aesReg = strtoupper(trim((string) ($mapped['registerNumber'] ?? '')));
        if ($aesReg !== '' && $aesReg !== $register) {
            return ['placement' => [], 'mapped' => [], 'register' => $register];
        }

        return ['placement' => $placement, 'mapped' => $mapped, 'register' => $register];
    }

    /**
     * Stream AES / remote student photo through the API (avoids browser mixed-content blocks).
     *
     * @param array<string, mixed> $ctx
     */
    public function streamStudentPhoto(string $studentId, array $ctx): void
    {
        $student = (new StudentModel())->findById($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        if (!$this->studentInScope($student, $ctx)) {
            Response::forbidden('Student is outside your department scope.');
        }

        try {
            $student = (new AesLoginService())->refreshStudentPlacementData($student);
        } catch (\Throwable) {
            // continue with stored photo
        }

        $photo = is_array($student['photo'] ?? null) ? $student['photo'] : null;
        $photoUrl = (new AesApiService())->resolvePhotoUrl(trim((string) ($photo['url'] ?? '')));
        if ($photoUrl === '') {
            Response::notFound('Student photo not available.');
        }

        $ch = curl_init($photoUrl);
        if ($ch === false) {
            Response::error('Could not load student photo.', 502);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AJCE-Placements/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            Response::error('Could not load student photo.', 502);
        }

        if ($contentType === '' || !str_starts_with(strtolower($contentType), 'image/')) {
            $contentType = 'image/jpeg';
        }

        header('Content-Type: ' . $contentType);
        header('Cache-Control: private, max-age=3600');
        echo $body;
        exit;
    }

    /**
     * @param array<string, mixed> $row Serialized student row (mutated in place for list APIs).
     * @param array<string, mixed> $student Raw student document.
     * @param array<string, mixed>|null $user Raw user document.
     * @return array<string, mixed>
     */
    public function enrichStudentListRow(array $row, array $student, ?array $user): array
    {
        $displayName = $this->studentDisplayName($student, $user);
        $photo = (new AesLoginService())->resolveProfilePhoto($student, is_array($user) ? $user : []);
        $photoUrl = (string) ($photo['photoUrl'] ?? '');

        if ($photoUrl === '') {
            $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            if ($register !== '') {
                $placement = $this->placementProfileForRegister($register);
                $photoUrl = trim((string) ($placement['photoUrl'] ?? $placement['stud_photo'] ?? ''));
                if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
                    $photo['photoUrl'] = $photoUrl;
                    $photo['photo'] = ['url' => $photoUrl, 'source' => 'aes'];
                }
            }
        }

        $userOut = $user ? (DocumentHelper::serialize($user) ?? []) : [];
        if ($displayName !== '') {
            $userOut['name'] = $displayName;
        }
        if ($photoUrl !== '') {
            $userOut['photoUrl'] = $photoUrl;
            $userOut['photo'] = $photo['photo'] ?? ['url' => $photoUrl, 'source' => 'aes'];
        }

        $row['user'] = $userOut !== [] ? $userOut : null;
        $row['displayName'] = $displayName;
        if ($photoUrl !== '') {
            $row['photoUrl'] = $photoUrl;
            $row['photo'] = $photo['photo'] ?? ['url' => $photoUrl, 'source' => 'aes'];
        }

        return $this->applyAesPlacementFieldsToRow($row, $student);
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $ctx
     */
    private function studentInScope(array $student, array $ctx): bool
    {
        if (!empty($ctx['isAdmin'])) {
            return true;
        }
        $scopeDept = (string) ($ctx['departmentId'] ?? '');
        if ($scopeDept === '') {
            return true;
        }
        return (string) ($student['departmentId'] ?? '') === $scopeDept;
    }

    private function isCollegeEmailAddress(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        return str_contains($email, '@students.amaljyothi.ac.in')
            || str_contains($email, '@amaljyothi.ac.in')
            || str_ends_with($email, '@ajce.in')
            || (bool) preg_match('/@[a-z0-9.-]+\.ajce\.in$/', $email);
    }

    /**
     * Faculty / staff college mailbox (not a programme student subdomain).
     */
    private function isFacultyCollegeEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }
        if (str_contains($email, '@students.amaljyothi.ac.in')) {
            return false;
        }
        if (preg_match('/@(mca|cse|ece|eee|me|ce|it|cs|mba|mtech|ecea|mech|civil)\.ajce\.in$/', $email)) {
            return false;
        }

        return str_contains($email, '@amaljyothi.ac.in') || str_ends_with($email, '@ajce.in');
    }

    /**
     * Placement student list/search should exclude staff accounts and research (PHD) profiles.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $row
     */
    private function isPlacementStudentListCandidate(array $student, ?array $user, array $row): bool
    {
        if (is_array($user)) {
            $userRole = (string) ($user['role'] ?? '');
            if ($userRole !== '' && $userRole !== 'student') {
                return false;
            }
        }

        $dept = is_array($row['department'] ?? null) ? $row['department'] : [];
        $deptCode = strtoupper(trim((string) ($row['departmentCode'] ?? $dept['code'] ?? '')));
        $deptName = strtoupper(trim((string) ($row['departmentName'] ?? $dept['name'] ?? '')));
        foreach ([$deptCode, $deptName] as $label) {
            if ($label === 'PHD' || str_contains($label, 'PHD')) {
                return false;
            }
        }

        $batch = strtoupper(trim((string) ($row['classBatch'] ?? $student['classBatch'] ?? '')));
        if (preg_match('/^PHD[-_]/', $batch)) {
            return false;
        }

        $userRow = is_array($row['user'] ?? null) ? $row['user'] : [];
        $emails = array_unique(array_filter([
            strtolower(trim((string) ($row['collegeEmail'] ?? ''))),
            strtolower(trim((string) ($user['email'] ?? ''))),
            strtolower(trim((string) ($userRow['email'] ?? ''))),
        ]));
        foreach ($emails as $email) {
            if ($this->isFacultyCollegeEmail($email)) {
                return false;
            }
        }

        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register !== '') {
            $placement = $this->placementProfileForRegister($register);
            $course = strtoupper(trim((string) ($placement['stud_course'] ?? $placement['stud_cource_short'] ?? '')));
            if ($course === 'PHD' || str_contains($course, 'PHD')) {
                return false;
            }
            $aesBatch = strtoupper(trim((string) ($placement['stud_class'] ?? '')));
            if (preg_match('/^PHD[-_]/', $aesBatch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Merge AES placement profile fields into a serialized student list row.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $student
     * @return array<string, mixed>
     */
    private function applyAesPlacementFieldsToRow(array $row, array $student): array
    {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register === '') {
            return $row;
        }

        $ctx = $this->trustedAesContextForRegister($register);
        $placement = $ctx['placement'];
        $mapped = $ctx['mapped'];
        if ($placement === []) {
            return $row;
        }

        if (!empty($mapped['classBatch'])) {
            $row['classBatch'] = (string) $mapped['classBatch'];
        }

        $aesName = trim((new AesLoginService())->displayNameFromAesProfile($placement, $register));
        if ($aesName !== '' && $this->isUsablePersonName($aesName, $register)) {
            $row['displayName'] = $aesName;
            $userOut = is_array($row['user'] ?? null) ? $row['user'] : [];
            $userOut['name'] = $aesName;
            $row['user'] = $userOut;
            self::$placementNameCache[$register] = $aesName;
        }

        $academic = is_array($row['academic'] ?? null) ? $row['academic'] : (is_array($student['academic'] ?? null) ? $student['academic'] : []);
        if ((float) ($academic['cgpa'] ?? 0) <= 0 && !empty($mapped['cgpa']) && (float) $mapped['cgpa'] > 0) {
            $academic['cgpa'] = (float) $mapped['cgpa'];
        }
        if ((float) ($academic['marks10th'] ?? 0) <= 0 && !empty($mapped['marks10th']) && (float) $mapped['marks10th'] > 0) {
            $academic['marks10th'] = (float) $mapped['marks10th'];
        }
        if ((float) ($academic['marks12th'] ?? 0) <= 0 && !empty($mapped['marks12th']) && (float) $mapped['marks12th'] > 0) {
            $academic['marks12th'] = (float) $mapped['marks12th'];
            $academic['ugMarks'] = (float) $mapped['marks12th'];
        }
        if (isset($mapped['backlogs'])) {
            $academic['backlogs'] = (int) $mapped['backlogs'];
        }
        if ($academic !== []) {
            $row['academic'] = $academic;
        }

        $personal = is_array($row['personal'] ?? null) ? $row['personal'] : (is_array($student['personal'] ?? null) ? $student['personal'] : []);
        $contact = $this->contactFieldsFromSources(null, $personal, $placement, $mapped);
        if ($contact['phone'] !== '') {
            $personal['phone'] = $contact['phone'];
            $row['personal'] = $personal;
            $row['phone'] = $contact['phone'];
        }
        $gender = trim((string) ($mapped['gender'] ?? $personal['gender'] ?? ''));
        if ($gender !== '') {
            $personal['gender'] = $gender;
            $row['personal'] = $personal;
            $row['gender'] = $gender;
        }
        if ($contact['collegeEmail'] !== '') {
            $row['collegeEmail'] = $contact['collegeEmail'];
        }
        if ($contact['personalEmail'] !== '') {
            $row['personalEmail'] = $contact['personalEmail'];
        }

        $deptName = trim((string) ($mapped['departmentName'] ?? $mapped['branch'] ?? $placement['departmentName'] ?? $placement['deptName'] ?? $placement['branch'] ?? ''));
        if ($deptName !== '') {
            $row['departmentName'] = $deptName;
        }
        $deptCode = trim((string) ($mapped['department'] ?? $placement['deptCode'] ?? $placement['department'] ?? ''));
        if ($deptCode !== '') {
            $row['departmentCode'] = $deptCode;
        } elseif ($deptName !== '') {
            $row['departmentCode'] = $deptName;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $user
     */
    private function studentDisplayName(array $student, ?array $user): string
    {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register !== '' && isset(self::$placementNameCache[$register]) && self::$placementNameCache[$register] !== '') {
            return self::$placementNameCache[$register];
        }

        $aes = new AesLoginService();
        if ($register !== '') {
            $placement = $this->placementProfileForRegister($register);
            $aesName = trim($aes->displayNameFromAesProfile($placement, $register));
            if ($aesName !== '' && $this->isUsablePersonName($aesName, $register)) {
                if (is_array($user) && !empty($user['_id']) && trim((string) ($user['name'] ?? '')) !== $aesName) {
                    (new UserModel())->updateUser((string) $user['_id'], ['name' => $aesName]);
                }
                self::$placementNameCache[$register] = $aesName;

                return $aesName;
            }
        }

        $name = is_array($user) ? trim((string) ($user['name'] ?? '')) : '';
        if ($this->isUsablePersonName($name, $register)) {
            return $name;
        }

        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) ($personal['name'] ?? $personal['fullName'] ?? ''));
        if ($this->isUsablePersonName($name, $register)) {
            return $name;
        }

        if ($register === '') {
            return '';
        }

        if (!isset(self::$placementNameCache[$register])) {
            self::$placementNameCache[$register] = '';
        }

        return self::$placementNameCache[$register];
    }

    private function isUsablePersonName(string $name, string $register): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return false;
        }
        if ($register !== '' && strcasecmp($name, $register) === 0) {
            return false;
        }

        return (bool) preg_match('/[a-zA-Z]/', $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function placementProfileForRegister(string $register): array
    {
        $register = strtoupper(trim($register));
        if ($register === '') {
            return [];
        }
        if (isset(self::$placementProfileCache[$register])) {
            return self::$placementProfileCache[$register];
        }

        $profile = [];
        try {
            $fetched = (new AesApiService())->fetchStudentPlacementProfile(['admno' => $register]);
            $profile = is_array($fetched) ? $fetched : [];
        } catch (\Throwable) {
            $profile = [];
        }

        self::$placementProfileCache[$register] = $profile;

        return $profile;
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
            (string) ($row['displayName'] ?? ''),
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
     * Resolve a student profile by MariaDB id, linked user id, or register number.
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

        $student = $studentModel->findByUserId($ref);
        if ($student) {
            return $student;
        }

        if (preg_match('/^\d{4,8}$/', $ref) === 1) {
            return $studentModel->findByRegisterNumber($ref);
        }

        return null;
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
        $selfCompanyKeys = [];
        if (is_array($self) && (string) ($self['companyName'] ?? '') !== '') {
            $selfCompany = (string) $self['companyName'];
            $selfRole = (string) ($self['role'] ?? '');
            $selfCompanyKeys[$this->pipelineCompanyKey($selfCompany)] = true;
            $pipeline[] = [
                'id'             => 'self-placement',
                'company'        => $selfCompany,
                'role'           => $selfRole,
                'stage'          => 'self_reported',
                'status'         => ($placed || (string) ($self['status'] ?? '') === 'approved') ? 'placed' : (string) ($self['status'] ?? 'pending'),
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
            $company = (string) ($result['company'] ?? '');
            $companyKey = $this->pipelineCompanyKey($company);
            // Self-placement already upserts a recruitment result — show only the self-reported row.
            if ($companyKey !== '' && isset($selfCompanyKeys[$companyKey])) {
                continue;
            }
            $status = (string) ($result['status'] ?? 'selected');
            $serialized = DocumentHelper::serialize($result) ?? [];
            $pipeline[] = [
                'id'             => 'result-' . (string) ($result['_id'] ?? ''),
                'company'        => $company,
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

    private function pipelineCompanyKey(string $company): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $company) ?? $company));
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
