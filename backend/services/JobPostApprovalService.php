<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AlumniJobPostModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Approves alumni/staff job posts and publishes them as department drives.
 */
final class JobPostApprovalService
{
    private AlumniJobPostModel $posts;
    private CompanyModel $companies;
    private DriveModel $drives;
    private DepartmentModel $departments;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->posts = new AlumniJobPostModel();
        $this->companies = new CompanyModel();
        $this->drives = new DriveModel();
        $this->departments = new DepartmentModel();
        $this->notifications = new NotificationService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPending(array $ctx): array
    {
        $all = $this->posts->findAll([], 500);
        $rows = array_values(array_filter($all, function (array $post): bool {
            $status = strtolower((string) ($post['status'] ?? ''));
            $driveId = trim((string) ($post['driveId'] ?? ''));
            if ($driveId !== '') {
                return false;
            }
            return in_array($status, ['pending', 'open', 'reviewing'], true);
        }));

        if (empty($ctx['isAdmin'])) {
            $deptId = trim((string) ($ctx['departmentId'] ?? ''));
            $rows = array_values(array_filter(
                $rows,
                function (array $post) use ($deptId): bool {
                    if ($deptId === '') {
                        return false;
                    }
                    // Multi-department / college-wide posts are admin-only.
                    if ($this->isMultiDepartmentPost($post)) {
                        return false;
                    }
                    $postDept = trim((string) ($post['departmentId'] ?? ''));
                    return $postDept !== '' && $postDept === $deptId;
                }
            ));
        }

        usort($rows, static fn (array $a, array $b): int =>
            strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''))
        );

        return array_map(fn (array $post): array => $this->serializePost($post), $rows);
    }

    /**
     * Approve an alumni/staff job post or a company job and publish it as a drive.
     *
     * @param array<string, mixed> $reviewer
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array<string, mixed>
     */
    public function approve(string $postId, array $reviewer, array $ctx, ?string $departmentIdOverride = null): array
    {
        if (!Security::isValidId($postId)) {
            Response::error('Invalid job post id.', 400);
        }

        $alumniPost = $this->posts->findById($postId);
        if ($alumniPost) {
            return $this->approveAlumniOrStaffPost($alumniPost, $reviewer, $ctx, $departmentIdOverride);
        }

        $companyJob = (new \PMS\Models\JobModel())->findById($postId);
        if ($companyJob) {
            return $this->approveCompanyJob($companyJob, $reviewer, $ctx, $departmentIdOverride);
        }

        Response::notFound('Job post not found.');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $reviewer
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array<string, mixed>
     */
    private function approveAlumniOrStaffPost(array $post, array $reviewer, array $ctx, ?string $departmentIdOverride): array
    {
        $this->assertCanReview($post, $ctx, $departmentIdOverride);

        $status = strtolower((string) ($post['status'] ?? ''));
        if (!in_array($status, ['pending', 'open', 'reviewing'], true)) {
            Response::error('This job post cannot be approved.', 422);
        }

        if (!empty($post['driveId'])) {
            Response::error('This job post is already linked to a drive.', 409);
        }

        $resolved = $this->resolveDepartmentForPublish($post, $ctx, $departmentIdOverride);
        $departmentId = $resolved['departmentId'];
        $department = $resolved['department'];
        $branchCodes = $resolved['branchCodes'];

        $companyName = trim((string) ($post['company'] ?? ''));
        if ($companyName === '') {
            Response::error('Job post is missing a company name.', 422);
        }

        $companyId = $this->resolveOrCreateCompany($companyName);
        $title = trim((string) ($post['title'] ?? 'Job opening'));
        $date = date('Y-m-d');
        $time = '10:00';
        $eligibility = is_array($post['eligibility'] ?? null) ? $post['eligibility'] : [];
        if ($branchCodes !== []) {
            $eligibility['branches'] = $branchCodes;
        }
        if ($departmentId !== '') {
            $eligibility['departments'] = [$departmentId];
        }

        $dup = $this->drives->findDuplicateDrive($companyId, $title, $date, null, $departmentId);
        if ($dup !== null) {
            Response::error(
                'A drive for this company, role, and date already exists for this department.',
                409,
                ['existingId' => (string) ($dup['_id'] ?? '')]
            );
        }

        $driveInput = [
            'title' => $title,
            'companyId' => $companyId,
            'type' => 'direct',
            'date' => $date,
            'time' => $time,
            'branches' => $branchCodes,
            'eligibility' => $eligibility,
            'tier' => 'Tier 2',
            'departmentId' => $departmentId !== '' ? $departmentId : null,
            'jdFile' => null,
        ];
        if (!empty($post['posterUrl']) && ($post['posterType'] ?? '') === 'pdf') {
            $driveInput['jdFile'] = (string) $post['posterUrl'];
        }

        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $driveId = $this->drives->createDrive($driveInput, $reviewerId);
        $postId = (string) ($post['_id'] ?? '');

        $patch = [
            'status' => 'open',
            'driveId' => Security::toObjectId($driveId),
            'approvedBy' => $reviewerId !== '' ? Security::toObjectId($reviewerId) : null,
            'approvedAt' => DocumentHelper::now(),
            'rejectedReason' => '',
        ];
        if ($departmentId !== '' && empty($post['departmentId'])) {
            $patch['departmentId'] = Security::toObjectId($departmentId);
        }
        $this->posts->update($postId, $patch);

        $this->announcePublishedDrive($title, $date, $departmentId, $department);

        $ownerId = (string) ($post['ownerUserId'] ?? $post['alumniUserId'] ?? '');
        if ($ownerId !== '' && Security::isValidId($ownerId)) {
            $this->notifications->notifyUser(
                $ownerId,
                'job_post',
                'Job post approved',
                "Your job post \"{$title}\" was approved and published as a placement drive.",
                ['jobPostId' => $postId, 'driveId' => $driveId]
            );
        }

        $updated = $this->posts->findById($postId) ?? $post;
        return [
            'driveId' => $driveId,
            'sourceType' => strtolower((string) ($post['sourceType'] ?? 'alumni')),
            'post' => $this->serializePost($updated),
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $reviewer
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array<string, mixed>
     */
    private function approveCompanyJob(array $job, array $reviewer, array $ctx, ?string $departmentIdOverride): array
    {
        if (empty($ctx['isAdmin'])) {
            // Officers may only publish company jobs that resolve to their single department.
            if ($this->isMultiDepartmentPost($job)) {
                Response::forbidden('Only admin can publish multi-department company jobs as drives.');
            }
            $officerDept = trim((string) ($ctx['departmentId'] ?? ''));
            $jobDept = trim((string) ($job['departmentId'] ?? ''));
            if ($jobDept === '') {
                $eligibility = is_array($job['eligibility'] ?? null) ? $job['eligibility'] : [];
                $deps = $eligibility['departments'] ?? [];
                if (is_array($deps) && isset($deps[0])) {
                    $jobDept = trim((string) $deps[0]);
                }
            }
            if ($officerDept === '' || $jobDept === '' || $jobDept !== $officerDept) {
                Response::forbidden('You can only publish job posts for your department.');
            }
        }

        if (!empty($job['driveId'])) {
            Response::error('This job is already linked to a drive.', 409);
        }

        $status = strtolower((string) ($job['status'] ?? 'open'));
        if (!in_array($status, ['open', 'ongoing', 'reviewing', 'pending'], true)) {
            Response::error('This job cannot be published as a drive.', 422);
        }

        $resolved = $this->resolveDepartmentForPublish($job, $ctx, $departmentIdOverride);
        $departmentId = $resolved['departmentId'];
        $department = $resolved['department'];
        $branchCodes = $resolved['branchCodes'];
        if ($branchCodes === []) {
            $eligibility = is_array($job['eligibility'] ?? null) ? $job['eligibility'] : [];
            $rawBranches = $eligibility['branches'] ?? $job['branches'] ?? [];
            if (is_string($rawBranches)) {
                $rawBranches = preg_split('/[,;]+/', $rawBranches) ?: [];
            }
            if (is_array($rawBranches)) {
                $branchCodes = array_values(array_unique(array_filter(array_map(
                    static fn (mixed $v): string => strtoupper(trim((string) $v)),
                    $rawBranches
                ))));
            }
        }

        $companyId = (string) ($job['companyId'] ?? '');
        if ($companyId === '' || !$this->companies->findById($companyId)) {
            $companyName = trim((string) ($job['companyName'] ?? ''));
            if ($companyName === '') {
                Response::error('Company job is missing a valid company.', 422);
            }
            $companyId = $this->resolveOrCreateCompany($companyName);
        }

        $title = trim((string) ($job['title'] ?? 'Job opening'));
        $date = date('Y-m-d');
        $time = '10:00';
        $eligibility = is_array($job['eligibility'] ?? null) ? $job['eligibility'] : [];
        if ($branchCodes !== []) {
            $eligibility['branches'] = $branchCodes;
        }
        if ($departmentId !== '') {
            $eligibility['departments'] = [$departmentId];
        }

        $dup = $this->drives->findDuplicateDrive($companyId, $title, $date, null, $departmentId);
        if ($dup !== null) {
            Response::error(
                'A drive for this company, role, and date already exists for this department.',
                409,
                ['existingId' => (string) ($dup['_id'] ?? '')]
            );
        }

        $driveInput = [
            'title' => $title,
            'companyId' => $companyId,
            'type' => 'direct',
            'date' => $date,
            'time' => $time,
            'branches' => $branchCodes,
            'eligibility' => $eligibility,
            'tier' => 'Tier 2',
            'departmentId' => $departmentId !== '' ? $departmentId : null,
            'jdFile' => $job['jdFile'] ?? null,
        ];
        if (empty($driveInput['jdFile']) && !empty($job['posterUrl']) && ($job['posterType'] ?? '') === 'pdf') {
            $driveInput['jdFile'] = (string) $job['posterUrl'];
        }

        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $driveId = $this->drives->createDrive($driveInput, $reviewerId);
        $jobId = (string) ($job['_id'] ?? '');
        (new \PMS\Models\JobModel())->update($jobId, [
            'driveId' => Security::toObjectId($driveId),
            'status' => 'open',
        ]);

        $this->announcePublishedDrive($title, $date, $departmentId, $department);

        return [
            'driveId' => $driveId,
            'sourceType' => 'company',
            'post' => [
                'id' => $jobId,
                'title' => $title,
                'driveId' => $driveId,
                'status' => 'open',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array{departmentId:string,department:?array,branchCodes:string[]}
     */
    private function resolveDepartmentForPublish(array $item, array $ctx, ?string $departmentIdOverride): array
    {
        $override = trim((string) ($departmentIdOverride ?? ''));
        $fromItem = trim((string) ($item['departmentId'] ?? ''));
        if ($fromItem === '') {
            $eligibility = is_array($item['eligibility'] ?? null) ? $item['eligibility'] : [];
            $deps = $eligibility['departments'] ?? [];
            if (is_array($deps) && isset($deps[0])) {
                $fromItem = trim((string) $deps[0]);
            }
        }

        $departmentId = $override !== '' ? $override : $fromItem;
        if ($departmentId === '' && empty($ctx['isAdmin'])) {
            $departmentId = trim((string) ($ctx['departmentId'] ?? ''));
        }

        if ($departmentId === '') {
            if (!empty($ctx['isAdmin'])) {
                return ['departmentId' => '', 'department' => null, 'branchCodes' => []];
            }
            Response::error('Select a department before publishing this job as a drive.', 422);
        }

        if (empty($ctx['isAdmin']) && (string) ($ctx['departmentId'] ?? '') !== $departmentId) {
            Response::forbidden('You can only publish drives for your department.');
        }

        $department = $this->departments->findById($departmentId);
        if (!$department) {
            Response::error('Selected department is invalid.', 422);
        }

        return [
            'departmentId' => $departmentId,
            'department' => $department,
            'branchCodes' => $this->departmentBranchCodes($department),
        ];
    }

    private function announcePublishedDrive(string $title, string $date, string $departmentId, ?array $department): void
    {
        if ($departmentId === '' || $department === null) {
            $this->notifications->announceDrive($title, $date, null);
            return;
        }
        $studentIds = PlacementOfficerContext::userIdsInDepartment([
            'isAdmin' => false,
            'departmentId' => $departmentId,
            'department' => $department,
            'profile' => null,
        ]);
        $this->notifications->announceDrive($title, $date, $studentIds !== [] ? $studentIds : null);
    }

    /**
     * @param array<string, mixed> $reviewer
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array<string, mixed>
     */
    public function reject(string $postId, array $reviewer, array $ctx, string $reason = ''): array
    {
        $post = $this->requirePost($postId);
        $this->assertCanReview($post, $ctx);

        $status = strtolower((string) ($post['status'] ?? ''));
        if (!in_array($status, ['pending', 'open', 'reviewing'], true) || !empty($post['driveId'])) {
            Response::error('Only unpublished job posts can be rejected.', 422);
        }

        $reason = trim($reason);
        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $this->posts->update($postId, [
            'status' => 'rejected',
            'rejectedReason' => $reason,
            'approvedBy' => $reviewerId !== '' ? Security::toObjectId($reviewerId) : null,
            'approvedAt' => DocumentHelper::now(),
        ]);

        $title = trim((string) ($post['title'] ?? 'Job opening'));
        $ownerId = (string) ($post['ownerUserId'] ?? $post['alumniUserId'] ?? '');
        if ($ownerId !== '' && Security::isValidId($ownerId)) {
            $message = "Your job post \"{$title}\" was not approved.";
            if ($reason !== '') {
                $message .= ' Reason: ' . $reason;
            }
            $this->notifications->notifyUser(
                $ownerId,
                'job_post',
                'Job post not approved',
                $message,
                ['jobPostId' => $postId]
            );
        }

        $updated = $this->posts->findById($postId) ?? $post;
        return ['post' => $this->serializePost($updated)];
    }

    /**
     * Notify admin + department placement officer about a new pending post.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $poster
     */
    public function notifyReviewers(array $post, array $poster): void
    {
        $title = trim((string) ($post['title'] ?? 'Job opening'));
        $company = trim((string) ($post['company'] ?? 'a company'));
        $posterName = trim((string) ($poster['name'] ?? 'Someone'));
        $source = strtolower((string) ($post['sourceType'] ?? 'alumni'));
        $sourceLabel = $source === 'staff' ? 'Staff' : 'Alumni';
        $departmentId = (string) ($post['departmentId'] ?? '');
        $dept = $departmentId !== '' ? $this->departments->findById($departmentId) : null;
        $deptLabel = trim((string) ($dept['code'] ?? $dept['name'] ?? 'department'));
        $message = "{$posterName} ({$sourceLabel}) submitted \"{$title}\" at {$company} for {$deptLabel}. Approve to publish as a drive.";
        $metadata = [
            'jobPostId' => (string) ($post['_id'] ?? ''),
            'departmentId' => $departmentId,
        ];

        $this->notifications->notifyAdmins('job_post', 'Job post awaiting approval', $message, $metadata);

        if ($departmentId === '') {
            return;
        }
        $officerProfile = (new PlacementOfficerModel())->findByDepartment($departmentId);
        $officerUserId = (string) ($officerProfile['userId'] ?? '');
        if ($officerUserId === '' || !Security::isValidId($officerUserId)) {
            return;
        }
        $this->notifications->notifyUser(
            $officerUserId,
            'job_post',
            'Job post awaiting approval',
            $message,
            $metadata,
            false
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array{isAdmin:bool,departmentId:?string} $ctx
     */
    private function assertCanReview(array $post, array $ctx, ?string $departmentIdOverride = null): void
    {
        if (!empty($ctx['isAdmin'])) {
            return;
        }

        $officerDept = trim((string) ($ctx['departmentId'] ?? ''));
        if ($officerDept === '') {
            Response::forbidden('You can only review job posts for your department.');
        }

        if ($this->isMultiDepartmentPost($post)) {
            Response::forbidden('Only admin can review multi-department job posts.');
        }

        $postDept = trim((string) ($post['departmentId'] ?? ''));
        $override = trim((string) ($departmentIdOverride ?? ''));
        if ($postDept === '' || $postDept !== $officerDept) {
            Response::forbidden('You can only review job posts for your department.');
        }
        if ($override !== '' && $override !== $officerDept) {
            Response::forbidden('You can only review job posts for your department.');
        }
    }

    /** @param array<string, mixed> $post */
    private function isMultiDepartmentPost(array $post): bool
    {
        $eligibility = is_array($post['eligibility'] ?? null) ? $post['eligibility'] : [];
        $departments = $eligibility['departments'] ?? [];
        $deptIds = [];
        if (is_array($departments)) {
            foreach ($departments as $id) {
                $value = trim((string) $id);
                if ($value !== '') {
                    $deptIds[$value] = true;
                }
            }
        }
        $postDept = trim((string) ($post['departmentId'] ?? ''));
        if ($postDept !== '') {
            $deptIds[$postDept] = true;
        }
        if (count($deptIds) > 1) {
            return true;
        }

        $branches = $eligibility['branches'] ?? $post['branches'] ?? [];
        if (is_string($branches)) {
            $branches = preg_split('/[,;]+/', $branches) ?: [];
        }
        $branchCodes = [];
        if (is_array($branches)) {
            foreach ($branches as $branch) {
                $code = strtoupper(trim((string) $branch));
                if ($code !== '') {
                    $branchCodes[$code] = true;
                }
            }
        }

        // No single department and not exactly one branch => college-wide / multi (admin only).
        if ($postDept === '' && count($deptIds) === 0 && count($branchCodes) !== 1) {
            return true;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function requirePost(string $postId): array
    {
        if (!Security::isValidId($postId)) {
            Response::error('Invalid job post id.', 400);
        }
        $post = $this->posts->findById($postId);
        if (!$post) {
            Response::notFound('Job post not found.');
        }
        return $post;
    }

    private function resolveOrCreateCompany(string $companyName): string
    {
        $existing = $this->companies->findByNormalizedName($companyName);
        if ($existing) {
            return (string) ($existing['_id'] ?? '');
        }
        return $this->companies->createCompany([
            'companyName' => $companyName,
            'associationStatus' => 'pending',
            'comments' => 'Auto-created from approved job post',
            'category' => 'Software',
            'tier' => 'Tier 2',
        ]);
    }

    /**
     * @param array<string, mixed> $department
     * @return string[]
     */
    private function departmentBranchCodes(array $department): array
    {
        $codes = [];
        foreach ([$department['code'] ?? '', $department['shortName'] ?? ''] as $value) {
            $code = strtoupper(trim((string) $value));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function serializePost(array $post): array
    {
        $serialized = DocumentHelper::serialize($post) ?? [];
        $departmentId = (string) ($post['departmentId'] ?? '');
        $department = $departmentId !== '' ? $this->departments->findById($departmentId) : null;
        $ownerId = (string) ($post['ownerUserId'] ?? $post['alumniUserId'] ?? '');
        $owner = $ownerId !== '' ? (new UserModel())->findById($ownerId) : null;
        $source = strtolower((string) ($post['sourceType'] ?? 'alumni'));

        return [
            'id' => (string) ($post['_id'] ?? ''),
            'title' => trim((string) ($post['title'] ?? '')),
            'company' => trim((string) ($post['company'] ?? '')),
            'jobType' => trim((string) ($post['jobType'] ?? 'Full-time')),
            'package' => trim((string) ($post['package'] ?? '')),
            'location' => trim((string) ($post['location'] ?? '')),
            'description' => trim((string) ($post['description'] ?? '')),
            'status' => strtolower(trim((string) ($post['status'] ?? 'pending'))),
            'audience' => strtolower(trim((string) ($post['audience'] ?? 'both'))),
            'sourceType' => $source,
            'sourceLabel' => $source === 'staff' ? 'Staff' : 'Alumni',
            'departmentId' => $departmentId,
            'departmentCode' => trim((string) ($department['code'] ?? '')),
            'departmentName' => trim((string) ($department['name'] ?? '')),
            'driveId' => (string) ($post['driveId'] ?? ''),
            'posterName' => trim((string) ($owner['name'] ?? '')),
            'posterEmail' => trim((string) ($owner['email'] ?? '')),
            'posterUrl' => trim((string) ($post['posterUrl'] ?? $post['imageUrl'] ?? '')),
            'posterType' => trim((string) ($post['posterType'] ?? '')),
            'createdAt' => $serialized['createdAt'] ?? null,
            'rejectedReason' => trim((string) ($post['rejectedReason'] ?? '')),
        ];
    }
}
