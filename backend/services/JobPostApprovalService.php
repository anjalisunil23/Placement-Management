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
        $rows = $this->posts->findAll(['status' => 'pending'], 300);
        if (!$ctx['isAdmin']) {
            $deptId = (string) ($ctx['departmentId'] ?? '');
            $rows = array_values(array_filter(
                $rows,
                static fn (array $post): bool => (string) ($post['departmentId'] ?? '') === $deptId
            ));
        }

        usort($rows, static fn (array $a, array $b): int =>
            strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''))
        );

        return array_map(fn (array $post): array => $this->serializePost($post), $rows);
    }

    /**
     * @param array<string, mixed> $reviewer
     * @param array{isAdmin:bool,departmentId:?string,department:?array} $ctx
     * @return array<string, mixed>
     */
    public function approve(string $postId, array $reviewer, array $ctx): array
    {
        $post = $this->requirePost($postId);
        $this->assertCanReview($post, $ctx);

        if (strtolower((string) ($post['status'] ?? '')) !== 'pending') {
            Response::error('Only pending job posts can be approved.', 422);
        }

        if (!empty($post['driveId'])) {
            Response::error('This job post is already linked to a drive.', 409);
        }

        $departmentId = (string) ($post['departmentId'] ?? '');
        $department = $departmentId !== '' ? $this->departments->findById($departmentId) : null;
        if (!$department) {
            Response::error('Job post is missing a valid department.', 422);
        }

        $companyName = trim((string) ($post['company'] ?? ''));
        if ($companyName === '') {
            Response::error('Job post is missing a company name.', 422);
        }

        $companyId = $this->resolveOrCreateCompany($companyName);
        $branchCodes = $this->departmentBranchCodes($department);
        $title = trim((string) ($post['title'] ?? 'Job opening'));
        $date = date('Y-m-d');
        $time = '10:00';
        $eligibility = is_array($post['eligibility'] ?? null) ? $post['eligibility'] : [];
        $eligibility['branches'] = $branchCodes;
        $eligibility['departments'] = [$departmentId];

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
            'departmentId' => $departmentId,
            'jdFile' => null,
        ];
        if (!empty($post['posterUrl']) && ($post['posterType'] ?? '') === 'pdf') {
            $driveInput['jdFile'] = (string) $post['posterUrl'];
        }

        $reviewerId = (string) ($reviewer['_id'] ?? '');
        $driveId = $this->drives->createDrive($driveInput, $reviewerId);

        $this->posts->update($postId, [
            'status' => 'open',
            'driveId' => Security::toObjectId($driveId),
            'approvedBy' => $reviewerId !== '' ? Security::toObjectId($reviewerId) : null,
            'approvedAt' => DocumentHelper::now(),
            'rejectedReason' => '',
        ]);

        $studentIds = PlacementOfficerContext::userIdsInDepartment([
            'isAdmin' => false,
            'departmentId' => $departmentId,
            'department' => $department,
            'profile' => null,
        ]);
        $this->notifications->announceDrive($title, $date, $studentIds !== [] ? $studentIds : null);

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
            'post' => $this->serializePost($updated),
        ];
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

        if (strtolower((string) ($post['status'] ?? '')) !== 'pending') {
            Response::error('Only pending job posts can be rejected.', 422);
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
    private function assertCanReview(array $post, array $ctx): void
    {
        if (!empty($ctx['isAdmin'])) {
            return;
        }
        $postDept = (string) ($post['departmentId'] ?? '');
        $officerDept = (string) ($ctx['departmentId'] ?? '');
        if ($postDept === '' || $officerDept === '' || $postDept !== $officerDept) {
            Response::forbidden('You can only review job posts for your department.');
        }
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
