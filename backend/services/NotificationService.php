<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\BroadcastLogModel;
use PMS\Models\CompanyModel;
use PMS\Models\NotificationModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

/**
 * Notification dispatch — in-app + email + broadcasts.
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

    public function notifyUser(string $userId, string $type, string $title, string $message, array $metadata = [], bool $sendEmail = true): void
    {
        $this->notificationModel->notify($userId, $type, $title, $message, $metadata);

        if (!$sendEmail) {
            return;
        }

        $user = $this->userModel->findById($userId);
        if ($user && !empty($user['email'])) {
            $this->emailService->send(
                $user['email'],
                "[PlaceHub] {$title}",
                "<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>"
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

        $students = $this->userModel->findByRole('student', 5000);
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
            ['status' => $status, 'company' => $companyName]
        );
    }

    public function notifyApplicationUpdate(string $studentUserId, string $title, string $message, array $metadata = []): void
    {
        $this->notifyUser($studentUserId, 'application_update', $title, $message, $metadata);
    }

    /**
     * Notify all admin accounts about staff/alumni submissions.
     */
    public function notifyAdmins(string $type, string $title, string $message, array $metadata = []): void
    {
        foreach ($this->userModel->findByRole('admin', 100) as $admin) {
            $this->notifyUser((string) $admin['_id'], $type, $title, $message, $metadata, false);
        }
    }

    /**
     * @return array{recipientCount: int, emailSentCount: int, logId: string}
     */
    public function broadcast(
        array $sender,
        string $title,
        string $message,
        string $audience,
        ?string $departmentId = null,
        bool $sendEmail = true
    ): array {
        $userIds = $this->resolveBroadcastRecipients($audience, $departmentId);
        $emailSent = 0;

        foreach ($userIds as $uid) {
            $this->notifyUser(
                $uid,
                'broadcast',
                $title,
                $message,
                ['audience' => $audience],
                $sendEmail
            );
            if ($sendEmail) {
                $emailSent++;
            }
        }

        $logId = (new BroadcastLogModel())->logBroadcast([
            'sentBy'         => (string) ($sender['_id'] ?? ''),
            'sentByName'     => (string) ($sender['name'] ?? ''),
            'sentByRole'     => (string) ($sender['role'] ?? ''),
            'title'          => $title,
            'message'        => $message,
            'audience'       => $audience,
            'audienceLabel'  => $this->audienceLabel($audience, $departmentId),
            'departmentId'   => $departmentId,
            'recipientCount' => count($userIds),
            'emailSentCount' => $sendEmail ? $emailSent : 0,
            'sendEmail'      => $sendEmail,
        ]);

        return [
            'recipientCount' => count($userIds),
            'emailSentCount' => $sendEmail ? $emailSent : 0,
            'logId'          => $logId,
        ];
    }

    /**
     * @return string[]
     */
    private function resolveBroadcastRecipients(string $audience, ?string $departmentId): array
    {
        return match ($audience) {
            'students' => $this->studentUserIds($departmentId),
            'staff'    => $this->roleUserIds('staff'),
            'alumni'   => $this->roleUserIds('alumni'),
            'officers' => $this->roleUserIds('placement_officer'),
            'companies'=> $this->companyUserIds(),
            'everyone' => $this->allActiveUserIds(),
            default    => $this->studentUserIds($departmentId),
        };
    }

    /**
     * @return string[]
     */
    private function studentUserIds(?string $departmentId): array
    {
        if ($departmentId) {
            $oid = Security::toObjectId($departmentId);
            if (!$oid) {
                return [];
            }
            $students = (new StudentModel())->findAll(['departmentId' => $oid], 5000);
            return array_values(array_filter(array_map(
                static fn (array $s): string => (string) ($s['userId'] ?? ''),
                $students
            )));
        }

        return array_map(
            static fn (array $u): string => (string) $u['_id'],
            $this->userModel->findByRole('student', 5000)
        );
    }

    /**
     * @return string[]
     */
    private function roleUserIds(string $role): array
    {
        return array_map(
            static fn (array $u): string => (string) $u['_id'],
            $this->userModel->findByRole($role, 5000)
        );
    }

    /**
     * @return string[]
     */
    private function companyUserIds(): array
    {
        $ids = [];
        foreach ((new CompanyModel())->findAll([], 500) as $company) {
            $uid = (string) ($company['userId'] ?? '');
            if ($uid !== '') {
                $ids[] = $uid;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @return string[]
     */
    private function allActiveUserIds(): array
    {
        $ids = [];
        foreach (['student', 'staff', 'alumni', 'placement_officer', 'company', 'admin'] as $role) {
            foreach ($this->userModel->findByRole($role, 5000) as $user) {
                if (($user['status'] ?? 'active') === 'blocked') {
                    continue;
                }
                $ids[] = (string) $user['_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    private function audienceLabel(string $audience, ?string $departmentId): string
    {
        if ($audience === 'students' && $departmentId) {
            return 'Students in selected department';
        }

        return match ($audience) {
            'students'  => 'All students',
            'staff'     => 'All staff',
            'alumni'    => 'All alumni',
            'officers'  => 'Placement officers',
            'companies' => 'Company recruiters',
            'everyone'  => 'All users',
            default     => $audience,
        };
    }

    public function unreadCount(string $userId): int
    {
        return $this->notificationModel->countUnread($userId);
    }
}
