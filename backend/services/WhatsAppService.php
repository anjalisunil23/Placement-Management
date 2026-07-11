<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * WhatsApp template sender via AES WAPI (wapi.aesajce.in).
 *
 * Template: ajce_official_notification
 * - header: 1 text parameter
 * - body: 2 text parameters (message, footer/signature)
 */
final class WhatsAppService
{
    private array $config;

    public function __construct()
    {
        $app = require dirname(__DIR__) . '/config/app.php';
        $this->config = is_array($app['whatsapp'] ?? null) ? $app['whatsapp'] : [];
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']) && trim((string) ($this->config['endpoint'] ?? '')) !== '';
    }

    /**
     * @param list<string> $bodyParams
     * @return array{ok:bool,response:?string,error:?string}
     */
    public function send(string $to, string $header, array $bodyParams): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'response' => null, 'error' => 'WhatsApp disabled'];
        }

        $phone = $this->normalizePhone($to);
        if ($phone === '') {
            return ['ok' => false, 'response' => null, 'error' => 'Invalid phone number'];
        }

        $header = trim($header);
        if ($header === '') {
            $header = 'AJCE Placements';
        }

        $bodyParams = array_values(array_map(
            static fn ($p): string => trim((string) $p),
            $bodyParams
        ));
        while (count($bodyParams) < 2) {
            $bodyParams[] = 'AJCE Placement Cell';
        }
        $bodyParams = array_slice($bodyParams, 0, 2);

        $waParams = [];
        foreach ($bodyParams as $param) {
            $waParams[] = ['type' => 'text', 'text' => $param !== '' ? $param : '-'];
        }

        $endpoint = rtrim((string) ($this->config['endpoint'] ?? 'https://wapi.aesajce.in/send'), '/');
        $tag = (string) ($this->config['tag'] ?? 'AJCE Placements');
        $templateName = (string) ($this->config['template'] ?? 'ajce_official_notification');

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['ok' => false, 'response' => null, 'error' => 'Could not init cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 15),
            CURLOPT_POSTFIELDS     => [
                'to'       => $phone,
                'tag'      => $tag,
                'template' => json_encode([
                    'name'       => $templateName,
                    'language'   => ['code' => 'en'],
                    'components' => [
                        [
                            'type'       => 'header',
                            'parameters' => [['type' => 'text', 'text' => $header]],
                        ],
                        [
                            'type'       => 'body',
                            'parameters' => $waParams,
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        curl_close($ch);

        if ($errno) {
            error_log('[PMS WhatsApp] cURL error: ' . $error);
            return ['ok' => false, 'response' => is_string($response) ? $response : null, 'error' => $error];
        }

        return ['ok' => true, 'response' => is_string($response) ? $response : null, 'error' => null];
    }

    /**
     * Placement update helper — header = title, body = [message, signature].
     *
     * @return array{ok:bool,response:?string,error:?string}
     */
    public function sendPlacementUpdate(string $to, string $title, string $message): array
    {
        $signature = (string) ($this->config['signature'] ?? 'AJCE Placement Cell');
        return $this->send($to, $title, [$message, $signature]);
    }

    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        // Strip leading country code 91 for 12-digit Indian mobiles.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // WAPI examples use 10-digit local numbers.
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits) === 1) {
            return $digits;
        }

        // Allow already-international numbers if configured length looks valid.
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return $digits;
        }

        return '';
    }
}
