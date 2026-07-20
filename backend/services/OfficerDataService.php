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
                'applied', 'resume_pending' => 'applied',
                'resume_verified' => 'resume_verification',
                'officer_approved' => 'approval',
                'company_review' => 'company_selection',
                'shortlisted' => 'shortlisted',
                'selected' => 'company_selection',
                'rejected' => 'rejected',
                default => $status,
            };

            $studentArr = is_array($student) ? $student : [];
            $appResume = is_array($app['resume'] ?? null) ? $app['resume'] : [];
            $resumeFileMeta = $this->resumeFileForApplication($app);
            $resumePath = is_array($resumeFileMeta) ? (string) ($resumeFileMeta['path'] ?? '') : '';
            $resumeFile = is_array($resumeFileMeta) ? (string) ($resumeFileMeta['filename'] ?? '') : '';
            if ($resumeFile === '' && $resumePath !== '') {
                $resumeFile = basename(str_replace('\\', '/', $resumePath));
            }

            $companyName = (string) ($company['companyName'] ?? '');
            if ($companyName === '' && is_array($drive)) {
                $title = (string) ($drive['title'] ?? '');
                if (str_contains($title, '—')) {
                    $companyName = trim((string) (explode('—', $title, 2)[1] ?? ''));
                }
            }

            $personal = is_array($studentArr['personal'] ?? null) ? $studentArr['personal'] : [];
            $academic = is_array($studentArr['academic'] ?? null) ? $studentArr['academic'] : [];
            $userEmail = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
            $userPhone = is_array($user) ? trim((string) ($user['phone'] ?? '')) : '';
            $collegeEmail = trim((string) ($personal['collegeEmail'] ?? $userEmail));
            $personalEmail = trim((string) ($personal['personalEmail'] ?? ''));
            $email = $collegeEmail !== '' ? $collegeEmail : $personalEmail;
            $phone = trim((string) ($personal['phone'] ?? $userPhone));
            $cgpa = (float) ($academic['cgpa'] ?? 0);

            $studentName = $studentArr !== []
                ? $this->studentDisplayName($studentArr, is_array($user) ? $user : null)
                : '';

            // Backfill contact/CGPA from AES when local profile fields are thin.
            if ($studentArr !== [] && ($email === '' || $phone === '' || $cgpa <= 0)) {
                $aesRow = $this->applyAesPlacementFieldsToRow([
                    'academic' => $academic,
                    'personal' => $personal,
                    'collegeEmail' => $collegeEmail,
                    'personalEmail' => $personalEmail,
                    'phone' => $phone,
                ], $studentArr);
                $aesAcademic = is_array($aesRow['academic'] ?? null) ? $aesRow['academic'] : $academic;
                $aesPersonal = is_array($aesRow['personal'] ?? null) ? $aesRow['personal'] : $personal;
                if ($cgpa <= 0) {
                    $cgpa = (float) ($aesAcademic['cgpa'] ?? 0);
                }
                if ($phone === '') {
                    $phone = trim((string) ($aesRow['phone'] ?? $aesPersonal['phone'] ?? ''));
                }
                if ($collegeEmail === '') {
                    $collegeEmail = trim((string) ($aesRow['collegeEmail'] ?? ''));
                }
                if ($personalEmail === '') {
                    $personalEmail = trim((string) ($aesRow['personalEmail'] ?? ''));
                }
                if ($email === '') {
                    $email = $collegeEmail !== '' ? $collegeEmail : $personalEmail;
                }
                if ($studentName === '') {
                    $studentName = trim((string) ($aesRow['displayName'] ?? ''));
                }
            }

            $row = DocumentHelper::serialize($app) ?? [];
            $row['driveId'] = (string) ($app['driveId'] ?? '');
            $row['studentId'] = (string) ($app['studentId'] ?? '');
            $row['studentName'] = $studentName;
            $row['registerNumber'] = $studentArr !== [] ? (string) ($studentArr['registerNumber'] ?? '') : '';
            $row['department'] = is_array($dept) ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '';
            $row['email'] = $email;
            $row['collegeEmail'] = $collegeEmail;
            $row['personalEmail'] = $personalEmail;
            $row['phone'] = $phone;
            $row['cgpa'] = $cgpa > 0 ? $cgpa : null;
            $row['company'] = $companyName;
            $row['role'] = is_array($drive) ? (string) ($drive['title'] ?? '') : '';
            $row['stage'] = $stage;
            $row['appliedAt'] = $row['createdAt'] ?? null;
            $row['resumeLabel'] = (string) ($appResume['label'] ?? '');
            $row['resumeFileName'] = $resumeFile;
            $row['resumePath'] = $resumePath;
            $row['hasResume'] = $resumePath !== '';
            $certs = is_array($app['certificates'] ?? null) ? $app['certificates'] : [];
            $row['certificateCount'] = count($certs);
            $row['certificates'] = [];
            foreach ($certs as $idx => $cert) {
                if (!is_array($cert)) {
                    continue;
                }
                $row['certificates'][] = [
                    'index'    => (int) $idx,
                    'fileName' => (string) ($cert['fileName'] ?? ''),
                    'label'    => (string) ($cert['label'] ?? pathinfo((string) ($cert['fileName'] ?? ''), PATHINFO_FILENAME)),
                ];
            }
            $customAnswers = is_array($app['customAnswers'] ?? null) ? $app['customAnswers'] : [];
            $row['customAnswers'] = [];
            foreach ($customAnswers as $answer) {
                if (!is_array($answer)) {
                    continue;
                }
                $title = trim((string) ($answer['title'] ?? ''));
                $value = trim((string) ($answer['value'] ?? ''));
                if ($title === '' && $value === '') {
                    continue;
                }
                $row['customAnswers'][] = [
                    'fieldId' => (string) ($answer['fieldId'] ?? ''),
                    'title'   => $title,
                    'value'   => $value,
                ];
            }
            $dob = trim((string) ($app['applicantDob'] ?? $personal['dob'] ?? ''));
            $row['applicantDob'] = $dob;
            $age = $app['applicantAge'] ?? null;
            if (($age === null || $age === '') && $dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                try {
                    $born = new \DateTimeImmutable($dob);
                    $age = (int) $born->diff(new \DateTimeImmutable('today'))->y;
                } catch (\Throwable) {
                    $age = null;
                }
            }
            $row['applicantAge'] = is_numeric($age) ? (int) $age : null;
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
     * Eligible students for a drive who have not submitted an application yet.
     *
     * @param array<string, mixed> $ctx
     * @return array{
     *   driveId: string,
     *   driveTitle: string,
     *   driveStatus: string,
     *   company: string,
     *   appliedCount: int,
     *   eligibleNotAppliedCount: int,
     *   returned: int,
     *   limit: int,
     *   students: array<int, array<string, mixed>>
     * }
     */
    public function listNonApplicantsForDrive(string $driveId, array $ctx, int $limit = 300): array
    {
        $limit = max(1, min(500, $limit));
        $drive = (new DriveModel())->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }
        if (!$ctx['isAdmin'] && !PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {
            Response::forbidden('This drive is not for your department.');
        }

        $apps = (new ApplicationModel())->findByDrive($driveId);
        $appliedIds = [];
        foreach ($apps as $app) {
            $sid = (string) ($app['studentId'] ?? '');
            if ($sid !== '') {
                $appliedIds[$sid] = true;
            }
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $engine = new EligibilityEngine();

        $departments = [];
        foreach ($deptModel->findAll([], 200) as $d) {
            $departments[(string) $d['_id']] = $d;
        }

        $companyName = '';
        $companyId = (string) ($drive['companyId'] ?? '');
        if ($companyId !== '') {
            $company = (new CompanyModel())->findById($companyId);
            $companyName = is_array($company) ? (string) ($company['companyName'] ?? '') : '';
        }
        if ($companyName === '') {
            $title = (string) ($drive['title'] ?? '');
            if (str_contains($title, '—')) {
                $companyName = trim((string) (explode('—', $title, 2)[0] ?? ''));
            }
        }

        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $students = $studentModel->findAll($filter, 1000);
        $nonApplicants = [];
        $eligibleCount = 0;

        foreach ($students as $student) {
            $sid = (string) ($student['_id'] ?? '');
            if ($sid === '' || isset($appliedIds[$sid])) {
                continue;
            }

            $userId = (string) ($student['userId'] ?? '');
            $deptId = (string) ($student['departmentId'] ?? '');
            $user = $userId !== '' ? $userModel->findById($userId) : null;
            $dept = $departments[$deptId] ?? null;
            $row = DocumentHelper::serialize($student) ?? [];
            $row = $this->enrichStudentListRow($row, $student, $user, false);
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : null;
            if (!$this->isPlacementStudentListCandidate($student, $user, $row, false)) {
                continue;
            }

            if (!$engine->driveVisibleToStudent($student, $drive)) {
                continue;
            }

            $check = $engine->checkStudentAgainstDrive($student, $drive, false);
            if (!($check['eligible'] ?? false)) {
                continue;
            }

            $eligibleCount++;
            if (count($nonApplicants) >= $limit) {
                continue;
            }

            $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $resume = is_array($student['resume'] ?? null) ? $student['resume'] : [];
            $chances = is_array($student['placementChances'] ?? null) ? $student['placementChances'] : [];

            $nonApplicants[] = [
                'studentId'         => $sid,
                'name'              => (string) ($row['displayName'] ?? ($user['name'] ?? '')),
                'registerNumber'    => (string) ($student['registerNumber'] ?? ''),
                'department'        => (string) ($dept['code'] ?? $dept['name'] ?? ''),
                'cgpa'              => (float) ($academic['cgpa'] ?? 0),
                'backlogs'          => (int) ($academic['backlogs'] ?? 0),
                'hasResume'         => !empty($resume['path']) || !empty($resume['filename']),
                'placed'            => (bool) ($student['placed'] ?? false),
                'chancesRemaining'  => (int) ($chances['remaining'] ?? 0),
                'phone'             => (string) ($personal['phone'] ?? $row['phone'] ?? ''),
                'email'             => (string) ($row['collegeEmail'] ?? ($user['email'] ?? '')),
            ];
        }

        return [
            'driveId'                 => $driveId,
            'driveTitle'              => (string) ($drive['title'] ?? ''),
            'driveStatus'             => (string) ($drive['status'] ?? ''),
            'company'                 => $companyName,
            'appliedCount'            => count($appliedIds),
            'eligibleNotAppliedCount' => $eligibleCount,
            'returned'                => count($nonApplicants),
            'limit'                   => $limit,
            'students'                => $nonApplicants,
        ];
    }

    /**
     * Resolve stored path/URI for an application's resume (application snapshot or student profile).
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
            $storedName = (string) ($studentResume['storedName'] ?? '');
            if ($storedName !== '') {
                $path = (new ObjectStorageService())->uri(ObjectStorageService::FOLDER_RESUMES, $storedName);
            }
        }
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'uploads://')) {
            $path = (new ObjectStorageService())->uri(
                ObjectStorageService::FOLDER_RESUMES,
                basename($path)
            );
        }

        $filename = (string) ($appResume['fileName'] ?? $studentResume['filename'] ?? basename(str_replace('\\', '/', $path)));

        return ['path' => $path, 'filename' => $filename];
    }

    public function streamApplicationResume(string $appId, array $ctx): void
    {
        $app = $this->assertApplicationInScope($appId, $ctx);
        $this->streamResolvedApplicationResume($app, false);
    }

    /**
     * Stream application resume without officer-scope check (signed public report links).
     * Forces PDF download (attachment) for Excel "Open resume" clicks.
     */
    public function streamApplicationResumeSigned(string $appId): void
    {
        $appId = trim($appId);
        $app = (new ApplicationModel())->findById($appId);
        if (!$app) {
            Response::notFound('Application not found for this resume link. Generate the report again.');
        }
        $this->streamResolvedApplicationResume($app, true);
    }

    /**
     * @param array<string, mixed> $app
     */
    private function streamResolvedApplicationResume(array $app, bool $forceDownload = false): void
    {
        $file = $this->resumeFileForApplication($app);
        if ($file === null) {
            Response::notFound('Resume file not found for this application.');
        }

        $storage = new ObjectStorageService();
        $filename = $this->resumeDownloadFilename((string) ($file['filename'] ?? 'resume.pdf'), $forceDownload);
        $mime = $forceDownload
            ? 'application/pdf'
            : $storage->guessMime($filename);
        try {
            $storage->streamWithFallback(
                $file['path'],
                $filename,
                $mime,
                !$forceDownload,
                ObjectStorageService::FOLDER_RESUMES
            );
        } catch (\Throwable) {
            Response::notFound('Resume file not found for this application.');
        }
    }

    /**
     * Stream a certificate PDF attached to an application.
     *
     * @param array<string, mixed> $ctx
     */
    public function streamApplicationCertificate(string $appId, int $index, array $ctx): void
    {
        $app = $this->assertApplicationInScope($appId, $ctx);
        $certs = is_array($app['certificates'] ?? null) ? $app['certificates'] : [];
        if ($index < 0 || $index >= count($certs) || !is_array($certs[$index] ?? null)) {
            Response::notFound('Certificate not found for this application.');
        }
        $cert = $certs[$index];
        $path = trim((string) ($cert['path'] ?? ''));
        $filename = trim((string) ($cert['fileName'] ?? ''));
        if ($filename === '' && $path !== '') {
            $filename = basename($path);
        }
        if ($path === '') {
            Response::notFound('Certificate file not found for this application.');
        }
        if ($filename === '') {
            $filename = 'certificate.pdf';
        }

        $storage = new ObjectStorageService();
        $mime = $storage->guessMime($filename);
        try {
            $storage->streamWithFallback(
                $path,
                $filename,
                $mime,
                true,
                ObjectStorageService::FOLDER_CERTIFICATES
            );
        } catch (\Throwable) {
            Response::notFound('Certificate file not found for this application.');
        }
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(array $ctx, ?string $query = null): array
    {
        if (!empty($ctx['staffScope'])) {
            $aesRows = $this->listStudentsFromAesDirectory($ctx);
            if ($aesRows !== []) {
                return $this->filterStudentRows($aesRows, $query);
            }
        }

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
            if (!empty($ctx['staffScope']) && !StaffContext::studentMatchesScope($s, $ctx)) {
                continue;
            }
            $userId = (string) ($s['userId'] ?? '');
            $deptId = (string) ($s['departmentId'] ?? '');
            $u = $userId ? $userModel->findById($userId) : null;
            $dept = $departments[$deptId] ?? null;

            $row = DocumentHelper::serialize($s) ?? [];
            // List views must stay local-only — AES-per-row freezes Students page load.
            $row = $this->enrichStudentListRow($row, $s, $u, false);
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : null;
            if (!$this->isPlacementStudentListCandidate($s, $u, $row, false)) {
                continue;
            }
            $rows[] = $row;
        }

        return $this->filterStudentRows($rows, $query);
    }

    /**
     * Complete AES roster for one selected placement class, without requiring
     * an existing local student/profile record.
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listAesClassStudents(array $ctx, string $programme, string $batch): array
    {
        $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($programme);
        $batch = trim($batch);
        if ($programme === '' || $batch === '') {
            return [];
        }

        $campusWide = !empty($ctx['campusWide']) || (
            !empty($ctx['isAdmin']) && empty($ctx['staffScope']) && empty($ctx['departmentId'])
        );
        $deptAesId = $campusWide ? '' : (new PlacementFilterService())->resolveParentDeptAesId($ctx);
        $records = $this->fetchAesDirectoryRecords($deptAesId, $campusWide);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : null;
        $deptCode = strtoupper(trim((string) ($dept['code'] ?? '')));
        $deptName = trim((string) ($dept['name'] ?? ''));
        $localByKey = $this->indexLocalStudentsForClassRoster($ctx);
        $rows = [];
        $seen = [];

        foreach ($records as $record) {
            $recordDept = strtoupper(trim((string) (
                $record['stud_deptcode']
                ?? $record['parentDepartmentCode']
                ?? ''
            )));
            if ($deptAesId !== '' && $recordDept !== '' && $recordDept !== strtoupper($deptAesId)) {
                continue;
            }
            if (!$this->isAesStudyingStudent($record) || !$this->isAesFinalYearStudent($record)) {
                continue;
            }

            $admno = strtoupper(trim((string) (
                $record['admno']
                ?? $record['stud_admno']
                ?? ''
            )));
            $regNo = strtoupper(trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? '')));
            $local = null;
            foreach ([$admno, $regNo] as $key) {
                if ($key !== '' && isset($localByKey[$key])) {
                    $local = $localByKey[$key];
                    break;
                }
            }
            if ($local === null) {
                $local = $this->findLocalStudentByAesKeys($admno, $regNo);
                if (is_array($local)) {
                    foreach ([$admno, $regNo] as $key) {
                        if ($key !== '') {
                            $localByKey[$key] = $local;
                        }
                    }
                }
            }

            $row = $this->mapAesDirectoryRecordToListRow($record, $local, $dept, $deptCode, $deptName);
            if ($row === null || strcasecmp((string) ($row['classBatch'] ?? ''), $batch) !== 0) {
                continue;
            }
            $rowProgramme = DepartmentProgrammeCatalog::resolveProgrammeCode((string) (
                $row['stud_course']
                ?? $row['programme']
                ?? ''
            ));
            $classProgramme = $this->placementProgrammeCode(trim(implode(' ', array_filter([
                (string) ($row['classBatch'] ?? ''),
                (string) ($row['stud_class'] ?? ''),
                (string) ($row['stud_course'] ?? ''),
                (string) ($row['programme'] ?? ''),
            ]))));
            $effectiveProgramme = $classProgramme !== '' ? $classProgramme : $rowProgramme;
            $wantProgramme = DepartmentProgrammeCatalog::resolveProgrammeCode($programme);
            if ($wantProgramme === '' || (
                strcasecmp($effectiveProgramme, $wantProgramme) !== 0
                && strcasecmp($rowProgramme, $wantProgramme) !== 0
            )) {
                continue;
            }

            $key = strtoupper(trim((string) ($row['admno'] ?? $row['registerNumber'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $row;
        }

        return $this->supplementAesClassRosterGaps(
            $rows,
            $seen,
            $programme,
            $batch,
            $dept,
            $deptCode,
            $deptName,
            $localByKey
        );
    }

    /**
     * AES getAllStudInfo4Placement often omits a few classmates still marked on an
     * earlier semester (e.g. INMCA strength 57 = 55×S9 + 2×S8). Fill small admno
     * gaps with individual getStudInfo4Placement lookups for the same cohort.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, true> $seen
     * @param array<string, mixed>|null $dept
     * @return array<int, array<string, mixed>>
     */
    private function supplementAesClassRosterGaps(
        array $rows,
        array &$seen,
        string $programme,
        string $batch,
        ?array $dept,
        string $deptCode,
        string $deptName,
        array $localByKey = []
    ): array {
        $cohort = $this->cohortPrefixFromBatch($batch);
        if ($cohort === '' || $rows === []) {
            return $rows;
        }

        $admnos = [];
        foreach ($rows as $row) {
            $admno = (int) preg_replace('/\D+/', '', (string) ($row['admno'] ?? $row['registerNumber'] ?? '')) ;
            if ($admno > 0) {
                $admnos[$admno] = true;
            }
        }
        if (count($admnos) < 2) {
            return $rows;
        }

        $sorted = array_keys($admnos);
        sort($sorted, SORT_NUMERIC);
        $gapAdmnos = [];
        for ($i = 0, $n = count($sorted); $i < $n - 1; $i++) {
            $a = $sorted[$i];
            $b = $sorted[$i + 1];
            $gap = $b - $a;
            // Skip large jumps (lateral/odd admnos like 14170 → 14998).
            if ($gap <= 1 || $gap > 5) {
                continue;
            }
            for ($candidate = $a + 1; $candidate < $b; $candidate++) {
                $gapAdmnos[] = (string) $candidate;
            }
        }
        if ($gapAdmnos === []) {
            return $rows;
        }

        $wantProgramme = DepartmentProgrammeCatalog::resolveProgrammeCode($programme);
        $api = new AesApiService();
        foreach ($gapAdmnos as $admno) {
            $key = strtoupper($admno);
            if (isset($seen[$key])) {
                continue;
            }
            try {
                // Full profile — fetchStudInfoPlacementRow() only returns course/branch/class.
                $record = $api->fetchStudentPlacementProfile(['admno' => $admno]);
            } catch (\Throwable) {
                continue;
            }
            if ($record === [] || !$this->isAesStudyingStudent($record)) {
                continue;
            }

            $class = trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? ''));
            $classNorm = DepartmentProgrammeCatalog::normalizeCode($class);
            $cohortNorm = DepartmentProgrammeCatalog::normalizeCode($cohort);
            if ($cohortNorm === '' || !str_starts_with($classNorm, $cohortNorm)) {
                continue;
            }

            $hint = trim(implode(' ', array_filter([
                (string) ($record['stud_course'] ?? ''),
                (string) ($record['stud_cource_short'] ?? ''),
                (string) ($record['stud_branch'] ?? ''),
                $class,
            ])));
            $detected = $this->placementProgrammeCode($hint);
            $resolvedCourse = DepartmentProgrammeCatalog::resolveProgrammeCode((string) (
                $record['stud_course'] ?? $record['stud_cource_short'] ?? ''
            ));
            if ($wantProgramme !== ''
                && strcasecmp($detected, $wantProgramme) !== 0
                && strcasecmp($resolvedCourse, $wantProgramme) !== 0
                && !($wantProgramme === 'INMCA' && strcasecmp((string) ($record['stud_branch'] ?? ''), 'Integrated') === 0)
            ) {
                continue;
            }

            // Attach to the selected class so department filters keep the classmate.
            $record['stud_class'] = $batch;
            $record['classBatch'] = $batch;
            if (trim((string) ($record['admno'] ?? $record['stud_admno'] ?? '')) === '') {
                $record['admno'] = $admno;
                $record['stud_admno'] = $admno;
            }

            $local = $localByKey[$key] ?? null;
            $regNo = strtoupper(trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? '')));
            if ($local === null && $regNo !== '' && isset($localByKey[$regNo])) {
                $local = $localByKey[$regNo];
            }

            $row = $this->mapAesDirectoryRecordToListRow($record, $local, $dept, $deptCode, $deptName);
            if ($row === null) {
                continue;
            }
            $row['classBatch'] = $batch;
            $row['stud_class'] = $batch;
            $seen[$key] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Full class roster for staff CT/CoCT assignments — uses listAesClassStudents
     * (with admno-gap supplementation) so classmates still on an earlier semester
     * appear alongside the current batch (e.g. INMCA 57 = 55×S9 + 2×S8).
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    private function listStaffClassRosterRows(array $ctx): array
    {
        $staffBatches = StaffContext::assignedClassBatches($ctx);
        if ($staffBatches === []) {
            return [];
        }

        $byAdmno = [];
        foreach ($staffBatches as $cohort) {
            $cohort = trim((string) $cohort);
            if ($cohort === '') {
                continue;
            }

            $programme = $this->placementProgrammeCode($cohort);
            if ($programme === '') {
                $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($cohort);
            }
            if ($programme === '') {
                continue;
            }

            $semesterBatch = $this->resolvePrimaryClassBatchForCohort($ctx, $cohort);
            if ($semesterBatch === '') {
                continue;
            }

            foreach ($this->listAesClassStudents($ctx, $programme, $semesterBatch) as $row) {
                $key = strtoupper(trim((string) ($row['admno'] ?? $row['registerNumber'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $byAdmno[$key] = $row;
            }
        }

        return array_values($byAdmno);
    }

    /**
     * Local PlaceHub rows for the staff member's assigned cohorts, without the
     * final-year-only restriction. This keeps the Students page aligned with the
     * real class roster even when a few classmates still carry an older semester
     * label like S8 inside the same 2022-27 cohort.
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    private function listLocalStaffClassRosterRows(array $ctx): array
    {
        $staffBatches = StaffContext::assignedClassBatches($ctx);
        if ($staffBatches === []) {
            return [];
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();

        $departments = [];
        foreach ($deptModel->findAll([], 400) as $d) {
            $departments[(string) ($d['_id'] ?? '')] = $d;
        }

        $rows = [];
        foreach ($studentModel->findAll(PlacementOfficerContext::studentCollectionFilter($ctx), 10000) as $student) {
            if (!StaffContext::classBatchMatchesAssigned(
                StaffContext::studentClassBatch($student),
                $staffBatches
            )) {
                continue;
            }

            $userId = (string) ($student['userId'] ?? '');
            $user = $userId !== '' ? $userModel->findById($userId) : null;
            if (is_array($user)) {
                $role = (string) ($user['role'] ?? '');
                if ($role !== '' && $role !== 'student') {
                    continue;
                }
                if (($user['status'] ?? '') === 'blocked') {
                    continue;
                }
            }

            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $departments[$deptId] ?? null;
            $row = DocumentHelper::serialize($student) ?? [];
            $row = $this->enrichStudentListRow($row, $student, $user, false);
            $admno = strtoupper(trim((string) ($row['admno'] ?? $row['registerNumber'] ?? $student['registerNumber'] ?? '')));
            if ($admno !== '') {
                $row['admno'] = $admno;
                $row['registerNumber'] = $admno;
            }
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : ($row['department'] ?? null);
            if (!$this->isPlacementStudentListCandidate($student, $user, $row, false)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Pick the dominant semester batch for a cohort (highest S, tie-break by headcount).
     *
     * @param array<string, mixed> $ctx
     */
    private function resolvePrimaryClassBatchForCohort(array $ctx, string $cohort): string
    {
        $cohort = strtoupper(trim($cohort));
        if ($cohort === '') {
            return '';
        }
        if (preg_match('/-S\d+$/i', $cohort) === 1) {
            return $cohort;
        }

        $cohortNorm = DepartmentProgrammeCatalog::normalizeCode($cohort);
        if ($cohortNorm === '') {
            return '';
        }

        $campusWide = !empty($ctx['campusWide']) || (
            !empty($ctx['isAdmin']) && empty($ctx['staffScope']) && empty($ctx['departmentId'])
        );
        $deptAesId = $campusWide ? '' : (new PlacementFilterService())->resolveParentDeptAesId($ctx);
        $records = $this->fetchAesDirectoryRecords($deptAesId, $campusWide);

        $batchCounts = [];
        foreach ($records as $record) {
            if (!$this->isAesStudyingStudent($record)) {
                continue;
            }
            $class = trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? ''));
            if ($class === '') {
                continue;
            }
            $classNorm = DepartmentProgrammeCatalog::normalizeCode($class);
            if (!str_starts_with($classNorm, $cohortNorm)) {
                continue;
            }
            $batchCounts[$class] = ($batchCounts[$class] ?? 0) + 1;
        }

        if ($batchCounts === []) {
            return '';
        }

        uksort(
            $batchCounts,
            static function (string $a, string $b) use ($batchCounts): int {
                $countCmp = ($batchCounts[$b] ?? 0) <=> ($batchCounts[$a] ?? 0);
                if ($countCmp !== 0) {
                    return $countCmp;
                }

                return self::semesterFromBatchLabel($b) <=> self::semesterFromBatchLabel($a);
            }
        );

        return (string) array_key_first($batchCounts);
    }

    private static function semesterFromBatchLabel(string $batch): int
    {
        if (preg_match('/-S(\d+)$/i', $batch, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Cohort prefix shared by semester variants, e.g. MCAINT2022-27-S9 → MCAINT2022-27.
     */
    private function cohortPrefixFromBatch(string $batch): string
    {
        $batch = strtoupper(trim($batch));
        if ($batch === '') {
            return '';
        }
        if (preg_match(
            '/^((?:MCAINT|INMCA|MCA|BCA|[A-Z]{2,10})\d{4}\s*[-–]\s*\d{2,4})/',
            $batch,
            $m
        ) === 1) {
            return strtoupper(preg_replace('/\s+/', '', $m[1]) ?? $m[1]);
        }

        return '';
    }

    /**
     * Campus-wide final-year studying students for admin Students page.
     * Prefer AES getAllStudInfo4Placement rows, then fill from the PlaceHub student table
     * (AES currently often returns a single object).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCampusFinalYearStudents(?string $query = null): array
    {
        $ctx = [
            'isAdmin'      => true,
            'campusWide'   => true,
            'departmentId' => null,
            'department'   => null,
            'profile'      => null,
        ];

        return $this->listFinalYearStudentsForScope($ctx, $query);
    }

    /**
     * Final-year students for a scope: campus-wide (admin) or department (PO/staff).
     * Merges AES directory rows with PlaceHub students so policyAccepted is accurate.
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listFinalYearStudentsForScope(array $ctx, ?string $query = null): array
    {
        $campusWide = !empty($ctx['campusWide']) || (
            !empty($ctx['isAdmin']) && empty($ctx['staffScope']) && empty($ctx['departmentId'])
        );
        if (!$campusWide && empty($ctx['departmentId'])) {
            return [];
        }

        $byAdmno = [];
        $aesRows = !empty($ctx['staffScope'])
            ? $this->listStaffClassRosterRows($ctx)
            : $this->listStudentsFromAesDirectory($ctx);
        foreach ($aesRows as $row) {
            $key = strtoupper(trim((string) ($row['admno'] ?? $row['registerNumber'] ?? '')));
            if ($key === '') {
                continue;
            }
            $byAdmno[$key] = $row;
        }

        $localRows = !empty($ctx['staffScope'])
            ? $this->listLocalStaffClassRosterRows($ctx)
            : $this->listFinalYearFromStudentTable($ctx);
        foreach ($localRows as $row) {
            $key = strtoupper(trim((string) ($row['admno'] ?? $row['registerNumber'] ?? '')));
            if ($key === '') {
                continue;
            }
            if (!isset($byAdmno[$key])) {
                $byAdmno[$key] = $row;
                continue;
            }
            $byAdmno[$key] = $this->mergeFinalYearListRows($byAdmno[$key], $row);
        }

        $rows = array_values($byAdmno);
        if (!empty($ctx['staffScope'])) {
            $staffBatches = StaffContext::assignedClassBatches($ctx);
            if ($staffBatches === []) {
                $rows = [];
            } else {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => StaffContext::classBatchMatchesAssigned(
                        StaffContext::studentClassBatch($row),
                        $staffBatches
                    )
                ));
            }
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp(
                (string) ($a['displayName'] ?? $a['registerNumber'] ?? ''),
                (string) ($b['displayName'] ?? $b['registerNumber'] ?? '')
            )
        );

        return $this->filterStudentRows($rows, $query);
    }

    /**
     * PlaceHub students with admno that look like currently studying final-year.
     * When $ctx is department-scoped, only that department (and staff class batches if set).
     *
     * @param array<string, mixed>|null $ctx
     * @return array<int, array<string, mixed>>
     */
    private function listFinalYearFromStudentTable(?array $ctx = null): array
    {
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();

        $departments = [];
        foreach ($deptModel->findAll([], 400) as $d) {
            $departments[(string) $d['_id']] = $d;
        }

        $campusWide = $ctx === null
            || !empty($ctx['campusWide'])
            || (!empty($ctx['isAdmin']) && empty($ctx['staffScope']) && empty($ctx['departmentId']));
        $filter = $campusWide
            ? []
            : PlacementOfficerContext::studentCollectionFilter($ctx ?? []);

        $rows = [];
        foreach ($studentModel->findAll($filter, 10000) as $student) {
            if (!$campusWide && !empty($ctx['staffScope']) && !StaffContext::studentMatchesScope($student, $ctx)) {
                continue;
            }

            $admno = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            if ($admno === '' || !preg_match('/^[A-Z0-9]{4,20}$/', $admno)) {
                continue;
            }
            if (!$this->isLocalFinalYearStudyingStudent($student)) {
                continue;
            }

            $userId = (string) ($student['userId'] ?? '');
            $user = $userId !== '' ? $userModel->findById($userId) : null;
            if (is_array($user)) {
                $role = (string) ($user['role'] ?? '');
                if ($role !== '' && $role !== 'student') {
                    continue;
                }
                if (($user['status'] ?? '') === 'blocked') {
                    continue;
                }
            }

            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $departments[$deptId] ?? null;
            $row = DocumentHelper::serialize($student) ?? [];
            $row = $this->enrichStudentListRow($row, $student, $user, false);
            $row['admno'] = $admno;
            $row['registerNumber'] = $admno;
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : ($row['department'] ?? null);
            if (!$this->isPlacementStudentListCandidate($student, $user, $row, false)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $student
     */
    private function isLocalFinalYearStudyingStudent(array $student): bool
    {
        $batch = strtoupper(trim((string) ($student['classBatch'] ?? '')));
        $hint = strtoupper(trim(implode(' ', array_filter([
            $batch,
            (string) ($student['programme'] ?? ''),
            (string) ($student['branch'] ?? ''),
            (string) ($student['departmentCode'] ?? ''),
            is_array($student['department'] ?? null)
                ? (string) (($student['department']['code'] ?? '') . ' ' . ($student['department']['name'] ?? ''))
                : '',
            is_array($student['personal'] ?? null) ? (string) ($student['personal']['course'] ?? '') : '',
        ]))));
        if ($batch === '') {
            // Placement Cell students without a batch are usually final-year/outgoing.
            return true;
        }

        return $this->looksLikeFinalYearClassBatch($batch, $hint);
    }

    /**
     * Whether a stud_class / classBatch label represents a final-year outgoing cohort.
     */
    public function isFinalYearClassBatch(string $batch, string $programmeHint = ''): bool
    {
        return $this->looksLikeFinalYearClassBatch($batch, $programmeHint);
    }

    /**
     * Detect PG / MCA programmes even when labels are glued (MCA2025-27-S3, MCA Regular).
     */
    private function isPgPlacementProgramme(string $text): bool
    {
        $text = strtoupper(trim($text));
        if ($text === '') {
            return false;
        }

        return preg_match(
            '/(?:^|[^A-Z])(?:IN)?MCA(?:REG)?(?=\d|[^A-Z]|$)|(?:^|[^A-Z])(?:MBA|M\.?TECH|MTECH|MCAR|PG)(?=\d|[^A-Z]|$)/',
            $text
        ) === 1;
    }

    private function placementProgrammeCode(string $text): string
    {
        $normalized = DepartmentProgrammeCatalog::normalizeCode($text);
        if ($normalized === '') {
            return '';
        }

        // Prefer catalog resolve for exact programme/alias tokens.
        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($normalized);
        if (in_array($resolved, ['BCA', 'MCA', 'INMCA'], true)) {
            return $resolved;
        }

        // Longer Integrated-MCA markers before bare MCA (MCAINT contains MCA).
        foreach (['MCAINT', 'INMCA', 'INTMCA', 'IMCA', 'DDMCA'] as $code) {
            if (str_contains($normalized, $code)) {
                return 'INMCA';
            }
        }
        if (str_contains($normalized, 'BCA')) {
            return 'BCA';
        }
        if (str_contains($normalized, 'MCA')) {
            return 'MCA';
        }

        return '';
    }

    private function looksLikeFinalYearClassBatch(string $batch, string $programmeHint = ''): bool
    {
        $batch = strtoupper(trim($batch));
        $programmeHint = strtoupper(trim($programmeHint));
        if ($batch === '') {
            return false;
        }
        if (preg_match('/\b(FINAL|OUTGOING|PASS.?OUT|PLACEMENT)\b/', $batch) === 1) {
            return true;
        }

        $programmeCode = $this->placementProgrammeCode($batch . ' ' . $programmeHint);
        $isPg = $this->isPgPlacementProgramme($batch . ' ' . $programmeHint);
        $finalSemesterStart = match ($programmeCode) {
            'BCA' => 5,
            'MCA' => 3,
            'INMCA' => 9,
            default => $isPg ? 3 : 7,
        };

        if (preg_match('/(?:^|[^A-Z0-9])S(10|[1-9])(?:[^A-Z0-9]|$)/', $batch, $m) === 1
            || preg_match('/\bS(10|[1-9])\b/', $batch, $m) === 1) {
            $sem = (int) $m[1];
            return $sem >= $finalSemesterStart;
        }

        if (preg_match('/\b(SEM(?:ESTER)?[\s\-]*(10|[1-9]))\b/', $batch, $m) === 1) {
            $sem = (int) ($m[2] ?? 0);
            return $sem >= $finalSemesterStart;
        }

        // Year-range batches like MCA2025-27 / 2023-27 without semester.
        // Previous logic treated *any* year-range as final-year, which could surface older outgoing batches.
        // Tighten by checking the end-year against the current year window.
        if (preg_match('/20\d{2}\s*[-–]\s*(\d{2,4})/', $batch, $m) === 1) {
            $endRaw = trim((string) ($m[1] ?? ''));
            if ($endRaw !== '') {
                $endYear = strlen($endRaw) === 2 ? (2000 + (int) $endRaw) : (int) $endRaw;
                $nowYear = (int) date('Y');
                // Allow the "final year" window to include batches completing in current year,
                // and (for late academic cycles) the next year.
                if ($endYear >= $nowYear && $endYear <= ($nowYear + 1)) {
                    return true;
                }
            }
        }

        if ($isPg && preg_match('/REGULAR/', $batch . ' ' . $programmeHint) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $aesRow
     * @param array<string, mixed> $localRow
     * @return array<string, mixed>
     */
    private function mergeFinalYearListRows(array $aesRow, array $localRow): array
    {
        foreach (['displayName', 'classBatch', 'collegeEmail', 'personalEmail', 'phone', 'photoUrl', 'photo', 'academic', 'department', 'departmentCode', 'departmentName', 'year', 'semester'] as $field) {
            $aesVal = $aesRow[$field] ?? null;
            $localVal = $localRow[$field] ?? null;
            $aesEmpty = $aesVal === null || $aesVal === '' || $aesVal === [];
            if ($aesEmpty && $localVal !== null && $localVal !== '' && $localVal !== []) {
                $aesRow[$field] = $localVal;
            }
        }
        if (empty($aesRow['user']) && !empty($localRow['user'])) {
            $aesRow['user'] = $localRow['user'];
        }
        if (!empty($localRow['_id']) && empty($aesRow['aesOnly'])) {
            $aesRow['_id'] = $localRow['_id'];
            $aesRow['id'] = $localRow['id'] ?? $localRow['_id'];
            unset($aesRow['aesOnly'], $aesRow['isNew']);
        }
        if (!empty($localRow['policyAccepted'])) {
            $aesRow['policyAccepted'] = true;
            $aesRow['policyAcceptedAt'] = $localRow['policyAcceptedAt'] ?? ($aesRow['policyAcceptedAt'] ?? '');
        }

        return $aesRow;
    }

    /**
     * Staff/admin directory from AES getAllStudInfo4Placement (final-year admno rows),
     * merged with local PlaceHub student fields.
     *
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    private function listStudentsFromAesDirectory(array $ctx): array
    {
        $campusWide = !empty($ctx['campusWide']) || (
            !empty($ctx['isAdmin']) && empty($ctx['staffScope']) && empty($ctx['departmentId'])
        );
        $deptAesId = $campusWide ? '' : (new PlacementFilterService())->resolveParentDeptAesId($ctx);
        $records = $this->fetchAesDirectoryRecords($deptAesId, $campusWide);
        if ($records === []) {
            return [];
        }

        $localByKey = $this->indexLocalStudentsForAesMerge($ctx);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : null;
        $deptCode = strtoupper(trim((string) ($dept['code'] ?? '')));
        $deptName = trim((string) ($dept['name'] ?? ''));
        $staffBatches = !empty($ctx['staffScope']) ? StaffContext::assignedClassBatches($ctx) : [];
        // Class teachers / co-class teachers with no assignment see no AES directory students.
        if (!empty($ctx['staffScope']) && $staffBatches === []) {
            return [];
        }
        $rows = [];
        $seenAdmno = [];

        foreach ($records as $record) {
            if (!$this->isAesStudyingStudent($record)) {
                continue;
            }

            $recordDept = strtoupper(trim((string) (
                $record['stud_deptcode']
                ?? $record['parentDepartmentCode']
                ?? ''
            )));
            if ($deptAesId !== '' && $recordDept !== '' && $recordDept !== strtoupper($deptAesId)) {
                continue;
            }

            // Placement directory keys on AES student-table admno.
            $admno = strtoupper(trim((string) (
                $record['admno']
                ?? $record['stud_admno']
                ?? ''
            )));
            $regNo = strtoupper(trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? '')));
            if ($admno === '') {
                continue;
            }
            if (isset($seenAdmno[$admno])) {
                continue;
            }
            if (!$this->isAesFinalYearStudent($record)) {
                continue;
            }

            $local = null;
            foreach ([$admno, $regNo] as $key) {
                if ($key !== '' && isset($localByKey[$key])) {
                    $local = $localByKey[$key];
                    break;
                }
            }

            $row = $this->mapAesDirectoryRecordToListRow($record, $local, $dept, $deptCode, $deptName);
            if ($row === null) {
                continue;
            }
            if ($staffBatches !== [] && !StaffContext::classBatchMatchesAssigned(
                StaffContext::studentClassBatch($row),
                $staffBatches
            )) {
                continue;
            }
            if (!$this->isPlacementStudentListCandidate(
                is_array($local) ? $local : ['registerNumber' => $admno],
                null,
                $row,
                false
            )) {
                continue;
            }
            $seenAdmno[$admno] = true;
            $rows[] = $row;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp(
                (string) ($a['displayName'] ?? $a['registerNumber'] ?? ''),
                (string) ($b['displayName'] ?? $b['registerNumber'] ?? '')
            )
        );

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAesDirectoryRecords(string $deptAesId, bool $campusWide): array
    {
        $api = new AesApiService();
        if (!$campusWide) {
            $params = [];
            if ($deptAesId !== '') {
                $params['stud_deptcode'] = $deptAesId;
            }
            try {
                $records = $api->fetchAllStudInfo4Placement($params);
                if ($this->isComputerApplicationsDirectory($deptAesId, $records)) {
                    foreach (['BCA', 'INMCA', 'MCAINT', 'MCA'] as $course) {
                        try {
                            $records = $this->mergeAesDirectoryRecords(
                                $records,
                                $api->fetchAllStudInfo4Placement($params + ['stud_course' => $course])
                            );
                        } catch (\Throwable) {
                            // Keep already-fetched rows if a course-specific call fails.
                        }
                    }
                }

                return $records;
            } catch (\Throwable) {
                return [];
            }
        }

        $merged = [];
        $seen = [];
        $append = static function (array $records) use (&$merged, &$seen): void {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $key = strtoupper(trim((string) (
                    $record['admno']
                    ?? $record['stud_admno']
                    ?? $record['registerNumber']
                    ?? $record['registerno']
                    ?? ''
                )));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $record;
            }
        };

        try {
            $append($api->fetchAllStudInfo4Placement([]));
        } catch (\Throwable) {
            // Fall through to per-department fetch.
        }

        foreach ($this->campusParentDeptAesIds() as $aesId) {
            try {
                $departmentRecords = $api->fetchAllStudInfo4Placement(['stud_deptcode' => $aesId]);
                $append($departmentRecords);
                if ($this->isComputerApplicationsDirectory($aesId, $departmentRecords)) {
                    foreach (['BCA', 'INMCA', 'MCAINT', 'MCA'] as $course) {
                        try {
                            $append($api->fetchAllStudInfo4Placement([
                                'stud_deptcode' => $aesId,
                                'stud_course'   => $course,
                            ]));
                        } catch (\Throwable) {
                            continue;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function isComputerApplicationsDirectory(string $deptAesId, array $records): bool
    {
        // AES department 30 is Computer Applications. Record inspection keeps this
        // working if the numeric department mapping changes in another environment.
        if ($deptAesId === '30') {
            return true;
        }
        foreach ($records as $record) {
            $hint = trim(implode(' ', array_filter([
                (string) ($record['stud_course'] ?? ''),
                (string) ($record['stud_cource_short'] ?? ''),
                (string) ($record['stud_branch'] ?? ''),
                (string) ($record['programme'] ?? ''),
                (string) ($record['stud_class'] ?? ''),
            ])));
            if (in_array($this->placementProgrammeCode($hint), ['BCA', 'MCA', 'INMCA'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $second
     * @return list<array<string, mixed>>
     */
    private function mergeAesDirectoryRecords(array $first, array $second): array
    {
        $merged = [];
        $seen = [];
        foreach (array_merge($first, $second) as $record) {
            $key = strtoupper(trim((string) (
                $record['admno']
                ?? $record['stud_admno']
                ?? $record['registerNumber']
                ?? $record['registerno']
                ?? ''
            )));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $record;
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function campusParentDeptAesIds(): array
    {
        $ids = [];
        foreach ((new DepartmentModel())->findAll([], 400) as $dept) {
            foreach ([(string) ($dept['aesId'] ?? ''), (string) ($dept['code'] ?? '')] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && ctype_digit($candidate)) {
                    $ids[$candidate] = true;
                }
            }
        }

        try {
            foreach ((new AesApiService())->listDepartments() as $row) {
                foreach ([
                    (string) ($row['aesId'] ?? ''),
                    (string) ($row['code'] ?? ''),
                    (string) ($row['deptCode'] ?? ''),
                    (string) ($row['id'] ?? ''),
                ] as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '' && ctype_digit($candidate)) {
                        $ids[$candidate] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // Keep Mongo/local department AES ids.
        }

        $out = array_keys($ids);
        sort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * Add fallback rows for summary-only academic values when AES omits the
     * detailed qualification entries needed by the modal table.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function backfillAcademicQualificationRows(array $rows, ?float $cgpa, ?float $marks10th, ?float $marks12th): array
    {
        $has10th = false;
        $has12th = false;
        $hasCgpa = false;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = strtoupper(trim((string) ($row['qualification'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (preg_match('/\b(SSLC|SSC|10TH|10\s*STD|CLASS\s*X|SECONDARY)\b/', $label) === 1) {
                $has10th = true;
            }
            if (preg_match('/\b(HSC|PLUS\s*TWO|PLUS2|12TH|CLASS\s*XII|HIGHER\s*SECONDARY)\b/', $label) === 1) {
                $has12th = true;
            }
            if (str_contains($label, 'CGPA') || preg_match('/\bGPA\b/', $label) === 1) {
                $hasCgpa = true;
            }
        }

        if ($marks10th !== null && $marks10th > 0 && !$has10th) {
            $rows[] = [
                'qualification' => 'SSLC / 10th',
                'mark' => $marks10th,
                'percentage' => $marks10th,
            ];
        }
        if ($marks12th !== null && $marks12th > 0 && !$has12th) {
            $rows[] = [
                'qualification' => 'Plus Two / 12th',
                'mark' => $marks12th,
                'percentage' => $marks12th,
            ];
        }
        if ($cgpa !== null && $cgpa > 0 && !$hasCgpa) {
            $rows[] = [
                'qualification' => 'Current CGPA',
                'mark' => $cgpa,
                'maxMark' => 10,
            ];
        }

        return $rows;
    }

    /**
     * Drop alumni / left / transferred students so only currently studying rows remain.
     *
     * @param array<string, mixed> $record
     */
    private function isAesStudyingStudent(array $record): bool
    {
        $blob = strtolower(trim(implode(' ', array_filter([
            (string) ($record['stud_status'] ?? ''),
            (string) ($record['status'] ?? ''),
            (string) ($record['student_status'] ?? ''),
            (string) ($record['stud_type'] ?? ''),
            (string) ($record['category'] ?? ''),
            (string) ($record['role'] ?? ''),
        ]))));
        if ($blob === '') {
            return true;
        }

        return preg_match(
            '/\b(alumni|alumnus|passed\s*out|passout|graduated|left|dropout|discontinued|transferred|faculty|staff|employee)\b/',
            $blob
        ) !== 1;
    }

    /**
     * Prefer clearly final-year AES rows; keep records with no year/semester so
     * getAllStudInfo4Placement payloads that already omit juniors still appear.
     *
     * @param array<string, mixed> $record
     */
    private function isAesFinalYearStudent(array $record): bool
    {
        $yearRaw = strtolower(trim((string) (
            $record['stud_year']
            ?? $record['year']
            ?? $record['currentYear']
            ?? $record['stud_current_year']
            ?? ''
        )));
        $semesterRaw = strtolower(trim((string) (
            $record['stud_semester']
            ?? $record['semester']
            ?? $record['stud_sem']
            ?? ''
        )));
        $classBatch = trim((string) (
            $record['stud_class']
            ?? $record['classBatch']
            ?? ''
        ));
        $courseHint = trim(implode(' ', array_filter([
            (string) ($record['stud_course'] ?? ''),
            (string) ($record['stud_cource_short'] ?? ''),
            (string) ($record['stud_branch'] ?? ''),
            (string) ($record['branch'] ?? ''),
            (string) ($record['programme'] ?? ''),
            $classBatch,
        ])));
        $programmeCode = $this->placementProgrammeCode($courseHint);
        $isPg = $this->isPgPlacementProgramme($courseHint);

        if ($classBatch !== '' && $this->looksLikeFinalYearClassBatch($classBatch, $courseHint)) {
            return true;
        }

        if ($yearRaw === '' && $semesterRaw === '' && $classBatch === '') {
            return true;
        }

        if ($yearRaw !== '') {
            if (preg_match('/\b(final|outgoing|last|pass.?out)\b/', $yearRaw) === 1) {
                return true;
            }
            $yearNumber = 0;
            $yearWords = [
                1 => '/\b(1|i|1st|first)\b/',
                2 => '/\b(2|ii|2nd|second)\b/',
                3 => '/\b(3|iii|3rd|third)\b/',
                4 => '/\b(4|iv|4th|fourth)\b/',
                5 => '/\b(5|v|5th|fifth)\b/',
            ];
            foreach ($yearWords as $number => $pattern) {
                if (preg_match($pattern, $yearRaw) === 1) {
                    $yearNumber = $number;
                    break;
                }
            }
            $finalYear = match ($programmeCode) {
                'BCA' => 3,
                'MCA' => 2,
                'INMCA' => 5,
                default => $isPg ? 2 : 4,
            };
            if ($yearNumber > 0) {
                return $yearNumber >= $finalYear;
            }
        }

        if ($semesterRaw !== '') {
            if (preg_match('/(?:^|[^0-9])(10|[1-9])(?:[^0-9]|$)/', $semesterRaw, $m) === 1) {
                $semester = (int) $m[1];
                $finalSemesterStart = match ($programmeCode) {
                    'BCA' => 5,
                    'MCA' => 3,
                    'INMCA' => 9,
                    default => $isPg ? 3 : 7,
                };

                return $semester >= $finalSemesterStart;
            }
        }

        // Named class/batch that is clearly not final-year must not be kept
        // just because year/semester fields were empty or unparsed.
        if ($classBatch !== '') {
            return false;
        }

        return true;
    }

    /**
     * Local PlaceHub students for class-roster merge (admno / register keys).
     * Department-scoped only — do not require assigned teaching classes, so staff
     * placement edits still overlay AES blank shells after reload.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, array<string, mixed>>
     */
    private function indexLocalStudentsForClassRoster(array $ctx): array
    {
        $model = new StudentModel();
        $indexed = [];
        $scopeDeptId = trim((string) ($ctx['departmentId'] ?? ''));
        $seenIds = [];

        $candidates = [];
        if ($scopeDeptId !== '') {
            $candidates = array_merge(
                $candidates,
                $model->findAll(PlacementOfficerContext::studentCollectionFilter($ctx), 5000)
            );
        }
        // Staff-saved placements may lack / mismatch departmentId; still overlay AES shells.
        foreach ([
            ['placed' => true],
            ['source' => 'staff_registry'],
        ] as $extraFilter) {
            foreach ($model->findAll($extraFilter, 2000) as $student) {
                $candidates[] = $student;
            }
        }

        foreach ($candidates as $student) {
            $id = (string) ($student['_id'] ?? '');
            if ($id !== '' && isset($seenIds[$id])) {
                continue;
            }
            if ($id !== '') {
                $seenIds[$id] = true;
            }

            if ($scopeDeptId !== '') {
                $studentDeptId = trim((string) ($student['departmentId'] ?? ''));
                $hasPlacement = is_array($student['placement'] ?? null)
                    && trim((string) ($student['placement']['company'] ?? '')) !== '';
                $isStaffMaterialized = (string) ($student['source'] ?? '') === 'staff_registry'
                    || !empty($student['placed'])
                    || $hasPlacement;
                // Keep unmatched/empty departmentId rows and any staff-saved placements.
                if (
                    $studentDeptId !== ''
                    && $studentDeptId !== $scopeDeptId
                    && !$isStaffMaterialized
                ) {
                    continue;
                }
            }

            foreach (['registerNumber', 'admno'] as $field) {
                $key = strtoupper(trim((string) ($student[$field] ?? '')));
                if ($key !== '') {
                    $indexed[$key] = $student;
                }
            }
        }

        return $indexed;
    }

    /**
     * Direct lookup when the department-scoped index missed a staff-saved student.
     *
     * @return array<string, mixed>|null
     */
    private function findLocalStudentByAesKeys(string $admno, string $regNo): ?array
    {
        $model = new StudentModel();
        foreach ([$admno, $regNo] as $key) {
            $key = strtoupper(trim($key));
            if ($key === '') {
                continue;
            }
            $found = $model->findByRegisterNumber($key);
            if ($found) {
                return $found;
            }
            $byAdmno = $model->findOne(['admno' => $key]);
            if ($byAdmno) {
                return $byAdmno;
            }
        }

        return null;
    }

    /**
     * Local PlaceHub students for AES directory merge (admno / register keys).
     * For staffScope registry views, index the full department — not only assigned
     * teaching classes — so saved placement fields survive reload overlays.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, array<string, mixed>>
     */
    private function indexLocalStudentsForAesMerge(array $ctx): array
    {
        // Staff placement registry is department-wide; reuse the same index rules.
        if (!empty($ctx['staffScope'])) {
            return $this->indexLocalStudentsForClassRoster($ctx);
        }

        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $indexed = [];
        $campusWide = !empty($ctx['campusWide']) || (
            !empty($ctx['isAdmin']) && empty($ctx['departmentId'])
        );
        foreach ((new StudentModel())->findAll($filter, 5000) as $student) {
            if (!$campusWide && !empty($ctx['departmentId'])) {
                $studentDeptId = trim((string) ($student['departmentId'] ?? ''));
                $scopeDeptId = trim((string) ($ctx['departmentId'] ?? ''));
                if ($studentDeptId !== '' && $scopeDeptId !== '' && $studentDeptId !== $scopeDeptId) {
                    continue;
                }
            }
            foreach (['registerNumber', 'admno'] as $field) {
                $key = strtoupper(trim((string) ($student[$field] ?? '')));
                if ($key !== '') {
                    $indexed[$key] = $student;
                }
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $local
     * @param array<string, mixed>|null $dept
     * @return array<string, mixed>|null
     */
    private function mapAesDirectoryRecordToListRow(
        array $record,
        ?array $local,
        ?array $dept,
        string $deptCode,
        string $deptName
    ): ?array {
        $admno = strtoupper(trim((string) (
            $record['admno']
            ?? $record['stud_admno']
            ?? ''
        )));
        $regNo = strtoupper(trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? '')));
        if ($admno === '') {
            return null;
        }
        $register = $admno;

        // getStudInfo4Placement is authoritative for the student's full name.
        $name = trim((string) ($record['stud_name'] ?? $record['name'] ?? ''));
        $photoUrl = trim((string) ($record['photoUrl'] ?? $record['stud_photo'] ?? ''));
        $classBatch = trim((string) ($record['classBatch'] ?? $record['stud_class'] ?? ''));
        $year = trim((string) ($record['year'] ?? $record['stud_year'] ?? ''));
        $semester = trim((string) ($record['semester'] ?? $record['stud_semester'] ?? ''));
        $programme = trim((string) (
            $record['stud_course']
            ?? $record['stud_cource_short']
            ?? $record['branch']
            ?? $record['programme']
            ?? ''
        ));
        $classCode = DepartmentProgrammeCatalog::normalizeCode($classBatch);
        if (str_contains($classCode, 'MCAINT') || str_contains($classCode, 'INMCA')) {
            $programme = 'INMCA';
        } else {
            $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($programme);
        }
        $cgpa = isset($record['cgpa']) && is_numeric($record['cgpa']) ? (float) $record['cgpa'] : null;
        $backlogs = isset($record['backlogs'])
            ? (int) $record['backlogs']
            : (isset($record['backlog']) ? (int) $record['backlog'] : 0);

        $row = is_array($local) ? (DocumentHelper::serialize($local) ?? []) : [];
        $isAesOnly = $row === [];
        if ($isAesOnly) {
            $row = [
                '_id'            => $register,
                'id'             => $register,
                'registerNumber' => $register,
                'aesOnly'        => true,
                'isNew'          => true,
            ];
        } else {
            // extractRegistryRows / UI keys on `id`; MariaDB docs only expose `_id`.
            $localId = (string) ($row['id'] ?? $row['_id'] ?? '');
            if ($localId !== '') {
                $row['id'] = $localId;
                $row['_id'] = $localId;
            }
            unset($row['aesOnly'], $row['isNew']);
        }

        $row['registerNumber'] = $register;
        $row['admno'] = $admno;
        if ($regNo !== '') {
            $row['registerno'] = $regNo;
        }
        $row['displayName'] = $name !== '' ? $name : (string) ($row['displayName'] ?? $register);
        $row['classBatch'] = $classBatch !== '' ? $classBatch : (string) ($row['classBatch'] ?? '');
        $row['stud_class'] = $classBatch !== '' ? $classBatch : (string) ($row['stud_class'] ?? '');
        $row['stud_course'] = $programme !== '' ? $programme : (string) ($row['stud_course'] ?? '');
        $row['stud_branch'] = trim((string) ($record['stud_branch'] ?? $row['stud_branch'] ?? ''));
        $courseId = trim((string) (
            $record['courseId']
            ?? $record['course_id']
            ?? $record['stud_courseid']
            ?? $record['stud_course_id']
            ?? $record['stud_deptcode']
            ?? $record['deptCode']
            ?? $record['parentDepartmentCode']
            ?? $row['courseId']
            ?? $row['course_id']
            ?? ''
        ));
        $branchId = trim((string) (
            $record['branchId']
            ?? $record['branch_id']
            ?? $record['stud_branchid']
            ?? $record['stud_branch_id']
            ?? $row['branchId']
            ?? $row['branch_id']
            ?? $row['stud_branchid']
            ?? ''
        ));
        if ($courseId !== '') {
            $row['courseId'] = $courseId;
            $row['course_id'] = $courseId;
        }
        if ($branchId !== '') {
            $row['branchId'] = $branchId;
            $row['branch_id'] = $branchId;
            $row['stud_branchid'] = $branchId;
        }
        $deptAesId = trim((string) (
            $record['stud_deptcode']
            ?? $record['parentDepartmentCode']
            ?? $record['deptCode']
            ?? ''
        ));
        if ($deptAesId !== '' && ctype_digit($deptAesId)) {
            $row['stud_deptcode'] = $deptAesId;
            $row['parentDepartmentCode'] = $deptAesId;
            if ($courseId === '') {
                $row['courseId'] = $deptAesId;
                $row['course_id'] = $deptAesId;
            }
        }
        if ($year !== '') {
            $row['year'] = $year;
        }
        if ($semester !== '') {
            $row['semester'] = $semester;
        }
        $row['collegeEmail'] = trim((string) ($record['collegeEmail'] ?? $record['stud_ajce_mails'] ?? $row['collegeEmail'] ?? ''));
        $row['personalEmail'] = trim((string) ($record['personalEmail'] ?? $record['stud_personal_mails'] ?? $row['personalEmail'] ?? ''));
        $row['phone'] = trim((string) ($record['phone'] ?? $record['stud_mobiles'] ?? $row['phone'] ?? ''));
        if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $row['photoUrl'] = $photoUrl;
            $row['photo'] = ['url' => $photoUrl, 'source' => 'aes'];
        }
        if ($isAesOnly) {
            $row['aesOnly'] = true;
            $row['isNew'] = true;
        }

        $academic = is_array($row['academic'] ?? null) ? $row['academic'] : [];
        if ($cgpa !== null && $cgpa > 0) {
            $academic['cgpa'] = $cgpa;
        }
        $academic['backlogs'] = $backlogs;
        $row['academic'] = $academic;

        $recordDeptCode = strtoupper(trim((string) ($record['stud_deptcode'] ?? $record['parentDepartmentCode'] ?? '')));
        $aesBranchName = trim((string) (
            $record['stud_branch']
            ?? $record['branch_name']
            ?? $record['branchName']
            ?? ''
        ));
        $parentDeptName = trim((string) (
            $record['parentDepartmentName']
            ?? $record['dept_name']
            ?? $record['department_name']
            ?? ''
        ));
        // Prefer programme / course (MCA, INMCA, B.Tech, CS, …) like Branch under Department.
        $aesApi = new AesApiService();
        $programmeIsCourseLevel = $programme !== '' && $aesApi->isCourseLevelShort($programme);
        $courseLevelLabel = $programme !== '' ? $aesApi->courseLevelLabel($programme) : '';
        $catalogueProgrammeLabel = ($programme !== '' && !$programmeIsCourseLevel)
            ? DepartmentProgrammeCatalog::programmeLabel($programme)
            : '';
        $programmeDisplay = $courseLevelLabel !== ''
            ? $courseLevelLabel
            : ($catalogueProgrammeLabel !== ''
                ? $catalogueProgrammeLabel
                : (($programme !== '' && !ctype_digit($programme)) ? strtoupper($programme) : ''));

        $parentDeptShort = strtoupper(trim((string) (
            $record['parentDepartmentShort']
            ?? ''
        )));
        $displayDeptCode = $programme !== ''
            ? strtoupper($programme)
            : ($parentDeptShort !== ''
                ? $parentDeptShort
                : ($deptCode !== '' ? $deptCode : $recordDeptCode));
        $displayDeptName = $programmeDisplay !== ''
            ? $programmeDisplay
            : ($parentDeptName !== ''
                ? $parentDeptName
                : ($aesBranchName !== ''
                    ? $aesBranchName
                    : ($deptName !== '' && strcasecmp($deptName, $displayDeptCode) !== 0
                        ? $deptName
                        : ($deptName !== '' ? $deptName : $displayDeptCode))));
        $row['departmentCode'] = $displayDeptCode;
        $row['departmentName'] = $displayDeptName;
        $row['programme'] = $programme;
        $row['branchName'] = $aesBranchName;
        if ($parentDeptName !== '') {
            $row['parentDepartmentName'] = $parentDeptName;
        }
        $row['department'] = [
            'id'    => $dept ? (string) ($dept['_id'] ?? '') : '',
            'code'  => $displayDeptCode,
            'name'  => $displayDeptName,
            'aesId' => trim((string) ($record['stud_deptcode'] ?? ($dept['aesId'] ?? ''))),
        ];

        $user = null;
        if (is_array($local) && !empty($local['userId'])) {
            $user = (new UserModel())->findById((string) $local['userId']);
        }
        $row = $this->enrichStudentListRow($row, is_array($local) ? $local : $row, $user, false);
        if ($this->isUsablePersonName($name, $register)) {
            $row['displayName'] = $name;
            $userOut = is_array($row['user'] ?? null) ? $row['user'] : [];
            $userOut['name'] = $name;
            $row['user'] = $userOut;
        }

        return $row;
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
            $admno = strtoupper(trim((string) ($student['admno'] ?? '')));
            $regNo = strtoupper(trim((string) ($student['registerno'] ?? '')));
            $matches = $actual !== '' && $expected === $actual;
            $matches = $matches
                || ($admno !== '' && $expected === $admno)
                || ($regNo !== '' && $expected === $regNo)
                || ($actual !== '' && (str_ends_with($actual, $expected) || str_ends_with($expected, $actual)));
            // Final-year Open uses AES admission numbers while PlaceHub may store university regs.
            if (!$matches && ctype_digit($expected)) {
                $matches = true;
            }
            if (!$matches) {
                Response::notFound('Student register number does not match.');
            }
        }
        // Stamp officer dept onto AES-only synthetics so photo/pipeline scope stays consistent.
        if (!empty($student['aesOnly']) && empty($student['departmentId']) && !empty($ctx['departmentId'])) {
            $student['departmentId'] = (string) $ctx['departmentId'];
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
        if ($placement !== [] && $this->aesPlacementMatchesRegister($placement, $mapped, $register)) {
            $aesFullName = trim((string) ($placement['stud_name'] ?? $placement['name'] ?? ''));
            if ($this->isUsablePersonName($aesFullName, $register)) {
                $displayName = $aesFullName;
            }
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
        if (!empty($academic['qualifications']) && is_array($academic['qualifications'])) {
            $qualifications = $academic['qualifications'];
        }

        // Academic marks/CGPA/edu rows always come from getStudQual4Placement.
        $aesApi = new AesApiService();
        $qual = $this->fetchStudQual4PlacementProfile($aesApi, $placement, $register);
        if ($qual === []) {
            $qual = $aesApi->extractQualificationFromPlacement($placement);
        }
        if ($qual !== []) {
            if (!empty($qual['cgpa']) && (float) $qual['cgpa'] > 0) {
                $cgpa = (float) $qual['cgpa'];
            }
            if (!empty($qual['marks10th']) && (float) $qual['marks10th'] > 0) {
                $marks10 = (float) $qual['marks10th'];
            }
            if (!empty($qual['marks12th']) && (float) $qual['marks12th'] > 0) {
                $marks12 = (float) $qual['marks12th'];
            }
            if (isset($qual['backlogs'])) {
                $backlogs = (int) $qual['backlogs'];
            }
            if (!empty($qual['qualifications']) && is_array($qual['qualifications'])) {
                $qualifications = $this->realEducationQualificationRows($qual['qualifications']);
            }
            // Keep the profile education table when AES returns marks only (no edu rows).
            if ($qualifications === [] && !empty($academic['qualifications']) && is_array($academic['qualifications'])) {
                $qualifications = $this->realEducationQualificationRows($academic['qualifications']);
            }
        }
        $qualifications = $this->backfillAcademicQualificationRows(
            $qualifications,
            $cgpa > 0 ? $cgpa : null,
            $marks10 > 0 ? $marks10 : null,
            $marks12 > 0 ? $marks12 : null
        );

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
            'qualifications'  => $qualifications,
            'academic'        => array_merge($academic, $qualifications !== [] ? ['qualifications' => $qualifications] : []),
            'policyAccepted'  => (bool) ($student['policyAccepted'] ?? false),
            'policyAcceptedAt'=> (string) ($student['policyAcceptedAt'] ?? ''),
            'policyVersion'   => (string) ($student['policyVersion'] ?? ''),
        ];

        return $this->mergeAesPlacementIntoOverview($student, $user, $overview);
    }

    /**
     * Drop fabricated SSLC/HSC/CGPA rows (built from top-level marks) — keep real profile edu rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function realEducationQualificationRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $q) {
            if (!is_array($q)) {
                continue;
            }
            $label = strtoupper(trim((string) ($q['qualification'] ?? '')));
            $institution = trim((string) ($q['institution'] ?? $q['instname'] ?? ''));
            $monthYear = trim((string) ($q['monthYear'] ?? $q['monthyear'] ?? ''));
            $isSynthetic = $institution === ''
                && $monthYear === ''
                && (
                    $label === 'SSLC / 10TH'
                    || $label === 'HSC / 12TH'
                    || $label === 'CGPA'
                    || preg_match('/^SSLC\s*\/\s*10TH$/', $label) === 1
                    || preg_match('/^HSC\s*\/\s*12TH$/', $label) === 1
                );
            if ($isSynthetic) {
                continue;
            }
            $out[] = $q;
        }

        return $out;
    }

    /**
     * Always call AES getStudQual4Placement for marks / CGPA / education rows.
     *
     * @param array<string, mixed> $placement
     * @return array<string, mixed>
     */
    private function fetchStudQual4PlacementProfile(AesApiService $aesApi, array $placement, string $register): array
    {
        $qualAdmno = $aesApi->resolveQualificationAdmissionNumber($placement, $register);
        if ($qualAdmno === '' || !ctype_digit($qualAdmno)) {
            $qualAdmno = ctype_digit($register) ? $register : '';
        }
        if ($qualAdmno === '' || !ctype_digit($qualAdmno)) {
            return [];
        }

        try {
            $qualParams = ['admno' => $qualAdmno, 'stud_admno' => $qualAdmno];
            $regNo = trim((string) ($placement['registerno'] ?? $placement['registerNumber'] ?? $register));
            if ($regNo !== '' && $regNo !== $qualAdmno) {
                $qualParams['registerno'] = $regNo;
                $qualParams['registerNumber'] = $regNo;
            }

            $qual = $aesApi->fetchStudentQualificationProfile($qualParams);
            if (!is_array($qual) || $qual === []) {
                return [];
            }
            if (!empty($qual['qualifications']) && is_array($qual['qualifications'])) {
                $qual['qualifications'] = $this->realEducationQualificationRows($qual['qualifications']);
            }

            return $qual;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $overview
     * @param array<string, mixed> $qual
     * @return array<string, mixed>
     */
    private function applyQualProfileToOverview(array $overview, array $qual): array
    {
        if ($qual === []) {
            return $overview;
        }

        if (!empty($qual['cgpa']) && (float) $qual['cgpa'] > 0) {
            $overview['cgpa'] = (float) $qual['cgpa'];
        }
        if (!empty($qual['marks10th']) && (float) $qual['marks10th'] > 0) {
            $overview['marks10th'] = (float) $qual['marks10th'];
        }
        if (!empty($qual['marks12th']) && (float) $qual['marks12th'] > 0) {
            $overview['marks12th'] = (float) $qual['marks12th'];
            $overview['ugMarks'] = (float) $qual['marks12th'];
        }
        if (isset($qual['backlogs'])) {
            $overview['backlogs'] = (int) $qual['backlogs'];
        }
        $quals = is_array($qual['qualifications'] ?? null) ? $qual['qualifications'] : [];
        if ($quals !== []) {
            $overview['qualifications'] = $quals;
            $academic = is_array($overview['academic'] ?? null) ? $overview['academic'] : [];
            $academic['qualifications'] = $quals;
            if (!empty($qual['cgpa']) && (float) $qual['cgpa'] > 0) {
                $academic['cgpa'] = (float) $qual['cgpa'];
            }
            if (!empty($qual['marks10th']) && (float) $qual['marks10th'] > 0) {
                $academic['marks10th'] = (float) $qual['marks10th'];
            }
            if (!empty($qual['marks12th']) && (float) $qual['marks12th'] > 0) {
                $academic['marks12th'] = (float) $qual['marks12th'];
                $academic['ugMarks'] = (float) $qual['marks12th'];
            }
            $overview['academic'] = $academic;
        }

        return $overview;
    }

    /**
     * Education qualification profile for Open-profile modal (AES getStudQual4Placement).
     *
     * @param array<string, mixed> $ctx
     * @return array{cgpa:?float,marks10th:?float,marks12th:?float,ugMarks:?float,backlogs:?int,qualifications:list<array<string,mixed>>}
     */
    public function getEducationQualifications(string $studentRef, array $ctx, ?string $expectedRegister = null): array
    {
        $student = $this->resolveStudentRef($studentRef);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        if ($expectedRegister !== null && $expectedRegister !== '') {
            $expected = strtoupper(trim($expectedRegister));
            $actual = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            $admno = strtoupper(trim((string) ($student['admno'] ?? '')));
            $regNo = strtoupper(trim((string) ($student['registerno'] ?? '')));
            $matches = $actual !== '' && ($expected === $actual || str_ends_with($actual, $expected) || str_ends_with($expected, $actual));
            $matches = $matches
                || ($admno !== '' && $expected === $admno)
                || ($regNo !== '' && $expected === $regNo)
                || (ctype_digit($expected) && ctype_digit($actual) === false && $expected === $actual);
            // Opening from AES admno against a PlaceHub university register is allowed.
            if (!$matches && ctype_digit($expected)) {
                $matches = true;
            }
            if (!$matches) {
                Response::notFound('Student register number does not match.');
            }
        }
        if (!empty($student['aesOnly']) && empty($student['departmentId']) && !empty($ctx['departmentId'])) {
            $student['departmentId'] = (string) $ctx['departmentId'];
        }
        if (!$this->studentInScope($student, $ctx)) {
            Response::forbidden('Student is outside your department scope.');
        }

        $register = strtoupper(trim((string) (
            $student['registerNumber']
            ?? $student['admno']
            ?? $expectedRegister
            ?? $studentRef
        )));
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $storedQuals = $this->realEducationQualificationRows(
            (!empty($academic['qualifications']) && is_array($academic['qualifications']))
                ? array_values($academic['qualifications'])
                : []
        );

        $empty = [
            'cgpa' => null,
            'marks10th' => null,
            'marks12th' => null,
            'ugMarks' => null,
            'backlogs' => null,
            'qualifications' => $storedQuals,
        ];
        if ((float) ($academic['cgpa'] ?? 0) > 0) {
            $empty['cgpa'] = (float) $academic['cgpa'];
        }
        if ((float) ($academic['marks10th'] ?? 0) > 0) {
            $empty['marks10th'] = (float) $academic['marks10th'];
        }
        if ((float) ($academic['marks12th'] ?? $academic['ugMarks'] ?? 0) > 0) {
            $empty['marks12th'] = (float) ($academic['marks12th'] ?? $academic['ugMarks']);
            $empty['ugMarks'] = $empty['marks12th'];
        }
        if (isset($academic['backlogs'])) {
            $empty['backlogs'] = (int) $academic['backlogs'];
        }

        $aesApi = new AesApiService();
        $placementHint = [
            'admno' => $register,
            'stud_admno' => (string) ($student['admno'] ?? $student['stud_admno'] ?? $register),
            'registerNumber' => $register,
            'registerno' => (string) ($student['registerno'] ?? ''),
        ];
        foreach ([
            (string) ($expectedRegister ?? ''),
            (string) $studentRef,
            (string) ($student['admno'] ?? ''),
            (string) ($student['stud_admno'] ?? ''),
            $register,
        ] as $candidate) {
            $candidate = strtoupper(trim($candidate));
            if ($candidate !== '' && ctype_digit($candidate)) {
                $placementHint['admno'] = $candidate;
                $placementHint['stud_admno'] = $candidate;
                break;
            }
        }

        $qual = $this->fetchStudQual4PlacementProfile($aesApi, $placementHint, $register);
        if ($qual === []) {
            // Fall back to the same education table stored on the student profile.
            return $empty;
        }

        $rows = $this->realEducationQualificationRows(
            is_array($qual['qualifications'] ?? null) ? array_values($qual['qualifications']) : []
        );
        // Prefer the live AES edu table when present; otherwise keep profile table rows.
        $tableRows = $rows !== [] ? $rows : $storedQuals;
        $cgpa = (!empty($qual['cgpa']) && (float) $qual['cgpa'] > 0) ? (float) $qual['cgpa'] : $empty['cgpa'];
        $marks10th = (!empty($qual['marks10th']) && (float) $qual['marks10th'] > 0) ? (float) $qual['marks10th'] : $empty['marks10th'];
        $marks12th = (!empty($qual['marks12th']) && (float) $qual['marks12th'] > 0) ? (float) $qual['marks12th'] : $empty['marks12th'];
        $tableRows = $this->backfillAcademicQualificationRows($tableRows, $cgpa, $marks10th, $marks12th);

        return [
            'cgpa' => $cgpa,
            'marks10th' => $marks10th,
            'marks12th' => $marks12th,
            'ugMarks' => ($marks12th !== null && $marks12th > 0)
                ? $marks12th
                : $empty['ugMarks'],
            'backlogs' => isset($qual['backlogs']) ? (int) $qual['backlogs'] : $empty['backlogs'],
            'qualifications' => $tableRows,
        ];
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
        $aesApi = new AesApiService();

        if ($placement === []) {
            // Still fetch getStudQual4Placement by admission number when info placement is empty.
            $overview = $this->applyQualProfileToOverview(
                $overview,
                $this->fetchStudQual4PlacementProfile($aesApi, ['admno' => $register, 'stud_admno' => $register], $register)
            );

            return $overview;
        }

        $mapped = (new AesLoginService())->mapAesDetailsToUserFields($placement);
        $skipIdentityMerge = !$this->aesPlacementMatchesRegister($placement, $mapped, $register);

        if (!$skipIdentityMerge) {
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
        }

        // Prefer live getStudQual4Placement over any marks cached on the placement record.
        $qual = $this->fetchStudQual4PlacementProfile($aesApi, $placement, $register);
        if ($qual === []) {
            $qual = $aesApi->extractQualificationFromPlacement($placement);
        }
        $overview = $this->applyQualProfileToOverview($overview, $qual);

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
        if (!$this->aesPlacementMatchesRegister($placement, $mapped, $register)) {
            return ['placement' => [], 'mapped' => [], 'register' => $register];
        }

        return ['placement' => $placement, 'mapped' => $mapped, 'register' => $register];
    }

    /**
     * Match either the AES admission number or university register number.
     *
     * @param array<string, mixed> $placement
     * @param array<string, mixed> $mapped
     */
    private function aesPlacementMatchesRegister(array $placement, array $mapped, string $register): bool
    {
        $register = strtoupper(trim($register));
        if ($register === '') {
            return false;
        }

        foreach ([
            $placement['stud_admno'] ?? '',
            $placement['admno'] ?? '',
            $placement['registerno'] ?? '',
            $placement['registerNumber'] ?? '',
            $mapped['registerNumber'] ?? '',
            $mapped['admno'] ?? '',
        ] as $candidate) {
            $candidate = strtoupper(trim((string) $candidate));
            if (
                $candidate !== ''
                && (
                    $candidate === $register
                    || str_ends_with($candidate, $register)
                    || str_ends_with($register, $candidate)
                )
            ) {
                return true;
            }
        }

        return false;
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
    public function enrichStudentListRow(array $row, array $student, ?array $user, bool $liveAes = true): array
    {
        $displayName = $liveAes
            ? $this->studentDisplayName($student, $user)
            : $this->localStudentDisplayName($student, $user);

        $photoUrl = '';
        $photoMeta = null;
        if ($liveAes) {
            $photo = (new AesLoginService())->resolveProfilePhoto($student, is_array($user) ? $user : []);
            $photoUrl = (string) ($photo['photoUrl'] ?? '');
            $photoMeta = $photo['photo'] ?? null;

            if ($photoUrl === '') {
                $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
                if ($register !== '') {
                    $placement = $this->placementProfileForRegister($register);
                    $photoUrl = trim((string) ($placement['photoUrl'] ?? $placement['stud_photo'] ?? ''));
                    if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
                        $photoMeta = ['url' => $photoUrl, 'source' => 'aes'];
                    } else {
                        $photoUrl = '';
                    }
                }
            }
        } else {
            $stored = is_array($student['photo'] ?? null) ? $student['photo'] : null;
            $photoUrl = is_array($stored) ? trim((string) ($stored['url'] ?? '')) : '';
            if ($photoUrl === '' && is_array($user)) {
                $userPhoto = is_array($user['photo'] ?? null) ? $user['photo'] : null;
                $photoUrl = trim((string) ($user['photoUrl'] ?? (is_array($userPhoto) ? ($userPhoto['url'] ?? '') : '')));
                $stored = is_array($userPhoto) ? $userPhoto : $stored;
            }
            if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
                $photoMeta = is_array($stored) ? $stored : ['url' => $photoUrl, 'source' => 'stored'];
            } else {
                $photoUrl = '';
            }
        }

        $userOut = $user ? (DocumentHelper::serialize($user) ?? []) : [];
        if ($displayName !== '') {
            $userOut['name'] = $displayName;
        }
        if ($photoUrl !== '') {
            $userOut['photoUrl'] = $photoUrl;
            $userOut['photo'] = $photoMeta ?? ['url' => $photoUrl, 'source' => 'stored'];
        }

        $row['user'] = $userOut !== [] ? $userOut : null;
        $row['displayName'] = $displayName;
        if ($photoUrl !== '') {
            $row['photoUrl'] = $photoUrl;
            $row['photo'] = $photoMeta ?? ['url' => $photoUrl, 'source' => 'stored'];
        }

        if ($liveAes) {
            return $this->applyAesPlacementFieldsToRow($row, $student);
        }

        return $row;
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
        // AES directory / Open-profile synthetics have no PlaceHub departmentId.
        // Final-year lists are already scoped; allow when dept is unset or matches.
        if (!empty($student['aesOnly'])) {
            if (!empty($ctx['staffScope'])) {
                return StaffContext::studentMatchesScope($student, $ctx);
            }
            $scopeDept = (string) ($ctx['departmentId'] ?? '');
            if ($scopeDept === '') {
                return true;
            }
            $studentDept = (string) ($student['departmentId'] ?? '');

            return $studentDept === '' || $studentDept === $scopeDept;
        }
        if (!empty($ctx['staffScope'])) {
            return StaffContext::studentMatchesScope($student, $ctx);
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
    private function isPlacementStudentListCandidate(array $student, ?array $user, array $row, bool $liveAes = true): bool
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

        if ($liveAes) {
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
    private function localStudentDisplayName(array $student, ?array $user): string
    {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        $name = is_array($user) ? trim((string) ($user['name'] ?? '')) : '';
        if ($this->isUsablePersonName($name, $register)) {
            return $name;
        }

        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) ($personal['name'] ?? $personal['fullName'] ?? $student['displayName'] ?? ''));
        if ($this->isUsablePersonName($name, $register)) {
            return $name;
        }

        return '';
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

        return $this->localStudentDisplayName($student, $user);
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
        if ($fileName === '') {
            return false;
        }
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function streamStudentResume(string $studentId, array $ctx): void
    {
        PlacementOfficerContext::assertStudentInDepartment($studentId, $ctx);
        $this->streamResolvedStudentResume($studentId, false);
    }

    /** Stream student resume without department scope (signed public report links). */
    public function streamStudentResumeSigned(string $studentId): void
    {
        $this->streamResolvedStudentResume($studentId, true);
    }

    private function streamResolvedStudentResume(string $studentId, bool $forceDownload = false): void
    {
        $file = $this->resumeFileForApplication(['studentId' => $studentId, 'resume' => []]);
        if ($file === null) {
            Response::notFound('Resume file not found for this student.');
        }

        $storage = new ObjectStorageService();
        $filename = $this->resumeDownloadFilename((string) ($file['filename'] ?? 'resume.pdf'), $forceDownload);
        $mime = $forceDownload
            ? 'application/pdf'
            : $storage->guessMime($filename);
        try {
            $storage->streamWithFallback(
                $file['path'],
                $filename,
                $mime,
                !$forceDownload,
                ObjectStorageService::FOLDER_RESUMES
            );
        } catch (\Throwable) {
            Response::notFound('Resume file not found for this student.');
        }
    }

    private function resumeDownloadFilename(string $filename, bool $forcePdf): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'resume.pdf';
        }
        if ($forcePdf && !str_ends_with(strtolower($filename), '.pdf')) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $filename = ($base !== '' ? $base : 'resume') . '.pdf';
        }

        return $filename;
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
            $regDeadline = DriveLifecycle::registrationDeadline(is_array($drive) ? $drive : $row);
            $deadline = $regDeadline !== ''
                ? $regDeadline
                : trim((string) ($elig['deadline'] ?? $row['deadline'] ?? ''));
            $jobType = trim((string) ($elig['jobType'] ?? $row['jobType'] ?? ''));
            $mode = trim((string) ($elig['mode'] ?? $row['mode'] ?? ''));
            // Display-only fallback for empty deadline column (does not affect Closed derivation).
            if ($deadline === '' && !empty($row['date'])) {
                $deadline = (string) $row['date'];
            }
            $row['package'] = $package;
            $row['deadline'] = $deadline;
            $row['jobType'] = $jobType;
            $row['mode'] = $mode;
            $row['status'] = DriveLifecycle::effectiveStatus(is_array($drive) ? $drive : $row);
            $row['eligibility'] = array_merge($elig, [
                'package'  => $package,
                // Keep explicit registration deadline in eligibility for clients;
                // do not overwrite with recruitment-date fallback.
                'deadline' => $regDeadline !== '' ? $regDeadline : trim((string) ($elig['deadline'] ?? '')),
                'jobType'  => $jobType,
                'mode'     => $mode,
            ]);

            $hasShortlistDoc = trim((string) ($row['shortlistDocument'] ?? '')) !== ''
                || trim((string) ($row['shortlistDocumentName'] ?? '')) !== '';
            $row['hasShortlistDocument'] = $hasShortlistDoc;
            $row['shortlistDocumentName'] = (string) ($row['shortlistDocumentName'] ?? '');
            $row['shortlistUploadedAt'] = (string) ($row['shortlistUploadedAt'] ?? '');
            if ($hasShortlistDoc) {
                $driveId = (string) ($row['id'] ?? $row['_id'] ?? '');
                $row['shortlistDocumentUrl'] = $driveId !== ''
                    ? '/api/officer/drives/' . rawurlencode($driveId) . '/shortlist-document'
                    : '';
            } else {
                $row['shortlistDocumentUrl'] = '';
            }
            unset($row['shortlistDocument'], $row['shortlistUploadedBy']);

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
        // getExtendedAnalytics already includes getDashboardAnalytics — call once.
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
            'totalCompanies'      => $extended['totals']['companies'] ?? (new CompanyModel())->count([]),
            'totalStaff'          => $userModel->count(['role' => 'staff']),
            'totalAlumni'         => $userModel->count(['role' => 'alumni']),
            'salaryAnalytics'     => $extended['salaryAnalytics'],
            'branchStatistics'    => $extended['branchStatistics'],
            'companyStatistics'   => $extended['companyStatistics'],
            'hiringTrend'         => $extended['hiringTrend'],
            'hiringTrendLastYear' => $extended['hiringTrendLastYear'] ?? null,
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

        $upper = strtoupper($ref);
        $student = $studentModel->findByRegisterNumber($upper);
        if ($student) {
            return $student;
        }

        if (preg_match('/^\d{4,12}$/', $ref) === 1 || preg_match('/^[A-Z0-9]{5,20}$/', $upper) === 1) {
            return $this->syntheticStudentFromAes($upper);
        }

        return null;
    }

    /**
     * Build a minimal PlaceHub-shaped student when AES has the directory row but Mongo does not.
     *
     * @return array<string, mixed>|null
     */
    private function syntheticStudentFromAes(string $register): ?array
    {
        try {
            $placement = (new AesApiService())->fetchStudentPlacementProfile(['admno' => $register]);
        } catch (\Throwable) {
            return null;
        }
        if ($placement === []) {
            return null;
        }

        $admno = strtoupper(trim((string) (
            $placement['admno']
            ?? $placement['stud_admno']
            ?? $placement['registerNumber']
            ?? $register
        )));
        $name = trim((string) ($placement['stud_name'] ?? $placement['name'] ?? ''));
        $photoUrl = trim((string) ($placement['photoUrl'] ?? $placement['stud_photo'] ?? ''));
        $cgpa = isset($placement['cgpa']) && is_numeric($placement['cgpa']) ? (float) $placement['cgpa'] : 0.0;
        $backlogs = isset($placement['backlogs'])
            ? (int) $placement['backlogs']
            : (isset($placement['backlog']) ? (int) $placement['backlog'] : 0);

        return [
            '_id'            => $admno,
            'registerNumber' => $admno,
            'classBatch'     => trim((string) ($placement['classBatch'] ?? $placement['stud_class'] ?? '')),
            'aesOnly'        => true,
            'personal'       => [
                'phone'          => trim((string) ($placement['phone'] ?? $placement['stud_mobiles'] ?? '')),
                'personalEmail'  => trim((string) ($placement['personalEmail'] ?? $placement['stud_personal_mails'] ?? '')),
                'collegeEmail'   => trim((string) ($placement['collegeEmail'] ?? $placement['stud_ajce_mails'] ?? '')),
                'fullName'       => $name,
            ],
            'academic'       => [
                'cgpa'     => $cgpa,
                'backlogs' => $backlogs,
            ],
            'photo'          => ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL))
                ? ['url' => $photoUrl, 'source' => 'aes']
                : null,
            'placed'         => false,
            'policyAccepted' => false,
        ];
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
        if (!empty($student['aesOnly'])) {
            if (!$this->studentInScope($student, $ctx)) {
                Response::forbidden('Student is outside your department scope.');
            }
        } else {
            PlacementOfficerContext::assertStudentInDepartment((string) ($student['_id'] ?? ''), $ctx);
            if (!empty($ctx['staffScope'])) {
                StaffContext::assertStudentInScope($student, [
                    'departmentId' => $ctx['departmentId'] ?? '',
                    'profile'      => $ctx['profile'] ?? null,
                ]);
            }
        }

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
