<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Isolated Resume Builder certifications store (relational, not JSON payload).
 */
final class ResumeCertificationModel
{
    public const NAME_MIN = 3;
    public const NAME_MAX = 200;
    public const ORG_MIN = 2;
    public const ORG_MAX = 150;
    public const CREDENTIAL_ID_MAX = 100;
    public const URL_MAX = 500;
    public const DESC_MAX = 1000;
    public const COMPLETE_MIN_COUNT = 1;
    public const RECOMMENDED_COUNT = 2;

    private PDO $db;
    private string $table;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = Collections::RESUME_CERTIFICATIONS;
        $this->ensureTable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByStudentId(string $studentId): array
    {
        $sid = Security::toObjectId($studentId);
        if ($sid === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT id, certification_name, issuing_organization, issue_date, expiry_date,
                    credential_id, credential_url, description, created_at, updated_at
             FROM `{$this->table}`
             WHERE student_id = ?
             ORDER BY issue_date DESC, updated_at DESC"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForStudent(string $certificationId, string $studentId): ?array
    {
        $id = Security::toObjectId($certificationId);
        $sid = Security::toObjectId($studentId);
        if ($id === null || $sid === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, certification_name, issuing_organization, issue_date, expiry_date,
                    credential_id, credential_url, description, created_at, updated_at
             FROM `{$this->table}`
             WHERE id = ? AND student_id = ?
             LIMIT 1"
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function textLength(string $text): int
    {
        $trimmed = trim($text);
        return function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
    }

    public static function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return $text;
    }

    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /**
     * @param array{
     *   certification_name?: string,
     *   issuing_organization?: string,
     *   issue_date?: string,
     *   expiry_date?: string|null,
     *   credential_id?: string|null,
     *   credential_url?: string|null,
     *   description?: string|null
     * } $data
     * @return array{ok: bool, error: string|null, data?: array<string, mixed>}
     */
    public static function validate(array $data): array
    {
        $name = self::normalizeText((string) ($data['certification_name'] ?? ''));
        $org = self::normalizeText((string) ($data['issuing_organization'] ?? ''));
        $issue = trim((string) ($data['issue_date'] ?? ''));
        $expiry = trim((string) ($data['expiry_date'] ?? ''));
        $credentialId = self::normalizeText((string) ($data['credential_id'] ?? ''));
        $credentialUrl = trim((string) ($data['credential_url'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $nameLen = self::textLength($name);
        if ($nameLen < self::NAME_MIN) {
            return ['ok' => false, 'error' => 'Certification name must be at least ' . self::NAME_MIN . ' characters.'];
        }
        if ($nameLen > self::NAME_MAX) {
            return ['ok' => false, 'error' => 'Certification name must be at most ' . self::NAME_MAX . ' characters.'];
        }

        $orgLen = self::textLength($org);
        if ($orgLen < self::ORG_MIN) {
            return ['ok' => false, 'error' => 'Issuing organization must be at least ' . self::ORG_MIN . ' characters.'];
        }
        if ($orgLen > self::ORG_MAX) {
            return ['ok' => false, 'error' => 'Issuing organization must be at most ' . self::ORG_MAX . ' characters.'];
        }

        $issueDate = self::normalizeDate($issue);
        if ($issueDate === null) {
            return ['ok' => false, 'error' => 'Enter a valid issue date.'];
        }

        $expiryDate = null;
        if ($expiry !== '') {
            $expiryDate = self::normalizeDate($expiry);
            if ($expiryDate === null) {
                return ['ok' => false, 'error' => 'Enter a valid expiry date.'];
            }
            if ($expiryDate < $issueDate) {
                return ['ok' => false, 'error' => 'Expiry date cannot be earlier than issue date.'];
            }
        }

        if ($credentialId !== '' && self::textLength($credentialId) > self::CREDENTIAL_ID_MAX) {
            return ['ok' => false, 'error' => 'Credential ID must be at most ' . self::CREDENTIAL_ID_MAX . ' characters.'];
        }

        if ($credentialUrl !== '') {
            if (self::textLength($credentialUrl) > self::URL_MAX) {
                return ['ok' => false, 'error' => 'Credential URL must be at most ' . self::URL_MAX . ' characters.'];
            }
            if (!filter_var($credentialUrl, FILTER_VALIDATE_URL)) {
                return ['ok' => false, 'error' => 'Enter a valid credential URL (including https://).'];
            }
        }

        if ($description !== '' && self::textLength($description) > self::DESC_MAX) {
            return ['ok' => false, 'error' => 'Description must be at most ' . self::DESC_MAX . ' characters.'];
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'certification_name' => $name,
                'issuing_organization' => $org,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'credential_id' => $credentialId !== '' ? $credentialId : null,
                'credential_url' => $credentialUrl !== '' ? $credentialUrl : null,
                'description' => $description !== '' ? $description : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createForStudent(string $studentId, array $data): array
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

        $id = Security::generateId();
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}`
              (id, student_id, certification_name, issuing_organization, issue_date, expiry_date,
               credential_id, credential_url, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $sid,
            $clean['certification_name'],
            $clean['issuing_organization'],
            $clean['issue_date'],
            $clean['expiry_date'],
            $clean['credential_id'],
            $clean['credential_url'],
            $clean['description'],
            $now,
            $now,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not save certification.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateForStudent(string $certificationId, string $studentId, array $data): array
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($certificationId);
        if ($sid === null || $id === null) {
            throw new \InvalidArgumentException('Invalid certification.');
        }
        $existing = $this->findByIdForStudent($id, $sid);
        if (!$existing) {
            throw new \RuntimeException('Certification not found.');
        }

        $check = self::validate($data);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) $check['error']);
        }
        /** @var array<string, mixed> $clean */
        $clean = $check['data'] ?? [];
        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}`
             SET certification_name = ?, issuing_organization = ?, issue_date = ?, expiry_date = ?,
                 credential_id = ?, credential_url = ?, description = ?, updated_at = ?
             WHERE id = ? AND student_id = ?"
        );
        $stmt->execute([
            $clean['certification_name'],
            $clean['issuing_organization'],
            $clean['issue_date'],
            $clean['expiry_date'],
            $clean['credential_id'],
            $clean['credential_url'],
            $clean['description'],
            $now,
            $id,
            $sid,
        ]);
        $row = $this->findByIdForStudent($id, $sid);
        if (!$row) {
            throw new \RuntimeException('Could not update certification.');
        }
        return $row;
    }

    public function deleteForStudent(string $certificationId, string $studentId): bool
    {
        $sid = Security::toObjectId($studentId);
        $id = Security::toObjectId($certificationId);
        if ($sid === null || $id === null) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ? AND student_id = ?");
        $stmt->execute([$id, $sid]);
        return $stmt->rowCount() > 0;
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              student_id CHAR(24) NOT NULL,
              certification_name VARCHAR(200) NOT NULL,
              issuing_organization VARCHAR(150) NOT NULL,
              issue_date DATE NOT NULL,
              expiry_date DATE NULL,
              credential_id VARCHAR(100) NULL,
              credential_url VARCHAR(500) NULL,
              description VARCHAR(1000) NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_resume_certifications_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
