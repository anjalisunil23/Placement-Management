<?php

declare(strict_types=1);

namespace PMS\Alumni;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniJobPostModel;
use PMS\Models\AlumniModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\ApplicationModel;
use PMS\Models\DriveModel;
use PMS\Models\NotificationModel;
use PMS\Models\CompanyModel;
use PMS\Models\ResumeModel;
use PMS\Models\StudentModel;
use PMS\Models\SuccessStoryModel;
use PMS\Services\AesLoginService;
use PMS\Services\ApplicationUploadService;
use PMS\Services\EligibilityEngine;
use PMS\Services\JobFeedService;
use PMS\Services\ObjectStorageService;
use PMS\Services\OfficerDataService;
use PMS\Services\RecruitmentResultService;
use PMS\Services\NotificationService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;
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
    $model = new AlumniModel();
    $profile = $model->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::notFound('Alumni profile not found.');
    }
    $aes = new AesLoginService();
    $profile = $aes->syncAlumniPlacementPhoto($user, $profile) ?? $profile;
    $photo = $aes->resolveAlumniProfilePhoto($user, $profile, false);
    $out = AlumniModel::serializeProfile($profile);
    if (($photo['photoUrl'] ?? '') !== '') {
      $out['photoUrl'] = $photo['photoUrl'];
      $out['photo'] = $photo['photo'];
    }
    Response::success(DocumentHelper::serialize($out));
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

  /** POST /api/alumni/employment-docs — upload offer letter, company ID, salary slip */
  public function submitEmploymentDocs(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $model = new AlumniModel();
    $profile = $model->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::notFound('Alumni profile not found.');
    }

    $fields = AlumniModel::profileToUserFields($profile);
    if (!$fields['isWorking']) {
      Response::error('Employment documents can only be uploaded when you are marked as currently employed.', 422);
    }

    $company = trim((string) ($profile['company'] ?? ''));
    if ($company === '') {
      Response::error('Set your current company before uploading employment documents.', 422);
    }

    $existing = is_array($profile['employmentDocs'] ?? null) ? $profile['employmentDocs'] : [];
    $hasExistingOffer = (string) ($existing['offerLetter'] ?? '') !== '';
    $hasNewOffer = isset($_FILES['offerLetter'])
      && (int) ($_FILES['offerLetter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $hasNewCompanyId = isset($_FILES['companyIdDoc'])
      && (int) ($_FILES['companyIdDoc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $hasNewSalarySlip = isset($_FILES['salarySlip'])
      && (int) ($_FILES['salarySlip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasNewOffer && !$hasNewCompanyId && !$hasNewSalarySlip) {
      Response::error('Choose at least one document to upload.', 422);
    }
    if (!$hasExistingOffer && !$hasNewOffer) {
      Response::error('Offer letter (PDF) is required.', 422);
    }

    $userKey = preg_replace('/[^a-zA-Z0-9]+/', '', (string) ($user['_id'] ?? 'alumni')) ?: 'alumni';
    $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $company) ?: 'company';
    $savedPaths = [];
    $docs = $existing;

    try {
      if ($hasNewOffer) {
        $docs['offerLetter'] = $this->saveEmploymentUpload(
          'offerLetter',
          $userKey,
          $safeCompany,
          'offer',
          ['pdf'],
          $savedPaths
        );
      }
      if ($hasNewCompanyId) {
        $docs['companyIdDoc'] = $this->saveEmploymentUpload(
          'companyIdDoc',
          $userKey,
          $safeCompany,
          'company_id',
          ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'],
          $savedPaths
        );
      }
      if ($hasNewSalarySlip) {
        $docs['salarySlip'] = $this->saveEmploymentUpload(
          'salarySlip',
          $userKey,
          $safeCompany,
          'salary_slip',
          ['pdf', 'doc', 'docx'],
          $savedPaths
        );
      }
    } catch (\RuntimeException $e) {
      $storage = new ObjectStorageService();
      foreach ($savedPaths as $path) {
        $storage->delete((string) $path);
      }
      Response::error($e->getMessage(), 400);
    }

    $docs['updatedAt'] = DocumentHelper::now();
    if (!$model->update((string) $profile['_id'], ['employmentDocs' => $docs])) {
      $storage = new ObjectStorageService();
      foreach ($savedPaths as $path) {
        $storage->delete((string) $path);
      }
      Response::error('Could not save employment documents.', 500);
    }

    $updated = $model->findById((string) $profile['_id']) ?? $profile;
    Response::success(
      AlumniModel::serializeEmploymentDocs(is_array($updated['employmentDocs'] ?? null) ? $updated['employmentDocs'] : $docs),
      'Employment documents uploaded.',
      201
    );
  }

  /** GET /api/alumni/employment-docs/offer-letter */
  public function downloadOfferLetter(): void
  {
    $this->streamEmploymentDoc('offerLetter');
  }

  /** GET /api/alumni/employment-docs/company-id */
  public function downloadCompanyIdDoc(): void
  {
    $this->streamEmploymentDoc('companyIdDoc');
  }

  /** GET /api/alumni/employment-docs/salary-slip */
  public function downloadSalarySlip(): void
  {
    $this->streamEmploymentDoc('salarySlip');
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
    $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
    if (!$profile || ($profile['isWorking'] ?? false) !== true) {
      Response::forbidden('Only employed alumni can post jobs.');
    }
    $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);
    $errors = Validator::validate($input, [
      'title'        => 'required',
      'company'      => 'required',
      'departmentId' => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $department = (new \PMS\Models\DepartmentModel())->findById($departmentId);
    if (!$department) {
      Response::error('Select a valid department for this job post.', 422);
    }

    $branchCodes = array_values(array_unique(array_filter(array_map(
      static fn (mixed $value): string => strtoupper(trim((string) $value)),
      [$department['code'] ?? '', $department['shortName'] ?? '']
    ))));
    $input['departmentId'] = $departmentId;
    $input['eligibility'] = [
      'branches' => $branchCodes,
      'departments' => [$departmentId],
    ];
    $input['status'] = 'pending';
    $input['audience'] = $input['audience'] ?? 'student';

    $savedPosterPath = '';
    $posterFile = $_FILES['poster'] ?? $_FILES['image'] ?? null;
    if (is_array($posterFile) && ($posterFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $savedPoster = $this->storeJobPoster($posterFile, 'alumni_' . (string) $user['_id']);
      $input['posterUrl'] = $savedPoster['url'];
      $input['posterType'] = $savedPoster['type'];
      if ($savedPoster['type'] === 'image') {
        $input['imageUrl'] = $savedPoster['url'];
      }
      $savedPosterPath = $savedPoster['path'];
    }
    try {
      $id = (new AlumniJobPostModel())->createPost((string) $user['_id'], $input, 'alumni');
    } catch (\Throwable $e) {
      if ($savedPosterPath !== '') {
        (new ObjectStorageService())->delete($savedPosterPath);
      }
      throw $e;
    }

    $created = (new AlumniJobPostModel())->findById($id);
    if (!$created) {
      $created = array_merge($input, ['_id' => $id, 'sourceType' => 'alumni']);
    }
    (new \PMS\Services\JobPostApprovalService())->notifyReviewers($created, $user);
    Response::success(['id' => $id, 'status' => 'pending'], 'Job post submitted for approval.', 201);
  }

  /**
   * @param array<string, mixed> $file
   * @return array{url:string,path:string,type:string}
   */
  private function storeJobPoster(array $file, string $prefix): array
  {
    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $file,
      (int) ($config['uploads']['max_job_poster'] ?? 10 * 1024 * 1024),
      ['jpg', 'jpeg', 'png', 'webp', 'pdf']
    );
    if ($error) {
      Response::error($error, 400);
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix)
      . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $storage = new ObjectStorageService($config);
    try {
      $path = $storage->putUploadedFile(
        ObjectStorageService::FOLDER_JOB_POSTERS,
        $filename,
        $file
      );
    } catch (\Throwable $e) {
      Response::error('Failed to save the job poster to S3: ' . $e->getMessage(), 500);
    }
    $storedName = $storage->storedNameFromUri($path);
    return [
      'url' => $storage->mediaUrl(ObjectStorageService::FOLDER_JOB_POSTERS, $storedName),
      'path' => $path,
      'type' => $ext === 'pdf' ? 'pdf' : 'image',
    ];
  }

  /** GET /api/alumni/jobs */
  public function listJobs(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
    if (!$profile || ($profile['isWorking'] ?? false) === true) {
      Response::forbidden('This feed is available to job-seeking alumni.');
    }
    Response::success((new JobFeedService())->listForSeekingAlumni($user));
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
      'contactNumber' => 'required|phone',
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
    $allDrives = (new DriveModel())->findAll([], 50);
    $drives = array_values(array_filter(
      $allDrives,
      static fn (array $drive): bool => !in_array(
        \PMS\Services\DriveLifecycle::effectiveStatus($drive),
        ['completed', 'closed'],
        true
      )
    ));
    $engine = new EligibilityEngine();
    $studentId = (string) $student['_id'];
    $companyModel = new CompanyModel();
    $appModel = new ApplicationModel();
    $drives = array_values(array_filter(
      $drives,
      static function (array $drive) use ($engine, $student, $studentId, $appModel): bool {
        if ($appModel->findByStudentAndDrive($studentId, (string) ($drive['_id'] ?? ''))) {
          return true;
        }
        return $engine->driveVisibleToStudent($student, $drive);
      }
    ));

    $result = array_map(function ($drive) use ($engine, $studentId, $companyModel, $appModel) {
      $serialized = DocumentHelper::serialize($drive);
      $serialized['eligibility'] = $engine->checkForDrive($studentId, (string) $drive['_id']);

      $company = $companyModel->findById((string) ($drive['companyId'] ?? ''));
      $companyName = (string) ($company['companyName'] ?? '');
      if ($companyName === '') {
        $title = (string) ($drive['title'] ?? '');
        if (str_contains($title, '—')) {
          $companyName = trim((string) (explode('—', $title, 2)[1] ?? ''));
        }
      }
      $serialized['companyName'] = $companyName;
      $app = $appModel->findByStudentAndDrive($studentId, (string) $drive['_id']);
      $serialized['applied'] = (bool) $app;
      $serialized['applicationStatus'] = $app['status'] ?? null;

      return $serialized;
    }, $drives);

    $result = array_values(array_filter(
        $result,
        static function (array $row): bool {
            if (!empty($row['applied'])) {
                return true;
            }

            return ($row['eligibility']['eligible'] ?? false) === true;
        }
    ));

    Response::success($result);
  }

  /** GET /api/alumni/resumes */
  public function listResumes(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $student = (new StudentModel())->findByUserId((string) $user['_id']);
    if (!$student) {
      Response::success([]);
      return;
    }

    $rows = (new ResumeModel())->findByStudent((string) $student['_id'], 50);
    $out = array_map(static function (array $r) {
      return [
        '_id'         => (string) $r['_id'],
        'label'       => $r['label'] ?? '',
        'profileType' => $r['profileType'] ?? '',
        'fileName'    => $r['fileName'] ?? '',
        'fileSize'    => (int) ($r['fileSize'] ?? 0),
        'verified'    => (bool) ($r['verified'] ?? false),
        'isDefault'   => (bool) ($r['isDefault'] ?? false),
        'uploadedAt'  => isset($r['uploadedAt']) ? DocumentHelper::serialize($r['uploadedAt']) : null,
        'viewUrl'     => '/backend/api/student/resumes/' . (string) $r['_id'] . '/view',
      ];
    }, $rows);

    Response::success($out);
  }

  /** POST /api/alumni/apply */
  public function apply(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $student = (new StudentModel())->findByUserId((string) $user['_id']);
    if (!$student) {
      Response::error('Alumni must have a linked student profile to apply.', 403);
    }
    $uploads = new ApplicationUploadService();
    $input = $uploads->parseApplyInput();
    $errors = Validator::validate($input, ['driveId' => 'required']);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $studentId = (string) $student['_id'];
    $driveId = $input['driveId'];
    $resumeId = (string) ($input['resumeId'] ?? '');

    $check = (new EligibilityEngine())->checkForDrive($studentId, $driveId, $resumeId !== '' ? $resumeId : null);
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

    $student = (new StudentModel())->findById($studentId) ?? $student;
    $student = $uploads->applyDobToProfile($student, $input, new StudentModel());
    $resume = $uploads->resolveResume($input, $student, $studentId);

    try {
      $certificates = $uploads->storeCertificates((string) ($student['registerNumber'] ?? ''));
    } catch (\RuntimeException $e) {
      Response::error($e->getMessage(), 422);
    }

    $createData = [
      'studentId' => $studentId,
      'driveId'   => $driveId,
      'companyId' => (string) $drive['companyId'],
      'status'    => ($student['resume']['verified'] ?? false) ? 'resume_verified' : 'applied',
    ];
    if ($resume !== null) {
      $createData['resume'] = $resume;
    }
    if ($certificates !== []) {
      $createData['certificates'] = $certificates;
    }

    $dob = trim((string) ($input['dob'] ?? ($student['personal']['dob'] ?? '')));
    if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
      $createData['applicantDob'] = $dob;
      try {
        $born = new \DateTimeImmutable($dob);
        $age = (int) $born->diff(new \DateTimeImmutable('today'))->y;
        if ($age >= 0 && $age <= 120) {
          $createData['applicantAge'] = $age;
        }
      } catch (\Throwable) {
        // Age is optional metadata.
      }
    }

    $driveFields = DriveModel::normalizeApplicationFields($drive['applicationFields'] ?? []);
    try {
      $customAnswers = $uploads->parseCustomAnswers($input, $driveFields);
    } catch (\RuntimeException $e) {
      Response::error($e->getMessage(), 422);
    }
    if ($customAnswers !== []) {
      $createData['customAnswers'] = $customAnswers;
    }

    $appId = $appModel->createApplication($createData);

    (new NotificationService())->notifyUser(
      (string) $user['_id'],
      'application_submitted',
      'Application Submitted',
      'Your alumni drive application has been submitted.',
      ['applicationId' => $appId]
    );

    Response::success(['applicationId' => $appId], 'Application submitted.', 201);
  }

  /** GET /api/alumni/applications */
  public function myApplications(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $student = (new StudentModel())->findByUserId((string) $user['_id']);
    if (!$student) {
      Response::success([]);
      return;
    }
    $apps = (new ApplicationModel())->findByStudent((string) $student['_id']);
    $rows = (new OfficerDataService())->enrichApplications($apps);
    $rows = (new RecruitmentResultService())->mergeIntoApplicationRows(
      $rows,
      (string) ($student['registerNumber'] ?? '')
    );
    Response::success($rows);
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

  /** POST /api/alumni/notifications/delete-selected */
  public function deleteSelectedNotifications(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
    $ids = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $ids)));
    if ($ids === []) {
      Response::error('Select at least one notification to delete.', 422);
    }
    $count = (new NotificationModel())->deleteOwned((string) $user['_id'], $ids);
    Response::success(['deleted' => $count], $count === 1 ? 'Notification deleted.' : "{$count} notifications deleted.");
  }

  /** POST /api/alumni/notifications/delete-all */
  public function deleteAllNotifications(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $readOnly = !array_key_exists('readOnly', $input) || filter_var($input['readOnly'], FILTER_VALIDATE_BOOL);
    $count = (new NotificationModel())->deleteAllForUser((string) $user['_id'], $readOnly);
    Response::success(
      ['deleted' => $count],
      $readOnly ? 'All read notifications deleted.' : 'All notifications deleted.'
    );
  }

  /** GET /api/alumni/success-stories */
  public function listSuccessStories(): void
  {
    $user = RBACMiddleware::requireAlumni();
    try {
      $rows = (new SuccessStoryModel())->findByAlumni((string) $user['_id']);
      $out = [];
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $out[] = SuccessStoryModel::serializeForApi($row);
      }
      Response::success($out);
    } catch (\Throwable $e) {
      Response::error('Could not load success stories: ' . $e->getMessage(), 500);
    }
  }

  /** POST /api/alumni/success-stories */
  public function createSuccessStory(): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    if (!is_array($input)) {
      $input = [];
    }
    $errors = Validator::validate($input, [
      'company' => 'required',
      'role'    => 'required',
      'quote'   => 'required',
    ]);
    if (!empty($errors)) {
      Response::error('Validation failed.', 422, $errors);
    }

    $profileModel = new AlumniModel();
    $profile = $profileModel->findByUserId((string) $user['_id']);
    $company = trim((string) ($input['company'] ?? ''));
    if ($company === '' && is_array($profile)) {
      $company = trim((string) ($profile['company'] ?? ''));
    }
    $role = trim((string) ($input['role'] ?? ''));
    if ($role === '' && is_array($profile)) {
      $role = trim((string) ($profile['role'] ?? ''));
    }
    $package = trim((string) ($input['package'] ?? ''));
    if ($package === '' && is_array($profile)) {
      $package = trim((string) ($profile['package'] ?? ''));
    }
    $name = trim((string) ($input['name'] ?? $user['name'] ?? ''));
    if ($name === '') {
      $name = (string) ($user['name'] ?? 'Alumni');
    }
    $quote = trim((string) ($input['quote'] ?? ''));
    if ($company === '' || $role === '' || $quote === '') {
      Response::error('Company, role, and story text are required.', 422);
    }

    $model = new SuccessStoryModel();
    try {
      $id = $model->createStory(
        (string) $user['_id'],
        (string) ($user['name'] ?? 'Alumni'),
        [
          'name'    => $name,
          'company' => $company,
          'role'    => $role,
          'package' => $package,
          'quote'   => $quote,
        ]
      );

      if (is_array($profile) && $package !== '' && trim((string) ($profile['package'] ?? '')) === '') {
        try {
          $profileModel->updateProfile((string) $profile['_id'], ['package' => $package]);
        } catch (\Throwable) {
          // Story save should still succeed if package sync fails.
        }
      }

      $created = $model->findById($id);
      Response::success(
        SuccessStoryModel::serializeForApi($created ?? ['_id' => $id, 'name' => $name, 'company' => $company, 'role' => $role, 'package' => $package, 'quote' => $quote, 'status' => 'published', 'alumniUserId' => (string) $user['_id']]),
        'Success story published on the public portal.',
        201
      );
    } catch (\InvalidArgumentException $e) {
      Response::error($e->getMessage(), 422);
    } catch (\Throwable $e) {
      Response::error('Could not save success story: ' . $e->getMessage(), 500);
    }
  }

  /** PUT /api/alumni/success-stories/{id} */
  public function updateSuccessStory(string $id): void
  {
    $user = RBACMiddleware::requireAlumni();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    if (!is_array($input)) {
      $input = [];
    }
    $model = new SuccessStoryModel();
    try {
      if (!$model->updateStory($id, (string) $user['_id'], $input)) {
        Response::error('Story not found or no valid fields to update.', 404);
      }
      $updated = $model->findById($id);
      Response::success(
        SuccessStoryModel::serializeForApi($updated ?? ['_id' => $id]),
        'Success story updated.'
      );
    } catch (\Throwable $e) {
      Response::error('Could not update success story: ' . $e->getMessage(), 500);
    }
  }

  /** DELETE /api/alumni/success-stories/{id} */
  public function deleteSuccessStory(string $id): void
  {
    $user = RBACMiddleware::requireAlumni();
    try {
      if (!(new SuccessStoryModel())->deleteStory($id, (string) $user['_id'])) {
        Response::notFound('Story not found.');
      }
      Response::success(['id' => $id], 'Success story removed.');
    } catch (\Throwable $e) {
      Response::error('Could not delete success story: ' . $e->getMessage(), 500);
    }
  }

  /**
   * @param array<int, string> $savedPaths
   */
  private function saveEmploymentUpload(
    string $field,
    string $userKey,
    string $safeCompany,
    string $prefix,
    array $extensions,
    array &$savedPaths
  ): string {
    if (!isset($_FILES[$field])) {
      throw new \RuntimeException('Missing upload field.');
    }

    $uploadError = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
      throw new \RuntimeException('No file uploaded.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
      throw new \RuntimeException(ucfirst(str_replace('_', ' ', $prefix)) . ' upload failed.');
    }

    $config = require dirname(__DIR__) . '/config/app.php';
    $error = Security::validateUploadedFile(
      $_FILES[$field],
      $config['uploads']['max_resume'],
      $extensions
    );
    if ($error) {
      throw new \RuntimeException(ucfirst(str_replace('_', ' ', $prefix)) . ': ' . $error);
    }

    $ext = strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
    $storedName = 'alumni_' . $userKey . '_' . $safeCompany . '_' . $prefix . '_' . time() . '.' . $ext;
    $storage = new ObjectStorageService($config);
    try {
      $path = $storage->putUploadedFile(
        ObjectStorageService::FOLDER_ALUMNI_EMPLOYMENT,
        $storedName,
        $_FILES[$field]
      );
    } catch (\Throwable $e) {
      throw new \RuntimeException('Failed to save ' . str_replace('_', ' ', $prefix) . ' to S3: ' . $e->getMessage());
    }

    $savedPaths[] = $path;

    return $path;
  }

  private function streamEmploymentDoc(string $field): void
  {
    $user = RBACMiddleware::requireAlumni();
    $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
    if (!$profile) {
      Response::notFound('Alumni profile not found.');
    }

    $docs = is_array($profile['employmentDocs'] ?? null) ? $profile['employmentDocs'] : null;
    $stored = (string) ($docs[$field] ?? '');
    if ($stored === '') {
      Response::notFound('Document not found.');
    }

    $downloadName = basename(str_replace('\\', '/', $stored));
    $storage = new ObjectStorageService();
    $mime = $storage->guessMime($downloadName);
    try {
      $storage->streamWithFallback(
        $stored,
        $downloadName,
        $mime,
        true,
        ObjectStorageService::FOLDER_ALUMNI_EMPLOYMENT
      );
    } catch (\Throwable) {
      Response::notFound('Document file missing on server.');
    }
  }
}
