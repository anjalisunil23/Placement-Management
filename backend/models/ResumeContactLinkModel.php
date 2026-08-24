<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder professional links (LinkedIn, GitHub, website).
 */
final class ResumeContactLinkModel
{
    public const URL_MAX = 500;

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_CONTACT_LINKS;
        $this->ensureTable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByStudentId(string $studentId): ?array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, linkedin_url, github_url, website_url, created_at, updated_at
             FROM `{$this->table}` WHERE student_id = ? LIMIT 1"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array{linkedin_url?: string, github_url?: string, website_url?: string} $data
     * @return array{ok: bool, error: string|null, data?: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $linkedin = trim((string) ($data['linkedin_url'] ?? ''));
        $github = trim((string) ($data['github_url'] ?? ''));
        $website = trim((string) ($data['website_url'] ?? ''));

        foreach ([
            'LinkedIn' => $linkedin,
            'GitHub' => $github,
            'Website' => $website,
        ] as $label => $url) {
            if ($url === '') {
                continue;
            }
            if ((function_exists('mb_strlen') ? mb_strlen($url) : strlen($url)) > self::URL_MAX) {
                return ['ok' => false, 'error' => $label . ' URL must be at most ' . self::URL_MAX . ' characters.'];
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'error' => 'Enter a valid ' . $label . ' URL (including https://).'];
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'linkedin_url' => $linkedin !== '' ? $linkedin : null,
                'github_url' => $github !== '' ? $github : null,
                'website_url' => $website !== '' ? $website : null,
            ],
        ];
    }

    /**
     * @param array{linkedin_url?: string, github_url?: string, website_url?: string} $data
     * @return array<string, mixed>
     */
    public function upsertForStudent(string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            throw new \InvalidArgumentException('Invalid student.');
        }
        $check = self::validate($data);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        /** @var array<string, mixed> $clean */
        $clean = $check['data'] ?? [];
        $existing = $this->findByStudentId($sid);
        $now = DocumentHelper::now();

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE `{$this->table}`
                 SET linkedin_url = ?, github_url = ?, website_url = ?, updated_at = ?
                 WHERE student_id = ?"
            );
            $stmt->execute([
                $clean['linkedin_url'],
                $clean['github_url'],
                $clean['website_url'],
                $now,
                $sid,
            ]);
        } else {
            $id = Security::generateId();
            $stmt = $this->db->prepare(
                "INSERT INTO `{$this->table}`
                  (id, student_id, linkedin_url, github_url, website_url, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $id,
                $sid,
                $clean['linkedin_url'],
                $clean['github_url'],
                $clean['website_url'],
                $now,
                $now,
            ]);
        }

        $row = $this->findByStudentId($sid);
        if (!$row) {
            throw new \RuntimeException('Could not save professional links.');
        }
        return $row;
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              student_id CHAR(24) NOT NULL,
              linkedin_url VARCHAR(500) NULL,
              github_url VARCHAR(500) NULL,
              website_url VARCHAR(500) NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uq_resume_contact_links_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
