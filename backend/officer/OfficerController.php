<?php



declare(strict_types=1);



namespace PMS\Officer;



use PMS\Middleware\RBACMiddleware;

use PMS\Models\ApplicationModel;

use PMS\Models\DriveModel;

use PMS\Models\NotificationModel;

use PMS\Models\RecruitmentResultModel;

use PMS\Models\StudentModel;

use PMS\Models\UserModel;

use PMS\Services\ApplicationWorkflowService;
use PMS\Services\NotificationService;
use PMS\Services\OfficerDataService;
use PMS\Services\PlacementOfficerContext;
use PMS\Services\AnalyticsService;
use PMS\Services\RecruitingService;
use PMS\Services\TrackingService;

use PMS\Utils\DocumentHelper;

use PMS\Utils\Response;

use PMS\Utils\Security;

use PMS\Utils\Validator;



/**

 * Department-wise placement officers & admin — drives and application workflow.

 */

final class OfficerController

{

    /** GET /api/officer/profile */

    public function profile(): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);



        Response::success([

            'user'       => DocumentHelper::serialize($user),

            'isAdmin'    => $ctx['isAdmin'],

            'department' => $ctx['department'] ? DocumentHelper::serialize($ctx['department']) : null,

            'designation'=> $ctx['profile']['designation'] ?? null,

        ]);

    }



    /** POST /api/officer/drives */

    public function createDrive(): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);

        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);



        $errors = Validator::validate($input, [

            'title'     => 'required',

            'companyId' => 'required',

            'type'      => 'required|in:exclusive,pooled,direct',

            'date'      => 'required',

            'time'      => 'required',

        ]);

        if (!empty($errors)) {

            Response::error('Validation failed.', 422, $errors);

        }



        if (isset($input['eligibility']) && is_string($input['eligibility'])) {

            $input['eligibility'] = json_decode($input['eligibility'], true) ?? [];

        }

        if (isset($input['branches']) && is_string($input['branches'])) {

            $input['branches'] = json_decode($input['branches'], true) ?? [];

        }



        $input = PlacementOfficerContext::applyDepartmentToDriveInput($input, $ctx);



        if (isset($_FILES['jd'])) {

            $config = require dirname(__DIR__) . '/config/app.php';

            $err = Security::validateUploadedFile($_FILES['jd'], $config['uploads']['max_jd'], ['pdf', 'doc', 'docx']);

            if ($err) {

                Response::error($err, 400);

            }

            $dir = $config['uploads']['jd_dir'];

            if (!is_dir($dir)) {

                mkdir($dir, 0755, true);

            }

            $input['jdFile'] = $dir . '/' . time() . '_' . basename($_FILES['jd']['name']);

            move_uploaded_file($_FILES['jd']['tmp_name'], $input['jdFile']);

        }



        $id = (new DriveModel())->createDrive($input, (string) $user['_id']);

        $notifyUserIds = $ctx['isAdmin'] ? null : PlacementOfficerContext::userIdsInDepartment($ctx);

        (new NotificationService())->announceDrive($input['title'], $input['date'], $notifyUserIds);

        Response::success(['id' => $id], 'Drive created.', 201);

    }



    /** GET /api/officer/drives */

    public function listDrives(): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);

        $driveModel = new DriveModel();



        if ($ctx['isAdmin']) {

            $drives = $driveModel->findAll([], 100);

        } else {

            $deptOid = Security::toObjectId($ctx['departmentId']);

            $deptCode = $ctx['department']['code'] ?? '';

            $or = [

                ['departmentId' => $deptOid],

                ['branches' => []],

            ];

            if ($deptCode !== '') {

                $or[] = ['branches' => $deptCode];

            }

            $drives = $driveModel->findAll(['$or' => $or], 100);

        }



        Response::success((new OfficerDataService())->enrichDrivesWithCompany($drives));

    }

    /** PUT /api/officer/drives/{id} */
    public function updateDrive(string $driveId): void
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $ctx = PlacementOfficerContext::resolve($user);
        $driveModel = new DriveModel();
        $drive = $driveModel->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }

        if (!$ctx['isAdmin'] && !PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {
            Response::forbidden('This drive is not for your department.');
        }

        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (isset($input['eligibility']) && is_string($input['eligibility'])) {
            $input['eligibility'] = json_decode($input['eligibility'], true) ?? [];
        }
        $allowed = ['title','type','date','time','eligibility','tier','status','branches'];
        $update = array_intersect_key($input, array_flip($allowed));
        if (isset($update['eligibility']) && is_array($update['eligibility'])) {
            $update['eligibility'] = array_merge($drive['eligibility'] ?? [], $update['eligibility']);
        }

        // For placement officers, keep drive scoped to their department
        if (!$ctx['isAdmin']) {
            $update = PlacementOfficerContext::applyDepartmentToDriveInput($update, $ctx);
        }

        $driveModel->update($driveId, $update);
        Response::success(null, 'Drive updated.');
    }

    /** DELETE /api/officer/drives/{id} */
    public function deleteDrive(string $driveId): void
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $ctx = PlacementOfficerContext::resolve($user);
        $driveModel = new DriveModel();
        $drive = $driveModel->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }

        if (!$ctx['isAdmin'] && !PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {
            Response::forbidden('This drive is not for your department.');
        }

        $driveModel->delete($driveId);
        Response::success(null, 'Drive deleted.');
    }



    /** POST /api/officer/drives/{id}/attendance */

    public function markAttendance(string $driveId): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);

        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        $errors = Validator::validate($input, [

            'studentId' => 'required',

            'present'   => 'required',

        ]);

        if (!empty($errors)) {

            Response::error('Validation failed.', 422, $errors);

        }



        PlacementOfficerContext::assertStudentInDepartment($input['studentId'], $ctx);



        $drive = (new DriveModel())->findById($driveId);

        if (!$drive) {

            Response::notFound('Drive not found.');

        }

        if (!PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {

            Response::forbidden('This drive is not for your department.');

        }



        (new DriveModel())->markAttendance($driveId, $input['studentId'], (bool) $input['present']);

        Response::success(null, 'Attendance updated.');

    }



    /** POST /api/officer/applications/{id}/approve */

    public function approveApplication(string $appId): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);

        $appModel = new ApplicationModel();

        $app = $appModel->findById($appId);

        if (!$app) {

            Response::notFound();

        }



        PlacementOfficerContext::assertStudentInDepartment((string) $app['studentId'], $ctx);



        (new ApplicationWorkflowService())->transition($appId, 'officer_approved', (string) $user['_id']);

        $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
        if ($student && !empty($student['userId'])) {
            (new NotificationService())->notifyApplicationUpdate(
                (string) $student['userId'],
                'Application Approved',
                'Your application has been approved by the placement officer and is moving forward in the pipeline.'
            );
        }

        Response::success(null, 'Application approved by placement officer.');

    }



    /** GET /api/officer/dashboard */
    public function dashboard(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->dashboardStats($scope['ctx']));
    }

    /** GET /api/officer/students */
    public function listStudents(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listStudents($scope['ctx']));
    }

    /** GET /api/officer/applications */
    public function listApplications(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $filter = [];
        if (!empty($_GET['status'])) {
            $filter['status'] = $_GET['status'];
        }
        if (!empty($_GET['driveId'])) {
            $driveOid = Security::toObjectId((string) $_GET['driveId']);
            if ($driveOid) {
                $filter['driveId'] = $driveOid;
            }
        }
        Response::success((new OfficerDataService())->listApplications($scope['ctx'], $filter));
    }

    /** GET /api/officer/resumes/pending */
    public function listPendingResumes(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listPendingResumes($scope['ctx']));
    }

    /** GET /api/officer/resumes */
    public function listResumes(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        Response::success((new OfficerDataService())->listResumeQueue($scope['ctx']));
    }

    /** GET /api/officer/students/{id}/resume */
    public function downloadStudentResume(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamStudentResume($studentId, $scope['ctx']);
    }

    /** POST /api/officer/students/{id}/verify-resume */
    public function verifyResume(string $studentId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        PlacementOfficerContext::assertStudentInDepartment($studentId, $scope['ctx']);

        $model = new StudentModel();
        $student = $model->findById($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }

        $resume = $student['resume'] ?? [];
        $resume['verified'] = true;
        $model->update($studentId, ['resume' => $resume]);
        (new ApplicationWorkflowService())->onResumeVerified($studentId, (string) $scope['user']['_id']);

        $userId = (string) ($student['userId'] ?? '');
        if ($userId) {
            (new NotificationService())->notifyUser(
                $userId,
                'resume_verified',
                'Resume Verified',
                'Your resume has been verified. Your application will proceed to placement officer review.'
            );
        }
        Response::success(null, 'Resume verified.');
    }

    /** GET /api/officer/results */
    public function listResults(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $filter = (new OfficerDataService())->resultFilterFromRequest();
        Response::success((new OfficerDataService())->listResults($scope['ctx'], $filter));
    }

    /** POST /api/officer/results */
    public function upsertResult(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'studentName'    => 'required|min:2',
            'registerNumber' => 'required',
            'company'        => 'required',
            'role'           => 'required',
            'status'         => 'required|in:selected,rejected',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        (new OfficerDataService())->assertResultRegisterInScope((string) $input['registerNumber'], $scope['ctx']);
        if (!$scope['ctx']['isAdmin'] && !empty($scope['ctx']['departmentId'])) {
            $input['departmentId'] = $scope['ctx']['departmentId'];
        }

        try {
            $id = (new RecruitmentResultModel())->upsertByRegisterCompany($input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success(['id' => $id], 'Result saved.');
    }

    /** GET /api/officer/analytics */
    public function analytics(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        Response::success((new AnalyticsService())->getDashboardAnalytics($deptId));
    }

    /** POST /api/officer/applications/{id}/reject */
    public function rejectApplication(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->assertApplicationInScope($appId, $scope['ctx']);
        $app = (new ApplicationModel())->findById($appId);
        (new ApplicationWorkflowService())->transition($appId, 'rejected', (string) $scope['user']['_id']);
        if ($app) {
            $student = (new StudentModel())->findById((string) ($app['studentId'] ?? ''));
            if ($student && !empty($student['userId'])) {
                (new NotificationService())->notifyApplicationUpdate(
                    (string) $student['userId'],
                    'Application Rejected',
                    'Your application was rejected by the placement officer. Contact the placement cell for details.'
                );
            }
        }
        Response::success(null, 'Application rejected.');
    }

    /** GET /api/officer/applications/{id}/resume */
    public function downloadApplicationResume(string $appId): void
    {
        $scope = (new OfficerDataService())->requireScope();
        (new OfficerDataService())->streamApplicationResume($appId, $scope['ctx']);
    }

    /** GET /api/officer/applications/pending */
    public function pendingApplications(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $filter = ['status' => ['$in' => ['applied', 'resume_verified']]];
        Response::success((new OfficerDataService())->listApplications($scope['ctx'], $filter));
    }



    /** POST /api/officer/users/{id}/approve — approve student registration */

    public function approveStudent(string $userId): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);



        $userModel = new UserModel();

        $target = $userModel->findById($userId);

        if (!$target || ($target['role'] ?? '') !== 'student') {

            Response::error('Only student accounts can be approved here.', 400);

        }



        PlacementOfficerContext::assertUserStudentInDepartment($userId, $ctx);



        $userModel->approveUser($userId);

        (new NotificationService())->notifyUser(

            $userId,

            'registration_approved',

            'Registration Approved',

            'Your student registration has been approved by the placement officer.'

        );

        Response::success(null, 'Student registration approved.');

    }



    /** GET /api/officer/students/pending */

    public function pendingStudents(): void

    {

        $user = RBACMiddleware::requirePlacementOfficer();

        $ctx = PlacementOfficerContext::resolve($user);



        if ($ctx['isAdmin']) {

            $users = (new UserModel())->findAll(['role' => 'student', 'approved' => false], 200);

            Response::success(DocumentHelper::serializeMany($users));

            return;

        }



        $deptUserIds = PlacementOfficerContext::userIdsInDepartment($ctx);

        if (empty($deptUserIds)) {

            Response::success([]);

            return;

        }



        $oids = array_values(array_filter(array_map(

            fn (string $id) => Security::toObjectId($id),

            $deptUserIds

        )));

        $users = (new UserModel())->findAll([

            'role'     => 'student',

            'approved' => false,

            '_id'      => ['$in' => $oids],

        ], 200);



        Response::success(DocumentHelper::serializeMany($users));

    }

    /** GET /api/officer/tracking */
    public function placementTracking(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        $limit = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 100;
        Response::success((new TrackingService())->getOverview($deptId, $limit));
    }

    /** GET /api/officer/analytics/extended */
    public function extendedAnalytics(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        Response::success((new AnalyticsService())->getExtendedAnalytics($deptId));
    }

    /** GET /api/officer/placement-console */
    public function placementConsole(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        Response::success((new AnalyticsService())->getPlacementConsole($deptId));
    }

    /** GET /api/officer/recruiting */
    public function recruitingOverview(): void
    {
        $scope = (new OfficerDataService())->requireScope();
        $deptId = $scope['ctx']['isAdmin'] ? null : $scope['ctx']['departmentId'];
        Response::success((new RecruitingService())->getCampusOverview($deptId));
    }

    /** GET /api/officer/notifications */
    public function notifications(): void
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $notifs = (new NotificationModel())->findByUser((string) $user['_id']);
        Response::success(DocumentHelper::serializeMany($notifs));
    }

    /** POST /api/officer/notifications/{id}/read */
    public function markNotificationRead(string $id): void
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $notif = (new NotificationModel())->findById($id);
        if (!$notif || (string) ($notif['userId'] ?? '') !== (string) $user['_id']) {
            Response::notFound();
        }
        (new NotificationModel())->markRead($id);
        Response::success(null, 'Notification marked as read.');
    }

    /** POST /api/officer/notifications/read-all */
    public function markAllNotificationsRead(): void
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $count = (new NotificationModel())->markAllRead((string) $user['_id']);
        Response::success(['updated' => $count], 'All notifications marked as read.');
    }

}


