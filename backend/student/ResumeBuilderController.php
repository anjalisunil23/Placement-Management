<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ResumeCareerObjectiveModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;

/**
 * Isolated Resume Builder APIs. Does not change student profile endpoints.
 */
final class ResumeBuilderController
{
    private StudentModel $studentModel;
    private ResumeCareerObjectiveModel $objectiveModel;

    public function __construct()
    {
        $this->studentModel = new StudentModel();
        $this->objectiveModel = new ResumeCareerObjectiveModel();
    }

    /** GET /api/student/resume-builder/career-objective */
    public function getCareerObjective(): void
    {
        $studentId = $this->currentStudentId();
        $row = $this->objectiveModel->findByStudentId($studentId);
        $text = is_array($row) ? trim((string) ($row['objective_text'] ?? '')) : '';
        Response::success([
            'objectiveText' => $text !== '' ? $text : null,
            'hasObjective' => $text !== '',
        ]);
    }

    /** PUT /api/student/resume-builder/career-objective */
    public function saveCareerObjective(): void
    {
        $studentId = $this->currentStudentId();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        $text = (string) ($input['objectiveText'] ?? $input['objective_text'] ?? '');
        $check = ResumeCareerObjectiveModel::validateText($text);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }

        $row = $this->objectiveModel->upsertForStudent($studentId, $text);
        $saved = trim((string) ($row['objective_text'] ?? ''));
        Response::success([
            'objectiveText' => $saved,
            'hasObjective' => $saved !== '',
        ], 'Career objective saved.');
    }

    private function currentStudentId(): string
    {
        $user = RBACMiddleware::requireStudent();
        $profile = $this->studentModel->findByUserId((string) ($user['_id'] ?? ''));
        $id = is_array($profile) ? (string) ($profile['_id'] ?? '') : '';
        if ($id === '') {
            Response::notFound('Student profile not found. Please sign in again with your college account.');
        }
        return $id;
    }
}
