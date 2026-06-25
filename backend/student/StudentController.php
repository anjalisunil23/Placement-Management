<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\AuthMiddleware;
use PMS\Middleware\RBACMiddleware;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\ResumeModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Services\OfficerDataService;
use PMS\Services\RecruitmentResultService;
use PMS\Services\ApplicationUploadService;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\AesLoginService;
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
    if ($profile) {
      return $profile;
    }

    $aes = new AesLoginService();
    $sessionAes = Security::getSessionAesProfile();
    $reg = $aes->resolveAesAdmissionNumber(
      (string) ($user['registerNumber'] ?? ''),
      $sessionAes
    );
    if ($reg === '') {
      Response::notFound('Student profile not found. Please sign in again with your college account.');
    }

    $existingByReg = $this->studentModel->findByRegisterNumber($reg);
    if ($existingByReg) {
      $existingUserId = (string) ($existingByReg['userId'] ?? '');
      $currentUserId = (string) ($user['_id'] ?? '');
      if ($existingUserId === '' || $existingUserId === $currentUserId) {
        $linkedUserId = Security::toObjectId($currentUserId);
        if ($linkedUserId !== null) {
          $this->studentModel->update((string) $existingByReg['_id'], ['userId' => $linkedUserId]);
          $profile = $this->studentModel->findById((string) $existingByReg['_id']);
          if ($profile) {
            return $profile;
          }
        }
      }
      Response::error('This register number is already linked to another account.', 409);
    }

    $linkedUserId = Security::toObjectId((string) $user['_id']);
    if ($linkedUserId === null) {
      Response::error('Invalid account session. Please sign in again.', 401);
    }

    $mapped = $aes->mapAesDetailsToUserFields($sessionAes);
    $createData = ['registerNumber' => $reg];
    if (!empty($mapped['cgpa'])) {
      $createData['academic'] = ['cgpa' => (float) $mapped['cgpa']];
    }
    $academicCreate = $createData['academic'] ?? [];
    foreach (['marks10th', 'marks12th'] as $markKey) {
      if (!empty($mapped[$markKey]) && (float) $mapped[$markKey] > 0) {
        $academicCreate[$markKey] = (float) $mapped[$markKey];
      }
    }
    if (isset($academicCreate['marks12th']) && !isset($academicCreate['ugMarks'])) {
      $academicCreate['ugMarks'] = (float) $academicCreate['marks12th'];
    }
    if ($academicCreate !== []) {
      $createData['academic'] = $academicCreate;
    }
    if (!empty($mapped['phone'])) {
      $createData['personal'] = ['phone' => (string) $mapped['phone']];
    }

    $this->studentModel->createProfile((string) $user['_id'], $createData);
    $profile = $this->studentModel->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::error('Could not create your student profile.', 500);
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
    $aes = new AesLoginService();
    $sessionAes = \PMS\Utils\Security::getSessionAesProfile();
    $reg = $aes->resolveAesAdmissionNumber(
        (string) ($profile['registerNumber'] ?? ''),
        $sessionAes
    );
    if ($reg !== '' && (string) ($profile['registerNumber'] ?? '') === '') {
      $this->studentModel->update((string) $profile['_id'], ['registerNumber' => $reg]);
      $profile['registerNumber'] = $reg;
    }
    $apiName = $aes->syncStudentNameFromPlacement($user, $reg);
    if ($apiName !== '') {
      $user['name'] = $apiName;
    }
    $aes->syncStudentDepartmentIfMissing($profile, array_merge(
        \PMS\Utils\Security::getSessionAesProfile(),
        ['registerNumber' => (string) ($profile['registerNumber'] ?? '')]
    ));
    $aes->syncStudentPlacementExtras($profile);
    $profile = $this->studentModel->findById((string) $profile['_id']) ?? $profile;

    $dept = !empty($profile['departmentId'])
      ? (new DepartmentModel())->findById((string) $profile['departmentId'])
      : null;

    $out = DocumentHelper::serialize($profile) ?? [];
    if (!empty($out['photo']) && is_array($out['photo'])) {
      unset($out['photo']['path']);
    }

    $academic = is_array($out['academic'] ?? null) ? $out['academic'] : [];
    $personal = is_array($out['personal'] ?? null) ? $out['personal'] : [];
    $merged = $aes->applyAesSessionToUserFields(array_merge(
      StudentModel::profileToUserFields($profile, $dept),
      [
        'name'  => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
      ]
    ));

    $reg = (string) ($out['registerNumber'] ?? $merged['registerNumber'] ?? '');
    $collegeEmail = $aes->excludeSyntheticCollegeEmail((string) ($merged['collegeEmail'] ?? ''), $reg);
    $personalEmail = (string) ($merged['personalEmail'] ?? '');

    $out['user'] = [
      'name'          => (string) ($merged['name'] ?? $user['name'] ?? ''),
      'stud_name'     => (string) ($merged['name'] ?? $user['name'] ?? ''),
      'email'         => $collegeEmail !== '' ? $collegeEmail : ($personalEmail !== '' ? $personalEmail : (string) ($user['email'] ?? '')),
      'collegeEmail'  => $collegeEmail,
      'personalEmail' => $personalEmail,
      'phone'         => (string) ($merged['phone'] ?? $personal['phone'] ?? ''),
    ];

    $dbDept = $dept ? [
      'id'   => (string) ($dept['_id'] ?? ''),
      'code' => (string) ($dept['code'] ?? ''),
      'name' => (string) ($dept['name'] ?? ''),
    ] : null;
    $resolvedDept = $aes->resolveStudentDepartmentFields($dbDept, $reg);
    $out['department'] = [
      'id'   => (string) ($dbDept['id'] ?? ''),
      'code' => $resolvedDept['code'],
      'name' => $resolvedDept['name'],
    ];
    if ($resolvedDept['code'] === '' && $resolvedDept['name'] === '') {
      $out['department'] = null;
    }
    $out['departmentCode'] = $resolvedDept['code'];
    $out['departmentName'] = $resolvedDept['name'];
    $out['programme'] = $resolvedDept['name'];
    $out['branch'] = $resolvedDept['name'];
    if (!empty($merged['cgpa']) && (float) $merged['cgpa'] > 0) {
      $academic['cgpa'] = (float) $merged['cgpa'];
      $out['academic'] = $academic;
    }
    foreach (['marks10th', 'marks12th'] as $markKey) {
      if (!empty($merged[$markKey]) && (float) $merged[$markKey] > 0) {
        $academic[$markKey] = (float) $merged[$markKey];
        $out['academic'] = $academic;
      }
    }
    if (!empty($academic['marks12th']) && (float) ($academic['ugMarks'] ?? 0) <= 0) {
      $academic['ugMarks'] = (float) $academic['marks12th'];
      $out['academic'] = $academic;
    }
    if (!empty($merged['phone']) && empty($personal['phone'])) {
      $personal['phone'] = (string) $merged['phone'];
      $out['personal'] = $personal;
    }
    if (!empty($merged['photoUrl'])) {
      $out['photoUrl'] = (string) $merged['photoUrl'];
      if (empty($out['photo']) || !is_array($out['photo'])) {
        $out['photo'] = ['url' => (string) $merged['photoUrl'], 'source' => 'aes'];
      }
    }
    if (!empty($out['selfPlacement']) && is_array($out['selfPlacement'])) {
      unset($out['selfPlacement']['offerLetterPath']);
    }
    if (!empty($out['placed'])) {
      $placement = is_array($out['placement'] ?? null) ? $out['placement'] : null;
      if ($placement === null && is_array($out['selfPlacement'] ?? null)) {
        $sp = $out['selfPlacement'];
        $out['placement'] = [
          'company' => (string) ($sp['companyName'] ?? ''),
          'role'    => (string) ($sp['role'] ?? ''),
          'address' => (string) ($sp['companyAddress'] ?? ''),
          'source'  => 'self_reported',
        ];
      }
    }

    Response::success(DocumentHelper::jsonSafe($out));
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
      if (!isset($input[$key]) || !is_array($input[$key])) {
        continue;
      }
      $existing = is_array($profile[$key] ?? null) ? $profile[$key] : [];
      $update[$key] = array_merge($existing, $input[$key]);
    }

    if ($update === []) {
      Response::error('No valid fields to update.', 422);
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

    $this->studentModel->update((string) $profile['_id'], [
      'resume' => [
        'filename'   => $doc['fileName'],
        'path'       => $path,
        'verified'   => false,
        'uploadedAt' => DocumentHelper::now(),
      ],
    ]);

    (new ApplicationWorkflowService())->onResumeUploaded((string) $profile['_id']);

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
        'file'       => $filename,
        'url'        => $relative,
        'source'     => 'upload',
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
    $driveModel = new DriveModel();
    $allDrives = $driveModel->findAll([], 200, 0, ['date' => -1, 'createdAt' => -1]);
    $drives = array_values(array_filter(
      $allDrives,
      static function (array $drive): bool {
        $status = strtolower((string) ($drive['status'] ?? 'scheduled'));
        return !in_array($status, ['completed', 'closed'], true);
      }
    ));

    $engine = new EligibilityEngine();
    $studentId = (string) $profile['_id'];
    $appModel = new ApplicationModel();
    $drives = array_values(array_filter(
      $drives,
      static function (array $drive) use ($engine, $profile, $studentId, $appModel): bool {
        if ($appModel->findByStudentAndDrive($studentId, (string) ($drive['_id'] ?? ''))) {
          return true;
        }
        return $engine->driveVisibleToStudent($profile, $drive);
      }
    ));
    $enriched = (new OfficerDataService())->enrichDrivesWithCompany($drives);

    $result = array_map(function (array $row) use ($engine, $studentId, $appModel) {
      $driveId = (string) ($row['id'] ?? $row['_id'] ?? '');
      $row['eligibilityCheck'] = $engine->checkForDrive($studentId, $driveId);

      $app = $appModel->findByStudentAndDrive($studentId, $driveId);
      $row['applied'] = (bool) $app;
      $row['applicationStatus'] = $app['status'] ?? null;

      return $row;
    }, $enriched);

    $register = (string) ($profile['registerNumber'] ?? '');
    if ($register !== '') {
      $lookupRows = array_map(static fn (array $row): array => [
        'driveId' => (string) ($row['id'] ?? $row['_id'] ?? ''),
        'company' => (string) ($row['companyName'] ?? ''),
        'status'  => (string) ($row['applicationStatus'] ?? ''),
        'package' => '',
      ], $result);
      $merged = (new RecruitmentResultService())->mergeIntoApplicationRows($lookupRows, $register);
      foreach ($result as $i => &$row) {
        if (!empty($merged[$i]['resultStatus'])) {
          $row['applicationStatus'] = $merged[$i]['resultStatus'];
        }
        if (!empty($merged[$i]['resultPackage'])) {
          $row['package'] = $merged[$i]['resultPackage'];
        }
      }
      unset($row);
    }

    Response::success(DocumentHelper::jsonSafe($result));
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
    $appModel = new ApplicationModel();
    $drives = array_values(array_filter(
      $drives,
      static function (array $drive) use ($engine, $profile, $studentId, $appModel): bool {
        if ($appModel->findByStudentAndDrive($studentId, (string) ($drive['_id'] ?? ''))) {
          return true;
        }
        return $engine->driveVisibleToStudent($profile, $drive);
      }
    ));
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
    $enriched = (new OfficerDataService())->enrichDrivesWithCompany([$drive]);
    $out = $enriched[0] ?? DocumentHelper::serialize($drive);
    $out['company'] = $company ? DocumentHelper::serialize($company) : null;
    $out['eligibilityCheck'] = $engine->checkForDrive((string) $profile['_id'], $driveId);
    Response::success($out);
  }

  /** POST /api/student/apply */
  public function apply(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $uploads = new ApplicationUploadService();
    $input = $uploads->parseApplyInput();

    $errors = Validator::validate($input, [
      'driveId' => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $studentId = (string) $profile['_id'];
    $driveId = $input['driveId'];
    $resumeId = (string) ($input['resumeId'] ?? '');

    $profile = $this->studentModel->findById($studentId) ?? $profile;
    $engine = new EligibilityEngine();
    $check = $engine->checkForDrive($studentId, $driveId, $resumeId !== '' ? $resumeId : null);
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

    $profile = $this->studentModel->findById($studentId) ?? $profile;
    $resume = $uploads->resolveResume($input, $profile, $studentId);

    try {
      $certificates = $uploads->storeCertificates((string) ($profile['registerNumber'] ?? ''));
    } catch (\RuntimeException $e) {
      Response::error($e->getMessage(), 422);
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
    if ($certificates !== []) {
      $createData['certificates'] = $certificates;
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

  /** POST /api/student/self-placement — off-campus / self-reported placement */
  public function submitSelfPlacement(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $existing = $profile['selfPlacement'] ?? null;
    if (is_array($existing) && ($existing['status'] ?? '') === 'pending') {
      Response::error('Your placement report is already under review.', 409);
    }

    if (($profile['placed'] ?? false) === true) {
      Response::error('You are already marked as placed.', 409);
    }

    $companyName = trim((string) ($_POST['companyName'] ?? ''));
    $companyAddress = trim((string) ($_POST['companyAddress'] ?? ''));
    $jobRole = trim((string) ($_POST['role'] ?? ''));

    if ($companyName === '' || $jobRole === '') {
      Response::error('Company name and role are required.', 422);
    }
    if ($companyAddress === '') {
      Response::error('Company address is required.', 422);
    }

    if (!isset($_FILES['offerLetter'])) {
      Response::error('Offer letter PDF is required.', 400);
    }

    $uploadError = (int) ($_FILES['offerLetter']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
      Response::error('Offer letter PDF is required.', 400);
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
      $uploadMessages = [
        UPLOAD_ERR_INI_SIZE   => 'Offer letter exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'Offer letter exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'Offer letter upload was interrupted. Try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is not configured.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the offer letter.',
        UPLOAD_ERR_EXTENSION  => 'Server blocked the offer letter upload.',
      ];
      Response::error($uploadMessages[$uploadError] ?? 'Offer letter upload failed.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES['offerLetter'],
      $config['uploads']['max_resume'],
      ['pdf']
    );
    if ($error) {
      Response::error($error, 400);
    }

    $dir = $config['uploads']['offer_letter_dir'] ?? ($config['uploads']['reports_dir'] . '/offer_letters');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      Response::error('Server upload folder is not writable.', 500);
    }

    $registerNo = (string) ($profile['registerNumber'] ?? 'student');
    $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $companyName) ?: 'company';
    $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_offer_' . time() . '.pdf';
    if (!move_uploaded_file($_FILES['offerLetter']['tmp_name'], $path)) {
      Response::error('Failed to save offer letter.', 500);
    }

    $report = [
      'companyName'    => $companyName,
      'companyAddress' => $companyAddress,
      'role'           => $jobRole,
      'offerLetter'    => basename($path),
      'status'         => 'pending',
      'submittedAt'    => DocumentHelper::now(),
    ];

    $history = is_array($profile['placementHistory'] ?? null) ? $profile['placementHistory'] : [];
    $history[] = [
      'type'    => 'self_reported',
      'company' => $companyName,
      'address' => $companyAddress,
      'role'    => $jobRole,
      'status'  => 'pending',
      'date'    => DocumentHelper::now(),
    ];

    $saved = $this->studentModel->update((string) $profile['_id'], [
      'selfPlacement'    => $report,
      'placementHistory' => $history,
    ]);
    if (!$saved) {
      @unlink($path);
      Response::error('Could not save placement report.', 500);
    }

    $studentName = (string) ($user['name'] ?? $registerNo);
    try {
      $notifier = new NotificationService();
      $notifier->notifyPlacementCell(
        'self_placement_submitted',
        'Self-placement report — review required',
        "{$studentName} ({$registerNo}) submitted a placement report for {$companyName} as {$jobRole}. Verify the offer letter and mark as placed.",
        [
          'action'         => 'verify_self_placement',
          'studentId'      => (string) $profile['_id'],
          'registerNumber' => $registerNo,
          'companyName'    => $companyName,
          'role'           => $jobRole,
          'link'           => 'students.html?verify=' . rawurlencode((string) $profile['_id']),
        ],
        true
      );
      $notifier->notifyUser(
        (string) $user['_id'],
        'placement_report_submitted',
        'Placement report submitted',
        'Your placement report for ' . $companyName . ' is under review by the placement cell.',
        ['companyName' => $companyName],
        false
      );
    } catch (\Throwable) {
      // Submission succeeded; notification failure must not block the student.
    }

    Response::success($report, 'Placement report submitted for verification.', 201);
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
    $rows = (new OfficerDataService())->enrichApplications($apps);
    $rows = (new RecruitmentResultService())->mergeIntoApplicationRows(
      $rows,
      (string) ($profile['registerNumber'] ?? '')
    );
    Response::success(DocumentHelper::jsonSafe($rows));
  }

  /** GET /api/student/results */
  public function myResults(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    Response::success(DocumentHelper::jsonSafe(
      (new RecruitmentResultService())->listForStudent((string) ($profile['registerNumber'] ?? ''))
    ));
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
