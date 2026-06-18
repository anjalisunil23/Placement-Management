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
use PMS\Models\ResumeModel;
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

  /** GET /api/student/dashboard */
  public function dashboard(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $studentId = (string) $profile['_id'];
    $cgpa = (float) (
      $profile['cgpa']
      ?? ($profile['academic']['cgpa'] ?? 0)
      ?? ($profile['academic']['mca']['cgpa'] ?? 0)
    );

    $applications = (new ApplicationModel())->count(['studentId' => Security::toObjectId($studentId)]);
    $resumeCount = !empty($profile['resume']) ? 1 : 0;
    $unreadNotifications = (new NotificationModel())->countUnread((string) $user['_id']);
    $remainingChances = (int) (($profile['placementChances']['remaining'] ?? null) ?? ($profile['chancesRemaining'] ?? 0));

    Response::success([
      'cgpa' => $cgpa,
      'applications' => $applications,
      'resumeCount' => $resumeCount,
      'unreadNotifications' => $unreadNotifications,
      'remainingChances' => $remainingChances,
    ]);
  }

  /** GET /api/student/profile */
  public function getProfile(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $out = DocumentHelper::serialize($profile);
    if (!empty($out['photo']) && is_array($out['photo'])) {
      unset($out['photo']['path']);
    }
    Response::success($out);
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

  /** GET /api/student/resumes */
  public function listResumes(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $rows = (new ResumeModel())->findByStudent((string) $profile['_id'], 50);

    $out = array_map(function (array $r) {
      return [
        '_id' => (string) $r['_id'],
        'label' => $r['label'] ?? '',
        'profileType' => $r['profileType'] ?? '',
        'fileName' => $r['fileName'] ?? '',
        'fileSize' => (int) ($r['fileSize'] ?? 0),
        'verified' => (bool) ($r['verified'] ?? false),
        'isDefault' => (bool) ($r['isDefault'] ?? false),
        'uploadedAt' => isset($r['uploadedAt']) ? DocumentHelper::serialize($r['uploadedAt']) : null,
        'viewUrl' => '/backend/api/student/resumes/' . (string) $r['_id'] . '/view',
      ];
    }, $rows);

    Response::success($out);
  }

  /** POST /api/student/resumes/upload */
  public function uploadResumeToLibrary(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    if (!isset($_FILES['file'])) {
      Response::error('Resume file required.', 400);
    }

    $label = (string) ($_POST['label'] ?? 'Resume');
    $profileType = (string) ($_POST['profileType'] ?? 'General');

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES['file'],
      $config['uploads']['max_resume'],
      Security::allowedResumeExtensions()
    );
    if ($error) {
      Response::error($error, 400);
    }

    $dir = $config['uploads']['resume_dir'];
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
    $registerNo = (string) ($profile['registerNumber'] ?? 'student');
    $safeLabel = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($profileType));
    $storedName = $registerNo . '_' . $safeLabel . '_' . time() . '.' . $ext;
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
      Response::error('Failed to save resume.', 500);
    }

    $doc = [
      'userId' => Security::toObjectId((string) $user['_id']),
      'studentId' => Security::toObjectId((string) $profile['_id']),
      'label' => $label,
      'profileType' => $profileType,
      'fileName' => basename((string) ($_FILES['file']['name'] ?? $storedName)),
      'fileSize' => (int) ($_FILES['file']['size'] ?? 0),
      'mime' => (string) ($_FILES['file']['type'] ?? ''),
      'storedName' => $storedName,
      'verified' => false,
      'isDefault' => false,
      'uploadedAt' => DocumentHelper::now(),
    ];

    $id = (new ResumeModel())->insert($doc);
    Response::success(['id' => $id], 'Resume uploaded.', 201);
  }

  /** GET /api/student/resumes/{id}/view */
  public function viewResume(string $resumeId): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $resume = (new ResumeModel())->findById($resumeId);
    if (!$resume || (string) ($resume['studentId'] ?? '') !== (string) $profile['_id']) {
      Response::notFound('Resume not found.');
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $dir = $config['uploads']['resume_dir'];
    $stored = basename((string) ($resume['storedName'] ?? ''));
    if ($stored === '') {
      Response::notFound('Resume not found.');
    }
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($path)) {
      Response::notFound('Resume file missing.');
    }

    $ext = strtolower(pathinfo((string) ($resume['fileName'] ?? ''), PATHINFO_EXTENSION));
    $mime = match ($ext) {
      'pdf'  => 'application/pdf',
      'doc'  => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename((string) ($resume['fileName'] ?? 'resume.pdf')) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }

  /** POST /api/student/resumes/{id}/delete */
  public function deleteResumeFromLibrary(string $resumeId): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $resume = (new ResumeModel())->findById($resumeId);
    if (!$resume || (string) ($resume['studentId'] ?? '') !== (string) $profile['_id']) {
      Response::notFound('Resume not found.');
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $dir = $config['uploads']['resume_dir'];
    $stored = basename((string) ($resume['storedName'] ?? ''));
    if ($stored !== '') {
      $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $stored;
      if (is_file($path)) {
        @unlink($path);
      }
    }

    (new ResumeModel())->delete($resumeId);
    Response::success(null, 'Resume deleted.');
  }

  /** POST /api/student/resumes/{id}/default */
  public function setDefaultResume(string $resumeId): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $resume = (new ResumeModel())->findById($resumeId);
    if (!$resume || (string) ($resume['studentId'] ?? '') !== (string) $profile['_id']) {
      Response::notFound('Resume not found.');
    }

    (new ResumeModel())->setDefault((string) $profile['_id'], $resumeId);
    Response::success(null, 'Default resume set.');
  }

  /** POST /api/student/photo */
  public function uploadPhoto(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    if (!isset($_FILES['photo'])) {
      Response::error('Photo file required.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES['photo'],
      2 * 1024 * 1024,
      Security::allowedPhotoExtensions()
    );
    if ($error) {
      Response::error($error, 400);
    }

    $dir = $config['uploads']['photo_dir'] ?? (dirname(__DIR__, 2) . '/uploads/photos');
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $registerNo = (string) ($profile['registerNumber'] ?? 'student');
    $filename = $registerNo . '_photo_' . time() . '.' . $ext;
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $path)) {
      Response::error('Failed to save photo.', 500);
    }

    $relative = '/uploads/photos/' . $filename;
    $this->studentModel->update((string) $profile['_id'], [
      'photo' => [
        'file' => $filename,
        'url'  => $relative,
        'uploadedAt' => DocumentHelper::now(),
      ],
    ]);

    Response::success(['file' => $filename, 'url' => $relative], 'Photo updated.');
  }

  /** POST /api/student/photo/remove */
  public function removePhoto(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    // Best-effort delete existing file (if it exists on disk)
    $existing = $profile['photo'] ?? null;
    if (is_array($existing) && !empty($existing['file'])) {
      $config = require dirname(__DIR__) . '/config/app.php';
      $dir = $config['uploads']['photo_dir'] ?? (dirname(__DIR__, 2) . '/uploads/photos');
      $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename((string) $existing['file']);
      if (is_file($candidate)) {
        @unlink($candidate);
      }
    }

    $this->studentModel->update((string) $profile['_id'], ['photo' => null]);
    Response::success(null, 'Photo removed.');
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

    $result = array_map(function ($drive) use ($engine, $studentId) {
      $eligibility = $engine->checkForDrive($studentId, (string) $drive['_id']);
      $serialized = DocumentHelper::serialize($drive);
      $serialized['eligibility'] = $eligibility;
      return $serialized;
    }, $drives);

    Response::success($result);
  }

  /** GET /api/student/open-drives */
  public function openDrives(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $companyModel = new CompanyModel();
    $driveModel = new DriveModel();
    $engine = new EligibilityEngine();
    $studentId = (string) $profile['_id'];

    $drives = $driveModel->findAll(['status' => ['$ne' => 'completed']], 50);
    $rows = [];

    foreach ($drives as $drive) {
      $company = !empty($drive['companyId']) ? $companyModel->findById((string) $drive['companyId']) : null;
      $eligibility = $engine->checkForDrive($studentId, (string) $drive['_id']);

      $rows[] = [
        'driveId' => (string) $drive['_id'],
        'companyName' => $company['companyName'] ?? 'Company',
        'role' => $drive['title'] ?? 'Role',
        'package' => $drive['eligibility']['package'] ?? ($company['package'] ?? null),
        'category' => $company['category'] ?? ($drive['eligibility']['category'] ?? null),
        'deadline' => $drive['date'] ?? null,
        'status' => $drive['status'] ?? 'scheduled',
        'eligible' => $eligibility['eligible'] ?? false,
        'reasons' => $eligibility['reasons'] ?? [],
      ];
    }

    Response::success($rows);
  }

  /** GET /api/student/drives/{id} */
  public function driveDetails(string $driveId): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $drive = (new DriveModel())->findById($driveId);
    if (!$drive) {
      Response::notFound('Drive not found.');
    }

    $company = !empty($drive['companyId']) ? (new CompanyModel())->findById((string) $drive['companyId']) : null;
    $engine = new EligibilityEngine();
    $eligibility = $engine->checkForDrive((string) $profile['_id'], $driveId);

    $out = DocumentHelper::serialize($drive);
    $out['company'] = $company ? DocumentHelper::serialize($company) : null;
    $out['eligibility'] = $eligibility;
    Response::success($out);
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

    $appId = $appModel->createApplication([
      'studentId' => $studentId,
      'driveId'   => $driveId,
      'companyId' => (string) $drive['companyId'],
      'jobId'     => $input['jobId'] ?? null,
      'status'    => ($profile['resume']['verified'] ?? false) ? 'resume_verified' : 'applied',
    ]);

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

  /** POST /api/student/notifications/read-all */
  public function markAllNotificationsRead(): void
  {
    $user = RBACMiddleware::requireStudent();
    $count = (new NotificationModel())->markAllRead((string) $user['_id']);
    Response::success(['updated' => $count], 'All notifications marked as read.');
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
