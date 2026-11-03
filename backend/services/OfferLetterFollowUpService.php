<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Models\StudentPlacementDetailsTable;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;

/**
 * Centralized pending offer-letter follow-up for admin, PO, and class teachers.
 * Built on OfficerDataService::listPlacedStudents + registry offer_letter paths.
 */
final class OfferLetterFollowUpService
{
    private OfficerDataService $officerData;
    private StudentModel $studentModel;

    public function __construct()
    {
        $this->officerData = new OfficerDataService();
        $this->studentModel = new StudentModel();
    }

    /**
     * @param array<string, mixed> $ctx PlacementOfficerContext / StaffContext-compatible
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(array $ctx, array $filters = []): array
    {
        $query = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $statusFilter = trim((string) ($filters['offerLetterStatus'] ?? $filters['status'] ?? ''));
        if ($statusFilter === '') {
            $statusFilter = 'follow_up';
        }
        $departmentId = trim((string) ($filters['departmentId'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $company = strtolower(trim((string) ($filters['company'] ?? '')));
        $studRole = strtolower(trim((string) ($filters['studRole'] ?? 'all')));

        $placed = $this->officerData->listPlacedStudents($ctx, $query !== '' ? $query : null);
        $detailsByAdmno = $this->detailsOfferLetterMap();
        $offerIndex = AlumniEmploymentOfferIndex::load();

        $rows = [];
        $seenRegisters = [];
        $counts = [
            'pending_upload' => 0,
            'confidentiality_exception' => 0,
            'uploaded' => 0,
            'awaiting_verification' => 0,
            'verified' => 0,
            'follow_up' => 0,
            'total' => 0,
        ];

        foreach ($placed as $student) {
            $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));
            $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
            $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : [];
            $doc = array_merge($placement, $self);

            if ($register !== '') {
                $seenRegisters[$register] = true;
            }
            $details = $register !== '' ? ($detailsByAdmno[$register] ?? null) : null;
            if (is_array($details)) {
                if (trim((string) ($details['offer_letter'] ?? '')) !== '' && trim((string) ($doc['offerLetter'] ?? '')) === '') {
                    $doc['offerLetter'] = (string) $details['offer_letter'];
                }
                if (trim((string) ($details['offer_letter_exception'] ?? '')) !== '') {
                    $doc['offerLetterException'] = (string) $details['offer_letter_exception'];
                }
                if (trim((string) ($details['offer_letter_exception_note'] ?? '')) !== '') {
                    $doc['offerLetterExceptionNote'] = (string) $details['offer_letter_exception_note'];
                }
                if (trim((string) ($details['offer_letter_followup_at'] ?? '')) !== '') {
                    $doc['offerLetterFollowUpAt'] = (string) $details['offer_letter_followup_at'];
                }
                if (trim((string) ($details['offer_letter_followup_note'] ?? '')) !== '') {
                    $doc['offerLetterFollowUpNote'] = (string) $details['offer_letter_followup_note'];
                }
            }
            if (trim((string) ($doc['offerLetter'] ?? '')) === '') {
                $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
                $user = is_array($student['user'] ?? null) ? $student['user'] : [];
                $email = strtolower(trim((string) (
                    $student['email']
                    ?? $student['collegeEmail']
                    ?? $personal['collegeEmail']
                    ?? $user['email']
                    ?? ''
                )));
                $hit = $offerIndex->find($register, $email);
                if (is_array($hit) && trim((string) ($hit['path'] ?? '')) !== '') {
                    $doc['offerLetter'] = (string) $hit['path'];
                    unset($doc['offerLetterVerified'], $doc['verificationDate']);
                }
            }

            $tracking = OfferLetterTracking::summarize($doc);
            $status = (string) $tracking['offerLetterStatus'];
            $counts['total']++;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if (OfferLetterTracking::needsFollowUp($status)) {
                $counts['follow_up']++;
            }

            $deptId = (string) ($student['departmentId'] ?? $student['department']['id'] ?? '');
            if ($departmentId !== '' && $deptId !== $departmentId) {
                continue;
            }
            $classBatch = trim((string) ($student['classBatch'] ?? $student['batch'] ?? $student['year'] ?? ''));
            if ($batch !== '' && strcasecmp($classBatch, $batch) !== 0) {
                continue;
            }
            $companyName = trim((string) (
                $placement['company']
                ?? $placement['companyName']
                ?? $self['companyName']
                ?? $student['placementCompany']
                ?? ''
            ));
            if ($company !== '' && !str_contains(strtolower($companyName), $company)) {
                continue;
            }
            $roleHint = strtolower(trim((string) ($student['studRole'] ?? $student['stud_role'] ?? '')));
            if ($studRole === 'student' && str_contains($roleHint, 'alumni')) {
                continue;
            }
            if ($studRole === 'alumni' && $roleHint !== '' && !str_contains($roleHint, 'alumni')) {
                continue;
            }

            $include = match ($statusFilter) {
                'follow_up', 'pending' => OfferLetterTracking::needsFollowUp($status),
                'all' => true,
                default => $status === $statusFilter,
            };
            if (!$include) {
                continue;
            }

            $reportedBy = trim((string) (
                $doc['reportedBy']
                ?? $self['verifiedByName']
                ?? $self['reportedBy']
                ?? ''
            ));
            if ($reportedBy === '') {
                $reportedBy = !empty($self['recordedByStaff']) ? 'Placement staff' : 'Student / alumni';
            }

            $rows[] = [
                'studentId' => (string) ($student['_id'] ?? $student['id'] ?? ''),
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'studentName' => (string) ($student['displayName'] ?? $student['user']['name'] ?? $student['name'] ?? ''),
                'department' => (string) ($student['department']['code'] ?? $student['department']['name'] ?? $student['departmentName'] ?? ''),
                'departmentId' => $deptId,
                'batch' => $classBatch,
                'company' => $companyName,
                'role' => trim((string) ($placement['role'] ?? $self['role'] ?? $student['placementRole'] ?? '')),
                'reportedAt' => trim((string) (
                    $placement['placedAt']
                    ?? $self['submittedAt']
                    ?? $self['verifiedAt']
                    ?? $student['placedAt']
                    ?? ''
                )),
                'reportedBy' => $reportedBy,
                'reportedByRole' => trim((string) ($doc['reportedByRole'] ?? $self['reportedByRole'] ?? '')),
                'offerLetterStatus' => $status,
                'offerLetterStatusLabel' => OfferLetterTracking::displayLabel($status),
                'offerLetterException' => (string) ($tracking['offerLetterException'] ?? ''),
                'offerLetterExceptionNote' => (string) ($tracking['offerLetterExceptionNote'] ?? ''),
                'offerLetterFollowUpAt' => (string) ($tracking['offerLetterFollowUpAt'] ?? ''),
                'offerLetterFollowUpNote' => (string) ($tracking['offerLetterFollowUpNote'] ?? ''),
                'hasOfferLetter' => (bool) ($tracking['hasOfferLetter'] ?? false),
                'needsOfferLetterFollowUp' => OfferLetterTracking::needsFollowUp($status),
                'studRole' => $roleHint !== '' ? $roleHint : 'student',
            ];
        }

        $this->appendRegistryOfferRows(
            $rows,
            $counts,
            $detailsByAdmno,
            $seenRegisters,
            $offerIndex,
            $ctx,
            $statusFilter,
            $departmentId,
            $batch,
            $company,
            $studRole,
            $query
        );

        usort($rows, static function (array $a, array $b): int {
            $byDate = strcmp((string) ($b['reportedAt'] ?? ''), (string) ($a['reportedAt'] ?? ''));
            if ($byDate !== 0) {
                return $byDate;
            }

            return strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? ''));
        });

        return [
            'rows' => DocumentHelper::jsonSafe($rows),
            'counts' => $counts,
            'pendingCount' => (int) ($counts['follow_up'] ?? 0),
            'filters' => [
                'offerLetterStatus' => $statusFilter,
                'departmentId' => $departmentId,
                'batch' => $batch,
                'company' => $company,
                'studRole' => $studRole,
                'q' => $query,
            ],
        ];
    }

    /**
     * Record an internal follow-up note/date on the student's placement document.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordFollowUp(string $studentRef, array $ctx, array $input): array
    {
        $existingStudent = $this->officerData->resolveStudentRef($studentRef);
        if (!is_array($existingStudent)) {
            return $this->recordFollowUpOnRegistry($studentRef, $ctx, $input);
        }
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $studentId = (string) ($student['_id'] ?? '');

        $followUpAt = trim((string) ($input['offerLetterFollowUpAt'] ?? $input['followUpAt'] ?? ''));
        $followUpNote = trim((string) ($input['offerLetterFollowUpNote'] ?? $input['followUpNote'] ?? ''));
        if ($followUpAt === '') {
            $followUpAt = date('Y-m-d');
        }
        if (strlen($followUpNote) > 500) {
            $followUpNote = substr($followUpNote, 0, 500);
        }

        $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
        $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : [];
        $placement['offerLetterFollowUpAt'] = $followUpAt;
        $self['offerLetterFollowUpAt'] = $followUpAt;
        if ($followUpNote !== '') {
            $placement['offerLetterFollowUpNote'] = $followUpNote;
            $self['offerLetterFollowUpNote'] = $followUpNote;
        }

        $this->studentModel->update($studentId, [
            'placement' => $placement,
            'selfPlacement' => $self !== [] ? $self : ($student['selfPlacement'] ?? null),
        ]);

        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register !== '') {
            $details = new StudentPlacementDetailsTable();
            if ($details->isReady()) {
                $details->saveOfferLetterFollowUp($register, $followUpAt, $followUpNote);
            }
        }

        return [
            'studentId' => $studentId,
            'offerLetterFollowUpAt' => $followUpAt,
            'offerLetterFollowUpNote' => $followUpNote,
        ];
    }

    /**
     * Persist confidentiality exception on a placed student without requiring a file.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordConfidentialityException(string $studentRef, array $ctx, array $input): array
    {
        $existingStudent = $this->officerData->resolveStudentRef($studentRef);
        if (!is_array($existingStudent)) {
            return $this->recordExceptionOnRegistry($studentRef, $ctx, $input);
        }
        $student = $this->resolveScopedStudent($studentRef, $ctx);
        $studentId = (string) ($student['_id'] ?? '');
        $parsed = OfferLetterTracking::parseExceptionInput(array_merge($input, [
            'offerLetterException' => OfferLetterTracking::EXCEPTION_COMPANY_CONFIDENTIALITY,
        ]));
        $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
        $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : [];
        if (OfferLetterTracking::hasOfferLetterPath($placement) || OfferLetterTracking::hasOfferLetterPath($self)) {
            Response::error('An offer letter is already on file for this placement.', 409);
        }
        $placement = OfferLetterTracking::applyUploadState($placement, null, $parsed);
        $self = OfferLetterTracking::applyUploadState($self !== [] ? $self : [
            'companyName' => (string) ($placement['company'] ?? ''),
            'role' => (string) ($placement['role'] ?? ''),
            'status' => 'approved',
        ], null, $parsed);

        $this->studentModel->update($studentId, [
            'placement' => $placement,
            'selfPlacement' => $self,
        ]);

        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register !== '') {
            $details = new StudentPlacementDetailsTable();
            if ($details->isReady()) {
                $details->saveOfferLetterException(
                    $register,
                    OfferLetterTracking::EXCEPTION_COMPANY_CONFIDENTIALITY,
                    $parsed['note']
                );
            }
        }

        return OfferLetterTracking::summarize($placement);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function resolveScopedStudent(string $studentRef, array $ctx): array
    {
        $student = $this->officerData->resolveStudentRef($studentRef);
        if (!is_array($student)) {
            Response::notFound('Student not found.');
        }
        PlacementOfficerContext::assertStudentInDepartment((string) ($student['_id'] ?? ''), $ctx);
        if (!empty($ctx['staffScope']) && StaffContext::assignedClassBatches($ctx) !== []) {
            if (!StaffContext::studentMatchesScope($student, $ctx)) {
                Response::forbidden('This student is outside your assigned class.');
            }
        }

        return $student;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function detailsOfferLetterMap(): array
    {
        $map = [];
        try {
            $details = new StudentPlacementDetailsTable();
            if (!$details->isReady()) {
                return [];
            }
            foreach ($details->listRows(20000) as $row) {
                $admno = strtoupper(trim((string) ($row['admno'] ?? '')));
                if ($admno === '') {
                    continue;
                }
                if (!isset($map[$admno])) {
                    $map[$admno] = $row;
                    continue;
                }
                foreach ([
                    'offer_letter',
                    'offer_letter_exception',
                    'offer_letter_exception_note',
                    'offer_letter_followup_at',
                    'offer_letter_followup_note',
                ] as $column) {
                    if (trim((string) ($map[$admno][$column] ?? '')) === '' && trim((string) ($row[$column] ?? '')) !== '') {
                        $map[$admno][$column] = $row[$column];
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * Placed registry rows that are not already in the student placement collection.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int> $counts
     * @param array<string, array<string, mixed>> $detailsByAdmno
     * @param array<string, bool> $seenRegisters
     * @param array<string, mixed> $ctx
     */
    private function appendRegistryOfferRows(
        array &$rows,
        array &$counts,
        array $detailsByAdmno,
        array $seenRegisters,
        AlumniEmploymentOfferIndex $offerIndex,
        array $ctx,
        string $statusFilter,
        string $departmentId,
        string $batch,
        string $company,
        string $studRole,
        string $query
    ): void {
        foreach ($detailsByAdmno as $admno => $details) {
            if (!is_array($details) || isset($seenRegisters[$admno])) {
                continue;
            }
            $type = trim((string) ($details['type'] ?? ''));
            $employer = trim((string) ($details['employer'] ?? ''));
            if ($employer === '' || in_array($type, ['Higher Education', 'Research'], true)) {
                continue;
            }
            if (!$this->registryDetailsInScope($ctx, $details, $admno)) {
                continue;
            }
            $doc = [
                'offerLetter' => trim((string) ($details['offer_letter'] ?? '')),
                'offerLetterException' => trim((string) ($details['offer_letter_exception'] ?? '')),
                'offerLetterExceptionNote' => trim((string) ($details['offer_letter_exception_note'] ?? '')),
                'offerLetterFollowUpAt' => trim((string) ($details['offer_letter_followup_at'] ?? '')),
                'offerLetterFollowUpNote' => trim((string) ($details['offer_letter_followup_note'] ?? '')),
            ];
            if ($doc['offerLetter'] === '') {
                $hit = $offerIndex->find($admno, (string) ($details['email'] ?? ''));
                if (is_array($hit) && trim((string) ($hit['path'] ?? '')) !== '') {
                    $doc['offerLetter'] = (string) $hit['path'];
                }
            }
            $tracking = OfferLetterTracking::summarize($doc);
            $status = (string) $tracking['offerLetterStatus'];
            $counts['total']++;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if (OfferLetterTracking::needsFollowUp($status)) {
                $counts['follow_up']++;
            }

            $classBatch = trim((string) ($details['year'] ?? ''));
            if ($batch !== '' && strcasecmp($classBatch, $batch) !== 0) {
                continue;
            }
            if ($departmentId !== '') {
                $rowDepartment = (new StaffPlacementRegistryService())->admnoDepartmentId($admno);
                if ($rowDepartment !== '' && strcasecmp($rowDepartment, $departmentId) !== 0) {
                    continue;
                }
            }
            if ($company !== '' && !str_contains(strtolower($employer), $company)) {
                continue;
            }
            $roleHint = strtolower(trim((string) ($details['stud_role'] ?? '')));
            if ($studRole === 'student' && str_contains($roleHint, 'alumni')) {
                continue;
            }
            if ($studRole === 'alumni' && $roleHint !== '' && !str_contains($roleHint, 'alumni')) {
                continue;
            }
            $name = trim((string) ($details['student'] ?? ''));
            if ($query !== '') {
                $haystack = strtolower($name . ' ' . $admno . ' ' . $employer);
                if (!str_contains($haystack, strtolower($query))) {
                    continue;
                }
            }
            $include = match ($statusFilter) {
                'follow_up', 'pending' => OfferLetterTracking::needsFollowUp($status),
                'all' => true,
                default => $status === $statusFilter,
            };
            if (!$include) {
                continue;
            }

            $rows[] = [
                'studentId' => $admno,
                'registerNumber' => $admno,
                'studentName' => $name,
                'department' => '',
                'departmentId' => '',
                'batch' => $classBatch,
                'company' => $employer,
                'role' => '',
                'reportedAt' => trim((string) ($details['createdate'] ?? $details['updatedate'] ?? '')),
                'reportedBy' => 'Placement registry',
                'reportedByRole' => '',
                'offerLetterStatus' => $status,
                'offerLetterStatusLabel' => OfferLetterTracking::displayLabel($status),
                'offerLetterException' => (string) ($tracking['offerLetterException'] ?? ''),
                'offerLetterExceptionNote' => (string) ($tracking['offerLetterExceptionNote'] ?? ''),
                'offerLetterFollowUpAt' => (string) ($tracking['offerLetterFollowUpAt'] ?? ''),
                'offerLetterFollowUpNote' => (string) ($tracking['offerLetterFollowUpNote'] ?? ''),
                'hasOfferLetter' => (bool) ($tracking['hasOfferLetter'] ?? false),
                'needsOfferLetterFollowUp' => OfferLetterTracking::needsFollowUp($status),
                'studRole' => $roleHint !== '' ? $roleHint : 'alumni',
            ];
        }
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $details
     */
    private function registryDetailsInScope(array $ctx, array $details, string $admno): bool
    {
        if (!empty($ctx['isAdmin']) || !empty($ctx['campusWide'])) {
            return true;
        }
        $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : [];
        $isClassStaff = (string) ($user['role'] ?? '') === 'staff' || !empty($ctx['staffScope']);
        if ($isClassStaff) {
            $batches = StaffContext::assignedClassBatches($ctx);
            if ($batches !== []) {
                if (!StaffContext::classBatchMatchesAssigned(trim((string) ($details['year'] ?? '')), $batches)) {
                    return false;
                }
            }
        }
        $departmentId = trim((string) ($ctx['departmentId'] ?? ''));
        if ($departmentId === '') {
            return false;
        }
        $rowDepartment = (new StaffPlacementRegistryService())->admnoDepartmentId($admno);
        if ($rowDepartment !== '' && strcasecmp($rowDepartment, $departmentId) !== 0) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function recordFollowUpOnRegistry(string $studentRef, array $ctx, array $input): array
    {
        $details = new StudentPlacementDetailsTable();
        $row = $details->findByAdmnoOrId($studentRef);
        if (!is_array($row)) {
            Response::notFound('Student not found.');
        }
        $admno = strtoupper(trim((string) ($row['admno'] ?? '')));
        if (!$this->registryDetailsInScope($ctx, $row, $admno)) {
            Response::forbidden('This student is outside your scope.');
        }
        $followUpAt = trim((string) ($input['offerLetterFollowUpAt'] ?? $input['followUpAt'] ?? ''));
        $followUpNote = trim((string) ($input['offerLetterFollowUpNote'] ?? $input['followUpNote'] ?? ''));
        if ($followUpAt === '') {
            $followUpAt = date('Y-m-d');
        }
        if (strlen($followUpNote) > 500) {
            $followUpNote = substr($followUpNote, 0, 500);
        }
        if (!$details->saveOfferLetterFollowUp($admno, $followUpAt, $followUpNote)) {
            Response::error('Could not record this follow-up.', 500);
        }

        return [
            'studentId' => $admno,
            'offerLetterFollowUpAt' => $followUpAt,
            'offerLetterFollowUpNote' => $followUpNote,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function recordExceptionOnRegistry(string $studentRef, array $ctx, array $input): array
    {
        $details = new StudentPlacementDetailsTable();
        $row = $details->findByAdmnoOrId($studentRef);
        if (!is_array($row)) {
            Response::notFound('Student not found.');
        }
        $admno = strtoupper(trim((string) ($row['admno'] ?? '')));
        if (!$this->registryDetailsInScope($ctx, $row, $admno)) {
            Response::forbidden('This student is outside your scope.');
        }
        if (trim((string) ($row['offer_letter'] ?? '')) !== '' || $details->firstOfferLetterPath($admno) !== '') {
            Response::error('An offer letter is already on file for this placement.', 409);
        }
        $hit = AlumniEmploymentOfferIndex::load()->find($admno, (string) ($row['email'] ?? ''));
        if (is_array($hit) && trim((string) ($hit['path'] ?? '')) !== '') {
            Response::error('An offer letter is already on file for this placement.', 409);
        }
        $parsed = OfferLetterTracking::parseExceptionInput(array_merge($input, [
            'offerLetterException' => OfferLetterTracking::EXCEPTION_COMPANY_CONFIDENTIALITY,
        ]));
        if (!$details->saveOfferLetterException($admno, $parsed['exception'], $parsed['note'])) {
            Response::error('Could not record the confidentiality exception.', 500);
        }

        return OfferLetterTracking::summarize([
            'offerLetterException' => $parsed['exception'],
            'offerLetterExceptionNote' => $parsed['note'],
        ]);
    }
}
