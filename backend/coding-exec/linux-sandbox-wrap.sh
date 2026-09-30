#!/usr/bin/env bash
# OS-level limits for one student run (no Docker). Invoked only by coding-exec worker.
set -u
CPU_SEC="${1:?cpu}"
MEM_KB="${2:?mem_kb}"
FILE_BLOCKS="${3:?file_blocks}"
PROC_LIMIT="${4:?proc}"
WORKDIR="${5:?workdir}"
shift 5

ulimit -t "$CPU_SEC" 2>/dev/null || true
ulimit -v "$MEM_KB" 2>/dev/null || true
ulimit -f "$FILE_BLOCKS" 2>/dev/null || true
ulimit -u "$PROC_LIMIT" 2>/dev/null || true

cd "$WORKDIR" || exit 127
export HOME="$WORKDIR"
export TMPDIR="${WORKDIR}/temp"

exec "$@"
