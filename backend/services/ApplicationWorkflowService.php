<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Schemas\Collections;
use PMS\Utils\Response;

/**
 * Enforces application status transitions across the placement workflow.
 */
final class ApplicationWorkflowService
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        'applied'          => ['resume_verified', 'officer_approved', 'company_review', 'shortlisted', 'selected', 'rejected', 'withdrawn'],
        'resume_pending'   => ['resume_verified', 'officer_approved', 'company_review', 'shortlisted', 'selected', 'rejected', 'withdrawn'],
        'resume_verified'  => ['officer_approved', 'company_review', 'shortlisted', 'selected', 'rejected', 'withdrawn'],
        'officer_approved' => ['company_review', 'shortlisted', 'selected', 'rejected', 'withdrawn'],
        'company_review'   => ['shortlisted', 'selected', 'rejected', 'withdrawn'],
        'shortlisted'      => ['selected', 'rejected', 'withdrawn'],
        'selected'         => [],
        'rejected'         => [],
        'withdrawn'        => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function transition(
        string $applicationId,
        string $newStatus,
        string $by,
        string $remarks = '',
        bool $terminateOnError = true
    ): bool {
        $model = new ApplicationModel();
        $app = $model->findById($applicationId);
        if (!$app) {
            if ($terminateOnError) {
                Response::notFound('Application not found.');
            }
            return false;
        }

        $current = $app['status'] ?? 'applied';
        if (!$this->canTransition($current, $newStatus)) {
            if ($terminateOnError) {
                Response::error(
                    "Cannot transition from '{$current}' to '{$newStatus}'.",
                    422
                );
            }
            return false;
        }

        if (!in_array($newStatus, Collections::APPLICATION_STATUS, true)) {
            if ($terminateOnError) {
                Response::error('Invalid application status.', 400);
            }
            return false;
        }

        return $model->updateStatus($applicationId, $newStatus, $by, $remarks);
    }

    /**
     * Set status without intermediate transition checks (document import / admin correction).
     */
    public function forceStatus(
        string $applicationId,
        string $newStatus,
        string $by,
        string $remarks = ''
    ): bool {
        if (!in_array($newStatus, Collections::APPLICATION_STATUS, true)) {
            return false;
        }

        $model = new ApplicationModel();
        if (!$model->findById($applicationId)) {
            return false;
        }

        return $model->updateStatus($applicationId, $newStatus, $by, $remarks);
    }

    /**
     * Admin-recorded final outcome — bypasses intermediate workflow states.
     */
    public function forceFinalStatus(
        string $applicationId,
        string $newStatus,
        string $by,
        string $remarks = ''
    ): bool {
        if (!in_array($newStatus, ['selected', 'rejected'], true)) {
            return false;
        }

        $model = new ApplicationModel();
        if (!$model->findById($applicationId)) {
            return false;
        }

        return $model->updateStatus($applicationId, $newStatus, $by, $remarks);
    }

    /**
     * After admin verifies resume, advance pending applications for this student.
     */
    public function onResumeVerified(string $studentId, string $by): void
    {
        $model = new ApplicationModel();
        $apps = $model->findByStudent($studentId);
        foreach ($apps as $app) {
            $status = $app['status'] ?? '';
            if (in_array($status, ['applied', 'resume_pending'], true)) {
                $this->transition((string) $app['_id'], 'resume_verified', $by, 'Resume verified by admin', false);
            }
        }
    }

    /**
     * When student uploads/re-applies resume, mark applications as awaiting verification.
     */
    public function onResumeUploaded(string $studentId): void
    {
        $model = new ApplicationModel();
        foreach ($model->findByStudent($studentId) as $app) {
            if (($app['status'] ?? '') === 'resume_verified') {
                // Re-upload resets to applied pending re-verification
                $model->updateStatus((string) $app['_id'], 'applied', $studentId, 'Resume re-uploaded');
            }
        }
    }
}
