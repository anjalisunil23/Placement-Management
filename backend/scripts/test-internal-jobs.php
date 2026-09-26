<?php

declare(strict_types=1);

require dirname(__DIR__) . '/services/InternalJobService.php';

use PMS\Services\InternalJobService;

$failed = 0;

function check(bool $ok, string $label): void
{
    global $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
}

$base = [
    'jobType' => 'part_time',
    'title' => 'Lab assistant',
    'company' => 'AJCE Labs',
    'departmentId' => 'dept1',
    'description' => 'Help the lab',
    'requiredSkills' => 'Excel',
    'eligibilityCriteria' => 'Second year',
    'vacancies' => '2',
    'workLocation' => 'Kanjirappally',
    'workMode' => 'on_site',
    'startDate' => '2026-10-01',
    'endDate' => '2026-12-01',
    'duration' => '',
    'workingHours' => '4 hours/day',
    'stipend' => '5000',
    'applicationDeadline' => '2026-09-28',
    'contactInformation' => 'placement@ajce.in',
    'additionalRequirements' => '',
];

$draft = InternalJobService::validate(['jobType' => 'part_time', 'title' => 'Lab assistant'], false);
check($draft['ok'], 'draft can be saved with title and job type only');

$missingStipend = $base;
$missingStipend['stipend'] = '';
$published = InternalJobService::validate($missingStipend, true);
check(!$published['ok'] && str_contains($published['message'], 'Stipend'), 'part-time publish requires a stipend');

$okPart = InternalJobService::validate($base, true);
check($okPart['ok'], 'part-time publish accepts a stipend');
check(InternalJobService::compensationLabel($okPart['data']) === 'Stipend: ₹5,000/month', 'part-time stipend label');

$intern = $base;
$intern['jobType'] = 'internship';
$intern['title'] = 'Winter intern';
$intern['compensationType'] = 'unpaid';
$intern['compensationAmount'] = '1000';
$unpaid = InternalJobService::validate($intern, true);
check($unpaid['ok'] && $unpaid['data']['compensationAmount'] === '', 'unpaid internship clears the amount');
check(InternalJobService::compensationLabel($unpaid['data']) === 'Free / Unpaid', 'unpaid label');

$intern['compensationType'] = 'fee';
$intern['compensationAmount'] = '';
$feeMissing = InternalJobService::validate($intern, true);
check(!$feeMissing['ok'], 'fee-based internship requires an amount');

$intern['compensationAmount'] = '2500';
$fee = InternalJobService::validate($intern, true);
check($fee['ok'] && InternalJobService::compensationLabel($fee['data']) === 'Fee: ₹2,500', 'fee label');

$intern['compensationType'] = 'stipend';
$intern['compensationAmount'] = '8000';
$intern['paymentFrequency'] = '';
$freqMissing = InternalJobService::validate($intern, true);
check(!$freqMissing['ok'], 'stipend internship requires a payment frequency');

$intern['paymentFrequency'] = 'monthly';
$stipend = InternalJobService::validate($intern, true);
check($stipend['ok'] && InternalJobService::compensationLabel($stipend['data']) === 'Stipend: ₹8,000/month', 'internship stipend label');

check(InternalJobService::resolveDepartmentId('other', 'cse') === 'cse', 'officer department cannot be changed');
check(InternalJobService::resolveDepartmentId('ece', null) === 'ece', 'admin keeps the selected department');

$post = $stipend['data'];
$post['departmentCode'] = 'CSE';
$post['status'] = 'published';
$blocked = InternalJobService::applyDecision($post, [
    'applied' => false,
    'departmentId' => 'ece',
    'departmentCode' => 'ECE',
    'today' => '2026-09-26',
    'applicantCount' => 0,
    'cgpa' => 8,
]);
check(!$blocked['canApply'] && str_contains($blocked['reason'], 'department'), 'student in another department cannot apply');

$allowed = InternalJobService::applyDecision($post, [
    'applied' => false,
    'departmentId' => 'dept1',
    'departmentCode' => 'CSE',
    'today' => '2026-09-26',
    'applicantCount' => 0,
    'cgpa' => 8,
]);
check($allowed['canApply'], 'student in the same department can apply');

check(InternalJobService::matchesCompensationFilter($okPart['data'], 'stipend'), 'stipend filter includes part-time jobs');
check(!InternalJobService::matchesCompensationFilter($unpaid['data'], 'stipend'), 'stipend filter excludes unpaid internships');
check(InternalJobService::matchesCompensationFilter($fee['data'], 'fee'), 'fee filter matches fee-based internships');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}

echo "All internal job checks passed\n";
exit(0);
