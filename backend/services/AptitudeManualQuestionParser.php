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
        $sectionForQuestion = $this->mapSectionTopicsForQuestions($text);
        $dataSufficiency = $this->parseDataSufficiencySections($text);
        $parsedDsNums = [];
        foreach ($dataSufficiency as $dsq) {
            $n = (int) ($dsq['questionNumber'] ?? 0);
            if ($n > 0) {
                $parsedDsNums[$n] = true;
            }
        }

        $statementsConclusions = $this->parseStatementsConclusionsSections($text);
        $parsedScNums = [];
        foreach ($statementsConclusions as $scq) {
            $n = (int) ($scq['questionNumber'] ?? 0);
            if ($n > 0) {
                $parsedScNums[$n] = true;
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
            // Topic banners are not questions.
            if (preg_match('/^\s*.{0,120}\((?:Sample\s+)?Questions?\)\s*$/iu', $trim)
                && !preg_match('/[a-eA-E][\.\)]|[A-Ea-e][\.\):]/u', $trim)) {
                continue;
            }
            $qNum = $this->leadingQuestionNumber($trim);
            if ($qNum !== null && (isset($parsedDsNums[$qNum]) || isset($parsedScNums[$qNum]))) {
                continue;
            }
            if (!preg_match('/\([a-eA-E]\)/', $trim)
                && !preg_match('/(?:^|[\s])[a-eA-E]\)/u', $trim)
                && !preg_match('/[A-Ea-e][\.\):]/u', $trim)) {
                continue;
            }
            $questionPart = self::stripLeadingQuestionNumber(self::stripDirectionsRangeLabel($trim));
            $questionPart = self::cutAtNextSectionHeading($questionPart);
            if ($qNum !== null && isset($passageForQuestion[$qNum])) {
                $trim = $passageForQuestion[$qNum] . "\n\n" . $questionPart;
            } else {
                $trim = $questionPart;
            }
            $parsed = $this->parseQuestionBlock($trim);
            if ($parsed !== null) {
                if ($qNum !== null && empty($parsed['questionNumber'])) {
                    $parsed['questionNumber'] = $qNum;
                }
                if ($qNum !== null && isset($sectionForQuestion[$qNum]) && empty($parsed['section'])) {
                    $parsed['section'] = $sectionForQuestion[$qNum];
                }
                $parsed['prompt'] = self::cutAtNextNumberedQuestion(
                    self::cutAtNextStudyPassage(
                        self::cutAtNextSectionHeading(
                            self::stripEmbeddedQuestionNumber(
                                (string) ($parsed['prompt'] ?? ''),
                                (int) ($parsed['questionNumber'] ?? $qNum ?? 0)
                            )
                        )
                    )
                );
                // Drop a trailing arrangement token row glued onto unrelated prompts
                // (keep it when this question is itself an arrangement item).
                if (!JdTextExtractionService::mentionsSymbolArrangement((string) $parsed['prompt'])
                    && !preg_match('/\babove\s+arrangement\b/iu', (string) $parsed['prompt'])) {
                    $parsed['prompt'] = self::cutTrailingArrangementLine((string) $parsed['prompt']);
                }
                $parsed['prompt'] = $this->ensureArrangementLineOnPrompt(
                    (string) ($parsed['prompt'] ?? ''),
                    $text,
                    (int) ($parsed['questionNumber'] ?? $qNum ?? 0)
                );
                if (isset($parsed['options']) && is_array($parsed['options'])) {
                    $parsed['options'] = array_map(
                        static fn ($o) => self::cutTrailingArrangementLine(
                            self::cutAtNextNumberedQuestion(
                                self::cutAtNextStudyPassage(self::cutAtNextSectionHeading((string) $o))
                            )
                        ),
                        $parsed['options']
                    );
                    $parsed['options'] = self::repairDuplicateLetterCodeOptions($parsed['options']);
                }
                $out[] = $parsed;
            }
        }

        $special = array_merge($dataSufficiency, $statementsConclusions);
        if ($special !== []) {
            $out = array_merge($out, $special);
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
        $text = preg_replace('/(?<![0-9])(\d{1,3})\s+\.\s+(?=[A-Za-z(])/u', '$1) ', $text) ?? $text;

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
        $inline = preg_split('/\s+(?=\d{1,3}\)\s+\S)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
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
        $optionList = self::repairDuplicateLetterCodeOptions(array_slice(array_values($options), 0, 5));
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
        $studyStop = '\s+(?:Study|Read)\s+the\s+following\b';
        $nextQStop = '\s+\d{1,3}\)\s+\S';
        if (preg_match_all(
            // Skip answer-key prose: "answer (A), (B), (C)…" (comma / next letter immediately after).
            '/(?:^|[\n\s])\(([a-eA-E])\)\s+(?![,;])(?!\([a-eA-E]\))(.+?)(?=(?:[\n\s]\([a-eA-E]\)|'
            . $nextQStop . '|' . $studyStop . '|\s+Directions\s*\(|$))/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $label = $this->cleanOptionText($m[2]);
                if ($this->isJunkExtractedOption($label)) {
                    continue;
                }
                $options[strtoupper($m[1])] = $label;
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|[\n\s])([a-eA-E])\)\s*(.+?)(?=(?:[\n\s][a-eA-E]\)|'
            . $nextQStop . '|' . $studyStop . '|\s+Directions\s*\(|$))/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([A-Ea-e])[\.\):]\s*(.+?)(?=\n\s*[A-Ea-e][\.\):]|\n\s*(?:Answer|Correct|Explanation)|'
            . $nextQStop . '|' . $studyStop . '|\s+Directions\s*\(|\z)/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n|\s)\(([A-Ea-e])\)\s*(.+?)(?=(?:^|\n|\s)\([A-Ea-e]\)|\n\s*(?:Answer|Correct)|'
            . $nextQStop . '|' . $studyStop . '|\s+Directions\s*\(|\z)/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $options[strtoupper($m[1])] = $this->cleanOptionText($m[2]);
            }
        }
        if ($options === [] && preg_match_all(
            '/(?:^|\n)\s*([1-5])[\.\):]\s*(.+?)(?=\n\s*[1-5][\.\):]|\n\s*(?:Answer|Correct|Explanation)|'
            . $nextQStop . '|' . $studyStop . '|\s+Directions\s*\(|\z)/su',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            $map = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E'];
            foreach ($matches as $m) {
                $letter = $map[$m[1]] ?? '';
                if ($letter !== '') {
                    $options[$letter] = $this->cleanOptionText($m[2]);
                }
            }
        }
        if ($options === [] && preg_match_all(
            '/\b([A-Ea-e])\.\s+(.+?)(?=\s+[A-Ea-e]\.\s+|\s*(?:Answer|Ans)\s*[:\.]|'
            . $nextQStop . '|' . $studyStop . '|\z)/su',
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
     * @return array<int, string> question number => passage / arrangement prefix (no Directions label)
     */
    private function mapDirectionPassages(string $text): array
    {
        $map = [];
        if (preg_match_all(
            '/Directions\s*\((\d+)\s*[-–]\s*(\d+)\)\s*:([\s\S]*?)(?=\n\s*(?:\d{1,3}\)\s|\d{1,4}[\.\):]\s))/iu',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $m) {
                $start = (int) ($m[1][0] ?? 0);
                $end = (int) ($m[2][0] ?? 0);
                if ($start <= 0 || $end < $start || $end - $start > 30) {
                    continue;
                }
                $matchStart = (int) ($m[0][1] ?? 0);
                $afterPos = $matchStart + strlen((string) ($m[0][0] ?? ''));
                $body = $this->normalizePassageBody((string) ($m[3][0] ?? ''));
                $body = $this->ensureArrangementLineInPassage($body, $text, $matchStart, $afterPos);
                if ($body === '') {
                    continue;
                }
                for ($q = $start; $q <= $end; $q++) {
                    $map[$q] = $body;
                }
            }
        }

        // Unnumbered "Directions:" + arrangement/study text applying to the next question(s).
        if (preg_match_all(
            '/Directions\s*(?!\(\s*\d+\s*[-–]\s*\d+\s*\))\s*:\s*([\s\S]*?)(?=\n\s*(?:\d{1,3}\)\s|\d{1,4}[\.\):]\s))/iu',
            $text,
            $openMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($openMatches as $m) {
                $rawBody = (string) ($m[1][0] ?? '');
                if ($this->isSharedAnswerKeyDirections($rawBody)) {
                    continue;
                }
                $matchStart = (int) ($m[0][1] ?? 0);
                $afterPos = $matchStart + strlen((string) ($m[0][0] ?? ''));
                $body = $this->normalizePassageBody($rawBody);
                $body = $this->ensureArrangementLineInPassage($body, $text, $matchStart, $afterPos);
                if ($body === '' || mb_strlen($body) < 12) {
                    continue;
                }
                $maxSpan = $this->openDirectionsQuestionSpan($rawBody);
                $following = $this->listFollowingQuestionNumbers($text, $afterPos, $maxSpan);
                if ($following === []) {
                    continue;
                }
                foreach ($following as $q) {
                    if (!isset($map[$q])) {
                        $map[$q] = $body;
                    }
                }
            }
        }

        // "Study/Read the following information carefully…" without a Directions: label.
        if (preg_match_all(
            '/((?:Study|Read)\s+the\s+following\s+'
            . '(?:information|passage|arrangement|data|table|pie\s*chart|graph|bar\s*graph)\b'
            . '[\s\S]*?)(?=\n\s*(?:\d{1,3}\)\s|\d{1,4}[\.\):]\s)|\n\s*Directions\s*:|$)/iu',
            $text,
            $studyMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($studyMatches as $m) {
                $rawBody = (string) ($m[1][0] ?? '');
                $matchStart = (int) ($m[1][1] ?? 0);
                $afterPos = $matchStart + strlen($rawBody);
                $body = $this->normalizePassageBody($rawBody);
                if ($body === '' || mb_strlen($body) < 24) {
                    continue;
                }
                $maxSpan = preg_match('/\bquestions\b/iu', $rawBody) === 1 ? 8 : 1;
                $following = $this->listFollowingQuestionNumbers($text, $afterPos, $maxSpan);
                if ($following === []) {
                    continue;
                }
                foreach ($following as $q) {
                    if (!isset($map[$q])) {
                        $map[$q] = $body;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Singular "the following question" → only the next item; otherwise shared Directions
     * apply to the following items until the next Directions/section (e.g. Verbal Q46–48).
     */
    private function openDirectionsQuestionSpan(string $rawBody): int
    {
        // e.g. arrangement: "The following question is based on…" → only the next item.
        if (preg_match('/\bthe\s+following\s+question\b/iu', $rawBody) === 1) {
            return 1;
        }

        return 12;
    }

    /**
     * @return list<int>
     */
    private function listFollowingQuestionNumbers(string $text, int $from, int $max): array
    {
        if ($max < 1) {
            return [];
        }
        $slice = substr($text, $from);
        if (!is_string($slice) || $slice === '') {
            return [];
        }
        if (!preg_match_all('/(?<![0-9])(\d{1,3})\)\s+\S/u', $slice, $mm, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $nums = [];
        $lastAt = 0;
        foreach ($mm[1] as $hit) {
            $q = (int) ($hit[0] ?? 0);
            $at = (int) ($hit[1] ?? 0);
            if ($q <= 0) {
                continue;
            }
            $between = substr($slice, $lastAt, max(0, $at - $lastAt));
            if ($nums !== [] && is_string($between)) {
                if (preg_match('/Directions\s*:/iu', $between) === 1
                    || self::nextDirectionsRangeOffset($between) !== null
                    || self::nextSectionHeadingOffset($between) !== null
                    || self::nextStudyPassageOffset($between) !== null) {
                    break;
                }
            }
            $nums[] = $q;
            $lastAt = $at;
            if (count($nums) >= $max) {
                break;
            }
        }

        return $nums;
    }

    private function normalizePassageBody(string $body): string
    {
        $body = self::cutAtNextDirectionsRange($body);
        $body = self::cutAtNextSectionHeading($body);
        $body = JdTextExtractionService::repairArrangementGlyphs($body);
        $body = trim(preg_replace('/[^\S\n]+/u', ' ', $body) ?? $body);
        $body = preg_replace('/\n{2,}/u', "\n", $body) ?? $body;
        $body = self::stripDirectionsRangeLabel($body);
        $body = preg_replace('/^\s*Directions\s*:\s*/iu', '', $body) ?? $body;

        return trim($body);
    }

    /**
     * If Directions mention a letter/number/symbol arrangement but the token row was
     * split onto another line/page, pull that row into the passage.
     * Only search near this Directions block — never the whole document (avoids wrong Q).
     */
    private function ensureArrangementLineInPassage(string $body, string $fullText, int $dirStart, int $dirEnd): string
    {
        $needsLine = JdTextExtractionService::mentionsSymbolArrangement($body)
            || JdTextExtractionService::mentionsSymbolArrangement(
                substr($fullText, max(0, $dirStart - 80), max(0, $dirEnd - $dirStart) + 400)
            );
        if (!$needsLine) {
            return $body;
        }
        // PDF reading order sometimes places the bold row after the question — search nearby only.
        $from = max(0, $dirStart - 200);
        $window = substr($fullText, $from, 3200);
        $line = is_string($window) ? JdTextExtractionService::extractSymbolArrangementLine($window) : '';
        $line = $line !== '' ? JdTextExtractionService::repairArrangementGlyphs($line) : '';
        if ($line === '') {
            return $body;
        }
        if (JdTextExtractionService::textHasSymbolArrangementLine($body)) {
            $existing = JdTextExtractionService::extractSymbolArrangementLine($body);
            if ($existing !== '' && mb_strlen($line) > mb_strlen($existing) + 4) {
                return trim(str_replace($existing, $line, $body));
            }

            return $body;
        }
        if (!str_contains($body, $line)) {
            return trim($body . "\n" . $line);
        }

        return $body;
    }

    private function ensureArrangementLineOnPrompt(string $prompt, string $fullText, int $qNum = 0): string
    {
        $prompt = JdTextExtractionService::repairArrangementGlyphs($prompt);
        // Only letter/number/symbol arrangement — not generic "paragraph arrangement" wording.
        if (!JdTextExtractionService::mentionsSymbolArrangement($prompt)
            && !preg_match('/\babove\s+arrangement\b/iu', $prompt)) {
            return $prompt;
        }
        // Require an explicit symbol-arrangement cue before injecting a token row.
        $hasSymbolCue = preg_match(
            '/letter\s*[\/\s]*number\s*[\/\s]*symbol\s+arrangement|\babove\s+arrangement\b/iu',
            $prompt
        ) === 1;
        if (!$hasSymbolCue) {
            return $prompt;
        }

        $line = $this->findArrangementLineNearQuestion($fullText, $qNum);
        if ($line === '' && JdTextExtractionService::textHasSymbolArrangementLine($prompt)) {
            return $prompt;
        }
        if ($line === '') {
            return $prompt;
        }

        if (JdTextExtractionService::textHasSymbolArrangementLine($prompt)) {
            $existing = JdTextExtractionService::extractSymbolArrangementLine($prompt);
            if ($existing !== '' && mb_strlen($line) > mb_strlen($existing) + 4) {
                return trim(str_replace($existing, $line, $prompt));
            }

            return $prompt;
        }

        // Insert arrangement row before the question stem when prompt has directions + stem.
        if (preg_match('/^(.*(?:letter\s*[\/\s]*number\s*[\/\s]*symbol\s+)?arrangement[^\n]*[.?!]?)(\s+)(.+)$/isu', $prompt, $m)) {
            return trim($m[1] . "\n" . $line . "\n" . $m[3]);
        }

        return trim($line . "\n" . $prompt);
    }

    /** Arrangement token row near a question number — not a document-wide grab. */
    private function findArrangementLineNearQuestion(string $fullText, int $qNum): string
    {
        if ($qNum <= 0) {
            return '';
        }
        if (!preg_match('/(?<![0-9])' . preg_quote((string) $qNum, '/') . '\)\s+/u', $fullText, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $at = (int) ($m[0][1] ?? 0);
        $from = max(0, $at - 1000);
        $window = substr($fullText, $from, 2200);
        if (!is_string($window) || $window === '') {
            return '';
        }
        $line = JdTextExtractionService::extractSymbolArrangementLine($window);

        return $line !== '' ? JdTextExtractionService::repairArrangementGlyphs($line) : '';
    }

    private function isSharedAnswerKeyDirections(string $body): bool
    {
        return preg_match('/Mark\s+your\s+answer\s+as\s*\(\s*1\s*\)/iu', $body) === 1
            || preg_match('/statement\s*\(\s*i\s*\)/iu', $body) === 1
            || (
                preg_match('/\bconclusions?\b/iu', $body) === 1
                && preg_match('/\bA\)\s*If\s+(?:only|either|both|neither)/iu', $body) === 1
            );
    }

    private function questionFollowsSamePassage(string $text, int $passageEndPos, int $qNum): bool
    {
        $slice = substr($text, $passageEndPos);
        if (!is_string($slice)) {
            return false;
        }
        $n = preg_quote((string) $qNum, '/');
        if (!preg_match('/(?<![0-9])' . $n . '\)\s+/u', $slice, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $at = (int) ($m[0][1] ?? 0);
        $between = substr($slice, 0, $at);
        if (self::nextDirectionsRangeOffset($between) !== null) {
            return false;
        }
        if (preg_match('/Directions\s*:/iu', $between) === 1) {
            return false;
        }
        if (self::nextSectionHeadingOffset($between) !== null) {
            return false;
        }

        return true;
    }

    /** Remove leading "Directions (N–M):" / "Directions (N-M):" labels from created question text. */
    public static function stripDirectionsRangeLabel(string $text): string
    {
        $text = preg_replace('/^\s*Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/\n\s*Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:\s*/iu', "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Cut everything from the next ranged Directions block onward
     * (e.g. "Directions (20 - 23): …" belongs to a different question set).
     */
    public static function cutAtNextDirectionsRange(string $text): string
    {
        if (preg_match('/\s*Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return trim(substr($text, 0, (int) $m[0][1]));
        }

        return trim($text);
    }

    /**
     * Cut next topic/section headings (e.g. "Verbal Ability…", "Basic Computer Knowledge…")
     * so they are not glued onto the previous question and do not share Directions.
     */
    public static function cutAtNextSectionHeading(string $text): string
    {
        $at = self::nextSectionHeadingOffset($text);
        if ($at === null) {
            return trim($text);
        }

        return trim(substr($text, 0, $at));
    }

    /**
     * Map "Banking Awareness (Sample Questions)" style banners onto following question numbers.
     *
     * @return array<int, string> question number => section title
     */
    private function mapSectionTopicsForQuestions(string $text): array
    {
        $map = [];
        if (!preg_match_all(
            '/(?:^|[\n\r]\s*|\s)('
            . '(?:(?:Basic|General|Verbal|Quantitative|Computer|Digital|Reasoning|English'
            . '|Numerical|Logical|Data|Marketing|Banking|Financial|Insurance|Current)[^\n\r]{0,90}?'
            . '|[A-Z][a-z]+(?:\s+(?:and\s+)?[A-Z][a-z]+){0,8})'
            . '\s*\((?:Sample\s+)?Questions?\))/u',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $label = trim((string) ($m[1][0] ?? ''));
            $at = (int) ($m[1][1] ?? -1);
            if ($at < 0 || $label === '' || !self::looksLikeSectionHeading($label)) {
                continue;
            }
            $title = trim(preg_replace('/\s*\((?:Sample\s+)?Questions?\)\s*$/iu', '', $label) ?? $label);
            if ($title === '') {
                $title = $label;
            }
            $after = $at + strlen($label);
            $following = $this->listFollowingQuestionNumbers($text, $after, 40);
            foreach ($following as $q) {
                if (!isset($map[$q])) {
                    $map[$q] = $title;
                }
            }
        }

        return $map;
    }

    /**
     * Cut "Study/Read the following information carefully…" passages glued onto prior options.
     * OCR typo "answer he given questions" is accepted.
     * If the text itself starts with that opener, keep it (it is the passage, not a leak).
     */
    public static function cutAtNextStudyPassage(string $text): string
    {
        $at = self::nextStudyPassageOffset($text);
        if ($at === null || $at < 1) {
            return trim($text);
        }

        return trim(substr($text, 0, $at));
    }

    private static function nextStudyPassageOffset(string $text, int $from = 0): ?int
    {
        if (preg_match(
            '/(?:^|[\n\r]\s*|\s)'
            . '((?:Study|Read)\s+the\s+following\s+'
            . '(?:information|passage|arrangement|data|table|pie\s*chart|graph|bar\s*graph)\b'
            . '[^\n\r]{0,120}?(?:carefully\s+)?(?:and\s+answer\s+(?:the|he)\s+given\s+questions?[:.]?)?)/iu',
            $text,
            $m,
            PREG_OFFSET_CAPTURE,
            $from
        )) {
            return (int) ($m[1][1] ?? $m[0][1] ?? -1) >= 0
                ? (int) ($m[1][1] ?? $m[0][1])
                : null;
        }

        return null;
    }

    private static function nextDirectionsRangeOffset(string $text, int $from = 0): ?int
    {
        if (preg_match('/Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:/iu', $text, $m, PREG_OFFSET_CAPTURE, $from)) {
            return (int) $m[0][1];
        }

        return null;
    }

    private static function nextSectionHeadingOffset(string $text, int $from = 0): ?int
    {
        $patterns = [
            // Topic banners: "Banking Awareness (Sample Questions)", "Basic Computer Knowledge … (Questions)"
            // Prefer Title Case words (not ALLCAPS acronyms like SMTP) immediately before (Sample Questions).
            '/(?:^|[\n\r]\s*|\s)'
            . '((?:'
            . '(?:Basic|General|Verbal|Quantitative|Computer|Digital|Reasoning|English'
            . '|Numerical|Logical|Data|Marketing|Banking|Financial|Insurance|Current)'
            . '[^\n\r]{0,90}?'
            . '|'
            . '[A-Z][a-z]+(?:\s+(?:and\s+)?[A-Z][a-z]+){0,8}'
            . ')'
            . '\s*\((?:Sample\s+)?Questions?\))/u',
            // Named aptitude sections (allow a short lead-in like "Basic ")
            '/(?:^|[\n\r]\s*|\s{2,}|\s)'
            . '((?:Basic\s+|General\s+)?'
            . '(?:Verbal\s+Ability|Quantitative\s+Aptitude|Reasoning(?:\s+Ability)?'
            . '|English\s+Language|General\s+Awareness|Banking\s+Awareness'
            . '|Financial\s+Awareness|Current\s+Affairs'
            . '|Computer\s+Knowledge(?:\s+and\s+Digital\s+Banking)?'
            . '|Digital\s+Banking|Numerical\s+Ability|Logical\s+Reasoning'
            . '|Data\s+Interpretation|Marketing\s+Aptitude)'
            . '(?:\s*\([^)]*\))?)/iu',
            // Title-case banner line immediately before the next numbered question
            '/(?:^|[\n\r]\s*)'
            . '([A-Z][A-Za-z0-9][A-Za-z0-9 \\/&,\-]{6,90}(?:\([^)]{3,40}\))?)\s*'
            . '(?=[\n\r]+\s*\d{1,3}\))/u',
        ];
        $best = null;
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $from)) {
                continue;
            }
            $at = (int) ($m[1][1] ?? $m[0][1] ?? -1);
            $label = trim((string) ($m[1][0] ?? ''));
            if ($at < 0 || $label === '' || !self::looksLikeSectionHeading($label)) {
                continue;
            }
            if ($best === null || $at < $best) {
                $best = $at;
            }
        }

        return $best;
    }

    private static function looksLikeSectionHeading(string $label): bool
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        if ($label === '' || mb_strlen($label) < 8 || mb_strlen($label) > 140) {
            return false;
        }
        if (str_contains($label, '?') || preg_match('/^\d{1,3}\)/u', $label) === 1) {
            return false;
        }
        if (preg_match('/^(?:a|b|c|d|e)\)/iu', $label) === 1) {
            return false;
        }
        if (preg_match('/\b(?:no correction|none of these|cannot be determined)\b/iu', $label) === 1) {
            return false;
        }
        // Avoid cutting mid-sentence option text that happens to contain "computer knowledge".
        if (preg_match('/\b(?:is|are|was|were|which|what|how|when|where|who|the\s+following)\b/iu', $label) === 1
            && preg_match('/\((?:Sample\s+)?Questions?\)/iu', $label) !== 1) {
            return false;
        }
        if (preg_match('/\((?:Sample\s+)?Questions?\)/iu', $label) === 1) {
            return true;
        }
        if (preg_match(
            '/\b(?:Ability|Aptitude|Reasoning|Awareness|Knowledge|Banking|Interpretation|Language)\b/iu',
            $label
        ) === 1) {
            return true;
        }
        // Title-ish: at least 3 capitalized words
        if (preg_match_all('/\b[A-Z][A-Za-z0-9]+\b/u', $label, $wm) && count($wm[0] ?? []) >= 3) {
            return true;
        }

        return false;
    }

    /** Strip leading "7)" / "Q7." — UI already shows Q7. */
    public static function stripLeadingQuestionNumber(string $text): string
    {
        $text = preg_replace('/^\s*(?:Q(?:uestion)?\s*)?\d{1,4}[\.\):]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d{1,3}\)\s*/u', '', $text) ?? $text;

        return trim($text);
    }

    /** Remove a mid-prompt "7)" before the actual question when Q number is known. */
    public static function stripEmbeddedQuestionNumber(string $prompt, int $qNum): string
    {
        $prompt = trim($prompt);
        if ($prompt === '' || $qNum <= 0) {
            return self::stripLeadingQuestionNumber($prompt);
        }
        $n = preg_quote((string) $qNum, '/');
        // "…P. 7) Who sits" / newline "7) Who sits"
        $prompt = preg_replace('/(^|[\n\r]\s*|\.\s+)(?:Q(?:uestion)?\s*)?' . $n . '[\.\):]\s+/iu', '$1', $prompt) ?? $prompt;
        $prompt = preg_replace('/\s+' . $n . '\)\s+/u', ' ', $prompt) ?? $prompt;

        return trim(self::stripLeadingQuestionNumber($prompt));
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
        $block = self::cutAtNextDirectionsRange($block);
        $block = self::cutAtNextSectionHeading($block);
        $block = self::cutAtNextStudyPassage($block);
        $block = self::cutAtNextNumberedQuestion($block);
        // If this block is a normal MCQ (has options) and ends with an arrangement row, drop the row.
        if (preg_match('/[A-Ea-e][\.\)]|[a-eA-E]\)/u', $block) === 1
            && !JdTextExtractionService::mentionsSymbolArrangement($block)) {
            $block = self::cutTrailingArrangementLine($block);
        }
        if (preg_match('/\([a-eA-E]\)|[a-eA-E]\)|[A-Ea-e][\.\):]/u', $block)
            && preg_match('/\s+Directions\s*:/iu', $block, $dm, PREG_OFFSET_CAPTURE)) {
            $block = trim(substr($block, 0, (int) $dm[0][1]));
        }
        $stripped = preg_replace('/\s+Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:.*$/isu', '', $block);
        $stripped = preg_replace('/\s+Directions\s*:[\s\S]*$/iu', '', $stripped ?? $block);
        $stripped = self::cutAtNextStudyPassage($stripped ?? $block);
        $stripped = self::cutAtNextNumberedQuestion($stripped);

        return trim($stripped ?? $block);
    }

    /**
     * Remove a trailing letter/number/symbol arrangement token row glued onto option/prompt text.
     * e.g. "None of these T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K"
     */
    public static function cutTrailingArrangementLine(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Whole last line is an arrangement row.
        $lines = preg_split('/\n/u', $text) ?: [];
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if (count($lines) >= 2) {
            $last = trim((string) end($lines));
            if (JdTextExtractionService::isSymbolArrangementLine($last)
                || JdTextExtractionService::isArrangementLineContinuation($last)) {
                array_pop($lines);
                // Also drop a short wrapped continuation above if present.
                if ($lines !== []) {
                    $prev = trim((string) end($lines));
                    if (JdTextExtractionService::isSymbolArrangementLine($prev)) {
                        array_pop($lines);
                    }
                }

                return trim(implode("\n", $lines));
            }
        }

        // Same-line glue: prose + arrangement tokens at end.
        $tokenRun = '[\p{L}\p{N}@#%©®™$₹*&+\-=□■▪▫âÃÂ●]';
        if (preg_match(
            '/^(.*?\S)\s+((?:' . $tokenRun . '\s+){7,}' . $tokenRun . ')\s*$/us',
            $text,
            $m
        )) {
            $tail = trim(preg_replace('/\s+/u', ' ', (string) $m[2]) ?? (string) $m[2]);
            if (JdTextExtractionService::isSymbolArrangementLine($tail)) {
                return trim((string) $m[1]);
            }
        }

        return $text;
    }

    /**
     * When the next "69) …" question is glued onto option E / a prior block, cut it off.
     * Does not cut a leading question number (the block's own stem).
     */
    public static function cutAtNextNumberedQuestion(string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        // Skip the block's own leading "69)"
        $offset = 0;
        if (preg_match('/^\s*(?:Q(?:uestion)?\s*)?\d{1,3}\)\s+/iu', $text, $lead)) {
            $offset = strlen($lead[0]);
        }
        if (preg_match('/\s+\d{1,3}\)\s+\S/u', $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $at = (int) ($m[0][1] ?? -1);
            if ($at > 0) {
                return trim(substr($text, 0, $at));
            }
        }

        return trim($text);
    }

    /**
     * Data sufficiency sets: shared Directions with (1)–(5) answers and per-question (i)/(ii) statements.
     *
     * @return list<array<string, mixed>>
     */
    private function parseDataSufficiencySections(string $text): array
    {
        if (!preg_match('/Directions\s*:[\s\S]*?(?:statement\s*\(\s*i\s*\)|Mark\s+your\s+answer\s+as\s*\(\s*1\s*\)|data\s+in\s+statement)/iu', $text)) {
            return [];
        }

        $out = [];
        $offsets = [];
        if (preg_match_all('/Directions\s*(?!\(\s*\d+\s*-)\s*:/iu', $text, $starts, PREG_OFFSET_CAPTURE)) {
            foreach ($starts[0] as $st) {
                $offsets[] = (int) ($st[1] ?? 0);
            }
        }
        if ($offsets === []) {
            return [];
        }

        foreach ($offsets as $oi => $startPos) {
            $nextDir = $offsets[$oi + 1] ?? strlen($text);
            $section = substr($text, $startPos, $nextDir - $startPos);
            if (!is_string($section) || trim($section) === '') {
                continue;
            }
            if (!preg_match('/Directions\s*(?!\(\s*\d+\s*-)\s*:\s*(.*)$/isu', $section, $secMatch)) {
                continue;
            }
            $afterColon = (string) ($secMatch[1] ?? '');
            if (!preg_match('/(?:statement\s*\(\s*i\s*\)|Mark\s+your\s+answer|\(\s*1\s*\)\s*if)/iu', $afterColon)) {
                continue;
            }
            $qStart = $this->findDataSufficiencyQuestionsStart($afterColon);
            if ($qStart === null) {
                continue;
            }
            $body = trim(substr($afterColon, 0, $qStart));
            if ($body === '') {
                continue;
            }
            $options = $this->extractDataSufficiencyOptions($body);
            if (count($options) < 2) {
                continue;
            }
            $tail = self::cutAtNextDirectionsRange(trim(substr($afterColon, $qStart)));
            $directionsPrefix = 'Directions: ' . trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

            $chunks = $this->splitDataSufficiencyQuestionChunks($tail);
            if ($chunks === []) {
                continue;
            }

            foreach ($chunks as $chunk) {
                $chunk = self::cutAtNextDirectionsRange(trim($chunk));
                if ($chunk === '' || !preg_match('/^\s*(\d{1,3})[\.\)]\s*/u', $chunk, $qNumMatch)) {
                    continue;
                }
                $qNum = (int) $qNumMatch[1];
                if (!$this->chunkHasDataSufficiencyStatements($chunk)) {
                    continue;
                }

                $promptBody = preg_replace('/^\s*\d{1,3}[\.\)]\s*/u', '', $chunk) ?? $chunk;
                $promptBody = self::cutAtNextDirectionsRange(trim($promptBody));
                if ($promptBody === '') {
                    continue;
                }

                $prompt = $promptBody;
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

    private function chunkHasDataSufficiencyStatements(string $chunk): bool
    {
        $hasI = preg_match('/(?:^|[\n\r]\s*|\s)\(?\s*i\s*\)?\s*[\.\):]\s+\S/iu', $chunk) === 1;
        $hasIi = preg_match('/(?:^|[\n\r]\s*|\s)\(?\s*ii\s*\)?\s*[\.\):]\s+\S/iu', $chunk) === 1;

        return $hasI && $hasIi;
    }

    /**
     * Syllogism-style sets: shared Directions with A–E conclusion rules and per-item Statements + Conclusions I/II.
     *
     * @return list<array<string, mixed>>
     */
    private function parseStatementsConclusionsSections(string $text): array
    {
        if (!preg_match('/Directions\s*:[\s\S]*?\bconclusions?\b/iu', $text)) {
            return [];
        }

        $out = [];
        $offsets = [];
        if (preg_match_all('/Directions\s*(?!\(\s*\d+\s*-)\s*:/iu', $text, $starts, PREG_OFFSET_CAPTURE)) {
            foreach ($starts[0] as $st) {
                $offsets[] = (int) ($st[1] ?? 0);
            }
        }
        if ($offsets === []) {
            return [];
        }

        foreach ($offsets as $oi => $startPos) {
            $nextDir = $offsets[$oi + 1] ?? strlen($text);
            $section = substr($text, $startPos, $nextDir - $startPos);
            if (!is_string($section) || trim($section) === '') {
                continue;
            }
            if (!preg_match('/Directions\s*(?!\(\s*\d+\s*-)\s*:\s*(.*)$/isu', $section, $secMatch)) {
                continue;
            }
            $afterColon = (string) ($secMatch[1] ?? '');
            if (!$this->isStatementsConclusionsDirections($afterColon)) {
                continue;
            }
            $qStart = $this->findStatementsConclusionsQuestionsStart($afterColon);
            if ($qStart === null) {
                continue;
            }
            $body = trim(substr($afterColon, 0, $qStart));
            if ($body === '') {
                continue;
            }
            $options = $this->extractSharedConclusionOptions($body);
            if (count($options) < 2) {
                continue;
            }
            $tail = self::cutAtNextSectionHeading(
                self::cutAtNextDirectionsRange(trim(substr($afterColon, $qStart)))
            );
            $directionsPrefix = 'Directions: ' . $this->normalizeStatementsConclusionsDirectionsBody($body);

            $chunks = $this->splitStatementsConclusionsQuestionChunks($tail);
            if ($chunks === []) {
                continue;
            }

            foreach ($chunks as $chunk) {
                $chunk = self::cutAtNextSectionHeading(self::cutAtNextDirectionsRange(trim($chunk)));
                if ($chunk === '' || !preg_match('/^\s*(\d{1,3})\)\s*/u', $chunk, $qNumMatch)) {
                    continue;
                }
                $qNum = (int) $qNumMatch[1];
                if (!$this->chunkHasStatementsConclusions($chunk)) {
                    continue;
                }

                $promptBody = preg_replace('/^\s*\d{1,3}\)\s*/u', '', $chunk) ?? $chunk;
                $promptBody = self::cutAtNextSectionHeading(self::cutAtNextDirectionsRange(trim($promptBody)));
                if ($promptBody === '') {
                    continue;
                }

                $prompt = self::formatStatementsConclusionsPrompt($promptBody);
                $optionList = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                    if (isset($options[$letter])) {
                        $optionList[] = $options[$letter];
                    }
                }
                while (count($optionList) < 4) {
                    $optionList[] = '—';
                }
                $optionList = array_slice($optionList, 0, 5);

                $out[] = [
                    'prompt' => $prompt,
                    'options' => $optionList,
                    'correctIndex' => 0,
                    'explanation' => '',
                    'category' => 'Logical Reasoning',
                    'difficulty' => 'Medium',
                    'source' => 'MANUAL_UPLOAD',
                    'questionNumber' => $qNum,
                    'section' => 'Statements & Conclusions',
                    'questionType' => 'STATEMENTS_CONCLUSIONS',
                    'directionsBlock' => $directionsPrefix,
                    'answerKnown' => false,
                ];
            }
        }

        return $out;
    }

    private function isStatementsConclusionsDirections(string $body): bool
    {
        if (preg_match('/statement\s*\(\s*i\s*\)/iu', $body)
            && preg_match('/Mark\s+your\s+answer\s+as\s*\(\s*1\s*\)/iu', $body)) {
            return false;
        }
        if (!preg_match('/\bconclusions?\b/iu', $body)) {
            return false;
        }

        return preg_match('/\bA\)\s*If\s+(?:only|either|both|neither)/iu', $body) === 1
            || preg_match('/\banswer\s*\(\s*[A-E]\s*\)/iu', $body) === 1;
    }

    private function chunkHasStatementsConclusions(string $chunk): bool
    {
        if (preg_match('/\bStatements\b/iu', $chunk) !== 1) {
            return false;
        }
        if (preg_match('/\bConclusions\b/iu', $chunk) !== 1) {
            return false;
        }

        return preg_match('/(?:^|[\n\r]\s*|\s)I\)\s+\S/u', $chunk) === 1
            && preg_match('/(?:^|[\n\r]\s*|\s)II\)\s+\S/u', $chunk) === 1;
    }

    /**
     * Keep Statements / Conclusions / I) / II) on separate lines (never one flat paragraph).
     */
    public static function formatStatementsConclusionsPrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }
        $prompt = preg_replace('/^\s*\d{1,3}\)\s*/u', '', $prompt) ?? $prompt;
        $prompt = trim(preg_replace('/[^\S\n]+/u', ' ', $prompt) ?? $prompt);
        $prompt = preg_replace('/\s*\n\s*/u', "\n", $prompt) ?? $prompt;

        // Flatten then re-insert structure so OCR one-liners become multi-line.
        $flat = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        if (preg_match(
            '/^Statements\s+(.+?)\s+Conclusions\s+(.+)$/iu',
            $flat,
            $m
        )) {
            $stmts = self::splitSyllogismStatementLines(trim((string) $m[1]));
            $conclusions = self::formatSyllogismConclusionLines(trim((string) $m[2]));
            return "Statements\n{$stmts}\nConclusions\n{$conclusions}";
        }

        $prompt = preg_replace('/\bStatements\b\s*/iu', "Statements\n", $prompt) ?? $prompt;
        $prompt = preg_replace('/\s*\bConclusions\b\s*/iu', "\nConclusions\n", $prompt) ?? $prompt;
        // Match II) before I) so "II)" is not split into "I" + "I)".
        $prompt = preg_replace('/\s+(II\))\s+/u', "\n$1 ", $prompt) ?? $prompt;
        $prompt = preg_replace('/(?<!I)(I\))\s+/u', "\n$1 ", $prompt) ?? $prompt;
        $prompt = trim(preg_replace("/\n{3,}/u", "\n\n", $prompt) ?? $prompt);

        return $prompt;
    }

    private static function splitSyllogismStatementLines(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace(
            '/\s+(?=(?:All|Some|No|Only|Every|None|Most|A\s+few)\b)/u',
            "\n",
            $text
        ) ?? $text;

        return trim($text);
    }

    private static function formatSyllogismConclusionLines(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^I\)\s*(.+?)\s*II\)\s*(.+)$/iu', $text, $m)) {
            return 'I) ' . trim((string) $m[1]) . "\nII) " . trim((string) $m[2]);
        }
        // Match II) before I) so "II)" is not split into "I" + "I)".
        $text = preg_replace('/\s*(II\))\s*/u', "\n$1 ", $text) ?? $text;
        $text = preg_replace('/(?<!I)(I\))\s*/u', "\n$1 ", $text) ?? $text;

        return trim($text);
    }

    private function findStatementsConclusionsQuestionsStart(string $text): ?int
    {
        if (preg_match('/(?<![0-9])(\d{1,3})\)\s+Statements\b/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return (int) ($m[0][1] ?? 0);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function splitStatementsConclusionsQuestionChunks(string $tail): array
    {
        $chunks = [];
        $pos = 0;
        $len = strlen($tail);
        while ($pos < $len && preg_match('/(?<![0-9])(\d{1,3})\)\s+Statements\b/iu', $tail, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $at = (int) ($m[0][1] ?? 0);
            $end = $len;
            $dirAt = self::nextDirectionsRangeOffset($tail, $at + 1);
            if ($dirAt !== null && $dirAt < $end) {
                $end = $dirAt;
            }
            $secAt = self::nextSectionHeadingOffset($tail, $at + 1);
            if ($secAt !== null && $secAt < $end) {
                $end = $secAt;
            }
            $scan = $at + 1;
            while ($scan < $len && preg_match('/(?<![0-9])(\d{1,3})\)\s+Statements\b/iu', $tail, $m2, PREG_OFFSET_CAPTURE, $scan)) {
                $at2 = (int) ($m2[0][1] ?? 0);
                if ($at2 < $end) {
                    $end = $at2;
                }
                break;
            }
            $chunk = self::cutAtNextSectionHeading(
                self::cutAtNextDirectionsRange(trim(substr($tail, $at, $end - $at)))
            );
            if ($chunk !== '' && $this->chunkHasStatementsConclusions($chunk)) {
                $chunks[] = $chunk;
            }
            $pos = $end > $at ? $end : $at + 1;
        }

        return $chunks;
    }

    /**
     * Shared A–E labels for syllogism / statements-conclusions sets.
     * Ignore prose like "answer (A), (B), (C), (D) and (E) is correct answer…".
     *
     * @return array<string, string>
     */
    private function extractSharedConclusionOptions(string $directionsBody): array
    {
        $defaults = [
            'A' => 'If only conclusion I follows',
            'B' => 'If only conclusion II follows',
            'C' => 'If either conclusion I or conclusion II follows',
            'D' => 'If neither conclusion I nor conclusion II follows',
            'E' => 'If both conclusions I and II follow',
        ];

        $out = [];
        // Prefer explicit conclusion-rule markers: A) If only… / (A) If neither…
        if (preg_match_all(
            '/(?:^|[\n\s])(?:\(([A-Ea-e])\)|([A-Ea-e])\))\s*'
            . '(If\s+(?:only|either|neither|both)\b[^.\n\r]*?(?:follows?|follow))/iu',
            $directionsBody,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $letter = strtoupper(trim((string) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''))));
                $label = $this->cleanOptionText((string) ($m[3] ?? ''));
                if ($letter !== '' && $this->isPlausibleConclusionOption($label)) {
                    $out[$letter] = $label;
                }
            }
        }

        if (count($out) < 4) {
            $raw = $this->extractOptionsFromBlock($directionsBody);
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                if (isset($out[$letter])) {
                    continue;
                }
                $label = $this->cleanOptionText((string) ($raw[$letter] ?? ''));
                if ($this->isPlausibleConclusionOption($label)) {
                    $out[$letter] = $label;
                }
            }
        }

        if (count($out) >= 4) {
            $ordered = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                if (isset($out[$letter])) {
                    $ordered[$letter] = $out[$letter];
                } elseif (isset($defaults[$letter])) {
                    $ordered[$letter] = $defaults[$letter];
                }
            }

            return $ordered;
        }

        return $defaults;
    }

    private function isPlausibleConclusionOption(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) < 8) {
            return false;
        }
        if (preg_match('/^(?:,+|and|or)\s*$/iu', $t) === 1) {
            return false;
        }
        if (preg_match('/\bis correct answer\b|\bindicate it on the answer\b/iu', $t) === 1) {
            return false;
        }

        return preg_match('/^If\s+(?:only|either|neither|both)\b/iu', $t) === 1
            || preg_match('/\bconclusions?\b/iu', $t) === 1;
    }

    /** Directions prose only — A–E conclusion rules belong in options, not the header. */
    private function normalizeStatementsConclusionsDirectionsBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        // Cut from the first A–E conclusion-rule marker (shown as MCQ options instead).
        if (preg_match(
            '/(?:^|[\n\s])(?:\([A-Ea-e]\)|[A-Ea-e]\))\s*If\s+(?:only|either|neither|both)\b/iu',
            $body,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $body = trim(substr($body, 0, (int) ($m[0][1] ?? 0)));
        }
        // Soften "answer (A), (B), (C), (D) and (E) is correct…" into a short instruction.
        $body = preg_replace(
            '/\b(?:Then\s+)?decide which of the answer\s*\(\s*[A-E]\s*\)(?:\s*,\s*\(\s*[A-E]\s*\)){3}\s*and\s*\(\s*[A-E]\s*\)\s*'
            . 'is correct answer and indicate it on the\s*answer sheet\.?/iu',
            'Choose the correct option (A–E).',
            $body
        ) ?? $body;
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        return $body;
    }

    /**
     * First real question line — skip "(1) if the data…" answer-key markers inside Directions.
     */
    private function findDataSufficiencyQuestionsStart(string $text): ?int
    {
        $pos = 0;
        $len = strlen($text);
        while ($pos < $len && preg_match('/(?<![0-9])(\d{1,3})[\.\)]\s+[A-Za-z(]/u', $text, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $at = (int) ($m[0][1] ?? 0);
            if ($this->isDataSufficiencyAnswerKeyMarker($text, $at, (string) ($m[0][0] ?? ''))) {
                $pos = $at + 1;
                continue;
            }
            $peek = substr($text, $at, min(2400, $len - $at));
            if (!$this->chunkHasDataSufficiencyStatements($peek)) {
                $pos = $at + 1;
                continue;
            }

            return $at;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function splitDataSufficiencyQuestionChunks(string $tail): array
    {
        $chunks = [];
        $pos = 0;
        $len = strlen($tail);
        while ($pos < $len && preg_match('/(?<![0-9])(\d{1,3})[\.\)]\s+[A-Za-z(]/u', $tail, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $at = (int) ($m[0][1] ?? 0);
            $marker = (string) ($m[0][0] ?? '');
            if ($this->isDataSufficiencyAnswerKeyMarker($tail, $at, $marker)) {
                $pos = $at + 1;
                continue;
            }
            $end = $len;
            $dirAt = self::nextDirectionsRangeOffset($tail, $at + 1);
            if ($dirAt !== null && $dirAt < $end) {
                $end = $dirAt;
            }
            $scan = $at + 1;
            while ($scan < $len && preg_match('/(?<![0-9])(\d{1,3})[\.\)]\s+[A-Za-z(]/u', $tail, $m2, PREG_OFFSET_CAPTURE, $scan)) {
                $at2 = (int) ($m2[0][1] ?? 0);
                if ($this->isDataSufficiencyAnswerKeyMarker($tail, $at2, (string) ($m2[0][0] ?? ''))) {
                    $scan = $at2 + 1;
                    continue;
                }
                if ($at2 < $end) {
                    $end = $at2;
                }
                break;
            }
            $chunk = self::cutAtNextDirectionsRange(trim(substr($tail, $at, $end - $at)));
            if ($chunk !== '' && $this->chunkHasDataSufficiencyStatements($chunk)) {
                $chunks[] = $chunk;
            }
            $pos = $end > $at ? $end : $at + 1;
        }

        return $chunks;
    }

    private function isDataSufficiencyAnswerKeyMarker(string $text, int $at, string $marker): bool
    {
        if ($at > 0 && $text[$at - 1] === '(') {
            return true;
        }
        $after = substr($text, $at + strlen($marker));

        return preg_match('/^if\s+(?:the\s+data|either|data)/iu', $after) === 1
            || preg_match('/^if\s+statement/iu', $after) === 1;
    }

    /**
     * @return array<int, string> 1-based option index => label
     */
    private function extractDataSufficiencyOptions(string $directionsBody): array
    {
        $opts = [];
        if (preg_match_all(
            '/Mark\s+your\s+answer\s+as\s*\(\s*([1-5])\s*\)\s*(.+?)(?=Mark\s+your\s+answer\s+as\s*\(\s*[1-5]\s*\)|$)/isu',
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
        if ($opts === [] && preg_match_all(
            '/\(\s*([1-5])\s*\)\s*(.+?)(?=\(\s*[1-5]\s*\)|$)/su',
            $directionsBody,
            $hits,
            PREG_SET_ORDER
        )) {
            foreach ($hits as $hit) {
                $label = trim(preg_replace('/\s+/u', ' ', $hit[2]) ?? $hit[2]);
                if ($label !== '' && mb_strlen($label) > 12) {
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
        $text = self::cutAtNextDirectionsRange($text);
        $text = self::cutAtNextSectionHeading($text);
        $text = self::cutAtNextStudyPassage($text);
        $text = self::cutAtNextNumberedQuestion($text);
        $text = self::cutTrailingArrangementLine($text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/\s+Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:.*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+\d{1,3}\)\s+Study\s+the\s+following.*$/iu', '', $text) ?? $text;
        $text = preg_replace(
            '/\s+(?:Study|Read)\s+the\s+following\s+'
            . '(?:information|passage|arrangement|data|table|pie\s*chart|graph|bar\s*graph)\b.*$/iu',
            '',
            $text
        ) ?? $text;
        $text = preg_replace('/\s+\d{1,3}\)\s+\S.*$/u', '', $text) ?? $text;
        $text = self::cutTrailingArrangementLine($text);

        return trim($text);
    }

    /** Prose fragments mistaken for options, e.g. from "answer (A), (B), …". */
    private function isJunkExtractedOption(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) < 2) {
            return true;
        }
        if (preg_match('/^(?:,+|and|or)\s*$/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^\s*is correct answer\b/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/\bindicate it on the answer sheet\b/iu', $t) === 1) {
            return true;
        }

        return false;
    }

    /**
     * OCR/PDF text often collapses near-identical coding options (YITSNED vs YTISNED).
     * If two letter-code options are identical and exactly one confused adjacent swap
     * is missing from the set, restore that distinct option on the later duplicate.
     *
     * @param list<string> $options
     * @return list<string>
     */
    public static function repairDuplicateLetterCodeOptions(array $options): array
    {
        $opts = array_values(array_map(static fn ($o) => trim((string) $o), $options));
        if (count($opts) < 3) {
            return $opts;
        }

        $codeIdx = [];
        foreach ($opts as $i => $o) {
            if (preg_match('/^[A-Za-z]{4,12}$/u', $o) === 1) {
                $codeIdx[] = $i;
            }
        }
        if (count($codeIdx) < 3) {
            return $opts;
        }

        $len = strlen($opts[$codeIdx[0]]);
        foreach ($codeIdx as $i) {
            if (strlen($opts[$i]) !== $len) {
                return $opts;
            }
        }

        for ($a = 0; $a < count($codeIdx); $a++) {
            for ($b = $a + 1; $b < count($codeIdx); $b++) {
                $i = $codeIdx[$a];
                $j = $codeIdx[$b];
                if (strcasecmp($opts[$i], $opts[$j]) !== 0) {
                    continue;
                }
                $dup = $opts[$i];
                $existing = [];
                foreach ($codeIdx as $ci) {
                    $existing[strtoupper($opts[$ci])] = true;
                }
                $candidates = [];
                $chars = preg_split('//u', $dup, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                for ($p = 0, $plen = count($chars) - 1; $p < $plen; $p++) {
                    if (!self::isConfusedLetterPair($chars[$p], $chars[$p + 1])) {
                        continue;
                    }
                    $swap = $chars;
                    $tmp = $swap[$p];
                    $swap[$p] = $swap[$p + 1];
                    $swap[$p + 1] = $tmp;
                    $cand = implode('', $swap);
                    if (!isset($existing[strtoupper($cand)])) {
                        $candidates[$cand] = true;
                    }
                }
                if (count($candidates) === 1) {
                    $fixed = (string) array_key_first($candidates);
                    // Put the restored distinct spelling on the earlier option (B before C).
                    $opts[$i] = preg_match('/^[A-Z]+$/u', $dup) === 1 ? strtoupper($fixed) : $fixed;
                }

                return $opts;
            }
        }

        return $opts;
    }

    private static function isConfusedLetterPair(string $a, string $b): bool
    {
        $pair = strtoupper($a . $b);

        return in_array($pair, ['IT', 'TI', 'IL', 'LI', 'IJ', 'JI', 'DO', 'OD', 'RN', 'NR', 'CG', 'GC', 'UO', 'OU', 'VW', 'WV'], true);
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
