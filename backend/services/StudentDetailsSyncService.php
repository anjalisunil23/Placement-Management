<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentDetailsModel;
use PMS\Utils\DocumentHelper;

/**
 * Shared idempotent AES → student_details synchronization.
 * Used by both admin and staff sync endpoints.
 */
final class StudentDetailsSyncService
{
    /**
     * @param list<array<string, mixed>> $records AES directory rows
     * @param array<string, mixed> $options syncSource, studRole, departmentId
     * @return array<string, mixed>
     */
    public function syncFromAesRecords(array $records, array $options = []): array
    {
        $startedAt = microtime(true);
        $syncedAt = DocumentHelper::now();
        $model = new StudentDetailsModel();

        if (!$model->isAvailable()) {
            return [
                'success'     => false,
                'error'       => 'student_details table is not available.',
                'fetched'     => count($records),
                'inserted'    => 0,
                'updated'     => 0,
                'unchanged'   => 0,
                'failed'      => 0,
                'skipped'     => count($records),
                'syncedAt'    => $syncedAt,
                'durationMs'  => 0,
            ];
        }

        $stats = [
            'success'    => true,
            'fetched'    => count($records),
            'inserted'   => 0,
            'updated'    => 0,
            'unchanged'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'syncedAt'   => $syncedAt,
            'syncSource' => (string) ($options['syncSource'] ?? 'aes'),
        ];

        $syncedAdmnos = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                $stats['skipped']++;
                continue;
            }
            try {
                $result = $model->upsertFromAesRecord($record, $options);
                $action = (string) ($result['action'] ?? 'failed');
                $admno = strtoupper(trim((string) ($result['aesAdmno'] ?? '')));
                if ($admno !== '') {
                    $syncedAdmnos[$admno] = true;
                }
                if ($action === 'inserted') {
                    $stats['inserted']++;
                } elseif ($action === 'updated') {
                    $stats['updated']++;
                } elseif ($action === 'unchanged') {
                    $stats['unchanged']++;
                } elseif ($action === 'skipped') {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                }
            } catch (\Throwable) {
                $stats['failed']++;
            }
        }

        if ($syncedAdmnos !== []) {
            $stats['registrationRefresh'] = $model->refreshRegistrationStatuses(array_keys($syncedAdmnos));
        }

        $stats['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);
        $stats['studentsSynced'] = $stats['inserted'] + $stats['updated'] + $stats['unchanged'];

        return $stats;
    }

    /**
     * Merge multiple sync result arrays (e.g. studying + alumni passes).
     *
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    public function mergeSyncStats(array $parts): array
    {
        $merged = [
            'success'    => true,
            'fetched'    => 0,
            'inserted'   => 0,
            'updated'    => 0,
            'unchanged'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'syncedAt'   => DocumentHelper::now(),
            'durationMs' => 0,
        ];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (empty($part['success'])) {
                $merged['success'] = false;
            }
            foreach (['fetched', 'inserted', 'updated', 'unchanged', 'failed', 'skipped', 'durationMs'] as $key) {
                $merged[$key] += (int) ($part[$key] ?? 0);
            }
            if (!empty($part['syncedAt'])) {
                $merged['syncedAt'] = (string) $part['syncedAt'];
            }
            if (!empty($part['syncSource'])) {
                $merged['syncSource'] = (string) $part['syncSource'];
            }
            if (!empty($part['error'])) {
                $merged['error'] = (string) $part['error'];
            }
        }
        $merged['studentsSynced'] = $merged['inserted'] + $merged['updated'] + $merged['unchanged'];

        return $merged;
    }
}
