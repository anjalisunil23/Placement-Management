<?php

declare(strict_types=1);

namespace PMS\Alumni;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniJobPostModel;
use PMS\Models\AlumniModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\ApplicationModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Models\SuccessStoryModel;
use PMS\Services\EligibilityEngine;
use PMS\Services\NotificationService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;

/**
 * Alumni module — profile, job posts, referrals, and eligible drive applications.
 */
final class AlumniController
{
  /** GET /api/alumni/profile */
  public function getProfile(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::notFound('Alumni profile not found.');
    }
    Response::success(DocumentHelper::serialize(AlumniModel::serializeProfile($profile)));
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
    if (!$model->updateProfile((string) $profile['_id'], $input)) {
      Response::error('No valid fields to update.', 422);
    }
    $updated = $model->findById((string) $profile['_id']);
    Response::success(DocumentHelper::serialize(AlumniModel::serializeProfile($updated ?? $profile)), 'Profile updated.');
  }

  /** GET /api/alumni/dashboard */
  public function dashboard(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $userId = (string) $user['_id'];
    $jobModel = new AlumniJobPostModel();
    $refModel = new AlumniReferralModel();

    Response::success([
      'totalPosts'       => count($jobModel->findByAlumni($userId)),
      'activePosts'      => $jobModel->countActiveByAlumni($userId),
      'viewsThisMonth'   => $jobModel->sumViewsByAlumni($userId),
      'referralsCount'   => $refModel->countByAlumni($userId),
      'referralsPending' => $refModel->countByAlumni($userId),
    ]);
  }

  /** GET /api/alumni/job-posts */
  public function listJobPosts(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $posts = (new AlumniJobPostModel())->findByAlumni((string) $user['_id']);
    Response::success(DocumentHelper::serializeMany($posts));
  }

  /** POST /api/alumni/job-posts */
  public function createJobPost(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $errors = Validator::validate($input, [
      'title'   => 'required',
      'company' => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }
    $id = (new AlumniJobPostModel())->createPost((string) $user['_id'], $input);
    Response::success(['id' => $id], 'Job posted successfully.', 201);
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
    Response::success((new AlumniReferralModel())->listEnrichedForAlumni((string) $user['_id']));
  }

  /** POST /api/alumni/jobs/refer */
  public function referJob(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $errors = Validator::validate($input, [
      'companyName'   => 'required',
      'hrName'        => 'required',
      'hrEmail'       => 'required|email',
      'contactNumber' => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }
    $id = (new AlumniReferralModel())->createReferral((string) $user['_id'], $input);
    (new NotificationService())->notifyAdmins(
      'recommendation_update',
      'New alumni company referral',
      (string) ($user['name'] ?? 'Alumni') . ' referred ' . (string) ($input['companyName'] ?? 'a company') . ' to the placement cell.',
      ['referralId' => $id]
    );
    Response::success(['id' => $id], 'Company recommended successfully.', 201);
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

  /** GET /api/alumni/notifications */
  public function notifications(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
    Response::success(DocumentHelper::serializeMany($notifs));
  }

  /** POST /api/alumni/notifications/{id}/read */
  public function markNotificationRead(string $id): void
  {
    $user = RBACMiddleware::requireAlumni();
    $notif = (new NotificationModel())->findById($id);
    if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
      Response::notFound();
    }
    (new NotificationModel())->markRead($id);
    Response::success(null, 'Notification marked as read.');
  }

  /** POST /api/alumni/notifications/read-all */
  public function markAllNotificationsRead(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $count = (new NotificationModel())->markAllRead((string) $user['_id']);
    Response::success(['updated' => $count], 'All notifications marked as read.');
  }

  /** GET /api/alumni/success-stories */
  public function listSuccessStories(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $rows = (new SuccessStoryModel())->findByAlumni((string) $user['_id']);
    Response::success(DocumentHelper::serializeMany($rows));
  }

  /** POST /api/alumni/success-stories */
  public function createSuccessStory(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $errors = Validator::validate($input, [
      'company' => 'required',
      'role'    => 'required',
      'quote'   => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
    $company = trim((string) ($input['company'] ?? ''));
    if ($company === '' && $profile) {
      $company = trim((string) ($profile['company'] ?? ''));
    }
    $role = trim((string) ($input['role'] ?? ''));
    if ($role === '' && $profile) {
      $role = trim((string) ($profile['role'] ?? ''));
    }

    $id = (new SuccessStoryModel())->createStory(
      (string) $user['_id'],
      (string) ($user['name'] ?? 'Alumni'),
      [
        'name'    => trim((string) ($input['name'] ?? $user['name'] ?? '')),
        'company' => $company,
        'role'    => $role,
        'package' => trim((string) ($input['package'] ?? '')),
        'quote'   => trim((string) ($input['quote'] ?? '')),
      ]
    );
    Response::success(['id' => $id], 'Success story published on the public portal.', 201);
  }

  /** PUT /api/alumni/success-stories/{id} */
  public function updateSuccessStory(string $id): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $model = new SuccessStoryModel();
    if (!$model->updateStory($id, (string) $user['_id'], $input)) {
      Response::error('Story not found or no valid fields to update.', 404);
    }
    $updated = $model->findById($id);
    Response::success(DocumentHelper::serialize($updated ?? []), 'Success story updated.');
  }

  /** DELETE /api/alumni/success-stories/{id} */
  public function deleteSuccessStory(string $id): void
  {
    $user = RBACMiddleware::requireAlumni();
    if (!(new SuccessStoryModel())->deleteStory($id, (string) $user['_id'])) {
      Response::notFound('Story not found.');
    }
    Response::success(null, 'Success story removed.');
  }
}
