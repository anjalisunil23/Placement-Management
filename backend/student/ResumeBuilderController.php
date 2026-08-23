<?php

declare(strict_types=1);

namespace PMS\Student;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ResumeActivityModel;
use PMS\Models\ResumeCareerObjectiveModel;
use PMS\Models\ResumeCertificationModel;
use PMS\Models\ResumeExperienceModel;
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
    private ResumeExperienceModel $experienceModel;
    private ResumeCertificationModel $certificationModel;
    private ResumeActivityModel $activityModel;

    public function __construct()
    {
        $this->studentModel = new StudentModel();
        $this->objectiveModel = new ResumeCareerObjectiveModel();
        $this->skillModel = new ResumeSkillModel();
        $this->projectModel = new ResumeProjectModel();
        $this->experienceModel = new ResumeExperienceModel();
        $this->certificationModel = new ResumeCertificationModel();
        $this->activityModel = new ResumeActivityModel();
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

    /** GET /api/student/resume-builder/experience */
    public function listExperience(): void
    {
        $studentId = $this->currentStudentId();
        $rows = $this->experienceModel->listByStudentId($studentId);
        Response::success([
            'experiences' => array_map([$this, 'serializeExperience'], $rows),
            'count' => count($rows),
            'types' => ResumeExperienceModel::TYPES,
            'complete' => count($rows) >= ResumeExperienceModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeExperienceModel::RECOMMENDED_COUNT,
        ]);
    }

    /** POST /api/student/resume-builder/experience */
    public function addExperience(): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readExperienceInput();
        $check = ResumeExperienceModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->experienceModel->createForStudent($studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($this->experiencePayload($studentId, $row), 'Experience added.');
    }

    /** PUT /api/student/resume-builder/experience/{id} */
    public function updateExperience(string $id): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readExperienceInput();
        $check = ResumeExperienceModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->experienceModel->updateForStudent($id, $studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
        Response::success($this->experiencePayload($studentId, $row), 'Experience updated.');
    }

    /** POST /api/student/resume-builder/experience/{id}/delete */
    public function deleteExperience(string $id): void
    {
        $studentId = $this->currentStudentId();
        if (!$this->experienceModel->deleteForStudent($id, $studentId)) {
            Response::notFound('Experience not found.');
        }
        Response::success($this->experiencePayload($studentId), 'Experience removed.');
    }

    /**
     * @return array{
     *   organization_name: string,
     *   position_title: string,
     *   experience_type: string,
     *   location: string,
     *   description: string,
     *   start_date: string,
     *   end_date: string,
     *   currently_working: bool
     * }
     */
    private function readExperienceInput(): array
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        return [
            'organization_name' => (string) ($input['organizationName'] ?? $input['organization_name'] ?? ''),
            'position_title' => (string) ($input['positionTitle'] ?? $input['position_title'] ?? ''),
            'experience_type' => (string) ($input['experienceType'] ?? $input['experience_type'] ?? ''),
            'location' => (string) ($input['location'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'start_date' => (string) ($input['startDate'] ?? $input['start_date'] ?? ''),
            'end_date' => (string) ($input['endDate'] ?? $input['end_date'] ?? ''),
            'currently_working' => ResumeExperienceModel::parseCurrentlyWorking($input),
        ];
    }

    /**
     * @param array<string, mixed>|null $changed
     * @return array<string, mixed>
     */
    private function experiencePayload(string $studentId, ?array $changed = null): array
    {
        $rows = $this->experienceModel->listByStudentId($studentId);
        return [
            'experience' => $changed ? $this->serializeExperience($changed) : null,
            'experiences' => array_map([$this, 'serializeExperience'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeExperienceModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeExperienceModel::RECOMMENDED_COUNT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: string,
     *   organizationName: string,
     *   positionTitle: string,
     *   experienceType: string,
     *   location: string|null,
     *   description: string,
     *   startDate: string,
     *   endDate: string|null,
     *   currentlyWorking: bool
     * }
     */
    private function serializeExperience(array $row): array
    {
        $location = trim((string) ($row['location'] ?? ''));
        $end = (string) ($row['end_date'] ?? '');
        $currently = (int) ($row['currently_working'] ?? 0) === 1;
        return [
            'id' => (string) ($row['id'] ?? ''),
            'organizationName' => (string) ($row['organization_name'] ?? ''),
            'positionTitle' => (string) ($row['position_title'] ?? ''),
            'experienceType' => (string) ($row['experience_type'] ?? ''),
            'location' => $location !== '' ? $location : null,
            'description' => (string) ($row['description'] ?? ''),
            'startDate' => (string) ($row['start_date'] ?? ''),
            'endDate' => $currently || $end === '' ? null : $end,
            'currentlyWorking' => $currently,
        ];
    }

    /** GET /api/student/resume-builder/certifications */
    public function listCertifications(): void
    {
        $studentId = $this->currentStudentId();
        $rows = $this->certificationModel->listByStudentId($studentId);
        Response::success([
            'certifications' => array_map([$this, 'serializeCertification'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeCertificationModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeCertificationModel::RECOMMENDED_COUNT,
        ]);
    }

    /** POST /api/student/resume-builder/certifications */
    public function addCertification(): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readCertificationInput();
        $check = ResumeCertificationModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->certificationModel->createForStudent($studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($this->certificationsPayload($studentId, $row), 'Certification added.');
    }

    /** PUT /api/student/resume-builder/certifications/{id} */
    public function updateCertification(string $id): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readCertificationInput();
        $check = ResumeCertificationModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->certificationModel->updateForStudent($id, $studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
        Response::success($this->certificationsPayload($studentId, $row), 'Certification updated.');
    }

    /** POST /api/student/resume-builder/certifications/{id}/delete */
    public function deleteCertification(string $id): void
    {
        $studentId = $this->currentStudentId();
        if (!$this->certificationModel->deleteForStudent($id, $studentId)) {
            Response::notFound('Certification not found.');
        }
        Response::success($this->certificationsPayload($studentId), 'Certification removed.');
    }

    /**
     * @return array{
     *   certification_name: string,
     *   issuing_organization: string,
     *   issue_date: string,
     *   expiry_date: string,
     *   credential_id: string,
     *   credential_url: string,
     *   description: string
     * }
     */
    private function readCertificationInput(): array
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        return [
            'certification_name' => (string) ($input['certificationName'] ?? $input['certification_name'] ?? ''),
            'issuing_organization' => (string) ($input['issuingOrganization'] ?? $input['issuing_organization'] ?? ''),
            'issue_date' => (string) ($input['issueDate'] ?? $input['issue_date'] ?? ''),
            'expiry_date' => (string) ($input['expiryDate'] ?? $input['expiry_date'] ?? ''),
            'credential_id' => (string) ($input['credentialId'] ?? $input['credential_id'] ?? ''),
            'credential_url' => (string) ($input['credentialUrl'] ?? $input['credential_url'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $changed
     * @return array<string, mixed>
     */
    private function certificationsPayload(string $studentId, ?array $changed = null): array
    {
        $rows = $this->certificationModel->listByStudentId($studentId);
        return [
            'certification' => $changed ? $this->serializeCertification($changed) : null,
            'certifications' => array_map([$this, 'serializeCertification'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeCertificationModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeCertificationModel::RECOMMENDED_COUNT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: string,
     *   certificationName: string,
     *   issuingOrganization: string,
     *   issueDate: string,
     *   expiryDate: string|null,
     *   credentialId: string|null,
     *   credentialUrl: string|null,
     *   description: string|null
     * }
     */
    private function serializeCertification(array $row): array
    {
        $credentialId = trim((string) ($row['credential_id'] ?? ''));
        $credentialUrl = trim((string) ($row['credential_url'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $expiry = (string) ($row['expiry_date'] ?? '');
        return [
            'id' => (string) ($row['id'] ?? ''),
            'certificationName' => (string) ($row['certification_name'] ?? ''),
            'issuingOrganization' => (string) ($row['issuing_organization'] ?? ''),
            'issueDate' => (string) ($row['issue_date'] ?? ''),
            'expiryDate' => $expiry !== '' ? $expiry : null,
            'credentialId' => $credentialId !== '' ? $credentialId : null,
            'credentialUrl' => $credentialUrl !== '' ? $credentialUrl : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    /** GET /api/student/resume-builder/activities */
    public function listActivities(): void
    {
        $studentId = $this->currentStudentId();
        $rows = $this->activityModel->listByStudentId($studentId);
        Response::success([
            'activities' => array_map([$this, 'serializeActivity'], $rows),
            'count' => count($rows),
            'types' => ResumeActivityModel::TYPES,
            'complete' => count($rows) >= ResumeActivityModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeActivityModel::RECOMMENDED_COUNT,
        ]);
    }

    /** POST /api/student/resume-builder/activities */
    public function addActivity(): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readActivityInput();
        $check = ResumeActivityModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->activityModel->createForStudent($studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success($this->activitiesPayload($studentId, $row), 'Activity added.');
    }

    /** PUT /api/student/resume-builder/activities/{id} */
    public function updateActivity(string $id): void
    {
        $studentId = $this->currentStudentId();
        $input = $this->readActivityInput();
        $check = ResumeActivityModel::validate($input);
        if (!$check['ok']) {
            Response::error((string) $check['error'], 422);
        }
        try {
            $row = $this->activityModel->updateForStudent($id, $studentId, $input);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::notFound($e->getMessage());
        }
        Response::success($this->activitiesPayload($studentId, $row), 'Activity updated.');
    }

    /** POST /api/student/resume-builder/activities/{id}/delete */
    public function deleteActivity(string $id): void
    {
        $studentId = $this->currentStudentId();
        if (!$this->activityModel->deleteForStudent($id, $studentId)) {
            Response::notFound('Activity not found.');
        }
        Response::success($this->activitiesPayload($studentId), 'Activity removed.');
    }

    /**
     * @return array{
     *   title: string,
     *   activity_type: string,
     *   organization: string,
     *   description: string,
     *   activity_date: string
     * }
     */
    private function readActivityInput(): array
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            $input = [];
        }
        return [
            'title' => (string) ($input['title'] ?? ''),
            'activity_type' => (string) ($input['activityType'] ?? $input['activity_type'] ?? ''),
            'organization' => (string) ($input['organization'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'activity_date' => (string) ($input['activityDate'] ?? $input['activity_date'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $changed
     * @return array<string, mixed>
     */
    private function activitiesPayload(string $studentId, ?array $changed = null): array
    {
        $rows = $this->activityModel->listByStudentId($studentId);
        return [
            'activity' => $changed ? $this->serializeActivity($changed) : null,
            'activities' => array_map([$this, 'serializeActivity'], $rows),
            'count' => count($rows),
            'complete' => count($rows) >= ResumeActivityModel::COMPLETE_MIN_COUNT,
            'recommended' => count($rows) >= ResumeActivityModel::RECOMMENDED_COUNT,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id: string,
     *   title: string,
     *   activityType: string,
     *   organization: string|null,
     *   description: string,
     *   activityDate: string|null
     * }
     */
    private function serializeActivity(array $row): array
    {
        $org = trim((string) ($row['organization'] ?? ''));
        $activityDate = (string) ($row['activity_date'] ?? '');
        return [
            'id' => (string) ($row['id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'activityType' => (string) ($row['activity_type'] ?? ''),
            'organization' => $org !== '' ? $org : null,
            'description' => (string) ($row['description'] ?? ''),
            'activityDate' => $activityDate !== '' ? $activityDate : null,
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
