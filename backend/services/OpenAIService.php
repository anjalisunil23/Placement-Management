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
    public function generateJson(string $systemPrompt, string $userPrompt): array
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
            'temperature' => 0.35,
        ];

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Could not encode OpenAI request.');
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
}
