<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;

/**
 * Placement funnel tracking and candidate progress aggregation.
 */
final class TrackingService
{
    /** @var array<string, string> */
    private const FUNNEL_LABELS = [
        'applied'     => 'Applied',
        'shortlisted' => 'Shortlisted',
        'test'        => 'Test',
        'interview'   => 'Interview',
        'offered'     => 'Offered',
        'joined'      => 'Joined',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getOverview(?string $departmentId = null, int $candidateLimit = 100): array
    {
        $ctx = [
            'isAdmin'      => $departmentId === null,
            'departmentId' => ($departmentId !== null && $departmentId !== '') ? $departmentId : null,
            'department'   => null,
            'profile'      => null,
        ];

        return $this->getOverviewForContext($ctx, $candidateLimit);
    }

    /**
     * @param array<string, mixed> $ctx PlacementOfficerContext shape
     * @return array<string, mixed>
     */
    public function getOverviewForContext(array $ctx, int $candidateLimit = 100): array
    {
        $students = (new StudentModel())->findAll(PlacementOfficerContext::studentCollectionFilter($ctx), 5000);
        $entries = $this->collectPipelineEntries($students);

        $funnel = array_fill_keys(array_keys(self::FUNNEL_LABELS), 0);
        $offersByStudent = [];
        $activeStudentIds = [];

        foreach ($entries as $entry) {
            $student = $entry['_student'] ?? [];
            $sid = (string) ($entry['studentId'] ?? '');
            if ($sid !== '') {
                $activeStudentIds[$sid] = true;
            }

            $bucket = $this->pipelineEntryBucket($entry, $student);
            if ($bucket === 'excluded' || !isset($funnel[$bucket])) {
                continue;
            }
            $funnel[$bucket]++;

            if ($this->entryCountsAsOffer($entry)) {
                if ($sid !== '') {
                    $offersByStudent[$sid] = ($offersByStudent[$sid] ?? 0) + 1;
                }
            }
        }

        $singleOffer = 0;
        $multipleOffers = 0;
        $noOfferYet = 0;
        foreach (array_keys($activeStudentIds) as $sid) {
            $count = $offersByStudent[$sid] ?? 0;
            if ($count === 0) {
                $noOfferYet++;
            } elseif ($count === 1) {
                $singleOffer++;
            } else {
                $multipleOffers++;
            }
        }

        $applied = $funnel['applied'] + $funnel['shortlisted'] + $funnel['test']
            + $funnel['interview'] + $funnel['offered'] + $funnel['joined'];
        $conversion = $applied > 0
            ? round(($funnel['joined'] / $applied) * 100, 1)
            : 0;

        return [
            'summary' => [
                'applied'     => $applied,
                'shortlisted' => $funnel['shortlisted'] + $funnel['test'] + $funnel['interview'] + $funnel['offered'] + $funnel['joined'],
                'interviewed' => $funnel['interview'] + $funnel['offered'] + $funnel['joined'],
                'offered'     => $funnel['offered'] + $funnel['joined'],
                'joined'      => $funnel['joined'],
                'conversion'  => $conversion,
            ],
            'funnel' => [
                'labels' => array_values(self::FUNNEL_LABELS),
                'values' => [
                    $applied,
                    $funnel['shortlisted'] + $funnel['test'] + $funnel['interview'] + $funnel['offered'] + $funnel['joined'],
                    $funnel['test'] + $funnel['interview'] + $funnel['offered'] + $funnel['joined'],
                    $funnel['interview'] + $funnel['offered'] + $funnel['joined'],
                    $funnel['offered'] + $funnel['joined'],
                    $funnel['joined'],
                ],
                'stages' => array_map(
                    static fn (string $key, string $label): array => [
                        'key'   => $key,
                        'label' => $label,
                        'count' => $funnel[$key],
                    ],
                    array_keys(self::FUNNEL_LABELS),
                    array_values(self::FUNNEL_LABELS)
                ),
            ],
            'offerStatus' => [
                'labels' => ['Single Offer', 'Multiple Offers', 'No Offer Yet'],
                'values' => [$singleOffer, $multipleOffers, $noOfferYet],
            ],
            'candidates' => $this->buildCandidateRows($entries, $candidateLimit),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $students
     * @return array<int, array<string, mixed>>
     */
    private function collectPipelineEntries(array $students): array
    {
        $officer = new OfficerDataService();
        $userModel = new UserModel();
        $entries = [];

        foreach ($students as $student) {
            $sid = (string) ($student['_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $studentName = trim((string) ($user['name'] ?? ''));
            if ($studentName === '') {
                $studentName = 'Student';
            }

            foreach ($officer->buildStudentPipeline($student) as $pipeline) {
                $status = strtolower((string) ($pipeline['status'] ?? ''));
                if (in_array($status, ['rejected', 'withdrawn'], true)) {
                    continue;
                }
                $entries[] = array_merge($pipeline, [
                    'student'        => $studentName,
                    'studentId'      => $sid,
                    'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                    '_student'       => $student,
                ]);
            }
        }

        usort($entries, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['appliedAt'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['appliedAt'] ?? '')) ?: 0;

            return $bTime <=> $aTime;
        });

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $student
     */
    private function pipelineEntryBucket(array $entry, array $student): string
    {
        $status = strtolower((string) ($entry['status'] ?? ''));
        $stage = (string) ($entry['stage'] ?? '');
        $source = (string) ($entry['source'] ?? '');
        $placed = ($student['placed'] ?? false) === true;

        if (in_array($status, ['rejected', 'withdrawn'], true)) {
            return 'excluded';
        }
        if ($placed && in_array($status, ['placed', 'selected', 'approved'], true)) {
            return 'joined';
        }
        if ($status === 'placed') {
            return 'joined';
        }
        if ($status === 'selected' || ($source === 'recruitment_result' && $status !== 'rejected')) {
            return $placed ? 'joined' : 'offered';
        }
        if ($status === 'shortlisted' || $stage === 'company_selection') {
            return 'interview';
        }
        if (in_array($status, ['officer_approved', 'company_review', 'pending', 'approved'], true)
            || $stage === 'self_reported'
            || $stage === 'approval') {
            return 'shortlisted';
        }
        if ($stage === 'resume_verification' || $status === 'applied') {
            return 'applied';
        }
        if ($source === 'history') {
            return $placed ? 'joined' : 'offered';
        }

        return 'applied';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryCountsAsOffer(array $entry): bool
    {
        $status = strtolower((string) ($entry['status'] ?? ''));
        $source = (string) ($entry['source'] ?? '');

        if ($status === 'rejected' || $status === 'withdrawn') {
            return false;
        }
        if ($status === 'placed') {
            return true;
        }
        if ($status === 'selected') {
            return true;
        }
        if ($source === 'recruitment_result') {
            return true;
        }
        if ($source === 'self_placement' && in_array($status, ['approved', 'placed'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidateRows(array $entries, int $limit): array
    {
        $rows = [];
        foreach (array_slice($entries, 0, $limit) as $entry) {
            $student = is_array($entry['_student'] ?? null) ? $entry['_student'] : [];
            $status = strtolower((string) ($entry['status'] ?? ''));
            $stageLabel = $this->entryStageLabel($entry, $student);
            $badge = match (true) {
                $status === 'placed' || (!empty($student['placed']) && in_array($status, ['selected', 'approved'], true)) => 'success',
                $status === 'selected' || ($entry['source'] ?? '') === 'recruitment_result' => 'success',
                $status === 'rejected' => 'danger',
                in_array($status, ['shortlisted', 'officer_approved', 'company_review', 'pending', 'approved'], true) => 'info',
                default => 'muted',
            };

            $updatedAt = $entry['appliedAt'] ?? null;
            if (is_array($updatedAt)) {
                $serialized = DocumentHelper::serialize(['at' => $updatedAt]);
                $updatedAt = $serialized['at'] ?? null;
            }

            $rows[] = [
                'applicationId' => (string) ($entry['id'] ?? ''),
                'student'       => (string) ($entry['student'] ?? 'Student'),
                'registerNumber'=> (string) ($entry['registerNumber'] ?? ''),
                'company'       => (string) ($entry['company'] ?? ''),
                'role'          => (string) ($entry['role'] ?? ''),
                'stage'         => $stageLabel,
                'updatedAt'     => $updatedAt,
                'status'        => $status,
                'badge'         => $badge,
                'source'        => (string) ($entry['source'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $student
     */
    private function entryStageLabel(array $entry, array $student): string
    {
        $status = strtolower((string) ($entry['status'] ?? ''));
        $source = (string) ($entry['source'] ?? '');
        $placed = ($student['placed'] ?? false) === true;

        if ($placed && in_array($status, ['placed', 'selected', 'approved'], true)) {
            return 'Joined';
        }
        if ($source === 'recruitment_result' && $status === 'selected') {
            return 'Offer Released';
        }
        if ($source === 'self_placement') {
            return match ($status) {
                'pending'  => 'Self-placement (pending)',
                'approved' => 'Self-placement (approved)',
                'placed'   => 'Joined (self-placement)',
                default    => 'Self-placement',
            };
        }
        if ($source === 'history') {
            return $placed ? 'Joined' : 'Placement recorded';
        }

        return match ($status) {
            'selected' => 'Offer Released',
            'shortlisted' => 'Shortlisted',
            'officer_approved' => 'Officer Approved',
            'company_review' => 'Under Review',
            'applied', 'resume_pending', 'resume_verified' => 'Applied',
            'pending' => 'Pending',
            'approved' => 'Approved',
            'placed' => 'Joined',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
