<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\StudentModel;
use PMS\Services\StudentAiPracticeService;
use PMS\Services\StudentJdService;
use PMS\Utils\Response;

/**
 * Student JD library and AI self-practice.
 */
final class StudentPracticeController
{
    private StudentModel $students;
    private StudentJdService $jds;
    private StudentAiPracticeService $practice;

    public function __construct()
    {
        $this->students = new StudentModel();
        $this->jds = new StudentJdService();
        $this->practice = new StudentAiPracticeService();
    }

    /** GET /api/student/jds */
    public function listJds(): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        Response::success(['jds' => $this->jds->listPublishedJds($profile)]);
    }

    /** GET /api/student/jds/{id} */
    public function getJd(string $id): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        try {
            Response::success($this->jds->getPublishedJd($profile, $id));
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
    }

    /** GET /api/student/jds/{id}/document */
    public function streamJdDocument(string $id): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        $this->jds->streamJdDocument($profile, $id);
    }

    /** POST /api/student/ai-practice/generate */
    public function generatePractice(): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            Response::success(
                $this->practice->generate($profile, $body),
                'Practice questions generated.'
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 503);
        }
    }

    /** POST /api/student/ai-practice/submit */
    public function submitPractice(): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        if ($sessionId === '') {
            Response::error('Session id is required.', 422);
        }
        Response::success(
            $this->practice->submit($profile, $sessionId, $answers),
            'Practice submitted.'
        );
    }

    /** GET /api/student/ai-practice/history */
    public function practiceHistory(): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        Response::success($this->practice->listHistory($profile));
    }

    /** GET /api/student/ai-practice/{id} */
    public function getPracticeSession(string $id): void
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->students->findByUserId((string) ($user['_id'] ?? ''));
        if ($profile === null) {
            Response::notFound('Student profile not found.');
        }
        Response::success($this->practice->getSession($profile, $id));
    }
}
