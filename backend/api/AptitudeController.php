<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Middleware\RBACMiddleware;
use PMS\Services\AptitudeAccessService;
use PMS\Services\AptitudeService;
use PMS\Utils\Response;

/**
 * Aptitude mock tests API.
 */
final class AptitudeController
{
    private AptitudeService $service;

    public function __construct()
    {
        $this->service = new AptitudeService();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** GET /api/aptitude/meta */
    public function meta(): void
    {
        AuthMiddleware::authenticate();
        Response::success([
            'categories' => \PMS\Models\AptitudeTestModel::CATEGORIES,
            'difficulties' => \PMS\Models\AptitudeTestModel::DIFFICULTIES,
            'statuses' => \PMS\Models\AptitudeTestModel::STATUSES,
            'questionTypes' => ['mcq'],
            'bulkExcelHeaders' => ['prompt', 'optionA', 'optionB', 'optionC', 'optionD', 'correct', 'marks', 'explanation', 'category'],
            'bulkFormats' => ['xlsx', 'xls'],
        ]);
    }

    /** GET /api/aptitude/access */
    public function access(): void
    {
        $user = AuthMiddleware::authenticate();
        $role = AuthMiddleware::resolvedRole($user);
        Response::success([
            'canTake' => AptitudeAccessService::canTake($user),
            'canManage' => AptitudeAccessService::canManage($user),
            'canViewDirectory' => AptitudeAccessService::canViewDirectory($user),
            'canViewCompanyApplicants' => $role === 'company',
            'role' => $role,
            'alumniSeeking' => $role === 'alumni' && !AptitudeAccessService::alumniIsWorking($user),
            'scope' => AptitudeAccessService::scopeInfo($user),
        ]);
    }

    /** GET /api/aptitude/tests */
    public function listTests(): void
    {
        $user = AptitudeAccessService::requirePortalUser();
        $includeAnswers = AptitudeAccessService::canManage($user);
        $tests = $includeAnswers
            ? $this->service->listAllForAdmin($user)
            : $this->service->listPublishedForUser($user, false);
        Response::success(['tests' => $tests]);
    }

    /** POST /api/aptitude/tests */
    public function createTest(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createTest($user, $this->body()), 'Aptitude test created.');
    }

    /** POST /api/aptitude/media — rich-text image upload for MCQ editor */
    public function uploadMedia(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->uploadRichTextImage($user), 'Image uploaded.');
    }

    /** PUT /api/aptitude/tests/{id} */
    public function updateTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateTest($user, $id, $this->body()), 'Aptitude test updated.');
    }

    /** DELETE /api/aptitude/tests/{id} */
    public function deleteTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteTest($user, $id);
        Response::success(null, 'Aptitude test deleted.');
    }

    /** POST /api/aptitude/tests/{id}/questions/bulk */
    public function bulkQuestions(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $payload = $body['questions'] ?? $body['csv'] ?? $body['content'] ?? $body;
        $replace = !empty($body['replace']);
        Response::success(
            $this->service->bulkAddToTest($user, $id, $payload, $replace),
            'Questions imported.'
        );
    }

    /** GET /api/aptitude/question-bank */
    public function listBank(): void
    {
        $user = AuthMiddleware::authenticate();
        AptitudeAccessService::requireManager($user);
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $difficulty = isset($_GET['difficulty']) ? (string) $_GET['difficulty'] : null;
        Response::success($this->service->listBank($category, $difficulty));
    }

    /** POST /api/aptitude/question-bank/bulk */
    public function bulkBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $payload = $body['questions'] ?? $body['csv'] ?? $body['content'] ?? $body;
        $category = (string) ($body['category'] ?? 'General Aptitude');
        Response::success(
            $this->service->bulkAddToBank($user, $payload, $category),
            'Question bank updated.'
        );
    }

    /** DELETE /api/aptitude/question-bank/{id} */
    public function deleteBankQuestion(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteBankQuestion($user, $id);
        Response::success(null, 'Question deleted from bank.');
    }

    /** POST /api/aptitude/tests/{id}/questions/from-bank */
    public function fromBank(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $ids = array_values(array_filter(array_map('strval', (array) ($body['bankIds'] ?? $body['ids'] ?? []))));
        Response::success(
            $this->service->addBankQuestionsToTest($user, $id, $ids),
            'Bank questions added to test.'
        );
    }

    /** POST /api/aptitude/tests/{id}/start */
    public function start(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->start($user, $id), 'Attempt started.');
    }

    /** POST /api/aptitude/attempts/{id}/submit */
    public function submit(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        $meta = [
            'markedForReview' => $body['markedForReview'] ?? [],
            'timeTakenSeconds' => $body['timeTakenSeconds'] ?? null,
            'autoSubmitted' => !empty($body['autoSubmitted']),
        ];
        Response::success($this->service->submit($user, $id, $answers, $meta), 'Attempt submitted.');
    }

    /** GET /api/aptitude/attempts/{id}/result */
    public function attemptResult(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->attemptResult($user, $id));
    }

    /** GET /api/aptitude/me */
    public function myProgress(): void
    {
        $user = AuthMiddleware::authenticate();
        AptitudeAccessService::requireTaker($user);
        Response::success($this->service->myProgress($user));
    }

    /** GET /api/aptitude/progress/compare?userIds=a,b */
    public function compare(): void
    {
        $user = AuthMiddleware::authenticate();
        $raw = (string) ($_GET['userIds'] ?? '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($ids === [] && isset($_GET['userId'])) {
            $ids = array_map('strval', (array) $_GET['userId']);
        }
        Response::success($this->service->compare($user, $ids));
    }

    /** GET /api/aptitude/progress/filters */
    public function progressFilters(): void
    {
        $user = AuthMiddleware::authenticate();
        $filters = [
            'department' => $_GET['department'] ?? ($_GET['departmentId'] ?? ''),
            'course' => $_GET['course'] ?? ($_GET['branch'] ?? ''),
            'class' => $_GET['class'] ?? ($_GET['batch'] ?? ($_GET['classBatch'] ?? '')),
        ];
        Response::success($this->service->progressFilterOptions($user, $filters));
    }

    /** GET /api/aptitude/progress */
    public function progressDirectory(): void
    {
        $user = AuthMiddleware::authenticate();
        // Filters are advisory only — AptitudeAccessService clamps them to auth scope.
        $filters = [
            'batch' => $_GET['batch'] ?? '',
            'class' => $_GET['class'] ?? ($_GET['classBatch'] ?? ''),
            'course' => $_GET['course'] ?? '',
            'semester' => $_GET['semester'] ?? '',
            'department' => $_GET['department'] ?? ($_GET['departmentId'] ?? ''),
            'test' => $_GET['test'] ?? ($_GET['testId'] ?? ''),
            'category' => $_GET['category'] ?? '',
            'userType' => $_GET['userType'] ?? '',
            'resultType' => $_GET['resultType'] ?? '',
        ];
        Response::success($this->service->directory($user, $filters));
    }

    /** GET /api/aptitude/subjects/{userId} */
    public function subjectProgress(string $userId): void
    {
        $viewer = AuthMiddleware::authenticate();
        AptitudeAccessService::requireCanViewSubject($viewer, $userId);
        Response::success($this->service->progressForUserId($userId));
    }

    /** GET /api/company/applicants/{studentId}/aptitude */
    public function companyApplicantAptitude(string $studentId): void
    {
        // Company identity is taken from the session via RBAC — never from the request body.
        $user = RBACMiddleware::requireCompany();
        Response::success($this->service->companyStudentProgress($user, $studentId));
    }
}
