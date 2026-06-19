<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\AuthMiddleware;
use PMS\Middleware\RBACMiddleware;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\EligibilityEngine;
use PMS\Services\NotificationService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;
use PMS\Utils\Validator;

/**
 * Student module — profile, resume, applications.
 */
final class StudentController
{
  private StudentModel $studentModel;

  public function __construct()
  {
    $this->studentModel = new StudentModel();
  }

  private function getStudentProfile(array $user): array
  {
    $profile = $this->studentModel->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::notFound('Student profile not found.');
    }
    return $profile;
  }

  /** GET /api/student/profile */
  public function getProfile(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    Response::success(DocumentHelper::serialize($profile));
  }

  /** PUT /api/student/profile */
  public function updateProfile(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

    $allowed = ['personal', 'academic', 'certifications'];
    $update = [];
    foreach ($allowed as $key) {
      if (isset($input[$key])) {
        $update[$key] = $input[$key];
      }
    }

    $this->studentModel->update((string) $profile['_id'], $update);
    Response::success(null, 'Profile updated.');
  }

  /** POST /api/student/policy/accept */
  public function acceptPolicy(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $this->studentModel->update((string) $profile['_id'], ['policyAccepted' => true]);
    Response::success(null, 'Placement policy accepted.');
  }

  /** POST /api/student/resume */
  public function uploadResume(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    if (!isset($_FILES['resume'])) {
      Response::error('Resume file required.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES['resume'],
      $config['uploads']['max_resume'],
      Security::allowedResumeExtensions()
    );
    if ($error) {
      Response::error($error, 400);
    }

    $registerNo = $profile['registerNumber'] ?? '';
    if (!Security::validateResumeFilename($_FILES['resume']['name'], $user['name'], $registerNo)) {
      Response::error(
        "Resume must be named: {Name}_{RegisterNo}_Resume.pdf (e.g. Student_{$registerNo}_Resume.pdf)",
        400
      );
    }

    $dir = $config['uploads']['resume_dir'];
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $filename = basename($_FILES['resume']['name']);
    $path = $dir . '/' . $registerNo . '_' . time() . '_' . $filename;

    if (!move_uploaded_file($_FILES['resume']['tmp_name'], $path)) {
      Response::error('Failed to save resume.', 500);
    }

    $this->studentModel->update((string) $profile['_id'], [
      'resume' => [
        'filename'   => $filename,
        'path'       => $path,
        'verified'   => false,
        'uploadedAt' => DocumentHelper::now(),
      ],
    ]);

    (new ApplicationWorkflowService())->onResumeUploaded((string) $profile['_id']);

    Response::success(['filename' => $filename], 'Resume uploaded. Awaiting verification.');
  }

  /** GET /api/student/jobs */
  public function listJobs(): void
  {
    RBACMiddleware::requireStudent();
    $jobs = (new JobModel())->findAll([], 100);
    Response::success(DocumentHelper::serializeMany($jobs));
  }

  /** GET /api/student/drives */
  public function listDrives(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $drives = (new DriveModel())->findAll(['status' => ['$ne' => 'completed']], 50);
    $engine = new EligibilityEngine();
    $studentId = (string) $profile['_id'];
    $companyModel = new CompanyModel();
    $appModel = new ApplicationModel();

    $result = array_map(function ($drive) use ($engine, $studentId, $companyModel, $appModel) {
      $eligibility = $engine->checkForDrive($studentId, (string) $drive['_id']);
      $serialized = DocumentHelper::serialize($drive);
      $serialized['eligibility'] = $eligibility;

      $company = $companyModel->findById((string) ($drive['companyId'] ?? ''));
      $companyName = (string) ($company['companyName'] ?? '');
      if ($companyName === '') {
        $title = (string) ($drive['title'] ?? '');
        if (str_contains($title, '—')) {
          $companyName = trim((string) (explode('—', $title, 2)[1] ?? ''));
        }
      }
      $serialized['companyName'] = $companyName;
      $serialized['applied'] = (bool) $appModel->findByStudentAndDrive($studentId, (string) $drive['_id']);

      return $serialized;
    }, $drives);

    Response::success($result);
  }

  /** POST /api/student/apply */
  public function apply(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

    $errors = Validator::validate($input, [
      'driveId' => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $studentId = (string) $profile['_id'];
    $driveId = $input['driveId'];

    $engine = new EligibilityEngine();
    $check = $engine->checkForDrive($studentId, $driveId);
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

    $resume = null;
    if (!empty($input['resumePath']) || !empty($input['resumeFileName'])) {
      $resume = [
        'resumeId'   => (string) ($input['resumeId'] ?? ''),
        'label'      => (string) ($input['resumeLabel'] ?? ''),
        'fileName'   => (string) ($input['resumeFileName'] ?? ''),
        'path'       => (string) ($input['resumePath'] ?? ''),
      ];
    } elseif (!empty($profile['resume']['path'])) {
      $resume = [
        'resumeId' => '',
        'label'    => 'Uploaded resume',
        'fileName' => (string) ($profile['resume']['filename'] ?? basename((string) $profile['resume']['path'])),
        'path'     => (string) $profile['resume']['path'],
      ];
    }

    $createData = [
      'studentId' => $studentId,
      'driveId'   => $driveId,
      'companyId' => (string) $drive['companyId'],
      'jobId'     => $input['jobId'] ?? null,
      'status'    => ($profile['resume']['verified'] ?? false) ? 'resume_verified' : 'applied',
    ];
    if ($resume !== null) {
      $createData['resume'] = $resume;
    }

    $appId = $appModel->createApplication($createData);

    (new NotificationService())->notifyUser(
      (string) $user['_id'],
      'application_submitted',
      'Application Submitted',
      'Your application has been submitted and is pending resume verification.',
      ['applicationId' => $appId]
    );

    Response::success(['applicationId' => $appId], 'Application submitted.', 201);
  }

  /** POST /api/student/signed-report */
  public function uploadSignedReport(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    if (!isset($_FILES['report'])) {
      Response::error('Signed report PDF required.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES['report'],
      $config['uploads']['max_resume'],
      ['pdf']
    );
    if ($error) {
      Response::error($error, 400);
    }

    $dir = $config['uploads']['signed_dir'] ?? ($config['uploads']['reports_dir'] . '/signed');
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $registerNo = $profile['registerNumber'] ?? 'student';
    $path = $dir . '/' . $registerNo . '_signed_' . time() . '.pdf';
    if (!move_uploaded_file($_FILES['report']['tmp_name'], $path)) {
      Response::error('Failed to save signed report.', 500);
    }

    $this->studentModel->update((string) $profile['_id'], ['signedReport' => $path]);
    Response::success(['path' => basename($path)], 'Signed report uploaded.');
  }

  /** POST /api/student/applications/{id}/withdraw */
  public function withdrawApplication(string $appId): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $appModel = new ApplicationModel();
    $app = $appModel->findById($appId);
    if (!$app || (string) $app['studentId'] !== (string) $profile['_id']) {
      Response::notFound('Application not found.');
    }
    (new ApplicationWorkflowService())->transition($appId, 'withdrawn', (string) $user['_id']);
    Response::success(null, 'Application withdrawn.');
  }

  /** POST /api/student/notifications/{id}/read */
  public function markNotificationRead(string $id): void
  {
    $user = RBACMiddleware::requireStudent();
    $notif = (new NotificationModel())->findById($id);
    if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
      Response::notFound();
    }
    (new NotificationModel())->markRead($id);
    Response::success(null, 'Notification marked as read.');
  }

  /** GET /api/student/applications */
  public function myApplications(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $apps = (new ApplicationModel())->findByStudent((string) $profile['_id']);
    Response::success(DocumentHelper::serializeMany($apps));
  }

  /** GET /api/student/notifications */
  public function notifications(): void
  {
    $user = RBACMiddleware::requireStudent();
    $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
    Response::success(DocumentHelper::serializeMany($notifs));
  }

  /** GET /api/student/placement-history */
  public function placementHistory(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    Response::success([
      'history'  => $profile['placementHistory'] ?? [],
      'chances'  => $profile['placementChances'] ?? ['used' => 0, 'remaining' => 0],
      'placed'   => $profile['placed'] ?? false,
    ]);
  }
}
