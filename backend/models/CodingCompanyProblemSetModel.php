<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Company-scoped coding problem sets — separate from aptitude JD question sets.
 */
class CodingCompanyProblemSetModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::CODING_COMPANY_PROBLEM_SETS;
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
            'CREATE TABLE IF NOT EXISTS `coding_company_problem_sets` (
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
    public function listSummaries(int $limit = 200): array
    {
        $rows = $this->findAll([], $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->summaryView($row);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCompanyBlocks(int $limit = 200): array
    {
        return $this->groupSetsIntoBlocks($this->listSummaries($limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listStudentCompanyBlocks(int $limit = 200): array
    {
        $sets = $this->listSummaries($limit);
        $blocks = $this->groupSetsIntoBlocks($sets);

        return array_values(array_filter($blocks, static fn (array $b): bool => trim((string) ($b['companyId'] ?? '')) !== ''));
    }

    /**
     * @param list<array<string, mixed>> $sets
     * @return list<array<string, mixed>>
     */
    private function groupSetsIntoBlocks(array $sets): array
    {
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
                    'problemCount' => 0,
                    'sets' => [],
                ];
            }
            $blocks[$key]['setCount']++;
            $blocks[$key]['problemCount'] += (int) ($set['problemCount'] ?? 0);
            $blocks[$key]['sets'][] = $set;
        }

        $out = array_values($blocks);
        usort($out, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['companyName'] ?? ''),
            (string) ($b['companyName'] ?? '')
        ));

        return $out;
    }

    public function deleteSet(string $id): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }

        return $this->delete($id);
    }

    /**
     * @param array<int, array<string, mixed>> $problems
     * @return array<string, mixed>
     */
    public function createSet(
        string $setTitle,
        array $problems,
        ?string $createdBy = null,
        ?string $companyId = null,
        ?string $companyName = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null
    ): array {
        $setTitle = trim($setTitle);
        if ($setTitle === '') {
            throw new \InvalidArgumentException('Set title is required.');
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
        foreach (array_values($problems) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $norm = CodingProblemBankModel::normalize($p);
            if (trim((string) ($norm['title'] ?? '')) === '') {
                continue;
            }
            $norm['id'] = 'cp-' . ($i + 1) . '-' . bin2hex(random_bytes(4));
            $norm['source'] = 'AI_JD';
            $normalized[] = $norm;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('No valid problems to save.');
        }

        $doc = [
            'setTitle' => $setTitle,
            'jdTitle' => $setTitle,
            'companyId' => $companyId,
            'companyName' => $companyName,
            'problemCount' => count($normalized),
            'problems' => $normalized,
            'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
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

        return $saved !== null ? $this->detailView($saved, false) : ['id' => $id, 'setTitle' => $setTitle];
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
                throw new \InvalidArgumentException('Invalid company block set selected.');
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            $marks = max(1, (float) ($rule['marks'] ?? 2));
            $selectedIds = array_values(array_filter(array_map(
                static fn ($id): string => trim((string) $id),
                (array) ($rule['selectedQuestionIds'] ?? $rule['selectedProblemIds'] ?? [])
            )));
            if ($count <= 0) {
                continue;
            }
            if (count($selectedIds) !== $count) {
                $title = trim((string) ($rule['jdTitle'] ?? $rule['setTitle'] ?? 'JD set'));
                throw new \InvalidArgumentException(
                    'Select exactly ' . $count . ' problem(s) for ' . ($title !== '' ? $title : 'the company block set') . '.'
                );
            }

            $set = $this->findById($setId);
            if ($set === null) {
                throw new \InvalidArgumentException('Company problem set not found.');
            }
            $setTitle = trim((string) ($set['setTitle'] ?? $set['jdTitle'] ?? 'Job Description'));
            $index = $this->problemIndex($set);

            foreach ($selectedIds as $pid) {
                $key = $setId . ':' . $pid;
                if (isset($usedKeys[$key])) {
                    continue;
                }
                $p = $index[$pid] ?? null;
                if ($p === null) {
                    throw new \InvalidArgumentException('One or more selected company block problems were not found.');
                }
                $usedKeys[$key] = true;
                $picked[] = $this->toTestItemFromProblem($p, $setId, $setTitle, $marks);
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
                throw new \InvalidArgumentException('Invalid company block set selected.');
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            $marks = max(1, (float) ($rule['marks'] ?? 2));
            if ($count <= 0) {
                continue;
            }

            $set = $this->findById($setId);
            if ($set === null) {
                throw new \InvalidArgumentException('Company problem set not found.');
            }
            $setTitle = trim((string) ($set['setTitle'] ?? $set['jdTitle'] ?? 'Job Description'));
            $pool = array_values($this->problemIndex($set));
            if (count($pool) < $count) {
                throw new \InvalidArgumentException(
                    'Only ' . count($pool) . ' problem(s) available in '
                    . ($setTitle !== '' ? $setTitle : 'the company block set')
                    . '. Reduce the count.'
                );
            }
            shuffle($pool);
            $slice = array_slice($pool, 0, $count);
            foreach ($slice as $p) {
                $pid = trim((string) ($p['id'] ?? ''));
                $key = $setId . ':' . $pid;
                if ($pid === '' || isset($usedKeys[$key])) {
                    continue;
                }
                $usedKeys[$key] = true;
                $picked[] = $this->toTestItemFromProblem($p, $setId, $setTitle, $marks);
            }
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function publicDetail(array $row, bool $forStudent = false): array
    {
        return $this->detailView($row, $forStudent);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function studentPublicDetail(array $row): array
    {
        return $this->detailView($row, true);
    }

    /**
     * @param array<string, mixed> $set
     * @return array<string, array<string, mixed>>
     */
    private function problemIndex(array $set): array
    {
        $index = [];
        foreach (array_values((array) ($set['problems'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = trim((string) ($p['id'] ?? ('cp-' . ($i + 1))));
            if ($id === '') {
                continue;
            }
            $index[$id] = array_merge(CodingProblemBankModel::normalize($p), ['id' => $id]);
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function toTestItemFromProblem(array $p, string $setId, string $setTitle, float $marks): array
    {
        $slug = substr($setId, -6) . '-' . substr((string) ($p['id'] ?? 'p'), -6);

        return array_merge(CodingProblemBankModel::normalize($p), [
            'id' => 'cb-' . $slug,
            'companySetId' => $setId,
            'jdSetId' => $setId,
            'setTitle' => $setTitle,
            'jdTitle' => $setTitle,
            'marks' => $marks,
            'source' => 'AI_JD',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summaryView(array $row, bool $forStudent = false): array
    {
        $problems = array_values((array) ($row['problems'] ?? []));
        $setTitle = (string) ($row['setTitle'] ?? $row['jdTitle'] ?? '');

        return $this->withDocumentUrl([
            'id' => (string) ($row['_id'] ?? ''),
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'setTitle' => $setTitle,
            'jdTitle' => $setTitle,
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'problemCount' => (int) ($row['problemCount'] ?? count($problems)),
            'questionCount' => (int) ($row['problemCount'] ?? count($problems)),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row, $forStudent);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailView(array $row, bool $forStudent = false): array
    {
        $problems = [];
        foreach (array_values((array) ($row['problems'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $norm = CodingProblemBankModel::normalize($p);
            $problems[] = array_merge($norm, [
                'id' => (string) ($p['id'] ?? ('cp-' . ($i + 1))),
                'source' => 'AI_JD',
            ]);
        }
        $setTitle = (string) ($row['setTitle'] ?? $row['jdTitle'] ?? '');

        return $this->withDocumentUrl([
            'id' => (string) ($row['_id'] ?? ''),
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'setTitle' => $setTitle,
            'jdTitle' => $setTitle,
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'problemCount' => count($problems),
            'questionCount' => count($problems),
            'problems' => $problems,
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row, $forStudent);
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
                ? '/backend/api/coding/student/company-block/sets'
                : '/backend/api/coding/company-block/sets';
            $view['jdFileUrl'] = $prefix . '/' . rawurlencode($id) . '/document';
            $view['hasDocument'] = true;
        }

        return $view;
    }
}
