<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

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
        $apps = $this->loadApplications($departmentId);
        $studentCache = [];
        $funnel = array_fill_keys(array_keys(self::FUNNEL_LABELS), 0);
        $selectedByStudent = [];

        foreach ($apps as $app) {
            $status = (string) ($app['status'] ?? 'applied');
            if (in_array($status, ['rejected', 'withdrawn'], true)) {
                continue;
            }

            $bucket = $this->funnelBucket($status, $app, $studentCache);
            if (isset($funnel[$bucket])) {
                $funnel[$bucket]++;
            }

            if ($status === 'selected') {
                $sid = (string) ($app['studentId'] ?? '');
                if ($sid !== '') {
                    $selectedByStudent[$sid] = ($selectedByStudent[$sid] ?? 0) + 1;
                }
            }
        }

        $activeStudentIds = [];
        foreach ($apps as $app) {
            $status = (string) ($app['status'] ?? 'applied');
            if (!in_array($status, ['rejected', 'withdrawn'], true)) {
                $sid = (string) ($app['studentId'] ?? '');
                if ($sid !== '') {
                    $activeStudentIds[$sid] = true;
                }
            }
        }

        $singleOffer = 0;
        $multipleOffers = 0;
        $noOfferYet = 0;
        foreach (array_keys($activeStudentIds) as $sid) {
            $count = $selectedByStudent[$sid] ?? 0;
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
            'candidates' => $this->buildCandidateRows($apps, $candidateLimit),
        ];
    }

    /**
     * @param array<string, array<string, mixed>|null> $studentCache
     */
    private function funnelBucket(string $status, array $app, array &$studentCache): string
    {
        if ($status === 'selected') {
            $student = $this->student((string) ($app['studentId'] ?? ''), $studentCache);
            if ($student && !empty($student['placed'])) {
                return 'joined';
            }
            return 'offered';
        }

        return match ($status) {
            'applied', 'resume_pending', 'resume_verified' => 'applied',
            'officer_approved' => 'shortlisted',
            'company_review' => 'test',
            'shortlisted' => 'interview',
            default => 'applied',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadApplications(?string $departmentId): array
    {
        $appModel = new ApplicationModel();
        if ($departmentId === null || $departmentId === '') {
            return $appModel->findAll([], 5000);
        }

        $studentIds = [];
        $deptOid = Security::toObjectId($departmentId);
        if ($deptOid === null) {
            return [];
        }

        foreach ((new StudentModel())->findAll(['departmentId' => $deptOid], 5000) as $student) {
            $studentIds[] = $student['_id'];
        }
        if ($studentIds === []) {
            return [];
        }

        return $appModel->findAll(['studentId' => ['$in' => $studentIds]], 5000);
    }

    /**
     * @param array<int, array<string, mixed>> $apps
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidateRows(array $apps, int $limit): array
    {
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $companyModel = new CompanyModel();
        $driveModel = new DriveModel();
        $jobModel = new JobModel();
        $departmentModel = new DepartmentModel();
        $deptCodeCache = [];

        usort($apps, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['updatedAt'] ?? $a['createdAt'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['updatedAt'] ?? $b['createdAt'] ?? '')) ?: 0;
            return $bTime <=> $aTime;
        });

        $rows = [];
        foreach (array_slice($apps, 0, $limit) as $app) {
            $student = $studentModel->findById((string) ($app['studentId'] ?? ''));
            $user = $student ? $userModel->findById((string) ($student['userId'] ?? '')) : null;
            $company = $companyModel->findById((string) ($app['companyId'] ?? ''));
            $drive = $driveModel->findById((string) ($app['driveId'] ?? ''));
            if (!$company && $drive) {
                $company = $companyModel->findById((string) ($drive['companyId'] ?? ''));
            }
            $job = !empty($app['jobId']) ? $jobModel->findById((string) $app['jobId']) : null;

            $status = (string) ($app['status'] ?? 'applied');
            $stageLabel = $this->stageLabel($status, $student);
            $deptId = (string) ($student['departmentId'] ?? '');
            if ($deptId !== '' && !isset($deptCodeCache[$deptId])) {
                $dept = $departmentModel->findById($deptId);
                $deptCodeCache[$deptId] = (string) ($dept['code'] ?? $dept['name'] ?? '');
            }
            $badge = match ($status) {
                'selected' => !empty($student['placed']) ? 'success' : 'success',
                'rejected' => 'danger',
                'shortlisted', 'company_review', 'officer_approved' => 'info',
                default => 'muted',
            };

            $rows[] = [
                'applicationId' => (string) ($app['_id'] ?? ''),
                'student'       => (string) ($user['name'] ?? 'Student'),
                'registerNumber'=> (string) ($student['registerNumber'] ?? ''),
                'department'    => $deptCodeCache[$deptId] ?? '',
                'cgpa'          => (float) ($student['academic']['cgpa'] ?? $student['cgpa'] ?? 0),
                'company'       => (string) ($company['companyName'] ?? ''),
                'role'          => (string) ($job['title'] ?? $drive['title'] ?? ''),
                'stage'         => $stageLabel,
                'updatedAt'     => DocumentHelper::serialize($app)['updatedAt'] ?? null,
                'status'        => $status,
                'badge'         => $badge,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $student
     */
    private function stageLabel(string $status, ?array $student): string
    {
        if ($status === 'selected') {
            return !empty($student['placed']) ? 'Joined' : 'Offer Released';
        }

        return match ($status) {
            'applied', 'resume_pending', 'resume_verified' => 'Applied',
            'officer_approved' => 'Officer Approved',
            'company_review' => 'Under Review',
            'shortlisted' => 'Shortlisted',
            'rejected' => 'Rejected',
            'withdrawn' => 'Withdrawn',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @param array<string, array<string, mixed>|null> $cache
     * @return array<string, mixed>|null
     */
    private function student(string $studentId, array &$cache): ?array
    {
        if (!isset($cache[$studentId])) {
            $cache[$studentId] = (new StudentModel())->findById($studentId);
        }
        return $cache[$studentId];
    }
}
