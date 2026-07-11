<?php

declare(strict_types=1);

namespace PMS\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Outbound email — ElasticEmail API (primary) with optional SMTP fallback.
 *
 * Used for drive announcements, application updates, and placement notices
 * delivered to student Gmail / personal addresses.
 */
final class EmailService
{
    private array $config;

    public function __construct()
    {
        $app = require dirname(__DIR__) . '/config/app.php';
        $this->config = is_array($app['mail'] ?? null) ? $app['mail'] : [];
    }

    public function isEnabled(): bool
    {
        $driver = $this->driver();
        if ($driver === 'elasticemail') {
            return trim((string) ($this->config['api_key'] ?? '')) !== '';
        }
        if ($driver === 'smtp') {
            return trim((string) ($this->config['username'] ?? '')) !== '';
        }
        return true; // log driver
    }

    /**
     * @return array{ok:bool,response:?string,error:?string}
     */
    public function sendMail(array $params): array
    {
        $to = strtolower(trim((string) ($params['to'] ?? '')));
        $subject = trim((string) ($params['subject'] ?? ''));
        $body = (string) ($params['body'] ?? $params['bodyHtml'] ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'response' => null, 'error' => 'Invalid recipient'];
        }
        if ($subject === '') {
            return ['ok' => false, 'response' => null, 'error' => 'Missing subject'];
        }

        $from = trim((string) ($params['from'] ?? $this->config['from'] ?? 'info@aessas.org'));
        $fromName = trim((string) ($params['name'] ?? $params['fromName'] ?? $this->config['from_name'] ?? 'Placement Cell - Amal Jyothi'));
        $attachmentPath = isset($params['attachment']) && is_string($params['attachment'])
            ? $params['attachment']
            : null;

        if (!$this->isEnabled() || $this->driver() === 'log') {
            error_log("[PMS Email] To: {$to} | Subject: {$subject}" . ($attachmentPath ? " | Attachment: {$attachmentPath}" : ''));
            return ['ok' => true, 'response' => 'logged', 'error' => null];
        }

        if ($this->driver() === 'elasticemail' && ($attachmentPath === null || $attachmentPath === '')) {
            return $this->sendViaElasticEmail($to, $subject, $body, $from, $fromName);
        }

        $ok = $this->sendViaSmtp($to, $subject, $body, true, $attachmentPath, $from, $fromName);
        return ['ok' => $ok, 'response' => null, 'error' => $ok ? null : 'SMTP send failed'];
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = true, ?string $attachmentPath = null): bool
    {
        $html = $isHtml ? $body : '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>';
        $result = $this->sendMail([
            'to'         => $to,
            'subject'    => $subject,
            'body'       => $html,
            'attachment' => $attachmentPath,
        ]);
        return $result['ok'];
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

    private function driver(): string
    {
        $driver = strtolower(trim((string) ($this->config['driver'] ?? 'elasticemail')));
        if ($driver === 'elasticemail' && trim((string) ($this->config['api_key'] ?? '')) === '') {
            if (trim((string) ($this->config['username'] ?? '')) !== '') {
                return 'smtp';
            }
            return 'log';
        }
        return $driver !== '' ? $driver : 'log';
    }

    /**
     * @return array{ok:bool,response:?string,error:?string}
     */
    private function sendViaElasticEmail(
        string $to,
        string $subject,
        string $bodyHtml,
        string $from,
        string $fromName
    ): array {
        try {
            $post = [
                'from'           => $from !== '' ? $from : 'info@aessas.org',
                'fromName'       => $fromName !== '' ? $fromName : 'Placement Cell - Amal Jyothi',
                'apikey'         => (string) $this->config['api_key'],
                'subject'        => $subject,
                'to'             => $to,
                'bodyHtml'       => $bodyHtml,
                'bodyText'       => trim(strip_tags($bodyHtml)),
                'isTransactional'=> 'true',
                'trackOpens'     => 'false',
                'trackClicks'    => 'false',
            ];

            $ch = curl_init();
            if ($ch === false) {
                return ['ok' => false, 'response' => null, 'error' => 'Could not init cURL'];
            }

            curl_setopt_array($ch, [
                CURLOPT_URL            => (string) ($this->config['endpoint'] ?? 'https://api.elasticemail.com/v2/email/send'),
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 20),
                CURLOPT_SSL_VERIFYPEER => !empty($this->config['verify_ssl']),
            ]);

            $result = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = $errno ? curl_error($ch) : null;
            curl_close($ch);

            if ($errno) {
                error_log('[PMS Email] ElasticEmail cURL: ' . $error);
                return ['ok' => false, 'response' => is_string($result) ? $result : null, 'error' => $error];
            }

            $decoded = is_string($result) ? json_decode($result, true) : null;
            $success = is_array($decoded) ? !empty($decoded['success']) : (is_string($result) && str_contains($result, '"success":true'));
            if (!$success) {
                error_log('[PMS Email] ElasticEmail response: ' . (is_string($result) ? $result : 'empty'));
                return ['ok' => false, 'response' => is_string($result) ? $result : null, 'error' => 'ElasticEmail rejected send'];
            }

            return ['ok' => true, 'response' => is_string($result) ? $result : null, 'error' => null];
        } catch (\Throwable $ex) {
            error_log('[PMS Email] ' . $ex->getMessage());
            return ['ok' => false, 'response' => null, 'error' => $ex->getMessage()];
        }
    }

    private function sendViaSmtp(
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        ?string $attachmentPath,
        string $from,
        string $fromName
    ): bool {
        if (empty($this->config['username'])) {
            error_log("[PMS Email SMTP fallback] To: {$to} | Subject: {$subject}");
            return true;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = (string) ($this->config['host'] ?? 'localhost');
            $mail->SMTPAuth   = true;
            $mail->Username   = (string) $this->config['username'];
            $mail->Password   = (string) ($this->config['password'] ?? '');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) ($this->config['port'] ?? 587);

            $mail->setFrom($from !== '' ? $from : (string) $this->config['from'], $fromName);
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
}
