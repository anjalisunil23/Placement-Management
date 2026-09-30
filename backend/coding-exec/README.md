# Coding execution (PHP + self-hosted Piston)

See **[docs/CODE_EXECUTION.md](../../docs/CODE_EXECUTION.md)** for full architecture, Docker setup, and environment variables.

## Summary

1. Browser → PHP `POST /api/coding/execute` or submit APIs  
2. `CodeExecutionService` → self-hosted **Piston** (`CODE_EXECUTION_URL`) when `CODING_EXEC_MODE=remote_only`  
3. Optional fallback: CLI worker `backend/coding-exec/worker.php` + `CodingSandboxEngine` (local compilers, ulimit)  
4. `CodingSubmissionGrader` runs all test cases; `CodingTestCaseChecker` compares output  

Wandbox and other public third-party runners are **not** used.

## Quick start

```bash
docker compose -f docker-compose.coding-exec.yml up -d
```

```env
CODE_EXECUTION_URL=http://127.0.0.1:2000
CODING_EXEC_MODE=remote_only
CODING_REMOTE_BACKENDS=piston
```

```bash
php backend/scripts/coding-remote-smoke.php
php backend/scripts/coding-exec-test.php
```
