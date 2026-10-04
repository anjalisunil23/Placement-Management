<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeTestModel;

/**
 * Use OpenAI to turn OCR / pasted manual text into structured MCQs when local parsing fails.
 */
final class AptitudeManualQuestionAiParser
{
    private const MAX_INPUT_CHARS = 14000;

    public function __construct(
        private ?OpenAIService $openai = null
    ) {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 80) {
            return [];
        }
        if (!$this->openai->isConfigured()) {
            return [];
        }
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_INPUT_CHARS);
        }

        $system = <<<'SYS'
You extract multiple-choice aptitude questions from uploaded question-manual text (OCR or PDF paste).
Return JSON only: {"questions":[...]}.
Each question must have: prompt (string), options (array of 4 or 5 strings for (a)-(e) style), correctIndex (0-based), explanation (string, may be empty).
Infer the correct answer when the manual marks it (Answer: B, Ans C, etc.). Skip items that are not MCQs.
Do not invent questions that are not present in the source text.
SYS;

        $user = "Extract every MCQ you can find in this manual text:\n\n" . $text;

        try {
            $raw = $this->openai->generateJson($system, $user, 4096);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude manual AI] parse failed: ' . $e->getMessage());

            return [];
        }

        $rows = [];
        if (isset($raw['questions']) && is_array($raw['questions'])) {
            $rows = $raw['questions'];
        }

        $out = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $opts = is_array($row['options'] ?? null) ? array_values($row['options']) : [];
            if (count($opts) < 2 && isset($row['optionA'])) {
                $opts = array_values(array_filter([
                    $row['optionA'] ?? '',
                    $row['optionB'] ?? '',
                    $row['optionC'] ?? '',
                    $row['optionD'] ?? '',
                ], static fn ($v): bool => trim((string) $v) !== ''));
            }
            $correct = (int) ($row['correctIndex'] ?? -1);
            if ($correct < 0 && isset($row['correct'])) {
                $letter = strtoupper(trim((string) $row['correct']));
                if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'D') {
                    $correct = ord($letter) - ord('A');
                }
            }
            $norm = AptitudeTestModel::normalizeMcq([
                'prompt' => (string) ($row['prompt'] ?? $row['question'] ?? ''),
                'options' => $opts,
                'correctIndex' => max(0, min(3, $correct)),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'category' => 'General Aptitude',
                'difficulty' => 'Medium',
                'source' => 'MANUAL_UPLOAD',
            ], 'General Aptitude', $i);
            if ($norm !== null) {
                $norm['source'] = 'MANUAL_UPLOAD';
                $out[] = $norm;
            }
        }

        return $out;
    }
}
