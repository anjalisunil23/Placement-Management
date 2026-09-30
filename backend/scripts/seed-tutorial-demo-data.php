<?php

declare(strict_types=1);

/**
 * Idempotent demonstration courses for every active tutorial category.
 * Uses the existing Tutorial service. Does not create users, students, or departments.
 * Usage: php backend/scripts/seed-tutorial-demo-data.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialService;

$users = new UserModel();
$departments = new DepartmentModel();
$service = new TutorialService();
$admins = $users->findByRole('admin', 1);
$admin = $admins[0] ?? null;
if (!is_array($admin) || (string) ($admin['_id'] ?? '') === '') {
    fwrite(STDERR, "No admin user exists, so demo courses were not created.\n");
    exit(1);
}

$departmentIds = [];
foreach ($departments->findAll([], 300) as $department) {
    $id = (string) ($department['_id'] ?? '');
    if ($id !== '' && DepartmentModel::isStudentAcademicDepartment((string) ($department['code'] ?? ''), (string) ($department['name'] ?? ''))) {
        $departmentIds[] = $id;
    }
}
if ($departmentIds === []) {
    echo "No academic departments found. Department-scoped demos will be visible to all students.\n";
}

$bySlug = [];
foreach ($service->listCategories($admin) as $category) {
    $bySlug[(string) ($category['slug'] ?? '')] = (string) ($category['id'] ?? '');
}

$existing = [];
foreach ($service->listManaged($admin) as $course) {
    $existing[(string) ($course['topic'] ?? '')] = true;
}

/**
 * @return array{visibility: string, departmentIds: list<string>, passingYears: list<string>}
 */
$scope = static function (string $mode) use ($departmentIds): array {
    $departmentId = $departmentIds[0] ?? '';
    if (($mode === 'department' || $mode === 'both') && $departmentId === '') {
        return ['visibility' => 'all', 'departmentIds' => [], 'passingYears' => []];
    }
    if ($mode === 'department') {
        return ['visibility' => 'scoped', 'departmentIds' => [$departmentId], 'passingYears' => []];
    }
    if ($mode === 'year') {
        return ['visibility' => 'scoped', 'departmentIds' => [], 'passingYears' => ['2027']];
    }
    if ($mode === 'both') {
        return ['visibility' => 'scoped', 'departmentIds' => [$departmentId], 'passingYears' => ['2027']];
    }
    if ($mode === 'year-2028') {
        return ['visibility' => 'scoped', 'departmentIds' => [], 'passingYears' => ['2028']];
    }

    return ['visibility' => 'all', 'departmentIds' => [], 'passingYears' => []];
};

$lesson = static function (string $lead, array $points): string {
    $items = '';
    foreach ($points as $point) {
        $items .= '<li>' . htmlspecialchars($point, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    return '<p>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</p><ul>' . $items . '</ul>';
};

$catalog = [
    'programming-languages' => [
        [
            'topic' => 'demo-python-fundamentals',
            'title' => 'Python Fundamentals',
            'description' => 'A first course in Python for placement practice: variables, decisions, loops, and small functions. Students compare their saved attempts with the sample tests. Nothing on this page runs their code.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Variables and data types', 'content' => $lesson('A Python name points at a value. The useful types for this course are integers, floats, strings, and booleans.', ['Use snake_case for variable names.', 'input() returns a string, so convert with int() before arithmetic.', 'Print with one clear line so a sample test can be compared by eye.']), 'exercise' => ['title' => 'Largest of three numbers', 'language' => 'python', 'instructions' => 'Read three integers and print the largest one.', 'boilerplate' => "a = int(input())\nb = int(input())\nc = int(input())\nprint(max(a, b, c))\n", 'stdin' => "7\n3\n9\n", 'expected' => "9\n"]],
                ['title' => 'Conditions and loops', 'content' => $lesson('if, elif, and else choose a path. for and while repeat work until the stopping condition is true.', ['Prefer a for loop when the count is known.', 'Keep the loop body short enough to explain in one sentence.', 'Test the boundary values: empty input is not the same as zero.']), 'exercise' => null],
            ],
        ],
        [
            'topic' => 'demo-c-fundamentals',
            'title' => 'C Programming Basics',
            'description' => 'Covers the shape of a C program, printf, and a small function. Use it to show a second programming course in the same category.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Program structure', 'content' => $lesson('Every example in this course starts in main and writes to standard output.', ['Include stdio.h before calling printf.', 'Return 0 from main when the program finishes normally.', 'Match the sample output exactly, including the newline.']), 'exercise' => ['title' => 'Print a greeting', 'language' => 'c', 'instructions' => 'Print Hello, placement on its own line.', 'boilerplate' => "#include <stdio.h>\nint main(void) {\n    printf(\"Hello, placement\\n\");\n    return 0;\n}\n", 'stdin' => '', 'expected' => "Hello, placement\n"]],
                ['title' => 'Functions', 'content' => $lesson('A function name, parameters, and return type make a calculation reusable.', ['Pass values in; do not rely on global variables for this course.', 'Name the function after the result it returns.']), 'exercise' => null],
            ],
        ],
    ],
    'tools' => [
        [
            'topic' => 'demo-git-basics',
            'title' => 'Git for Placement Projects',
            'description' => 'How a student keeps a project in Git: status, commit, and a short history they can explain in an interview. Visible to one academic department when the campus has departments configured.',
            'mode' => 'department',
            'modules' => [
                ['title' => 'Status and commit', 'content' => $lesson('Git status shows what changed. A commit records one intentional step with a message that says why.', ['Stage only the files that belong in that step.', 'Write the message in the present tense.', 'Do not commit passwords, token files, or local configuration.']), 'exercise' => ['title' => 'Write a commit message', 'language' => 'javascript', 'instructions' => 'In a comment, write a one-line commit message for adding a student search field. Save the attempt. It is not executed.', 'boilerplate' => "// git commit -m \"Add a search field to the student course list\"\n", 'stdin' => '', 'expected' => "Add a search field to the student course list\n"]],
                ['title' => 'Reading history', 'content' => $lesson('git log shows who changed the project and in what order.', ['Use a short log when explaining a project in a placement interview.', 'A revert commit is still part of the history.']), 'exercise' => null],
            ],
        ],
        [
            'topic' => 'demo-editor-setup',
            'title' => 'Editor Setup for Practice',
            'description' => 'A short course on keeping an editor readable: font, indentation, and saving work before an attempt.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Readable editor settings', 'content' => $lesson('A monospace font and consistent indentation make starter code easier to edit.', ['Use spaces or tabs consistently inside one file.', 'Save before you close the page. A draft in the browser is not the stored attempt.']), 'exercise' => null],
                ['title' => 'What Save Attempt stores', 'content' => $lesson('Save Attempt keeps your source text. It does not compile, run, or grade it.', ['The status is Attempted.', 'Opening an older attempt loads it into the editor without creating a new row.']), 'exercise' => null],
            ],
        ],
    ],
    'technologies' => [
        [
            'topic' => 'demo-cloud-intro',
            'title' => 'Introduction to Cloud Computing',
            'description' => 'Cloud concepts for a placement interview: what a cloud service is, and how IaaS, PaaS, and SaaS differ. This course is limited to the 2027 passout year.',
            'mode' => 'year',
            'modules' => [
                ['title' => 'Cloud concepts', 'content' => $lesson('Cloud computing rents compute, storage, and networking instead of buying a machine for every project.', ['You pay for what the workload uses.', 'The provider runs the physical hardware.', 'A student project still needs a clear owner for accounts and data.']), 'exercise' => null],
                ['title' => 'IaaS, PaaS, and SaaS', 'content' => $lesson('The service model says how much of the stack you manage.', ['IaaS: you manage the operating system and the application.', 'PaaS: you bring the application; the platform runs it.', 'SaaS: you use the finished application.']), 'exercise' => ['title' => 'Name the service model', 'language' => 'javascript', 'instructions' => 'In a comment, name the model used when a team deploys its own app on a managed platform. Save the attempt.', 'boilerplate' => "// PaaS\n", 'stdin' => '', 'expected' => "PaaS\n"]],
            ],
        ],
        [
            'topic' => 'demo-web-http',
            'title' => 'How the Web Reaches a Page',
            'description' => 'Request, response, and status codes in plain language, so a student can explain a page load without a compiler.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Request and response', 'content' => $lesson('The browser sends an HTTP request. The server sends a response with a status and a body.', ['GET reads. POST sends a new record.', '200 means the response is usable. 404 means that address has no resource.']), 'exercise' => null],
                ['title' => 'What the student page calls', 'content' => $lesson('The course catalogue asks only for published summaries. Opening a lesson loads that lesson.', ['Search and category stay on the server.', 'A draft is not returned to a student.']), 'exercise' => null],
            ],
        ],
    ],
    'frameworks' => [
        [
            'topic' => 'demo-react-components',
            'title' => 'React Components for Beginners',
            'description' => 'Components, props, and a tiny list. Students who match both the selected department and the 2027 passout year can open this course.',
            'mode' => 'both',
            'modules' => [
                ['title' => 'A component is a function', 'content' => $lesson('A React component returns the markup for one piece of the page.', ['Props are inputs. Do not change them inside the child.', 'A list needs a stable key, not the array index when the order can change.']), 'exercise' => ['title' => 'Outline a component', 'language' => 'javascript', 'instructions' => 'Keep the starter function and add a comment naming the prop it should display. Save the attempt. It is not executed.', 'boilerplate' => "function CourseTitle(props) {\n  // display props.title\n  return null;\n}\n", 'stdin' => '', 'expected' => "props.title\n"]],
                ['title' => 'Rendering a list', 'content' => $lesson('Map an array of modules to one element each.', ['The parent owns the array.', 'The child receives one module at a time.']), 'exercise' => null],
            ],
        ],
        [
            'topic' => 'demo-bootstrap-layout',
            'title' => 'Bootstrap Layout Essentials',
            'description' => 'Rows, columns, and a card, using the same Bootstrap the placement portal already loads.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Rows and columns', 'content' => $lesson('A row holds columns. On a phone the columns stack. On a wide screen they sit side by side.', ['Use col-lg-4 and col-lg-8 when a course needs a list and an editor.', 'Do not add a horizontal scrollbar for a button row. Wrap it.']), 'exercise' => null],
                ['title' => 'Cards', 'content' => $lesson('A course card shows the title, category, counts, and one action.', ['Keep the card short.', 'Do not invent ratings or enrolment counts.']), 'exercise' => null],
            ],
        ],
    ],
    'databases' => [
        [
            'topic' => 'demo-sql-fundamentals',
            'title' => 'SQL Fundamentals',
            'description' => 'Relational tables, SELECT, and a filter. The exercise is a query the student saves. The page does not run it.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Tables, rows, and keys', 'content' => $lesson('A table stores one kind of fact. A row is one instance. A key identifies that row.', ['students has one row per student.', 'A foreign key points at a row in another table.', 'Do not store the same fact in two columns that can disagree.']), 'exercise' => null],
                ['title' => 'SELECT and WHERE', 'content' => $lesson('SELECT chooses columns. WHERE keeps the rows that match.', ['Name the columns you need instead of using SELECT * in a submitted answer.', 'Compare numbers without quotes and text with quotes.', 'ORDER BY is separate from the filter.']), 'exercise' => ['title' => 'Students above 80', 'language' => 'sql', 'instructions' => 'Write a query that returns name and marks from students where marks are greater than 80.', 'boilerplate' => "SELECT name, marks\nFROM students\nWHERE marks > 80;\n", 'stdin' => '', 'expected' => "SELECT name, marks FROM students WHERE marks > 80;\n"]],
            ],
        ],
        [
            'topic' => 'demo-sql-joins',
            'title' => 'SQL Joins',
            'description' => 'How to read two tables together without duplicating their rows by hand.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Why a join exists', 'content' => $lesson('A join matches rows from two tables on a shared key.', ['An inner join keeps matches only.', 'A missing key on one side drops that row from an inner join.']), 'exercise' => null],
                ['title' => 'Writing the ON clause', 'content' => $lesson('ON says which columns correspond. WHERE then filters the combined rows.', ['Use the key, not a display name, in ON.', 'Alias tables when the query names both.']), 'exercise' => null],
            ],
        ],
    ],
    'computer-science' => [
        [
            'topic' => 'demo-data-structures',
            'title' => 'Data Structures Fundamentals',
            'description' => 'Arrays, linked lists, stacks, and queues for interview explanations. This course is limited to the 2028 passout year.',
            'mode' => 'year-2028',
            'modules' => [
                ['title' => 'Arrays', 'content' => $lesson('An array stores items in a fixed order so an index reaches one item quickly.', ['Index 0 is the first item.', 'Inserting in the middle shifts later items.']), 'exercise' => ['title' => 'First and last index', 'language' => 'python', 'instructions' => 'Given a list in a comment, note the index of the first item and the last item. Save the attempt.', 'boilerplate' => "items = ['arrays', 'lists', 'stacks']\n# first index 0, last index 2\n", 'stdin' => '', 'expected' => "0 2\n"]],
                ['title' => 'Stacks and queues', 'content' => $lesson('A stack removes the most recently added item. A queue removes the oldest.', ['Stack: push and pop.', 'Queue: enqueue and dequeue.', 'Use a stack for undo and a queue for a waiting line.']), 'exercise' => null],
            ],
        ],
        [
            'topic' => 'demo-complexity',
            'title' => 'Complexity in Plain Language',
            'description' => 'How to talk about time cost without pretending the portal will time a program.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Constant and linear', 'content' => $lesson('Constant work does not grow with the list. Linear work walks each item once.', ['Reading one index is constant.', 'Finding a value by scanning is linear.']), 'exercise' => null],
                ['title' => 'What not to claim', 'content' => $lesson('This course does not measure a running program.', ['Say what the loop counts.', 'Do not report a runtime the page did not measure.']), 'exercise' => null],
            ],
        ],
    ],
    'devops' => [
        [
            'topic' => 'demo-deploy-basics',
            'title' => 'Deploying a PHP App',
            'description' => 'The path from a Git commit to a PHP and MariaDB site on shared hosting. Visible to one academic department when one exists.',
            'mode' => 'department',
            'modules' => [
                ['title' => 'What the server needs', 'content' => $lesson('This portal runs on PHP, MariaDB, and Apache. It does not need a background worker for tutorials.', ['PHP files keep their capital letters on Linux.', 'Tables for tutorials are created by the tutorial models.', 'Do not put database passwords in a page.']), 'exercise' => null],
                ['title' => 'Pull and refresh', 'content' => $lesson('After a pull, a cached script name can still be the old file until the query string changes.', ['Hard-refresh the tutorials page after a deploy.', 'Confirm the new script version in the page source.']), 'exercise' => ['title' => 'Name the refresh step', 'language' => 'javascript', 'instructions' => 'In a comment, name the browser action that loads the new tutorial script after a deploy. Save the attempt.', 'boilerplate' => "// hard refresh\n", 'stdin' => '', 'expected' => "hard refresh\n"]],
            ],
        ],
        [
            'topic' => 'demo-env-safety',
            'title' => 'Environment Files and Secrets',
            'description' => 'Which settings stay on the server and which files must not be committed.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'What stays off Git', 'content' => $lesson('.env holds the database password and must not be committed.', ['The repository already ignores environment files.', 'A demo course does not store a password.']), 'exercise' => null],
                ['title' => 'Safe defaults', 'content' => $lesson('Tutorials do not add a new environment variable.', ['Use the existing application configuration.', 'If a setting is missing, fail with a short message, not a stack dump to the student.']), 'exercise' => null],
            ],
        ],
    ],
    'other' => [
        [
            'topic' => 'demo-interview-answers',
            'title' => 'Explaining a Project in an Interview',
            'description' => 'A short course on describing one project clearly: problem, your part, and a result you can defend.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'Problem and your part', 'content' => $lesson('Start with the user problem, then the piece you built.', ['One sentence for the problem.', 'One sentence for your part.', 'Leave out tools you did not use.']), 'exercise' => ['title' => 'Two-sentence project summary', 'language' => 'javascript', 'instructions' => 'Replace the comments with two sentences about a project you can discuss. Save the attempt.', 'boilerplate' => "// Problem: students could not find practice material for their department.\n// My part: I built the course list and the lesson page.\n", 'stdin' => '', 'expected' => ""]],
                ['title' => 'A result you can defend', 'content' => $lesson('A result is something a reviewer can see.', ['Point at a page, a query, or a saved attempt.', 'Do not claim a grade the system does not calculate.']), 'exercise' => null],
            ],
        ],
        [
            'topic' => 'demo-study-plan',
            'title' => 'A Weekly Study Plan',
            'description' => 'How to use the course list: one module, one exercise attempt, then the next module.',
            'mode' => 'all',
            'modules' => [
                ['title' => 'One module at a time', 'content' => $lesson('Finish the lesson you opened before jumping to the last module.', ['Previous and Next move through the outline.', 'Next does not mark the current module complete.']), 'exercise' => null],
                ['title' => 'Save the attempt', 'content' => $lesson('When the exercise has starter code, edit it and choose Save Attempt.', ['The message is that the attempt was saved.', 'A second save creates another attempt.']), 'exercise' => null],
            ],
        ],
    ],
];

$created = 0;
$skipped = 0;
foreach ($catalog as $slug => $courses) {
    $categoryId = $bySlug[$slug] ?? '';
    if ($categoryId === '') {
        echo "SKIP category {$slug}: not in the active category list.\n";
        continue;
    }
    foreach ($courses as $course) {
        $topic = (string) $course['topic'];
        if (isset($existing[$topic])) {
            $skipped++;
            echo "SKIP {$topic}: already present.\n";
            continue;
        }
        $audience = $scope((string) $course['mode']);
        $row = $service->createTutorial($admin, [
            'title' => (string) $course['title'],
            'categoryId' => $categoryId,
            'topic' => $topic,
            'description' => (string) $course['description'],
            'visibility' => $audience['visibility'],
            'departmentIds' => $audience['departmentIds'],
            'passingYears' => $audience['passingYears'],
        ]);
        foreach ($course['modules'] as $module) {
            $savedModule = $service->createModule($admin, $row['id'], [
                'title' => (string) $module['title'],
                'content' => (string) $module['content'],
            ]);
            $exercise = $module['exercise'] ?? null;
            if (is_array($exercise)) {
                $savedExercise = $service->createExercise($admin, $row['id'], $savedModule['id'], [
                    'title' => (string) $exercise['title'],
                    'instructions' => '<p>' . htmlspecialchars((string) $exercise['instructions'], ENT_QUOTES, 'UTF-8') . '</p>',
                    'language' => (string) $exercise['language'],
                    'boilerplate' => (string) $exercise['boilerplate'],
                ]);
                $expected = (string) ($exercise['expected'] ?? '');
                if ($expected !== '') {
                    $service->createTestCase($admin, $savedExercise['id'], [
                        'stdin' => (string) ($exercise['stdin'] ?? ''),
                        'expectedOutput' => $expected,
                        'sample' => true,
                    ]);
                    if (($exercise['language'] ?? '') === 'python' || ($exercise['language'] ?? '') === 'c') {
                        $service->createTestCase($admin, $savedExercise['id'], [
                            'stdin' => 'hidden',
                            'expectedOutput' => 'not shown to students',
                            'sample' => false,
                        ]);
                    }
                }
            }
        }
        $service->publish($admin, $row['id']);
        $existing[$topic] = true;
        $created++;
        echo "CREATED {$topic} ({$audience['visibility']}).\n";
    }
}

echo $created . ' demo courses created, ' . $skipped . " already present.\n";
