<?php

declare(strict_types=1);

/**
 * Tutorial AI generation tests (OpenAI mocked — no live API calls).
 * Usage: php backend/scripts/test-tutorial-ai.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialExerciseModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialTestCaseModel;
use PMS\Models\UserModel;
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
        $check(true, $label . ' → ' . $e->getMessage());
    }
};

final class FakeTutorialAIService extends TutorialAIService
{
    /** @var array<string, mixed>|null */
    public ?array $nextJson = null;
    public ?Throwable $nextError = null;

    protected function assertAiConfigured(): void
    {
        // Mocked tests do not require a live OpenAI key.
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

$sampleBlocks = static function (): array {
    return [
        ['type' => 'heading', 'level' => 2, 'text' => 'Overview'],
        ['type' => 'paragraph', 'text' => 'This lesson introduces the topic clearly.'],
        ['type' => 'quote', 'text' => 'A useful reminder for students.'],
        ['type' => 'code', 'language' => 'python', 'source' => "print('hello')\n", 'exampleOutput' => "hello\n"],
        ['type' => 'divider'],
        ['type' => 'paragraph', 'text' => 'Practice the idea with a short exercise later.'],
    ];
};

$sampleCourseJson = static function (int $modules = 2) use ($sampleBlocks): array {
    $list = [];
    for ($i = 1; $i <= $modules; $i++) {
        $list[] = [
            'title' => 'Module ' . $i,
            'subtitle' => 'Part ' . $i,
            'description' => 'Description ' . $i,
            'learningObjectives' => ['Learn item ' . $i],
            'lessonDocument' => ['version' => 1, 'blocks' => $sampleBlocks()],
        ];
    }

    return [
        'version' => 1,
        'course' => [
            'title' => 'Generated Python Basics',
            'description' => 'A draft course on Python for placement readiness.',
            'academicField' => 'computer_applications',
            'difficulty' => 'beginner',
            'learningObjectives' => ['Write simple programs'],
            'estimatedDurationMinutes' => 180,
            'topic' => 'Python',
        ],
        'modules' => $list,
    ];
};

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$service = new TutorialService();
$ai = new FakeTutorialAIService(null, $service);

$userIds = [];
$studentIds = [];
$tutorialIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-ai.test',
        'password' => 'tutorial-ai-test-pass',
        'role' => $role,
    ]);
    $userIds[] = $id;
    $user = $users->findById($id);
    if (!is_array($user)) {
        throw new RuntimeException('User missing.');
    }

    return $user;
};

try {
    $staff = $makeUser('staff', 'ai_staff');
    $student = $makeUser('student', 'ai_student');
    $deptId = $departments->createDepartment([
        'name' => 'AI Test Dept ' . $suffix,
        'code' => 'AIT' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $studentIds[] = $students->createProfile((string) $student['_id'], [
        'registerNumber' => 'AI' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);

    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'programming-languages category exists');

    // 1–2 valid JSON normalize
    $doc = $ai->normalizeCourseDocument($sampleCourseJson(2), $ai->normalizeCourseRequest([
        'topic' => 'Python',
        'academicField' => 'computer_applications',
        'difficulty' => 'beginner',
        'moduleCount' => 2,
    ]));
    $check(($doc['course']['title'] ?? '') === 'Generated Python Basics' && count($doc['modules']) === 2, 'valid complete-course JSON');

    $mod = $ai->normalizeModule([
        'title' => 'Loops',
        'subtitle' => 'for and while',
        'lessonDocument' => ['version' => 1, 'blocks' => $sampleBlocks()],
    ], 'beginner', 'computer_applications');
    $check(($mod['title'] ?? '') === 'Loops' && count($mod['lessonDocument']['blocks']) >= 4, 'valid individual-module JSON');

    // 3 missing course title
    $throws(static function () use ($ai, $sampleCourseJson): void {
        $raw = $sampleCourseJson(1);
        $raw['course']['title'] = '';
        $ai->normalizeCourseDocument($raw, $ai->normalizeCourseRequest([
            'topic' => 'Python', 'academicField' => 'computer_applications', 'difficulty' => 'beginner', 'moduleCount' => 1,
        ]));
    }, 'missing course title');

    // 4 missing module title
    $throws(static function () use ($ai): void {
        $ai->normalizeModule([
            'title' => '',
            'lessonDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'x']]],
        ], 'beginner', 'other');
    }, 'missing module title');

    // 5 missing lesson blocks
    $throws(static function () use ($ai): void {
        $ai->normalizeModule([
            'title' => 'Empty',
            'lessonDocument' => ['version' => 1, 'blocks' => []],
        ], 'beginner', 'other');
    }, 'missing lesson blocks');

    // 6 unsupported block types dropped
    $blocks = $ai->normalizeBlocks([
        ['type' => 'paragraph', 'text' => 'Keep'],
        ['type' => 'video', 'text' => 'Nope'],
        ['type' => 'image', 'url' => 'https://example.com/a.png', 'alt' => 'x'],
    ], 'other');
    $check(count($blocks) === 1 && ($blocks[0]['type'] ?? '') === 'paragraph', 'unsupported block types removed');

    // 7 invalid code language → auto
    $codeBlocks = $ai->normalizeBlocks([
        ['type' => 'code', 'language' => 'brainfuck', 'source' => 'x', 'exampleOutput' => ''],
    ], 'computer_applications');
    $check(($codeBlocks[0]['language'] ?? '') === 'auto', 'invalid code language becomes auto');

    // 8 excessive module count
    $throws(static function () use ($ai): void {
        $ai->normalizeCourseRequest([
            'topic' => 'Cloud', 'academicField' => 'engineering', 'difficulty' => 'beginner', 'moduleCount' => 99,
        ]);
    }, 'excessive module count');

    // 9 excessive prompt size
    $throws(static function () use ($ai): void {
        $ai->normalizeCourseRequest([
            'topic' => 'Cloud',
            'academicField' => 'engineering',
            'difficulty' => 'beginner',
            'moduleCount' => 2,
            'syllabusText' => str_repeat('syllabus ', 3000),
        ]);
    }, 'excessive syllabus size');

    // 10 invalid academic field
    $throws(static function () use ($ai): void {
        $ai->normalizeAcademicField('astrology');
    }, 'invalid academic field');

    // 11 invalid difficulty
    $throws(static function () use ($ai): void {
        $ai->normalizeDifficulty('legendary');
    }, 'invalid difficulty');

    // 12 malformed / empty modules
    $throws(static function () use ($ai): void {
        $ai->normalizeCourseDocument(['course' => ['title' => 'X'], 'modules' => []], $ai->normalizeCourseRequest([
            'topic' => 'X', 'academicField' => 'other', 'difficulty' => 'beginner', 'moduleCount' => 1,
        ]));
    }, 'malformed course without modules');

    // 13–14 OpenAI timeout / rate limit surfaced
    $clearCooldown = static function (array $user): void {
        @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) ($user['_id'] ?? '')) . '.cooldown');
    };
    $clearCooldown($staff);
    $ai->nextError = new RuntimeException('Could not reach OpenAI. Check network connectivity and try again.');
    $throws(static function () use ($ai, $staff): void {
        $ai->generateCoursePreview($staff, [
            'topic' => 'Networks', 'academicField' => 'engineering', 'difficulty' => 'beginner', 'moduleCount' => 2,
        ]);
    }, 'OpenAI timeout-style failure');

    $clearCooldown($staff);
    $ai->nextError = new RuntimeException('OpenAI rate limit reached. Please wait a moment and try again.');
    $throws(static function () use ($ai, $staff): void {
        $ai->generateCoursePreview($staff, [
            'topic' => 'Networks', 'academicField' => 'engineering', 'difficulty' => 'beginner', 'moduleCount' => 2,
        ]);
    }, 'OpenAI rate limit failure');
    $ai->nextError = null;

    // 15 unauthorized student
    $throws(static function () use ($ai, $student, $sampleCourseJson): void {
        $ai->nextJson = $sampleCourseJson(1);
        $ai->generateCoursePreview($student, [
            'topic' => 'Python', 'academicField' => 'computer_applications', 'difficulty' => 'beginner', 'moduleCount' => 1,
        ]);
    }, 'unauthorized student generation');

    // Preview does not persist (17) + generate with mock (1 path)
    $beforeCount = count($service->listManaged($staff));
    $ai->nextJson = $sampleCourseJson(2);
    // bypass cooldown by using unique user id hash already written — sleep if needed
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) $staff['_id']) . '.cooldown');
    $preview = $ai->generateCoursePreview($staff, [
        'topic' => 'Python',
        'academicField' => 'computer_applications',
        'difficulty' => 'beginner',
        'moduleCount' => 2,
        'mcqsPerModule' => 3,
        'practicalPreference' => 'light',
    ]);
    $afterCount = count($service->listManaged($staff));
    $check($afterCount === $beforeCount, 'preview does not persist data');
    $check(($preview['course']['title'] ?? '') !== '' && ($preview['moduleCount'] ?? 0) === 2, 'generate-course returns preview');

    // 18 explicit save creates draft
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) $staff['_id']) . '.cooldown');
    $saved = $ai->saveCourseDraft($staff, [
        'categoryId' => $categoryId,
        'visibility' => 'all',
        'course' => $preview['course'],
        'modules' => $preview['modules'],
        'preferences' => $preview['preferences'],
    ]);
    $tutorialIds[] = (string) ($saved['tutorial']['id'] ?? '');
    $check(
        ($saved['status'] ?? '') === 'draft'
        && ($saved['tutorial']['status'] ?? '') === 'draft'
        && count($saved['modules'] ?? []) === 2,
        'explicit save creates a draft course'
    );

    // 21 editor can reopen — content is JSON lesson document
    $managed = $service->showManaged($staff, (string) $saved['tutorial']['id']);
    $firstContent = (string) (($managed['modules'][0]['content'] ?? ''));
    $decoded = json_decode($firstContent, true);
    $check(
        is_array($decoded)
        && (int) ($decoded['version'] ?? 0) === 1
        && is_array($decoded['blocks'] ?? null)
        && ($decoded['blocks'][0]['type'] ?? '') !== '',
        'existing lesson editor can reopen generated content'
    );

    // 16 ownership — second staff cannot save module into first staff course
    $staffB = $makeUser('staff', 'ai_staff_b');
    $throws(static function () use ($ai, $staffB, $saved, $sampleBlocks): void {
        $ai->saveModuleDraft($staffB, (string) $saved['tutorial']['id'], [
            'module' => [
                'title' => 'Intruder',
                'lessonDocument' => ['version' => 1, 'blocks' => $sampleBlocks()],
            ],
        ]);
    }, 'staff ownership enforcement');

    // 19–20 module save + status unchanged
    $published = $service->publish($staff, (string) $saved['tutorial']['id']);
    $check(($published['status'] ?? '') === 'published', 'course can be published after AI draft modules exist');
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) $staff['_id']) . '.cooldown');
    $ai->nextJson = [
        'module' => [
            'title' => 'Extra Module',
            'subtitle' => 'Added later',
            'description' => 'More content',
            'lessonDocument' => ['version' => 1, 'blocks' => $sampleBlocks()],
        ],
    ];
    $modPreview = $ai->generateModulePreview($staff, (string) $saved['tutorial']['id'], [
        'topic' => 'Functions',
        'difficulty' => 'beginner',
        'academicField' => 'computer_applications',
    ]);
    $modSaved = $ai->saveModuleDraft($staff, (string) $saved['tutorial']['id'], [
        'module' => $modPreview['module'],
        'difficulty' => 'beginner',
        'academicField' => 'computer_applications',
    ]);
    $check(($modSaved['module']['title'] ?? '') === 'Extra Module', 'explicit module save creates the next ordered module');
    $check(($modSaved['courseStatus'] ?? '') === 'published' && ($modSaved['statusUnchanged'] ?? false) === true, 'existing course status remains unchanged after adding a generated module');

    // business field strips programming code into paragraph
    $biz = $ai->normalizeBlocks([
        ['type' => 'code', 'language' => 'python', 'source' => 'print(1)', 'exampleOutput' => ''],
        ['type' => 'paragraph', 'text' => 'Keep marketing ideas.'],
    ], 'business_administration');
    $check(
        count($biz) === 2
        && ($biz[0]['type'] ?? '') === 'paragraph'
        && ($biz[1]['type'] ?? '') === 'paragraph',
        'business field does not keep programming code blocks'
    );

    // Lesson-linked programming exercises
    $exerciseCourse = $sampleCourseJson(2);
    $exerciseCourse['course']['title'] = 'Python With Practice';
    $exerciseCourse['modules'][0]['lessonDocument']['blocks'] = [
        ['id' => 'vars', 'type' => 'heading', 'level' => 2, 'text' => 'Python Variables'],
        ['type' => 'paragraph', 'text' => 'A variable stores a name or a number.'],
        ['id' => 'types', 'type' => 'heading', 'level' => 2, 'text' => 'Data Types'],
        ['type' => 'paragraph', 'text' => 'Integers, strings, and booleans are different types.'],
    ];
    $exerciseCourse['modules'][0]['exercises'] = [
        [
            'lessonTitle' => 'Python Variables',
            'title' => 'Store a name',
            'instructions' => 'Create a variable for a student name and print it.',
            'language' => 'python',
            'boilerplate' => "name = ''\n",
            'testCases' => [
                ['stdin' => '', 'expectedOutput' => "Ada\n", 'sample' => true],
                ['stdin' => '', 'expectedOutput' => "HIDDEN_NAME\n", 'sample' => false],
            ],
        ],
        [
            'lessonBlockId' => 'missing-lesson',
            'lessonTitle' => 'Not a lesson',
            'title' => 'Orphan exercise',
            'instructions' => 'This exercise does not belong to a lesson.',
            'language' => 'python',
            'testCases' => [
                ['stdin' => '', 'expectedOutput' => "x\n", 'sample' => true],
            ],
        ],
        [
            'lessonTitle' => 'data types',
            'title' => 'Show types',
            'instructions' => 'Create variables of different types and print them.',
            'language' => 'py',
            'boilerplate' => "value = 1\n",
            'testCases' => [
                ['stdin' => '', 'expectedOutput' => "1\n", 'sample' => true],
                ['stdin' => '', 'expectedOutput' => "2\n", 'sample' => true],
            ],
        ],
        [
            'lessonTitle' => 'Python Variables',
            'title' => 'Blank output',
            'instructions' => 'This case has no expected output.',
            'language' => 'python',
            'testCases' => [
                ['stdin' => '', 'expectedOutput' => '   ', 'sample' => true],
            ],
        ],
    ];
    $exerciseCourse['modules'][1]['lessonDocument']['blocks'] = [
        ['id' => 'loops', 'type' => 'heading', 'level' => 2, 'text' => 'Loops'],
        ['type' => 'paragraph', 'text' => 'A loop repeats work.'],
    ];
    $exerciseCourse['modules'][1]['exercises'] = [[
        'lessonBlockId' => 'loops',
        'title' => 'Count to three',
        'instructions' => 'Print the numbers 1, 2, and 3.',
        'language' => 'python',
        'boilerplate' => '',
        'testCases' => [
            ['stdin' => '', 'expectedOutput' => "1\n2\n3\n", 'sample' => true],
            ['stdin' => '', 'expectedOutput' => "1\n2\n3\n", 'sample' => false],
        ],
    ]];
    $linked = $ai->normalizeCourseDocument($exerciseCourse, $ai->normalizeCourseRequest([
        'topic' => 'Python',
        'academicField' => 'computer_applications',
        'difficulty' => 'beginner',
        'moduleCount' => 2,
    ]));
    $firstExercises = $linked['modules'][0]['exercises'] ?? [];
    $secondExercises = $linked['modules'][1]['exercises'] ?? [];
    $lessonIds = array_map(static fn (array $row): string => (string) ($row['lessonBlockId'] ?? ''), $firstExercises);
    $typeExercise = null;
    foreach ($firstExercises as $row) {
        if (($row['lessonBlockId'] ?? '') === 'types') {
            $typeExercise = $row;
        }
    }
    $check(
        count($firstExercises) === 2
        && in_array('vars', $lessonIds, true)
        && in_array('types', $lessonIds, true)
        && !in_array('missing-lesson', $lessonIds, true)
        && count($secondExercises) === 1
        && ($secondExercises[0]['lessonBlockId'] ?? '') === 'loops',
        'generated exercises link only to real lessons'
    );
    $check(
        is_array($typeExercise)
        && ($typeExercise['language'] ?? '') === 'python'
        && ($typeExercise['testCases'][1]['sample'] ?? true) === false,
        'exercise language and hidden test cases are normalized'
    );
    $check(
        ($linked['modules'][0]['exercises'] ?? null) !== null
        && count($ai->normalizeCourseDocument($sampleCourseJson(1), $ai->normalizeCourseRequest([
            'topic' => 'Python', 'academicField' => 'computer_applications', 'difficulty' => 'beginner', 'moduleCount' => 1,
        ]))['modules'][0]['exercises'] ?? []) === 0,
        'courses without exercises remain valid'
    );

    $linked['modules'][0]['exercises'][0]['title'] = 'Store a student name';
    $linked['modules'][0]['exercises'] = array_values(array_filter(
        $linked['modules'][0]['exercises'],
        static fn (array $row): bool => ($row['lessonBlockId'] ?? '') !== 'types'
    ));
    $linked['modules'][0]['exercises'][] = [
        'lessonBlockId' => 'removed-heading',
        'lessonTitle' => 'Gone',
        'title' => 'Should not save',
        'instructions' => 'The lesson no longer exists.',
        'language' => 'python',
        'testCases' => [
            ['stdin' => '', 'expectedOutput' => "no\n", 'sample' => true],
        ],
    ];
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) $staff['_id']) . '.cooldown');
    $practiceSaved = $ai->saveCourseDraft($staff, [
        'categoryId' => $categoryId,
        'visibility' => 'all',
        'course' => $linked['course'],
        'modules' => $linked['modules'],
    ]);
    $practiceTutorialId = (string) ($practiceSaved['tutorial']['id'] ?? '');
    $tutorialIds[] = $practiceTutorialId;
    $check(
        ($practiceSaved['status'] ?? '') === 'draft'
        && ($practiceSaved['tutorial']['status'] ?? '') === 'draft'
        && (int) ($practiceSaved['modules'][0]['exerciseCount'] ?? 0) === 1
        && (int) ($practiceSaved['modules'][1]['exerciseCount'] ?? 0) === 1,
        'edited lesson exercises save with the draft and invalid links are dropped'
    );
    $throws(static function () use ($service, $student, $practiceTutorialId, $practiceSaved): void {
        $service->moduleForStudent($student, $practiceTutorialId, (string) ($practiceSaved['modules'][0]['id'] ?? ''));
    }, 'draft lesson exercises stay hidden from students');
    $service->publish($staff, $practiceTutorialId);
    $studentModule = $service->moduleForStudent($student, $practiceTutorialId, (string) ($practiceSaved['modules'][0]['id'] ?? ''));
    $studentLessonIds = [];
    foreach (($studentModule['lessons'] ?? []) as $lesson) {
        $studentLessonIds[] = (string) ($lesson['id'] ?? '');
    }
    $studentExerciseIds = array_map(static fn (array $row): string => (string) ($row['lessonBlockId'] ?? ''), $studentModule['exercises'] ?? []);
    $check(
        $studentLessonIds === ['vars', 'types']
        && $studentExerciseIds === ['vars']
        && count($studentModule['exercises'] ?? []) === 1,
        'student module lists only the exercises for its lessons'
    );
    $otherModule = $service->moduleForStudent($student, $practiceTutorialId, (string) ($practiceSaved['modules'][1]['id'] ?? ''));
    $check(
        array_map(static fn (array $row): string => (string) ($row['lessonBlockId'] ?? ''), $otherModule['exercises'] ?? []) === ['loops'],
        'changing the module returns that lesson\'s exercises'
    );
    $studentExercise = $service->exerciseForStudent($student, (string) ($studentModule['exercises'][0]['id'] ?? ''));
    $studentJson = json_encode($studentExercise);
    $check(
        ($studentExercise['lessonBlockId'] ?? '') === 'vars'
        && ($studentExercise['title'] ?? '') === 'Store a student name'
        && count($studentExercise['testCases'] ?? []) === 1
        && (($studentExercise['testCases'][0]['sample'] ?? false) === true)
        && is_string($studentJson)
        && !str_contains($studentJson, 'HIDDEN_NAME'),
        'student exercise API hides hidden test cases'
    );

    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', (string) $staff['_id']) . '.cooldown');
    $ai->nextJson = [
        'exercises' => [[
            'lessonTitle' => 'Something else',
            'title' => 'Add two numbers',
            'instructions' => 'Store two numbers and print their sum.',
            'language' => 'python',
            'boilerplate' => "a = 0\nb = 0\n",
            'testCases' => [
                ['stdin' => "2\n3\n", 'expectedOutput' => "5\n", 'sample' => true],
                ['stdin' => "4\n5\n", 'expectedOutput' => "9\n", 'sample' => false],
            ],
        ]],
    ];
    $regenerated = $ai->generateLessonExercisesPreview($staff, [
        'lessonBlockId' => 'vars',
        'lessonTitle' => 'Python Variables',
        'lessonText' => 'A variable stores a name or a number.',
        'topic' => 'Python',
        'difficulty' => 'beginner',
        'academicField' => 'computer_applications',
        'count' => 2,
    ]);
    $check(
        count($regenerated['exercises'] ?? []) === 1
        && ($regenerated['exercises'][0]['lessonBlockId'] ?? '') === 'vars'
        && ($regenerated['exercises'][0]['testCases'][1]['sample'] ?? true) === false,
        'regenerated exercises stay linked to the requested lesson'
    );
    $throws(static function () use ($ai, $student): void {
        $ai->generateLessonExercisesPreview($student, [
            'lessonBlockId' => 'vars',
            'lessonTitle' => 'Python Variables',
            'lessonText' => 'Variables.',
        ]);
    }, 'students cannot regenerate lesson exercises');

    // 22 manual create still works
    $manual = $service->createTutorial($staff, [
        'title' => 'Manual Course ' . $suffix,
        'topic' => 'Manual',
        'description' => 'Created without AI.',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialIds[] = (string) ($manual['id'] ?? '');
    $manualModule = $service->createModule($staff, (string) $manual['id'], [
        'title' => 'Manual Module',
        'content' => '<p>Manual HTML still works</p>',
    ]);
    $manualExercise = $service->createExercise($staff, (string) $manual['id'], (string) $manualModule['id'], [
        'title' => 'Manual sum',
        'instructions' => 'Add two numbers that a tutor created by hand.',
        'language' => 'python',
        'boilerplate' => '',
        'lessonBlockId' => '',
    ]);
    $check(
        ($manualModule['title'] ?? '') === 'Manual Module'
        && ($manualExercise['title'] ?? '') === 'Manual sum'
        && ($manualExercise['lessonBlockId'] ?? 'missing') === '',
        'existing manual course and module creation still work'
    );
} catch (Throwable $e) {
    $check(false, 'unexpected: ' . $e->getMessage());
} finally {
    $tutorialModel = new TutorialModel();
    $moduleModel = new TutorialModuleModel();
    $exerciseModel = new TutorialExerciseModel();
    $caseModel = new TutorialTestCaseModel();
    foreach ($tutorialIds as $id) {
        if ($id === '') {
            continue;
        }
        try {
            foreach ($moduleModel->listByTutorial($id) as $module) {
                foreach ($exerciseModel->listByModule((string) ($module['_id'] ?? '')) as $exercise) {
                    foreach ($caseModel->listByExercise((string) ($exercise['_id'] ?? '')) as $case) {
                        $caseModel->delete((string) ($case['_id'] ?? ''));
                    }
                    $exerciseModel->delete((string) ($exercise['_id'] ?? ''));
                }
                $moduleModel->delete((string) ($module['_id'] ?? ''));
            }
            $tutorialModel->delete($id);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
    foreach ($studentIds as $id) {
        if ($id !== '') {
            $students->delete($id);
        }
    }
    foreach ($userIds as $id) {
        if ($id !== '') {
            $users->delete($id);
        }
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
