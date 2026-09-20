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
 * Assign / list department placement representatives (placement officer → admin visibility).
 */
final class VolunteerAssignmentService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForContext(array $ctx, ?string $departmentId = null): array
    {
        (new PcaOfferLetterService())->expireOutdatedAssignments();

        $filter = ['status' => 'active'];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return [];
            }
            $filter['departmentId'] = $deptOid;
        } elseif (!$ctx['isAdmin'] && !empty($ctx['departmentId'])) {
            $filter['departmentId'] = Security::toObjectId((string) $ctx['departmentId']);
        }

        $rows = (new StudentVolunteerModel())->listActive($filter, 1000);
        return $this->enrichRows($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function assign(array $ctx, string $studentId, string $assignedByUserId, ?string $notes = null): array
    {
        (new PcaOfferLetterService())->expireOutdatedAssignments();

        if (!empty($ctx['isAdmin'])) {
            Response::forbidden('Use the admin placement representatives view to manage campus-wide assignments.');
        }
        if (empty($ctx['departmentId'])) {
            Response::forbidden('Your placement officer profile has no department assigned.');
        }

        PlacementOfficerContext::assertStudentInDepartment($studentId, $ctx);

        $studentModel = new StudentModel();
        $student = $studentModel->findById($studentId)
            ?? $studentModel->findByUserId($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }

        $canonicalStudentId = (string) ($student['_id'] ?? '');
        $deptId = (string) ($student['departmentId'] ?? '');
        if ($deptId !== (string) $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }

        $volunteerModel = new StudentVolunteerModel();
        if ($volunteerModel->findActiveByStudentId($canonicalStudentId)) {
            Response::error('This student is already assigned as a placement representative.', 422);
        }

        $dept = (new DepartmentModel())->findById($deptId);
        $userModel = new UserModel();
        $studentUser = !empty($student['userId']) ? $userModel->findById((string) $student['userId']) : null;
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $studentName = trim((string) ($studentUser['name'] ?? $personal['name'] ?? $personal['fullName'] ?? ''));
        $letterService = new PcaOfferLetterService();

        $assignmentId = $volunteerModel->insert([
            'studentId'    => Security::toObjectId($canonicalStudentId),
            'userId'       => !empty($student['userId']) ? Security::toObjectId((string) $student['userId']) : null,
            'departmentId' => Security::toObjectId($deptId),
            'assignedBy'   => Security::toObjectId($assignedByUserId),
            'assignedAt'   => DocumentHelper::now(),
            'academicYear' => PcaOfferLetterService::currentAcademicYear(),
            'status'       => 'active',
            'notes'        => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'offerLetter'  => $letterService->defaultLetterPayload($student, $dept, $studentName),
        ]);

        $doc = $volunteerModel->findById($assignmentId);
        $enriched = $this->enrichRows($doc ? [$doc] : []);
        return $enriched[0] ?? [];
    }

    public function remove(array $ctx, string $assignmentId, string $removedByUserId): void
    {
        $volunteerModel = new StudentVolunteerModel();
        $row = $volunteerModel->findById($assignmentId);
        if (!$row || ($row['status'] ?? '') !== 'active') {
            Response::notFound('Placement representative assignment not found.');
        }

        if (!$ctx['isAdmin']) {
            if (empty($ctx['departmentId'])) {
                Response::forbidden('Your placement officer profile has no department assigned.');
            }
            if ((string) ($row['departmentId'] ?? '') !== (string) $ctx['departmentId']) {
                Response::forbidden('This placement representative assignment is outside your department.');
            }
        }

        $volunteerModel->update($assignmentId, [
            'status'    => 'removed',
            'removedAt' => DocumentHelper::now(),
            'removedBy' => Security::toObjectId($removedByUserId),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();

        $studentIds = [];
        $userIds = [];
        $deptIds = [];
        foreach ($rows as $row) {
            $sid = (string) ($row['studentId'] ?? '');
            if ($sid !== '') {
                $studentIds[$sid] = true;
            }
            $uid = (string) ($row['userId'] ?? '');
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
            $by = (string) ($row['assignedBy'] ?? '');
            if ($by !== '') {
                $userIds[$by] = true;
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
        foreach ($rows as $row) {
            $sid = (string) ($row['studentId'] ?? '');
            $student = $students[$sid] ?? null;
            $uid = (string) ($row['userId'] ?? ($student['userId'] ?? ''));
            $studentUser = $uid !== '' ? ($users[$uid] ?? null) : null;
            if (!$studentUser && $student && !empty($student['userId'])) {
                $studentUser = $users[(string) $student['userId']] ?? null;
                $uid = (string) ($student['userId'] ?? '');
            }

            $deptId = (string) ($row['departmentId'] ?? ($student['departmentId'] ?? ''));
            $dept = $deptId !== '' ? ($departments[$deptId] ?? null) : null;
            $assigner = $users[(string) ($row['assignedBy'] ?? '')] ?? null;

            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $name = (string) ($studentUser['name'] ?? $personal['name'] ?? $personal['fullName'] ?? '');
            $registerNumber = (string) ($student['registerNumber'] ?? '');

            $letter = is_array($row['offerLetter'] ?? null) ? $row['offerLetter'] : [];
            $offerStatus = strtolower(trim((string) ($letter['status'] ?? 'draft'))) === 'published'
                ? 'published'
                : 'draft';

            $out[] = [
                'id'               => (string) ($row['_id'] ?? ''),
                'studentId'        => $sid,
                'userId'           => $uid !== '' ? $uid : null,
                'name'             => $name,
                'registerNumber'   => $registerNumber,
                'email'            => (string) ($studentUser['email'] ?? ''),
                'classBatch'       => (string) ($student['classBatch'] ?? ''),
                'departmentId'     => $deptId,
                'departmentName'   => (string) ($dept['name'] ?? ''),
                'departmentCode'   => (string) ($dept['code'] ?? ''),
                'assignedBy'       => (string) ($row['assignedBy'] ?? ''),
                'assignedByName'   => (string) ($assigner['name'] ?? ''),
                'assignedAt'       => $this->formatTimestamp($row['assignedAt'] ?? $row['createdAt'] ?? null),
                'academicYear'     => (string) ($row['academicYear'] ?? PcaOfferLetterService::currentAcademicYear()),
                'notes'            => (string) ($row['notes'] ?? ''),
                'status'           => (string) ($row['status'] ?? 'active'),
                'offerLetterStatus'=> $offerStatus,
                'offerLetterPublishedAt' => $this->formatTimestamp($letter['publishedAt'] ?? null),
            ];
        }

        usort($out, static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $out;
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
