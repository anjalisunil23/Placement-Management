# Coding execution service (no Docker)

Student code is **never** compiled inside the PHP-FPM web request.

## Flow

1. Browser → `POST /api/coding/execute` or submit endpoints (PHP backend)
2. `CodeExecutionService` writes a job JSON file
3. CLI worker: `php backend/coding-exec/worker.php --job=/path/job.json`
4. `CodingSandboxEngine` creates `sandbox/job_*/{source,input,output,temp}/`, applies `ulimit` via `linux-sandbox-wrap.sh`, compiles/runs, deletes the tree
5. Results return to the API → browser

## Production setup (Linux / cPanel)

1. Install toolchains on the host (or set paths in `.env`):
   - `python3`, `node`, `gcc`, `g++`, `javac`, `java`
2. Optional dedicated user (recommended):

   ```bash
   useradd -r -s /bin/false coding_runner
   ```

   In `.env`:

   ```env
   CODING_RUN_USER=coding_runner
   CODING_SANDBOX_ROOT=/var/lib/pms-coding-sandbox
   ```

   Grant the web/PHP user permission to `sudo -u coding_runner` the worker only, or run workers as a queue user on a small VM.

3. Resource limits (defaults shown):

   ```env
   CODING_MEMORY_LIMIT_MB=128
   CODING_PROCESS_LIMIT=32
   CODING_FILE_SIZE_KB=10240
   CODING_WALL_CLOCK_SEC=5
   ```

4. Optional remote fallback when compilers are missing (sends code to third party):

   ```env
   CODING_REMOTE_BACKENDS=wandbox
   ```

   Leave empty for local-only (most secure).

## Submit grading

Practice submit (`POST /coding/problems/{id}/submit`) requires `sourceCode` and grades **all** test cases (including hidden) on the server via `CodingSubmissionGrader`.

Mock/contest submit (`POST /coding/attempts/{id}/submit`) sends `{ answers: { [questionId]: { language, code } }, timeTakenSeconds }` only. Scores and hidden-case results are computed on the server; the browser never receives hidden inputs or expected outputs.

Hidden test I/O is stripped in `CodingProblemBankModel::publicView()` and must never appear in student API responses.
