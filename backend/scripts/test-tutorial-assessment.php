<?php

declare(strict_types=1);

/**
 * Tutorial module MCQ assessment tests (OpenAI mocked — no live API calls).
 * Usage: php backend/scripts/test-tutorial-assessment.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialExerciseModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleAssessmentAnswerModel;
use PMS\Models\TutorialModuleAssessmentAttemptModel;
use PMS\Models\TutorialModuleAssessmentModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialModuleQuestionModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialAIService;
use PMS\Services\TutorialAssessmentService;
use PMS\Services\TutorialService;

$failed = 0;
$passed = 0;
$check = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};
$throws = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(false, $label . ' (expected exception)');
    } catch (Throwable $e) {
        $check(true, $label . ' → ' . $e->getMessage());
    }
};

final class FakeAssessmentAIService extends TutorialAIService
{
    /** @var array<string, mixed>|null */
    public ?array $nextJson = null;
    public ?Throwable $nextError = null;

    protected function assertAiConfigured(): void
    {
    }

    protected function callGenerateJson(string $system, string $user): array
    {
        if ($this->nextError !== null) {
            throw $this->nextError;
        }
        if ($this->nextJson === null) {
            throw new RuntimeException('Fake AI has no response queued.');
        }

        return $this->nextJson;
    }
}

$sampleMcq = static function (int $count = 2, int $correct = 0): array {
    $questions = [];
    for ($i = 1; $i <= $count; $i++) {
        $questions[] = [
            'question' => 'Sample question ' . $i . ' about HTML tags?',
            'options' => ['Option A' . $i, 'Option B' . $i, 'Option C' . $i, 'Option D' . $i],
            'correctAnswer' => $correct,
            'explanation' => 'Because option index ' . $correct . ' is correct for Q' . $i . '.',
            'difficulty' => 'beginner',
            'marks' => 1,
        ];
    }

    return ['questions' => $questions];
};

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$service = new TutorialService();
$ai = new FakeAssessmentAIService(null, $service);
$assessments = new TutorialAssessmentService($service, $ai);
$assessmentModel = new TutorialModuleAssessmentModel();
$questionModel = new TutorialModuleQuestionModel();
$attemptModel = new TutorialModuleAssessmentAttemptModel();
$answerModel = new TutorialModuleAssessmentAnswerModel();

$userIds = [];
$studentIds = [];
$tutorialIds = [];
$moduleIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-mcq.test',
        'password' => 'tutorial-mcq-test-pass',
        'role' => $role,
    ]);
    $userIds[] = $id;
    $user = $users->findById($id);
    if (!is_array($user)) {
        throw new RuntimeException('User missing.');
    }

    return $user;
};

$clearCooldown = static function (array $user): void {
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) ($user['_id'] ?? '')) . '.cooldown');
};

try {
    $staff = $makeUser('staff', 'mcq_staff');
    $student = $makeUser('student', 'mcq_student');
    $student2 = $makeUser('student', 'mcq_student2');
    $deptId = $departments->createDepartment([
        'name' => 'MCQ Test Dept ' . $suffix,
        'code' => 'MCQ' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $studentIds[] = $students->createProfile((string) $student['_id'], [
        'registerNumber' => 'MQ' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $studentIds[] = $students->createProfile((string) $student2['_id'], [
        'registerNumber' => 'MQ2' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);

    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'category ready');

    $course = $service->createTutorial($staff, [
        'title' => 'MCQ Course ' . $suffix,
        'description' => 'HTML basics for assessment tests.',
        'topic' => 'HTML',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialId = (string) ($course['id'] ?? '');
    $tutorialIds[] = $tutorialId;
    $module = $service->createModule($staff, $tutorialId, [
        'title' => 'Intro to HTML',
        'subtitle' => 'Tags and structure',
        'content' => json_encode([
            'version' => 1,
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'HTML tags'],
                ['type' => 'paragraph', 'text' => 'HTML uses tags like html, head, and body to structure documents.'],
                ['type' => 'code', 'language' => 'html', 'source' => "<html></html>\n", 'exampleOutput' => ''],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $moduleId = (string) ($module['id'] ?? '');
    $moduleIds[] = $moduleId;
    $check($moduleId !== '', 'module created for assessment');

    // 1 AI MCQ generation (mocked)
    $clearCooldown($staff);
    $ai->nextJson = $sampleMcq(3, 1);
    $preview = $assessments->generateForModule($staff, $tutorialId, $moduleId, [
        'questionCount' => 3,
        'difficulty' => 'beginner',
    ]);
    $check(($preview['questionCount'] ?? 0) === 3, '1 AI MCQ generation with mocked OpenAI');
    $check(!isset($preview['questions'][0]['_id']), 'AI generation returns preview only (no persistence ids)');

    // 2 invalid AI JSON / empty questions
    $clearCooldown($staff);
    $ai->nextJson = ['questions' => []];
    $throws(static function () use ($assessments, $staff, $tutorialId, $moduleId): void {
        $assessments->generateForModule($staff, $tutorialId, $moduleId, ['questionCount' => 2]);
    }, '2 invalid AI JSON empty questions');

    // 3 invalid answer index
    $throws(static function () use ($ai): void {
        $ai->normalizeMcqQuestions([[
            'question' => 'Q?',
            'options' => ['A', 'B', 'C', 'D'],
            'correctAnswer' => 9,
            'explanation' => 'Why',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]]);
    }, '3 invalid answer index');

    // 4 incorrect option count
    $throws(static function () use ($ai): void {
        $ai->normalizeMcqQuestions([[
            'question' => 'Q?',
            'options' => ['A', 'B', 'C'],
            'correctAnswer' => 0,
            'explanation' => 'Why',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]]);
    }, '4 incorrect option count');

    // 5 missing explanation
    $throws(static function () use ($ai): void {
        $ai->normalizeMcqQuestions([[
            'question' => 'Q?',
            'options' => ['A', 'B', 'C', 'D'],
            'correctAnswer' => 0,
            'explanation' => '',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]]);
    }, '5 missing explanation');

    // 6 empty question
    $throws(static function () use ($ai): void {
        $ai->normalizeMcqQuestions([[
            'question' => '   ',
            'options' => ['A', 'B', 'C', 'D'],
            'correctAnswer' => 0,
            'explanation' => 'Why',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]]);
    }, '6 empty question');

    // 7 manual question creation via save
    $manual = $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
        'title' => 'HTML Quiz',
        'status' => 'draft',
        'passPercent' => 50,
        'maxAttempts' => 2,
        'showExplanations' => true,
        'allowReview' => true,
        'questions' => [
            [
                'question' => 'Which tag starts an HTML document?',
                'options' => ['<html>', '<body>', '<head>', '<title>'],
                'correctIndex' => 0,
                'explanation' => 'The html tag is the root element.',
                'difficulty' => 'beginner',
                'marks' => 2,
            ],
            [
                'question' => 'Which tag contains visible page content?',
                'options' => ['<head>', '<meta>', '<body>', '<link>'],
                'correctIndex' => 2,
                'explanation' => 'Body holds visible content.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
        ],
    ]);
    $check(($manual['questionCount'] ?? 0) === 2 && ($manual['totalMarks'] ?? 0) === 3, '7 manual question creation');
    $check(($manual['assessment']['status'] ?? '') === 'draft', 'manual save stays draft');

    // 8 question editing
    $edited = $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
        'title' => 'HTML Quiz Edited',
        'status' => 'draft',
        'passPercent' => 60,
        'maxAttempts' => 2,
        'questions' => [
            [
                'question' => 'What is the root HTML tag?',
                'options' => ['html', 'body', 'div', 'span'],
                'correctIndex' => 0,
                'explanation' => 'html is the root.',
                'difficulty' => 'beginner',
                'marks' => 2,
            ],
            [
                'question' => 'Visible content lives in?',
                'options' => ['head', 'script', 'body', 'style'],
                'correctIndex' => 2,
                'explanation' => 'body is visible.',
                'difficulty' => 'intermediate',
                'marks' => 2,
            ],
        ],
    ]);
    $check(($edited['assessment']['title'] ?? '') === 'HTML Quiz Edited' && ($edited['questions'][1]['difficulty'] ?? '') === 'intermediate', '8 question editing');

    // 9 question deletion (save with one question)
    $deleted = $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
        'status' => 'draft',
        'questions' => [
            [
                'question' => 'Only remaining question?',
                'options' => ['Yes', 'No', 'Maybe', 'Never'],
                'correctIndex' => 0,
                'explanation' => 'One question left after delete.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
        ],
    ]);
    $check(($deleted['questionCount'] ?? 0) === 1, '9 question deletion');

    // 10 question ordering
    $ordered = $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
        'status' => 'draft',
        'questions' => [
            [
                'question' => 'First question',
                'options' => ['A1', 'B1', 'C1', 'D1'],
                'correctIndex' => 0,
                'explanation' => 'First.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
            [
                'question' => 'Second question',
                'options' => ['A2', 'B2', 'C2', 'D2'],
                'correctIndex' => 1,
                'explanation' => 'Second.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
        ],
    ]);
    $check(($ordered['questions'][0]['question'] ?? '') === 'First question' && ($ordered['questions'][1]['sortOrder'] ?? 0) === 2, '10 question ordering');

    // Cannot publish assessment while course is draft
    $throws(static function () use ($assessments, $staff, $tutorialId, $moduleId, $ordered): void {
        $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
            'status' => 'published',
            'questions' => array_map(static function (array $q): array {
                return [
                    'question' => $q['question'],
                    'options' => $q['options'],
                    'correctIndex' => $q['correctIndex'],
                    'explanation' => $q['explanation'],
                    'difficulty' => $q['difficulty'],
                    'marks' => $q['marks'],
                ];
            }, $ordered['questions']),
        ]);
    }, 'publish blocked while course draft');

    // 11 assessment save + publish course + publish assessment
    $service->publish($staff, $tutorialId);
    $published = $assessments->saveAssessment($staff, $tutorialId, $moduleId, [
        'title' => 'Published HTML Quiz',
        'status' => 'published',
        'passPercent' => 50,
        'maxAttempts' => 2,
        'showExplanations' => true,
        'allowReview' => true,
        'questions' => [
            [
                'question' => 'Root tag?',
                'options' => ['html', 'body', 'div', 'p'],
                'correctIndex' => 0,
                'explanation' => 'html root.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
            [
                'question' => 'Visible content?',
                'options' => ['head', 'meta', 'body', 'title'],
                'correctIndex' => 2,
                'explanation' => 'body visible.',
                'difficulty' => 'beginner',
                'marks' => 1,
            ],
        ],
    ]);
    $check(($published['assessment']['status'] ?? '') === 'published', '11 assessment save published');

    // 12 student question loading
    $studentView = $assessments->getForStudent($student, $tutorialId, $moduleId);
    $check(($studentView['assessment']['questionCount'] ?? 0) === 2, '12 student question loading');

    // 13 correct answers not present in student API
    $json = json_encode($studentView);
    $check(
        is_string($json)
        && !str_contains($json, 'correctIndex')
        && !str_contains($json, 'correctAnswer')
        && !str_contains($json, 'explanation'),
        '13 correct answers not present in student API'
    );

    // 14 server-side score calculation (all correct)
    $start = $assessments->startAttempt($student, $tutorialId, $moduleId);
    $attemptId = (string) ($start['attempt']['id'] ?? '');
    $answers = [];
    foreach ($studentView['questions'] as $q) {
        $answers[] = ['questionId' => $q['id'], 'selectedIndex' => 0]; // first is correct for Q1, wrong for Q2
    }
    // Fix answers using managed keys for scoring expectation
    $managed = $assessments->getManaged($staff, $tutorialId, $moduleId);
    $correctAnswers = [];
    foreach ($managed['questions'] as $q) {
        $correctAnswers[] = [
            'questionId' => $q['id'],
            'selectedIndex' => (int) $q['correctIndex'],
        ];
    }
    $result = $assessments->submitAttempt($student, $tutorialId, $moduleId, [
        'attemptId' => $attemptId,
        'answers' => $correctAnswers,
        'score' => 999,
        'totalMarks' => 1,
        'isCorrect' => true,
    ]);
    $check(($result['score'] ?? -1) === 2 && ($result['percent'] ?? -1) === 100 && ($result['passed'] ?? false) === true, '14 server-side score calculation');

    // 15 incorrect answer submission
    $start2 = $assessments->startAttempt($student, $tutorialId, $moduleId);
    $wrong = [];
    foreach ($managed['questions'] as $q) {
        $wrong[] = [
            'questionId' => $q['id'],
            'selectedIndex' => ((int) $q['correctIndex'] + 1) % 4,
        ];
    }
    $failResult = $assessments->submitAttempt($student, $tutorialId, $moduleId, [
        'attemptId' => (string) ($start2['attempt']['id'] ?? ''),
        'answers' => $wrong,
    ]);
    $check(($failResult['score'] ?? -1) === 0 && ($failResult['passed'] ?? true) === false, '15 incorrect answer submission');

    // 16 attempt limit
    $throws(static function () use ($assessments, $student, $tutorialId, $moduleId): void {
        $assessments->startAttempt($student, $tutorialId, $moduleId);
    }, '16 attempt limit');

    // 17 duplicate submission
    $startOther = $assessments->startAttempt($student2, $tutorialId, $moduleId);
    $dupAnswers = [];
    foreach ($managed['questions'] as $q) {
        $dupAnswers[] = ['questionId' => $q['id'], 'selectedIndex' => (int) $q['correctIndex']];
    }
    $assessments->submitAttempt($student2, $tutorialId, $moduleId, [
        'attemptId' => (string) ($startOther['attempt']['id'] ?? ''),
        'answers' => $dupAnswers,
    ]);
    $throws(static function () use ($assessments, $student2, $tutorialId, $moduleId, $startOther, $dupAnswers): void {
        $assessments->submitAttempt($student2, $tutorialId, $moduleId, [
            'attemptId' => (string) ($startOther['attempt']['id'] ?? ''),
            'answers' => $dupAnswers,
        ]);
    }, '17 duplicate submission');

    // 18 cross-student access
    $throws(static function () use ($assessments, $student, $tutorialId, $moduleId, $startOther, $dupAnswers): void {
        $assessments->submitAttempt($student, $tutorialId, $moduleId, [
            'attemptId' => (string) ($startOther['attempt']['id'] ?? ''),
            'answers' => $dupAnswers,
        ]);
    }, '18 cross-student access');

    // 19 draft/unpublished course access
    $draftCourse = $service->createTutorial($staff, [
        'title' => 'Draft MCQ Course ' . $suffix,
        'description' => 'Draft only.',
        'topic' => 'CSS',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $draftId = (string) ($draftCourse['id'] ?? '');
    $tutorialIds[] = $draftId;
    $draftModule = $service->createModule($staff, $draftId, [
        'title' => 'Draft module',
        'subtitle' => '',
        'content' => '<p>Draft lesson</p>',
    ]);
    $draftModuleId = (string) ($draftModule['id'] ?? '');
    $moduleIds[] = $draftModuleId;
    $assessments->saveAssessment($staff, $draftId, $draftModuleId, [
        'status' => 'draft',
        'questions' => [[
            'question' => 'Draft Q?',
            'options' => ['A', 'B', 'C', 'D'],
            'correctIndex' => 0,
            'explanation' => 'Draft.',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]],
    ]);
    $throws(static function () use ($assessments, $student, $draftId, $draftModuleId): void {
        $assessments->getForStudent($student, $draftId, $draftModuleId);
    }, '19 draft/unpublished course access');

    // 20 department and passout-year visibility
    $scoped = $service->createTutorial($staff, [
        'title' => 'Scoped MCQ ' . $suffix,
        'description' => 'Scoped course.',
        'topic' => 'Git',
        'categoryId' => $categoryId,
        'visibility' => 'scoped',
        'departmentIds' => [$deptId],
        'passingYears' => ['2099'],
    ]);
    $scopedId = (string) ($scoped['id'] ?? '');
    $tutorialIds[] = $scopedId;
    $scopedModule = $service->createModule($staff, $scopedId, [
        'title' => 'Scoped module',
        'content' => '<p>Scoped</p>',
    ]);
    $scopedModuleId = (string) ($scopedModule['id'] ?? '');
    $moduleIds[] = $scopedModuleId;
    $service->publish($staff, $scopedId);
    $assessments->saveAssessment($staff, $scopedId, $scopedModuleId, [
        'status' => 'published',
        'questions' => [[
            'question' => 'Scoped Q?',
            'options' => ['A', 'B', 'C', 'D'],
            'correctIndex' => 1,
            'explanation' => 'Scoped.',
            'difficulty' => 'beginner',
            'marks' => 1,
        ]],
    ]);
    $throws(static function () use ($assessments, $student, $scopedId, $scopedModuleId): void {
        $assessments->getForStudent($student, $scopedId, $scopedModuleId);
    }, '20 department and passout-year visibility');

    // 21 existing course progress still works
    $progress = $service->markModuleComplete($student, $tutorialId, $moduleId);
    $check(in_array($moduleId, $progress['completedModuleIds'] ?? [], true) || ($progress['completedModules'] ?? 0) >= 1, '21 existing course progress');

    // 22 existing exercise attempts still work
    $exercise = $service->createExercise($staff, $tutorialId, $moduleId, [
        'title' => 'Practice Python',
        'instructions' => 'Print hello',
        'language' => 'python',
        'boilerplate' => 'print("hi")',
        'timeLimitMs' => 5000,
        'memoryLimitKb' => 128000,
    ]);
    $exerciseId = (string) ($exercise['id'] ?? '');
    $attempt = $service->saveAttempt($student, $exerciseId, [
        'sourceCode' => 'print("hi")',
        'language' => 'python',
    ]);
    $check(($attempt['attemptId'] ?? '') !== '' && ($attempt['status'] ?? '') === 'ATTEMPTED', '22 existing exercise attempts');

    // 23 existing manual course creation still works
    $manualCourse = $service->createTutorial($staff, [
        'title' => 'Manual still works ' . $suffix,
        'description' => 'Regression',
        'topic' => 'JS',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialIds[] = (string) ($manualCourse['id'] ?? '');
    $check(($manualCourse['status'] ?? '') === 'draft', '23 existing manual course creation');

    // 24 existing AI course generation still works (mocked)
    $clearCooldown($staff);
    $ai->nextJson = [
        'version' => 1,
        'course' => [
            'title' => 'AI Regression Course',
            'description' => 'Still works',
            'academicField' => 'computer_applications',
            'difficulty' => 'beginner',
            'learningObjectives' => ['Obj'],
            'estimatedDurationMinutes' => 60,
            'topic' => 'Python',
        ],
        'modules' => [[
            'title' => 'M1',
            'subtitle' => 'S1',
            'description' => 'D1',
            'learningObjectives' => ['L1'],
            'lessonDocument' => [
                'version' => 1,
                'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Hello world lesson content.'],
                ],
            ],
        ]],
    ];
    $coursePreview = $ai->generateCoursePreview($staff, [
        'topic' => 'Python',
        'academicField' => 'computer_applications',
        'difficulty' => 'beginner',
        'moduleCount' => 1,
    ]);
    $check(($coursePreview['moduleCount'] ?? 0) === 1, '24 existing AI course generation');

    // Save-generated path + regenerate append
    $clearCooldown($staff);
    $ai->nextJson = $sampleMcq(1, 0);
    $genPreview = $assessments->generateForModule($staff, $tutorialId, $moduleId, ['questionCount' => 1]);
    $savedGen = $assessments->saveGenerated($staff, $tutorialId, $moduleId, [
        'status' => 'draft',
        'replaceExisting' => false,
        'questions' => $genPreview['questions'],
    ]);
    $check(($savedGen['questionCount'] ?? 0) >= 1, 'save-generated appends without wiping when replaceExisting=false');

    $managedKeys = $assessments->getManaged($staff, $tutorialId, $moduleId);
    $check(isset($managedKeys['questions'][0]['correctIndex']), 'staff manage API includes answer keys');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  fatal → ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    foreach ($moduleIds as $mid) {
        $assessment = $assessmentModel->findByModule($mid);
        if (is_array($assessment)) {
            $aid = (string) ($assessment['_id'] ?? '');
            foreach ($attemptModel->findAll(['assessmentId' => $aid], 200) as $attempt) {
                $attemptId = (string) ($attempt['_id'] ?? '');
                foreach ($answerModel->listByAttempt($attemptId) as $answer) {
                    $answerModel->delete((string) ($answer['_id'] ?? ''));
                }
                $attemptModel->delete($attemptId);
            }
            $questionModel->deleteByAssessment($aid);
            $assessmentModel->delete($aid);
        }
    }
    foreach ($tutorialIds as $tid) {
        try {
            $mods = (new TutorialModuleModel())->listByTutorial($tid);
            foreach ($mods as $mod) {
                $mid = (string) ($mod['_id'] ?? '');
                foreach ((new TutorialExerciseModel())->listByModule($mid) as $ex) {
                    (new TutorialExerciseModel())->delete((string) ($ex['_id'] ?? ''));
                }
                (new TutorialModuleModel())->delete($mid);
            }
            (new TutorialModel())->delete($tid);
        } catch (Throwable) {
        }
    }
    foreach ($studentIds as $sid) {
        try {
            $students->delete($sid);
        } catch (Throwable) {
        }
    }
    foreach ($userIds as $uid) {
        try {
            $users->delete($uid);
        } catch (Throwable) {
        }
    }
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
