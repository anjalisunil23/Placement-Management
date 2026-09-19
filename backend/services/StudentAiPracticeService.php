<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeTestModel;
use PMS\Models\StudentAiPracticeModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Student self-practice AI MCQ generation (not saved to official question bank).
 */
final class StudentAiPracticeService
{
    public const MAX_QUESTIONS = 25;
    private const MIN_COOLDOWN_SECONDS = 15;

    private AptitudeAiQuestionService $ai;
    private StudentJdService $jds;
    private StudentAiPracticeModel $sessions;

    public function __construct()
    {
        $this->ai = new AptitudeAiQuestionService();
        $this->jds = new StudentJdService();
        $this->sessions = new StudentAiPracticeModel();
    }

    /**
     * @param array<string, mixed> $studentProfile
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $studentProfile, array $body): array
    {
        if (!$this->ai->checkStatus()['configured']) {
            throw new \RuntimeException('AI practice is not configured on the server. Please try again later.');
        }

        $studentId = (string) ($studentProfile['_id'] ?? '');
        $this->assertCooldown($studentId);

        $mode = strtolower(trim((string) ($body['sourceMode'] ?? $body['mode'] ?? 'topic')));
        if (!in_array($mode, ['topic', 'jd', 'jd_topic'], true)) {
            throw new \InvalidArgumentException('Invalid question source mode.');
        }

        $topic = trim((string) ($body['topic'] ?? ''));
        $driveId = trim((string) ($body['jdDriveId'] ?? $body['driveId'] ?? ''));
        $difficulty = $this->normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium'));
        $count = $this->normalizeCount($body['count'] ?? $body['questionCount'] ?? 10);
        $instructions = trim((string) ($body['instructions'] ?? ''));
        $language = 'English';

        $jobDescription = '';
        $jdTitle = '';
        $companyName = '';

        if ($mode === 'topic' && $topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }
        if ($mode === 'jd' || $mode === 'jd_topic') {
            if ($driveId === '') {
                throw new \InvalidArgumentException('Select a published job description.');
            }
            $jdDetail = $this->jds->getPublishedJd($studentProfile, $driveId);
            $jobDescription = $this->jds->resolveJdTextForDrive($studentProfile, $driveId);
            $jdTitle = (string) ($jdDetail['jobTitle'] ?? '');
            $companyName = (string) ($jdDetail['companyName'] ?? '');
        }
        if ($mode === 'jd_topic' && $topic === '') {
            throw new \InvalidArgumentException('Topic is required for Topic + Job Description mode.');
        }

        $instr = $instructions !== '' ? $instructions : 'Generate questions suitable for campus placement preparation.';
        $generated = match ($mode) {
            'topic' => $this->generateByDifficulty(
                'topic',
                $topic,
                $jobDescription,
                $difficulty,
                $count,
                $language,
                $instr
            ),
            'jd' => $this->generateByDifficulty(
                'jd',
                $topic,
                $jobDescription,
                $difficulty,
                $count,
                $language,
                'Generate questions relevant to this job description for campus placement preparation. ' . $instr
            ),
            'jd_topic' => $this->generateByDifficulty(
                'jd_topic',
                $topic,
                $jobDescription,
                $difficulty,
                $count,
                $language,
                trim(
                    "Generate questions specifically about: {$topic}. "
                    . "Every question must relate to {$topic} as required by this Job Description. "
                    . $instr
                )
            ),
            default => throw new \InvalidArgumentException('Invalid mode.'),
        };

        $questions = $this->normalizePracticeQuestions($generated['questions'] ?? [], $mode, $topic);
        if ($questions === []) {
            throw new \RuntimeException('Unable to generate questions. Please try again.');
        }

        $sessionId = $this->sessions->createSession([
            'studentId' => Security::toObjectId($studentId),
            'sourceMode' => $mode,
            'topic' => $topic,
            'jdDriveId' => $driveId,
            'jdTitle' => $jdTitle,
            'companyName' => $companyName,
            'difficulty' => $difficulty,
            'questionCount' => count($questions),
            'questions' => $questions,
            'status' => 'in_progress',
            'score' => 0,
            'analysis' => [],
        ]);

        $this->touchCooldown($studentId);

        return [
            'sessionId' => $sessionId,
            'sourceMode' => $mode,
            'topic' => $topic,
            'jdTitle' => $jdTitle,
            'companyName' => $companyName,
            'difficulty' => $difficulty,
            'questionCount' => count($questions),
            'questions' => $this->stripAnswers($questions),
        ];
    }

    /**
     * @param array<string, mixed> $studentProfile
     * @param array<int, mixed> $answers
     * @return array<string, mixed>
     */
    public function submit(array $studentProfile, string $sessionId, array $answers): array
    {
        $studentId = (string) ($studentProfile['_id'] ?? '');
        $row = $this->sessions->findForStudent($sessionId, $studentId);
        if ($row === null) {
            Response::notFound('Practice session not found.');
        }
        if ((string) ($row['status'] ?? '') === 'completed') {
            return $this->sessions->detailView($row);
        }

        $questions = array_values((array) ($row['questions'] ?? []));
        $analysis = [];
        $score = 0;

        foreach ($questions as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $qid = (string) ($q['id'] ?? 'q' . ($i + 1));
            $picked = null;
            foreach ($answers as $ans) {
                if (!is_array($ans)) {
                    continue;
                }
                if ((string) ($ans['questionId'] ?? '') === $qid) {
                    $picked = isset($ans['selectedIndex']) ? (int) $ans['selectedIndex'] : null;
                    break;
                }
            }
            if ($picked === null && isset($answers[$i])) {
                $picked = is_array($answers[$i])
                    ? (int) ($answers[$i]['selectedIndex'] ?? -1)
                    : (int) $answers[$i];
            }

            $correct = (int) ($q['correctIndex'] ?? 0);
            $isCorrect = $picked !== null && $picked >= 0 && $picked === $correct;
            if ($isCorrect) {
                $score++;
            }

            $analysis[] = [
                'questionId' => $qid,
                'question' => (string) ($q['prompt'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'studentAnswerIndex' => $picked,
                'correctAnswerIndex' => $correct,
                'status' => $isCorrect ? 'correct' : ($picked === null || $picked < 0 ? 'unanswered' : 'incorrect'),
                'explanation' => (string) ($q['explanation'] ?? ''),
                'topic' => (string) ($q['topic'] ?? ''),
                'difficulty' => (string) ($q['difficulty'] ?? ''),
            ];
        }

        $total = count($questions);
        $updated = array_merge($row, [
            'status' => 'completed',
            'score' => $score,
            'analysis' => $analysis,
            'completedAt' => gmdate('c'),
        ]);
        $this->sessions->update($sessionId, $updated);

        $saved = $this->sessions->findById($sessionId);

        return $this->sessions->detailView($saved ?? $updated);
    }

    /**
     * @param array<string, mixed> $studentProfile
     * @return array<string, mixed>
     */
    public function listHistory(array $studentProfile): array
    {
        $studentId = (string) ($studentProfile['_id'] ?? '');

        return [
            'sessions' => $this->sessions->listForStudent($studentId),
        ];
    }

    /**
     * @param array<string, mixed> $studentProfile
     * @return array<string, mixed>
     */
    public function getSession(array $studentProfile, string $sessionId): array
    {
        $studentId = (string) ($studentProfile['_id'] ?? '');
        $row = $this->sessions->findForStudent($sessionId, $studentId);
        if ($row === null) {
            Response::notFound('Practice session not found.');
        }

        $view = $this->sessions->detailView($row);
        if (($row['status'] ?? '') === 'in_progress') {
            $view['questions'] = $this->stripAnswers(array_values((array) ($row['questions'] ?? [])));
        }

        return $view;
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function normalizePracticeQuestions(array $raw, string $mode, string $topic): array
    {
        $out = [];
        foreach (array_values($raw) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, 'General Aptitude', $i);
            if ($norm === null) {
                continue;
            }
            if ($mode === 'topic' && $topic !== '') {
                $norm['topic'] = $topic;
            }
            $norm['id'] = 'sp-' . ($i + 1) . '-' . bin2hex(random_bytes(3));
            $norm['source'] = 'STUDENT_PRACTICE';
            $out[] = $norm;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    private function stripAnswers(array $questions): array
    {
        $out = [];
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($q['id'] ?? ''),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'topic' => (string) ($q['topic'] ?? ''),
                'difficulty' => (string) ($q['difficulty'] ?? ''),
                'category' => (string) ($q['category'] ?? 'General Aptitude'),
            ];
        }

        return $out;
    }

    private function normalizeCount(mixed $raw): int
    {
        $count = (int) $raw;
        if ($count < 1) {
            throw new \InvalidArgumentException('Number of questions must be at least 1.');
        }
        if ($count > self::MAX_QUESTIONS) {
            throw new \InvalidArgumentException('Maximum ' . self::MAX_QUESTIONS . ' questions per practice session.');
        }

        return $count;
    }

    private function normalizeDifficulty(string $difficulty): string
    {
        $raw = trim($difficulty);
        if (strcasecmp($raw, 'Mixed') === 0) {
            return 'Mixed';
        }

        return AptitudeTestModel::normalizeDifficulty($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateByDifficulty(
        string $mode,
        string $topic,
        string $jobDescription,
        string $difficulty,
        int $count,
        string $language,
        string $instructions
    ): array {
        if ($difficulty !== 'Mixed') {
            return $this->generateSingleBatch($mode, $topic, $jobDescription, $difficulty, $count, $language, $instructions);
        }

        $easy = (int) floor($count * 0.35);
        $hard = (int) floor($count * 0.25);
        $medium = max(0, $count - $easy - $hard);
        $merged = [];
        foreach (['Easy' => $easy, 'Medium' => $medium, 'Hard' => $hard] as $diff => $n) {
            if ($n <= 0) {
                continue;
            }
            $batch = $this->generateSingleBatch($mode, $topic, $jobDescription, $diff, $n, $language, $instructions);
            foreach ($batch['questions'] ?? [] as $q) {
                $merged[] = $q;
            }
        }

        return ['questions' => array_slice($merged, 0, $count)];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSingleBatch(
        string $mode,
        string $topic,
        string $jobDescription,
        string $difficulty,
        int $count,
        string $language,
        string $instructions
    ): array {
        return match ($mode) {
            'topic' => $this->ai->generate(
                'General Aptitude',
                $topic,
                $difficulty,
                $count,
                1.0,
                $language,
                $instructions
            ),
            'jd' => $this->ai->generateFromJd(
                $jobDescription,
                $difficulty,
                $count,
                1.0,
                $language,
                $instructions
            ),
            'jd_topic' => $this->ai->generateFromJd(
                $jobDescription,
                $difficulty,
                $count,
                1.0,
                $language,
                $instructions
            ),
            default => throw new \InvalidArgumentException('Invalid mode.'),
        };
    }

    private function assertCooldown(string $studentId): void
    {
        if ($studentId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_stu_ai_cd_' . hash('sha256', $studentId) . '.txt';
        if (!is_readable($path)) {
            return;
        }
        $last = (int) trim((string) file_get_contents($path));
        if ($last > 0 && (time() - $last) < self::MIN_COOLDOWN_SECONDS) {
            throw new \RuntimeException('Please wait a few seconds before generating again.');
        }
    }

    private function touchCooldown(string $studentId): void
    {
        if ($studentId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_stu_ai_cd_' . hash('sha256', $studentId) . '.txt';
        file_put_contents($path, (string) time());
    }
}
