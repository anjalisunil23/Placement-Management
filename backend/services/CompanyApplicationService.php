<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

final class CompanyApplicationService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(string $companyId, array $filters = []): array
    {
        $apps = (new ApplicationModel())->findByCompany($companyId);
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $wantedStatus = (string) $filters['status'];
            $apps = array_values(array_filter(
                $apps,
                fn (array $a): bool => $this->displayStatus($a) === $wantedStatus
                    || (string) ($a['status'] ?? '') === $wantedStatus
                    || $this->uiStatus((string) ($a['status'] ?? 'applied')) === $wantedStatus
            ));
        }
        if (!empty($filters['driveId'])) {
            $driveId = (string) $filters['driveId'];
            $apps = array_values(array_filter(
                $apps,
                static fn (array $a) => (string) ($a['driveId'] ?? '') === $driveId
            ));
        }

        $minCgpa = isset($filters['minCgpa']) ? (float) $filters['minCgpa'] : null;
        $branch = isset($filters['branch']) ? (string) $filters['branch'] : '';

        return $this->enrichApplicationRows($apps, $minCgpa, $branch, true);
    }

    /**
     * Bulk-enrich application rows (one batched load) — used by campus recruiting overview.
     *
     * @param array<int, array<string, mixed>> $apps
     * @return array<int, array<string, mixed>>
     */
    public function enrichApplicationRows(
        array $apps,
        ?float $minCgpa = null,
        string $branch = '',
        bool $includeResumeMeta = true
    ): array {
        if ($apps === []) {
            return [];
        }

        $studentIds = [];
        $driveIds = [];
        $jobIds = [];
        foreach ($apps as $app) {
            $sid = trim((string) ($app['studentId'] ?? ''));
            $did = trim((string) ($app['driveId'] ?? ''));
            $jid = trim((string) ($app['jobId'] ?? ''));
            if ($sid !== '') {
                $studentIds[$sid] = true;
            }
            if ($did !== '') {
                $driveIds[$did] = true;
            }
            if ($jid !== '') {
                $jobIds[$jid] = true;
            }
        }

        $students = (new StudentModel())->findByIds(array_keys($studentIds));
        $userIds = [];
        $deptIds = [];
        foreach ($students as $student) {
            $uid = trim((string) ($student['userId'] ?? ''));
            $did = trim((string) ($student['departmentId'] ?? ''));
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
            if ($did !== '') {
                $deptIds[$did] = true;
            }
        }
        $users = (new UserModel())->findByIds(array_keys($userIds));
        $drives = (new DriveModel())->findByIds(array_keys($driveIds));
        $jobs = (new JobModel())->findByIds(array_keys($jobIds));
        $departments = (new DepartmentModel())->findByIds(array_keys($deptIds));
        $deptCache = [];
        foreach ($departments as $id => $dept) {
            $deptCache[$id] = (string) ($dept['code'] ?? $dept['name'] ?? '');
        }

        $rows = [];
        foreach ($apps as $app) {
            $studentId = trim((string) ($app['studentId'] ?? ''));
            $student = $studentId !== '' ? ($students[$studentId] ?? null) : null;
            if (!$student) {
                continue;
            }
            $cgpa = (float) ($student['academic']['cgpa'] ?? 0);
            if ($minCgpa !== null && $cgpa < $minCgpa) {
                continue;
            }
            $deptCode = $this->departmentCodeFromCache($student, $deptCache);
            if ($branch !== '' && strcasecmp($deptCode, $branch) !== 0) {
                continue;
            }

            $uid = trim((string) ($student['userId'] ?? ''));
            $did = trim((string) ($app['driveId'] ?? ''));
            $jid = trim((string) ($app['jobId'] ?? ''));
            $user = $uid !== '' ? ($users[$uid] ?? null) : null;
            $drive = $did !== '' ? ($drives[$did] ?? null) : null;
            $job = $jid !== '' ? ($jobs[$jid] ?? null) : null;

            $rows[] = $this->serializeRow(
                $app,
                $student,
                $user,
                $drive,
                $job,
                $deptCache,
                $includeResumeMeta
            );
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(string $companyId): array
    {
        $apps = (new ApplicationModel())->findByCompany($companyId);
        $counts = [
            'total'        => count($apps),
            'applied'      => 0,
            'under_review' => 0,
            'shortlisted'  => 0,
            'interview'    => 0,
            'offered'      => 0,
            'rejected'     => 0,
        ];
        foreach ($apps as $app) {
            $bucket = $this->displayStatus($app);
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
        }
        return $counts;
    }

    public function uiStatus(string $status): string
    {
        return match ($status) {
            'applied', 'resume_pending', 'resume_verified' => 'applied',
            'officer_approved', 'company_review', 'waiting' => 'under_review',
            'shortlisted' => 'shortlisted',
            'selected' => 'offered',
            'rejected', 'withdrawn' => 'rejected',
            default => 'applied',
        };
    }

    /**
     * Company applicants display status (local companyViewStatus wins when set).
     *
     * @param array<string, mixed> $app
     */
    public function displayStatus(array $app): string
    {
        $companyView = strtolower(trim((string) ($app['companyViewStatus'] ?? '')));
        if ($companyView !== '') {
            return $this->uiStatus($companyView);
        }
        return $this->uiStatus((string) ($app['status'] ?? 'applied'));
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, string> $deptCache
     */
    private function departmentCode(array $student, DepartmentModel $deptModel, array &$deptCache): string
    {
        $deptId = (string) ($student['departmentId'] ?? '');
        if ($deptId === '') {
            return '';
        }
        if (!isset($deptCache[$deptId])) {
            $dept = $deptModel->findById($deptId);
            $deptCache[$deptId] = $dept ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '';
        }
        return $deptCache[$deptId];
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, string> $deptCache
     */
    private function departmentCodeFromCache(array $student, array $deptCache): string
    {
        $deptId = (string) ($student['departmentId'] ?? '');

        return $deptId !== '' ? (string) ($deptCache[$deptId] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed>|null $student
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $drive
     * @param array<string, mixed>|null $job
     * @param array<string, string> $deptCache
     * @return array<string, mixed>
     */
    private function serializeRow(
        array $app,
        ?array $student,
        ?array $user,
        ?array $drive,
        ?array $job,
        array $deptCache,
        bool $includeResumeMeta = true
    ): array {
        $serialized = DocumentHelper::serialize($app);
        $dept = $student ? $this->departmentCodeFromCache($student, $deptCache) : '';
        $appId = (string) ($serialized['id'] ?? $serialized['_id'] ?? $app['_id'] ?? '');

        $hasResume = false;
        if ($includeResumeMeta) {
            $hasResume = $this->hasLocalResumeSnapshot($app, $student);
        } else {
            $appResume = is_array($app['resume'] ?? null) ? $app['resume'] : [];
            $studentResume = is_array($student['resume'] ?? null) ? $student['resume'] : [];
            $hasResume = trim((string) ($appResume['path'] ?? '')) !== ''
                || trim((string) ($studentResume['path'] ?? '')) !== '';
        }

        $serialized['student'] = [
            'name'           => $user['name'] ?? 'Student',
            'registerNumber' => $student['registerNumber'] ?? '',
            'department'     => $dept,
            'classBatch'     => trim((string) ($student['classBatch'] ?? '')),
            'cgpa'           => (float) ($student['academic']['cgpa'] ?? 0),
            'backlogs'       => (int) ($student['academic']['backlogs'] ?? 0),
            'hasResume'      => $hasResume,
        ];
        $serialized['drive'] = $drive ? [
            'title'   => $drive['title'] ?? '',
            'package' => $drive['tier'] ?? '',
            'status'  => $drive['status'] ?? '',
        ] : null;
        $serialized['job'] = $job ? [
            'title'   => $job['title'] ?? '',
            'package' => $job['package'] ?? '',
            'location'=> $job['location'] ?? '',
        ] : null;
        $serialized['uiStatus'] = $this->displayStatus($app);
        $serialized['companyViewStatus'] = strtolower(trim((string) ($app['companyViewStatus'] ?? '')));
        $serialized['resumeUrl'] = ($hasResume && $appId !== '')
            ? '/backend/api/company/applications/' . rawurlencode($appId) . '/resume'
            : '';
        return $serialized;
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed>|null $student
     */
    private function hasLocalResumeSnapshot(array $app, ?array $student): bool
    {
        foreach ([
            is_array($app['resume'] ?? null) ? $app['resume'] : null,
            is_array($student['resume'] ?? null) ? $student['resume'] : null,
        ] as $src) {
            if (!is_array($src)) {
                continue;
            }
            if (trim((string) ($src['path'] ?? '')) !== '' || trim((string) ($src['storedName'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Bulk-update application results from a CSV upload.
     *
     * Expected columns (case-insensitive): register_number, status, remarks
     *
     * @return array{updated:int, failed:int, errors:array<int,string>}
     */
    public function uploadResultsCsv(string $companyId, string $byUserId, string $csvContent): array
    {
        $rows = $this->parseCsvRows($csvContent);
        if ($rows === []) {
            return ['updated' => 0, 'failed' => 0, 'errors' => ['CSV file is empty or could not be parsed.']];
        }

        $apps = (new ApplicationModel())->findByCompany($companyId);
        $appsByStudent = [];
        foreach ($apps as $app) {
            $sid = (string) ($app['studentId'] ?? '');
            if ($sid !== '') {
                $appsByStudent[$sid][] = $app;
            }
        }

        $studentModel = new StudentModel();
        $workflow = new ApplicationWorkflowService();
        $notifier = new NotificationService();
        $placement = new PlacementChanceService();
        $company = (new \PMS\Models\CompanyModel())->findById($companyId);
        $companyName = (string) ($company['companyName'] ?? 'Company');

        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $roll = $this->csvField($row, ['register_number', 'registernumber', 'roll', 'roll_no', 'register', 'register no']);
            $statusRaw = $this->csvField($row, ['status', 'result', 'outcome', 'selection']);
            $remarks = $this->csvField($row, ['remarks', 'comment', 'notes', 'remark']);

            if ($roll === '') {
                $errors[] = "Row {$line}: register number is required.";
                $failed++;
                continue;
            }

            $status = $this->normalizeUploadStatus($statusRaw);
            if ($status === null) {
                $errors[] = "Row {$line}: invalid status \"{$statusRaw}\".";
                $failed++;
                continue;
            }

            $student = $studentModel->findByRegisterNumber($roll);
            if (!$student) {
                $errors[] = "Row {$line}: student \"{$roll}\" not found.";
                $failed++;
                continue;
            }

            $studentApps = $appsByStudent[(string) $student['_id']] ?? [];
            if ($studentApps === []) {
                $errors[] = "Row {$line}: no application found for \"{$roll}\" at your company.";
                $failed++;
                continue;
            }

            $rowUpdated = false;
            foreach ($studentApps as $app) {
                $appId = (string) ($app['_id'] ?? '');
                if ($appId === '') {
                    continue;
                }
                if (!$workflow->transition($appId, $status, $byUserId, $remarks, false)) {
                    continue;
                }
                $rowUpdated = true;
                $userId = (string) ($student['userId'] ?? '');
                if ($userId !== '') {
                    if ($status === 'shortlisted') {
                        $notifier->notifyApplicationUpdate(
                            $userId,
                            'Shortlisted',
                            'Congratulations! You have been shortlisted by ' . $companyName . '.'
                        );
                    } else {
                        $notifier->notifySelectionUpdate($userId, $companyName, $status);
                    }
                }
                if ($status === 'selected') {
                    $placement->consumeOnSelection(
                        (string) $student['_id'],
                        (string) ($app['driveId'] ?? ''),
                        [
                            'companyId'     => (string) ($app['companyId'] ?? ''),
                            'driveId'       => (string) ($app['driveId'] ?? ''),
                            'applicationId' => $appId,
                        ]
                    );
                }
            }

            if ($rowUpdated) {
                $updated++;
            } else {
                $errors[] = "Row {$line}: could not update \"{$roll}\" (status transition not allowed).";
                $failed++;
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsvRows(string $csvContent): array
    {
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if ($lines === []) {
            return [];
        }

        $headerLine = array_shift($lines);
        if ($headerLine === null || trim($headerLine) === '') {
            return [];
        }

        $headers = str_getcsv($headerLine);
        $headers = array_map(static fn ($h) => strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) $h) ?? '')), $headers);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = trim((string) ($cells[$i] ?? ''));
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string> $row
     * @param string[] $keys
     */
    private function csvField(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $normalized = strtolower(preg_replace('/[\s\-]+/', '_', $key) ?? $key);
            if (!empty($row[$normalized])) {
                return trim($row[$normalized]);
            }
        }
        return '';
    }

    private function normalizeUploadStatus(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return 'selected';
        }

        return match (true) {
            in_array($value, ['selected', 'select', 'offered', 'offer', 'hired', 'joined', 'yes', 'y'], true) => 'selected',
            in_array($value, ['rejected', 'reject', 'not_selected', 'not selected', 'no', 'n'], true) => 'rejected',
            in_array($value, ['shortlisted', 'shortlist'], true) => 'shortlisted',
            default => null,
        };
    }
}
