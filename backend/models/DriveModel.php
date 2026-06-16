<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class DriveModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::DRIVES;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDrive(array $data, string $createdBy): string
    {
        $doc = [
            'title'       => $data['title'],
            'companyId'   => Security::toObjectId($data['companyId']),
            'type'        => $data['type'],
            'date'        => $data['date'],
            'time'        => $data['time'],
            'branches'    => $data['branches'] ?? [],
            'eligibility' => $data['eligibility'] ?? [],
            'tier'        => $data['tier'] ?? 'Tier 2',
            'jdFile'      => $data['jdFile'] ?? null,
            'attendance'  => [],
            'results'     => [],
            'status'      => 'scheduled',
            'createdBy'   => Security::toObjectId($createdBy),
            'departmentId'=> isset($data['departmentId']) ? Security::toObjectId($data['departmentId']) : null,
        ];
        return $this->insert($doc);
    }

    public function markAttendance(string $driveId, string $studentId, bool $present): bool
    {
        $drive = $this->findById($driveId);
        if (!$drive) {
            return false;
        }
        $attendance = $drive['attendance'] ?? [];
        $studentOid = (string) Security::toObjectId($studentId);
        $found = false;
        foreach ($attendance as &$entry) {
            if ((string) ($entry['studentId'] ?? '') === $studentOid) {
                $entry['present'] = $present;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (!$found) {
            $attendance[] = [
                'studentId' => Security::toObjectId($studentId),
                'present'   => $present,
            ];
        }
        return $this->update($driveId, ['attendance' => $attendance]);
    }
}
