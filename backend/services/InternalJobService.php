<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Field rules and display labels for internal part-time jobs and internships.
 * Department and role checks live in the controller; this class stays free of the database.
 */
final class InternalJobService
{
    public const PART_TIME = 'part_time';
    public const INTERNSHIP = 'internship';

    public const UNPAID = 'unpaid';
    public const FEE = 'fee';
    public const STIPEND = 'stipend';

    /** @var string[] */
    public const WORK_MODES = ['online', 'offline'];

    /** @var string[] */
    public const FREQUENCIES = ['monthly', 'weekly', 'one_time'];

    /** @var string[] */
    public const STATUSES = ['draft', 'published', 'closed'];

    public static function normalizeJobType(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = str_replace(['-', ' '], '_', $value);
        if (in_array($value, ['parttime', 'part_time_job', 'part_time'], true)) {
            return self::PART_TIME;
        }
        if (in_array($value, ['internship', 'intern'], true)) {
            return self::INTERNSHIP;
        }

        return '';
    }

    public static function normalizeWorkMode(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = str_replace(['-', ' '], '_', $value);
        if (in_array($value, ['online', 'remote'], true)) {
            return 'online';
        }
        if (in_array($value, ['offline', 'on_site', 'onsite'], true)) {
            return 'offline';
        }

        return '';
    }

    public static function normalizeCompensationType(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = str_replace(['-', ' ', '/'], '_', $value);
        if (in_array($value, ['free', 'unpaid', 'free_unpaid', 'free___unpaid'], true)) {
            return self::UNPAID;
        }
        if (in_array($value, ['fee', 'fee_based', 'feebased'], true)) {
            return self::FEE;
        }
        if ($value === 'stipend') {
            return self::STIPEND;
        }

        return '';
    }

    public static function normalizeFrequency(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = str_replace(['-', ' '], '_', $value);
        if ($value === 'onetime') {
            $value = 'one_time';
        }

        return in_array($value, self::FREQUENCIES, true) ? $value : '';
    }

    public static function jobTypeLabel(string $jobType): string
    {
        return $jobType === self::INTERNSHIP ? 'Internship' : 'Part-Time Job';
    }

    public static function workModeLabel(string $mode): string
    {
        return match ($mode) {
            'online', 'remote' => 'Online',
            'offline', 'on_site' => 'Offline',
            default => '',
        };
    }

    public static function compensationTypeLabel(string $type): string
    {
        return match ($type) {
            self::UNPAID => 'Free / Unpaid',
            self::FEE => 'Fee-Based',
            self::STIPEND => 'Stipend',
            default => '',
        };
    }

    public static function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            'monthly' => 'Monthly',
            'weekly' => 'Weekly',
            'one_time' => 'One-time',
            default => '',
        };
    }

    /**
     * Officers are locked to their department. Admins keep the requested id.
     */
    public static function resolveDepartmentId(string $requested, ?string $lockedDepartmentId): string
    {
        if ($lockedDepartmentId !== null && trim($lockedDepartmentId) !== '') {
            return trim($lockedDepartmentId);
        }

        return trim($requested);
    }

    public static function departmentMatches(array $post, string $departmentId, string $departmentCode): bool
    {
        $postId = trim((string) ($post['departmentId'] ?? ''));
        $departmentId = trim($departmentId);
        if ($departmentId !== '' && $postId !== '' && strcasecmp($postId, $departmentId) === 0) {
            return true;
        }
        $code = strtoupper(trim($departmentCode));
        $postCode = strtoupper(trim((string) ($post['departmentCode'] ?? '')));

        return $code !== '' && $postCode !== '' && $code === $postCode;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,message:string,errors:string[],data:array<string,mixed>}
     */
    public static function validate(array $input, bool $forPublish): array
    {
        $errors = [];
        $jobType = self::normalizeJobType((string) ($input['jobType'] ?? ''));
        if ($jobType === '') {
            $errors[] = 'Select a job type: Part-Time Job or Internship.';
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors[] = $jobType === self::INTERNSHIP
                ? 'Internship title is required.'
                : 'Job title is required.';
        }

        $company = trim((string) ($input['company'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $skills = trim((string) ($input['requiredSkills'] ?? ''));
        $eligibility = trim((string) ($input['eligibilityCriteria'] ?? ''));
        $location = trim((string) ($input['workLocation'] ?? ''));
        $workMode = self::normalizeWorkMode((string) ($input['workMode'] ?? ''));
        $startDate = self::normalizeDate((string) ($input['startDate'] ?? ''));
        $endDate = self::normalizeDate((string) ($input['endDate'] ?? ''));
        $duration = trim((string) ($input['duration'] ?? ''));
        $hours = trim((string) ($input['workingHours'] ?? ''));
        $deadline = self::normalizeDate((string) ($input['applicationDeadline'] ?? ''));
        $contact = trim((string) ($input['contactInformation'] ?? ''));
        $extra = trim((string) ($input['additionalRequirements'] ?? ''));
        $departmentId = trim((string) ($input['departmentId'] ?? ''));
        $vacanciesRaw = trim((string) ($input['vacancies'] ?? ''));
        $vacancies = $vacanciesRaw === '' ? 0 : (int) $vacanciesRaw;
        $minCgpa = self::normalizeCgpa($input['minCgpa'] ?? '');

        $stipend = trim((string) ($input['stipend'] ?? ''));
        $compensationType = self::normalizeCompensationType((string) ($input['compensationType'] ?? ''));
        $compensationAmount = trim((string) ($input['compensationAmount'] ?? ''));
        $frequency = self::normalizeFrequency((string) ($input['paymentFrequency'] ?? ''));

        if (trim((string) ($input['startDate'] ?? '')) !== '' && $startDate === '') {
            $errors[] = 'Start date is not a valid date.';
        }
        if (trim((string) ($input['endDate'] ?? '')) !== '' && $endDate === '') {
            $errors[] = 'End date is not a valid date.';
        }
        if (trim((string) ($input['applicationDeadline'] ?? '')) !== '' && $deadline === '') {
            $errors[] = 'Application deadline is not a valid date.';
        }
        if (trim((string) ($input['workMode'] ?? '')) !== '' && $workMode === '') {
            $errors[] = 'Mode must be Online or Offline.';
        }
        if ($vacanciesRaw !== '' && $vacancies < 1) {
            $errors[] = 'Number of vacancies must be at least 1.';
        }
        if ($minCgpa !== null && ($minCgpa < 0 || $minCgpa > 10)) {
            $errors[] = 'Minimum CGPA must be between 0 and 10.';
        }

        if ($jobType === self::PART_TIME) {
            $compensationType = '';
            $compensationAmount = '';
            $frequency = '';
        } elseif ($jobType === self::INTERNSHIP) {
            $stipend = '';
            if (trim((string) ($input['compensationType'] ?? '')) !== '' && $compensationType === '') {
                $errors[] = 'Compensation type must be Free / Unpaid, Fee-Based, or Stipend.';
            }
            if ($compensationType === self::UNPAID) {
                $compensationAmount = '';
                $frequency = '';
            } elseif ($compensationType === self::FEE) {
                $frequency = '';
            } elseif ($compensationType !== self::STIPEND) {
                $frequency = '';
            }
        }

        if ($forPublish) {
            if ($company === '') {
                $errors[] = 'Company / organization is required.';
            }
            if ($departmentId === '') {
                $errors[] = 'Select a department.';
            }
            if ($description === '') {
                $errors[] = $jobType === self::INTERNSHIP
                    ? 'Internship description is required.'
                    : 'Job description is required.';
            }
            if ($skills === '') {
                $errors[] = 'Required skills are required.';
            }
            if ($eligibility === '') {
                $errors[] = 'Eligibility criteria are required.';
            }
            if ($vacancies < 1) {
                $errors[] = 'Number of vacancies is required.';
            }
            if ($location === '') {
                $errors[] = 'Work location is required.';
            }
            if ($workMode === '') {
                $errors[] = 'Select online or offline.';
            }
            if ($startDate === '') {
                $errors[] = 'Start date is required.';
            }
            if ($endDate === '' && $duration === '') {
                $errors[] = 'Enter an end date or a duration.';
            }
            if ($hours === '') {
                $errors[] = 'Working hours are required.';
            }
            if ($deadline === '') {
                $errors[] = 'Application deadline is required.';
            }
            if ($contact === '') {
                $errors[] = 'Contact information is required.';
            }
            if ($jobType === self::PART_TIME && $stipend === '') {
                $errors[] = 'Stipend is required for a part-time job.';
            }
            if ($jobType === self::INTERNSHIP) {
                if ($compensationType === '') {
                    $errors[] = 'Select a compensation type.';
                } elseif ($compensationType === self::FEE && $compensationAmount === '') {
                    $errors[] = 'Fee amount is required for a fee-based internship.';
                } elseif ($compensationType === self::STIPEND) {
                    if ($compensationAmount === '') {
                        $errors[] = 'Stipend amount is required.';
                    }
                    if ($frequency === '') {
                        $errors[] = 'Select a payment frequency.';
                    }
                }
            }
        }

        $data = [
            'jobType' => $jobType,
            'title' => $title,
            'company' => $company,
            'departmentId' => $departmentId,
            'description' => $description,
            'requiredSkills' => $skills,
            'eligibilityCriteria' => $eligibility,
            'minCgpa' => $minCgpa,
            'vacancies' => $vacancies,
            'workLocation' => $location,
            'workMode' => $workMode,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'duration' => $duration,
            'workingHours' => $hours,
            'stipend' => $stipend,
            'compensationType' => $compensationType,
            'compensationAmount' => $compensationAmount,
            'paymentFrequency' => $frequency,
            'applicationDeadline' => $deadline,
            'contactInformation' => $contact,
            'additionalRequirements' => $extra,
        ];

        return [
            'ok' => $errors === [],
            'message' => $errors[0] ?? '',
            'errors' => $errors,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function compensationLabel(array $post): string
    {
        $jobType = (string) ($post['jobType'] ?? '');
        if ($jobType === self::PART_TIME) {
            $money = self::moneyLabel((string) ($post['stipend'] ?? ''), 'month');

            return $money === '' ? '' : 'Stipend: ' . $money;
        }
        $type = (string) ($post['compensationType'] ?? '');
        if ($type === self::UNPAID) {
            return 'Free / Unpaid';
        }
        if ($type === self::FEE) {
            $money = self::moneyLabel((string) ($post['compensationAmount'] ?? ''), '');

            return $money === '' ? '' : 'Fee: ' . $money;
        }
        if ($type === self::STIPEND) {
            $frequency = (string) ($post['paymentFrequency'] ?? 'monthly');
            if ($frequency === 'one_time') {
                $money = self::moneyLabel((string) ($post['compensationAmount'] ?? ''), '');

                return $money === '' ? '' : 'Stipend: ' . $money . ' (one-time)';
            }
            $per = $frequency === 'weekly' ? 'week' : 'month';
            $money = self::moneyLabel((string) ($post['compensationAmount'] ?? ''), $per);

            return $money === '' ? '' : 'Stipend: ' . $money;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function matchesCompensationFilter(array $post, string $filter): bool
    {
        $filter = self::normalizeCompensationType($filter);
        if ($filter === '') {
            return true;
        }
        $jobType = (string) ($post['jobType'] ?? '');
        if ($filter === self::STIPEND) {
            return $jobType === self::PART_TIME
                || (string) ($post['compensationType'] ?? '') === self::STIPEND;
        }
        if ($jobType !== self::INTERNSHIP) {
            return false;
        }

        return (string) ($post['compensationType'] ?? '') === $filter;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $viewer
     * @return array{canApply:bool,reason:string}
     */
    public static function applyDecision(array $post, array $viewer): array
    {
        if ((string) ($post['status'] ?? '') !== 'published') {
            return ['canApply' => false, 'reason' => 'This post is not open for applications.'];
        }
        if (!empty($viewer['applied'])) {
            return ['canApply' => false, 'reason' => 'You have already applied.'];
        }
        if (!self::departmentMatches(
            $post,
            (string) ($viewer['departmentId'] ?? ''),
            (string) ($viewer['departmentCode'] ?? '')
        )) {
            return ['canApply' => false, 'reason' => 'This post is for a different department.'];
        }
        $deadline = trim((string) ($post['applicationDeadline'] ?? ''));
        $today = trim((string) ($viewer['today'] ?? ''));
        if ($deadline !== '' && $today !== '' && $deadline < $today) {
            return ['canApply' => false, 'reason' => 'The application deadline has passed.'];
        }
        $vacancies = (int) ($post['vacancies'] ?? 0);
        $count = (int) ($viewer['applicantCount'] ?? 0);
        if ($vacancies > 0 && $count >= $vacancies) {
            return ['canApply' => false, 'reason' => 'All vacancies are filled.'];
        }
        $minCgpa = $post['minCgpa'] ?? null;
        if ($minCgpa !== null && $minCgpa !== '' && (float) $minCgpa > 0) {
            $cgpa = (float) ($viewer['cgpa'] ?? 0);
            if ($cgpa + 0.0001 < (float) $minCgpa) {
                return [
                    'canApply' => false,
                    'reason' => 'Your CGPA does not meet the minimum of ' . self::trimNumber((float) $minCgpa) . '.',
                ];
            }
        }

        return ['canApply' => true, 'reason' => ''];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function present(array $post): array
    {
        $jobType = (string) ($post['jobType'] ?? '');
        $mode = (string) ($post['workMode'] ?? '');
        $compType = (string) ($post['compensationType'] ?? '');
        $code = trim((string) ($post['departmentCode'] ?? ''));
        $name = trim((string) ($post['departmentName'] ?? ''));
        $departmentLabel = $code !== '' && $name !== '' && strcasecmp($code, $name) !== 0
            ? $code . ' — ' . $name
            : ($name !== '' ? $name : $code);
        $attachment = is_array($post['attachment'] ?? null) ? $post['attachment'] : [];

        return [
            'id' => (string) ($post['_id'] ?? $post['id'] ?? ''),
            'jobType' => $jobType,
            'jobTypeLabel' => self::jobTypeLabel($jobType),
            'title' => (string) ($post['title'] ?? ''),
            'company' => (string) ($post['company'] ?? ''),
            'departmentId' => (string) ($post['departmentId'] ?? ''),
            'departmentCode' => $code,
            'departmentName' => $name,
            'departmentLabel' => $departmentLabel !== '' ? $departmentLabel : '—',
            'description' => (string) ($post['description'] ?? ''),
            'requiredSkills' => (string) ($post['requiredSkills'] ?? ''),
            'eligibilityCriteria' => (string) ($post['eligibilityCriteria'] ?? ''),
            'minCgpa' => $post['minCgpa'] ?? null,
            'vacancies' => (int) ($post['vacancies'] ?? 0),
            'workLocation' => (string) ($post['workLocation'] ?? ''),
            'workMode' => $mode,
            'workModeLabel' => self::workModeLabel($mode),
            'startDate' => (string) ($post['startDate'] ?? ''),
            'endDate' => (string) ($post['endDate'] ?? ''),
            'duration' => (string) ($post['duration'] ?? ''),
            'durationLabel' => self::durationLabel($post),
            'workingHours' => (string) ($post['workingHours'] ?? ''),
            'stipend' => (string) ($post['stipend'] ?? ''),
            'compensationType' => $compType,
            'compensationTypeLabel' => self::compensationTypeLabel($compType),
            'compensationAmount' => (string) ($post['compensationAmount'] ?? ''),
            'paymentFrequency' => (string) ($post['paymentFrequency'] ?? ''),
            'paymentFrequencyLabel' => self::frequencyLabel((string) ($post['paymentFrequency'] ?? '')),
            'compensationLabel' => self::compensationLabel($post),
            'applicationDeadline' => (string) ($post['applicationDeadline'] ?? ''),
            'contactInformation' => (string) ($post['contactInformation'] ?? ''),
            'additionalRequirements' => (string) ($post['additionalRequirements'] ?? ''),
            'attachmentUrl' => (string) ($attachment['url'] ?? ''),
            'attachmentName' => (string) ($attachment['name'] ?? ''),
            'status' => (string) ($post['status'] ?? 'draft'),
            'createdAt' => (string) ($post['createdAt'] ?? ''),
            'updatedAt' => (string) ($post['updatedAt'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function durationLabel(array $post): string
    {
        $duration = trim((string) ($post['duration'] ?? ''));
        $start = trim((string) ($post['startDate'] ?? ''));
        $end = trim((string) ($post['endDate'] ?? ''));
        if ($duration !== '') {
            return $duration;
        }
        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return $end !== '' ? $end : $start;
    }

    public static function moneyLabel(string $amount, string $per = ''): string
    {
        $amount = trim($amount);
        if ($amount === '') {
            return '';
        }
        $numeric = str_replace([',', '₹', ' '], '', $amount);
        if (preg_match('/^\d+(\.\d+)?$/', $numeric) === 1) {
            $n = (float) $numeric;
            $decimals = fmod($n, 1.0) === 0.0 ? 0 : 2;
            $formatted = '₹' . number_format($n, $decimals);
            if ($per !== '') {
                $formatted .= '/' . $per;
            }

            return $formatted;
        }
        if ($per !== '' && preg_match('/\/\s*(month|week|one-?time)/i', $amount) !== 1) {
            return $amount . '/' . $per;
        }

        return $amount;
    }

    private static function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $raw);

        return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
    }

    private static function normalizeCgpa(mixed $raw): ?float
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }
        if (!is_numeric($text)) {
            return -1;
        }

        return round((float) $text, 2);
    }

    private static function trimNumber(float $n): string
    {
        $text = number_format($n, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}
