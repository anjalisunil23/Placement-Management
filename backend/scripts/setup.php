<?php

declare(strict_types=1);

/**
 * Database setup script — creates indexes and default admin user.
 *
 * Usage: php backend/scripts/setup.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor autoload.\n\n");
    fwrite(STDERR, "Fix:\n");
    fwrite(STDERR, "  1) Install the MongoDB PHP extension (ext-mongodb) for your PHP (XAMPP).\n");
    fwrite(STDERR, "  2) Run: composer install\n");
    fwrite(STDERR, "  3) Then rerun: php backend/scripts/setup.php\n\n");
    fwrite(STDERR, "Details: {$autoload} not found.\n");
    exit(1);
}
require_once $autoload;

if (!extension_loaded('mongodb')) {
    fwrite(STDERR, "Missing PHP extension: mongodb (ext-mongodb).\n\n");
    fwrite(STDERR, "Fix (XAMPP on Windows):\n");
    fwrite(STDERR, "  - Download the matching php_mongodb.dll for PHP " . PHP_VERSION . " (TS / x64) from PECL.\n");
    fwrite(STDERR, "  - Copy it into: C:\\\\xampp\\\\php\\\\ext\\\\\n");
    fwrite(STDERR, "  - Enable it in: C:\\\\xampp\\\\php\\\\php.ini (add: extension=mongodb)\n");
    fwrite(STDERR, "  - Restart Apache / re-open terminals.\n\n");
    fwrite(STDERR, "Then run:\n");
    fwrite(STDERR, "  composer install\n");
    fwrite(STDERR, "  php backend/scripts/setup.php\n");
    exit(1);
}

require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\PlacementNewsModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RecommendationModel;
use PMS\Models\RuleModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

echo "PMS Setup — Creating indexes...\n";
Database::setupIndexes();
echo "Indexes created.\n";

$userModel = new UserModel();
$admin = $userModel->findByEmail('admin@college.edu');

if (!$admin) {
    $id = $userModel->createUser([
        'name'     => 'System Administrator',
        'email'    => 'admin@college.edu',
        'password' => 'Admin@123456',
        'role'     => 'admin',
        'status'   => 'active',
        'approved' => true,
    ]);
    echo "Default admin created (admin@college.edu / Admin@123456)\n";
    echo "User ID: {$id}\n";
    echo "IMPORTANT: Change the default password after first login!\n";
} else {
    echo "Admin user already exists.\n";
}

// Default site settings and public page content
(new SystemSettingsModel())->save([
    'placementYear'    => '2025-26',
    'emailFrom'        => 'placement@college.edu',
    'maxUploadMb'      => 10,
    'smtpEnabled'      => true,
    'notifyOnApproval' => true,
]);
echo "System settings initialized.\n";

(new PublicPageContentModel())->save([
    'season'       => '2025-26',
    'placed'       => 2154,
    'companies'    => 142,
    'highestPkg'   => 68,
    'avgPkg'       => 9.4,
    'medianPkg'    => 8.2,
    'lowestPkg'    => 3.5,
    'headline'     => 'Where ambition meets opportunity',
    'achievements' => 'Record ₹68 LPA international offer · 92.5% MCA placement rate',
]);
echo "Public page content initialized.\n";

$newsModel = new PlacementNewsModel();
if ($newsModel->count([]) === 0) {
    $seedNews = [
        ['title' => 'Record-breaking season kicks off', 'summary' => 'Over 142 companies have already confirmed campus visits for 2025–26.', 'date' => '2025-11-12', 'link' => ''],
        ['title' => 'Google announces 28 SDE offers', 'summary' => 'One of the largest cohorts hired from a single drive this year.', 'date' => '2025-10-30', 'link' => ''],
        ['title' => 'New mentorship program launched', 'summary' => 'Alumni from 60+ companies join the placement readiness program.', 'date' => '2025-10-18', 'link' => ''],
    ];
    foreach ($seedNews as $item) {
        $newsModel->createNews($item);
    }
    echo "Placement news seeded.\n";
} else {
    echo "Placement news already exists.\n";
}

if (!(new RuleModel())->getActiveRule()) {
    (new RuleModel())->saveActiveRule([
        'minCgpa' => 7.5,
        'maxBacklog' => 0,
        'maxPlacementChances' => 5,
        'blockPlacedStudents' => true,
        'allowPlacedForSelectedDrives' => false,
        'placementPolicy' => 'Students with active backlogs are ineligible for Tier 1 drives.',
        'policyVersion' => 'v1.0',
    ]);
    echo "Placement rules seeded.\n";
}

// --- Departments & placement officer demo data ---
$deptModel = new DepartmentModel();
$departments = [
    ['name' => 'Computer Science & Engineering', 'code' => 'CSE'],
    ['name' => 'Information Technology', 'code' => 'IT'],
    ['name' => 'Master of Computer Applications', 'code' => 'MCA'],
];
$deptIds = [];
foreach ($departments as $d) {
    $existing = $deptModel->findByCode($d['code']);
    if ($existing) {
        $deptIds[$d['code']] = (string) $existing['_id'];
    } else {
        $deptIds[$d['code']] = $deptModel->createDepartment($d);
        echo "Department created: {$d['code']}\n";
    }
}

$poUser = $userModel->findByEmail('riya@college.edu');
if (!$poUser) {
    $poUserId = $userModel->createUser([
        'name'     => 'Riya Ahuja',
        'email'    => 'riya@college.edu',
        'password' => 'Officer@123456',
        'role'     => 'placement_officer',
        'status'   => 'active',
        'approved' => true,
    ]);
    try {
        (new PlacementOfficerModel())->createProfile($poUserId, [
            'departmentId' => $deptIds['MCA'],
            'designation'  => 'MCA Placement Officer',
        ]);
        echo "Placement officer created (riya@college.edu / Officer@123456) for MCA\n";
    } catch (\Throwable $e) {
        echo "Placement officer profile: {$e->getMessage()}\n";
    }
} else {
    echo "Placement officer user already exists.\n";
    $profile = (new PlacementOfficerModel())->findByUserId((string) $poUser['_id']);
    if (!$profile && isset($deptIds['MCA'])) {
        try {
            (new PlacementOfficerModel())->createProfile((string) $poUser['_id'], [
                'departmentId' => $deptIds['MCA'],
                'designation'  => 'MCA Placement Officer',
            ]);
            echo "Linked existing PO user to MCA department.\n";
        } catch (\Throwable $e) {
            echo "PO profile link: {$e->getMessage()}\n";
        }
    }
}

$staffUser = $userModel->findByEmail('ravi.iyer@college.edu');
if (!$staffUser) {
    $staffUserId = $userModel->createUser([
        'name'     => 'Prof. Ravi Iyer',
        'email'    => 'ravi.iyer@college.edu',
        'password' => 'Staff@123456',
        'role'     => 'staff',
        'status'   => 'active',
        'approved' => true,
    ]);
    try {
        (new StaffModel())->createProfile($staffUserId, [
            'departmentId' => $deptIds['CSE'],
            'designation'  => 'Associate Professor',
        ]);
        echo "Staff user created (ravi.iyer@college.edu / Staff@123456) for CSE\n";
    } catch (\Throwable $e) {
        echo "Staff profile: {$e->getMessage()}\n";
    }
    $staffUser = $userModel->findByEmail('ravi.iyer@college.edu');
} else {
    echo "Staff user already exists.\n";
    $staffProfile = (new StaffModel())->findByUserId((string) $staffUser['_id']);
    if (!$staffProfile && isset($deptIds['CSE'])) {
        try {
            (new StaffModel())->createProfile((string) $staffUser['_id'], [
                'departmentId' => $deptIds['CSE'],
                'designation'  => 'Associate Professor',
            ]);
            echo "Linked existing staff user to CSE department.\n";
        } catch (\Throwable $e) {
            echo "Staff profile link: {$e->getMessage()}\n";
        }
    }
}

if ($staffUser) {
    $recModel = new RecommendationModel();
    $existingRecs = $recModel->findByStaffId((string) $staffUser['_id'], 1);
    if ($existingRecs === []) {
        $seedRecs = [
            [
                'companyName' => 'Brillio',
                'companyWebsite' => 'https://brillio.com',
                'category' => 'Software',
                'reason' => 'Strong campus partnership potential for CSE batch.',
                'contact' => ['name' => 'Anita Desai', 'email' => 'anita.desai@brillio.com', 'phone' => '+91 98765 43210'],
                'status' => 'registered',
            ],
            [
                'companyName' => 'Postman',
                'companyWebsite' => 'https://postman.com',
                'category' => 'Software',
                'reason' => 'API tooling company with active lateral hiring.',
                'contact' => ['name' => 'Kunal Shah', 'email' => 'kunal@postman.com', 'phone' => '+91 91234 56780'],
                'status' => 'contacted',
            ],
            [
                'companyName' => 'Hasura',
                'companyWebsite' => 'https://hasura.io',
                'category' => 'Software',
                'reason' => 'GraphQL platform hiring full-stack engineers.',
                'contact' => ['name' => 'Meera Nambiar', 'email' => 'meera@hasura.io', 'phone' => '+91 99887 76655'],
                'status' => 'pending',
            ],
        ];
        foreach ($seedRecs as $rec) {
            $status = $rec['status'];
            unset($rec['status']);
            $id = $recModel->createRecommendation((string) $staffUser['_id'], $rec);
            $recModel->updateStatus($id, $status);
        }
        echo "Staff recommendations seeded.\n";
    } else {
        echo "Staff recommendations already exist.\n";
    }
}

$studentModel = new StudentModel();
$seedStudents = [
    [
        'name' => 'Karthik Subramanian', 'email' => 'karthik.s@college.edu', 'password' => 'Student@123456',
        'registerNumber' => '22MCA047', 'departmentId' => $deptIds['MCA'], 'cgpa' => 8.7, 'backlogs' => 0,
    ],
    [
        'name' => 'Ananya Reddy', 'email' => 'ananya.r@college.edu', 'password' => 'Student@123456',
        'registerNumber' => '22MCA018', 'departmentId' => $deptIds['MCA'], 'cgpa' => 8.2, 'backlogs' => 0,
    ],
    [
        'name' => 'Rahul Verma', 'email' => 'rahul.v@college.edu', 'password' => 'Student@123456',
        'registerNumber' => '21CSE012', 'departmentId' => $deptIds['CSE'], 'cgpa' => 7.9, 'backlogs' => 0,
    ],
];

$studentIds = [];
foreach ($seedStudents as $s) {
    if ($studentModel->findByRegisterNumber($s['registerNumber'])) {
        continue;
    }
    if ($userModel->findByEmail($s['email'])) {
        continue;
    }
    $uid = $userModel->createUser([
        'name'     => $s['name'],
        'email'    => $s['email'],
        'password' => $s['password'],
        'role'     => 'student',
        'status'   => 'active',
        'approved' => true,
    ]);
    $sid = $studentModel->createProfile($uid, [
        'registerNumber' => $s['registerNumber'],
        'departmentId'   => $s['departmentId'],
        'classBatch'     => '2022-26',
        'academic'       => ['cgpa' => $s['cgpa'], 'backlogs' => $s['backlogs']],
    ]);
    $studentIds[$s['registerNumber']] = $sid;
    echo "Student seeded: {$s['registerNumber']}\n";
}

$companyModel = new CompanyModel();
$company = $companyModel->findAll(['companyName' => 'Acme Cloud'], 1);
$companyId = $company ? (string) $company[0]['_id'] : $companyModel->createCompany([
    'companyName' => 'Acme Cloud',
    'category'    => 'Product',
    'tier'        => 'Tier 1',
    'website'     => 'https://acme.example.com',
    'contacts'    => [['name' => 'Neha Sharma', 'email' => 'neha@acme.io', 'phone' => '+91 98765 43210']],
]);
if (!$company) {
    echo "Demo company seeded: Acme Cloud\n";
}

$driveModel = new DriveModel();
$existingDrives = $driveModel->findAll(['title' => 'SDE Intern — Acme Cloud'], 1);
if ($existingDrives === [] && isset($deptIds['MCA'])) {
    $admin = $userModel->findByEmail('admin@college.edu');
    $createdBy = $admin ? (string) $admin['_id'] : '';
    $driveId = $driveModel->createDrive([
        'title'        => 'SDE Intern — Acme Cloud',
        'companyId'    => $companyId,
        'type'         => 'pooled',
        'date'         => '2026-03-15',
        'time'         => '10:00',
        'branches'     => ['MCA'],
        'departmentId' => $deptIds['MCA'],
        'eligibility'  => ['minCgpa' => 7.5, 'maxBacklogs' => 0],
        'tier'         => 'Tier 1',
        'status'       => 'scheduled',
    ], $createdBy);
    echo "Demo drive seeded for MCA\n";

    $mcaStudent = $studentModel->findByRegisterNumber('22MCA047');
    if ($mcaStudent) {
        $appModel = new ApplicationModel();
        $studentOid = (string) $mcaStudent['_id'];
        if (!$appModel->findByStudentAndDrive($studentOid, $driveId)) {
            $appModel->createApplication([
                'studentId' => $studentOid,
                'driveId'   => $driveId,
                'companyId' => $companyId,
                'status'    => 'applied',
            ]);
            echo "Demo application seeded for 22MCA047\n";
        }
    }
} else {
    echo "Demo drive already exists.\n";
}

// Create upload directories
$config = require dirname(__DIR__) . '/config/app.php';
foreach (['resume_dir', 'reports_dir', 'jd_dir'] as $key) {
    $dir = $config['uploads'][$key];
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: {$dir}\n";
    }
}

echo "Setup complete.\n";
