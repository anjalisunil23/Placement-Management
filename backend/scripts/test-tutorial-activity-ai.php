<?php

declare(strict_types=1);

/**
 * Tutorial practical-activity AI generation tests (OpenAI mocked — no live API calls).
 * Usage: php backend/scripts/test-tutorial-activity-ai.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialActivityService;
use PMS\Services\TutorialAIService;
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
        $msg = $e->getMessage();
        $safe = !str_contains($msg, 'sk-') && !str_contains(strtolower($msg), 'api_key') && !str_contains($msg, 'OPENAI');
        $check($safe, $label . ' → ' . $msg);
    }
};

final class FakeActivityAIService extends TutorialAIService
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

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$service = new TutorialService();
$ai = new FakeActivityAIService(null, $service);
$activities = new TutorialActivityService($service, null, null, null, $ai);
$activityModel = new TutorialModuleActivityModel();

$userIds = [];
$studentIds = [];
$tutorialIds = [];
$moduleIds = [];
$activityIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-act-ai.test',
        'password' => 'tutorial-act-ai-test-pass',
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

$sampleByType = static function (string $type): array {
    return match ($type) {
        'programming_task' => [
            'title' => 'Sum two numbers',
            'instructions' => 'Write a function that returns the sum of two integers.',
            'activityType' => 'programming_task',
            'difficulty' => 'beginner',
            'evaluationMode' => 'tutor_review',
            'config' => [
                'language' => 'python',
                'boilerplate' => "def add(a, b):\n    pass\n",
                'promptHint' => 'Use integers',
            ],
            'answerKey' => ['modelAnswer' => 'def add(a, b):\n    return a + b\n'],
        ],
        'sql_query' => [
            'title' => 'Select active students',
            'instructions' => 'Write a query that lists active students by register number.',
            'activityType' => 'sql_query',
            'difficulty' => 'beginner',
            'evaluationMode' => 'tutor_review',
            'config' => [
                'schemaDescription' => 'students(id, register_number, is_active)',
                'promptHint' => '',
            ],
            'answerKey' => ['modelAnswer' => 'SELECT register_number FROM students WHERE is_active = 1;'],
        ],
        'numerical' => [
            'title' => 'Ohm law current',
            'instructions' => 'A resistor of 10 ohm has 5 V across it. Find the current in amperes.',
            'activityType' => 'numerical',
            'difficulty' => 'beginner',
            'evaluationMode' => 'auto_compare',
            'config' => ['unit' => 'A', 'tolerance' => 0.01, 'promptHint' => ''],
            'answerKey' => ['expectedValue' => 0.5],
        ],
        'short_answer' => [
            'title' => 'Define polymorphism',
            'instructions' => 'In one or two sentences, define polymorphism in OOP.',
            'activityType' => 'short_answer',
            'difficulty' => 'beginner',
            'evaluationMode' => 'self_check',
            'config' => ['maxLength' => 500, 'selfCheckRubric' => 'Mention one interface, many forms.'],
            'answerKey' => [
                'modelAnswer' => 'Polymorphism lets one interface be used with different types.',
                'keywords' => ['interface', 'many forms'],
            ],
        ],
        'case_study' => [
            'title' => 'Campus placement delay',
            'instructions' => 'A company delayed campus interviews by two weeks. Analyze the situation.',
            'activityType' => 'case_study',
            'difficulty' => 'intermediate',
            'evaluationMode' => 'tutor_review',
            'config' => [
                'parts' => [
                    ['id' => 'part-1', 'prompt' => 'List two student impacts.'],
                    ['id' => 'part-2', 'prompt' => 'Suggest one mitigation for the placement cell.'],
                ],
            ],
            'answerKey' => ['modelAnswer' => 'Impacts: anxiety, schedule clash. Mitigation: interim mock interviews.'],
        ],
        default => [
            'title' => 'Design a login flow',
            'instructions' => 'Design a secure student login flow with password reset. Include constraints and deliverables.',
            'activityType' => 'analytical_design',
            'difficulty' => 'advanced',
            'evaluationMode' => 'tutor_review',
            'config' => [
                'maxLength' => 4000,
                'deliverableHint' => 'Submit a short design outline with steps and constraints.',
            ],
            'answerKey' => ['modelAnswer' => 'Include hashing, rate limits, and email verification for reset.'],
        ],
    };
};

try {
    $staff = $makeUser('staff', 'actai_owner');
    $otherStaff = $makeUser('staff', 'actai_peer');
    $student = $makeUser('student', 'actai_student');
    $deptId = $departments->createDepartment([
        'name' => 'Act AI Dept ' . $suffix,
        'code' => 'AAI' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $studentIds[] = $students->createProfile((string) $student['_id'], [
        'registerNumber' => 'AA' . $suffix,
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
        'title' => 'Activity AI Course ' . $suffix,
        'description' => 'Course used for practical activity AI generation tests.',
        'topic' => 'Python',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialId = (string) ($course['id'] ?? '');
    $tutorialIds[] = $tutorialId;
    $module = $service->createModule($staff, $tutorialId, [
        'title' => 'Practice module',
        'subtitle' => 'Hands-on tasks',
        'content' => json_encode([
            'version' => 1,
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Practice'],
                ['type' => 'paragraph', 'text' => 'Students practice core ideas with short practical tasks.'],
                ['type' => 'code', 'language' => 'python', 'source' => "print('ready')\n", 'exampleOutput' => "ready\n"],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $moduleId = (string) ($module['id'] ?? '');
    $moduleIds[] = $moduleId;
    $check($moduleId !== '', 'module created');

    // 1 Authorized staff can request a generation preview
    $clearCooldown($staff);
    $beforeCount = count($activityModel->listByModule($moduleId, true));
    $ai->nextJson = $sampleByType('short_answer');
    $preview = $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'short_answer',
        'topic' => 'Polymorphism',
        'difficulty' => 'beginner',
        'additionalInstructions' => 'Keep it concise.',
    ]);
    $check(($preview['scope'] ?? '') === 'module_activity' && is_array($preview['activity'] ?? null), '1 staff can generate activity preview');
    $check(($preview['activity']['status'] ?? '') === 'draft', '1 preview status is draft');

    // 12 Preview generation does not create a database activity
    $afterPreviewCount = count($activityModel->listByModule($moduleId, true));
    $check($afterPreviewCount === $beforeCount, '12 preview does not create DB activity');

    // 2 Students cannot generate
    $clearCooldown($student);
    $ai->nextJson = $sampleByType('short_answer');
    $throws(static function () use ($activities, $student, $tutorialId, $moduleId): void {
        $activities->generateForModule($student, $tutorialId, $moduleId, [
            'activityType' => 'short_answer',
            'topic' => 'Polymorphism',
        ]);
    }, '2 student cannot generate activities');

    // 3 Unauthorized staff cannot generate for another owner's tutorial
    $clearCooldown($otherStaff);
    $ai->nextJson = $sampleByType('short_answer');
    $throws(static function () use ($activities, $otherStaff, $tutorialId, $moduleId): void {
        $activities->generateForModule($otherStaff, $tutorialId, $moduleId, [
            'activityType' => 'short_answer',
            'topic' => 'Polymorphism',
        ]);
    }, '3 unauthorized staff cannot generate for another owner');

    // 4 All six activity types produce expected schema
    foreach (TutorialAIService::ACTIVITY_TYPES as $type) {
        $clearCooldown($staff);
        $ai->nextJson = $sampleByType($type);
        $row = $activities->generateForModule($staff, $tutorialId, $moduleId, [
            'activityType' => $type,
            'topic' => 'Topic for ' . $type,
            'difficulty' => 'beginner',
            'language' => 'python',
        ]);
        $act = $row['activity'];
        $ok = ($act['activityType'] ?? '') === $type
            && ($act['title'] ?? '') !== ''
            && ($act['instructions'] ?? '') !== ''
            && is_array($act['config'] ?? null)
            && is_array($act['answerKey'] ?? null)
            && ($act['status'] ?? '') === 'draft';
        if ($type === 'numerical') {
            $ok = $ok && array_key_exists('expectedValue', $act['answerKey'])
                && array_key_exists('tolerance', $act['config'])
                && !array_key_exists('expectedValue', $act['config']);
        }
        if ($type === 'case_study') {
            $parts = $act['config']['parts'] ?? [];
            $ok = $ok && is_array($parts) && count($parts) >= 1
                && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($parts[0]['id'] ?? '')) === 1;
        }
        if ($type === 'programming_task') {
            $ok = $ok && in_array(($act['config']['language'] ?? ''), ['c', 'cpp', 'java', 'python', 'javascript', 'php', 'sql', 'text'], true);
        }
        $check($ok, '4 schema for ' . $type);
    }

    // 5 Malformed AI JSON rejected safely
    $clearCooldown($staff);
    $ai->nextJson = ['title' => '', 'instructions' => ''];
    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->generateForModule($staff, $tutorialId, $moduleId, [
            'activityType' => 'short_answer',
            'topic' => 'Broken payload',
        ]);
    }, '5 malformed AI JSON rejected');

    // 6 Unsupported activity types rejected
    $clearCooldown($staff);
    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->generateForModule($staff, $tutorialId, $moduleId, [
            'activityType' => 'essay_exam',
            'topic' => 'Unsupported',
        ]);
    }, '6 unsupported activity type rejected');

    // 7 Unsupported evaluation modes rejected
    $throws(static function () use ($ai): void {
        $ai->normalizeActivityPreview([
            'title' => 'Bad mode',
            'instructions' => 'Write code.',
            'activityType' => 'programming_task',
            'evaluationMode' => 'auto_compare',
            'config' => ['language' => 'python', 'boilerplate' => ''],
            'answerKey' => [],
        ]);
    }, '7 unsupported evaluation mode rejected');

    // 8 Invalid programming languages normalized
    $normLang = $ai->normalizeActivityPreview([
        'title' => 'Lang normalize',
        'instructions' => 'Write a tiny program.',
        'activityType' => 'programming_task',
        'evaluationMode' => 'tutor_review',
        'config' => ['language' => 'py', 'boilerplate' => 'pass'],
        'answerKey' => [],
    ]);
    $check(($normLang['config']['language'] ?? '') === 'python', '8 language alias py → python');
    $normLang2 = $ai->normalizeActivityPreview([
        'title' => 'Lang fallback',
        'instructions' => 'Write a tiny program.',
        'activityType' => 'programming_task',
        'evaluationMode' => 'tutor_review',
        'config' => ['language' => 'brainfuck', 'boilerplate' => ''],
        'answerKey' => [],
    ]);
    $check(($normLang2['config']['language'] ?? '') === 'text', '8 unknown language → text');

    // 9 Numerical answer and tolerance validation
    $num = $ai->normalizeActivityPreview([
        'title' => 'Numeric ok',
        'instructions' => 'Compute 2+2.',
        'activityType' => 'numerical',
        'evaluationMode' => 'auto_compare',
        'config' => ['unit' => '', 'tolerance' => 0.05],
        'answerKey' => ['expectedValue' => 4],
    ]);
    $check(($num['answerKey']['expectedValue'] ?? null) === 4.0 && ($num['config']['tolerance'] ?? null) === 0.05, '9 numerical expectedValue + tolerance');
    $throws(static function () use ($ai): void {
        $ai->normalizeActivityPreview([
            'title' => 'Numeric bad',
            'instructions' => 'Compute something.',
            'activityType' => 'numerical',
            'evaluationMode' => 'auto_compare',
            'config' => ['tolerance' => 0.01],
            'answerKey' => ['expectedValue' => 'not-a-number'],
        ]);
    }, '9 invalid numerical expectedValue rejected');
    $throws(static function () use ($ai): void {
        $ai->normalizeActivityPreview([
            'title' => 'Numeric missing',
            'instructions' => 'Compute something.',
            'activityType' => 'numerical',
            'evaluationMode' => 'auto_compare',
            'config' => ['tolerance' => 0.01],
            'answerKey' => [],
        ]);
    }, '9 auto_compare missing expectedValue rejected');

    // 10 Case-study parts have stable valid identifiers
    $case = $ai->normalizeActivityPreview([
        'title' => 'Case ids',
        'instructions' => 'Read the case and answer.',
        'activityType' => 'case_study',
        'evaluationMode' => 'tutor_review',
        'config' => [
            'parts' => [
                ['id' => '!!!bad!!!', 'prompt' => 'First part'],
                ['id' => 'part-2', 'prompt' => 'Second part'],
                ['id' => 'part-2', 'prompt' => 'Duplicate id part'],
            ],
        ],
        'answerKey' => [],
    ]);
    $ids = array_map(static fn (array $p): string => (string) $p['id'], $case['config']['parts']);
    $check(count($ids) === 3 && count(array_unique($ids)) === 3, '10 case-study part ids unique');
    $check(
        preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ids[0]) === 1
        && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ids[1]) === 1
        && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ids[2]) === 1,
        '10 case-study part ids valid'
    );

    // 11 Answer keys stored only in protected fields
    $clearCooldown($staff);
    $ai->nextJson = $sampleByType('numerical');
    $numPreview = $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'numerical',
        'topic' => 'Ohm law',
    ]);
    $safe = $activities->studentSafeView(array_merge($numPreview['activity'], [
        '_id' => 'preview-only',
        'tutorialId' => $tutorialId,
        'moduleId' => $moduleId,
    ]));
    $check(
        !array_key_exists('answerKey', $safe)
        && !array_key_exists('expectedValue', $safe['config'] ?? [])
        && !array_key_exists('modelAnswer', $safe['config'] ?? []),
        '11 student-safe view hides answerKey / model answers'
    );

    // 13 Saving a preview creates a draft through the existing service
    $clearCooldown($staff);
    $ai->nextJson = $sampleByType('programming_task');
    $progPreview = $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'programming_task',
        'topic' => 'Add numbers',
        'language' => 'python',
    ]);
    $saved = $activities->saveGenerated($staff, $tutorialId, $moduleId, [
        'activity' => $progPreview['activity'],
    ]);
    $savedId = (string) ($saved['id'] ?? '');
    $activityIds[] = $savedId;
    $stored = $activityModel->findById($savedId);
    $check(
        $savedId !== ''
        && ($saved['status'] ?? '') === 'draft'
        && is_array($stored)
        && ($stored['status'] ?? '') === 'draft'
        && is_array($stored['answerKey'] ?? null)
        && (($stored['answerKey']['modelAnswer'] ?? '') !== ''),
        '13 saveGenerated creates draft with protected answerKey'
    );

    // 14 Publishing still requires explicit staff action
    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId, $savedId): void {
        // Course still draft — publish activity should fail.
        $activities->publish($staff, $tutorialId, $moduleId, $savedId);
    }, '14 publish blocked while course is draft');
    $service->publish($staff, $tutorialId);
    $published = $activities->publish($staff, $tutorialId, $moduleId, $savedId);
    $check(($published['status'] ?? '') === 'published', '14 explicit publish after course publish works');

    // Student API still has no answer key
    $studentView = $activities->getForStudent($student, $tutorialId, $moduleId, $savedId);
    $check(
        !array_key_exists('answerKey', $studentView)
        && !str_contains(json_encode($studentView, JSON_UNESCAPED_UNICODE) ?: '', 'modelAnswer'),
        '11b student getForStudent omits modelAnswer'
    );

    // 15 Cooldown / rate limiting
    $clearCooldown($staff);
    $ai->nextJson = $sampleByType('short_answer');
    $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'short_answer',
        'topic' => 'Cooldown A',
    ]);
    $ai->nextJson = $sampleByType('short_answer');
    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->generateForModule($staff, $tutorialId, $moduleId, [
            'activityType' => 'short_answer',
            'topic' => 'Cooldown B',
        ]);
    }, '15 cooldown respected on repeated generate');

    // 16 Provider failures handled safely
    $clearCooldown($staff);
    $ai->nextError = new RuntimeException('provider timeout simulated');
    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->generateForModule($staff, $tutorialId, $moduleId, [
            'activityType' => 'short_answer',
            'topic' => 'Timeout case',
        ]);
    }, '16 provider failure handled safely');
    $ai->nextError = null;

    // 17 No API key in responses
    $clearCooldown($staff);
    $ai->nextJson = $sampleByType('sql_query');
    $sqlPreview = $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'sql_query',
        'topic' => 'Active students',
    ]);
    $encoded = json_encode($sqlPreview, JSON_UNESCAPED_UNICODE) ?: '';
    $check(
        !str_contains($encoded, 'sk-')
        && !str_contains(strtolower($encoded), 'api_key')
        && !str_contains($encoded, 'OPENAI_API_KEY'),
        '17 no API key in generation response'
    );

    // Extra: save-generated keeps draft even if client asks published
    $clearCooldown($staff);
    $ai->nextJson = $sampleByType('analytical_design');
    $designPreview = $activities->generateForModule($staff, $tutorialId, $moduleId, [
        'activityType' => 'analytical_design',
        'topic' => 'Login design',
    ]);
    $designPreview['activity']['status'] = 'published';
    $savedDesign = $activities->saveGenerated($staff, $tutorialId, $moduleId, [
        'activity' => $designPreview['activity'],
    ]);
    $activityIds[] = (string) ($savedDesign['id'] ?? '');
    $check(($savedDesign['status'] ?? '') === 'draft', 'saveGenerated forces draft (no auto-publish)');

} catch (Throwable $e) {
    $check(false, 'fixture/setup → ' . $e->getMessage());
} finally {
    foreach ($moduleIds as $mid) {
        foreach ($activityModel->listByModule($mid, true) as $row) {
            try {
                $activityModel->delete((string) ($row['_id'] ?? ''));
            } catch (Throwable) {
            }
        }
    }
    foreach ($tutorialIds as $tid) {
        try {
            $mods = (new TutorialModuleModel())->listByTutorial($tid);
            foreach ($mods as $mod) {
                (new TutorialModuleModel())->delete((string) ($mod['_id'] ?? ''));
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
