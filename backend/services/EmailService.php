<?php

declare(strict_types=1);

namespace PMS\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Email notification service.
 */
final class EmailService
{
    private array $config;

    public function __construct()
    {
        $app = require dirname(__DIR__) . '/config/app.php';
        $this->config = $app['mail'];
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = true, ?string $attachmentPath = null): bool
    {
        if (empty($this->config['username'])) {
            error_log("[PMS Email] To: {$to} | Subject: {$subject}" . ($attachmentPath ? " | Attachment: {$attachmentPath}" : ''));
            return true;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'];
            $mail->Password   = $this->config['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->config['port'];

            $mail->setFrom($this->config['from'], $this->config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $body;
            } else {
                $mail->Body = strip_tags($body);
            }
            if ($attachmentPath && is_file($attachmentPath)) {
                $mail->addAttachment($attachmentPath, basename($attachmentPath));
            }
            $mail->send();
            return true;
        } catch (MailException $e) {
            error_log('[PMS Email] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string[] $recipients
     */
    public function sendReportToManagement(array $recipients, string $reportPath, string $reportType): void
    {
        $subject = "PMS {$reportType} Report — " . date('Y-m-d');
        $body = "<p>Please find the attached {$reportType} placement report.</p>";
        foreach ($recipients as $email) {
            if ($email === '') {
                continue;
            }
            $this->send($email, $subject, $body, true, is_file($reportPath) ? $reportPath : null);
        }
    }
}
