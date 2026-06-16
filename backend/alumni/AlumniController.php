<?php

declare(strict_types=1);

namespace PMS\Alumni;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\ApplicationModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Services\EligibilityEngine;
use PMS\Services\NotificationService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;

/**
 * Alumni module — profile, referrals, and eligible drive applications.
 */
final class AlumniController
{
    /** GET /api/alumni/profile */
    public function getProfile(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
        Response::success(DocumentHelper::serialize($profile));
    }

    /** PUT /api/alumni/profile */
    public function updateProfile(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $model = new AlumniModel();
        $profile = $model->findByUserId((string) $user['_id']);
        if (!$profile) {
            Response::notFound();
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $model->update((string) $profile['_id'], $input);
        Response::success(null, 'Profile updated.');
    }

    /** GET /api/alumni/jobs */
    public function listJobs(): void
    {
        RBACMiddleware::requireAlumni();
        Response::success(DocumentHelper::serializeMany((new JobModel())->findAll([], 100)));
    }

    /** GET /api/alumni/referrals */
    public function listReferrals(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $refs = (new AlumniReferralModel())->findByAlumni((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($refs));
    }

    /** POST /api/alumni/jobs/refer */
    public function referJob(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'jobTitle'    => 'required',
            'companyName' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }
        $id = (new AlumniReferralModel())->createReferral((string) $user['_id'], $input);
        Response::success(['id' => $id], 'Job referral submitted.', 201);
    }

    /** GET /api/alumni/drives */
    public function listDrives(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $student = (new StudentModel())->findByUserId((string) $user['_id']);
        if (!$student) {
            Response::success([], 'No linked student profile — drives unavailable.');
            return;
        }
        $drives = (new DriveModel())->findAll(['status' => ['$ne' => 'completed']], 50);
        $engine = new EligibilityEngine();
        $studentId = (string) $student['_id'];
        $result = array_map(function ($drive) use ($engine, $studentId) {
            $serialized = DocumentHelper::serialize($drive);
            $serialized['eligibility'] = $engine->checkForDrive($studentId, (string) $drive['_id']);
            return $serialized;
        }, $drives);
        Response::success($result);
    }

    /** POST /api/alumni/apply */
    public function apply(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $student = (new StudentModel())->findByUserId((string) $user['_id']);
        if (!$student) {
            Response::error('Alumni must have a linked student profile to apply.', 403);
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, ['driveId' => 'required']);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $studentId = (string) $student['_id'];
        $driveId = $input['driveId'];
        $check = (new EligibilityEngine())->checkForDrive($studentId, $driveId);
        if (!$check['eligible']) {
            Response::error('Not eligible: ' . implode(' ', $check['reasons']), 403);
        }

        $drive = (new DriveModel())->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }

        $appModel = new ApplicationModel();
        if ($appModel->findByStudentAndDrive($studentId, $driveId)) {
            Response::error('Already applied to this drive.', 409);
        }

        $appId = $appModel->createApplication([
            'studentId' => $studentId,
            'driveId'   => $driveId,
            'companyId' => (string) $drive['companyId'],
            'status'    => ($student['resume']['verified'] ?? false) ? 'resume_verified' : 'applied',
        ]);

        (new NotificationService())->notifyUser(
            (string) $user['_id'],
            'application_submitted',
            'Application Submitted',
            'Your alumni drive application has been submitted.',
            ['applicationId' => $appId]
        );

        Response::success(['applicationId' => $appId], 'Application submitted.', 201);
    }
}
