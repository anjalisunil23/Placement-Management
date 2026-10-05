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

        $passageForQuestion = $this->mapDirectionPassages($text);
        $dataSufficiency = $this->parseDataSufficiencySections($text);
        $parsedDsNums = [];
        foreach ($dataSufficiency as $dsq) {
            $n = (int) ($dsq['questionNumber'] ?? 0);
            if ($n > 0) {
                $parsedDsNums[$n] = true;
            }
        }

        $blocks = $this->splitQuestionBlocks($text);
        $out = [];
        foreach ($blocks as $block) {
            $trim = trim($block);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^Directions\s*\(\d+\s*-\s*\d+\)\s*:/iu', $trim)) {
                continue;
            }
            if (preg_match('/^Directions\s*:/iu', $trim)) {
                continue;
            }
            $qNum = $this->leadingQuestionNumber($trim);
            if ($qNum !== null && isset($parsedDsNums[$qNum])) {
                continue;
            }
            if (!preg_match('/\([a-eA-E]\)/', $trim)
                && !preg_match('/(?:^|[\s])[a-eA-E]\)/u', $trim)
                && !preg_match('/[A-Da-d][\.\):]/u', $trim)) {
                continue;
            }
            if ($qNum !== null && isset($passageForQuestion[$qNum])) {
                $trim = $passageForQuestion[$qNum] . "\n\n" . $trim;
            }
            $parsed = $this->parseQuestionBlock($trim);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        if ($dataSufficiency !== []) {
            $out = array_merge($out, $dataSufficiency);
            usort($out, static function (array $a, array $b): int {
                $na = (int) ($a['questionNumber'] ?? 0);
                $nb = (int) ($b['questionNumber'] ?? 0);
                if ($na > 0 && $nb > 0) {
                    return $na <=> $nb;
                }

                return 0;
            });
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
            '/(?=\n\s*Directions\s*(?:\(\d+\s*-\s*\d+\)\s*|:))/iu',
            '/(?=\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s+)/iu',
            '/(?=\n\s*\d{1,4}\s*[\.\):]\s+\S)/u',
            '/(?=\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}\s*[:\-]\s+\S)/iu',
        ];
        foreach ($patterns as $pattern) {
            $blocks = preg_split($pattern, "\n" . $text, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($blocks) && count($blocks) > 1) {
                $blocks = array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''));
                return $this->refineQuestionBlocks($blocks);
            }
        }
        $inline = preg_split('/\s+(?=\d{1,3}\)\s+[A-Za-z(])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($inline) && count($inline) > 1) {
            return array_values(array_filter(array_map('trim', $inline), static fn (string $b): bool => $b !== ''));
        }

        $blocks = preg_split('/\n\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s+/iu', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($blocks)
            ? $this->refineQuestionBlocks(array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== '')))
            : [];
    }

    /**
     * Split blocks that still contain multiple numbered questions or a glued Directions section.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    private function refineQuestionBlocks(array $blocks): array
    {
        $out = [];
        $subSplit = '/(?=\n\s*\d{1,3}\)\s+\S)/u';
        foreach ($blocks as $block) {
            $trim = trim($block);
            if ($trim === '') {
                continue;
            }
            $parts = preg_split($subSplit, "\n" . $trim, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts) && count($parts) > 1) {
                foreach ($parts as $part) {
                    $p = trim($part);
                    if ($p !== '') {
                        $out[] = $p;
                    }
                }
                continue;
            }
            $out[] = $trim;
        }

        return $out;
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

        $questionNumber = $this->leadingQuestionNumber($block);

        $block = $this->stripTrailingDirectionsTail($block);

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

        $row = [
            'prompt' => $prompt,
            'options' => $optionList,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => 'General Aptitude',
            'difficulty' => 'Medium',
            'source' => 'MANUAL_UPLOAD',
            'answerKnown' => $answerLetter !== null,
        ];
        if ($questionNumber !== null && $questionNumber > 0) {
            $row['questionNumber'] = $questionNumber;
        }

        return $row;
    }

    /**
     * @return array<string, string>
     */
    private function extractOptionsFromBlock(string $block): array
    {
        $options = [];
        if (preg_match_all(
            '/(?:^|[\n\s])\(([a-eA-E])\)\s*(.+?)(?=(?:[\n\s]\([a-eA-E]\)|\s+\d{1,3}\)|\s+Directions\s*\(|$))/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|[\n\s])([a-eA-E])\)\s*(.+?)(?=(?:[\n\s][a-eA-E]\)|\s+\d{1,3}\)|\s+Directions\s*\(|$))/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([A-Da-d])[\.\):]\s*(.+?)(?=\n\s*[A-Da-d][\.\):]|\n\s*(?:Answer|Correct|Explanation)|\s+Directions\s*\(|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n|\s)\(([A-Da-d])\)\s*(.+?)(?=(?:^|\n|\s)\([A-Da-d]\)|\n\s*(?:Answer|Correct)|\s+Directions\s*\(|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([1-4])[\.\):]\s*(.+?)(?=\n\s*[1-4][\.\):]|\n\s*(?:Answer|Correct|Explanation)|\s+Directions\s*\(|\z)/s',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            $map = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];
            foreach ($matches as $m) {
                $letter = $map[$m[1]] ?? '';
                if ($letter !== '') {
                    $options[$letter] = $this->cleanOptionText($m[2]);
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
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }

        foreach ($options as $letter => $label) {
            $options[$letter] = $this->cleanOptionText($label);
            if ($options[$letter] === '') {
                unset($options[$letter]);
            }
        }

        return $options;
    }

    /**
     * @return array<int, string> question number => directions + passage prefix
     */
    private function mapDirectionPassages(string $text): array
    {
        $map = [];
        if (!preg_match_all(
            '/Directions\s*\((\d+)\s*-\s*(\d+)\)\s*:([\s\S]*?)(?=\n\s*(?:\d{1,3}\)\s|\d{1,4}[\.\):]\s))/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            return $map;
        }
        foreach ($matches as $m) {
            $start = (int) $m[1];
            $end = (int) $m[2];
            if ($start <= 0 || $end < $start || $end - $start > 30) {
                continue;
            }
            $body = trim(preg_replace('/\s+/u', ' ', $m[3]) ?? $m[3]);
            if ($body === '') {
                continue;
            }
            $prefix = 'Directions (' . $start . '–' . $end . '): ' . $body;
            for ($q = $start; $q <= $end; $q++) {
                $map[$q] = $prefix;
            }
        }

        return $map;
    }

    private function leadingQuestionNumber(string $block): ?int
    {
        if (preg_match('/^\s*(?:Q(?:uestion)?\s*)?(\d{1,4})[\.\):]/iu', $block, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\s*(\d{1,3})\)\s/u', $block, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function stripTrailingDirectionsTail(string $block): string
    {
        if (preg_match('/\([a-eA-E]\)|[a-eA-E]\)/u', $block)
            && preg_match('/\s+Directions\s*:/iu', $block, $dm, PREG_OFFSET_CAPTURE)) {
            $block = trim(substr($block, 0, (int) $dm[0][1]));
        }
        $stripped = preg_replace('/\s+Directions\s*\(\d+\s*-\s*\d+\)\s*:.*$/isu', '', $block);
        $stripped = preg_replace('/\s+Directions\s*:[\s\S]*$/iu', '', $stripped ?? $block);

        return trim($stripped ?? $block);
    }

    /**
     * Data sufficiency sets: shared Directions with (1)–(5) answers and per-question (i)/(ii) statements.
     *
     * @return list<array<string, mixed>>
     */
    private function parseDataSufficiencySections(string $text): array
    {
        if (!preg_match('/Directions\s*:[\s\S]*?statement\s*\(\s*i\s*\)/iu', $text)
            && !preg_match('/Directions\s*:[\s\S]*?\(\s*1\s*\)/iu', $text)) {
            return [];
        }

        $out = [];
        if (!preg_match_all(
            '/Directions\s*(?!\(\s*\d+\s*-)\s*:([\s\S]*?)(?=\n\s*\d{1,3}\)\s+\S)/iu',
            $text,
            $dirMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($dirMatches as $dirMatch) {
            $body = trim((string) ($dirMatch[1][0] ?? ''));
            if ($body === '' || !preg_match('/statement\s*\(\s*i\s*\)|data in statement|\(\s*1\s*\)/iu', $body)) {
                continue;
            }
            $options = $this->extractDataSufficiencyOptions($body);
            if (count($options) < 2) {
                continue;
            }
            $dirEnd = (int) ($dirMatch[0][1] ?? 0) + strlen((string) ($dirMatch[0][0] ?? ''));
            $tail = substr($text, $dirEnd);
            if (!is_string($tail) || trim($tail) === '') {
                continue;
            }
            $tail = preg_split('/(?=\n\s*Directions\s*(?:\(\d+\s*-|:))/iu', $tail, 2)[0] ?? $tail;
            $chunks = preg_split('/(?=\n\s*\d{1,3}\)\s+\S)/u', "\n" . trim($tail), -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chunks)) {
                continue;
            }
            $directionsPrefix = 'Directions: ' . trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '' || !preg_match('/^\s*(\d{1,3})\)\s+/u', $chunk, $qm)) {
                    continue;
                }
                $qNum = (int) $qm[1];
                if (!preg_match('/\bi\)/iu', $chunk) || !preg_match('/\bii\)/iu', $chunk)) {
                    continue;
                }
                if (preg_match('/\([a-eA-E]\)|(?:^|[\s])[a-eA-E]\)/u', $chunk)) {
                    continue;
                }

                $promptBody = preg_replace('/^\s*\d{1,3}\)\s*/u', '', $chunk) ?? $chunk;
                $promptBody = trim($promptBody);
                if ($promptBody === '') {
                    continue;
                }

                $prompt = $directionsPrefix . "\n\n" . $qNum . ') ' . $promptBody;
                $optionList = array_slice(array_values($options), 0, 5);
                while (count($optionList) < 4) {
                    $optionList[] = '—';
                }

                $out[] = [
                    'prompt' => $prompt,
                    'options' => $optionList,
                    'correctIndex' => 0,
                    'explanation' => '',
                    'category' => 'Data Sufficiency',
                    'difficulty' => 'Medium',
                    'source' => 'MANUAL_UPLOAD',
                    'questionNumber' => $qNum,
                    'section' => 'Data Sufficiency',
                    'questionType' => 'DATA_SUFFICIENCY',
                    'directionsBlock' => $directionsPrefix,
                    'answerKnown' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, string> 1-based option index => label
     */
    private function extractDataSufficiencyOptions(string $directionsBody): array
    {
        $opts = [];
        if (preg_match_all(
            '/\(\s*([1-5])\s*\)\s*(.+?)(?=\(\s*[1-5]\s*\)|$)/su',
            $directionsBody,
            $hits,
            PREG_SET_ORDER
        )) {
            foreach ($hits as $hit) {
                $label = trim(preg_replace('/\s+/u', ' ', $hit[2]) ?? $hit[2]);
                if ($label !== '') {
                    $opts[(int) $hit[1]] = $label;
                }
            }
        }
        if (count($opts) >= 4) {
            ksort($opts);

            return $opts;
        }

        return [
            1 => 'If the data in statement (i) alone are sufficient to answer the question, while data in statement (ii) alone are not sufficient to answer the question.',
            2 => 'If the data in statement (ii) alone are sufficient to answer the question, while data in statement (i) alone are not sufficient to answer the question.',
            3 => 'If the data either in statement (i) alone or in statement (ii) alone are sufficient to answer the question.',
            4 => 'If the data given in both statements (i) and (ii) together are not sufficient to answer the question.',
            5 => 'If the data given in both statements (i) and (ii) together are necessary to answer the question.',
        ];
    }

    private function cleanOptionText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/\s+Directions\s*\(\d+\s*-\s*\d+\)\s*:.*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+\d{1,3}\)\s+Study\s+the\s+following.*$/iu', '', $text) ?? $text;

        return trim($text);
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
