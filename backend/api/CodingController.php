<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\AptitudeAccessService;
use PMS\Services\CodeExecutionService;
use PMS\Services\CodingService;
use PMS\Services\CodingTestCaseChecker;
use PMS\Utils\Response;

final class CodingController
{
    private ?CodingService $service = null;

    private function service(): CodingService
    {
        return $this->service ??= new CodingService();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function access(): void
    {
        $user = AuthMiddleware::authenticate();
        $role = AuthMiddleware::resolvedRole($user);
        Response::success([
            'canTake' => AptitudeAccessService::canTake($user),
            'canManage' => AptitudeAccessService::canManageCoding($user),
            'canViewDirectory' => AptitudeAccessService::canViewDirectory($user),
            'role' => $role,
            'scope' => AptitudeAccessService::scopeInfo($user),
        ]);
    }

    public function meta(): void
    {
        AuthMiddleware::authenticate();
        Response::success([
            'categories' => \PMS\Models\CodingTestModel::CATEGORIES,
            'difficulties' => \PMS\Models\CodingTestModel::DIFFICULTIES,
            'statuses' => \PMS\Models\CodingTestModel::STATUSES,
        ]);
    }

    public function listTests(): void
    {
        $user = AptitudeAccessService::requirePortalUser();
        $manage = isset($_GET['manage']) && (string) $_GET['manage'] === '1';
        $tests = ($manage && AptitudeAccessService::canManageCoding($user))
            ? $this->service()->listAllForAdmin($user)
            : $this->service()->listPublishedForUser($user);
        Response::success(['tests' => $tests]);
    }

    public function createTest(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->createTest($user, $this->body()), 'Coding test created.');
    }

    public function updateTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->updateTest($user, $id, $this->body()), 'Coding test updated.');
    }

    public function deleteTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteTest($user, $id);
        Response::success(null, 'Coding test deleted.');
    }

    public function listBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $difficulty = isset($_GET['difficulty']) ? trim((string) $_GET['difficulty']) : null;
        Response::success(['problems' => $this->service()->listBank($user, $category ?: null, $difficulty ?: null)]);
    }

    public function generateAiBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $jdText = trim((string) ($body['jobDescription'] ?? $body['jobDescriptionText'] ?? ''));
        if ($jdText !== '' || !empty($body['generationMode']) && (string) $body['generationMode'] === 'jd') {
            Response::success($this->service()->generateAiCompanyBlockProblems($user, $body));
            return;
        }
        Response::success($this->service()->generateAiBankProblems($user, $body));
    }

    public function saveAiBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $problems = is_array($body['problems'] ?? null) ? $body['problems'] : (is_array($body['questions'] ?? null) ? $body['questions'] : []);
        Response::success($this->service()->saveAiBankProblems($user, $problems), 'Problems saved.');
    }

    public function progressFilters(): void
    {
        $user = AuthMiddleware::authenticate();
        $filters = [
            'department' => $_GET['department'] ?? ($_GET['departmentId'] ?? ''),
            'course' => $_GET['course'] ?? ($_GET['branch'] ?? ''),
            'class' => $_GET['class'] ?? ($_GET['batch'] ?? ($_GET['classBatch'] ?? '')),
        ];
        Response::success((new \PMS\Services\AptitudeService())->progressFilterOptions($user, $filters));
    }

    public function createBankProblem(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->saveBankProblem($user, $this->body()), 'Problem saved.');
    }

    public function updateBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->saveBankProblem($user, $this->body(), $id), 'Problem saved.');
    }

    public function deleteBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteBankProblem($user, $id);
        Response::success(null, 'Problem deleted.');
    }

    /** POST /api/coding/problem-bank/bulk-delete */
    public function bulkDeleteBankProblems(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        Response::success(
            $this->service()->bulkDeleteBankProblems($user, $ids),
            'Problems deleted.'
        );
    }

    public function start(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->start($user, $id));
    }

    public function saveDraft(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->saveDraft($user, $id, $this->body()), 'Draft saved.');
    }

    public function submit(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->submit($user, $id, $this->body()), 'Submitted.');
    }

    public function attemptResult(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->attemptResult($user, $id));
    }

    public function myProgress(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->myProgress($user));
    }

    public function progressDirectory(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->directory($user, $_GET));
    }

    /** POST /api/coding/tests/{id}/publish-results */
    public function publishResults(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $published = array_key_exists('published', $body)
            ? filter_var($body['published'], FILTER_VALIDATE_BOOLEAN)
            : true;
        Response::success(
            $this->service()->publishContestResults($user, $id, $published),
            $published ? 'Contest results published.' : 'Contest results hidden from students.'
        );
    }

    /** GET /api/coding/tests/{id}/contest-results */
    public function contestResultsPreview(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->contestResultsPreview($user, $id));
    }

    public function contestBoard(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->contestBoard($user));
    }

    public function subjectProgress(string $userId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->subjectProgress($user, $userId));
    }

    /** GET /api/coding/company-block/companies */
    public function listCompanyBlockCompanies(): void
    {
        AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockCompanies());
    }

    /** GET /api/coding/company-block */
    public function listCompanyBlock(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockForAdmin($user));
    }

    /** GET /api/coding/company-block/sets/{id} */
    public function getCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->getCompanyBlockSet($user, $id, false));
    }

    /** DELETE /api/coding/company-block/sets/{id} */
    public function deleteCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteCompanyBlockSet($user, $id);
        Response::success(null, 'Company problem set deleted.');
    }

    /** GET /api/coding/company-block/sets/{id}/document */
    public function streamCompanyBlockDocument(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->streamCompanyBlockDocument($user, $id, false);
    }

    /** GET /api/coding/student/company-block/sets/{id}/document */
    public function streamStudentCompanyBlockDocument(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->streamCompanyBlockDocument($user, $id, true);
    }

    /** POST /api/coding/ai/extract-jd */
    public function extractAiJobDescription(): void
    {
        $user = AuthMiddleware::authenticate();
        if (!isset($_FILES['jd']) || !is_array($_FILES['jd'])) {
            Response::error('No file uploaded.', 422);
        }
        Response::success(
            $this->service()->extractAiJobDescription($user, $_FILES['jd']),
            'Job description text extracted.'
        );
    }

    /** POST /api/coding/ai/save-company-block */
    public function saveAiCompanyBlockSet(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $problems = is_array($body['problems'] ?? null) ? $body['problems'] : (is_array($body['questions'] ?? null) ? $body['questions'] : []);
        Response::success(
            $this->service()->saveAiCompanyBlockSet(
                $user,
                $problems,
                (string) ($body['setTitle'] ?? $body['jdTitle'] ?? ''),
                (string) ($body['companyId'] ?? ''),
                isset($body['companyName']) ? (string) $body['companyName'] : null,
                isset($body['jdFilename']) ? (string) $body['jdFilename'] : null,
                isset($body['jdFile']) ? (string) $body['jdFile'] : null,
                isset($body['jdFileUrl']) ? (string) $body['jdFileUrl'] : null,
                isset($body['jdMimeType']) ? (string) $body['jdMimeType'] : null
            ),
            'Company block problems saved.'
        );
    }

    /** GET /api/coding/student/company-block */
    public function listStudentCompanyBlock(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockForStudent($user));
    }

    /** GET /api/coding/student/company-block/sets/{id} */
    public function getStudentCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->getCompanyBlockSet($user, $id, true));
    }

    /** GET /api/coding/problems */
    public function listPracticeProblems(): void
    {
        $user = AuthMiddleware::authenticate();
        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $difficulty = isset($_GET['difficulty']) ? trim((string) $_GET['difficulty']) : null;
        Response::success(['problems' => $this->service()->listPracticeProblems($user, $category ?: null, $difficulty ?: null)]);
    }

    /** GET /api/coding/problems/{id} */
    public function getPracticeProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->getPracticeProblem($user, $id));
    }

    /** GET /api/coding/practice/submissions */
    public function listPracticeSubmissions(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(['submissions' => $this->service()->listPracticeSubmissions($user)]);
    }

    /** POST /api/coding/problems/{id}/submit */
    public function submitPracticeProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->submitPracticeProblem($user, $id, $this->body()), 'Submitted.');
    }

    /** POST /api/coding/problems/{id}/run — run editor source against sample/hidden tests (server-side inputs) */
    public function runPracticeProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        if (!AptitudeAccessService::canTake($user) && !AptitudeAccessService::canManageCoding($user)) {
            Response::forbidden('You cannot run code on this account.');
        }
        try {
            Response::success($this->service()->runPracticeProblem($user, $id, $this->body()));
        } catch (\Throwable $e) {
            Response::error(
                $e->getMessage(),
                500,
                [
                    'stage' => 'coding.practice.run',
                    'type' => $e::class,
                    'meta' => class_exists(\PMS\Utils\CodingDeployInfo::class, false)
                        ? \PMS\Utils\CodingDeployInfo::meta()
                        : null,
                ]
            );
        }
    }

    /** GET /api/coding/exec-health — deploy + bootstrap verification (authenticated) */
    public function execHealth(): void
    {
        $user = AuthMiddleware::authenticate();
        if (!AptitudeAccessService::canTake($user) && !AptitudeAccessService::canManageCoding($user)) {
            Response::forbidden('You cannot access coding execution health.');
        }
        $backendDir = dirname(__DIR__);
        $report = \PMS\Utils\CodingDeployInfo::healthReport($backendDir);
        $code = "n = int(input())\narr = list(map(int, input().split()))\n";
        $stdin = "4\n1 4 3 2";
        try {
            $exec = (new CodeExecutionService())->run('Python', $code, $stdin, 8000);
            $report['smoke'] = [
                'ok' => CodingTestCaseChecker::executionSucceeded($exec),
                'exitCode' => (int) ($exec['exit_code'] ?? -1),
                'status' => (string) ($exec['status'] ?? ''),
                'stdoutLen' => strlen((string) ($exec['stdout'] ?? '')),
                'stderrLen' => strlen((string) ($exec['stderr'] ?? '')),
                'errorSummary' => (string) ($exec['errorSummary'] ?? ''),
            ];
        } catch (\Throwable $e) {
            $report['smoke'] = [
                'ok' => false,
                'error' => $e->getMessage(),
                'type' => $e::class,
            ];
        }
        Response::success($report);
    }

    /** POST /api/coding/execute — compile/run student code (C++, Java, etc.) */
    public function execute(): void
    {
        $user = AuthMiddleware::authenticate();
        if (!AptitudeAccessService::canTake($user) && !AptitudeAccessService::canManageCoding($user)) {
            Response::forbidden('You cannot run code on this account.');
        }
        $body = $this->body();
        $language = \PMS\Utils\CodingLanguage::canonicalLabel((string) ($body['language'] ?? 'Python'));
        $source = (string) ($body['source'] ?? '');
        $stdin = (string) ($body['stdin'] ?? '');
        $timeLimitMs = max(500, min(15000, (int) ($body['timeLimitMs'] ?? 3000)));
        if (trim($source) === '') {
            Response::error('Source code is required.', 422);
        }
        $runner = new CodeExecutionService();
        $result = $runner->run($language, $source, $stdin, $timeLimitMs);
        if (is_array($result)) {
            $engine = (string) ($result['execEngine'] ?? '');
            $result['execBackend'] = $engine === 'wandbox' ? 'wandbox-v1' : 'local-v1';
        }
        Response::success($result);
    }
}
