<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\NotificationModel;
use PMS\Models\UserModel;

/**
 * Notification dispatch — in-app + email.
 */
final class NotificationService
{
    private NotificationModel $notificationModel;
    private EmailService $emailService;
    private UserModel $userModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
        $this->emailService      = new EmailService();
        $this->userModel         = new UserModel();
    }

    public function notifyUser(string $userId, string $type, string $title, string $message, array $metadata = []): void
    {
        $this->notificationModel->notify($userId, $type, $title, $message, $metadata);

        $user = $this->userModel->findById($userId);
        if ($user && !empty($user['email'])) {
            $this->emailService->send(
                $user['email'],
                "[PMS] {$title}",
                "<p>{$message}</p>"
            );
        }
    }

    /** Broadcast drive announcement — all students or department-scoped user IDs */
    public function announceDrive(string $driveTitle, string $date, ?array $userIds = null): void
    {
        if ($userIds !== null) {
            foreach ($userIds as $uid) {
                $this->notifyUser(
                    $uid,
                    'drive_announcement',
                    'New Placement Drive',
                    "Drive \"{$driveTitle}\" scheduled on {$date}. Check eligible drives and apply.",
                    ['driveTitle' => $driveTitle]
                );
            }
            return;
        }

        $students = $this->userModel->findByRole('student', 1000);
        foreach ($students as $user) {
            $this->notifyUser(
                (string) $user['_id'],
                'drive_announcement',
                'New Placement Drive',
                "Drive \"{$driveTitle}\" scheduled on {$date}. Check eligible drives and apply.",
                ['driveTitle' => $driveTitle]
            );
        }
    }

    public function notifySelectionUpdate(string $userId, string $companyName, string $status): void
    {
        $this->notifyUser(
            $userId,
            'selection_update',
            'Application Status Updated',
            "Your application for {$companyName} is now: {$status}.",
            ['status' => $status]
        );
    }
}
