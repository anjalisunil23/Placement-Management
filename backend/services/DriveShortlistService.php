<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\DriveModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * PO/admin upload of company shortlist documents + mark applicants shortlisted.
 */
final class DriveShortlistService
{
    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $documentFile $_FILES entry
     * @return array{
     *   driveId: string,
     *   documentSaved: bool,
     *   documentName: string,
     *   documentUrl: string,
     *   updated: int,
     *   failed: int,
     *   alreadyShortlisted: int,
     *   errors: array<int, string>,
     *   shortlisted: array<int, array<string, string>>
     * }
     */
    public function upload(
        string $driveId,
        array $ctx,
        string $byUserId,
        ?array $documentFile = null,
        string $csvContent = '',
        string $registerList = ''
    ): array {
        $driveModel = new DriveModel();
        $drive = $driveModel->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }
        if (!$ctx['isAdmin'] && !PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {
            Response::forbidden('This drive is not for your department.');
        }

        $documentSaved = false;
        $documentName = (string) ($drive['shortlistDocumentName'] ?? '');
        $documentPath = (string) ($drive['shortlistDocument'] ?? '');

        if (is_array($documentFile) && ($documentFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $max = (int) ($config['uploads']['max_shortlist'] ?? $config['uploads']['max_jd'] ?? 10485760);
            $error = Security::validateUploadedFile(
                $documentFile,
                $max,
                ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp']
            );
            if ($error) {
                Response::error($error, 400);
            }

            $dir = (string) ($config['uploads']['shortlist_dir'] ?? ($config['uploads']['jd_dir'] . '/shortlists'));
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename((string) $documentFile['name'])) ?: 'shortlist.pdf';
            $stored = $dir . '/' . $driveId . '_' . time() . '_' . $safeName;
            if (!move_uploaded_file((string) $documentFile['tmp_name'], $stored)) {
                Response::error('Could not save shortlist document.', 500);
            }

            $documentPath = $stored;
            $documentName = (string) $documentFile['name'];
            $documentSaved = true;
            $driveModel->update($driveId, [
                'shortlistDocument' => $documentPath,
                'shortlistDocumentName' => $documentName,
                'shortlistUploadedAt' => gmdate('c'),
                'shortlistUploadedBy' => $byUserId,
            ]);
        }

        $rolls = $this->extractRegisterNumbers($csvContent, $registerList);
        $result = [
            'driveId' => $driveId,
            'documentSaved' => $documentSaved,
            'documentName' => $documentName,
            'documentUrl' => $documentPath !== '' ? $this->publicDocumentUrl($driveId) : '',
            'updated' => 0,
            'failed' => 0,
            'alreadyShortlisted' => 0,
            'errors' => [],
            'shortlisted' => [],
        ];

        if ($rolls === []) {
            if (!$documentSaved) {
                Response::error('Upload a shortlist document and/or provide register numbers (CSV or list).', 422);
            }
            return $result;
        }

        $appModel = new ApplicationModel();
        $apps = $appModel->findByDrive($driveId);
        $appsByStudent = [];
        foreach ($apps as $app) {
            $sid = (string) ($app['studentId'] ?? '');
            if ($sid !== '') {
                $appsByStudent[$sid][] = $app;
            }
        }

        $studentModel = new StudentModel();
        $workflow = new ApplicationWorkflowService();
        $notifier = new NotificationService();
        $companyName = $this->driveCompanyLabel($drive);

        foreach ($rolls as $index => $roll) {
            $line = $index + 1;
            $student = $studentModel->findByRegisterNumber($roll);
            if (!$student) {
                $result['errors'][] = "Row {$line}: student \"{$roll}\" not found.";
                $result['failed']++;
                continue;
            }

            $studentApps = $appsByStudent[(string) $student['_id']] ?? [];
            if ($studentApps === []) {
                $result['errors'][] = "Row {$line}: \"{$roll}\" did not apply to this drive.";
                $result['failed']++;
                continue;
            }

            $rowUpdated = false;
            $already = false;
            foreach ($studentApps as $app) {
                $appId = (string) ($app['_id'] ?? '');
                $current = (string) ($app['status'] ?? '');
                if ($appId === '') {
                    continue;
                }
                if (in_array($current, ['shortlisted', 'selected'], true)) {
                    $already = true;
                    continue;
                }

                $ok = $workflow->transition(
                    $appId,
                    'shortlisted',
                    $byUserId,
                    'Shortlisted from company list uploaded by placement cell',
                    false
                );
                if (!$ok) {
                    // Force when intermediate states block transition (document is source of truth).
                    $ok = $workflow->forceStatus(
                        $appId,
                        'shortlisted',
                        $byUserId,
                        'Shortlisted from company list uploaded by placement cell'
                    );
                }
                if (!$ok) {
                    continue;
                }

                $rowUpdated = true;
                $userId = (string) ($student['userId'] ?? '');
                if ($userId !== '') {
                    $notifier->notifyApplicationUpdate(
                        $userId,
                        'Shortlisted',
                        'You have been shortlisted by ' . $companyName . ' for a campus drive. Check My applications for details.'
                    );
                }
            }

            if ($rowUpdated) {
                $result['updated']++;
                $result['shortlisted'][] = [
                    'registerNumber' => $roll,
                    'name' => (string) ($student['displayName'] ?? ''),
                ];
            } elseif ($already) {
                $result['alreadyShortlisted']++;
            } else {
                $result['errors'][] = "Row {$line}: could not shortlist \"{$roll}\".";
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @return array{path: string, filename: string}|null
     */
    public function documentForDrive(string $driveId, array $ctx): ?array
    {
        $drive = (new DriveModel())->findById($driveId);
        if (!$drive) {
            Response::notFound('Drive not found.');
        }
        if (!$ctx['isAdmin'] && !PlacementOfficerContext::driveMatchesDepartment($drive, $ctx)) {
            Response::forbidden('This drive is not for your department.');
        }

        $path = (string) ($drive['shortlistDocument'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => (string) ($drive['shortlistDocumentName'] ?? basename($path)),
        ];
    }

    private function publicDocumentUrl(string $driveId): string
    {
        return '/api/officer/drives/' . rawurlencode($driveId) . '/shortlist-document';
    }

    /**
     * @param array<string, mixed> $drive
     */
    private function driveCompanyLabel(array $drive): string
    {
        $companyId = (string) ($drive['companyId'] ?? '');
        if ($companyId !== '') {
            $company = (new \PMS\Models\CompanyModel())->findById($companyId);
            $name = is_array($company) ? trim((string) ($company['companyName'] ?? '')) : '';
            if ($name !== '') {
                return $name;
            }
        }
        $title = (string) ($drive['title'] ?? 'the company');
        if (str_contains($title, '—')) {
            return trim((string) (explode('—', $title, 2)[0] ?? $title));
        }
        return $title !== '' ? $title : 'the company';
    }

    /**
     * @return list<string>
     */
    private function extractRegisterNumbers(string $csvContent, string $registerList): array
    {
        $rolls = [];

        $csvContent = trim($csvContent);
        if ($csvContent !== '') {
            $rows = $this->parseCsvOrPlain($csvContent);
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $roll = $this->csvField($row, [
                        'register_number', 'registernumber', 'roll', 'roll_no', 'register',
                        'register_no', 'reg_no', 'admno', 'admission_no',
                    ]);
                    if ($roll === '' && count($row) === 1) {
                        $roll = trim((string) reset($row));
                    }
                } else {
                    $roll = trim((string) $row);
                }
                if ($roll !== '') {
                    $rolls[] = strtoupper($roll);
                }
            }
        }

        $registerList = trim($registerList);
        if ($registerList !== '') {
            $parts = preg_split('/[\s,;]+/', $registerList) ?: [];
            foreach ($parts as $part) {
                $roll = strtoupper(trim($part));
                if ($roll !== '') {
                    $rolls[] = $roll;
                }
            }
        }

        $unique = [];
        foreach ($rolls as $roll) {
            $unique[$roll] = true;
        }

        return array_keys($unique);
    }

    /**
     * @return array<int, array<string, string>|string>
     */
    private function parseCsvOrPlain(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }

        $first = str_getcsv($lines[0]);
        $looksHeader = false;
        foreach ($first as $cell) {
            $n = strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) $cell) ?? ''));
            if (in_array($n, ['register_number', 'roll', 'roll_no', 'register', 'reg_no', 'status'], true)) {
                $looksHeader = true;
                break;
            }
        }

        if ($looksHeader) {
            $headers = array_map(
                static fn ($h) => strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) $h) ?? '')),
                $first
            );
            array_shift($lines);
            $rows = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $cells = str_getcsv($line);
                $row = [];
                foreach ($headers as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $row[$key] = trim((string) ($cells[$i] ?? ''));
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // Plain list: one register number per line (or first CSV cell).
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $rows[] = trim((string) ($cells[0] ?? $line));
        }
        return $rows;
    }

    /**
     * @param array<string, string> $row
     * @param string[] $keys
     */
    private function csvField(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $normalized = strtolower(preg_replace('/[\s\-]+/', '_', $key) ?? $key);
            if (!empty($row[$normalized])) {
                return trim($row[$normalized]);
            }
        }
        return '';
    }
}
