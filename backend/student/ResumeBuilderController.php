<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ResumeCareerObjectiveModel;
use PMS\Models\ResumeProjectModel;
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
    private ResumeProjectModel $projectModel;

    public function __construct()
    {
        $this->studentModel = new StudentModel();
        $this->objectiveModel = new ResumeCareerObjectiveModel();
        $this->skillModel = new ResumeSkillModel();
        $this->projectModel = new ResumeProjectModel();
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

    /** GET /api/student/resume-builder/projects */
    public function listProjects(): void
    {
        $studentId = $this->currentStudentId();
        $rows = $this->projectModel->listByStudentId($studentId);
        Response::success([
            'projects' => array_map([$this, 'serializeProject'], $rows),
            'count' => count($rows),
            'types' => ResumeProjectModel::TYPES,
            'complete' => count($rows) >= ResumeProjectModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeProjectModel::RECOMMENDED_COUNT,
        ]);
    }

    /** POST /api/student/resume-builder/projects */
    public function addProject(): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readProjectInput();
        $check = ResumeProjectModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->projectModel->createForStudent($studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($this->projectsPayload($studentId, $row), 'Project added.');
    }

    /** PUT /api/student/resume-builder/projects/{id} */
    public function updateProject(string $id): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readProjectInput();
        $check = ResumeProjectModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->projectModel->updateForStudent($id, $studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
        Response::success($this->projectsPayload($studentId, $row), 'Project updated.');
    }

    /** POST /api/student/resume-builder/projects/{id}/delete */
    public function deleteProject(string $id): void
    {
        $studentId = $this->currentStudentId();
        if (!$this->projectModel->deleteForStudent($id, $studentId)) {
            Response::notFound('Project not found.');
        }
        Response::success($this->projectsPayload($studentId), 'Project removed.');
    }

    /**
     * @return array{
     *   project_title: string,
     *   project_type: string,
     *   technologies_used: string,
     *   project_description: string,
     *   project_link: string,
     *   start_date: string,
     *   end_date: string
     * }
     */
    private function readProjectInput(): array
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        return [
            'project_title' => (string) ($input['projectTitle'] ?? $input['project_title'] ?? ''),
            'project_type' => (string) ($input['projectType'] ?? $input['project_type'] ?? ''),
            'technologies_used' => (string) ($input['technologiesUsed'] ?? $input['technologies_used'] ?? ''),
            'project_description' => (string) ($input['projectDescription'] ?? $input['project_description'] ?? ''),
            'project_link' => (string) ($input['projectLink'] ?? $input['project_link'] ?? ''),
            'start_date' => (string) ($input['startDate'] ?? $input['start_date'] ?? ''),
            'end_date' => (string) ($input['endDate'] ?? $input['end_date'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $changed
     * @return array<string, mixed>
     */
    private function projectsPayload(string $studentId, ?array $changed = null): array
    {
        $rows = $this->projectModel->listByStudentId($studentId);
        return [
            'project' => $changed ? $this->serializeProject($changed) : null,
            'projects' => array_map([$this, 'serializeProject'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeProjectModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeProjectModel::RECOMMENDED_COUNT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: string,
     *   projectTitle: string,
     *   projectType: string,
     *   technologiesUsed: string,
     *   projectDescription: string,
     *   projectLink: string|null,
     *   startDate: string|null,
     *   endDate: string|null
     * }
     */
    private function serializeProject(array $row): array
    {
        $link = trim((string) ($row['project_link'] ?? ''));
        $start = (string) ($row['start_date'] ?? '');
        $end = (string) ($row['end_date'] ?? '');
        return [
            'id' => (string) ($row['id'] ?? ''),
            'projectTitle' => (string) ($row['project_title'] ?? ''),
            'projectType' => (string) ($row['project_type'] ?? ''),
            'technologiesUsed' => (string) ($row['technologies_used'] ?? ''),
            'projectDescription' => (string) ($row['project_description'] ?? ''),
            'projectLink' => $link !== '' ? $link : null,
            'startDate' => $start !== '' ? $start : null,
            'endDate' => $end !== '' ? $end : null,
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
