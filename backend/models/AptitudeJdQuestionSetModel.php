<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * JD-based aptitude question sets — stored separately from the general question bank.
 */
class AptitudeJdQuestionSetModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::APTITUDE_JD_QUESTION_SETS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `aptitude_jd_question_sets` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(int $limit = 200, bool $forStudent = false): array
    {
        $rows = $this->findAll([], $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->summaryView($row, $forStudent);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function listStudentCompanyBlocks(int $limit = 200): array
    {
        $sets = $this->listSummaries($limit, true);
        /** @var array<string, array<string, mixed>> $blocks */
        $blocks = [];
        foreach ($sets as $set) {
            $companyId = trim((string) ($set['companyId'] ?? ''));
            if ($companyId === '') {
                continue;
            }
            $companyName = trim((string) ($set['companyName'] ?? ''));
            if ($companyName === '') {
                $companyName = 'Company';
            }
            if (!isset($blocks[$companyId])) {
                $blocks[$companyId] = [
                    'companyId' => $companyId,
                    'companyName' => $companyName,
                    'setCount' => 0,
                    'questionCount' => 0,
                    'sets' => [],
                ];
            }
            $blocks[$companyId]['setCount']++;
            $blocks[$companyId]['questionCount'] += (int) ($set['questionCount'] ?? 0);
            $blocks[$companyId]['sets'][] = $set;
        }

        $out = array_values($blocks);
        usort($out, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['companyName'] ?? ''),
            (string) ($b['companyName'] ?? '')
        ));

        return $out;
    }

    public function listCompanyBlocks(int $limit = 200): array
    {
        $sets = $this->listSummaries($limit);
        /** @var array<string, array<string, mixed>> $blocks */
        $blocks = [];
        foreach ($sets as $set) {
            $companyId = trim((string) ($set['companyId'] ?? ''));
            $companyName = trim((string) ($set['companyName'] ?? ''));
            $key = $companyId !== '' ? $companyId : '_unassigned';
            if ($companyName === '') {
                $companyName = $companyId !== '' ? 'Company' : 'Unassigned';
            }
            if (!isset($blocks[$key])) {
                $blocks[$key] = [
                    'companyId' => $companyId,
                    'companyName' => $companyName,
                    'setCount' => 0,
                    'questionCount' => 0,
                    'sets' => [],
                ];
            }
            $blocks[$key]['setCount']++;
            $blocks[$key]['questionCount'] += (int) ($set['questionCount'] ?? 0);
            $blocks[$key]['sets'][] = $set;
        }

        $out = array_values($blocks);
        usort($out, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['companyName'] ?? ''),
            (string) ($b['companyName'] ?? '')
        ));

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function createSet(
        string $jdTitle,
        array $questions,
        ?string $createdBy = null,
        ?string $companyId = null,
        ?string $companyName = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null
    ): array {
        $jdTitle = trim($jdTitle);
        if ($jdTitle === '') {
            throw new \InvalidArgumentException('JD title is required.');
        }

        $companyId = trim((string) ($companyId ?? ''));
        if ($companyId === '' || !Security::isValidId($companyId)) {
            throw new \InvalidArgumentException('Company is required.');
        }
        $companyName = trim((string) ($companyName ?? ''));
        if ($companyName === '') {
            $company = (new CompanyModel())->findById($companyId);
            if ($company === null) {
                throw new \InvalidArgumentException('Selected company was not found.');
            }
            $companyName = trim((string) ($company['companyName'] ?? ''));
        }
        if ($companyName === '') {
            throw new \InvalidArgumentException('Selected company was not found.');
        }

        $normalized = [];
        foreach (array_values($questions) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, 'General Aptitude', $i);
            if ($norm === null) {
                continue;
            }
            $topic = trim((string) ($q['topic'] ?? ''));
            if ($topic !== '') {
                $norm['topic'] = $topic;
            }
            $norm['id'] = 'jdq-' . ($i + 1) . '-' . bin2hex(random_bytes(4));
            $norm['source'] = 'AI_JD';
            $normalized[] = $norm;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('No valid questions to save.');
        }

        $doc = [
            'jdTitle' => $jdTitle,
            'companyId' => $companyId,
            'companyName' => $companyName,
            'questionCount' => count($normalized),
            'questions' => $normalized,
            'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
            'manualSource' => 'ai',
            'companyBankKind' => 'question',
            'showInCompanyBank' => true,
        ];
        $filename = trim((string) ($jdFilename ?? ''));
        if ($filename !== '') {
            $doc['jdFilename'] = $filename;
        }
        $fileUri = trim((string) ($jdFile ?? ''));
        if ($fileUri !== '') {
            $doc['jdFile'] = $fileUri;
        }
        $fileUrl = trim((string) ($jdFileUrl ?? ''));
        if ($fileUrl !== '') {
            $doc['jdFileUrl'] = $fileUrl;
        }
        $mime = trim((string) ($jdMimeType ?? ''));
        if ($mime !== '') {
            $doc['jdMimeType'] = $mime;
        }

        $id = $this->insert($doc);
        $saved = $this->findById($id);

        return $saved !== null ? $this->detailView($saved) : ['id' => $id, 'jdTitle' => $jdTitle];
    }

    /**
     * Save a company-wise manual upload (document and/or parsed MCQs).
     *
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function createManualSet(
        string $jdTitle,
        array $questions,
        ?string $createdBy = null,
        ?string $companyId = null,
        ?string $companyName = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null,
        ?string $manualText = null,
        string $manualSaveTarget = 'bank',
        ?string $manualParseMethod = null,
        ?array $importMeta = null
    ): array {
        $jdTitle = trim($jdTitle);
        if ($jdTitle === '') {
            throw new \InvalidArgumentException('Title is required.');
        }

        $companyId = trim((string) ($companyId ?? ''));
        if ($companyId === '' || !Security::isValidId($companyId)) {
            throw new \InvalidArgumentException('Company is required.');
        }
        $companyName = trim((string) ($companyName ?? ''));
        if ($companyName === '') {
            $company = (new CompanyModel())->findById($companyId);
            if ($company === null) {
                throw new \InvalidArgumentException('Selected company was not found.');
            }
            $companyName = trim((string) ($company['companyName'] ?? ''));
        }
        if ($companyName === '') {
            throw new \InvalidArgumentException('Selected company was not found.');
        }

        $fileUri = trim((string) ($jdFile ?? ''));
        $manualTextTrim = trim((string) ($manualText ?? ''));

        $normalized = [];
        foreach (array_values($questions) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, 'General Aptitude', $i);
            if ($norm === null) {
                continue;
            }
            $topic = trim((string) ($q['topic'] ?? ''));
            if ($topic !== '') {
                $norm['topic'] = $topic;
            }
            $norm['id'] = 'jdq-' . ($i + 1) . '-' . bin2hex(random_bytes(4));
            $norm['source'] = 'MANUAL_UPLOAD';
            foreach (['questionNumber', 'sourcePage', 'section', 'confidence', 'containsImage', 'answerKnown', 'directionsBlock', 'questionType'] as $metaKey) {
                if (array_key_exists($metaKey, $q)) {
                    $norm[$metaKey] = $q[$metaKey];
                }
            }
            if (!isset($norm['answerKnown'])) {
                $norm['answerKnown'] = false;
            }
            $normalized[] = $norm;
        }

        if ($normalized === [] && $fileUri === '' && $manualTextTrim === '') {
            throw new \InvalidArgumentException(
                'Upload a PDF, image, or text file, paste manual text, or include parseable MCQs.'
            );
        }

        $target = strtolower(trim($manualSaveTarget));
        if (!in_array($target, ['bank', 'problem', 'both'], true)) {
            $target = 'bank';
        }

        $doc = [
            'jdTitle' => $jdTitle,
            'companyId' => $companyId,
            'companyName' => $companyName,
            'questionCount' => count($normalized),
            'questions' => $normalized,
            'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
            'manualSource' => 'upload',
            'companyBankKind' => 'local',
            'manualSaveTarget' => $target,
            'showInCompanyBank' => $target !== 'problem',
        ];
        $parseTag = trim((string) ($manualParseMethod ?? ''));
        if ($parseTag !== '') {
            $doc['manualParseMethod'] = $parseTag;
        }
        $filename = trim((string) ($jdFilename ?? ''));
        if ($filename !== '') {
            $doc['jdFilename'] = $filename;
        }
        if ($fileUri !== '') {
            $doc['jdFile'] = $fileUri;
        }
        $fileUrl = trim((string) ($jdFileUrl ?? ''));
        if ($fileUrl !== '') {
            $doc['jdFileUrl'] = $fileUrl;
        }
        $mime = trim((string) ($jdMimeType ?? ''));
        if ($mime !== '') {
            $doc['jdMimeType'] = $mime;
        }
        if ($manualTextTrim !== '') {
            $doc['manualText'] = mb_strlen($manualTextTrim) > 50000
                ? mb_substr($manualTextTrim, 0, 50000)
                : $manualTextTrim;
        }
        if (is_array($importMeta) && $importMeta !== []) {
            $doc['importMeta'] = $importMeta;
        }

        $existing = $this->findLocalManualSetByTitle($companyId, $jdTitle);
        if ($existing !== null) {
            $existingId = (string) ($existing['_id'] ?? '');
            if ($existingId !== '' && $this->update($existingId, $doc)) {
                $saved = $this->findById($existingId);

                return array_merge(
                    $saved !== null ? $this->detailView($saved) : ['id' => $existingId, 'jdTitle' => $jdTitle],
                    ['replacedExisting' => true]
                );
            }
        }

        $id = $this->insert($doc);
        $saved = $this->findById($id);
        $view = $saved !== null ? $this->detailView($saved) : ['id' => $id, 'jdTitle' => $jdTitle];
        $view['replacedExisting'] = false;

        return $view;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLocalManualSetByTitle(string $companyId, string $jdTitle): ?array
    {
        $companyId = trim($companyId);
        $titleNorm = mb_strtolower(trim($jdTitle));
        if ($companyId === '' || $titleNorm === '') {
            return null;
        }

        $rows = $this->findAll(['companyId' => $companyId], 400, 0, ['createdAt' => -1]);
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) ($row['jdTitle'] ?? ''))) !== $titleNorm) {
                continue;
            }
            if (self::resolveCompanyBankKind($row) !== 'local') {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public function resolveByRules(array $rules): array
    {
        $picked = [];
        $usedKeys = [];

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $setId = trim((string) ($rule['jdSetId'] ?? ''));
            if ($setId === '' || !Security::isValidId($setId)) {
                throw new \InvalidArgumentException('Invalid JD set selected.');
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            $marks = max(0.25, (float) ($rule['marks'] ?? 1));
            $selectedIds = array_values(array_filter(array_map(
                static fn ($id): string => trim((string) $id),
                (array) ($rule['selectedQuestionIds'] ?? [])
            )));
            if ($count <= 0) {
                continue;
            }
            if (count($selectedIds) !== $count) {
                $title = trim((string) ($rule['jdTitle'] ?? 'JD set'));
                throw new \InvalidArgumentException(
                    'Select exactly ' . $count . ' question(s) for ' . ($title !== '' ? $title : 'the JD set') . '.'
                );
            }

            $set = $this->findById($setId);
            if ($set === null) {
                throw new \InvalidArgumentException('JD question set not found.');
            }
            $jdTitle = trim((string) ($set['jdTitle'] ?? 'Job Description'));
            $index = $this->questionIndex($set);

            foreach ($selectedIds as $qid) {
                $key = $setId . ':' . $qid;
                if (isset($usedKeys[$key])) {
                    continue;
                }
                $q = $index[$qid] ?? null;
                if ($q === null) {
                    throw new \InvalidArgumentException('One or more selected JD questions were not found.');
                }
                $usedKeys[$key] = true;
                $picked[] = array_merge($q, [
                    'marks' => $marks,
                    'jdSetId' => $setId,
                    'jdTitle' => $jdTitle,
                    'source' => 'AI_JD',
                ]);
            }
        }

        return $picked;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public function pickRandomByRules(array $rules): array
    {
        $picked = [];
        $usedKeys = [];

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $setId = trim((string) ($rule['jdSetId'] ?? ''));
            if ($setId === '' || !Security::isValidId($setId)) {
                throw new \InvalidArgumentException('Invalid JD set selected.');
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            $marks = max(0.25, (float) ($rule['marks'] ?? 1));
            if ($count <= 0) {
                continue;
            }

            $set = $this->findById($setId);
            if ($set === null) {
                throw new \InvalidArgumentException('JD question set not found.');
            }
            $jdTitle = trim((string) ($set['jdTitle'] ?? 'Job Description'));
            $pool = array_values($this->questionIndex($set));
            if (count($pool) < $count) {
                throw new \InvalidArgumentException(
                    'Only ' . count($pool) . ' question(s) available in '
                    . ($jdTitle !== '' ? $jdTitle : 'the JD set')
                    . '. Reduce the count.'
                );
            }
            shuffle($pool);
            $slice = array_slice($pool, 0, $count);
            foreach ($slice as $q) {
                $qid = trim((string) ($q['id'] ?? ''));
                $key = $setId . ':' . $qid;
                if ($qid === '' || isset($usedKeys[$key])) {
                    continue;
                }
                $usedKeys[$key] = true;
                $picked[] = array_merge($q, [
                    'marks' => $marks,
                    'jdSetId' => $setId,
                    'jdTitle' => $jdTitle,
                    'source' => 'AI_JD',
                ]);
            }
        }

        return $picked;
    }

    public function deleteSet(string $id): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }

        return $this->delete($id);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|null Updated set detail
     */
    public function updateQuestion(string $setId, string $questionId, array $patch): ?array
    {
        if (!Security::isValidId($setId)) {
            return null;
        }
        $questionId = trim($questionId);
        if ($questionId === '') {
            return null;
        }

        $set = $this->findById($setId);
        if ($set === null) {
            return null;
        }

        $questions = array_values((array) ($set['questions'] ?? []));
        $updated = false;
        foreach ($questions as $i => $q) {
            if (!is_array($q) || (string) ($q['id'] ?? '') !== $questionId) {
                continue;
            }
            $merged = $q;
            if (array_key_exists('prompt', $patch)) {
                $merged['prompt'] = trim((string) $patch['prompt']);
            }
            if (array_key_exists('options', $patch) && is_array($patch['options'])) {
                $merged['options'] = array_values($patch['options']);
            }
            if (array_key_exists('correctIndex', $patch)) {
                $merged['correctIndex'] = (int) $patch['correctIndex'];
            }
            if (array_key_exists('explanation', $patch)) {
                $merged['explanation'] = trim((string) $patch['explanation']);
            }
            if (array_key_exists('answerKnown', $patch)) {
                $merged['answerKnown'] = !empty($patch['answerKnown']);
            }
            if (array_key_exists('directionsBlock', $patch)) {
                $merged['directionsBlock'] = trim((string) $patch['directionsBlock']);
            }

            $norm = AptitudeTestModel::normalizeMcq($merged, (string) ($merged['category'] ?? 'General Aptitude'), $i);
            if ($norm === null) {
                throw new \InvalidArgumentException('Question text and at least two options are required.');
            }
            $norm['id'] = $questionId;
            $norm['source'] = (string) ($q['source'] ?? 'MANUAL_UPLOAD');
            foreach (['questionNumber', 'sourcePage', 'section', 'confidence', 'containsImage', 'answerKnown', 'directionsBlock', 'questionType', 'topic'] as $metaKey) {
                if (array_key_exists($metaKey, $merged)) {
                    $norm[$metaKey] = $merged[$metaKey];
                }
            }
            if (array_key_exists('answerKnown', $patch)) {
                $norm['answerKnown'] = !empty($patch['answerKnown']);
            }
            $questions[$i] = $norm;
            $updated = true;
            break;
        }

        if (!$updated) {
            return null;
        }

        $set['questions'] = $questions;
        $set['questionCount'] = count($questions);
        if (!$this->update($setId, $set)) {
            return null;
        }
        $saved = $this->findById($setId);

        return $saved !== null ? $this->detailView($saved) : null;
    }

    /**
     * @param array<string, mixed> $set
     * @return array<string, array<string, mixed>>
     */
    private function questionIndex(array $set): array
    {
        $index = [];
        foreach (array_values((array) ($set['questions'] ?? [])) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $id = trim((string) ($q['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $index[$id] = $q;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summaryView(array $row, bool $forStudent = false): array
    {
        $id = (string) ($row['_id'] ?? '');

        return $this->withDocumentUrl([
            'id' => $id,
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'jdTitle' => (string) ($row['jdTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => $this->rowHasManualDocument($row),
            'questionCount' => (int) ($row['questionCount'] ?? count((array) ($row['questions'] ?? []))),
            'manualSaveTarget' => (string) ($row['manualSaveTarget'] ?? 'bank'),
            'manualSource' => (string) ($row['manualSource'] ?? ''),
            'companyBankKind' => self::resolveCompanyBankKind($row),
            'showInCompanyBank' => !array_key_exists('showInCompanyBank', $row) || !empty($row['showInCompanyBank']),
            'manualParseMethod' => (string) ($row['manualParseMethod'] ?? ''),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row, $forStudent);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailView(array $row, bool $forStudent = false): array
    {
        $questions = [];
        foreach (array_values((array) ($row['questions'] ?? [])) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $questions[] = [
                'id' => (string) ($q['id'] ?? ''),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'correctIndex' => (int) ($q['correctIndex'] ?? 0),
                'explanation' => (string) ($q['explanation'] ?? ''),
                'topic' => (string) ($q['topic'] ?? ''),
                'difficulty' => (string) ($q['difficulty'] ?? 'Medium'),
                'category' => (string) ($q['category'] ?? 'General Aptitude'),
                'marks' => (float) ($q['marks'] ?? 1),
                'source' => (string) ($q['source'] ?? 'MANUAL_UPLOAD'),
                'questionNumber' => (int) ($q['questionNumber'] ?? 0),
                'sourcePage' => (int) ($q['sourcePage'] ?? 0),
                'section' => (string) ($q['section'] ?? ''),
                'confidence' => isset($q['confidence']) ? (float) $q['confidence'] : null,
                'containsImage' => !empty($q['containsImage']),
                'answerKnown' => !empty($q['answerKnown']),
                'directionsBlock' => (string) ($q['directionsBlock'] ?? ''),
                'questionType' => (string) ($q['questionType'] ?? ''),
            ];
        }

        $id = (string) ($row['_id'] ?? '');

        return $this->withDocumentUrl([
            'id' => $id,
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'jdTitle' => (string) ($row['jdTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => $this->rowHasManualDocument($row),
            'questionCount' => count($questions),
            'questions' => $questions,
            'manualText' => $this->manualTextForView($row),
            'manualSaveTarget' => (string) ($row['manualSaveTarget'] ?? 'bank'),
            'manualSource' => (string) ($row['manualSource'] ?? ''),
            'companyBankKind' => self::resolveCompanyBankKind($row),
            'showInCompanyBank' => !array_key_exists('showInCompanyBank', $row) || !empty($row['showInCompanyBank']),
            'manualParseMethod' => (string) ($row['manualParseMethod'] ?? ''),
            'importMeta' => is_array($row['importMeta'] ?? null) ? $row['importMeta'] : [],
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row, $forStudent);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function resolveCompanyBankKind(array $row): string
    {
        $kind = strtolower(trim((string) ($row['companyBankKind'] ?? '')));
        if ($kind === 'local' || $kind === 'question') {
            return $kind;
        }
        if (strtolower(trim((string) ($row['manualSource'] ?? ''))) === 'upload') {
            return 'local';
        }

        return 'question';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowHasManualDocument(array $row): bool
    {
        return trim((string) ($row['jdFile'] ?? '')) !== ''
            || trim((string) ($row['jdFileUrl'] ?? '')) !== ''
            || trim((string) ($row['manualText'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function manualTextForView(array $row): string
    {
        $text = trim((string) ($row['manualText'] ?? ''));

        return mb_strlen($text) > 20000 ? (mb_substr($text, 0, 20000) . '…') : $text;
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withDocumentUrl(array $view, array $row, bool $forStudent = false): array
    {
        $fileUri = trim((string) ($row['jdFile'] ?? ''));
        $id = (string) ($view['id'] ?? $row['_id'] ?? '');
        if ($fileUri !== '' && $id !== '') {
            $prefix = $forStudent
                ? '/backend/api/aptitude/student/jd-sets'
                : '/backend/api/aptitude/jd-sets';
            $view['jdFileUrl'] = $prefix . '/' . rawurlencode($id) . '/document';
            $view['hasDocument'] = true;
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function publicDetail(array $row): array
    {
        return $this->detailView($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function studentPublicDetail(array $row): array
    {
        return $this->detailView($row, true);
    }
}
