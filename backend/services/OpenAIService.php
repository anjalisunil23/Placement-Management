<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * OpenAI Chat Completions client (server-side only).
 */
final class OpenAIService
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private string $baseUrl;

    public function __construct()
    {
        $app = require dirname(__DIR__) . '/config/app.php';
        $cfg = is_array($app['openai'] ?? null) ? $app['openai'] : [];
        $this->apiKey = trim((string) ($cfg['api_key'] ?? ''));
        $this->model = trim((string) ($cfg['model'] ?? 'gpt-4o-mini'));
        $this->timeout = max(30, (int) ($cfg['timeout'] ?? 120));
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.openai.com/v1'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'reachable' => false,
                'model' => $this->model,
                'message' => 'OpenAI API key is not configured on the server.',
            ];
        }

        return [
            'configured' => true,
            'reachable' => true,
            'model' => $this->model,
            'message' => 'OpenAI is configured.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateJson(string $systemPrompt, string $userPrompt, ?int $maxTokens = null, float $temperature = 0.35): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI question generation is not configured. Contact the administrator.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => max(0.0, min(1.0, $temperature)),
        ];
        if ($maxTokens !== null && $maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        $raw = $this->post('/chat/completions', $payload);
        $text = trim((string) ($raw['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('OpenAI returned an empty response. Please try again.');
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[PMS OpenAI] Invalid JSON: ' . $e->getMessage() . ' | snippet: ' . substr($text, 0, 300));
            throw new \RuntimeException('AI returned invalid JSON. Please try again.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * OCR / text extraction from a job-description image via vision-capable model.
     */
    public function extractTextFromImage(string $base64, string $mimeType): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured on the server.');
        }

        $mimeType = trim($mimeType) !== '' ? trim($mimeType) : 'image/jpeg';
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Extract all readable job description text from this image. Return plain text only with no commentary or markdown.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . $base64,
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0,
            'max_tokens' => 4096,
        ];

        $raw = $this->post('/chat/completions', $payload);
        $text = trim((string) ($raw['choices'][0]['message']['content'] ?? ''));

        return $text;
    }

    /**
     * OCR with a custom instruction (e.g. aptitude question manuals).
     */
    public function extractTextFromImageWithPrompt(string $base64, string $mimeType, string $instruction): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured on the server.');
        }

        $mimeType = trim($mimeType) !== '' ? trim($mimeType) : 'image/jpeg';
        $instruction = trim($instruction) !== '' ? trim($instruction) : 'Extract all readable text. Plain text only.';
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $instruction],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . $base64,
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0,
            'max_tokens' => 4096,
        ];

        $raw = $this->post('/chat/completions', $payload);

        return trim((string) ($raw['choices'][0]['message']['content'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $body = json_encode($this->sanitizePayload($payload), $flags);
        if ($body === false) {
            $detail = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown error';
            error_log('[PMS OpenAI] json_encode failed: ' . $detail);
            throw new \RuntimeException(
                'Could not send the question generation request. Reload the syllabus with Get and try again.'
            );
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not connect to OpenAI.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            error_log('[PMS OpenAI] cURL error ' . $errno . ': ' . $error);
            throw new \RuntimeException('Could not reach OpenAI. Check network connectivity and try again.');
        }

        try {
            $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            error_log('[PMS OpenAI] HTTP ' . $status . ' invalid envelope: ' . substr((string) $response, 0, 500));
            throw new \RuntimeException('OpenAI returned an unexpected response. Please try again.');
        }

        if ($status === 401 || $status === 403) {
            error_log('[PMS OpenAI] HTTP ' . $status . ' auth failure');
            throw new \RuntimeException('OpenAI authentication failed. Check the server API key configuration.');
        }

        if ($status === 429) {
            throw new \RuntimeException('OpenAI rate limit reached. Please wait a moment and try again.');
        }

        if ($status < 200 || $status >= 300) {
            $msg = trim((string) ($decoded['error']['message'] ?? ''));
            error_log('[PMS OpenAI] HTTP ' . $status . ': ' . ($msg !== '' ? $msg : substr((string) $response, 0, 500)));
            throw new \RuntimeException(
                $msg !== '' ? 'OpenAI error: ' . $msg : 'AI question generation is temporarily unavailable. Please try again.'
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizePayload(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::cleanUtf8($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $outKey = is_string($key) ? self::cleanUtf8($key) : $key;
            $out[$outKey] = $this->sanitizePayload($item);
        }

        return $out;
    }

    public static function cleanUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;

        return $text;
    }
}
