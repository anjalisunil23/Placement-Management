<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Deploy marker for production verification (bump when coding execution stack changes).
 */
final class CodingDeployInfo
{
    public const REVISION = '20261003-prod-exec-1';

    /** @return array<string, mixed> */
    public static function meta(): array
    {
        return [
            'revision' => self::REVISION,
            'gitHead' => self::readGitHead(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function healthReport(string $backendDir): array
    {
        $backendDir = rtrim($backendDir, '/\\');
        $files = [
            'bootstrap-services.php' => $backendDir . '/bootstrap-services.php',
            'api/index.php' => $backendDir . '/api/index.php',
            'utils/CodingExecutionDebug.php' => $backendDir . '/utils/CodingExecutionDebug.php',
            'utils/CodingExecutionErrorFormatter.php' => $backendDir . '/utils/CodingExecutionErrorFormatter.php',
            'utils/coding-runtime-fallback.php' => $backendDir . '/utils/coding-runtime-fallback.php',
            'services/CodingPracticeRunService.php' => $backendDir . '/services/CodingPracticeRunService.php',
            'services/CodeExecutionService.php' => $backendDir . '/services/CodeExecutionService.php',
        ];
        $fileStatus = [];
        foreach ($files as $label => $path) {
            $fileStatus[$label] = [
                'exists' => is_readable($path),
                'size' => is_readable($path) ? (int) filesize($path) : 0,
                'mtime' => is_readable($path) ? (int) filemtime($path) : 0,
            ];
        }

        return array_merge(self::meta(), [
            'bootstrap' => [
                'pms_load_backend_utils' => function_exists('pms_load_backend_utils'),
                'pms_coding_exec_debug_log' => function_exists('pms_coding_exec_debug_log'),
                'CodingExecutionDebug' => class_exists(CodingExecutionDebug::class, false),
                'CodingExecutionErrorFormatter' => class_exists(CodingExecutionErrorFormatter::class, false),
            ],
            'files' => $fileStatus,
            'env' => [
                'CODING_EXECUTOR' => trim((string) ($_ENV['CODING_EXECUTOR'] ?? getenv('CODING_EXECUTOR') ?: '')),
                'CODING_REMOTE_BACKENDS' => trim((string) ($_ENV['CODING_REMOTE_BACKENDS'] ?? getenv('CODING_REMOTE_BACKENDS') ?: '')),
                'CODING_WANDBOX_ENABLED' => trim((string) ($_ENV['CODING_WANDBOX_ENABLED'] ?? getenv('CODING_WANDBOX_ENABLED') ?: '')),
                'CODING_HTTP_SSL_VERIFY' => trim((string) ($_ENV['CODING_HTTP_SSL_VERIFY'] ?? getenv('CODING_HTTP_SSL_VERIFY') ?: '')),
                'curl' => function_exists('curl_init'),
            ],
        ]);
    }

    private static function readGitHead(): string
    {
        $root = dirname(__DIR__, 2);
        $headFile = $root . '/.git/HEAD';
        if (!is_readable($headFile)) {
            return '';
        }
        $head = trim((string) file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            $refFile = $root . '/.git/' . $ref;
            if (is_readable($refFile)) {
                return trim(substr((string) file_get_contents($refFile), 0, 12));
            }
        }

        return substr($head, 0, 12);
    }
}
