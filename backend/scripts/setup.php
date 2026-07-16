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
    fwrite(STDERR, "  1) Run: composer install\n");
    fwrite(STDERR, "  2) Then rerun: php backend/scripts/setup.php\n\n");
    fwrite(STDERR, "Details: {$autoload} not found.\n");
    exit(1);
}
require_once $autoload;

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "Missing PHP extension: pdo_mysql.\n\n");
    fwrite(STDERR, "Enable extension=pdo_mysql in php.ini and restart PHP.\n");
    exit(1);
}

require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\AlumniJobPostModel;
use PMS\Models\AlumniModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\NotificationModel;
use PMS\Models\PlacementNewsModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RecommendationModel;
use PMS\Models\RuleModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\SuccessStoryModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

echo "PMS Setup — Creating database tables...\n";
Database::setupIndexes();
echo "Database tables created.\n";

$userModel = new UserModel();
$admin = $userModel->findByEmail('placements@amaljyothi.ac.in');

if (!$admin) {
    $id = $userModel->createUser([
        'name'     => 'System Administrator',
        'email'    => 'placements@amaljyothi.ac.in',
        'password' => 'Placements@2026',
        'role'     => 'admin',
        'status'   => 'active',
        'approved' => true,
    ]);
    echo "Default admin created (placements@amaljyothi.ac.in)\n";
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
    'placementRate'=> 65.6,
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
    $admin = $userModel->findByEmail('placements@amaljyothi.ac.in');
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

// --- Alumni and company recruiter demo accounts ---
function seedAlumniUser(UserModel $userModel, string $email, array $profile): void
{
    if ($userModel->findByEmail($email)) {
        echo "Alumni user already exists: {$email}\n";
        return;
    }
    $userId = $userModel->createUser([
        'name' => $profile['name'], 'email' => $email, 'password' => $profile['password'],
        'role' => 'alumni', 'status' => 'active', 'approved' => true,
    ]);
    (new AlumniModel())->createProfile($userId, $profile);
    echo "Alumni user created: {$email} / {$profile['password']}\n";
}

seedAlumniUser($userModel, 'rohan.v@alumni.edu', [
    'name' => 'Rohan Verma', 'password' => 'Alumni@123456', 'company' => 'Google',
    'title' => 'SWE II', 'experience' => 3, 'skills' => ['Java', 'Go'], 'isWorking' => true,
]);
seedAlumniUser($userModel, 'priya.v@alumni.edu', [
    'name' => 'Priya Nair', 'password' => 'Alumni@123456', 'company' => '',
    'title' => '', 'experience' => 2, 'skills' => ['Python'], 'isWorking' => false,
]);

$rohan = $userModel->findByEmail('rohan.v@alumni.edu');
if ($rohan) {
    $rohanId = (string) $rohan['_id'];
    $jobPostModel = new AlumniJobPostModel();
    if ($jobPostModel->findByAlumni($rohanId) === []) {
        $jobPostModel->createPost($rohanId, [
            'title' => 'Senior SDE', 'company' => 'Google', 'type' => 'Full-time',
            'package' => '₹38 LPA', 'location' => 'Bengaluru', 'description' => 'Backend role.',
        ]);
        echo "Sample alumni job posts seeded for rohan.v@alumni.edu\n";
    }
    $refModel = new AlumniReferralModel();
    if ($refModel->findByAlumni($rohanId) === []) {
        $refModel->createReferral($rohanId, [
            'companyName' => 'Google',
            'companyWebsite' => 'https://careers.google.com',
            'hrName' => 'Priya Menon',
            'hrEmail' => 'priya.menon@google.com',
            'contactNumber' => '+91 98765 43210',
        ]);
        echo "Sample alumni referral seeded for rohan.v@alumni.edu\n";
    }

    $storyModel = new SuccessStoryModel();
    if ($storyModel->findByAlumni($rohanId) === []) {
        $storyModel->createStory($rohanId, 'Rohan Verma', [
            'name'    => 'Rohan Verma',
            'company' => 'Google',
            'role'    => 'SWE II',
            'package' => '38 LPA',
            'quote'   => 'PlaceHub connected me with mentors and mock interviews that made the Google process feel achievable. Grateful for the placement cell team.',
        ]);
        echo "Sample alumni success story seeded for rohan.v@alumni.edu\n";
    }
}

if (!$userModel->findByEmail('neha@acme.io')) {
    $companyUserId = $userModel->createUser([
        'name' => 'Neha Sharma', 'email' => 'neha@acme.io', 'password' => 'Company@123456',
        'role' => 'company', 'status' => 'active', 'approved' => true,
    ]);
    $linkedCompany = (new CompanyModel())->findByUserId($companyUserId);
    if (!$linkedCompany) {
        $existingAcme = $companyModel->findAll(['companyName' => 'Acme Cloud'], 1);
        if ($existingAcme !== [] && empty($existingAcme[0]['userId'])) {
            $companyModel->update((string) $existingAcme[0]['_id'], [
                'userId' => Security::toObjectId($companyUserId),
            ]);
        } else {
            (new CompanyModel())->createCompany([
                'userId' => $companyUserId, 'companyName' => 'Acme Cloud', 'category' => 'Software',
                'tier' => 'Tier 1', 'associationStatus' => 'active', 'website' => 'https://acme.example.com',
            ]);
        }
    }
    echo "Company user created: neha@acme.io / Company@123456\n";
} else {
    echo "Company user already exists: neha@acme.io\n";
}

$nehaUser = $userModel->findByEmail('neha@acme.io');
$nehaCompanyId = null;
$jobModel = new JobModel();
if ($nehaUser) {
    $linked = $companyModel->findByUserId((string) $nehaUser['_id']);
    $allAcme = $companyModel->findAll(['companyName' => 'Acme Cloud'], 10);
    $withJobs = null;
    foreach ($allAcme as $row) {
        if ($jobModel->findByCompany((string) $row['_id']) !== []) {
            $withJobs = $row;
            break;
        }
    }
    if ($withJobs && (!$linked || (string) $linked['_id'] !== (string) $withJobs['_id'])) {
        $companyModel->update((string) $withJobs['_id'], [
            'userId' => Security::toObjectId((string) $nehaUser['_id']),
        ]);
        $linked = $withJobs;
        echo "Relinked neha@acme.io to Acme Cloud company with job postings.\n";
    }
    if ($linked) {
        $nehaCompanyId = (string) $linked['_id'];
    }
}
if (!$nehaCompanyId && $company) {
    $nehaCompanyId = (string) $company[0]['_id'];
}
if ($nehaCompanyId && $jobModel->findByCompany($nehaCompanyId) === []) {
    $jobModel->createJob([
        'companyId' => $nehaCompanyId, 'title' => 'SDE-1', 'package' => '₹18 LPA',
        'location' => 'Bengaluru', 'jobType' => 'Full-time', 'status' => 'open',
        'description' => 'Backend and platform engineering role.',
    ]);
    echo "Sample company jobs seeded for Acme Cloud\n";
} elseif ($nehaUser && !$nehaCompanyId) {
    foreach ($companyModel->findAll(['companyName' => 'Acme Cloud'], 5) as $row) {
        if (empty($row['userId'])) {
            $companyModel->update((string) $row['_id'], [
                'userId' => Security::toObjectId((string) $nehaUser['_id']),
            ]);
            echo "Linked existing Acme Cloud company to neha@acme.io\n";
            break;
        }
    }
}

// --- Staff (faculty) demo account ---
$cseDept = $deptModel->findByCode('CSE');
$cseDeptId = $cseDept ? (string) $cseDept['_id'] : ($deptIds['CSE'] ?? null);
if (!$userModel->findByEmail('ravi.iyer@college.edu') && $cseDeptId) {
    $staffUserId = $userModel->createUser([
        'name' => 'Prof. Ravi Iyer', 'email' => 'ravi.iyer@college.edu', 'password' => 'Staff@123456',
        'role' => 'staff', 'status' => 'active', 'approved' => true,
    ]);
    (new StaffModel())->createProfile($staffUserId, [
        'departmentId' => $cseDeptId,
        'designation'  => 'Associate Professor',
    ]);
    $recModel = new RecommendationModel();
    if ($recModel->findByStaffUserId($staffUserId) === []) {
        $recModel->createRecommendation($staffUserId, [
            'companyName'    => 'Brillio',
            'companyWebsite' => 'https://brillio.com/careers',
            'category'       => 'Software',
            'reason'         => 'Strong campus partnership potential.',
            'contact'        => [
                'name'  => 'Anita Desai',
                'email' => 'anita.desai@brillio.com',
                'phone' => '+91 98765 43210',
            ],
        ]);
        $recModel->createRecommendation($staffUserId, [
            'companyName'    => 'Postman',
            'companyWebsite' => 'https://postman.com/careers',
            'category'       => 'Product',
            'reason'         => 'API tooling company with intern pipeline.',
            'contact'        => [
                'name'  => 'Kunal Shah',
                'email' => 'kunal@postman.com',
                'phone' => '+91 91234 56780',
            ],
        ]);
        $recs = $recModel->findByStaffUserId($staffUserId);
        if (isset($recs[0]['_id'])) {
            $recModel->updateStatus((string) $recs[0]['_id'], 'registered');
        }
        if (isset($recs[1]['_id'])) {
            $recModel->updateStatus((string) $recs[1]['_id'], 'contacted');
        }
    }
    echo "Staff user created: ravi.iyer@college.edu / Staff@123456\n";
} else {
    echo "Staff user already exists or CSE department missing.\n";
}

// --- Sample in-app notifications (unread + read) for demo accounts ---
$notifModel = new NotificationModel();
$seedNotifs = [
    ['email' => 'placements@amaljyothi.ac.in', 'items' => [
        ['type' => 'drive_announcement', 'title' => 'New drive published', 'message' => 'Google SDE-1 is now open for registrations.', 'read' => false],
        ['type' => 'offer', 'title' => 'Offer accepted', 'message' => 'Kabir Singh accepted Amazon SDE Intern offer.', 'read' => false],
        ['type' => 'resume_review', 'title' => 'Resume needs review', 'message' => '18 new resumes pending verification.', 'read' => false],
    ]],
    ['email' => 'riya@college.edu', 'items' => [
        ['type' => 'drive_announcement', 'title' => 'MCA drive scheduled', 'message' => 'Infosys MCA drive is scheduled for next week.', 'read' => false],
        ['type' => 'application_update', 'title' => 'Applications pending review', 'message' => '12 MCA applications await officer approval.', 'read' => false],
    ]],
    ['email' => 'ravi.iyer@college.edu', 'items' => [
        ['type' => 'recommendation_update', 'title' => 'Recommendation under review', 'message' => 'Your Brillio referral is being reviewed by the placement cell.', 'read' => false],
        ['type' => 'drive_announcement', 'title' => 'New drive: Google SDE-1', 'message' => 'CSE students can register for the Google SDE-1 drive.', 'read' => false],
    ]],
    ['email' => 'rahul.v@college.edu', 'items' => [
        ['type' => 'job_poster', 'title' => 'New drive: Google SDE-1', 'message' => 'Registration is open. Package ₹42 LPA. Deadline Dec 28.', 'read' => false],
        ['type' => 'application_update', 'title' => 'Microsoft SWE — Under review', 'message' => 'Your application is being reviewed by the placement cell.', 'read' => true],
    ]],
    ['email' => 'neha@acme.io', 'items' => [
        ['type' => 'application_submitted', 'title' => 'New application received', 'message' => 'A student applied to your SDE drive.', 'read' => false],
        ['type' => 'resume_verified', 'title' => 'Resume verified', 'message' => 'Placement cell verified a candidate resume for review.', 'read' => false],
    ]],
    ['email' => 'rohan.v@alumni.edu', 'items' => [
        ['type' => 'referral', 'title' => 'Referral received', 'message' => 'Your SDE-2 referral at Google was submitted successfully.', 'read' => false],
        ['type' => 'job_post', 'title' => 'Job post live', 'message' => 'Your Senior SDE posting is now visible to the alumni network.', 'read' => false],
        ['type' => 'application_update', 'title' => 'Application update', 'message' => 'Your drive application status was updated.', 'read' => true],
    ]],
    ['email' => 'priya.v@alumni.edu', 'items' => [
        ['type' => 'drive_announcement', 'title' => 'New drive open', 'message' => 'A drive matching your profile is open for alumni applications.', 'read' => false],
        ['type' => 'application_update', 'title' => 'Application under review', 'message' => 'Your application is being reviewed by the placement cell.', 'read' => false],
    ]],
];
foreach ($seedNotifs as $group) {
    $u = $userModel->findByEmail($group['email']);
    if (!$u) {
        continue;
    }
    $uid = (string) $u['_id'];
    if ($notifModel->findByUser($uid) !== []) {
        continue;
    }
    foreach ($group['items'] as $item) {
        $id = $notifModel->notify($uid, $item['type'], $item['title'], $item['message']);
        if (!empty($item['read'])) {
            $notifModel->markRead($id);
        }
    }
    echo "Notifications seeded for {$group['email']}\n";
}

// Local upload dirs are legacy-only (new files go to S3 via ObjectStorageService).
// Keep creating them so old files remain readable until migrate-uploads-to-s3.php runs.
$config = require dirname(__DIR__) . '/config/app.php';
foreach (['resume_dir', 'reports_dir', 'jd_dir', 'shortlist_dir'] as $key) {
    $dir = $config['uploads'][$key];
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created legacy directory (read fallback): {$dir}\n";
    }
}
echo "Note: new uploads go to S3. Migrate old files with:\n";
echo "  php backend/scripts/migrate-uploads-to-s3.php\n";
echo "  php backend/scripts/migrate-uploads-to-s3.php --delete-local\n";

echo "Setup complete.\n";
