<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ResumeCareerObjectiveModel;
use PMS\Models\ResumeSkillModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;

/**
 * Isolated Resume Builder APIs. Does not change student profile endpoints.
 */
final class ResumeBuilderController
{
    private StudentModel $studentModel;
    private ResumeCareerObjectiveModel $objectiveModel;
    private ResumeSkillModel $skillModel;

    public function __construct()
    {
        $this->studentModel = new StudentModel();
        $this->objectiveModel = new ResumeCareerObjectiveModel();
        $this->skillModel = new ResumeSkillModel();
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

    /** GET /api/student/resume-builder/skills */
    public function listSkills(): void
    {
        $studentId = $this->currentStudentId();
        $rows = $this->skillModel->listByStudentId($studentId);
        Response::success([
            'skills' => array_map([$this, 'serializeSkill'], $rows),
            'count' => count($rows),
            'categories' => ResumeSkillModel::CATEGORIES,
            'complete' => count($rows) >= ResumeSkillModel::COMPLETE_MIN_COUNT,
        ]);
    }

    /** POST /api/student/resume-builder/skills */
    public function addSkill(): void
    {
        $studentId = $this->currentStudentId();
        [$name, $category] = $this->readSkillInput();
        $check = ResumeSkillModel::validate($name, $category);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->skillModel->createForStudent($studentId, $name, $category);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($this->skillsPayload($studentId, $row), 'Skill added.');
    }

    /** PUT /api/student/resume-builder/skills/{id} */
    public function updateSkill(string $id): void
    {
        $studentId = $this->currentStudentId();
        [$name, $category] = $this->readSkillInput();
        $check = ResumeSkillModel::validate($name, $category);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->skillModel->updateForStudent($id, $studentId, $name, $category);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
        Response::success($this->skillsPayload($studentId, $row), 'Skill updated.');
    }

    /** POST /api/student/resume-builder/skills/{id}/delete */
    public function deleteSkill(string $id): void
    {
        $studentId = $this->currentStudentId();
        if (!$this->skillModel->deleteForStudent($id, $studentId)) {
            Response::notFound('Skill not found.');
        }
        Response::success($this->skillsPayload($studentId), 'Skill removed.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readSkillInput(): array
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        $name = (string) ($input['skillName'] ?? $input['skill_name'] ?? '');
        $category = (string) ($input['skillCategory'] ?? $input['skill_category'] ?? '');
        return [ResumeSkillModel::normalizeName($name), $category];
    }

    /**
     * @param array<string, mixed>|null $changed
     * @return array<string, mixed>
     */
    private function skillsPayload(string $studentId, ?array $changed = null): array
    {
        $rows = $this->skillModel->listByStudentId($studentId);
        return [
            'skill' => $changed ? $this->serializeSkill($changed) : null,
            'skills' => array_map([$this, 'serializeSkill'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeSkillModel::COMPLETE_MIN_COUNT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, skillName: string, skillCategory: string}
     */
    private function serializeSkill(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'skillName' => (string) ($row['skill_name'] ?? ''),
            'skillCategory' => (string) ($row['skill_category'] ?? ''),
        ];
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
