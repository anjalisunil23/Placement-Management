<?php
declare(strict_types=1);

/**
 * Ensure a placement officer account exists and is linked to a department.
 * Usage: php backend/scripts/ensure-placement-officer.php email@domain.com MCA [password]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\UserModel;

$email = strtolower(trim((string) ($argv[1] ?? '')));
$deptCode = strtoupper(trim((string) ($argv[2] ?? '')));
$password = (string) ($argv[3] ?? 'Officer@123456');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php backend/scripts/ensure-placement-officer.php email@domain.com DEPT_CODE [password]\n");
    exit(1);
}
if ($deptCode === '') {
    fwrite(STDERR, "Department code is required (e.g. MCA).\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$deptModel = new DepartmentModel();
$dept = $deptModel->findByCode($deptCode);
if (!$dept) {
    fwrite(STDERR, "Department not found: {$deptCode}\n");
    exit(1);
}

$deptId = (string) $dept['_id'];
$userModel = new UserModel();
$poModel = new PlacementOfficerModel();
$user = $userModel->findByEmail($email);

if ($user) {
    $role = (string) ($user['role'] ?? '');
    if ($role !== 'placement_officer' && $role !== 'admin') {
        $userModel->updateUser((string) $user['_id'], [
            'role'     => 'placement_officer',
            'status'   => 'active',
            'approved' => true,
        ]);
        echo "Updated existing user role to placement_officer.\n";
        $user = $userModel->findById((string) $user['_id']) ?? $user;
    } else {
        $user = $userModel->ensureLoginReady($user);
        echo "Found existing placement officer user.\n";
    }
} else {
    $userId = $userModel->createUser([
        'name'     => strstr($email, '@', true) ?: 'Placement Officer',
        'email'    => $email,
        'password' => $password,
        'role'     => 'placement_officer',
        'status'   => 'active',
        'approved' => true,
    ]);
    $user = $userModel->findById($userId);
    echo "Created placement officer user.\n";
}

if (!$user) {
    fwrite(STDERR, "Could not load user after create/update.\n");
    exit(1);
}

$userId = (string) $user['_id'];
$existingProfile = $poModel->findByUserId($userId);
$existingDept = $poModel->findByDepartment($deptId);

if ($existingProfile && (string) ($existingProfile['departmentId'] ?? '') === $deptId) {
    echo "Already assigned to {$deptCode}.\n";
} else {
    if ($existingDept && (string) ($existingDept['userId'] ?? '') !== $userId) {
        $poModel->deleteByDepartment($deptId);
        echo "Replaced previous officer on {$deptCode}.\n";
    }
    if ($existingProfile) {
        $poModel->deleteByUserId($userId);
    }
    $poModel->createProfile($userId, [
        'departmentId' => $deptId,
        'designation'  => $deptCode . ' Placement Officer',
    ]);
    echo "Linked to department {$deptCode} ({$dept['name']}).\n";
}

if ($argv[3] ?? null) {
    $userModel->updateUser($userId, ['password' => $password]);
    echo "Password updated.\n";
}

echo "\nReady:\n";
echo "  Email:    {$email}\n";
echo "  Password: {$password}\n";
echo "  Portal:   /placement-console.html\n";
echo "  Login:    /login.html\n";
