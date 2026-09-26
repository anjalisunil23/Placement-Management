<?php

declare(strict_types=1);

/**
 * Certification backend checks.
 * Usage: php backend/scripts/test-certifications.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Middleware\AuthMiddleware;
use PMS\Models\CertificationModel;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StudentCertificationModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Services\CertificationService;

$failed = 0;
$passed = 0;

$check = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};

$admin = ['_id' => 'a' . bin2hex(random_bytes(11)), 'role' => 'admin', 'email' => 'cert-admin@example.test'];
$officer = ['_id' => 'b' . bin2hex(random_bytes(11)), 'role' => 'placement_officer', 'email' => 'cert-officer@example.test'];
$studentUser = ['_id' => 'c' . bin2hex(random_bytes(11)), 'role' => 'student', 'name' => 'Cert Student A', 'email' => 'cert-a@example.test'];
$otherUser = ['_id' => 'd' . bin2hex(random_bytes(11)), 'role' => 'student', 'name' => 'Cert Student B', 'email' => 'cert-b@example.test'];

$check(CertificationService::canManage($admin), '1 admin can manage certifications');
$check(CertificationService::canManage($officer), '2 placement officer can manage certifications');
$check(!CertificationService::canManage($studentUser), '3 student cannot manage certifications');

$valid = CertificationService::validate([
    'name' => 'Python for Everybody',
    'url' => 'https://example.com/python-certification',
    'dueDate' => '2026-10-15',
    'description' => 'External course',
    'visibility' => 'ALL',
]);
$check($valid['ok'] && $valid['data']['visibility'] === 'all' && $valid['data']['departmentIds'] === [], '4 valid all-department certification');
$check(!CertificationService::validate(['name' => 'Python', 'url' => 'not-a-url', 'dueDate' => '2026-10-15', 'visibility' => 'departments', 'departmentIds' => ['x']])['ok'], '5 invalid URL rejected');
$check(!CertificationService::validate(['name' => 'Python', 'url' => 'https://example.com', 'dueDate' => '15-10-2026', 'visibility' => 'all'])['ok'], '6 invalid date rejected');
$check(!CertificationService::validate(['name' => 'Python', 'url' => 'https://example.com', 'dueDate' => '2026-10-15', 'visibility' => 'departments', 'departmentIds' => []])['ok'], '6b selected departments require ids');

$future = ['dueDate' => '2099-01-01'];
$past = ['dueDate' => '2000-01-01'];
$check(CertificationService::displayStatus($future, null) === 'available', '7 no proof before due date is available');
$check(CertificationService::displayStatus($past, null) === 'available', '8 past due date stays available');
$check(CertificationService::isPastDue($past) && !CertificationService::isPastDue($future), '8b past due is only a warning flag');
$pastDate = CertificationService::validate([
    'name' => 'Python',
    'url' => 'https://example.com',
    'dueDate' => '2000-01-01',
    'visibility' => 'all',
]);
$check(!$pastDate['ok'], '8c past due date is rejected');
$completed = ['status' => 'completed', 'proofPath' => 's3://bucket/certification-proofs/file.pdf'];
$check(CertificationService::displayStatus($past, $completed) === 'completed', '9 proof makes status completed');

$service = new CertificationService();
$createdId = '';
$secondId = '';
$selectedId = '';
$officerCertId = '';
$cseId = '';
$mcaId = '';
$eceId = '';
$studentId = '';
$otherStudentId = '';
try {
    $created = $service->create($admin, $valid['data']);
    $createdId = (string) $created['id'];
    $check($createdId !== '' && ($created['visibility'] ?? '') === 'all', '10 admin can create an all-department certification');

    $departments = new DepartmentModel();
    $cseId = $departments->insert(['name' => 'Computer Science', 'code' => 'CSE']);
    $mcaId = $departments->insert(['name' => 'Computer Applications', 'code' => 'MCA']);
    $eceId = $departments->insert(['name' => 'Electronics', 'code' => 'ECE']);
    $selected = $service->create($admin, [
        'name' => 'Python Certification',
        'url' => 'https://example.com/python-cse-mca',
        'dueDate' => '2026-12-01',
        'visibility' => 'SELECTED_DEPARTMENTS',
        'departmentIds' => [$cseId, $mcaId],
    ]);
    $selectedId = (string) $selected['id'];
    $check(count($selected['departmentIds'] ?? []) === 2, '10b admin can assign multiple departments');

    $officers = new PlacementOfficerModel();
    $users = new UserModel();
    $users->insert([
        'name' => 'CSE Officer',
        'email' => 'cert-officer@example.test',
        'role' => 'placement_officer',
        'status' => 'active',
    ]);
    $officerStored = $users->findOne(['email' => 'cert-officer@example.test']);
    $officer['_id'] = (string) ($officerStored['_id'] ?? '');
    $officers->insert(['userId' => $officer['_id'], 'departmentId' => $cseId, 'designation' => 'Department Placement Officer']);

    $officerCert = $service->create($officer, [
        'name' => 'CSE only certification',
        'url' => 'https://example.com/cse-only',
        'dueDate' => '2026-12-15',
        'visibility' => 'ALL',
        'departmentIds' => [$mcaId, $eceId],
    ]);
    $officerCertId = (string) $officerCert['id'];
    $check(($officerCert['visibility'] ?? '') === 'departments' && ($officerCert['departmentIds'] ?? []) === [$cseId], '10c officer certification is locked to their department');

    $officerCannotEditAll = false;
    try {
        $service->update($officer, $createdId, ['name' => 'Changed global']);
    } catch (\RuntimeException $e) {
        $officerCannotEditAll = $e->getCode() === 403;
    }
    $check($officerCannotEditAll, '10d officer cannot edit an all-department certification');

    $updated = $service->update($admin, $selectedId, [
        'name' => 'Python Certification',
        'url' => 'https://example.com/python-cse-mca',
        'dueDate' => '2026-12-02',
        'visibility' => 'departments',
        'departmentIds' => [$cseId],
    ]);
    $check(($updated['departmentIds'] ?? []) === [$cseId], '11 admin can update certification visibility');

    $studentBlocked = false;
    try {
        $service->create($studentUser, $valid['data']);
    } catch (\RuntimeException $e) {
        $studentBlocked = $e->getCode() === 403;
    }
    $check($studentBlocked, '12 student cannot create certification');

    $studentUpdateBlocked = false;
    try {
        $service->update($studentUser, $createdId, $valid['data']);
    } catch (\RuntimeException $e) {
        $studentUpdateBlocked = $e->getCode() === 403;
    }
    $check($studentUpdateBlocked, '13 student cannot update certification');

    $studentDeleteBlocked = false;
    try {
        $service->delete($studentUser, $createdId);
    } catch (\RuntimeException $e) {
        $studentDeleteBlocked = $e->getCode() === 403;
    }
    $check($studentDeleteBlocked, '14 student cannot delete certification');

    $users = new UserModel();
    $students = new StudentModel();
    $users->insert([
        '_id' => $studentUser['_id'],
        'name' => $studentUser['name'],
        'email' => $studentUser['email'],
        'role' => 'student',
        'status' => 'active',
    ]);
    $studentUser['_id'] = $users->findOne(['email' => $studentUser['email']])['_id'] ?? $studentUser['_id'];
    $studentId = $students->insert(['userId' => $studentUser['_id'], 'registerNumber' => 'CERTTESTA', 'departmentId' => $mcaId]);
    $users->insert([
        'name' => $otherUser['name'],
        'email' => $otherUser['email'],
        'role' => 'student',
        'status' => 'active',
    ]);
    $otherStored = $users->findOne(['email' => $otherUser['email']]);
    $otherUser['_id'] = (string) ($otherStored['_id'] ?? '');
    $otherStudentId = $students->insert(['userId' => $otherUser['_id'], 'registerNumber' => 'CERTTESTB', 'departmentId' => $cseId]);

    $listed = $service->listFor($studentUser);
    $seesAll = false;
    $seesCse = false;
    foreach ($listed as $row) {
        if (($row['id'] ?? '') === $createdId) {
            $seesAll = true;
        }
        if (($row['id'] ?? '') === $selectedId || ($row['id'] ?? '') === $officerCertId) {
            $seesCse = true;
        }
    }
    $check($seesAll && !$seesCse, '15 MCA student sees all-department certifications and not CSE-only certifications');

    $cseListed = $service->listFor($otherUser);
    $cseSeesSelected = false;
    foreach ($cseListed as $row) {
        if (($row['id'] ?? '') === $selectedId) {
            $cseSeesSelected = ($row['departmentIds'] ?? null) === null || true;
            $cseSeesSelected = true;
        }
    }
    $check($cseSeesSelected, '15b CSE student sees a certification that includes CSE');

    $bypass = false;
    try {
        $service->show($studentUser, $selectedId);
    } catch (\RuntimeException $e) {
        $bypass = $e->getCode() === 403;
    }
    $check($bypass, '15c student cannot open a certification outside their department');

    $own = $service->show($studentUser, $createdId);
    $check(($own['hasProof'] ?? true) === false, '16 student sees own incomplete certification');

    $progress = new StudentCertificationModel();
    $pair = [
        'studentId' => $studentId,
        'certificationId' => $createdId,
        'pairKey' => StudentCertificationModel::pairKey($studentId, $createdId),
        'status' => 'completed',
        'proofPath' => 's3://ajce-placements/certification-proofs/a.pdf',
        'proofFileName' => 'a.pdf',
        'completedAt' => '2026-09-26 00:00:00.000000',
    ];
    $progress->insert($pair);
    $duplicateBlocked = false;
    try {
        $progress->insert($pair);
    } catch (\PDOException $e) {
        $duplicateBlocked = (string) $e->getCode() === '23000';
    }
    $check($duplicateBlocked, '17 duplicate student certification is rejected by the database');

    $second = $service->create($officer, [
        'name' => 'Java Certification',
        'url' => 'https://example.com/java',
        'dueDate' => '2026-11-01',
    ]);
    $secondId = (string) $second['id'];
    $progress->insert([
        'studentId' => $studentId,
        'certificationId' => $secondId,
        'pairKey' => StudentCertificationModel::pairKey($studentId, $secondId),
        'status' => 'pending',
        'proofPath' => '',
        'proofFileName' => '',
    ]);
    $board = $service->leaderboard();
    $mine = null;
    foreach ($board as $row) {
        if (($row['name'] ?? '') === 'Cert Student A') {
            $mine = $row;
        }
    }
    $check(is_array($mine) && (int) $mine['completed'] === 1, '18 completed count includes only completed proof');
    $check(!array_filter($board, static fn (array $row): bool => ($row['name'] ?? '') === 'Cert Student B'), '19 pending certification is not counted');

    $existing = $progress->findByPair($studentId, $createdId);
    $progress->update((string) ($existing['_id'] ?? ''), ['proofFileName' => 'replaced.pdf', 'proofPath' => 's3://ajce-placements/certification-proofs/replaced.pdf', 'status' => 'completed']);
    $afterReplace = 0;
    foreach ($service->leaderboard() as $row) {
        if (($row['name'] ?? '') === 'Cert Student A') {
            $afterReplace = (int) $row['completed'];
        }
    }
    $check($afterReplace === 1, '20 replacing proof does not increase the count');

    $progress->insert([
        'studentId' => $otherStudentId,
        'certificationId' => $createdId,
        'pairKey' => StudentCertificationModel::pairKey($otherStudentId, $createdId),
        'status' => 'completed',
        'proofPath' => 's3://ajce-placements/certification-proofs/b.pdf',
        'proofFileName' => 'b.pdf',
    ]);
    $ordered = array_column($service->leaderboard(), 'completed');
    $check($ordered === array_values($ordered) && ($ordered[0] ?? 0) >= ($ordered[1] ?? 0), '21 leaderboard is ordered by completed count');

    $blockedProof = false;
    try {
        $service->proofFor($studentUser, $createdId, $otherStudentId);
    } catch (\RuntimeException $e) {
        $blockedProof = $e->getCode() === 403;
    }
    $check($blockedProof, '22 student cannot access another student proof');

    $guestBlocked = false;
    try {
        $service->completions(['_id' => 'guest', 'role' => 'company', 'email' => 'guest@example.test'], $createdId);
    } catch (\RuntimeException $e) {
        $guestBlocked = $e->getCode() === 403;
    }
    $check($guestBlocked, '23 unauthorized role cannot view completion records');

    $service->setCompletionStatus($admin, $createdId, $studentId, 'pending');
    $afterReset = 0;
    foreach ($service->leaderboard() as $row) {
        if (($row['name'] ?? '') === 'Cert Student A') {
            $afterReset = (int) $row['completed'];
        }
    }
    $check($afterReset === 0, '24 marking a completion pending removes it from the count');

    $invalidFile = false;
    try {
        $tmp = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents((string) $tmp, 'not a certificate');
        $service->submitProof($studentUser, $createdId, [
            'name' => 'proof.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 16,
        ]);
    } catch (\InvalidArgumentException) {
        $invalidFile = true;
    }
    $check($invalidFile, '25 invalid proof file is rejected');

    $service->delete($admin, $createdId);
    $gone = (new CertificationModel())->findById($createdId) === null;
    $studentStillThere = $students->findById($studentId) !== null;
    $check($gone && $studentStillThere, '26 deleting a certification removes only its completion rows');
    $createdId = '';
} catch (\Throwable $e) {
    $check(false, 'database certification flow: ' . $e->getMessage());
} finally {
    $certs = new CertificationModel();
    $progress = new StudentCertificationModel();
    foreach (array_filter([$createdId, $secondId, $selectedId, $officerCertId]) as $id) {
        foreach ($progress->findByCertification($id) as $row) {
            $progress->delete((string) ($row['_id'] ?? ''));
        }
        $certs->delete($id);
    }
    $students = new StudentModel();
    $users = new UserModel();
    if ($studentId !== '') {
        $students->delete($studentId);
    }
    if ($otherStudentId !== '') {
        $students->delete($otherStudentId);
    }
    foreach ([$studentUser['email'], $otherUser['email'], 'cert-officer@example.test'] as $email) {
        $row = $users->findOne(['email' => $email]);
        if (is_array($row)) {
            (new PlacementOfficerModel())->deleteByUserId((string) ($row['_id'] ?? ''));
            $users->delete((string) ($row['_id'] ?? ''));
        }
    }
    $departments = new DepartmentModel();
    foreach ([$cseId, $mcaId, $eceId] as $departmentId) {
        if ($departmentId !== '') {
            $departments->delete($departmentId);
        }
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
