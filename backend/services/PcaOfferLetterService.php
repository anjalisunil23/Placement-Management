<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\StudentVolunteerModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Placement Campus Ambassador (PCA) offer letter — draft, publish, student access.
 */
final class PcaOfferLetterService
{
    private const TZ = 'Asia/Kolkata';

    public static function academicYearFromDate(\DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $start = $month >= 7 ? $year : $year - 1;

        return $start . '–' . ($start + 1);
    }

    public static function currentAcademicYear(): string
    {
        return self::academicYearFromDate(new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)));
    }

    /** Remove representatives from previous academic years (July–June cycle). */
    public function expireOutdatedAssignments(): int
    {
        $current = self::currentAcademicYear();
        $model = new StudentVolunteerModel();
        $removed = 0;

        foreach ($model->listActive([], 5000) as $row) {
            $year = (string) ($row['academicYear'] ?? '');
            if ($year === '') {
                $assigned = $this->parseDate($row['assignedAt'] ?? $row['createdAt'] ?? null);
                $year = $assigned ? self::academicYearFromDate($assigned) : '';
            }
            if ($year !== '' && $year !== $current) {
                $model->update((string) $row['_id'], [
                    'status'       => 'removed',
                    'removedAt'    => DocumentHelper::now(),
                    'removedBy'    => null,
                    'removeReason' => 'academic_year_expired',
                ]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function getForOfficer(array $ctx, string $assignmentId): array
    {
        $row = $this->assertOfficerAccess($ctx, $assignmentId);
        $row = $this->ensureLetterDefaults($row);
        return $this->serializeLetter($row, includeDraft: true);
    }

    /** Published letter for admin view (same payload shape as student). */
    public function getForAdmin(string $assignmentId): array
    {
        $row = $this->assertActiveAssignment($assignmentId);
        return $this->serializeLetter($row, includeDraft: false);
    }

    /** Published letter for staff view (class-scoped, same payload shape as student). */
    public function getForStaff(array $staffCtx, string $assignmentId): array
    {
        $row = $this->assertActiveAssignment($assignmentId);
        $student = (new StudentModel())->findById((string) ($row['studentId'] ?? ''));
        if ($student === null) {
            Response::notFound('Placement representative assignment not found.');
        }
        StaffContext::assertStudentInScope($student, $staffCtx);

        return $this->serializeLetter($row, includeDraft: false);
    }

    /**
     * Published PCA offer letters for dashboard lists.
     *
     * @param array<string, mixed>|null $staffCtx StaffContext::resolve() for class filtering; null = campus-wide (admin).
     * @return list<array<string, mixed>>
     */
    public function listPublishedSummaries(?array $staffCtx = null): array
    {
        $rows = (new StudentVolunteerModel())->listActive(['status' => 'active'], 500);
        $published = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->letterStatus($row) === 'published'
        ));
        if ($published === []) {
            return [];
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $mode = $staffCtx !== null ? 'staff' : 'admin';

        $studentIds = [];
        $userIds = [];
        $deptIds = [];
        foreach ($published as $row) {
            $sid = (string) ($row['studentId'] ?? '');
            if ($sid !== '') {
                $studentIds[$sid] = true;
            }
            $uid = (string) ($row['userId'] ?? '');
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
            $did = (string) ($row['departmentId'] ?? '');
            if ($did !== '') {
                $deptIds[$did] = true;
            }
        }

        $students = $studentModel->findByIds(array_keys($studentIds));
        $users = $userModel->findByIds(array_keys($userIds));
        $departments = $deptModel->findByIds(array_keys($deptIds));
        $out = [];

        foreach ($published as $row) {
            $sid = (string) ($row['studentId'] ?? '');
            $student = $students[$sid] ?? null;
            if ($student === null) {
                continue;
            }
            if ($staffCtx !== null && !StaffContext::studentMatchesScope($student, $staffCtx)) {
                continue;
            }

            $assignmentId = (string) ($row['_id'] ?? '');
            $uid = (string) ($row['userId'] ?? $student['userId'] ?? '');
            $studentUser = $uid !== '' ? ($users[$uid] ?? null) : null;
            if (!$studentUser && !empty($student['userId'])) {
                $studentUser = $users[(string) $student['userId']] ?? null;
            }
            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $name = trim((string) ($studentUser['name'] ?? $personal['name'] ?? $personal['fullName'] ?? ''));
            $deptId = (string) ($row['departmentId'] ?? $student['departmentId'] ?? '');
            $dept = $deptId !== '' ? ($departments[$deptId] ?? null) : null;
            $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];

            $out[] = [
                'assignmentId'   => $assignmentId,
                'studentName'    => $name,
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'classBatch'     => StaffContext::studentClassBatch($student),
                'departmentName' => (string) ($dept['name'] ?? ''),
                'academicYear'   => (string) ($letter['academicYear'] ?? $row['academicYear'] ?? ''),
                'viewUrl'        => '/pca-offer-letter.html?mode=' . $mode . '&assignment=' . rawurlencode($assignmentId),
            ];
        }

        usort($out, static fn ($a, $b) => strcasecmp((string) $a['studentName'], (string) $b['studentName']));

        return $out;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveDraft(array $ctx, string $assignmentId, array $input, string $savedByUserId): array
    {
        $row = $this->assertOfficerAccess($ctx, $assignmentId);
        if ($this->letterStatus($row) === 'published') {
            Response::error('This offer letter is already published. Unpublish is not supported — create a new representative assignment if needed.', 409);
        }

        $letter = $this->mergeLetterInput($row, $input);
        $letter['status'] = 'draft';
        $letter['savedAt'] = DocumentHelper::now();
        $letter['savedBy'] = Security::toObjectId($savedByUserId);

        $model = new StudentVolunteerModel();
        $model->update($assignmentId, ['offerLetter' => $letter]);

        $updated = $model->findById($assignmentId);
        return $this->serializeLetter($updated ?: $row, includeDraft: true);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function publish(array $ctx, string $assignmentId, string $publishedByUserId): array
    {
        $row = $this->assertOfficerAccess($ctx, $assignmentId);
        $row = $this->ensureLetterDefaults($row);
        $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $letter['letterDate'] = $now->format('Y-m-d');
        $letter['academicYear'] = self::academicYearFromDate($now);
        $letter['status'] = 'published';
        $letter['publishedAt'] = DocumentHelper::now();
        $letter['publishedBy'] = Security::toObjectId($publishedByUserId);
        $letter['savedAt'] = $letter['publishedAt'];

        $name = trim((string) ($letter['studentName'] ?? ''));
        if ($name === '') {
            Response::error('Student name is required before publishing.', 422);
        }

        $model = new StudentVolunteerModel();
        $model->update($assignmentId, ['offerLetter' => $letter]);

        $userId = (string) ($row['userId'] ?? '');
        if ($userId !== '') {
            (new NotificationService())->notifyUser(
                $userId,
                'pca_offer_letter',
                'PCA appointment letter published',
                'Your Placement Campus Ambassador (PCA) offer letter is ready. Open your dashboard to view and download it.',
                [
                    'assignmentId' => $assignmentId,
                    'academicYear' => (string) ($row['academicYear'] ?? self::currentAcademicYear()),
                ],
                true
            );
        }

        $updated = $model->findById($assignmentId);
        return $this->serializeLetter($updated ?: $row, includeDraft: true);
    }

    /**
     * Published letter for the logged-in student (null if none).
     *
     * @return array<string, mixed>|null
     */
    public function getPublishedForStudentUser(string $userId): ?array
    {
        $studentModel = new StudentModel();
        $student = $studentModel->findByUserId($userId);
        if ($student === null) {
            return null;
        }

        $assignment = (new StudentVolunteerModel())->findActiveByStudentId((string) $student['_id']);
        if ($assignment === null || $this->letterStatus($assignment) !== 'published') {
            return null;
        }

        return $this->serializeLetter($assignment, includeDraft: false);
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $dept
     * @return array<string, mixed>
     */
    public function defaultLetterPayload(array $student, ?array $dept, string $studentName): array
    {
        $classBatch = trim((string) ($student['classBatch'] ?? ''));
        $deptLabel = trim((string) ($dept['name'] ?? ''));
        if ($deptLabel !== '' && ($dept['code'] ?? '') !== '') {
            $deptLabel = $deptLabel . ' (' . ($dept['code'] ?? '') . ')';
        }
        $classLabel = $classBatch;
        if ($deptLabel !== '') {
            $classLabel = $classLabel !== '' ? ($classBatch . ' / ' . $deptLabel) : $deptLabel;
        }

        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));

        return [
            'status'      => 'draft',
            'letterDate'  => '',
            'studentName' => $studentName,
            'classLabel'  => $classLabel,
            'academicYear'=> self::currentAcademicYear(),
            'savedAt'     => null,
            'publishedAt' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function serializeLetter(array $row, bool $includeDraft): array
    {
        $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
        $status = $this->letterStatus($row);

        if (!$includeDraft && $status !== 'published') {
            Response::notFound('Offer letter not published.');
        }

        $letterDate = (string) ($letter['letterDate'] ?? '');
        if ($status === 'published' && $letterDate === '') {
            $published = $this->parseDate($letter['publishedAt'] ?? null);
            $letterDate = $published ? $published->format('Y-m-d') : (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
        }

        $parsedDate = $letterDate !== ''
            ? ($this->parseDate($letterDate . ' 12:00:00') ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))
            : new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $academicYear = (string) ($letter['academicYear'] ?? $row['academicYear'] ?? self::academicYearFromDate($parsedDate));

        return [
            'assignmentId'  => (string) ($row['_id'] ?? ''),
            'studentId'     => (string) ($row['studentId'] ?? ''),
            'status'        => $status,
            'letterDate'    => $letterDate,
            'letterDateLabel' => $this->formatLetterDate($parsedDate),
            'studentName'   => (string) ($letter['studentName'] ?? ''),
            'classLabel'    => (string) ($letter['classLabel'] ?? ''),
            'academicYear'  => $academicYear,
            'publishedAt'   => $this->formatTimestamp($letter['publishedAt'] ?? null),
            'savedAt'       => $this->formatTimestamp($letter['savedAt'] ?? null),
            'viewUrl'       => '/pca-offer-letter.html?mode=student',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mergeLetterInput(array $row, array $input): array
    {
        $existing = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
        $letterDate = trim((string) ($input['letterDate'] ?? $existing['letterDate'] ?? ''));
        if ($letterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $letterDate)) {
            Response::error('letterDate must be YYYY-MM-DD.', 422);
        }

        $parsed = $letterDate !== '' ? $this->parseDate($letterDate . ' 12:00:00') : null;

        return [
            'status'       => (string) ($existing['status'] ?? 'draft'),
            'letterDate'   => $letterDate !== '' ? $letterDate : (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d'),
            'studentName'  => trim((string) ($input['studentName'] ?? $existing['studentName'] ?? '')),
            'classLabel'   => trim((string) ($input['classLabel'] ?? $existing['classLabel'] ?? '')),
            'academicYear' => $parsed ? self::academicYearFromDate($parsed) : (string) ($existing['academicYear'] ?? $row['academicYear'] ?? self::currentAcademicYear()),
            'savedAt'      => $existing['savedAt'] ?? null,
            'publishedAt'  => $existing['publishedAt'] ?? null,
            'publishedBy'  => $existing['publishedBy'] ?? null,
            'savedBy'      => $existing['savedBy'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ensureLetterDefaults(array $row): array
    {
        $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
        if (trim((string) ($letter['studentName'] ?? '')) !== '') {
            return $row;
        }

        $student = (new StudentModel())->findById((string) ($row['studentId'] ?? ''));
        if ($student === null) {
            return $row;
        }

        $dept = (new DepartmentModel())->findById((string) ($row['departmentId'] ?? ($student['departmentId'] ?? '')));
        $user = null;
        $uid = (string) ($row['userId'] ?? ($student['userId'] ?? ''));
        if ($uid !== '') {
            $user = (new UserModel())->findById($uid);
        }
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) ($user['name'] ?? $personal['name'] ?? $personal['fullName'] ?? ''));

        $row['offerLetter'] = $this->defaultLetterPayload($student, $dept, $name);
        return $row;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function assertActiveAssignment(string $assignmentId): array
    {
        $row = (new StudentVolunteerModel())->findById($assignmentId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            Response::notFound('Placement representative assignment not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function assertOfficerAccess(array $ctx, string $assignmentId): array
    {
        if (!empty($ctx['isAdmin'])) {
            Response::forbidden('Use User Management for campus-wide representative records.');
        }
        if (empty($ctx['departmentId'])) {
            Response::forbidden('Your placement officer profile has no department assigned.');
        }

        $row = (new StudentVolunteerModel())->findById($assignmentId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            Response::notFound('Placement representative assignment not found.');
        }
        if ((string) ($row['departmentId'] ?? '') !== (string) $ctx['departmentId']) {
            Response::forbidden('This placement representative assignment is outside your department.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function letterStatus(array $row): string
    {
        $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
        $status = strtolower(trim((string) ($letter['status'] ?? 'draft')));

        return $status === 'published' ? 'published' : 'draft';
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone(self::TZ));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $raw = trim($value);
        if (str_contains($raw, '.')) {
            $raw = explode('.', $raw, 2)[0];
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->setTimezone(new \DateTimeZone(self::TZ));
            }
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ));
    }

    private function formatLetterDate(\DateTimeInterface $date): string
    {
        return $date->format('j F Y');
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return '';
    }
}
