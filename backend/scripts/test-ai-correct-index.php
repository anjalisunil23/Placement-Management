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
        'name' => 'profit question aligns option with computed explanation answer',
        'q' => [
            'question' => 'A vendor sells a laptop for $800, making a profit of 25%. What was the cost price of the laptop?',
            'options' => ['$600', '$700', '$500', '$550'],
            'correctAnswerIndex' => 0,
            'correctOptionLetter' => 'A',
            'explanation' => 'Let the Cost Price be x. Selling Price = x + 0.25x = 1.25x. Therefore, 1.25x = $800, so x = $800 / 1.25 = $640.',
            'topic' => 'Profit and Loss',
            'difficulty' => 'Easy',
        ],
        'expectIndex' => 0,
        'expectOption' => '$640',
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
    if ($ok && isset($case['expectOption'])) {
        $opts = is_array($result['options'] ?? null) ? $result['options'] : [];
        $ok = (string) ($opts[$case['expectIndex']] ?? '') === $case['expectOption'];
    }
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $case['name'];
    if (!$ok) {
        echo ' (got index ' . json_encode($result['correctIndex'] ?? null);
        if (isset($case['expectOption'])) {
            $opts = is_array($result['options'] ?? null) ? $result['options'] : [];
            echo ', option ' . json_encode($opts[$case['expectIndex']] ?? null);
        }
        echo ', expected index ' . $case['expectIndex'];
        if (isset($case['expectOption'])) {
            echo ' option ' . $case['expectOption'];
        }
        echo ')';
    }
    echo PHP_EOL;
    if ($ok) {
        $passed++;
    }
}

echo PHP_EOL . "Passed {$passed}/" . count($cases) . PHP_EOL;
exit($passed === count($cases) ? 0 : 1);
