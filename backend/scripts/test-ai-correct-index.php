<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeAiQuestionService;

$svc = new AptitudeAiQuestionService();

$reflect = new ReflectionClass($svc);
$map = $reflect->getMethod('mapAiQuestion');
$map->setAccessible(true);
$mapSave = $reflect->getMethod('mapPreviewToRow');
$mapSave->setAccessible(true);

$cases = [
    [
        'name' => '0-based index with matching explanation',
        'q' => [
            'question' => 'What is 15% of 240?',
            'options' => ['24', '36', '30', '48'],
            'correctAnswerIndex' => 1,
            'correctOptionLetter' => 'B',
            'explanation' => '15% of 240 = 0.15 × 240 = 36.',
            'topic' => 'Percentage',
            'difficulty' => 'Medium',
        ],
        'expectIndex' => 1,
    ],
    [
        'name' => '1-based index corrected via explanation',
        'q' => [
            'question' => 'What is 15% of 240?',
            'options' => ['24', '36', '30', '48'],
            'correctAnswer' => 2,
            'explanation' => '15% of 240 = 36.',
            'topic' => 'Percentage',
            'difficulty' => 'Medium',
        ],
        'expectIndex' => 1,
    ],
    [
        'name' => 'save respects user correctIndex override',
        'save' => true,
        'q' => [
            'prompt' => 'What is 15% of 240?',
            'options' => ['24', '36', '30', '48'],
            'correctIndex' => 2,
            'explanation' => '15% of 240 = 36.',
            'topic' => 'Percentage',
            'difficulty' => 'Medium',
        ],
        'expectIndex' => 2,
    ],
    [
        'name' => 'letter overrides wrong numeric',
        'q' => [
            'question' => 'Average of 10, 20, 30?',
            'options' => ['15', '20', '25', '30'],
            'correctAnswerIndex' => 3,
            'correctOptionLetter' => 'B',
            'explanation' => '(10+20+30)/3 = 20.',
            'topic' => 'Averages',
            'difficulty' => 'Easy',
        ],
        'expectIndex' => 1,
    ],
];

$passed = 0;
foreach ($cases as $case) {
    $result = !empty($case['save'])
        ? $mapSave->invoke($svc, $case['q'], 'Quantitative Aptitude')
        : $map->invoke($svc, $case['q'], 'Quantitative Aptitude', 'Percentage', 'Medium', 1.0, 0.0);
    $ok = is_array($result) && (int) ($result['correctIndex'] ?? -1) === $case['expectIndex'];
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $case['name'];
    if (!$ok) {
        echo ' (got ' . json_encode($result['correctIndex'] ?? null) . ', expected ' . $case['expectIndex'] . ')';
    }
    echo PHP_EOL;
    if ($ok) {
        $passed++;
    }
}

echo PHP_EOL . "Passed {$passed}/" . count($cases) . PHP_EOL;
exit($passed === count($cases) ? 0 : 1);
