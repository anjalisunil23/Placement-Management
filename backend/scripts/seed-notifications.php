<?php
declare(strict_types=1);

/**
 * Seed demo in-app notifications for all roles (idempotent per user).
 * Usage: php backend/scripts/seed-notifications.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\NotificationModel;
use PMS\Models\UserModel;

$userModel = new UserModel();
$notifModel = new NotificationModel();

$groups = [
    ['email' => 'admin@college.edu', 'items' => [
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

foreach ($groups as $group) {
    $u = $userModel->findByEmail($group['email']);
    if (!$u) {
        echo "Skip {$group['email']} — user not found\n";
        continue;
    }
    $uid = (string) $u['_id'];
    if ($notifModel->findByUser($uid) !== []) {
        echo "Skip {$group['email']} — already has notifications\n";
        continue;
    }
    foreach ($group['items'] as $item) {
        $id = $notifModel->notify($uid, $item['type'], $item['title'], $item['message']);
        if (!empty($item['read'])) {
            $notifModel->markRead($id);
        }
    }
    echo "Seeded notifications for {$group['email']}\n";
}

echo "Done.\n";
