<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Parse MCQ-style aptitude questions from pasted or extracted manual text.
 */
final class AptitudeManualQuestionParser
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $text): array
    {
        $text = $this->normalizeManualText($text);
        if ($text === '') {
            return [];
        }

        $json = $this->parseJsonPayload($text);
        if ($json !== []) {
            return $json;
        }

        $blocks = $this->splitQuestionBlocks($text);
        $out = [];
        foreach ($blocks as $block) {
            $trim = trim($block);
            if ($trim === '') {
                continue;
            }
            if (!preg_match('/\([a-eA-E]\)/', $trim)
                && !preg_match('/(?:^|[\s])[a-eA-E]\)/u', $trim)
                && !preg_match('/[A-Da-d][\.\):]/u', $trim)) {
                continue;
            }
            $parsed = $this->parseQuestionBlock($trim);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $parsed
     * @return list<array<string, mixed>>
     */
    public function expandIfSingleMergedBlob(array $parsed, string $rawText): array
    {
        if (count($parsed) !== 1) {
            return $parsed;
        }
        $prompt = trim((string) ($parsed[0]['prompt'] ?? ''));
        $optionCount = count((array) ($parsed[0]['options'] ?? []));
        if (mb_strlen($prompt) < 280 && $optionCount <= 5) {
            return $parsed;
        }
        $again = $this->parse($rawText);

        return count($again) > 1 ? $again : $parsed;
    }

    private function normalizeManualText(string $text): string
    {
        $text = (new JdTextExtractionService())->sanitizeManualText($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace("/(\w)-\n(\w)/u", '$1$2', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function splitQuestionBlocks(string $text): array
    {
        $patterns = [
            '/(?=\n\s*\d{1,3}\)\s+\S)/u',
            '/(?=\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s+)/iu',
            '/(?=\n\s*\d{1,4}\s*[\.\):]\s+\S)/u',
            '/(?=\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}\s*[:\-]\s+\S)/iu',
        ];
        foreach ($patterns as $pattern) {
            $blocks = preg_split($pattern, "\n" . $text, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($blocks) && count($blocks) > 1) {
                return array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''));
            }
        }
        $inline = preg_split('/\s+(?=\d{1,3}\)\s+[A-Za-z(])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($inline) && count($inline) > 1) {
            return array_values(array_filter(array_map('trim', $inline), static fn (string $b): bool => $b !== ''));
        }

        $blocks = preg_split('/\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s+/iu', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($blocks)
            ? array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''))
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonPayload(string $text): array
    {
        $trim = ltrim($text);
        if ($trim === '' || ($trim[0] !== '[' && $trim[0] !== '{')) {
            return [];
        }
        try {
            $decoded = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $rows = isset($decoded['questions']) && is_array($decoded['questions'])
            ? $decoded['questions']
            : (isset($decoded[0]) ? $decoded : [$decoded]);
        $out = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = $this->normalizeRow($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseQuestionBlock(string $block): ?array
    {
        if ($block === '') {
            return null;
        }

        $block = preg_replace('/^\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s*/iu', '', $block) ?? $block;
        $block = preg_replace('/^\s*\d{1,3}\)\s*/u', '', $block) ?? $block;
        $answerLetter = null;
        if (preg_match('/(?:^|\n)\s*(?:Answer|Correct(?:\s+Answer)?|Ans)\s*[:\.\-]?\s*([A-Da-d])\b/im', $block, $am)) {
            $answerLetter = strtoupper($am[1]);
            $block = preg_replace('/(?:^|\n)\s*(?:Answer|Correct(?:\s+Answer)?|Ans)\s*[:\.\-]?\s*[A-Da-d]\b.*$/im', '', $block) ?? $block;
        }

        $explanation = '';
        if (preg_match('/(?:^|\n)\s*(?:Explanation|Solution|Exp)\s*[:\.\-]?\s*(.+)$/is', $block, $em)) {
            $explanation = trim($em[1]);
            $block = preg_replace('/(?:^|\n)\s*(?:Explanation|Solution|Exp)\s*[:\.\-]?\s*.+$/is', '', $block) ?? $block;
        }

        $options = $this->extractOptionsFromBlock($block);

        $prompt = trim($block);
        if ($options !== []) {
            $firstPos = null;
            foreach (array_keys($options) as $letter) {
                if (preg_match('/(?:^|[\n\s])\(' . preg_quote(strtolower($letter), '/') . '\)/i', $block, $pm, PREG_OFFSET_CAPTURE)
                    || preg_match('/(?:^|[\n\s])' . preg_quote(strtolower($letter), '/') . '\)/i', $block, $pm, PREG_OFFSET_CAPTURE)
                    || preg_match('/(?:^|\n)\s*' . preg_quote($letter, '/') . '[\.\):]/i', $block, $pm, PREG_OFFSET_CAPTURE)) {
                    $pos = (int) ($pm[0][1] ?? -1);
                    if ($pos >= 0 && ($firstPos === null || $pos < $firstPos)) {
                        $firstPos = $pos;
                    }
                }
            }
            if ($firstPos !== null && $firstPos > 0) {
                $prompt = trim(substr($block, 0, $firstPos));
            } else {
                $prompt = preg_replace('/(?:^|\n)\s*[A-Da-d][\.\):].*$/s', '', $block) ?? $prompt;
                $prompt = trim($prompt);
            }
        }

        if ($prompt === '' || count($options) < 2) {
            return null;
        }

        ksort($options);
        $optionList = array_slice(array_values($options), 0, 5);
        while (count($optionList) < 4) {
            $optionList[] = '—';
        }

        $correctIndex = 0;
        if ($answerLetter !== null && isset($options[$answerLetter])) {
            $letters = array_keys($options);
            $correctIndex = (int) array_search($answerLetter, $letters, true);
            if ($correctIndex < 0) {
                $correctIndex = 0;
            }
        }
        $correctIndex = max(0, min(count($optionList) - 1, $correctIndex));

        return [
            'prompt' => $prompt,
            'options' => $optionList,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => 'General Aptitude',
            'difficulty' => 'Medium',
            'source' => 'MANUAL_UPLOAD',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractOptionsFromBlock(string $block): array
    {
        $options = [];
        if (preg_match_all(
            '/(?:^|[\n\s])\(([a-eA-E])\)\s*(.+?)(?=(?:[\n\s]\([a-eA-E]\)|\s+\d{1,3}\)|$))/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|[\n\s])([a-eA-E])\)\s*(.+?)(?=(?:[\n\s][a-eA-E]\)|\s+\d{1,3}\)|$))/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([A-Da-d])[\.\):]\s*(.+?)(?=\n\s*[A-Da-d][\.\):]|\n\s*(?:Answer|Correct|Explanation)|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n|\s)\(([A-Da-d])\)\s*(.+?)(?=(?:^|\n|\s)\([A-Da-d]\)|\n\s*(?:Answer|Correct)|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([1-4])[\.\):]\s*(.+?)(?=\n\s*[1-4][\.\):]|\n\s*(?:Answer|Correct|Explanation)|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            $map = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];
            foreach ($matches as $m) {
                $letter = $map[$m[1]] ?? '';
                if ($letter !== '') {
                    $options[$letter] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
                }
            }
        }
        if ($options === [] && preg_match_all(
            '/\b([A-Da-d])\.\s+(.+?)(?=\s+[A-Da-d]\.\s+|\s*(?:Answer|Ans)\s*[:\.]|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = trim(preg_replace('/\s+/u', ' ', $m[2]) ?? $m[2]);
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        if (!isset($row['options']) && (isset($row['optionA']) || isset($row['A']))) {
            $opts = [];
            foreach (['optionA', 'optionB', 'optionC', 'optionD', 'optionE', 'A', 'B', 'C', 'D', 'E'] as $k) {
                if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                    $opts[] = trim((string) $row[$k]);
                }
            }
            $row['options'] = $opts;
        }
        $prompt = trim((string) ($row['prompt'] ?? $row['question'] ?? ''));
        $opts = is_array($row['options'] ?? null) ? $row['options'] : [];
        if ($prompt === '' || count($opts) < 2) {
            return null;
        }
        while (count($opts) < 4) {
            $opts[] = '—';
        }
        $correct = (int) ($row['correctIndex'] ?? -1);
        if ($correct < 0 && isset($row['correct'])) {
            $c = strtoupper(trim((string) $row['correct']));
            $correct = max(0, ord($c) - ord('A'));
        }

        return [
            'prompt' => $prompt,
            'options' => array_slice(array_values($opts), 0, 5),
            'correctIndex' => max(0, min(max(count($opts), 1) - 1, $correct)),
            'explanation' => trim((string) ($row['explanation'] ?? '')),
            'category' => trim((string) ($row['category'] ?? 'General Aptitude')) ?: 'General Aptitude',
            'difficulty' => trim((string) ($row['difficulty'] ?? 'Medium')) ?: 'Medium',
            'source' => 'MANUAL_UPLOAD',
        ];
    }
}
