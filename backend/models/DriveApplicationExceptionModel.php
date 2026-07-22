<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Per-student drive open exceptions (officer opens a drive for an already-placed student).
 */
final class DriveApplicationExceptionModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::DRIVE_APPLICATION_EXCEPTIONS;
    }

    public function findActive(string $studentId, string $driveId): ?array
    {
        $sId = Security::toObjectId($studentId);
        $dId = Security::toObjectId($driveId);
        if ($sId === null || $dId === null) {
            return null;
        }

        $row = $this->findOne([
            'studentId' => $sId,
            'driveId'   => $dId,
            'revokedAt' => null,
        ], ['sort' => ['grantedAt' => -1]]);

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = trim((string) ($row['expiresAt'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            return null;
        }

        return $row;
    }

    public function hasActive(string $studentId, string $driveId): bool
    {
        return $this->findActive($studentId, $driveId) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveForDrive(string $driveId, int $limit = 200): array
    {
        $dId = Security::toObjectId($driveId);
        if ($dId === null) {
            return [];
        }

        $rows = $this->findAll([
            'driveId'   => $dId,
            'revokedAt' => null,
        ], $limit);

        $now = time();
        $out = [];
        foreach ($rows as $row) {
            $expiresAt = trim((string) ($row['expiresAt'] ?? ''));
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < $now) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{id: string, created: bool}
     */
    public function grant(
        string $studentId,
        string $driveId,
        string $grantedBy,
        string $reason,
        ?string $expiresAt = null
    ): array {
        $existing = $this->findActive($studentId, $driveId);
        if (is_array($existing)) {
            return ['id' => (string) ($existing['_id'] ?? ''), 'created' => false];
        }

        $id = $this->insert([
            'studentId' => Security::toObjectId($studentId),
            'driveId'   => Security::toObjectId($driveId),
            'reason'    => $reason,
            'grantedBy' => Security::toObjectId($grantedBy),
            'grantedAt' => DocumentHelper::now(),
            'expiresAt' => $expiresAt,
            'revokedAt' => null,
            'revokedBy' => null,
            'bypass'    => [
                'placementCategory' => true,
            ],
        ]);

        return ['id' => $id, 'created' => true];
    }

    public function revoke(string $exceptionId, string $revokedBy): bool
    {
        $row = $this->findById($exceptionId);
        if (!$row || !empty($row['revokedAt'])) {
            return false;
        }

        return $this->update($exceptionId, [
            'revokedAt' => DocumentHelper::now(),
            'revokedBy' => Security::toObjectId($revokedBy),
        ]);
    }
}
