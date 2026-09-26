<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\CertificationModel;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentCertificationModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Campus certification opportunities and one completion row per student.
 */
final class CertificationService
{
    private const TZ = 'Asia/Kolkata';

    public function __construct(
        private ?CertificationModel $certifications = null,
        private ?StudentCertificationModel $progress = null,
        private ?StudentModel $students = null,
        private ?UserModel $users = null,
        private ?ObjectStorageService $storage = null,
    ) {
        $this->certifications = $certifications ?? new CertificationModel();
        $this->progress = $progress ?? new StudentCertificationModel();
        $this->students = $students ?? new StudentModel();
        $this->users = $users ?? new UserModel();
        $this->storage = $storage ?? new ObjectStorageService();
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canManage(array $user): bool
    {
        $role = AuthMiddleware::resolvedRole($user);

        return in_array($role, ['admin', 'placement_officer'], true);
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    /**
     * Stored status is pending or completed. Available and overdue are derived from the due date.
     *
     * @param array<string, mixed> $certification
     * @param array<string, mixed>|null $progress
     */
    public static function displayStatus(array $certification, ?array $progress): string
    {
        if (is_array($progress) && (string) ($progress['status'] ?? '') === 'completed' && self::hasProof($progress)) {
            return 'completed';
        }

        return 'available';
    }

    public static function isPastDue(array $certification): bool
    {
        $due = (string) ($certification['dueDate'] ?? '');

        return $due !== '' && $due < self::today();
    }

    /**
     * @param array<string, mixed> $progress
     */
    public static function hasProof(array $progress): bool
    {
        return trim((string) ($progress['proofPath'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public static function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? $input['certification_name'] ?? ''));
        $url = trim((string) ($input['url'] ?? $input['certification_url'] ?? ''));
        $due = trim((string) ($input['dueDate'] ?? $input['due_date'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $visibility = self::normalizeVisibility((string) ($input['visibility'] ?? $input['visibilityScope'] ?? ''));
        $departmentIds = self::normalizeIdList($input['departmentIds'] ?? $input['department_ids'] ?? []);

        if ($name === '' || strlen($name) < 2 || strlen($name) > 160) {
            return ['ok' => false, 'message' => 'Certification name must be between 2 and 160 characters.', 'data' => []];
        }
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'message' => 'Certification URL must be a valid http or https URL.', 'data' => []];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $due, new \DateTimeZone(self::TZ));
        if (!$date || $date->format('Y-m-d') !== $due) {
            return ['ok' => false, 'message' => 'Due date must be a valid date in YYYY-MM-DD format.', 'data' => []];
        }
        if ($due < self::today()) {
            return ['ok' => false, 'message' => 'Due date cannot be in the past.', 'data' => []];
        }
        if (strlen($description) > 2000) {
            return ['ok' => false, 'message' => 'Description must be 2000 characters or fewer.', 'data' => []];
        }
        if ($visibility === '') {
            return ['ok' => false, 'message' => 'Choose all departments or selected departments.', 'data' => []];
        }
        if ($visibility === 'departments' && $departmentIds === []) {
            return ['ok' => false, 'message' => 'Select at least one department.', 'data' => []];
        }
        if ($visibility === 'all') {
            $departmentIds = [];
        } else {
            $known = self::existingDepartmentIds();
            foreach ($departmentIds as $departmentId) {
                if (!isset($known[$departmentId])) {
                    return ['ok' => false, 'message' => 'One or more departments were not found.', 'data' => []];
                }
            }
        }

        return [
            'ok' => true,
            'message' => '',
            'data' => [
                'name' => $name,
                'url' => $url,
                'dueDate' => $due,
                'description' => $description,
                'visibility' => $visibility,
                'departmentIds' => $departmentIds,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array{id:string,code:string,name:string}|null $lockedDepartment
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public static function validateForManager(array $input, ?array $lockedDepartment): array
    {
        if ($lockedDepartment !== null) {
            $input['visibility'] = 'departments';
            $input['departmentIds'] = [$lockedDepartment['id']];
        }

        return self::validate($input);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $user, array $input): array
    {
        $this->assertManager($user);
        $locked = $this->lockedDepartment($user);
        $valid = self::validateForManager($input, $locked);
        if (!$valid['ok']) {
            throw new \InvalidArgumentException($valid['message']);
        }
        $doc = $valid['data'];
        $doc['createdBy'] = (string) ($user['_id'] ?? '');
        $id = $this->certifications->insert($doc);

        return $this->publicCertification($this->requireCertification($id), null, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listFor(array $user): array
    {
        $role = AuthMiddleware::resolvedRole($user);
        $rows = $this->certifications->findAll([], 500, 0, ['dueDate' => 1, 'createdAt' => -1]);
        if ($role === 'student') {
            $student = $this->requireStudent($user);
            $departmentId = (string) ($student['departmentId'] ?? '');
            $mine = $this->indexProgress($this->progress->findByStudent((string) $student['_id']));
            $out = [];
            foreach ($rows as $row) {
                if (!$this->visibleToDepartment($row, $departmentId)) {
                    continue;
                }
                $out[] = $this->publicCertification($row, $mine[(string) ($row['_id'] ?? '')] ?? null, false);
            }

            return $out;
        }
        $locked = $this->lockedDepartment($user);
        $out = [];
        foreach ($rows as $row) {
            if (!$this->officerCanView($row, $locked)) {
                continue;
            }
            $out[] = $this->publicCertification($row, null, true);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function show(array $user, string $id): array
    {
        $row = $this->requireCertification($id);
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'student') {
            $student = $this->requireStudent($user);
            $this->assertVisibleToStudent($row, $student);
            $progress = $this->progress->findByPair((string) $student['_id'], $id);

            return $this->publicCertification($row, $progress, false);
        }
        $this->assertOfficerCanView($user, $row);

        return $this->publicCertification($row, null, true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $user, string $id, array $input): array
    {
        $this->assertManager($user);
        $existing = $this->requireCertification($id);
        $this->assertOfficerCanEdit($user, $existing);
        $locked = $this->lockedDepartment($user);
        $merged = [
            'name' => $input['name'] ?? $input['certification_name'] ?? ($existing['name'] ?? ''),
            'url' => $input['url'] ?? $input['certification_url'] ?? ($existing['url'] ?? ''),
            'dueDate' => $input['dueDate'] ?? $input['due_date'] ?? ($existing['dueDate'] ?? ''),
            'description' => $input['description'] ?? ($existing['description'] ?? ''),
            'visibility' => $input['visibility'] ?? $input['visibilityScope'] ?? ($existing['visibility'] ?? ''),
            'departmentIds' => $input['departmentIds'] ?? $input['department_ids'] ?? ($existing['departmentIds'] ?? []),
        ];
        $valid = self::validateForManager($merged, $locked);
        if (!$valid['ok']) {
            throw new \InvalidArgumentException($valid['message']);
        }
        $this->certifications->update($id, $valid['data']);

        return $this->publicCertification($this->requireCertification($id), null, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function delete(array $user, string $id): void
    {
        $this->assertManager($user);
        $existing = $this->requireCertification($id);
        $this->assertOfficerCanEdit($user, $existing);
        foreach ($this->progress->findByCertification($id) as $row) {
            $this->removeProofFile($row);
            $progressId = (string) ($row['_id'] ?? '');
            if ($progressId !== '') {
                $this->progress->delete($progressId);
            }
        }
        $this->certifications->delete($id);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function submitProof(array $user, string $certificationId, array $file): array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'student') {
            throw new \RuntimeException('Only students can submit certification proof.', 403);
        }
        $certification = $this->requireCertification($certificationId);
        $student = $this->requireStudent($user);
        $this->assertVisibleToStudent($certification, $student);
        $studentId = (string) $student['_id'];
        $this->assertProofFile($file);

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $filename = 'cert_' . $studentId . '_' . $certificationId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $this->storage->putUploadedFile(ObjectStorageService::FOLDER_CERTIFICATION_PROOFS, $filename, $file);
        $now = DocumentHelper::now();
        $existing = $this->progress->findByPair($studentId, $certificationId);
        $doc = [
            'studentId' => $studentId,
            'certificationId' => $certificationId,
            'pairKey' => StudentCertificationModel::pairKey($studentId, $certificationId),
            'status' => 'completed',
            'proofPath' => $path,
            'proofFileName' => basename((string) ($file['name'] ?? $filename)),
            'completedAt' => $now,
        ];
        if (is_array($existing)) {
            $this->removeProofFile($existing);
            $this->progress->update((string) $existing['_id'], $doc);
        } else {
            try {
                $this->progress->insert($doc);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $existing = $this->progress->findByPair($studentId, $certificationId);
                if (!is_array($existing)) {
                    throw new \RuntimeException('This certification is already recorded for you.', 409);
                }
                $this->removeProofFile($existing);
                $this->progress->update((string) $existing['_id'], $doc);
            }
        }

        return $this->publicCertification($certification, $this->progress->findByPair($studentId, $certificationId), false);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function proofFor(array $user, string $certificationId, ?string $studentId): array
    {
        $row = $this->requireCertification($certificationId);
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'student') {
            $student = $this->requireStudent($user);
            $this->assertVisibleToStudent($row, $student);
            $ownId = (string) $student['_id'];
            if ($studentId !== null && $studentId !== '' && $studentId !== $ownId) {
                throw new \RuntimeException('You can only access your own certification proof.', 403);
            }
            $studentId = $ownId;
        } elseif (!self::canManage($user)) {
            throw new \RuntimeException('You do not have permission to access this proof.', 403);
        } elseif ($studentId === null || $studentId === '') {
            throw new \InvalidArgumentException('Student id is required.');
        } else {
            $this->assertOfficerCanView($user, $row);
        }

        $progress = $this->progress->findByPair($studentId, $certificationId);
        if (!is_array($progress) || !self::hasProof($progress)) {
            throw new \RuntimeException('Certification proof was not found.', 404);
        }

        return $progress;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function completions(array $user, string $certificationId): array
    {
        $this->assertManager($user);
        $row = $this->requireCertification($certificationId);
        $this->assertOfficerCanView($user, $row);
        $out = [];
        foreach ($this->progress->findByCertification($certificationId) as $row) {
            $student = $this->students->findById((string) ($row['studentId'] ?? ''));
            $out[] = [
                'studentName' => $this->displayName(is_array($student) ? $student : []),
                'status' => (string) ($row['status'] ?? 'pending'),
                'hasProof' => self::hasProof($row),
                'completedAt' => $row['completedAt'] ?? null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['studentName'], $b['studentName']));

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function setCompletionStatus(array $user, string $certificationId, string $studentId, string $status): void
    {
        $this->assertManager($user);
        $row = $this->requireCertification($certificationId);
        $this->assertOfficerCanEdit($user, $row);
        $status = strtolower(trim($status));
        $progress = $this->progress->findByPair($studentId, $certificationId);
        if ($status === 'pending') {
            if (!is_array($progress)) {
                return;
            }
            $this->removeProofFile($progress);
            $this->progress->update((string) $progress['_id'], [
                'status' => 'pending',
                'proofPath' => '',
                'proofFileName' => '',
                'completedAt' => null,
            ]);

            return;
        }
        if ($status === 'completed') {
            throw new \InvalidArgumentException('Completion requires an uploaded certificate. Status cannot be set directly.');
        }
        throw new \InvalidArgumentException('Status must be pending.');
    }

    /**
     * @return array<int, array{rank: int, name: string, completed: int}>
     */
    public function leaderboard(): array
    {
        $counts = [];
        foreach ($this->progress->findAll(['status' => 'completed'], 5000) as $row) {
            if (!self::hasProof($row)) {
                continue;
            }
            $studentId = (string) ($row['studentId'] ?? '');
            if ($studentId === '') {
                continue;
            }
            $counts[$studentId] = ($counts[$studentId] ?? 0) + 1;
        }
        $rows = [];
        foreach ($counts as $studentId => $count) {
            $student = $this->students->findById($studentId);
            $rows[] = [
                'name' => $this->displayName(is_array($student) ? $student : []),
                'completed' => $count,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $byCount = $b['completed'] <=> $a['completed'];
            return $byCount !== 0 ? $byCount : strcasecmp($a['name'], $b['name']);
        });
        $ranked = [];
        foreach ($rows as $index => $row) {
            $ranked[] = [
                'rank' => $index + 1,
                'name' => $row['name'],
                'completed' => $row['completed'],
            ];
        }

        return $ranked;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertManager(array $user): void
    {
        if (!self::canManage($user)) {
            throw new \RuntimeException('You do not have permission to manage certifications.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireStudent(array $user): array
    {
        $student = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if (!is_array($student) || trim((string) ($student['_id'] ?? '')) === '') {
            throw new \RuntimeException('Student profile not found.', 404);
        }

        return $student;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireCertification(string $id): array
    {
        $row = $this->certifications->findById($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Certification not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function assertProofFile(array $file): void
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $max = (int) ($config['uploads']['max_certificate'] ?? 5242880);
        $error = Security::validateUploadedFile($file, $max, ['pdf', 'jpg', 'jpeg', 'png']);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function removeProofFile(array $progress): void
    {
        $path = trim((string) ($progress['proofPath'] ?? ''));
        if ($path === '') {
            return;
        }
        try {
            $this->storage->delete($path);
        } catch (\Throwable) {
            // Replacing or deleting a record should not fail if the old file is already gone.
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexProgress(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) ($row['certificationId'] ?? '')] = $row;
        }

        return $indexed;
    }

    public static function normalizeVisibility(string $value): string
    {
        $raw = strtoupper(str_replace([' ', '-'], '_', trim($value)));
        if (in_array($raw, ['ALL', 'ALL_DEPARTMENTS'], true)) {
            return 'all';
        }
        if (in_array($raw, ['DEPARTMENTS', 'SELECTED', 'SELECTED_DEPARTMENTS'], true)) {
            return 'departments';
        }

        return '';
    }

    /**
     * @return array<string, true>
     */
    private static function existingDepartmentIds(): array
    {
        $known = [];
        foreach ((new DepartmentModel())->findAll([], 300) as $department) {
            $id = (string) ($department['_id'] ?? '');
            $code = (string) ($department['code'] ?? '');
            $name = (string) ($department['name'] ?? '');
            if ($id !== '' && DepartmentModel::isStudentAcademicDepartment($code, $name)) {
                $known[$id] = true;
            }
        }

        return $known;
    }

    /**
     * @return list<string>
     */
    private static function normalizeIdList(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : preg_split('/\s*,\s*/', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = trim((string) $item);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id:string,code:string,name:string}|null
     */
    private function lockedDepartment(array $user): ?array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'placement_officer') {
            return null;
        }
        $department = $this->officerDepartment($user);
        if ($department === null) {
            throw new \RuntimeException('Placement officer profile not found. Contact admin.', 403);
        }

        return $department;
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
        if (is_array($officer) && !empty($officer['departmentId'])) {
            $deptId = (string) $officer['departmentId'];
        }
        if ($deptId === '') {
            $staff = (new StaffModel())->findByUserId($userId);
            if (is_array($staff) && !empty($staff['departmentId'])) {
                $deptId = (string) $staff['departmentId'];
            }
        }
        if ($deptId === '') {
            return null;
        }
        $dept = (new DepartmentModel())->findById($deptId);
        if (!is_array($dept)) {
            return null;
        }

        return [
            'id' => (string) ($dept['_id'] ?? $deptId),
            'code' => trim((string) ($dept['code'] ?? '')),
            'name' => trim((string) ($dept['name'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $certification
     */
    private function visibleToDepartment(array $certification, string $departmentId): bool
    {
        if ((string) ($certification['visibility'] ?? '') === 'all') {
            return true;
        }
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return false;
        }
        $ids = self::normalizeIdList($certification['departmentIds'] ?? []);

        return in_array($departmentId, $ids, true);
    }

    /**
     * @param array<string, mixed> $certification
     * @param array<string, mixed> $student
     */
    private function assertVisibleToStudent(array $certification, array $student): void
    {
        if (!$this->visibleToDepartment($certification, (string) ($student['departmentId'] ?? ''))) {
            throw new \RuntimeException('This certification is not available for your department.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $certification
     */
    private function assertOfficerCanView(array $user, array $certification): void
    {
        if (!self::canManage($user)) {
            throw new \RuntimeException('You do not have permission to view this certification.', 403);
        }
        if (!$this->officerCanView($certification, $this->lockedDepartment($user))) {
            throw new \RuntimeException('This certification is outside your department.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $certification
     */
    private function assertOfficerCanEdit(array $user, array $certification): void
    {
        $this->assertManager($user);
        $locked = $this->lockedDepartment($user);
        if ($locked === null) {
            return;
        }
        $createdBy = (string) ($certification['createdBy'] ?? '');
        if ($createdBy === '' || $createdBy !== (string) ($user['_id'] ?? '')) {
            throw new \RuntimeException('You can only edit certifications you created. Certifications created by the placement admin are view only.', 403);
        }
        $ids = self::normalizeIdList($certification['departmentIds'] ?? []);
        $ownOnly = (string) ($certification['visibility'] ?? '') === 'departments'
            && $ids === [$locked['id']];
        if (!$ownOnly) {
            throw new \RuntimeException('You can only edit certifications for your own department.', 403);
        }
    }

    /**
     * @param array<string, mixed> $certification
     * @param array{id:string,code:string,name:string}|null $locked
     */
    private function officerCanView(array $certification, ?array $locked): bool
    {
        if ($locked === null) {
            return true;
        }
        if ((string) ($certification['visibility'] ?? '') === 'all') {
            return true;
        }

        return $this->visibleToDepartment($certification, $locked['id']);
    }

    /**
     * @param array<string, mixed> $certification
     * @param array<string, mixed>|null $progress
     * @return array<string, mixed>
     */
    private function publicCertification(array $certification, ?array $progress, bool $manager): array
    {
        $view = [
            'id' => (string) ($certification['_id'] ?? ''),
            'name' => (string) ($certification['name'] ?? ''),
            'url' => (string) ($certification['url'] ?? ''),
            'dueDate' => (string) ($certification['dueDate'] ?? ''),
            'pastDue' => self::isPastDue($certification),
            'description' => (string) ($certification['description'] ?? ''),
            'visibility' => (string) ($certification['visibility'] ?? 'departments'),
            'departmentIds' => array_values($certification['departmentIds'] ?? []),
            'status' => self::displayStatus($certification, $progress),
            'hasProof' => is_array($progress) && self::hasProof($progress),
            'completedAt' => is_array($progress) ? ($progress['completedAt'] ?? null) : null,
        ];
        if ($manager) {
            $view['createdBy'] = (string) ($certification['createdBy'] ?? '');
        } else {
            unset($view['departmentIds']);
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $student
     */
    private function displayName(array $student): string
    {
        $user = $this->users->findById((string) ($student['userId'] ?? ''));
        $user = is_array($user) ? $user : [];
        foreach ([$user['stud_name'] ?? '', $user['name'] ?? '', $student['displayName'] ?? '', $student['stud_name'] ?? ''] as $candidate) {
            $name = trim((string) $candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return 'Student';
    }
}
