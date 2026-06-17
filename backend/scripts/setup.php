<?php

declare(strict_types=1);

/**
 * Database setup script — creates indexes and default admin user.
 *
 * Usage: php backend/scripts/setup.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\AlumniJobPostModel;
use PMS\Models\AlumniModel;
use PMS\Models\AlumniReferralModel;
use PMS\Models\UserModel;

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

function seedAlumniUser(UserModel $userModel, string $email, array $profile): void
{
    if ($userModel->findByEmail($email)) {
        echo "Alumni user already exists: {$email}\n";
        return;
    }

    $userId = $userModel->createUser([
        'name'     => $profile['name'],
        'email'    => $email,
        'password' => $profile['password'],
        'role'     => 'alumni',
        'status'   => 'active',
        'approved' => true,
    ]);

    (new AlumniModel())->createProfile($userId, $profile);

    echo "Alumni user created: {$email} / {$profile['password']}\n";
}

seedAlumniUser($userModel, 'rohan.v@alumni.edu', [
    'name'       => 'Rohan Verma',
    'password'   => 'Alumni@123456',
    'company'    => 'Google',
    'title'      => 'SWE II',
    'experience' => 3,
    'skills'     => ['Java', 'Go', 'Distributed Systems'],
    'isWorking'  => true,
]);

seedAlumniUser($userModel, 'priya.v@alumni.edu', [
    'name'       => 'Priya Nair',
    'password'   => 'Alumni@123456',
    'company'    => '',
    'title'      => '',
    'experience' => 2,
    'skills'     => ['Python', 'Data Analysis'],
    'isWorking'  => false,
]);

$rohan = $userModel->findByEmail('rohan.v@alumni.edu');
if ($rohan) {
    $rohanId = (string) $rohan['_id'];
    $jobModel = new AlumniJobPostModel();
    $refModel = new AlumniReferralModel();

    if ($jobModel->findByAlumni($rohanId) === []) {
        $jobModel->createPost($rohanId, [
            'title'       => 'Senior SDE',
            'company'     => 'Google',
            'type'        => 'Full-time',
            'package'     => '₹38 LPA',
            'location'    => 'Bengaluru',
            'description' => 'Backend role in Ads infrastructure.',
        ]);
        $jobModel->createPost($rohanId, [
            'title'       => 'Product Manager',
            'company'     => 'Google',
            'type'        => 'Full-time',
            'package'     => '₹32 LPA',
            'location'    => 'Hyderabad',
            'description' => 'PM role for consumer products.',
        ]);
        echo "Sample alumni job posts seeded for rohan.v@alumni.edu\n";
    }

    if ($refModel->findByAlumni($rohanId) === []) {
        $refModel->createReferral($rohanId, [
            'jobTitle'       => 'SDE-2',
            'companyName'    => 'Google',
            'companyWebsite' => 'https://careers.google.com',
            'package'        => '₹38 LPA',
            'type'           => 'Either',
            'description'    => 'Backend role in Ads infra. Strong DSA + systems.',
        ]);
        echo "Sample alumni referral seeded for rohan.v@alumni.edu\n";
    }
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
