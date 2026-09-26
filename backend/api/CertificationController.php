<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\CertificationService;
use PMS\Services\ObjectStorageService;
use PMS\Utils\Response;

/**
 * Campus certification opportunities.
 *
 * GET    /api/certifications                         auth: admin, placement_officer, student
 * POST   /api/certifications                         auth: admin, placement_officer
 *        body: { name, url, dueDate, description?, visibility: "all"|"departments", departmentIds?: string[] }
 *        Placement officers are locked to their own department and cannot create or edit all-department
 *        or multi-department certifications. They can view those when their department is included.
 * Students only receive certifications whose visibility is all or whose departmentIds
 * include the department stored on their own student profile. A department id in the
 * request is ignored. Placement officers can view certifications for their department
 * and all-department certifications. They can create and edit only certifications
 * locked to their own department.
 * GET    /api/certifications/leaderboard             auth: admin, placement_officer, student
 * GET    /api/certifications/{id}                    auth: admin, placement_officer, student
 * PUT    /api/certifications/{id}                    auth: admin, placement_officer
 * DELETE /api/certifications/{id}                    auth: admin, placement_officer
 * GET    /api/certifications/{id}/completions        auth: admin, placement_officer
 * PUT    /api/certifications/{id}/completions/{studentId}
 *        body: { status: "pending" }                 auth: admin, placement_officer
 * POST   /api/certifications/{id}/proof              auth: student, multipart file field "file"
 * GET    /api/certifications/{id}/proof              auth: student (own proof)
 * GET    /api/certifications/{id}/proof/{studentId}  auth: owner student, admin, placement_officer
 *
 * Students receive derived status: available, overdue, or completed.
 * Stored completion is pending until a proof file exists, then completed.
 * Leaderboard counts completed rows that still have proof. It does not accept a score.
 */
final class CertificationController
{
    private CertificationService $service;

    public function __construct(?CertificationService $service = null)
    {
        $this->service = $service ?? new CertificationService();
    }

    public function index(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->listFor($user));
    }

    public function create(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->create($user, $this->body()), 'Certification created.', 201);
    }

    public function show(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->show($user, $id));
    }

    public function update(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->update($user, $id, $this->body()), 'Certification updated.');
    }

    public function delete(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->delete($user, $id);
        Response::success(null, 'Certification deleted.');
    }

    public function leaderboard(): void
    {
        $user = AuthMiddleware::authenticate();
        $role = AuthMiddleware::resolvedRole($user);
        if (!in_array($role, ['admin', 'placement_officer', 'student'], true)) {
            Response::forbidden('You do not have permission to view the certification leaderboard.');
        }
        Response::success($this->service->leaderboard());
    }

    public function completions(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->completions($user, $id));
    }

    public function updateCompletion(string $id, string $studentId): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $this->service->setCompletionStatus($user, $id, $studentId, (string) ($body['status'] ?? ''));
        Response::success(null, 'Certification completion updated.');
    }

    public function submitProof(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('Certificate file is required.', 422);
        }
        Response::success($this->service->submitProof($user, $id, $_FILES['file']), 'Certification proof saved.');
    }

    /** POST /api/certifications/{id}/remove — student removes their own uploaded proof. */
    public function removeOwnCompletion(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->removeOwnCompletion($user, $id);
        Response::success(null, 'Uploaded certificate removed.');
    }

    public function downloadOwnProof(string $id): void
    {
        $this->downloadProof($id, '');
    }

    public function downloadProof(string $id, string $studentId = ''): void
    {
        $user = AuthMiddleware::authenticate();
        $progress = $this->service->proofFor($user, $id, $studentId !== '' ? $studentId : null);
        $fileName = (string) ($progress['proofFileName'] ?? 'certificate');
        $storage = new ObjectStorageService();
        $mime = $storage->guessMime($fileName);
        if (!headers_sent()) {
            header_remove('Content-Type');
        }
        $storage->streamWithFallback(
            (string) $progress['proofPath'],
            $fileName,
            $mime,
            true,
            ObjectStorageService::FOLDER_CERTIFICATION_PROOFS
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
