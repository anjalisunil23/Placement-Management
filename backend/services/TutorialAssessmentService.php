<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Middleware\AuthMiddleware;
use PMS\Models\StudentModel;
use PMS\Models\TutorialModuleAssessmentAnswerModel;
use PMS\Models\TutorialModuleAssessmentAttemptModel;
use PMS\Models\TutorialModuleAssessmentModel;
use PMS\Models\TutorialModuleQuestionModel;

/**
 * Module-wise MCQ assessments for Tutorials.
 * Separate from programming exercise attempts. Server-side scoring only.
 */
final class TutorialAssessmentService
{
    public function __construct(
        private ?TutorialService $tutorials = null,
        private ?TutorialAIService $ai = null,
        private ?TutorialModuleAssessmentModel $assessments = null,
        private ?TutorialModuleQuestionModel $questions = null,
        private ?TutorialModuleAssessmentAttemptModel $attempts = null,
        private ?TutorialModuleAssessmentAnswerModel $answers = null,
    ) {
        $this->tutorials = $tutorials ?? new TutorialService();
        $this->ai = $ai ?? new TutorialAIService(null, $this->tutorials);
        $this->assessments = $assessments ?? new TutorialModuleAssessmentModel();
        $this->questions = $questions ?? new TutorialModuleQuestionModel();
        $this->attempts = $attempts ?? new TutorialModuleAssessmentAttemptModel();
        $this->answers = $answers ?? new TutorialModuleAssessmentAnswerModel();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateForModule(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $ctx = $this->staffModuleContext($user, $tutorialId, $moduleId);
        if (isset($input['academicField']) && is_string($input['academicField']) && $input['academicField'] !== '') {
            $ctx['academicField'] = $input['academicField'];
        }

        return $this->ai->generateMcqPreview($user, $ctx, $input);
    }

    /**
     * Replace or create assessment questions from an approved AI preview.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveGenerated(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->staffModuleContext($user, $tutorialId, $moduleId);
        $normalized = $this->ai->normalizeMcqQuestions(is_array($input['questions'] ?? null) ? $input['questions'] : []);
        $selected = array_values(array_filter($normalized, static fn (array $q): bool => ($q['selected'] ?? true) !== false));
        if ($selected === []) {
            throw new \InvalidArgumentException('Select at least one generated question to save.');
        }
        $replace = ($input['replaceExisting'] ?? true) !== false;

        return $this->persistQuestions($user, $tutorialId, $moduleId, $selected, $input, $replace);
    }

    /**
     * Create/update assessment settings and full question list (manual editor save).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveAssessment(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->staffModuleContext($user, $tutorialId, $moduleId);
        $questionsIn = is_array($input['questions'] ?? null) ? $input['questions'] : [];
        $normalized = [];
        foreach ($questionsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = [
                'question' => (string) ($row['question'] ?? ''),
                'options' => (array) ($row['options'] ?? []),
                'correctIndex' => (int) ($row['correctIndex'] ?? $row['correctAnswer'] ?? -1),
                'correctAnswer' => (int) ($row['correctIndex'] ?? $row['correctAnswer'] ?? -1),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'difficulty' => (string) ($row['difficulty'] ?? 'beginner'),
                'marks' => (int) ($row['marks'] ?? 1),
                'selected' => true,
            ];
        }
        $validated = $this->ai->normalizeMcqQuestions($normalized);
        if ($validated === []) {
            throw new \InvalidArgumentException('Add at least one valid MCQ before saving.');
        }

        return $this->persistQuestions($user, $tutorialId, $moduleId, $validated, $input, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getManaged(array $user, string $tutorialId, string $moduleId): array
    {
        $this->staffModuleContext($user, $tutorialId, $moduleId);
        $assessment = $this->assessments->findByModule($moduleId);
        if ($assessment === null) {
            return [
                'assessment' => null,
                'questions' => [],
                'questionCount' => 0,
                'totalMarks' => 0,
            ];
        }

        return $this->managedView($assessment, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getForStudent(array $user, string $tutorialId, string $moduleId): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $assessment = $this->requirePublishedAssessment($tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $studentId = (string) ($student['_id'] ?? '');
        $questions = $this->questions->listByAssessment((string) ($assessment['_id'] ?? ''));
        $publicQuestions = [];
        $totalMarks = 0;
        foreach ($questions as $q) {
            $marks = (int) ($q['marks'] ?? 1);
            $totalMarks += $marks;
            $publicQuestions[] = [
                'id' => (string) ($q['_id'] ?? ''),
                'question' => (string) ($q['question'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'difficulty' => (string) ($q['difficulty'] ?? 'beginner'),
                'marks' => $marks,
                'sortOrder' => (int) ($q['sortOrder'] ?? 0),
            ];
        }
        $attempts = $this->attempts->listForStudent($studentId, (string) ($assessment['_id'] ?? ''));
        $submitted = $this->attempts->countSubmitted($studentId, (string) ($assessment['_id'] ?? ''));
        $inProgress = $this->attempts->findInProgress($studentId, (string) ($assessment['_id'] ?? ''));

        return [
            'assessment' => [
                'id' => (string) ($assessment['_id'] ?? ''),
                'title' => (string) ($assessment['title'] ?? 'Module quiz'),
                'passPercent' => (int) ($assessment['passPercent'] ?? 60),
                'maxAttempts' => (int) ($assessment['maxAttempts'] ?? 3),
                'showExplanations' => ($assessment['showExplanations'] ?? true) === true,
                'allowReview' => ($assessment['allowReview'] ?? true) === true,
                'questionCount' => count($publicQuestions),
                'totalMarks' => $totalMarks,
            ],
            'questions' => $publicQuestions,
            'attemptsUsed' => $submitted,
            'attemptsRemaining' => max(0, (int) ($assessment['maxAttempts'] ?? 3) - $submitted),
            'inProgressAttemptId' => $inProgress ? (string) ($inProgress['_id'] ?? '') : null,
            'attemptSummaries' => array_map(static function (array $row): array {
                return [
                    'id' => (string) ($row['_id'] ?? ''),
                    'attemptNumber' => (int) ($row['attemptNumber'] ?? 0),
                    'status' => (string) ($row['status'] ?? ''),
                    'score' => (int) ($row['score'] ?? 0),
                    'totalMarks' => (int) ($row['totalMarks'] ?? 0),
                    'percent' => (int) ($row['percent'] ?? 0),
                    'passed' => ($row['passed'] ?? false) === true,
                    'submittedAt' => $row['submittedAt'] ?? null,
                ];
            }, $attempts),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function startAttempt(array $user, string $tutorialId, string $moduleId): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $assessment = $this->requirePublishedAssessment($tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $studentId = (string) ($student['_id'] ?? '');
        $assessmentId = (string) ($assessment['_id'] ?? '');
        $existing = $this->attempts->findInProgress($studentId, $assessmentId);
        if ($existing !== null) {
            return [
                'attempt' => $this->publicAttempt($existing),
                'resumed' => true,
            ];
        }
        $submitted = $this->attempts->countSubmitted($studentId, $assessmentId);
        $max = (int) ($assessment['maxAttempts'] ?? 3);
        if ($submitted >= $max) {
            throw new \RuntimeException('You have used all attempts for this assessment.', 403);
        }
        $questions = $this->questions->listByAssessment($assessmentId);
        if ($questions === []) {
            throw new \InvalidArgumentException('This assessment has no questions yet.');
        }
        $totalMarks = 0;
        foreach ($questions as $q) {
            $totalMarks += (int) ($q['marks'] ?? 1);
        }
        $attempt = $this->attempts->createAttempt([
            'studentId' => $studentId,
            'assessmentId' => $assessmentId,
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'attemptNumber' => $submitted + 1,
            'totalMarks' => $totalMarks,
        ]);

        return [
            'attempt' => $this->publicAttempt($attempt),
            'resumed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submitAttempt(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $assessment = $this->requirePublishedAssessment($tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $studentId = (string) ($student['_id'] ?? '');
        $assessmentId = (string) ($assessment['_id'] ?? '');
        $attemptId = trim((string) ($input['attemptId'] ?? ''));
        $attempt = $attemptId !== '' ? $this->attempts->findById($attemptId) : $this->attempts->findInProgress($studentId, $assessmentId);
        if (!is_array($attempt) || (string) ($attempt['studentId'] ?? '') !== $studentId) {
            throw new \RuntimeException('Assessment attempt not found.', 404);
        }
        if ((string) ($attempt['assessmentId'] ?? '') !== $assessmentId) {
            throw new \RuntimeException('Assessment attempt not found.', 404);
        }
        if ((string) ($attempt['status'] ?? '') === 'SUBMITTED') {
            throw new \RuntimeException('This attempt was already submitted.', 409);
        }

        $questions = $this->questions->listByAssessment($assessmentId);
        $byId = [];
        foreach ($questions as $q) {
            $byId[(string) ($q['_id'] ?? '')] = $q;
        }
        if ($byId === []) {
            throw new \InvalidArgumentException('This assessment has no questions.');
        }

        $answersIn = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $selected = [];
        foreach ($answersIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qid = trim((string) ($row['questionId'] ?? $row['id'] ?? ''));
            if ($qid === '' || !isset($byId[$qid])) {
                throw new \InvalidArgumentException('An answer references an unknown question.');
            }
            if (isset($selected[$qid])) {
                throw new \InvalidArgumentException('Duplicate answers for the same question are not allowed.');
            }
            $idx = (int) ($row['selectedIndex'] ?? $row['answer'] ?? -1);
            if ($idx < 0 || $idx > 3) {
                throw new \InvalidArgumentException('Each answer must select an option from 0 to 3.');
            }
            $selected[$qid] = $idx;
        }
        if (count($selected) !== count($byId)) {
            throw new \InvalidArgumentException('Answer every question before submitting.');
        }

        $pdo = Database::pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $score = 0;
            $totalMarks = 0;
            $details = [];
            foreach ($byId as $qid => $question) {
                $marks = (int) ($question['marks'] ?? 1);
                $totalMarks += $marks;
                $chosen = $selected[$qid];
                $correctIndex = (int) ($question['correctIndex'] ?? -1);
                $isCorrect = $chosen === $correctIndex;
                $awarded = $isCorrect ? $marks : 0;
                $score += $awarded;
                $this->answers->createAnswer([
                    'attemptId' => (string) ($attempt['_id'] ?? ''),
                    'questionId' => $qid,
                    'selectedIndex' => $chosen,
                    'isCorrect' => $isCorrect,
                    'marksAwarded' => $awarded,
                ]);
                $detail = [
                    'questionId' => $qid,
                    'question' => (string) ($question['question'] ?? ''),
                    'options' => array_values((array) ($question['options'] ?? [])),
                    'selectedIndex' => $chosen,
                    'isCorrect' => $isCorrect,
                    'marks' => $marks,
                    'marksAwarded' => $awarded,
                ];
                if (($assessment['showExplanations'] ?? true) === true || ($assessment['allowReview'] ?? true) === true) {
                    $detail['correctIndex'] = $correctIndex;
                    $detail['explanation'] = (string) ($question['explanation'] ?? '');
                }
                $details[] = $detail;
            }
            $percent = $totalMarks > 0 ? (int) round(($score / $totalMarks) * 100) : 0;
            $passed = $percent >= (int) ($assessment['passPercent'] ?? 60);
            $final = $this->attempts->finalize((string) ($attempt['_id'] ?? ''), [
                'score' => $score,
                'totalMarks' => $totalMarks,
                'percent' => $percent,
                'passed' => $passed,
            ]);
            if ($startedTx) {
                $pdo->commit();
            }

            return [
                'attempt' => $this->publicAttempt($final ?? $attempt),
                'score' => $score,
                'totalMarks' => $totalMarks,
                'percent' => $percent,
                'passed' => $passed,
                'passPercent' => (int) ($assessment['passPercent'] ?? 60),
                'showExplanations' => ($assessment['showExplanations'] ?? true) === true,
                'allowReview' => ($assessment['allowReview'] ?? true) === true,
                'review' => (($assessment['allowReview'] ?? true) === true) ? $details : [],
            ];
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listAttemptsForStudent(array $user, string $tutorialId, string $moduleId): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $assessment = $this->requirePublishedAssessment($tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $rows = $this->attempts->listForStudent((string) ($student['_id'] ?? ''), (string) ($assessment['_id'] ?? ''));

        return [
            'attempts' => array_map(fn (array $row): array => $this->publicAttempt($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    private function persistQuestions(array $user, string $tutorialId, string $moduleId, array $questions, array $input, bool $replace): array
    {
        $existing = $this->assessments->findByModule($moduleId);
        $settings = [
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'title' => trim(strip_tags((string) ($input['title'] ?? ($existing['title'] ?? 'Module quiz')))) ?: 'Module quiz',
            'status' => strtolower(trim((string) ($input['status'] ?? ($existing['status'] ?? 'draft')))),
            'passPercent' => (int) ($input['passPercent'] ?? ($existing['passPercent'] ?? 60)),
            'maxAttempts' => (int) ($input['maxAttempts'] ?? ($existing['maxAttempts'] ?? 3)),
            'showExplanations' => array_key_exists('showExplanations', $input)
                ? (bool) $input['showExplanations']
                : (($existing['showExplanations'] ?? true) === true),
            'allowReview' => array_key_exists('allowReview', $input)
                ? (bool) $input['allowReview']
                : (($existing['allowReview'] ?? true) === true),
        ];
        if (!in_array($settings['status'], ['draft', 'published'], true)) {
            $settings['status'] = 'draft';
        }
        if ($settings['status'] === 'published') {
            $course = $this->tutorials->showManaged($user, $tutorialId);
            if ((string) ($course['status'] ?? '') !== 'published') {
                throw new \InvalidArgumentException('Publish the course before publishing this assessment.');
            }
        }

        $pdo = Database::pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            if ($existing === null) {
                $assessment = $this->assessments->create($settings);
            } else {
                $assessment = $this->assessments->updateAssessment((string) ($existing['_id'] ?? ''), $settings);
            }
            if (!is_array($assessment)) {
                throw new \RuntimeException('Assessment could not be saved.');
            }
            $assessmentId = (string) ($assessment['_id'] ?? '');
            if ($replace) {
                $this->questions->deleteByAssessment($assessmentId);
            }
            $order = $replace ? 1 : (count($this->questions->listByAssessment($assessmentId)) + 1);
            foreach ($questions as $row) {
                $this->questions->create([
                    'assessmentId' => $assessmentId,
                    'question' => (string) ($row['question'] ?? ''),
                    'options' => (array) ($row['options'] ?? []),
                    'correctIndex' => (int) ($row['correctIndex'] ?? $row['correctAnswer'] ?? 0),
                    'explanation' => (string) ($row['explanation'] ?? ''),
                    'difficulty' => (string) ($row['difficulty'] ?? 'beginner'),
                    'marks' => (int) ($row['marks'] ?? 1),
                    'sortOrder' => $order,
                ]);
                $order++;
            }
            if ($startedTx) {
                $pdo->commit();
            }

            return $this->managedView($assessment, true);
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private function managedView(array $assessment, bool $withKeys): array
    {
        $questions = $this->questions->listByAssessment((string) ($assessment['_id'] ?? ''));
        $totalMarks = 0;
        $outQuestions = [];
        foreach ($questions as $q) {
            $marks = (int) ($q['marks'] ?? 1);
            $totalMarks += $marks;
            $item = [
                'id' => (string) ($q['_id'] ?? ''),
                'question' => (string) ($q['question'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'difficulty' => (string) ($q['difficulty'] ?? 'beginner'),
                'marks' => $marks,
                'sortOrder' => (int) ($q['sortOrder'] ?? 0),
                'explanation' => (string) ($q['explanation'] ?? ''),
            ];
            if ($withKeys) {
                $item['correctIndex'] = (int) ($q['correctIndex'] ?? 0);
                $item['correctAnswer'] = (int) ($q['correctIndex'] ?? 0);
            }
            $outQuestions[] = $item;
        }

        return [
            'assessment' => [
                'id' => (string) ($assessment['_id'] ?? ''),
                'tutorialId' => (string) ($assessment['tutorialId'] ?? ''),
                'moduleId' => (string) ($assessment['moduleId'] ?? ''),
                'title' => (string) ($assessment['title'] ?? 'Module quiz'),
                'status' => (string) ($assessment['status'] ?? 'draft'),
                'passPercent' => (int) ($assessment['passPercent'] ?? 60),
                'maxAttempts' => (int) ($assessment['maxAttempts'] ?? 3),
                'showExplanations' => ($assessment['showExplanations'] ?? true) === true,
                'allowReview' => ($assessment['allowReview'] ?? true) === true,
            ],
            'questions' => $outQuestions,
            'questionCount' => count($outQuestions),
            'totalMarks' => $totalMarks,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function staffModuleContext(array $user, string $tutorialId, string $moduleId): array
    {
        $course = $this->tutorials->showManaged($user, $tutorialId);
        $module = null;
        foreach ((array) ($course['modules'] ?? []) as $row) {
            if ((string) ($row['id'] ?? '') === $moduleId) {
                $module = $row;
                break;
            }
        }
        if ($module === null) {
            throw new \RuntimeException('Module not found.', 404);
        }
        $lessonText = '';
        $content = (string) ($module['content'] ?? '');
        if ($content !== '' && str_starts_with(trim($content), '{')) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && is_array($decoded['blocks'] ?? null)) {
                foreach ($decoded['blocks'] as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = (string) ($block['type'] ?? '');
                    if (in_array($type, ['paragraph', 'heading', 'quote'], true)) {
                        $lessonText .= (string) ($block['text'] ?? '') . "\n";
                    } elseif ($type === 'code') {
                        $lessonText .= (string) ($block['source'] ?? '') . "\n";
                    }
                }
            }
        } else {
            $lessonText = strip_tags($content);
        }

        return [
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'courseDescription' => (string) ($course['description'] ?? ''),
            'academicField' => 'other',
            'moduleTitle' => (string) ($module['title'] ?? ''),
            'moduleDescription' => (string) ($module['subtitle'] ?? ''),
            'learningObjectives' => [],
            'lessonText' => $lessonText,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePublishedAssessment(string $tutorialId, string $moduleId): array
    {
        $assessment = $this->assessments->findByModule($moduleId);
        if (!is_array($assessment) || (string) ($assessment['tutorialId'] ?? '') !== $tutorialId) {
            throw new \RuntimeException('Assessment not found.', 404);
        }
        if ((string) ($assessment['status'] ?? '') !== 'published') {
            throw new \RuntimeException('Assessment not found.', 404);
        }

        return $assessment;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function studentProfile(array $user): array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'student') {
            throw new \RuntimeException('Tutorial not found.', 404);
        }
        $student = (new StudentModel())->findByUserId((string) ($user['_id'] ?? $user['id'] ?? ''));
        if (!is_array($student)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $student;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function publicAttempt(array $attempt): array
    {
        return [
            'id' => (string) ($attempt['_id'] ?? ''),
            'attemptNumber' => (int) ($attempt['attemptNumber'] ?? 0),
            'status' => (string) ($attempt['status'] ?? ''),
            'score' => (int) ($attempt['score'] ?? 0),
            'totalMarks' => (int) ($attempt['totalMarks'] ?? 0),
            'percent' => (int) ($attempt['percent'] ?? 0),
            'passed' => ($attempt['passed'] ?? false) === true,
            'startedAt' => $attempt['startedAt'] ?? null,
            'submittedAt' => $attempt['submittedAt'] ?? null,
        ];
    }
}
