<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeTestModel;

/**
 * Extract (not generate) structured MCQs from manual / OCR text via OpenAI.
 */
final class AptitudeManualQuestionAiParser
{
    private const CHUNK_CHARS = 12000;

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
        if ($text === '' || mb_strlen($text) < 60) {
            return [];
        }
        if (!$this->openai->isConfigured()) {
            return [];
        }

        $answerKey = self::parseAnswerKeyMap($text);
        $chunks = self::splitIntoChunks($text);
        $merged = [];
        $seen = [];

        foreach ($chunks as $chunk) {
            $batch = $this->parseChunk($chunk['text'], (int) ($chunk['defaultPage'] ?? 1));
            foreach ($batch as $row) {
                $sig = self::questionSignature($row);
                if ($sig === '' || isset($seen[$sig])) {
                    continue;
                }
                $seen[$sig] = true;
                $merged[] = $row;
            }
        }

        if ($merged === []) {
            return [];
        }

        self::applyAnswerKeyToQuestions($merged, $answerKey);

        usort($merged, static function (array $a, array $b): int {
            $na = (int) ($a['questionNumber'] ?? 0);
            $nb = (int) ($b['questionNumber'] ?? 0);
            if ($na > 0 && $nb > 0 && $na !== $nb) {
                return $na <=> $nb;
            }
            if ($na > 0 && $nb <= 0) {
                return -1;
            }
            if ($nb > 0 && $na <= 0) {
                return 1;
            }

            return 0;
        });

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseChunk(string $text, int $defaultPage): array
    {
        $system = <<<'SYS'
You EXTRACT multiple-choice questions from an uploaded aptitude question paper (OCR or PDF text).
You must NOT invent, rewrite, simplify, or solve questions. Copy wording and numbers exactly from the source.

Return JSON only:
{
  "questions": [
    {
      "questionNumber": 1,
      "prompt": "full question text; prepend shared directions/passage for this item only when needed for context",
      "options": ["opt A text", "opt B text", ...],
      "section": "section heading from the paper if present, else empty string",
      "sourcePage": 1,
      "confidence": 0.95,
      "containsImage": false,
      "answerLetter": "",
      "directionsBlock": ""
    }
  ]
}

Rules:
- Include every MCQ you can find. Support options labeled A–E, a–e, or 1–5 (store option text only, without labels).
- directionsBlock: store shared "Directions (N-M):" text once per group; do not repeat the full directions in every prompt unless the question truly stands alone.
- answerLetter: only if explicitly marked in the source (Answer: B, Ans: C). Otherwise empty string.
- sourcePage: use "--- PAGE N ---" markers in the input when present; else use the chunk default page.
- containsImage: true if the question depends on a diagram/graph/table image not fully described in text.
- confidence: 0.0–1.0 for extraction certainty.
- Skip non-MCQ content. Do not merge the entire paper into one question.
- Data sufficiency: when Directions define (1)–(5) for statement (i)/(ii) items, use those five as options; put the question plus (i) and (ii) in prompt; set questionType to DATA_SUFFICIENCY and section from the paper.
SYS;

        $user = "Default page if no marker: {$defaultPage}\n\nExtract MCQs from this text:\n\n" . $text;

        try {
            $raw = $this->openai->generateJson($system, $user, 8192, 0.0);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude manual AI] parse failed: ' . $e->getMessage());

            return [];
        }

        $rows = isset($raw['questions']) && is_array($raw['questions']) ? $raw['questions'] : [];

        return $this->normalizeAiRows($rows);
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeAiRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $opts = self::normalizeOptions($row);
            if ($opts === []) {
                continue;
            }
            $prompt = trim((string) ($row['prompt'] ?? $row['questionText'] ?? $row['question'] ?? ''));
            $directions = trim((string) ($row['directionsBlock'] ?? ''));
            if ($directions !== '' && !str_contains($prompt, $directions)) {
                $prompt = $directions . "\n\n" . $prompt;
            }
            $prompt = trim($prompt);
            if ($prompt === '') {
                continue;
            }

            $answerLetter = strtoupper(trim((string) ($row['answerLetter'] ?? $row['answer'] ?? '')));
            $correctIndex = 0;
            $answerKnown = false;
            if (strlen($answerLetter) === 1 && $answerLetter >= 'A' && $answerLetter <= 'E') {
                $idx = ord($answerLetter) - ord('A');
                if ($idx >= 0 && $idx < count($opts)) {
                    $correctIndex = $idx;
                    $answerKnown = true;
                }
            }

            $section = trim((string) ($row['section'] ?? ''));
            $category = $section !== '' ? $section : 'General Aptitude';
            $sourcePage = max(0, (int) ($row['sourcePage'] ?? 0));
            $qNum = max(0, (int) ($row['questionNumber'] ?? 0));
            $confidence = (float) ($row['confidence'] ?? 0.85);
            if ($confidence < 0 || $confidence > 1) {
                $confidence = 0.85;
            }

            $norm = AptitudeTestModel::normalizeMcq([
                'prompt' => $prompt,
                'options' => $opts,
                'correctIndex' => $correctIndex,
                'explanation' => '',
                'category' => $category,
                'difficulty' => trim((string) ($row['difficulty'] ?? 'Medium')) ?: 'Medium',
                'lockCorrectIndex' => $answerKnown,
            ], $category, $i);

            if ($norm === null) {
                continue;
            }

            $norm['source'] = 'MANUAL_UPLOAD';
            $norm['questionNumber'] = $qNum > 0 ? $qNum : ($i + 1);
            $norm['sourcePage'] = $sourcePage;
            $norm['section'] = $section;
            $norm['confidence'] = round($confidence, 2);
            $norm['containsImage'] = !empty($row['containsImage']);
            $norm['answerKnown'] = $answerKnown;
            if ($directions !== '') {
                $norm['directionsBlock'] = $directions;
            }
            if ($section !== '') {
                $norm['topic'] = $section;
            }

            $out[] = $norm;
        }

        if (count($out) === 1 && mb_strlen(trim((string) ($out[0]['prompt'] ?? ''))) > 4000) {
            return [];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function normalizeOptions(array $row): array
    {
        if (is_array($row['options'] ?? null)) {
            $opts = [];
            foreach ($row['options'] as $opt) {
                if (is_array($opt)) {
                    $t = trim((string) ($opt['text'] ?? ''));
                } else {
                    $t = trim((string) $opt);
                }
                if ($t !== '') {
                    $opts[] = AptitudeTestModel::sanitizeOptionText($t);
                }
            }

            return array_values(array_filter($opts, static fn (string $s): bool => $s !== ''));
        }

        $legacy = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $k = 'option' . $letter;
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                $legacy[] = AptitudeTestModel::sanitizeOptionText((string) $row[$k]);
            }
        }

        return $legacy;
    }

    /**
     * @return list<array{text:string, defaultPage:int}>
     */
    public static function splitIntoChunks(string $text): array
    {
        if (!preg_match('/---\s*PAGE\s+\d+\s*---/iu', $text)) {
            return self::splitBySize($text, 1);
        }

        $parts = preg_split('/(?=---\s*PAGE\s+\d+\s*---)/iu', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return self::splitBySize($text, 1);
        }

        $chunks = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $page = 1;
            if (preg_match('/---\s*PAGE\s+(\d+)\s*---/iu', $part, $pm)) {
                $page = max(1, (int) $pm[1]);
            }
            if (mb_strlen($part) <= self::CHUNK_CHARS) {
                $chunks[] = ['text' => $part, 'defaultPage' => $page];
                continue;
            }
            foreach (self::splitBySize($part, $page) as $sub) {
                $chunks[] = $sub;
            }
        }

        return $chunks !== [] ? $chunks : self::splitBySize($text, 1);
    }

    /**
     * @return list<array{text:string, defaultPage:int}>
     */
    private static function splitBySize(string $text, int $defaultPage): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= self::CHUNK_CHARS) {
            return [['text' => $text, 'defaultPage' => $defaultPage]];
        }

        $out = [];
        $offset = 0;
        $len = mb_strlen($text);
        while ($offset < $len) {
            $slice = mb_substr($text, $offset, self::CHUNK_CHARS);
            $out[] = ['text' => $slice, 'defaultPage' => $defaultPage];
            $offset += self::CHUNK_CHARS;
        }

        return $out;
    }

    /**
     * @return array<int, string> question number => A-E
     */
    public static function parseAnswerKeyMap(string $text): array
    {
        if (!preg_match('/Answer\s*(?:Key|Sheet|Keys)?\s*:?\s*([\s\S]{20,8000})$/iu', $text, $m)) {
            return [];
        }
        $block = (string) ($m[1] ?? '');
        $map = [];
        if (preg_match_all('/(?:^|[\s,;])(\d{1,3})\s*[-–:.]\s*([A-Ea-e])\b/mu', $block, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $map[(int) $hit[1]] = strtoupper((string) $hit[2]);
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $answerKey
     * @param list<array<string, mixed>> $questions
     */
    public static function applyAnswerKeyToQuestions(array &$questions, array $answerKey): void
    {
        if ($answerKey === []) {
            return;
        }
        foreach ($questions as &$q) {
            if (!empty($q['answerKnown'])) {
                continue;
            }
            $num = (int) ($q['questionNumber'] ?? 0);
            if ($num <= 0 || !isset($answerKey[$num])) {
                continue;
            }
            $letter = $answerKey[$num];
            $idx = ord($letter) - ord('A');
            $opts = (array) ($q['options'] ?? []);
            if ($idx >= 0 && $idx < count($opts)) {
                $q['correctIndex'] = $idx;
                $q['answerKnown'] = true;
                $q['lockCorrectIndex'] = true;
            }
        }
        unset($q);
    }

    /**
     * @param array<string, mixed> $q
     */
    private static function questionSignature(array $q): string
    {
        $prompt = mb_strtolower(trim((string) ($q['prompt'] ?? '')));
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
        if ($prompt === '') {
            return '';
        }
        $opts = implode('|', array_map(static fn ($o) => mb_strtolower(trim((string) $o)), (array) ($q['options'] ?? [])));

        return md5(mb_substr($prompt, 0, 200) . '::' . $opts);
    }
}
