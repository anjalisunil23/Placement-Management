<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ResumeModel;
use PMS\Utils\Security;

/**
 * Shared helpers for drive application submissions (resume + optional certificates).
 */
final class ApplicationUploadService
{
    /**
     * @return array<string, mixed>
     */
    public function parseApplyInput(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'multipart/form-data')) {
            return $_POST;
        }

        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    public function resolveResume(array $input, array $profile, string $studentId): ?array
    {
        $resumeId = (string) ($input['resumeId'] ?? '');
        $resume = null;

        if ($resumeId !== '') {
            $resumeDoc = (new ResumeModel())->findById($resumeId);
            if ($resumeDoc && (string) ($resumeDoc['studentId'] ?? '') === $studentId) {
                $storedName = (string) ($resumeDoc['storedName'] ?? '');
                $path = (new ObjectStorageService())->uri(ObjectStorageService::FOLDER_RESUMES, $storedName);
                $resume = [
                    'resumeId' => $resumeId,
                    'label'    => (string) ($resumeDoc['label'] ?? ''),
                    'fileName' => (string) ($resumeDoc['fileName'] ?? $storedName),
                    'path'     => $path,
                ];
            }
        }

        if ($resume === null && (!empty($input['resumePath']) || !empty($input['resumeFileName']))) {
            $resume = [
                'resumeId'   => $resumeId,
                'label'      => (string) ($input['resumeLabel'] ?? ''),
                'fileName'   => (string) ($input['resumeFileName'] ?? ''),
                'path'       => (string) ($input['resumePath'] ?? ''),
            ];
        } elseif ($resume === null && !empty($profile['resume']['path'])) {
            $resume = [
                'resumeId' => '',
                'label'    => 'Uploaded resume',
                'fileName' => (string) ($profile['resume']['filename'] ?? basename((string) $profile['resume']['path'])),
                'path'     => (string) $profile['resume']['path'],
            ];
        }

        return $resume;
    }

    /**
     * @return list<array{fileName:string,path:string,label:string}>
     */
    public function storeCertificates(string $registerNumber): array
    {
        if (!isset($_FILES['certificates'])) {
            return [];
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $allowed = Security::allowedCertificateExtensions();
        $maxSize = (int) ($config['uploads']['max_certificate'] ?? $config['uploads']['max_resume'] ?? 5242880);
        $files = $this->normalizeUploadedFiles($_FILES['certificates']);
        $stored = [];
        $safeReg = preg_replace('/[^a-z0-9]/i', '', $registerNumber) ?: 'applicant';
        $storage = new ObjectStorageService($config);

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $error = Security::validateUploadedFile($file, $maxSize, $allowed);
            if ($error !== null) {
                throw new \RuntimeException($error);
            }

            $original = basename((string) ($file['name'] ?? 'certificate'));
            $filename = $safeReg . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $original;
            try {
                $path = $storage->putUploadedFile(
                    ObjectStorageService::FOLDER_CERTIFICATES,
                    $filename,
                    $file
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to save certificate to S3: ' . $e->getMessage());
            }

            $stored[] = [
                'fileName' => $original,
                'path'     => $path,
                'label'    => pathinfo($original, PATHINFO_FILENAME),
            ];
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $fileField
     * @return list<array<string, mixed>>
     */
    private function normalizeUploadedFiles(array $fileField): array
    {
        if (!is_array($fileField['name'] ?? null)) {
            return [$fileField];
        }

        $normalized = [];
        $count = count($fileField['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name'     => $fileField['name'][$i] ?? '',
                'type'     => $fileField['type'][$i] ?? '',
                'tmp_name' => $fileField['tmp_name'][$i] ?? '',
                'error'    => $fileField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $fileField['size'][$i] ?? 0,
            ];
        }

        return $normalized;
    }
}
