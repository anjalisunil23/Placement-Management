<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Resource limits and paths for the coding sandbox (no Docker).
 */
final class CodingExecutionConfig
{
    /**
     * @return array{
     *   time_limit_sec:int,
     *   wall_clock_sec:int,
     *   memory_limit_mb:int,
     *   process_limit:int,
     *   file_size_kb:int,
     *   max_source_bytes:int,
     *   max_io_bytes:int,
     *   run_user:string,
     *   sandbox_root:string,
     *   remote_backends:list<string>
     * }
     */
    public static function limits(?int $timeLimitMs = null): array
    {
        $timeSec = max(1, min(15, (int) ceil(($timeLimitMs ?? 2000) / 1000)));
        $memoryMb = max(16, min(512, (int) ($_ENV['CODING_MEMORY_LIMIT_MB'] ?? 128)));
        $wall = max($timeSec + 1, min(20, (int) ($_ENV['CODING_WALL_CLOCK_SEC'] ?? ($timeSec + 3))));

        $remoteRaw = strtolower(trim((string) ($_ENV['CODING_REMOTE_BACKENDS'] ?? '')));
        $remote = $remoteRaw === '' || $remoteRaw === 'none'
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $remoteRaw))));

        $root = trim((string) ($_ENV['CODING_SANDBOX_ROOT'] ?? ''));
        if ($root === '') {
            $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms-coding-sandbox';
        }

        return [
            'time_limit_sec' => $timeSec,
            'wall_clock_sec' => $wall,
            'memory_limit_mb' => $memoryMb,
            'process_limit' => max(8, min(64, (int) ($_ENV['CODING_PROCESS_LIMIT'] ?? 32))),
            'file_size_kb' => max(256, min(65536, (int) ($_ENV['CODING_FILE_SIZE_KB'] ?? 10240))),
            'max_source_bytes' => max(1024, min(131072, (int) ($_ENV['CODING_MAX_SOURCE_BYTES'] ?? 65536))),
            'max_io_bytes' => max(1024, min(131072, (int) ($_ENV['CODING_MAX_IO_BYTES'] ?? 65536))),
            'run_user' => trim((string) ($_ENV['CODING_RUN_USER'] ?? '')),
            'sandbox_root' => $root,
            'remote_backends' => $remote,
        ];
    }
}
