<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeTestModel;

/**
 * Infer correct MCQ answers for manually uploaded questions (admin preview / save).
 */
final class AptitudeManualAnswerAnalyzer
{
    private int $batchSize;

    public function __construct(
        private ?OpenAIService $openai = null,
        ?int $batchSize = null
    ) {
        $this->openai = $openai ?? new OpenAIService();
        $configured = $batchSize ?? (int) ($_ENV['APTITUDE_MANUAL_ANSWER_AI_BATCH'] ?? 6);
        $this->batchSize = max(3, min(8, $configured));
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @param int $maxResolve Stop after this many newly resolved answers (0 = no limit).
     */
    public function analyze(array &$questions, int $maxResolve = 0): int
    {
        if (!$this->openai->isConfigured() || $questions === []) {
            return 0;
        }

        $pending = [];
        foreach ($questions as $i => $q) {
            if (!empty($q['answerKnown']) || !empty($q['aiAnalyzeFailed'])) {
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
        if ($maxResolve > 0 && count($pending) > $maxResolve) {
            $pending = array_slice($pending, 0, $maxResolve, true);
        }

        $resolved = 0;
        foreach (array_chunk($pending, $this->batchSize, true) as $batch) {
            if ($maxResolve > 0 && $resolved >= $maxResolve) {
                break;
            }
            $hits = $this->analyzeBatchWithFallback($batch);
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
                if ($maxResolve > 0 && $resolved >= $maxResolve) {
                    break 2;
                }
            }
        }

        if ($maxResolve === 1 && count($pending) === 1 && $resolved === 0) {
            $onlyIdx = (int) array_key_first($pending);
            if (isset($questions[$onlyIdx]) && is_array($questions[$onlyIdx])) {
                $questions[$onlyIdx]['aiAnalyzeFailed'] = true;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     * @return array<int, array{answerLetter:string,explanation:string}>
     */
    private function analyzeBatchWithFallback(array $batch): array
    {
        $hits = $this->analyzeBatch($batch);
        if ($hits !== [] || count($batch) <= 1) {
            return $hits;
        }

        $merged = [];
        foreach ($batch as $idx => $q) {
            $one = $this->analyzeBatch([(int) $idx => $q]);
            foreach ($one as $k => $v) {
                $merged[(int) $k] = $v;
            }
        }

        return $merged;
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
            $directions = trim((string) ($q['directionsBlock'] ?? ''));
            $prompt = trim((string) ($q['prompt'] ?? ''));
            if ($directions !== '' && !str_contains($prompt, mb_substr($directions, 0, 40))) {
                $prompt = $directions . "\n\n" . $prompt;
            }
            $items[] = [
                'index' => (int) $idx,
                'questionNumber' => $num > 0 ? $num : null,
                'prompt' => mb_strlen($prompt) > 3500 ? (mb_substr($prompt, 0, 3500) . '…') : $prompt,
                'options' => $optLines,
                'questionType' => $questionType !== '' ? $questionType : 'MCQ',
            ];
        }
        if ($items === []) {
            return [];
        }

        $system = <<<'SYS'
You solve multiple-choice aptitude questions. Return JSON only:
{"answers":[{"index":0,"answerLetter":"B","explanation":"1. First step\n2. Second step\n3. Final answer is 36 (option B)."}]}

Rules:
- index must match the input index field exactly.
- answerLetter: use A–E for standard MCQ and statements/conclusions items; use 1–5 for data sufficiency items.
- explanation: 2–5 numbered steps on separate lines ("1. ", "2. ", …) showing the working. Include conversions, formulas, or logical checks as needed. The last step must state the final answer value and that it matches your chosen option.
- answerLetter and explanation must agree; if the computed result is 4%, choose the option that says 4%, not another percentage.
- Use only the given prompt and options; do not invent extra facts beyond standard logical/mathematical reasoning.
- For statements/conclusions, apply syllogism rules to conclusions I and II in separate steps, then pick the matching A–E rule option.
SYS;

        $user = "Solve each question and pick the correct option letter.\n\n"
            . json_encode(['questions' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        try {
            $maxTokens = min(8192, 2048 + count($items) * 512);
            $raw = $this->openai->generateJson($system, $user, $maxTokens, 0.0);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude manual analyze] failed: ' . $e->getMessage());

            return [];
        }

        $indexByQuestionNumber = [];
        foreach ($items as $item) {
            $qn = (int) ($item['questionNumber'] ?? 0);
            if ($qn > 0) {
                $indexByQuestionNumber[$qn] = (int) $item['index'];
            }
        }

        $out = [];
        $answerRows = (array) ($raw['answers'] ?? []);
        if ($answerRows === [] && isset($raw['index'])) {
            $answerRows = [$raw];
        }
        foreach ($answerRows as $pos => $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = (int) ($row['index'] ?? -1);
            if ($idx < 0 && isset($row['questionNumber'])) {
                $idx = (int) ($indexByQuestionNumber[(int) $row['questionNumber']] ?? -1);
            }
            if ($idx < 0 && count($items) === 1) {
                $idx = (int) ($items[0]['index'] ?? -1);
            }
            if ($idx < 0 && is_int($pos) && isset($items[$pos])) {
                $idx = (int) $items[$pos]['index'];
            }
            if ($idx < 0) {
                continue;
            }
            $letter = $this->normalizeAnswerLetter((string) ($row['answerLetter'] ?? $row['answer'] ?? ''));
            if ($letter === '') {
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
    private function normalizeAnswerLetter(string $raw): string
    {
        $letter = strtoupper(trim($raw));
        if ($letter === '') {
            return '';
        }
        if (preg_match('/^([A-E])$/', $letter, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^([1-5])$/', $letter, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/(?:OPTION|ANSWER|CHOICE)\s*([A-E1-5])/i', $raw, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b([A-E])\b/', $letter, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b([1-5])\b/', $letter, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function resolveOptionIndex(string $letter, array $options, string $questionType): int
    {
        if ($letter === '') {
            return -1;
        }
        if (preg_match('/^[A-E]$/', $letter) === 1) {
            return ord($letter) - ord('A');
        }
        if (preg_match('/^[1-5]$/', $letter) === 1) {
            return ((int) $letter) - 1;
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
