# Coding code execution (self-hosted Piston)

## Architecture

```
Student browser
      ↓  (HTTPS, session auth)
PHP backend  —  POST /api/coding/execute, submit endpoints
      ↓  HTTP (private network)
Self-hosted Piston  —  POST /api/v2/piston/execute
      ↓
Isolated container per run (compiler/runtime, no app secrets)
      ↓
CodingTestCaseChecker  —  normalize stdout, verdict per test case
      ↓
PHP backend  →  browser
```

The browser **never** talks to Wandbox, Piston, or compilers directly.

## Supported languages

| Platform label | Piston runtime |
|----------------|----------------|
| Python         | python 3.10.x  |
| JavaScript     | javascript (Node) |
| C              | c (gcc)        |
| C++            | c++ (g++)      |
| Java           | java (OpenJDK) |

Versions match whatever runtimes are installed in your Piston image (`GET /api/v2/runtimes`).

## Environment variables

| Variable | Purpose |
|----------|---------|
| `CODE_EXECUTION_URL` | Base URL of Piston, e.g. `http://127.0.0.1:2000` or `http://piston:2000` |
| `CODING_PISTON_URL` | Alias for `CODE_EXECUTION_URL` |
| `CODING_EXEC_MODE` | `remote_only` (recommended with Piston), `worker_then_remote`, `worker_only`, `inline` |
| `CODING_REMOTE_BACKENDS` | `piston` or `none` (default: `piston` when URL is set) |
| `CODING_REMOTE_FALLBACK` | After local sandbox fails, call Piston (`true`/`false`) |
| `CODING_MEMORY_LIMIT_MB` | Hint for local worker sandbox (default 256) |
| `CODING_MAX_IO_BYTES` | Max stdout/stderr captured (default 65536) |

Do **not** use the public `emkc.org` Piston API (whitelist-only).

## Local development

1. Start Piston:

   ```bash
   docker compose -f docker-compose.coding-exec.yml up -d
   ```

2. Add to `.env`:

   ```env
   CODE_EXECUTION_URL=http://127.0.0.1:2000
   CODING_EXEC_MODE=remote_only
   CODING_REMOTE_BACKENDS=piston
   ```

3. Smoke test:

   ```bash
   php backend/scripts/coding-remote-smoke.php
   php backend/scripts/coding-exec-test.php
   ```

## Production (cPanel + VPS)

Run Piston on a **private** VPS or internal VM. Point production `.env` at the internal URL (VPN or firewall allowlist from the PHP host only). Set `CODING_EXEC_MODE=remote_only` on shared hosting without local compilers.

## Security

- Student code runs in Piston-managed containers, not in PHP-FPM.
- No `.env`, database, or application files inside execution containers.
- Piston disables outbound network for student programs by default.
- PHP sanitizes stderr before returning to students (no paths/secrets).
- Optional local worker (`backend/coding-exec/worker.php`) uses per-job directories, `ulimit`, and a dedicated `CODING_RUN_USER` when configured.

## Judging

`CodingSubmissionGrader` runs **every** test case (including hidden) server-side. Input strings are passed **unchanged** from the database. `CodingTestCaseChecker::normalize()` only trims trailing whitespace/lines for comparison.

Verdicts: Accepted, Wrong Answer, Compilation Error, Runtime Error, Time Limit Exceeded, Memory Limit Exceeded, Output Limit Exceeded.

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Execution service unavailable | `CODE_EXECUTION_URL`, firewall, `curl http://host:2000/api/v2/runtimes` from PHP server |
| Compilation Error | Student code; Piston stderr in server logs only |
| Still seeing Wandbox errors | Deploy latest `main`; remove `CODING_WANDBOX_*` from `.env` |

## Array Partition Hard (problem data)

This problem is **not** in the git problem bank; it may exist only in production MongoDB. The statement “array of **2n** integers” must match test cases:

- If the intended I/O is **N then N integers** (pair the sorted array and sum mins), sample input should stay:

  ```
  4
  1 4 3 2
  ```

  Expected output: `4`.

- If the intended spec is truly **2N integers** on one line, the sample must list **8** numbers and hidden cases must follow the same rule.

Update the problem in the officer problem bank so **statement, sample I/O, and every hidden case use the same format**. The grader passes `testCases[].input` to the runner **without modification**.

## Adding a language

1. Install runtime in Piston (`docker exec` / custom Piston build).
2. Map language in `PistonExecutionClient::mapLanguage()`.
3. Add label to the coding UI language list if not already present.
