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
        $this->baseUrl = rtrim((string) ($config['url'] ?? 'http://127.0.0.1:11434'), '/');
        $this->model = trim((string) ($config['model'] ?? 'qwen2.5'));
        $this->timeout = max(30, (int) ($config['timeout'] ?? 180));
    }

    /**
     * @return array{
     *   ready: bool,
     *   ollamaReachable: bool,
     *   modelInstalled: bool,
     *   model: string,
     *   url: string,
     *   installedModels: list<string>,
     *   message: string
     * }
     */
    public function checkStatus(): array
    {
        $installed = [];
        try {
            $tags = $this->get('/api/tags');
            $installed = $this->extractModelNames($tags);
        } catch (\RuntimeException $e) {
            return [
                'ready' => false,
                'ollamaReachable' => false,
                'modelInstalled' => false,
                'model' => $this->model,
                'url' => $this->baseUrl,
                'installedModels' => [],
                'message' => $e->getMessage(),
            ];
        }

        $modelInstalled = $this->modelIsInstalled($installed, $this->model);
        if (!$modelInstalled) {
            $hint = $installed !== []
                ? ' Installed models: ' . implode(', ', array_slice($installed, 0, 5)) . '.'
                : '';
            return [
                'ready' => false,
                'ollamaReachable' => true,
                'modelInstalled' => false,
                'model' => $this->model,
                'url' => $this->baseUrl,
                'installedModels' => $installed,
                'message' => 'AI model "' . $this->model . '" is not installed on the server. Run: ollama pull ' . $this->model . '.' . $hint,
            ];
        }

        return [
            'ready' => true,
            'ollamaReachable' => true,
            'modelInstalled' => true,
            'model' => $this->model,
            'url' => $this->baseUrl,
            'installedModels' => $installed,
            'message' => 'Ollama is ready.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt): array
    {
        if ($this->model === '') {
            throw new \RuntimeException('Ollama model is not configured.');
        }

        $status = $this->checkStatus();
        if (!$status['ready']) {
            throw new \RuntimeException((string) $status['message']);
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
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, string $jsonBody): array
    {
        return $this->request('POST', $path, $jsonBody);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $jsonBody): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required for AI generation.');
        }

        $urls = [$this->baseUrl . $path];
        if (str_contains($this->baseUrl, 'localhost')) {
            $urls[] = str_replace('localhost', '127.0.0.1', $this->baseUrl) . $path;
        }

        $lastError = 'Could not connect to Ollama.';
        foreach (array_unique($urls) as $url) {
            try {
                return $this->executeRequest($method, $url, $jsonBody);
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeRequest(string $method, string $url, ?string $jsonBody): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize AI HTTP client.');
        }

        $opts = [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $method === 'GET' ? 15 : $this->timeout,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $jsonBody ?? '';
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            error_log('[PMS Ollama] cURL error ' . $errno . ' @ ' . $url . ': ' . $error);
            throw new \RuntimeException($this->connectionErrorMessage($errno, $error, $url));
        }

        if ($status === 404) {
            $bodyMsg = $this->parseErrorFromBody((string) $raw);
            if ($bodyMsg !== null && stripos($bodyMsg, 'model') !== false) {
                throw new \RuntimeException('AI model not found. On the PHP server run: ollama pull ' . $this->model);
            }
            throw new \RuntimeException('AI model not found. On the PHP server run: ollama pull ' . $this->model);
        }

        if ($status < 200 || $status >= 300) {
            error_log('[PMS Ollama] HTTP ' . $status . ' @ ' . $url . ': ' . substr((string) $raw, 0, 500));
            $bodyMsg = $this->parseErrorFromBody((string) $raw);
            if ($bodyMsg !== null) {
                if (stripos($bodyMsg, 'model') !== false && stripos($bodyMsg, 'not found') !== false) {
                    throw new \RuntimeException('AI model not found. On the PHP server run: ollama pull ' . $this->model);
                }
                throw new \RuntimeException('Ollama error: ' . $bodyMsg);
            }
            throw new \RuntimeException('Ollama returned HTTP ' . $status . '. Check that Ollama is running on the PHP server.');
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
                throw new \RuntimeException('AI model not found. On the PHP server run: ollama pull ' . $this->model);
            }
            throw new \RuntimeException('Ollama error: ' . $msg);
        }

        return $decoded;
    }

    private function connectionErrorMessage(int $errno, string $error, string $url): string
    {
        if ($errno === 28) {
            return 'AI generation timed out. Try fewer questions or increase OLLAMA_TIMEOUT in server config.';
        }

        if ($errno === 7 || stripos($error, 'connect') !== false || stripos($error, 'refused') !== false) {
            return 'Ollama is not reachable from the PHP server at ' . $this->baseUrl
                . '. Install Ollama on the same machine as the backend, keep it running, then run: ollama pull '
                . $this->model;
        }

        return 'Could not connect to Ollama at ' . $this->baseUrl
            . '. Make sure Ollama is running on the PHP server and try again.';
    }

    private function parseErrorFromBody(string $raw): ?string
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $msg = trim((string) ($decoded['error'] ?? ''));
        return $msg !== '' ? $msg : null;
    }

    /**
     * @param array<string, mixed> $tags
     * @return list<string>
     */
    private function extractModelNames(array $tags): array
    {
        $models = [];
        foreach ((array) ($tags['models'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['model'] ?? ''));
            if ($name !== '') {
                $models[] = $name;
            }
        }
        return $models;
    }

    /**
     * @param list<string> $installed
     */
    private function modelIsInstalled(array $installed, string $model): bool
    {
        $want = strtolower(trim($model));
        if ($want === '') {
            return false;
        }
        foreach ($installed as $name) {
            $n = strtolower(trim($name));
            if ($n === $want || str_starts_with($n, $want . ':') || str_starts_with($want, explode(':', $n)[0] . ':')) {
                return true;
            }
            $base = explode(':', $n)[0];
            if ($base === $want || str_starts_with($n, $want)) {
                return true;
            }
        }
        return false;
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
