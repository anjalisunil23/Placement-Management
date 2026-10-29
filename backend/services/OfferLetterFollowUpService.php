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

        $rows = [];
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
                'offerLetterStatusLabel' => OfferLetterTracking::label($status),
                'offerLetterException' => (string) ($tracking['offerLetterException'] ?? ''),
                'offerLetterExceptionNote' => (string) ($tracking['offerLetterExceptionNote'] ?? ''),
                'offerLetterFollowUpAt' => (string) ($tracking['offerLetterFollowUpAt'] ?? ''),
                'offerLetterFollowUpNote' => (string) ($tracking['offerLetterFollowUpNote'] ?? ''),
                'hasOfferLetter' => (bool) ($tracking['hasOfferLetter'] ?? false),
                'needsOfferLetterFollowUp' => OfferLetterTracking::needsFollowUp($status),
                'studRole' => $roleHint !== '' ? $roleHint : 'student',
            ];
        }

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
                $map[$admno] = $row;
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }
}
