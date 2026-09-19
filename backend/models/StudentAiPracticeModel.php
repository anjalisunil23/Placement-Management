<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Ephemeral student AI self-practice sessions (not official tests or question bank).
 */
class StudentAiPracticeModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_AI_PRACTICE_SESSIONS;
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
            'CREATE TABLE IF NOT EXISTS `student_ai_practice_sessions` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $doc
     */
    public function createSession(array $doc): string
    {
        return $this->insert($doc);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForStudent(string $studentId, int $limit = 50): array
    {
        $oid = Security::toObjectId($studentId);
        if ($oid === null) {
            return [];
        }
        $rows = $this->findAll(['studentId' => $oid], $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->summaryView($row);
        }

        return $out;
    }

    public function findForStudent(string $sessionId, string $studentId): ?array
    {
        if (!Security::isValidId($sessionId)) {
            return null;
        }
        $row = $this->findById($sessionId);
        if ($row === null) {
            return null;
        }
        $owner = trim((string) ($row['studentId'] ?? ''));
        $expected = trim($studentId);
        if ($owner !== $expected) {
            $ownerOid = Security::toObjectId($owner);
            $expectedOid = Security::toObjectId($expected);
            if ($ownerOid === null || $expectedOid === null || (string) $ownerOid !== (string) $expectedOid) {
                return null;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function summaryView(array $row): array
    {
        $score = (int) ($row['score'] ?? 0);
        $total = (int) ($row['questionCount'] ?? 0);

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'sourceMode' => (string) ($row['sourceMode'] ?? 'topic'),
            'topic' => (string) ($row['topic'] ?? ''),
            'jdDriveId' => (string) ($row['jdDriveId'] ?? ''),
            'jdTitle' => (string) ($row['jdTitle'] ?? ''),
            'companyName' => (string) ($row['companyName'] ?? ''),
            'difficulty' => (string) ($row['difficulty'] ?? 'Medium'),
            'questionCount' => $total,
            'score' => $score,
            'percentage' => $total > 0 ? round(($score / $total) * 100, 1) : 0,
            'status' => (string) ($row['status'] ?? 'in_progress'),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
            'completedAt' => (string) ($row['completedAt'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function detailView(array $row): array
    {
        $summary = $this->summaryView($row);
        $analysis = [];
        foreach (array_values((array) ($row['analysis'] ?? [])) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $analysis[] = $item;
        }

        return array_merge($summary, [
            'analysis' => $analysis,
            'label' => $this->historyLabel($row),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function historyLabel(array $row): string
    {
        $mode = (string) ($row['sourceMode'] ?? 'topic');
        if ($mode === 'jd' || $mode === 'jd_topic') {
            $title = trim((string) ($row['jdTitle'] ?? ''));
            $company = trim((string) ($row['companyName'] ?? ''));

            return $title !== '' ? ($company !== '' ? "{$title} — {$company}" : $title) : 'Job Description';
        }

        return trim((string) ($row['topic'] ?? '')) ?: 'Topic practice';
    }
}
