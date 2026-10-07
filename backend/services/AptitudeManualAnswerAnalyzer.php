<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeTestModel;

/**
 * Infer correct MCQ answers for manually uploaded questions (admin preview / save).
 */
final class AptitudeManualAnswerAnalyzer
{
    private const BATCH_SIZE = 6;

    public function __construct(
        private ?OpenAIService $openai = null
    ) {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @param list<array<string, mixed>> $questions
     */
    public function analyze(array &$questions): int
    {
        if (!$this->openai->isConfigured() || $questions === []) {
            return 0;
        }

        $pending = [];
        foreach ($questions as $i => $q) {
            if (!empty($q['answerKnown'])) {
                continue;
            }
            $opts = array_values(array_filter(
                array_map(static fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])),
                static fn ($o) => $o !== '' && $o !== '—'
            ));
            if (count($opts) < 2) {
                continue;
            }
            $pending[$i] = $q;
        }
        if ($pending === []) {
            return 0;
        }

        $resolved = 0;
        foreach (array_chunk($pending, self::BATCH_SIZE, true) as $batch) {
            $hits = $this->analyzeBatch($batch);
            foreach ($hits as $idx => $hit) {
                $idx = (int) $idx;
                if (!isset($questions[$idx]) || !is_array($questions[$idx])) {
                    continue;
                }
                $letter = strtoupper(trim((string) ($hit['answerLetter'] ?? '')));
                $opts = array_values((array) ($questions[$idx]['options'] ?? []));
                $optIdx = $this->resolveOptionIndex(
                    $letter,
                    $opts,
                    (string) ($questions[$idx]['questionType'] ?? '')
                );
                if ($optIdx < 0 || $optIdx >= count($opts)) {
                    continue;
                }
                $exp = trim((string) ($hit['explanation'] ?? ''));
                $reconciled = $this->reconcileAiAnswer($opts, $optIdx, $exp);
                $questions[$idx]['correctIndex'] = $reconciled['correctIndex'];
                $questions[$idx]['answerKnown'] = true;
                $questions[$idx]['answerSource'] = 'ai';
                $questions[$idx]['aiAnalyzed'] = true;
                if ($reconciled['explanation'] !== '') {
                    $questions[$idx]['explanation'] = $reconciled['explanation'];
                }
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     * @return array<int, array{answerLetter:string,explanation:string}>
     */
    private function analyzeBatch(array $batch): array
    {
        $items = [];
        foreach ($batch as $idx => $q) {
            $opts = array_values((array) ($q['options'] ?? []));
            $questionType = trim((string) ($q['questionType'] ?? ''));
            $useNumeric = $questionType === 'DATA_SUFFICIENCY';
            $letters = ['A', 'B', 'C', 'D', 'E'];
            $optLines = [];
            foreach ($opts as $oi => $text) {
                $t = trim((string) $text);
                if ($t === '' || $t === '—') {
                    continue;
                }
                $label = $useNumeric ? (string) ($oi + 1) : ($letters[$oi] ?? (string) ($oi + 1));
                $optLines[] = $label . ') ' . $t;
            }
            if ($optLines === []) {
                continue;
            }
            $num = (int) ($q['questionNumber'] ?? 0);
            $items[] = [
                'index' => (int) $idx,
                'questionNumber' => $num > 0 ? $num : null,
                'prompt' => trim((string) ($q['prompt'] ?? '')),
                'options' => $optLines,
                'questionType' => $questionType,
            ];
        }
        if ($items === []) {
            return [];
        }

        $system = <<<'SYS'
You solve multiple-choice aptitude questions. Return JSON only:
{"answers":[{"index":0,"answerLetter":"B","explanation":"One short sentence."}]}

Rules:
- index must match the input index field exactly.
- answerLetter: use A–E for standard MCQ and statements/conclusions items; use 1–5 for data sufficiency items.
- explanation: brief reason (one sentence) that states the same final value as your chosen option text.
- answerLetter and explanation must agree; if the computed result is 4%, choose the option that says 4%, not another percentage.
- Use only the given prompt and options; do not invent extra facts beyond standard logical/mathematical reasoning.
- For statements/conclusions, apply syllogism rules to conclusions I and II, then pick the matching A–E rule option.
SYS;

        $user = "Solve each question and pick the correct option letter.\n\n"
            . json_encode(['questions' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        try {
            $raw = $this->openai->generateJson($system, $user, 4096, 0.0);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude manual analyze] failed: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ((array) ($raw['answers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = (int) ($row['index'] ?? -1);
            if ($idx < 0) {
                continue;
            }
            $letter = strtoupper(trim((string) ($row['answerLetter'] ?? '')));
            if (!preg_match('/^[A-E1-5]$/', $letter)) {
                continue;
            }
            $out[$idx] = [
                'answerLetter' => $letter,
                'explanation' => trim((string) ($row['explanation'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $options
     */
    private function resolveOptionIndex(string $letter, array $options, string $questionType): int
    {
        if ($letter === '') {
            return -1;
        }
        if ($questionType === 'DATA_SUFFICIENCY' && preg_match('/^[1-5]$/', $letter) === 1) {
            return ((int) $letter) - 1;
        }
        if (preg_match('/^[A-E]$/', $letter) === 1) {
            return ord($letter) - ord('A');
        }

        return -1;
    }

    /**
     * Prefer explanation-derived answer when AI letter and reasoning disagree.
     *
     * @param list<string> $options
     * @return array{correctIndex:int,explanation:string}
     */
    private function reconcileAiAnswer(array $options, int $letterIndex, string $explanation): array
    {
        $opts = array_values($options);
        $count = count($opts);
        if ($count === 0) {
            return ['correctIndex' => 0, 'explanation' => trim($explanation)];
        }

        $correctIndex = max(0, min($count - 1, $letterIndex));
        $explanation = trim($explanation);
        if ($explanation === '') {
            return ['correctIndex' => $correctIndex, 'explanation' => ''];
        }

        $fromExplanation = AptitudeTestModel::findUniqueOptionInExplanation($opts, $explanation);
        if ($fromExplanation !== null) {
            $correctIndex = $fromExplanation;
        } else {
            $computed = AptitudeTestModel::extractComputedNumericFromExplanation($explanation);
            if ($computed !== null) {
                foreach ($opts as $i => $opt) {
                    $optNum = AptitudeTestModel::parseOptionNumeric((string) $opt);
                    if ($optNum !== null && abs($optNum - $computed) < 0.01) {
                        $correctIndex = $i;
                        break;
                    }
                }
            }
        }

        $explanation = AptitudeTestModel::ensureExplanationMentionsCorrectOption($opts, $correctIndex, $explanation);

        return ['correctIndex' => $correctIndex, 'explanation' => $explanation];
    }
}
