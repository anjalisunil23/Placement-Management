<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Local Ollama HTTP client (generate JSON responses).
 */
final class OllamaService
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $app = require dirname(__DIR__) . '/config/app.php';
            $config = is_array($app['ollama'] ?? null) ? $app['ollama'] : [];
        }
        $this->baseUrl = rtrim((string) ($config['url'] ?? 'http://localhost:11434'), '/');
        $this->model = trim((string) ($config['model'] ?? 'qwen2.5'));
        $this->timeout = max(30, (int) ($config['timeout'] ?? 180));
    }

    /**
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt): array
    {
        if ($this->model === '') {
            throw new \RuntimeException('Ollama model is not configured.');
        }

        $payload = json_encode([
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
        ], JSON_THROW_ON_ERROR);

        $response = $this->post('/api/generate', $payload);
        $text = trim((string) ($response['response'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('AI returned an empty response.');
        }

        return $this->decodeJsonPayload($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, string $jsonBody): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required for AI generation.');
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize AI HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            error_log('[PMS Ollama] cURL error ' . $errno . ': ' . $error);
            throw new \RuntimeException('AI service is currently unavailable. Please make sure Ollama is running and try again.');
        }

        if ($status === 404) {
            throw new \RuntimeException('AI model not found. Install it with: ollama pull ' . $this->model);
        }

        if ($status < 200 || $status >= 300) {
            error_log('[PMS Ollama] HTTP ' . $status . ': ' . substr((string) $raw, 0, 500));
            throw new \RuntimeException('AI service is currently unavailable. Please make sure Ollama is running and try again.');
        }

        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[PMS Ollama] Invalid envelope JSON: ' . $e->getMessage());
            throw new \RuntimeException('AI service returned an invalid response. Please try again.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('AI service returned an invalid response. Please try again.');
        }

        if (!empty($decoded['error'])) {
            $msg = trim((string) $decoded['error']);
            error_log('[PMS Ollama] API error: ' . $msg);
            if (stripos($msg, 'model') !== false && stripos($msg, 'not found') !== false) {
                throw new \RuntimeException('AI model not found. Install it with: ollama pull ' . $this->model);
            }
            throw new \RuntimeException('AI service is currently unavailable. Please make sure Ollama is running and try again.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $text = trim($m[1]);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[PMS Ollama] Invalid AI JSON: ' . $e->getMessage() . ' | snippet: ' . substr($text, 0, 300));
            throw new \RuntimeException('AI returned invalid JSON. Please regenerate the questions.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('AI returned invalid JSON. Please regenerate the questions.');
        }

        return $decoded;
    }
}
