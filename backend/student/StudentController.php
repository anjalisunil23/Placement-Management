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
use PMS\Models\PolicyAcceptanceLogModel;
use PMS\Models\ResumeModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Services\OfficerDataService;
use PMS\Services\RecruitmentResultService;
use PMS\Services\ApplicationUploadService;
use PMS\Services\ApplicationWorkflowService;
use PMS\Services\AesApiService;
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
    $api = new AesApiService();
    $qualAdmno = $api->resolveQualificationAdmissionNumber($sessionAes, $reg);
    $academicCreate = [];
    if ($qualAdmno !== '' && ctype_digit($qualAdmno)) {
      try {
        $qual = $api->fetchStudentQualificationProfile([
          'admno' => $qualAdmno,
          'stud_admno' => $qualAdmno,
        ]);
      } catch (\Throwable) {
        $qual = [];
      }
      if ($qual !== []) {
        $qualMapped = $aes->mapAesDetailsToUserFields($qual);
        if (!empty($qualMapped['cgpa'])) {
          $academicCreate['cgpa'] = (float) $qualMapped['cgpa'];
        }
        foreach (['marks10th', 'marks12th'] as $markKey) {
          if (!empty($qualMapped[$markKey]) && (float) $qualMapped[$markKey] > 0) {
            $academicCreate[$markKey] = (float) $qualMapped[$markKey];
          }
        }
        if (isset($academicCreate['marks12th']) && !isset($academicCreate['ugMarks'])) {
          $academicCreate['ugMarks'] = (float) $academicCreate['marks12th'];
        }
        $qualRows = is_array($qual['qualifications'] ?? null) ? $qual['qualifications'] : [];
        if ($qualRows !== []) {
          $academicCreate['qualifications'] = $qualRows;
        }
      }
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
    $liteProfile = $this->isLiteProfileRequest();
    $forceRefresh = $this->isForcedAesRefreshRequest();
    if (!$liteProfile && ($forceRefresh || $this->shouldRefreshAesPlacement($profile))) {
      $profile = $aes->refreshStudentPlacementData($profile);
    }

    $dept = !empty($profile['departmentId'])
      ? (new DepartmentModel())->findById((string) $profile['departmentId'])
      : null;

    $out = DocumentHelper::serialize($profile) ?? [];
    if (!empty($out['photo']) && is_array($out['photo'])) {
      unset($out['photo']['path']);
    }

    $academic = is_array($out['academic'] ?? null) ? $out['academic'] : [];
    $personal = is_array($out['personal'] ?? null) ? $out['personal'] : [];
    if (
      (empty($academic['cgpa']) || (float) $academic['cgpa'] <= 0)
      && !empty($academic['qualifications'])
      && is_array($academic['qualifications'])
    ) {
      foreach ($academic['qualifications'] as $q) {
        if (!is_array($q)) {
          continue;
        }
        $label = strtoupper((string) ($q['qualification'] ?? ''));
        $mark = isset($q['mark']) && is_numeric($q['mark']) ? (float) $q['mark'] : null;
        $maxMark = isset($q['maxMark']) && is_numeric($q['maxMark']) ? (float) $q['maxMark'] : null;
        if (
          $mark !== null
          && $mark > 0
          && $mark <= 10
          && (
            preg_match('/\b(CGPA|CURRENT)\b/', $label) === 1
            || ($label === '' && ($maxMark === null || $maxMark <= 10))
          )
        ) {
          $academic['cgpa'] = $mark;
          $out['academic'] = $academic;
          break;
        }
      }
    }
    $merged = $aes->applyAesSessionToUserFields(array_merge(
      StudentModel::profileToUserFields($profile, $dept),
      [
        'name'  => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
      ]
    ));

    $reg = (string) ($out['registerNumber'] ?? $merged['registerNumber'] ?? '');
    $collegeEmail = $aes->excludeSyntheticCollegeEmail((string) ($merged['collegeEmail'] ?? ''), $reg);
    // Prefer user-saved personal email over AES session values.
    $personalEmail = strtolower(trim((string) (
      $personal['personalEmail']
      ?? $merged['personalEmail']
      ?? ''
    )));

    $out['user'] = [
      'name'          => (string) ($merged['name'] ?? $user['name'] ?? ''),
      'stud_name'     => (string) ($merged['name'] ?? $user['name'] ?? ''),
      'email'         => $collegeEmail !== '' ? $collegeEmail : ($personalEmail !== '' ? $personalEmail : (string) ($user['email'] ?? '')),
      'collegeEmail'  => $collegeEmail,
      'personalEmail' => $personalEmail,
      'phone'         => (string) ($merged['phone'] ?? $personal['phone'] ?? ''),
      'gender'        => (string) ($merged['gender'] ?? $personal['gender'] ?? ''),
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
    $out['gender'] = (string) ($merged['gender'] ?? $personal['gender'] ?? '');
    if (!empty($academic['cgpa']) && (float) $academic['cgpa'] > 0) {
      $out['academic'] = $academic;
      $out['cgpa'] = (float) $academic['cgpa'];
    }
    foreach (['marks10th', 'marks12th'] as $markKey) {
      if (!empty($academic[$markKey]) && (float) $academic[$markKey] > 0) {
        $out['academic'] = $academic;
        $out[$markKey] = (float) $academic[$markKey];
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
    if (!empty($merged['gender']) && empty($personal['gender'])) {
      $personal['gender'] = (string) $merged['gender'];
      $out['personal'] = $personal;
    }
    if (!empty($merged['photoUrl'])) {
      $out['photoUrl'] = (string) $merged['photoUrl'];
      if (empty($out['photo']) || !is_array($out['photo'])) {
        $out['photo'] = ['url' => (string) $merged['photoUrl'], 'source' => 'aes'];
      }
    }
    $qualifications = is_array($academic['qualifications'] ?? null) ? $academic['qualifications'] : [];
    if ($qualifications !== []) {
      $out['academic'] = $academic;
      $out['qualifications'] = $qualifications;
    } else {
      unset($academic['qualifications']);
      $out['academic'] = $academic;
      $out['qualifications'] = [];
    }
    if (!empty($out['selfPlacement']) && is_array($out['selfPlacement'])) {
      unset($out['selfPlacement']['offerLetterPath']);
    }
    if (!empty($out['placed'])) {
      $placement = is_array($out['placement'] ?? null) ? $out['placement'] : null;
      if ($placement === null && is_array($out['selfPlacement'] ?? null)) {
        $sp = $out['selfPlacement'];
        $out['placement'] = [
          'company'       => (string) ($sp['companyName'] ?? ''),
          'role'          => (string) ($sp['role'] ?? ''),
          'address'       => (string) ($sp['companyAddress'] ?? ''),
          'package'       => (string) ($sp['package'] ?? ''),
          'joinDate'      => (string) ($sp['joinDate'] ?? ''),
          'endDate'       => (string) ($sp['endDate'] ?? ''),
          'offerLetter'   => (string) ($sp['offerLetter'] ?? ''),
          'joiningLetter' => (string) ($sp['joiningLetter'] ?? ''),
          'companyIdDoc'  => (string) ($sp['companyIdDoc'] ?? ''),
          'source'        => 'self_reported',
        ];
      }
    }

    Response::success(DocumentHelper::jsonSafe($out));
  }

  private function isLiteProfileRequest(): bool
  {
    $lite = $_GET['lite'] ?? $_SERVER['HTTP_X_PROFILE_LITE'] ?? '';
    if ($lite === '' || $lite === '0' || $lite === 'false') {
      return false;
    }

    return true;
  }

  private function isForcedAesRefreshRequest(): bool
  {
    $refresh = $_GET['refresh'] ?? '';
    return $refresh === '1' || $refresh === 'true';
  }

  /**
   * @param array<string, mixed> $profile
   */
  private function shouldRefreshAesPlacement(array $profile): bool
  {
    $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
    $syncedAt = strtotime((string) ($academic['aesSyncedAt'] ?? ''));
    if ($syncedAt !== false && $syncedAt > 0 && (time() - $syncedAt) < 300) {
      return false;
    }

    return true;
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
      $patch = $input[$key];
      if ($key === 'academic') {
        $patch = $this->sanitizeAcademicPatch($patch);
      }
      if ($key === 'personal') {
        $patch = $this->sanitizePersonalPatch($patch);
      }
      if ($patch === []) {
        continue;
      }
      $update[$key] = array_merge($existing, $patch);
    }

    if ($update === []) {
      Response::error('No valid fields to update.', 422);
    }

    $this->studentModel->update((string) $profile['_id'], $update);
    Response::success(null, 'Profile updated.');
  }

  /**
   * @param array<string, mixed> $personal
   * @return array<string, mixed>
   */
  private function sanitizePersonalPatch(array $personal): array
  {
    $out = [];
    if (array_key_exists('phone', $personal)) {
      $phone = trim((string) $personal['phone']);
      $out['phone'] = $phone;
    }
    if (array_key_exists('personalEmail', $personal)) {
      $email = strtolower(trim((string) $personal['personalEmail']));
      if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out['personalEmail'] = $email;
      }
    }

    return $out;
  }

  /**
   * @param array<string, mixed> $academic
   * @return array<string, mixed>
   */
  private function sanitizeAcademicPatch(array $academic): array
  {
    $out = [];
    if (array_key_exists('cgpa', $academic) && is_numeric($academic['cgpa'])) {
      $cgpa = (float) $academic['cgpa'];
      if ($cgpa >= 0 && $cgpa <= 10) {
        $out['cgpa'] = $cgpa;
      }
    }
    if (array_key_exists('backlogs', $academic) && is_numeric($academic['backlogs'])) {
      $out['backlogs'] = max(0, (int) $academic['backlogs']);
    }
    foreach (['marks10th', 'marks12th', 'ugMarks', 'mcaMarks'] as $markKey) {
      if (!array_key_exists($markKey, $academic)) {
        continue;
      }
      $raw = $academic[$markKey];
      if ($raw === '' || $raw === null) {
        continue;
      }
      if (!is_numeric($raw)) {
        continue;
      }
      $mark = (float) $raw;
      if ($mark > 0 && $mark <= 100) {
        $out[$markKey] = $mark;
      }
    }

    return $out;
  }

  /** POST /api/student/policy/accept — Placement Cell registration + guidelines confirmation */
  public function acceptPolicy(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

    $errors = Validator::validate($input, [
      'registerNumber' => 'required',
      'name'           => 'required|min:2',
      'mobile'         => 'required|phone',
      'email'          => 'required|email',
      'branchBatch'    => 'required',
      'yop'            => 'required',
      'signedName'     => 'required|min:2',
    ]);
    if (empty($input['declarationAccepted']) && empty($input['declaration'])) {
      $errors['declarationAccepted'] = 'You must accept the placement guidelines declaration.';
    }
    $branch = trim((string) ($input['branchBatch'] ?? ''));
    $mtechBranch = trim((string) ($input['mtechBranch'] ?? ''));
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $version = 'ajce-2026-v1';
    $now = gmdate('c');
    $signedName = trim((string) $input['signedName']);
    $registration = [
      'registerNumber' => strtoupper(trim((string) $input['registerNumber'])),
      'name'           => trim((string) $input['name']),
      'mobile'         => trim((string) $input['mobile']),
      'email'          => strtolower(trim((string) $input['email'])),
      'branchBatch'    => $branch,
      'mtechBranch'    => $mtechBranch,
      'yop'            => trim((string) $input['yop']),
      'signedName'     => $signedName,
      'signedDate'     => trim((string) ($input['signedDate'] ?? date('Y-m-d'))),
      'declarationAccepted' => true,
      'acceptedAt'     => $now,
      'policyVersion'  => $version,
    ];

    $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
    $personal['phone'] = $registration['mobile'];
    if ($registration['email'] !== '' && !str_ends_with($registration['email'], '@students.amaljyothi.ac.in')) {
      $personal['personalEmail'] = $registration['email'];
    }

    $this->studentModel->update((string) $profile['_id'], [
      'policyAccepted'   => true,
      'policyAcceptedAt' => $now,
      'policyVersion'    => $version,
      'placementRegistration' => $registration,
      'classBatch'       => $branch . ($mtechBranch !== '' ? ' — ' . $mtechBranch : ''),
      'personal'         => $personal,
    ]);

    (new PolicyAcceptanceLogModel())->logAcceptance([
      'studentId'      => (string) $profile['_id'],
      'userId'         => (string) $user['_id'],
      'studentName'    => $registration['name'],
      'registerNumber' => $registration['registerNumber'],
      'policyVersion'  => $version,
      'acceptedAt'     => $now,
      'acceptedIp'     => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
      'userAgent'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
      'deviceType'     => 'web',
    ]);

    Response::success([
      'policyAccepted' => true,
      'policyVersion'  => $version,
      'policyAcceptedAt' => $now,
    ], 'Placement Cell registration confirmed.');
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

    $studentId = (string) $profile['_id'];
    $appModel = new ApplicationModel();
    $engine = new EligibilityEngine();
    if (
      !$appModel->findByStudentAndDrive($studentId, $driveId)
      && !$engine->driveVisibleToStudent($profile, $drive)
    ) {
      Response::notFound('Drive not found.');
    }

    $company = !empty($drive['companyId']) ? (new CompanyModel())->findById((string) $drive['companyId']) : null;
    $enriched = (new OfficerDataService())->enrichDrivesWithCompany([$drive]);
    $out = $enriched[0] ?? DocumentHelper::serialize($drive);
    $out['company'] = $company ? DocumentHelper::serialize($company) : null;
    $out['eligibilityCheck'] = $engine->checkForDrive($studentId, $driveId);
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

  /** POST /api/student/self-placement — off-campus / self-reported placement (or next after end date) */
  public function submitSelfPlacement(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    $existing = $profile['selfPlacement'] ?? null;
    if (is_array($existing) && ($existing['status'] ?? '') === 'pending') {
      Response::error('Your placement report is already under review.', 409);
    }

    $isPlaced = ($profile['placed'] ?? false) === true;
    $currentPlacement = is_array($profile['placement'] ?? null) ? $profile['placement'] : [];
    $addingNext = $isPlaced;

    if ($addingNext) {
      $prevEnd = trim((string) ($currentPlacement['endDate'] ?? ''));
      if ($prevEnd === '') {
        Response::error('Set an end date on your current placement before adding a new one.', 422);
      }
    }

    $companyName = trim((string) ($_POST['companyName'] ?? ''));
    $companyAddress = trim((string) ($_POST['companyAddress'] ?? ''));
    $jobRole = trim((string) ($_POST['role'] ?? ''));
    $package = trim((string) ($_POST['package'] ?? ''));
    $joinDate = trim((string) ($_POST['joinDate'] ?? ''));
    $endDate = trim((string) ($_POST['endDate'] ?? ''));

    if ($companyName === '' || $jobRole === '') {
      Response::error('Company name and role are required.', 422);
    }
    if ($package === '') {
      Response::error('Package is required.', 422);
    }
    if ($joinDate !== '' && !$this->isValidYmdDate($joinDate)) {
      Response::error('Join date must be YYYY-MM-DD.', 422);
    }
    if ($endDate !== '' && !$this->isValidYmdDate($endDate)) {
      Response::error('End date must be YYYY-MM-DD.', 422);
    }
    if ($joinDate !== '' && $endDate !== '' && $endDate < $joinDate) {
      Response::error('End date cannot be before join date.', 422);
    }

    $hasOffer = $this->hasUploadedFile('offerLetter');
    $hasJoining = $this->hasUploadedFile('joiningLetter');
    $hasCompanyId = $this->hasUploadedFile('companyIdDoc');
    if (!$hasOffer && !$hasJoining && !$hasCompanyId) {
      Response::error('Upload at least one document: offer letter, joining letter, or company ID.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $registerNo = (string) ($profile['registerNumber'] ?? 'student');
    $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $companyName) ?: 'company';
    $savedPaths = [];

    $offerLetter = null;
    if ($hasOffer) {
      $uploadError = (int) ($_FILES['offerLetter']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($uploadError !== UPLOAD_ERR_OK) {
        Response::error('Offer letter upload failed.', 400);
      }
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
      $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_offer_' . time() . '.pdf';
      if (!move_uploaded_file($_FILES['offerLetter']['tmp_name'], $path)) {
        Response::error('Failed to save offer letter.', 500);
      }
      $savedPaths[] = $path;
      $offerLetter = basename($path);
    }

    $joiningLetter = $this->saveOptionalSelfPlacementUpload(
      'joiningLetter',
      $registerNo,
      $safeCompany,
      'joining_letter',
      ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'],
      $savedPaths
    );
    $companyIdDoc = $this->saveOptionalSelfPlacementUpload(
      'companyIdDoc',
      $registerNo,
      $safeCompany,
      'company_id',
      ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'],
      $savedPaths
    );
    // Backward-compatible optional salary slip
    $salarySlip = $this->saveOptionalSelfPlacementUpload(
      'salarySlip',
      $registerNo,
      $safeCompany,
      'salary_slip',
      ['pdf', 'doc', 'docx'],
      $savedPaths
    );

    $report = [
      'companyName'    => $companyName,
      'companyAddress' => $companyAddress,
      'role'           => $jobRole,
      'package'        => $package,
      'joinDate'       => $joinDate,
      'endDate'        => $endDate,
      'status'         => 'pending',
      'submittedAt'    => DocumentHelper::now(),
    ];
    if ($offerLetter !== null) {
      $report['offerLetter'] = $offerLetter;
    }
    if ($joiningLetter !== null) {
      $report['joiningLetter'] = $joiningLetter;
    }
    if ($companyIdDoc !== null) {
      $report['companyIdDoc'] = $companyIdDoc;
    }
    if ($salarySlip !== null) {
      $report['salarySlip'] = $salarySlip;
    }

    $history = is_array($profile['placementHistory'] ?? null) ? $profile['placementHistory'] : [];
    if ($addingNext && $currentPlacement !== []) {
      $history[] = array_merge($currentPlacement, [
        'status'  => 'ended',
        'endedAt' => DocumentHelper::now(),
        'type'    => (string) ($currentPlacement['source'] ?? 'placement'),
      ]);
    }

    $history[] = [
      'type'      => 'self_reported',
      'company'   => $companyName,
      'address'   => $companyAddress,
      'role'      => $jobRole,
      'package'   => $package,
      'joinDate'  => $joinDate,
      'endDate'   => $endDate,
      'status'    => 'pending',
      'date'      => DocumentHelper::now(),
    ];

    $patch = [
      'selfPlacement'    => $report,
      'placementHistory' => $history,
    ];

    $saved = $this->studentModel->update((string) $profile['_id'], $patch);
    if (!$saved) {
      foreach ($savedPaths as $savedPath) {
        @unlink($savedPath);
      }
      Response::error('Could not save placement report.', 500);
    }

    $studentName = (string) ($user['name'] ?? $registerNo);
    try {
      $notifier = new NotificationService();
      $notifier->notifyPlacementCell(
        'self_placement_submitted',
        'Self-placement report — review required',
        "{$studentName} ({$registerNo}) submitted a placement report for {$companyName} as {$jobRole}. Verify documents and mark as placed.",
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

  /**
   * POST /api/student/placement/current
   * Update current placement details (package, dates, docs) when already placed.
   */
  public function updateCurrentPlacement(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);

    if (($profile['placed'] ?? false) !== true) {
      Response::error('You are not marked as placed yet.', 422);
    }

    $pending = $profile['selfPlacement'] ?? null;
    if (is_array($pending) && ($pending['status'] ?? '') === 'pending') {
      Response::error('A new placement report is under review. Wait for verification before editing.', 409);
    }

    $placement = is_array($profile['placement'] ?? null) ? $profile['placement'] : [];
    $self = is_array($profile['selfPlacement'] ?? null) ? $profile['selfPlacement'] : [];

    $companyName = trim((string) ($_POST['companyName'] ?? ($placement['company'] ?? $self['companyName'] ?? '')));
    $jobRole = trim((string) ($_POST['role'] ?? ($placement['role'] ?? $self['role'] ?? '')));
    $companyAddress = trim((string) ($_POST['companyAddress'] ?? ($placement['address'] ?? $self['companyAddress'] ?? '')));
    $package = trim((string) ($_POST['package'] ?? ($placement['package'] ?? $self['package'] ?? '')));
    $joinDate = trim((string) ($_POST['joinDate'] ?? ($placement['joinDate'] ?? $self['joinDate'] ?? '')));
    $endDate = trim((string) ($_POST['endDate'] ?? ($placement['endDate'] ?? $self['endDate'] ?? '')));

    if ($companyName === '' || $jobRole === '') {
      Response::error('Company name and role are required.', 422);
    }
    if ($package === '') {
      Response::error('Package is required.', 422);
    }
    if ($joinDate !== '' && !$this->isValidYmdDate($joinDate)) {
      Response::error('Join date must be YYYY-MM-DD.', 422);
    }
    if ($endDate !== '' && !$this->isValidYmdDate($endDate)) {
      Response::error('End date must be YYYY-MM-DD.', 422);
    }
    if ($joinDate !== '' && $endDate !== '' && $endDate < $joinDate) {
      Response::error('End date cannot be before join date.', 422);
    }

    $registerNo = (string) ($profile['registerNumber'] ?? 'student');
    $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $companyName) ?: 'company';
    $savedPaths = [];
    $config = require dirname(__DIR__) . '/config/app.php';

    $offerLetter = (string) ($placement['offerLetter'] ?? $self['offerLetter'] ?? '');
    $joiningLetter = (string) ($placement['joiningLetter'] ?? $self['joiningLetter'] ?? '');
    $companyIdDoc = (string) ($placement['companyIdDoc'] ?? $self['companyIdDoc'] ?? '');

    if ($this->hasUploadedFile('offerLetter')) {
      $error = Security::validateUploadedFile($_FILES['offerLetter'], $config['uploads']['max_resume'], ['pdf']);
      if ($error) {
        Response::error($error, 400);
      }
      $dir = $config['uploads']['offer_letter_dir'] ?? ($config['uploads']['reports_dir'] . '/offer_letters');
      if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        Response::error('Server upload folder is not writable.', 500);
      }
      $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_offer_' . time() . '.pdf';
      if (!move_uploaded_file($_FILES['offerLetter']['tmp_name'], $path)) {
        Response::error('Failed to save offer letter.', 500);
      }
      $savedPaths[] = $path;
      $offerLetter = basename($path);
    }

    $newJoining = $this->saveOptionalSelfPlacementUpload(
      'joiningLetter',
      $registerNo,
      $safeCompany,
      'joining_letter',
      ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'],
      $savedPaths
    );
    if ($newJoining !== null) {
      $joiningLetter = $newJoining;
    }
    $newCompanyId = $this->saveOptionalSelfPlacementUpload(
      'companyIdDoc',
      $registerNo,
      $safeCompany,
      'company_id',
      ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'],
      $savedPaths
    );
    if ($newCompanyId !== null) {
      $companyIdDoc = $newCompanyId;
    }

    if ($offerLetter === '' && $joiningLetter === '' && $companyIdDoc === '') {
      Response::error('At least one document is required: offer letter, joining letter, or company ID.', 422);
    }

    $placement = array_merge($placement, [
      'company'       => $companyName,
      'role'          => $jobRole,
      'address'       => $companyAddress,
      'package'       => $package,
      'joinDate'      => $joinDate,
      'endDate'       => $endDate,
      'offerLetter'   => $offerLetter,
      'joiningLetter' => $joiningLetter,
      'companyIdDoc'  => $companyIdDoc,
      'updatedAt'     => DocumentHelper::now(),
    ]);

    if ($self !== [] && in_array((string) ($self['status'] ?? ''), ['approved', 'placed'], true)) {
      $self['companyName'] = $companyName;
      $self['companyAddress'] = $companyAddress;
      $self['role'] = $jobRole;
      $self['package'] = $package;
      $self['joinDate'] = $joinDate;
      $self['endDate'] = $endDate;
      if ($offerLetter !== '') {
        $self['offerLetter'] = $offerLetter;
      }
      if ($joiningLetter !== '') {
        $self['joiningLetter'] = $joiningLetter;
      }
      if ($companyIdDoc !== '') {
        $self['companyIdDoc'] = $companyIdDoc;
      }
    }

    $saved = $this->studentModel->update((string) $profile['_id'], [
      'placement'     => $placement,
      'selfPlacement' => $self !== [] ? $self : ($profile['selfPlacement'] ?? null),
    ]);
    if (!$saved) {
      foreach ($savedPaths as $savedPath) {
        @unlink($savedPath);
      }
      Response::error('Could not update placement details.', 500);
    }

    Response::success([
      'placed'        => true,
      'placement'     => DocumentHelper::serialize($placement),
      'selfPlacement' => $self !== [] ? (DocumentHelper::serialize($self) ?? null) : null,
      'canAddNext'    => $endDate !== '',
    ], 'Placement details saved.');
  }

  private function hasUploadedFile(string $field): bool
  {
    if (!isset($_FILES[$field])) {
      return false;
    }
    return (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
  }

  private function isValidYmdDate(string $value): bool
  {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
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

  /**
   * POST /api/student/placement/history-dates
   * Update join/end dates on a past placementHistory entry.
   */
  public function updatePlacementHistoryDates(): void
  {
    $user = RBACMiddleware::requireStudent();
    $profile = $this->getStudentProfile($user);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

    $history = is_array($profile['placementHistory'] ?? null) ? $profile['placementHistory'] : [];
    $index = isset($input['historyIndex']) ? (int) $input['historyIndex'] : -1;
    if ($index < 0 || $index >= count($history) || !is_array($history[$index] ?? null)) {
      Response::error('Placement history entry not found.', 404);
    }

    $joinDate = trim((string) ($input['joinDate'] ?? ($history[$index]['joinDate'] ?? '')));
    $endDate = trim((string) ($input['endDate'] ?? ($history[$index]['endDate'] ?? '')));

    if ($joinDate !== '' && !$this->isValidYmdDate($joinDate)) {
      Response::error('Join date must be YYYY-MM-DD.', 422);
    }
    if ($endDate !== '' && !$this->isValidYmdDate($endDate)) {
      Response::error('End date must be YYYY-MM-DD.', 422);
    }
    if ($joinDate !== '' && $endDate !== '' && $endDate < $joinDate) {
      Response::error('End date cannot be before join date.', 422);
    }

    $history[$index]['joinDate'] = $joinDate;
    $history[$index]['endDate'] = $endDate;
    if ($endDate !== '') {
      $history[$index]['status'] = 'ended';
    }

    if (!$this->studentModel->update((string) $profile['_id'], ['placementHistory' => $history])) {
      Response::error('Could not update placement dates.', 500);
    }

    Response::success(['placementHistory' => $history], 'Placement dates updated.');
  }

  /**
   * @param string[] $savedPaths
   */
  private function saveOptionalSelfPlacementUpload(
    string $field,
    string $registerNo,
    string $safeCompany,
    string $prefix,
    array $extensions,
    array &$savedPaths
  ): ?string {
    if (!isset($_FILES[$field])) {
      return null;
    }

    $uploadError = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
      return null;
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
      Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ' upload failed.', 400);
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES[$field],
      $config['uploads']['max_resume'],
      $extensions
    );
    if ($error) {
      Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ': ' . $error, 400);
    }

    $dir = $config['uploads']['self_placement_dir'] ?? ($config['uploads']['offer_letter_dir'] . '/../self_placement');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      Response::error('Server upload folder is not writable.', 500);
    }

    $ext = strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
    $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_' . $prefix . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
      Response::error('Failed to save ' . str_replace('_', ' ', $prefix) . '.', 500);
    }

    $savedPaths[] = $path;

    return basename($path);
  }
}
