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
    private function summaryView(array $row): array
    {
        $problems = array_values((array) ($row['problems'] ?? []));

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'setTitle' => (string) ($row['setTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'problemCount' => (int) ($row['problemCount'] ?? count($problems)),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailView(array $row): array
    {
        $problems = [];
        foreach (array_values((array) ($row['problems'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $norm = CodingProblemBankModel::normalize($p);
            $problems[] = array_merge($norm, [
                'id' => (string) ($p['id'] ?? ('cp-' . ($i + 1))),
            ]);
        }

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'companyId' => (string) ($row['companyId'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'setTitle' => (string) ($row['setTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'problemCount' => count($problems),
            'problems' => $problems,
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ];
    }
}
