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
        $this->mergeLocalMailConfig();
        $this->mergeSettingsMailConfig();
    }

    /** Overlay backend/config/mail.local.php when present (gitignored). */
    private function mergeLocalMailConfig(): void
    {
        $path = dirname(__DIR__) . '/config/mail.local.php';
        if (!is_file($path)) {
            return;
        }
        $local = require $path;
        if (!is_array($local)) {
            return;
        }
        if (trim((string) ($this->config['api_key'] ?? '')) === '' && trim((string) ($local['api_key'] ?? '')) !== '') {
            $this->config['api_key'] = trim((string) $local['api_key']);
        }
        if (trim((string) ($this->config['from'] ?? '')) === '' && trim((string) ($local['from'] ?? '')) !== '') {
            $this->config['from'] = trim((string) $local['from']);
        }
        if (trim((string) ($this->config['from_name'] ?? '')) === '' && trim((string) ($local['from_name'] ?? '')) !== '') {
            $this->config['from_name'] = trim((string) $local['from_name']);
        }
        // Prefer verified local from address when env still has placeholder college.edu
        $from = strtolower(trim((string) ($this->config['from'] ?? '')));
        if (($from === '' || str_ends_with($from, '@college.edu')) && trim((string) ($local['from'] ?? '')) !== '') {
            $this->config['from'] = trim((string) $local['from']);
        }
    }

    /** Overlay ElasticEmail key saved in System Settings (admin UI). */
    private function mergeSettingsMailConfig(): void
    {
        if (trim((string) ($this->config['api_key'] ?? '')) !== '') {
            return;
        }
        try {
            $raw = (new \PMS\Models\SystemSettingsModel())->getRaw();
            $key = trim((string) ($raw['elasticEmailApiKey'] ?? ''));
            if ($key !== '') {
                $this->config['api_key'] = $key;
            }
            $from = trim((string) ($raw['emailFrom'] ?? ''));
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $envFrom = strtolower(trim((string) ($this->config['from'] ?? '')));
                if ($envFrom === '' || str_ends_with($envFrom, '@college.edu')) {
                    $this->config['from'] = $from;
                }
            }
        } catch (\Throwable $e) {
            // DB may be unavailable during CLI smoke without Mongo — ignore.
        }
    }

    public function isEnabled(): bool
    {
        $driver = $this->driver();
        if ($driver === 'elasticemail') {
            return $this->apiKey() !== '';
        }
        if ($driver === 'smtp') {
            return trim((string) ($this->config['username'] ?? '')) !== '';
        }
        return $driver === 'log';
    }

    /**
     * @return array{driver:string,enabled:bool,hasApiKey:bool,hasSmtp:bool,from:string,endpoint:string}
     */
    public function status(): array
    {
        return [
            'driver'    => $this->driver(),
            'enabled'   => $this->isEnabled(),
            'hasApiKey' => $this->apiKey() !== '',
            'hasSmtp'   => trim((string) ($this->config['username'] ?? '')) !== '',
            'from'      => (string) ($this->config['from'] ?? ''),
            'endpoint'  => (string) ($this->config['endpoint'] ?? ''),
        ];
    }

    /**
     * @return array{ok:bool,response:?string,error:?string,driver:?string}
     */
    public function sendMail(array $params): array
    {
        $to = strtolower(trim((string) ($params['to'] ?? '')));
        $subject = trim((string) ($params['subject'] ?? ''));
        $body = (string) ($params['body'] ?? $params['bodyHtml'] ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'response' => null, 'error' => 'Invalid recipient', 'driver' => null];
        }
        if ($subject === '') {
            return ['ok' => false, 'response' => null, 'error' => 'Missing subject', 'driver' => null];
        }

        $from = trim((string) ($params['from'] ?? $this->config['from'] ?? 'info@aessas.org'));
        $fromName = trim((string) ($params['name'] ?? $params['fromName'] ?? $this->config['from_name'] ?? 'Placement Cell - Amal Jyothi'));
        $attachmentPath = isset($params['attachment']) && is_string($params['attachment'])
            ? $params['attachment']
            : null;

        $driver = $this->driver();

        if ($driver === 'log') {
            error_log("[PMS Email] To: {$to} | Subject: {$subject}" . ($attachmentPath ? " | Attachment: {$attachmentPath}" : ''));
            return ['ok' => true, 'response' => 'logged', 'error' => null, 'driver' => 'log'];
        }

        if ($driver === 'elasticemail') {
            if ($this->apiKey() === '') {
                return [
                    'ok' => false,
                    'response' => null,
                    'error' => 'ELASTICEMAIL_API_KEY is not configured. Add it in .env, backend/config/mail.local.php, or System Settings.',
                    'driver' => 'elasticemail',
                ];
            }

            // Prefer SMTP for attachments when configured. Otherwise use ElasticEmail v4,
            // which supports base64 file attachments in transactional messages.
            if ($attachmentPath !== null && $attachmentPath !== '' && is_file($attachmentPath)) {
                if (trim((string) ($this->config['username'] ?? '')) !== '') {
                    $ok = $this->sendViaSmtp($to, $subject, $body, true, $attachmentPath, $from, $fromName);
                    return ['ok' => $ok, 'response' => null, 'error' => $ok ? null : 'SMTP send failed', 'driver' => 'smtp'];
                }
                $endpoint = trim((string) ($this->config['endpoint'] ?? ''));
                if (!str_contains(strtolower($endpoint), '/v4/')) {
                    $endpoint = 'https://api.elasticemail.com/v4/emails/transactional';
                }
                $result = $this->sendViaElasticEmailV4(
                    $to,
                    $subject,
                    $body,
                    $from,
                    $fromName,
                    $endpoint,
                    $attachmentPath
                );

                return $result + ['driver' => 'elasticemail'];
            }

            $result = $this->sendViaElasticEmail($to, $subject, $body, $from, $fromName);
            if ($result['ok']) {
                return $result + ['driver' => 'elasticemail'];
            }

            // Fall back to SMTP if configured.
            if (trim((string) ($this->config['username'] ?? '')) !== '') {
                $ok = $this->sendViaSmtp($to, $subject, $body, true, $attachmentPath, $from, $fromName);
                if ($ok) {
                    return [
                        'ok' => true,
                        'response' => $result['response'],
                        'error' => null,
                        'driver' => 'smtp',
                    ];
                }
            }

            return $result + ['driver' => 'elasticemail'];
        }

        if ($driver === 'smtp') {
            if (trim((string) ($this->config['username'] ?? '')) === '') {
                return [
                    'ok' => false,
                    'response' => null,
                    'error' => 'MAIL_USERNAME is not configured in .env',
                    'driver' => 'smtp',
                ];
            }
            $ok = $this->sendViaSmtp($to, $subject, $body, true, $attachmentPath, $from, $fromName);
            return ['ok' => $ok, 'response' => null, 'error' => $ok ? null : 'SMTP send failed', 'driver' => 'smtp'];
        }

        return ['ok' => false, 'response' => null, 'error' => 'Unknown mail driver: ' . $driver, 'driver' => $driver];
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
        $localPath = $reportPath;
        $temp = false;
        if ($reportPath !== '' && !is_file($reportPath)) {
            try {
                $materialized = (new ObjectStorageService())->materialize($reportPath);
                $localPath = $materialized['path'];
                $temp = $materialized['temp'];
            } catch (\Throwable) {
                $localPath = '';
            }
        }
        try {
            foreach ($recipients as $email) {
                if ($email === '') {
                    continue;
                }
                $this->send($email, $subject, $body, true, ($localPath !== '' && is_file($localPath)) ? $localPath : null);
            }
        } finally {
            if ($temp && $localPath !== '' && is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function apiKey(): string
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        // Last-resort fallback used by campus AES mailers (override via .env / mail.local.php / System Settings).
        return '84CCEEF6C93B5C43AE8F113D82C5CD31A59AEE3156BFEF9D239FEE3758CE8365F596821CAECE660EE3B504372D70B3B5';
    }

    private function driver(): string
    {
        $driver = strtolower(trim((string) ($this->config['driver'] ?? 'elasticemail')));
        if ($driver === '') {
            $driver = 'elasticemail';
        }

        // Only fall back to log when explicitly requested, or when nothing is configured.
        if ($driver === 'elasticemail' && $this->apiKey() === '') {
            if (trim((string) ($this->config['username'] ?? '')) !== '') {
                return 'smtp';
            }
            $env = strtolower((string) (($_ENV['APP_ENV'] ?? 'production')));
            return in_array($env, ['development', 'local', 'test'], true) ? 'log' : 'elasticemail';
        }

        return $driver;
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
        $endpoint = trim((string) ($this->config['endpoint'] ?? 'https://api.elasticemail.com/v2/email/send'));
        if ($endpoint === '') {
            $endpoint = 'https://api.elasticemail.com/v2/email/send';
        }

        // Prefer v4 when endpoint says so, otherwise try v2 then v4.
        if (str_contains(strtolower($endpoint), '/v4/')) {
            return $this->sendViaElasticEmailV4($to, $subject, $bodyHtml, $from, $fromName, $endpoint);
        }

        $v2 = $this->sendViaElasticEmailV2($to, $subject, $bodyHtml, $from, $fromName, $endpoint);
        if ($v2['ok']) {
            return $v2;
        }

        // Auto-upgrade path if v2 key/account expects REST v4.
        $v4Endpoint = 'https://api.elasticemail.com/v4/emails/transactional';
        $v4 = $this->sendViaElasticEmailV4($to, $subject, $bodyHtml, $from, $fromName, $v4Endpoint);
        if ($v4['ok']) {
            return $v4;
        }

        return [
            'ok' => false,
            'response' => $v4['response'] ?? $v2['response'],
            'error' => $v2['error'] ?: ($v4['error'] ?? 'ElasticEmail rejected send'),
        ];
    }

    /**
     * ElasticEmail legacy HTTP API (form-urlencoded).
     *
     * @return array{ok:bool,response:?string,error:?string}
     */
    private function sendViaElasticEmailV2(
        string $to,
        string $subject,
        string $bodyHtml,
        string $from,
        string $fromName,
        string $endpoint
    ): array {
        try {
            $post = [
                'apikey'          => $this->apiKey(),
                'from'            => $from !== '' ? $from : 'info@aessas.org',
                'fromName'        => $fromName !== '' ? $fromName : 'Placement Cell - Amal Jyothi',
                'subject'         => $subject,
                'to'              => $to,
                'bodyHtml'        => $bodyHtml,
                'bodyText'        => trim(strip_tags($bodyHtml)),
                'isTransactional' => 'true',
                'trackOpens'      => 'false',
                'trackClicks'     => 'false',
            ];

            // Must be urlencoded — array POSTFIELDS becomes multipart and ElasticEmail rejects it.
            $result = $this->curlPost($endpoint, http_build_query($post), [
                'Content-Type: application/x-www-form-urlencoded',
            ]);

            if (!$result['ok']) {
                return $result;
            }

            $raw = (string) ($result['response'] ?? '');
            $decoded = json_decode($raw, true);
            $success = is_array($decoded)
                ? !empty($decoded['success'])
                : str_contains($raw, '"success":true');

            if (!$success) {
                $err = is_array($decoded) ? (string) ($decoded['error'] ?? 'ElasticEmail rejected send') : 'ElasticEmail rejected send';
                error_log('[PMS Email] ElasticEmail v2: ' . $err . ' | ' . $raw);
                return ['ok' => false, 'response' => $raw, 'error' => $err];
            }

            return ['ok' => true, 'response' => $raw, 'error' => null];
        } catch (\Throwable $ex) {
            error_log('[PMS Email] ' . $ex->getMessage());
            return ['ok' => false, 'response' => null, 'error' => $ex->getMessage()];
        }
    }

    /**
     * ElasticEmail REST API v4 transactional send.
     *
     * @return array{ok:bool,response:?string,error:?string}
     */
    private function sendViaElasticEmailV4(
        string $to,
        string $subject,
        string $bodyHtml,
        string $from,
        string $fromName,
        string $endpoint,
        ?string $attachmentPath = null
    ): array {
        try {
            $fromHeader = trim(($fromName !== '' ? $fromName . ' ' : '') . '<' . ($from !== '' ? $from : 'info@aessas.org') . '>');
            $payload = [
                'Recipients' => [
                    'To' => [$to],
                ],
                'Content' => [
                    'From' => $fromHeader,
                    'Subject' => $subject,
                    'Body' => [
                        [
                            'ContentType' => 'HTML',
                            'Content' => $bodyHtml,
                        ],
                        [
                            'ContentType' => 'PlainText',
                            'Content' => trim(strip_tags($bodyHtml)),
                        ],
                    ],
                ],
                'Options' => [
                    'TrackOpens' => false,
                    'TrackClicks' => false,
                ],
            ];
            if ($attachmentPath !== null && $attachmentPath !== '') {
                if (!is_file($attachmentPath) || !is_readable($attachmentPath)) {
                    return ['ok' => false, 'response' => null, 'error' => 'Email attachment is not readable'];
                }
                $binary = file_get_contents($attachmentPath);
                if ($binary === false) {
                    return ['ok' => false, 'response' => null, 'error' => 'Email attachment could not be read'];
                }
                $mime = function_exists('mime_content_type')
                    ? (string) (mime_content_type($attachmentPath) ?: 'application/octet-stream')
                    : 'application/octet-stream';
                $payload['Content']['Attachments'] = [[
                    'BinaryContent' => base64_encode($binary),
                    'Name'          => basename($attachmentPath),
                    'ContentType'   => $mime,
                    'Size'          => strlen($binary),
                ]];
            }

            $result = $this->curlPost($endpoint, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-ElasticEmail-ApiKey: ' . $this->apiKey(),
            ]);

            if (!$result['ok']) {
                return $result;
            }

            $raw = (string) ($result['response'] ?? '');
            $decoded = json_decode($raw, true);
            // v4 success usually returns MessageID / TransactionID without a "success" flag.
            if (is_array($decoded) && (
                isset($decoded['MessageID'])
                || isset($decoded['TransactionID'])
                || isset($decoded['messageId'])
                || isset($decoded['transactionId'])
            )) {
                return ['ok' => true, 'response' => $raw, 'error' => null];
            }

            if (is_array($decoded) && (!empty($decoded['Error']) || isset($decoded['error']))) {
                $err = (string) ($decoded['Error'] ?? $decoded['error'] ?? 'ElasticEmail v4 rejected send');
                error_log('[PMS Email] ElasticEmail v4: ' . $err . ' | ' . $raw);
                return ['ok' => false, 'response' => $raw, 'error' => $err];
            }

            // HTTP 200 with empty/unknown body — treat as success only if no error text.
            if ($raw === '' || $raw === '{}' || $raw === 'null') {
                return ['ok' => true, 'response' => $raw, 'error' => null];
            }

            if (is_array($decoded) && empty($decoded['Error']) && empty($decoded['error'])) {
                return ['ok' => true, 'response' => $raw, 'error' => null];
            }

            error_log('[PMS Email] ElasticEmail v4 unexpected: ' . $raw);
            return ['ok' => false, 'response' => $raw, 'error' => 'ElasticEmail v4 rejected send'];
        } catch (\Throwable $ex) {
            error_log('[PMS Email] ' . $ex->getMessage());
            return ['ok' => false, 'response' => null, 'error' => $ex->getMessage()];
        }
    }

    /**
     * @param string[] $headers
     * @return array{ok:bool,response:?string,error:?string}
     */
    private function curlPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['ok' => false, 'response' => null, 'error' => 'Could not init cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => array_key_exists('verify_ssl', $this->config)
                ? !empty($this->config['verify_ssl'])
                : true,
        ]);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            error_log('[PMS Email] cURL: ' . $error);
            return ['ok' => false, 'response' => is_string($result) ? $result : null, 'error' => $error];
        }

        $raw = is_string($result) ? $result : null;
        if ($status >= 400) {
            $decoded = $raw ? json_decode($raw, true) : null;
            $err = is_array($decoded)
                ? (string) ($decoded['Error'] ?? $decoded['error'] ?? $decoded['message'] ?? ("HTTP {$status}"))
                : ("HTTP {$status}");
            error_log('[PMS Email] HTTP ' . $status . ': ' . ($raw ?? ''));
            return ['ok' => false, 'response' => $raw, 'error' => $err];
        }

        return ['ok' => true, 'response' => $raw, 'error' => null];
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
            return false;
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
                $mail->AltBody = trim(strip_tags($body));
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
