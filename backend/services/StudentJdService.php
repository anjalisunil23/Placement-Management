<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CompanyModel;
use PMS\Models\DriveModel;
use PMS\Utils\DocumentHelper;

/**
 * Published placement drive JDs visible to students.
 */
final class StudentJdService
{
    /**
     * @param array<string, mixed> $studentProfile
     * @return list<array<string, mixed>>
     */
    public function listPublishedJds(array $studentProfile): array
    {
        $engine = new EligibilityEngine();
        $driveModel = new DriveModel();
        $companyModel = new CompanyModel();
        $storage = new ObjectStorageService();

        $drives = $driveModel->findAll([], 300, 0, ['date' => -1, 'createdAt' => -1]);
        $out = [];

        foreach ($drives as $drive) {
            if (!$this->isPublishedJdDrive($drive)) {
                continue;
            }
            if (!$engine->driveVisibleToStudent($studentProfile, $drive)) {
                continue;
            }

            $id = (string) ($drive['_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $company = !empty($drive['companyId'])
                ? $companyModel->findById((string) $drive['companyId'])
                : null;
            $companyName = is_array($company) ? (string) ($company['companyName'] ?? '') : '';

            $docMeta = $this->resolveDocumentMeta($storage, $drive);
            $skills = $this->inferSkills($drive, $docMeta['extractedText'] ?? '');

            $out[] = [
                'id' => $id,
                'driveId' => $id,
                'jobTitle' => (string) ($drive['title'] ?? 'Role'),
                'companyName' => $companyName,
                'skills' => $skills,
                'postedDate' => (string) ($drive['createdAt'] ?? $drive['date'] ?? ''),
                'applicationDeadline' => DriveLifecycle::registrationDeadline($drive) ?: null,
                'recruitmentDate' => (string) ($drive['date'] ?? ''),
                'status' => DriveLifecycle::effectiveStatus($drive),
                'registrationOpen' => DriveLifecycle::isOpenForStudents($drive),
                'hasDocument' => $docMeta['hasDocument'],
                'jdFileUrl' => $docMeta['jdFileUrl'],
                'jdMimeType' => $docMeta['jdMimeType'],
                'description' => trim((string) ($drive['description'] ?? '')),
                'documentUrl' => '/backend/api/student/jds/' . rawurlencode($id) . '/document',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $studentProfile
     * @return array<string, mixed>
     */
    public function getPublishedJd(array $studentProfile, string $driveId): array
    {
        $drive = (new DriveModel())->findById($driveId);
        if ($drive === null || !$this->isPublishedJdDrive($drive)) {
            throw new \RuntimeException('Job description not found.', 404);
        }
        if (!(new EligibilityEngine())->driveVisibleToStudent($studentProfile, $drive)) {
            throw new \RuntimeException('Job description not found.', 404);
        }

        $company = !empty($drive['companyId'])
            ? (new CompanyModel())->findById((string) $drive['companyId'])
            : null;
        $storage = new ObjectStorageService();
        $docMeta = $this->resolveDocumentMeta($storage, $drive, true);

        return [
            'id' => (string) ($drive['_id'] ?? ''),
            'driveId' => (string) ($drive['_id'] ?? ''),
            'jobTitle' => (string) ($drive['title'] ?? 'Role'),
            'companyName' => is_array($company) ? (string) ($company['companyName'] ?? '') : '',
            'skills' => $this->inferSkills($drive, $docMeta['extractedText'] ?? ''),
            'postedDate' => (string) ($drive['createdAt'] ?? $drive['date'] ?? ''),
            'applicationDeadline' => DriveLifecycle::registrationDeadline($drive) ?: null,
            'recruitmentDate' => (string) ($drive['date'] ?? ''),
            'status' => DriveLifecycle::effectiveStatus($drive),
            'registrationOpen' => DriveLifecycle::isOpenForStudents($drive),
            'hasDocument' => $docMeta['hasDocument'],
            'jdFileUrl' => $docMeta['jdFileUrl'],
            'jdMimeType' => $docMeta['jdMimeType'],
            'documentUrl' => '/backend/api/student/jds/' . rawurlencode((string) ($drive['_id'] ?? '')) . '/document',
            'description' => trim((string) ($drive['description'] ?? '')),
            'extractedText' => $docMeta['extractedText'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $studentProfile
     */
    public function streamJdDocument(array $studentProfile, string $driveId): void
    {
        $drive = (new DriveModel())->findById($driveId);
        if ($drive === null || !$this->isPublishedJdDrive($drive)) {
            \PMS\Utils\Response::notFound('Document not found.');
        }
        if (!(new EligibilityEngine())->driveVisibleToStudent($studentProfile, $drive)) {
            \PMS\Utils\Response::notFound('Document not found.');
        }

        $fileUri = trim((string) ($drive['jdFile'] ?? ''));
        if ($fileUri === '') {
            \PMS\Utils\Response::notFound('No document attached to this job description.');
        }

        $filename = basename(parse_url($fileUri, PHP_URL_PATH) ?: 'job-description.pdf');
        if ($filename === '' || $filename === '/') {
            $filename = preg_replace('/[^\w\-]+/', '-', (string) ($drive['title'] ?? 'jd')) . '.pdf';
        }

        $storage = new ObjectStorageService();
        $mime = $storage->guessMime($filename);
        try {
            $storage->streamWithFallback($fileUri, $filename, $mime, true, ObjectStorageService::FOLDER_JD);
        } catch (\Throwable) {
            \PMS\Utils\Response::notFound('Document not found.');
        }
    }

    /**
     * @param array<string, mixed> $studentProfile
     */
    public function resolveJdTextForDrive(array $studentProfile, string $driveId): string
    {
        $detail = $this->getPublishedJd($studentProfile, $driveId);
        $text = trim((string) ($detail['extractedText'] ?? ''));
        if ($text === '') {
            $text = (new JdTextExtractionService())->sanitizeText((string) ($detail['description'] ?? ''));
        }
        if (mb_strlen($text) < 40) {
            throw new \InvalidArgumentException(
                'This Job Description does not contain enough readable information to generate relevant questions.'
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $drive
     */
    private function isPublishedJdDrive(array $drive): bool
    {
        $status = strtolower(trim((string) ($drive['status'] ?? 'scheduled')));
        if (in_array($status, ['draft', 'cancelled', 'deleted'], true)) {
            return false;
        }

        $jdFile = trim((string) ($drive['jdFile'] ?? ''));
        $description = trim((string) ($drive['description'] ?? ''));

        return $jdFile !== '' || $description !== '';
    }

    /**
     * @param array<string, mixed> $drive
     * @return array{hasDocument:bool,jdFileUrl:string,jdMimeType:string,extractedText?:string}
     */
    private function resolveDocumentMeta(ObjectStorageService $storage, array $drive, bool $withText = false): array
    {
        $fileUri = trim((string) ($drive['jdFile'] ?? ''));
        if ($fileUri === '') {
            $description = trim((string) ($drive['description'] ?? ''));

            return [
                'hasDocument' => false,
                'jdFileUrl' => '',
                'jdMimeType' => '',
                'extractedText' => $withText ? (new JdTextExtractionService())->sanitizeText($description) : '',
            ];
        }

        $resolved = $storage->resolve($fileUri);
        $folder = (string) ($resolved['folder'] ?? ObjectStorageService::FOLDER_JD);
        $filename = (string) ($resolved['filename'] ?? basename($fileUri));
        $mime = $storage->guessMime($filename);

        $meta = [
            'hasDocument' => true,
            'jdFileUrl' => $storage->mediaUrl($folder, $filename),
            'jdMimeType' => $mime,
        ];

        if ($withText) {
            try {
                $extractor = new JdTextExtractionService();
                $meta['extractedText'] = $extractor->extractTextFromStoredUri($fileUri, $mime);
            } catch (\Throwable $e) {
                error_log('[PMS Student JD] text extract failed: ' . $e->getMessage());
                $meta['extractedText'] = (new JdTextExtractionService())->sanitizeText(
                    (string) ($drive['description'] ?? '')
                );
            }
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $drive
     * @return list<string>
     */
    private function inferSkills(array $drive, string $jdText): array
    {
        $skills = [];
        $eligibility = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
        foreach (['skills', 'requiredSkills', 'technologies'] as $key) {
            $raw = $eligibility[$key] ?? null;
            if (is_string($raw)) {
                foreach (preg_split('/[,;|]/', $raw) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $skills[] = $part;
                    }
                }
            } elseif (is_array($raw)) {
                foreach ($raw as $item) {
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $skills[] = $item;
                    }
                }
            }
        }

        if ($skills === [] && $jdText !== '') {
            $common = [
                'Java', 'Python', 'JavaScript', 'TypeScript', 'SQL', 'React', 'Node.js', 'Spring',
                'Django', 'REST API', 'OOP', 'Data Structures', 'DBMS', 'C++', 'C#', '.NET',
                'Angular', 'Vue', 'PostgreSQL', 'MySQL', 'MongoDB', 'AWS', 'Docker', 'Kubernetes',
                'Git', 'Testing', 'Debugging', 'Algorithms',
            ];
            $lower = strtolower($jdText);
            foreach ($common as $skill) {
                if (str_contains($lower, strtolower($skill))) {
                    $skills[] = $skill;
                }
            }
        }

        $skills = array_values(array_unique(array_slice($skills, 0, 12)));

        return $skills;
    }
}
