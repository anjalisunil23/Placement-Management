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
 * Notification dispatch — in-app + email (student Gmail) + WhatsApp + broadcasts.
 */
final class NotificationService
{
    /** @var list<string> */
    private const WHATSAPP_PLACEMENT_TYPES = [
        'placement_approved',
        'placement_rejected',
        'placement_report_submitted',
        'selection_update',
        'application_update',
    ];

    /** Student-facing types that always email (drives, applications, placements). */
    /** @var list<string> */
    private const EMAIL_STUDENT_TYPES = [
        'drive_announcement',
        'application_update',
        'selection_update',
        'placement_approved',
        'placement_rejected',
        'placement_report_submitted',
    ];

    private NotificationModel $notificationModel;
    private EmailService $emailService;
    private WhatsAppService $whatsAppService;
    private UserModel $userModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
        $this->emailService      = new EmailService();
        $this->whatsAppService   = new WhatsAppService();
        $this->userModel         = new UserModel();
    }

    public function notifyUser(string $userId, string $type, string $title, string $message, array $metadata = [], bool $sendEmail = true): void
    {
        $this->notificationModel->notify($userId, $type, $title, $message, $metadata);

        $shouldEmail = $sendEmail || in_array($type, self::EMAIL_STUDENT_TYPES, true);
        if ($shouldEmail) {
            $this->maybeSendStudentEmail($userId, $type, $title, $message);
        }

        $this->maybeSendPlacementWhatsApp($userId, $type, $title, $message);
    }

    /**
     * Email student Gmail / personal address for drives, applications, placements.
     */
    private function maybeSendStudentEmail(string $userId, string $type, string $title, string $message): void
    {
        $email = $this->resolveStudentEmail($userId);
        if ($email === '') {
            return;
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $body = <<<HTML
<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#0F172A">
  <p style="margin:0 0 12px;font-size:18px;font-weight:600">{$safeTitle}</p>
  <p style="margin:0 0 16px">{$safeMessage}</p>
  <p style="margin:0;color:#64748B;font-size:13px">AJCE Placement Cell · <a href="https://placements.amaljyothi.ac.in">placements.amaljyothi.ac.in</a></p>
</div>
HTML;

        try {
            $result = $this->emailService->sendMail([
                'to'      => $email,
                'subject' => '[AJCE Placements] ' . $title,
                'body'    => $body,
            ]);
            if (!$result['ok']) {
                error_log('[PMS Email] Failed for user ' . $userId . ': ' . ($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            error_log('[PMS Email] Exception: ' . $e->getMessage());
        }
    }

    /**
     * Prefer personal Gmail, then other personal email, then account email.
     */
    private function resolveStudentEmail(string $userId): string
    {
        $candidates = [];

        $student = (new StudentModel())->findByUserId($userId);
        if (is_array($student)) {
            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $candidates[] = (string) ($personal['personalEmail'] ?? '');
            $candidates[] = (string) ($personal['email'] ?? '');
            $candidates[] = (string) ($student['personalEmail'] ?? '');
            $candidates[] = (string) ($student['stud_personal_mails'] ?? '');
        }

        $user = $this->userModel->findById($userId);
        if (is_array($user)) {
            $candidates[] = (string) ($user['personalEmail'] ?? '');
            $candidates[] = (string) ($user['email'] ?? '');
        }

        $valid = [];
        foreach ($candidates as $raw) {
            $email = strtolower(trim($raw));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!in_array($email, $valid, true)) {
                $valid[] = $email;
            }
        }

        foreach ($valid as $email) {
            if (str_ends_with($email, '@gmail.com') || str_ends_with($email, '@googlemail.com')) {
                return $email;
            }
        }

        foreach ($valid as $email) {
            if (!$this->isCollegeEmail($email)) {
                return $email;
            }
        }

        return $valid[0] ?? '';
    }

    private function isCollegeEmail(string $email): bool
    {
        return (bool) preg_match('/@(students\.)?amaljyothi\.ac\.in$|\.ajce\.in$/i', $email);
    }

    /**
     * Send WhatsApp for student placement / application status updates.
     */
    private function maybeSendPlacementWhatsApp(string $userId, string $type, string $title, string $message): void
    {
        if (!$this->whatsAppService->isEnabled()) {
            return;
        }
        if (!in_array($type, self::WHATSAPP_PLACEMENT_TYPES, true)) {
            return;
        }

        $phone = $this->resolveStudentPhone($userId);
        if ($phone === '') {
            return;
        }

        $body = trim($message);
        if (mb_strlen($body) > 900) {
            $body = mb_substr($body, 0, 897) . '...';
        }

        try {
            $result = $this->whatsAppService->sendPlacementUpdate($phone, $title, $body);
            if (!$result['ok']) {
                error_log('[PMS WhatsApp] Failed for user ' . $userId . ': ' . ($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            error_log('[PMS WhatsApp] Exception: ' . $e->getMessage());
        }
    }

    /**
     * Prefer student personal.phone; fall back to user / AES-synced fields.
     */
    private function resolveStudentPhone(string $userId): string
    {
        $candidates = [];

        $student = (new StudentModel())->findByUserId($userId);
        if (is_array($student)) {
            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $candidates[] = (string) ($personal['phone'] ?? '');
            $candidates[] = (string) ($personal['mobile'] ?? '');
            $candidates[] = (string) ($student['phone'] ?? '');
            $candidates[] = (string) ($student['stud_mobiles'] ?? '');
        }

        $user = $this->userModel->findById($userId);
        if (is_array($user)) {
            $candidates[] = (string) ($user['phone'] ?? '');
            $candidates[] = (string) ($user['mobile'] ?? '');
        }

        foreach ($candidates as $raw) {
            $normalized = $this->whatsAppService->normalizePhone($raw);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * Notify admins and placement officers about student-submitted placement reports.
     */
    public function notifyPlacementCell(string $type, string $title, string $message, array $metadata = [], bool $sendEmail = true): void
    {
        $this->notifyAdmins($type, $title, $message, $metadata);

        foreach ($this->userModel->findByRole('placement_officer', 200) as $officer) {
            $uid = (string) ($officer['_id'] ?? '');
            if ($uid === '' || !Security::isValidId($uid)) {
                continue;
            }
            $this->notifyUser($uid, $type, $title, $message, $metadata, $sendEmail);
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
