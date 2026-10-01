<?php

declare(strict_types=1);

/**
 * Placement officer (or admin) publishes HTML/CSS Basics and Version Control
 * for every student. Replaces leftover sample courses.
 * Usage: php backend/scripts/seed-tutorial-demo-data.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\TutorialCategoryModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialService;

$users = new UserModel();
(new TutorialCategoryModel())->seedDefaults();
$service = new TutorialService();
$admins = $users->findByRole('admin', 1);
$admin = $admins[0] ?? null;
$officers = $users->findByRole('placement_officer', 1);
$author = $officers[0] ?? $admin;
if (!is_array($admin) || (string) ($admin['_id'] ?? '') === '') {
    fwrite(STDERR, "No admin user exists, so sample courses could not be replaced.\n");
    exit(1);
}
if (!is_array($author) || (string) ($author['_id'] ?? '') === '') {
    fwrite(STDERR, "No placement officer or admin user exists, so courses were not created.\n");
    exit(1);
}
$authorRole = (string) ($author['role'] ?? '');
$authorName = (string) ($author['name'] ?? $author['email'] ?? $authorRole);

$bySlug = [];
foreach ($service->listCategories($author) as $category) {
    $bySlug[(string) ($category['slug'] ?? '')] = (string) ($category['id'] ?? '');
}

$blockId = static function (): string {
    return bin2hex(random_bytes(4));
};

$lesson = static function (array $blocks) use ($blockId): string {
    $normalized = [];
    foreach ($blocks as $block) {
        $block['id'] = (string) ($block['id'] ?? $blockId());
        $normalized[] = $block;
    }

    return json_encode(['version' => 1, 'blocks' => $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$p = static function (string $text): array {
    return ['type' => 'paragraph', 'text' => $text];
};
$h = static function (string $text, int $level = 2): array {
    return ['type' => 'heading', 'level' => $level === 3 ? 3 : 2, 'text' => $text];
};
$code = static function (string $language, string $source, string $exampleOutput = ''): array {
    return [
        'type' => 'code',
        'language' => $language,
        'source' => $source,
        'exampleOutput' => $exampleOutput,
    ];
};

$htmlCssModules = [
    [
        'title' => 'Your first HTML page',
        'subtitle' => 'What HTML is and the smallest complete document',
        'content' => $lesson([
            $p('HTML is the structure of a web page. Browsers read tags and turn them into headings, paragraphs, links, and images. CSS comes later and only changes how that structure looks.'),
            $h('A complete page'),
            $p('Every HTML file starts with a document type, then html, head, and body. The title in the head is the name of the tab. Visible content lives in the body.'),
            $code('html', "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <title>My first page</title>\n</head>\n<body>\n  <h1>Hello</h1>\n  <p>This is a page.</p>\n</body>\n</html>\n"),
            $h('Tags you will use constantly', 3),
            $p('h1 through h6 are headings. p is a paragraph. Nested tags must close in reverse order. Indent child tags so you can see the tree.'),
        ]),
        'exercise' => [
            'title' => 'Minimal page skeleton',
            'language' => 'html',
            'instructions' => 'Complete the starter so it is a valid HTML page with a title and one paragraph. Save Attempt stores the text. It is not opened in a browser from this page.',
            'boilerplate' => "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <title></title>\n</head>\n<body>\n  <p></p>\n</body>\n</html>\n",
        ],
    ],
    [
        'title' => 'Text, headings, and lists',
        'subtitle' => 'How to mark up readable content',
        'content' => $lesson([
            $p('Headings describe the outline. There should be one h1 for the page title. Use h2 for sections and h3 for subsections. Do not skip levels to make text look bigger. Size is CSS.'),
            $h('Paragraphs and emphasis'),
            $p('Wrap each idea in a p. Use strong for important words and em for stress. Do not use heading tags only because you want bold text.'),
            $h('Lists'),
            $p('ul is an unordered list. ol is numbered. Each item is an li. Nested lists go inside an li, not next to it.'),
            $code('html', "<h2>Materials</h2>\n<ul>\n  <li>Laptop</li>\n  <li>Editor</li>\n  <li>Browser</li>\n</ul>\n<ol>\n  <li>Write HTML</li>\n  <li>Add CSS</li>\n  <li>Open the file in the browser</li>\n</ol>\n"),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'Links, images, and page sections',
        'subtitle' => 'Connecting pages and grouping content',
        'content' => $lesson([
            $p('a creates a link. The href is the address. Use a real path or a full URL. Give the link text that describes the destination, not “click here”.'),
            $code('html', "<p><a href=\"https://developer.mozilla.org/\">HTML documentation</a></p>\n<p><a href=\"about.html\">About this course</a></p>\n"),
            $h('Images'),
            $p('img needs src and alt. alt describes the image for anyone who cannot see it. Width and height can wait for CSS. Prefer a path you control, not a random hotlinked file.'),
            $code('html', "<img src=\"campus.jpg\" alt=\"Front gate of the college\">\n"),
            $h('Sections'),
            $p('header, main, and footer name the parts of the page. nav holds the menu. These tags do not change the look by themselves. They make the structure clear for CSS and for assistive tools.'),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'CSS: selectors, color, and type',
        'subtitle' => 'Attach style without changing the HTML meaning',
        'content' => $lesson([
            $p('CSS selects HTML and sets properties. Put rules in a .css file and link it from head. You can start with a style tag while learning, then move the same rules into a file.'),
            $code('html', "<link rel=\"stylesheet\" href=\"styles.css\">\n"),
            $h('Selectors'),
            $p('An element selector styles every tag of that name. A class selector starts with a dot and only matches elements that have that class. Prefer classes for reusable look. Keep ids for unique landmarks.'),
            $code('css', "body {\n  font-family: Arial, sans-serif;\n  color: #1a1a1a;\n  background: #f7f7f5;\n}\n\nh1 {\n  font-size: 2rem;\n}\n\n.note {\n  color: #334155;\n}\n"),
            $h('Color and type', 3),
            $p('Use hex colors you can reuse. Set a readable font size on body, then scale headings from that. Line-height around 1.5 keeps paragraphs easy to scan.'),
        ]),
        'exercise' => [
            'title' => 'Style a heading and a note',
            'language' => 'css',
            'instructions' => 'Write CSS that makes h1 dark and .note a smaller muted paragraph. Save Attempt stores the CSS text. It is not applied to a live page here.',
            'boilerplate' => "h1 {\n  color: #111827;\n}\n\n.note {\n  font-size: 0.95rem;\n  color: #4b5563;\n}\n",
        ],
    ],
    [
        'title' => 'The box model',
        'subtitle' => 'Content, padding, border, and margin',
        'content' => $lesson([
            $p('Every element is a box. Inside is the content. Padding is space inside the border. Margin is space outside the border. Width usually means the content width unless you set box-sizing.'),
            $h('A practical default'),
            $p('border-box counts padding and border inside the width you set. That makes columns easier to reason about.'),
            $code('css', "* {\n  box-sizing: border-box;\n}\n\n.card {\n  width: 320px;\n  padding: 1rem;\n  border: 1px solid #d0d5dd;\n  margin: 0 0 1rem;\n  background: #fff;\n}\n"),
            $h('Spacing without empty tags', 3),
            $p('Do not add extra br tags to push things around. Use margin on headings and paragraphs. Adjacent vertical margins collapse, so one of the two values wins.'),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'A simple page layout',
        'subtitle' => 'Header, content, and a row of cards',
        'content' => $lesson([
            $p('Flexbox is enough for a first layout. A row is display flex. gap separates children. flex-wrap lets cards drop to the next line on a narrow screen.'),
            $code('css', ".page {\n  max-width: 720px;\n  margin: 0 auto;\n  padding: 1.5rem;\n}\n\n.cards {\n  display: flex;\n  flex-wrap: wrap;\n  gap: 1rem;\n}\n\n.cards article {\n  flex: 1 1 200px;\n  padding: 1rem;\n  border: 1px solid #e5e7eb;\n}\n"),
            $code('html', "<main class=\"page\">\n  <header>\n    <h1>HTML and CSS basics</h1>\n    <p>Structure first, then appearance.</p>\n  </header>\n  <section class=\"cards\">\n    <article>\n      <h2>HTML</h2>\n      <p>Headings, lists, links, images.</p>\n    </article>\n    <article>\n      <h2>CSS</h2>\n      <p>Selectors, color, boxes, layout.</p>\n    </article>\n  </section>\n</main>\n"),
            $p('When you finish this course you should be able to write a short page, link a stylesheet, and explain why a heading is a heading and not just large text.'),
        ]),
        'exercise' => null,
    ],
];

$gitModules = [
    [
        'title' => 'Why version control',
        'subtitle' => 'What Git is for before any command',
        'content' => $lesson([
            $p('Version control records snapshots of a project. You can see what changed, who changed it, and go back if a change was wrong. Emailing zip files does not do that.'),
            $h('What Git stores'),
            $p('Git stores a repository: the files plus a history of commits. A commit is one intentional step with a message. The working copy is the files on disk right now. Those two can differ until you commit.'),
            $h('What not to put in Git', 3),
            $p('Do not commit passwords, .env files, downloaded vendor folders you can restore, or large binaries you do not need. The project already ignores environment files for that reason.'),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'Repository, status, add, and commit',
        'subtitle' => 'The daily Git loop',
        'content' => $lesson([
            $p('git init creates a repository in the current folder. Do that once per project, not inside another Git project. git clone copies an existing remote repository instead.'),
            $code('bash', "git status\ngit add index.html styles.css\ngit commit -m \"Add the first HTML page and stylesheet\"\n", "On branch main\nChanges to be committed:\n  new file:   index.html\n  new file:   styles.css\n"),
            $h('Status first'),
            $p('git status tells you which files are new, changed, or staged. Read it before every commit. Stage only the files that belong in that step.'),
            $h('Commit messages', 3),
            $p('Write the message in the present tense and say why, not only what. “Add student search on the course list” is better than “updates”. One idea per commit is easier to explain in an interview.'),
        ]),
        'exercise' => [
            'title' => 'Write a commit message',
            'language' => 'bash',
            'instructions' => 'Replace the message with one line you would use after adding an about page. Save Attempt stores the text. Git is not run here.',
            'boilerplate' => "git commit -m \"Add an about page with the course outline\"\n",
        ],
    ],
    [
        'title' => 'History and what a commit contains',
        'subtitle' => 'Reading git log without rewriting the past',
        'content' => $lesson([
            $p('git log lists commits from newest to oldest. Each commit has a hash, an author, a date, and a message. You only need the short hash when you talk about a specific change.'),
            $code('bash', "git log --oneline -5\n", "a1b2c3d Add an about page with the course outline\n9f8e7d6 Add the first HTML page and stylesheet\n"),
            $h('Changing the last commit'),
            $p('If you have not pushed, you can amend the last commit to fix a typo in the message or add a forgotten file. If others already pulled that commit, make a new commit instead. Do not rewrite shared history.'),
            $p('A revert commit undoes a change by adding a new commit. The old commit stays in the log. That is safer than deleting history on a shared branch.'),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'Branches',
        'subtitle' => 'Work on a feature without touching the main line',
        'content' => $lesson([
            $p('A branch is a movable name for a commit. main (or master) is the line you keep stable. A feature branch is where you try work that is not ready yet.'),
            $code('bash', "git branch\ngit switch -c css-layout\n", ""),
            $h('Switching'),
            $p('git switch moves your working copy to that branch. Commit or stash before you switch if you have unfinished files, or Git will refuse to overwrite them.'),
            $h('Merging', 3),
            $p('When the feature is ready, switch to main and merge the branch. If both sides changed the same lines, Git stops with a conflict. Open the file, keep the correct lines, remove the conflict markers, then add and commit.'),
        ]),
        'exercise' => null,
    ],
    [
        'title' => 'Remotes, push, and pull',
        'subtitle' => 'Sharing the repository',
        'content' => $lesson([
            $p('A remote is another copy of the repository, usually on GitHub. origin is the usual name for that copy. git push sends your commits there. git pull brings down commits you do not have yet.'),
            $code('bash', "git remote -v\ngit pull origin main\ngit push -u origin main\n", ""),
            $h('Clone versus download zip'),
            $p('Clone keeps the history and the remote. A zip download is only files. For coursework and placement projects, clone so you can pull updates and push your own commits.'),
            $h('Before you push', 3),
            $p('Run status. Confirm you are on the branch you intend. Confirm you did not stage secrets. Pull first if others may have pushed. Then push.'),
        ]),
        'exercise' => null,
    ],
];

$catalog = [
    [
        'topic' => 'html-css-basics',
        'title' => 'HTML and CSS Basics',
        'category' => 'programming-languages',
        'description' => 'Build a small web page from structure to layout: HTML tags, links and images, CSS selectors, the box model, and a simple flex layout. Code in the lessons is for reading. Exercises use Save Attempt only.',
        'modules' => $htmlCssModules,
    ],
    [
        'topic' => 'version-control-git',
        'title' => 'Version Control with Git',
        'category' => 'tools',
        'description' => 'Use Git the way a placement project needs it: repository, commit, history, branches, and remotes. Commands are shown as examples. Nothing in this course runs Git on the server.',
        'modules' => $gitModules,
    ],
];

$keepTopics = [];
foreach ($catalog as $course) {
    $keepTopics[(string) $course['topic']] = true;
}

$sampleTitles = [
    'programming fundamentals',
    'git for placement projects',
    'python fundamentals',
    'c programming basics',
    'html and css basics',
    'version control with git',
];

foreach (['programming-languages', 'tools'] as $requiredSlug) {
    if (($bySlug[$requiredSlug] ?? '') === '') {
        fwrite(STDERR, "Required category {$requiredSlug} is missing. Courses were not replaced.\n");
        exit(1);
    }
}

$created = 0;
$createdIds = [];
foreach ($catalog as $course) {
    $categoryId = $bySlug[(string) $course['category']] ?? '';
    try {
        $row = $service->createTutorial($author, [
            'title' => (string) $course['title'],
            'categoryId' => $categoryId,
            'topic' => (string) $course['topic'],
            'description' => (string) $course['description'],
            'visibility' => 'all',
            'departmentIds' => [],
            'passingYears' => [],
        ]);
        foreach ($course['modules'] as $module) {
            $savedModule = $service->createModule($author, $row['id'], [
                'title' => (string) $module['title'],
                'subtitle' => (string) ($module['subtitle'] ?? ''),
                'content' => (string) $module['content'],
            ]);
            $exercise = $module['exercise'] ?? null;
            if (!is_array($exercise)) {
                continue;
            }
            $service->createExercise($author, $row['id'], $savedModule['id'], [
                'title' => (string) $exercise['title'],
                'instructions' => '<p>' . htmlspecialchars((string) $exercise['instructions'], ENT_QUOTES, 'UTF-8') . '</p>',
                'language' => (string) $exercise['language'],
                'boilerplate' => (string) $exercise['boilerplate'],
            ]);
        }
        $service->publish($author, $row['id']);
        $createdIds[(string) $row['id']] = true;
        $created++;
        echo 'CREATED ' . (string) $course['topic'] . ' by ' . $authorRole . ' ' . $authorName . " for all students.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED ' . (string) $course['topic'] . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$deleted = 0;
foreach ($service->listManaged($admin) as $course) {
    $id = (string) ($course['id'] ?? '');
    if (isset($createdIds[$id])) {
        continue;
    }
    $topic = strtolower(trim((string) ($course['topic'] ?? '')));
    $title = strtolower(trim((string) ($course['title'] ?? '')));
    $isOldDemo = str_starts_with($topic, 'demo-');
    $isReplacement = isset($keepTopics[$topic]);
    $isSampleTitle = in_array($title, $sampleTitles, true);
    if (!$isOldDemo && !$isReplacement && !$isSampleTitle) {
        continue;
    }
    $service->deleteTutorial($admin, $id);
    $deleted++;
    echo 'DELETED ' . (string) ($course['topic'] ?? '') . ' (' . (string) ($course['title'] ?? '') . ").\n";
}

echo $created . ' courses published for all students, ' . $deleted . " sample courses removed.\n";
