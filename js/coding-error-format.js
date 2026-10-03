/* PlaceHub — execution error summaries for Run Code / sample tests */
(function (global) {
  'use strict';

  const ERROR_STATUSES = new Set([
    'Runtime Error',
    'Compilation Error',
    'Syntax Error',
    'Time Limit Exceeded',
    'Memory Limit Exceeded',
  ]);

  function coerceOkFlag(ok) {
    if (ok === true || ok === 1) return true;
    const t = String(ok ?? '').trim().toLowerCase();
    return t === 'true' || t === '1';
  }

  /** Process exit / status — never treat empty stdout as failure. */
  function executionSucceeded(exec) {
    const base = exec || {};
    if (base.timedOut) return false;
    const status = String(base.status || '');
    if (ERROR_STATUSES.has(status)) return false;
    if (status === 'OK') return true;
    if (typeof base.exit_code === 'number') return base.exit_code === 0;
    return coerceOkFlag(base.ok);
  }

  const GENERIC_LABELS = new Set(ERROR_STATUSES);

  function isGenericLabel(text) {
    return GENERIC_LABELS.has(String(text || '').trim());
  }

  function primaryErrorLine(stderr) {
    const lines = String(stderr || '').split(/\r?\n/);
    let last = '';
    lines.forEach((line) => {
      const t = line.trim();
      const m = t.match(/^(\w+(?:Error|Exception)):\s*(.+)$/);
      if (m) last = `${m[1]}: ${m[2]}`;
    });
    return last;
  }

  function friendlyRuntimeMessage(detail) {
    let m = detail.match(/ValueError:\s*not enough values to unpack \(expected (\d+), got (\d+)\)/i);
    if (m) {
      const exp = Number(m[1]);
      const got = Number(m[2]);
      const expWord = exp === 1 ? 'value' : 'values';
      const gotPhrase = got === 1 ? `only ${got}` : String(got);
      return `Program expected ${exp} input ${expWord}, but received ${gotPhrase}.`;
    }
    if (/ValueError:\s*invalid literal for int\(\)/i.test(detail)) {
      return 'Program expected a numeric input value, but the input was not valid.';
    }
    if (/EOFError:/i.test(detail)) return 'Program tried to read input, but no more input was available.';
    if (/ZeroDivisionError:/i.test(detail)) return 'Program attempted to divide by zero.';
    if (/IndexError:/i.test(detail)) return 'Program accessed an invalid index in a list or sequence.';
    if (/TypeError:/i.test(detail)) return 'Program used a value in an unsupported way (type error).';
    if (/NameError:/i.test(detail)) return 'Program referenced a variable or name that is not defined.';
    return '';
  }

  function buildSummary(status, detail, stderr, language, stdinTrim) {
    const lang = String(language || '').toLowerCase();
    const emptyIn = !String(stdinTrim ?? '').trim();
    if (status === 'Time Limit Exceeded') return 'The program exceeded the time limit.';
    if (status === 'Memory Limit Exceeded') return 'The program exceeded the memory limit.';
    if (status === 'Compilation Error' || status === 'Syntax Error') {
      return lang === 'python'
        ? 'The program could not be compiled or parsed. Fix the syntax error and try again.'
        : 'The program could not be compiled.';
    }
    const friendly = friendlyRuntimeMessage(detail);
    if (friendly) return friendly;
    if (stderr && stderr.includes('Traceback')) {
      return 'The program stopped with a runtime error while processing input.';
    }
    if (status === 'Runtime Error') {
      if (emptyIn && lang === 'python') {
        return 'Custom input is empty, but your program tried to read input (stdin). Enter values in the Custom Input box.';
      }
      if (isGenericLabel(stderr)) {
        return 'The program exited with an error. Check your logic and input format.';
      }
      return 'The program stopped with a runtime error.';
    }
    return detail || status;
  }

  function isPythonSyntaxFailure(stderr, status) {
    return status === 'Runtime Error' && /\b(SyntaxError|IndentationError|TabError)\b/.test(stderr);
  }

  function stdinLineCount(stdin) {
    const norm = String(stdin ?? '').replace(/\r\n/g, '\n').trim();
    if (!norm) return 0;
    return norm.split('\n').length;
  }

  function countPythonInputCalls(source) {
    const m = String(source || '').match(/\binput\s*\(/g);
    return m ? m.length : 0;
  }

  function inferPythonInputHelp(source, stdin) {
    const reads = countPythonInputCalls(source);
    if (reads < 1) return null;
    const lines = stdinLineCount(stdin);
    if (lines >= reads) return null;
    const detail = 'EOFError: EOF when reading a line';
    if (lines === 0) {
      return {
        summary: `Custom input is empty, but your program reads ${reads} line(s) of input. Enter each line in Custom Input (this is a runtime error while reading stdin).`,
        detail,
      };
    }
    return {
      summary: `Your program reads ${reads} line(s) of input, but Custom Input has only ${lines}. Add ${reads - lines} more line(s) below (runtime error while reading stdin).`,
      detail,
    };
  }

  function enrichExec(exec, language, stdinHint, sourceHint) {
    const base = exec || {};
    let status = String(base.status || '');
    let stderr = String(base.stderrTrace || base.stderr || '').trim();
    const stdinTrim = String(stdinHint ?? base.stdin ?? '').trim();
    const source = String(sourceHint ?? base.source ?? '');
    if (isPythonSyntaxFailure(stderr, status)) status = 'Compilation Error';

    if (!ERROR_STATUSES.has(status) && !stderr) {
      return { ...base, status };
    }

    let errorDetail = String(base.errorDetail || '').trim() || primaryErrorLine(stderr);
    if (!errorDetail && stderr) {
      errorDetail = stderr.split(/\r?\n/).map((l) => l.trim()).find((l) => l && !isGenericLabel(l)) || '';
    }
    if (isGenericLabel(errorDetail)) errorDetail = '';

    let errorSummary = String(base.errorSummary || '').trim() || buildSummary(status, errorDetail, stderr, language, stdinTrim);

    if (status === 'Runtime Error' && String(language || '').toLowerCase() === 'python') {
      const needsInputHelp = !errorDetail || isGenericLabel(stderr) || errorSummary.includes('exited with an error') || /EOFError:/i.test(errorDetail);
      if (needsInputHelp) {
        const inferred = inferPythonInputHelp(source, stdinTrim);
        if (inferred) {
          errorSummary = inferred.summary;
          errorDetail = inferred.detail;
        }
      }
    }

    return {
      ...base,
      status,
      stderrTrace: stderr,
      errorDetail,
      errorSummary,
    };
  }

  /** Map practice /run API `custom.execution` (camelCase) to engine row for executionSucceeded(). */
  function apiExecutionRow(ex) {
    if (!ex || typeof ex !== 'object') return {};
    const exitCode = ex.exitCode ?? ex.exit_code;
    return {
      status: ex.engineStatus ?? ex.status ?? '',
      exit_code: typeof exitCode === 'number' ? exitCode : undefined,
      timedOut: !!ex.timedOut,
      ok: ex.succeeded,
    };
  }

  /**
   * When the engine succeeded (exit 0) but display status is still an error label, fix the row.
   * HTTP 200 practice runs can include both execution.succeeded=true and status=Runtime Error on stale deploys.
   */
  function reconcilePracticeRunRow(row) {
    const r = row && typeof row === 'object' ? { ...row } : {};
    const ex = r.execution;
    const failStatuses = ERROR_STATUSES;
    const procOk = executionSucceeded(apiExecutionRow(ex));
    if (!procOk || !failStatuses.has(String(r.status || ''))) {
      return r;
    }
    const emptyOut = String(r.output ?? r.stdout ?? ex?.stdout ?? '').trim() === '';
    const expected = String(r.expected ?? '').trim();
    const status = emptyOut && expected !== '' ? 'Execution Successful' : (r.passed ? 'Passed' : 'Wrong Answer');
    return {
      ...r,
      status,
      stderr: '',
      stderrTrace: '',
      errorSummary: '',
      errorDetail: '',
    };
  }

  function normalizePracticeRunResponse(run) {
    if (!run || typeof run !== 'object') return run;
    const custom = reconcilePracticeRunRow(run.custom || {});
    const results = Array.isArray(run.results)
      ? run.results.map((row) => reconcilePracticeRunRow(row))
      : run.results;
    return {
      ...run,
      custom,
      results,
      overall: custom.status || run.overall,
    };
  }

  function resolveRunStatus(custom) {
    const explicit = String(custom?.status || '');
    if (ERROR_STATUSES.has(explicit)) return explicit;
    if (explicit === 'Execution Successful') return 'Execution Successful';
    if (custom?.passed === true) return 'Passed';
    if (custom?.passed === false && explicit && explicit !== 'Passed') return explicit;
    if (custom?.passed === false) return 'Wrong Answer';
    return explicit || '—';
  }

  function errorBlockHtml(custom, esc) {
    const status = resolveRunStatus(custom);
    if (!ERROR_STATUSES.has(status)) return '';
    const summary = String(custom?.errorSummary || '').trim();
    const detail = String(custom?.errorDetail || '').trim();
    const trace = String(custom?.stderrTrace || custom?.stderr || '').trim();
    const escFn = typeof esc === 'function' ? esc : (s) => String(s ?? '');
    const uid = `cod-err-${Math.random().toString(36).slice(2, 9)}`;
    let html = `<div class="cod-run-error mt-1"><div class="small fw-semibold text-danger">✕ ${escFn(status)}</div>`;
    if (summary) html += `<div class="small mt-1">${escFn(summary)}</div>`;
    if (detail && detail !== summary && !isGenericLabel(detail)) {
      html += `<div class="small text-muted-2 mt-2 mb-0">Error Details:</div><div class="small font-monospace">${escFn(detail)}</div>`;
    }
    if (trace && trace !== detail && !isGenericLabel(trace)) {
      html += `<details class="small mt-2 mb-0"><summary class="text-muted-2" style="cursor:pointer">View full traceback</summary><pre class="cod-console cod-error mt-1 mb-0" style="font-size:.75rem">${escFn(trace)}</pre></details>`;
    }
    html += '</div>';
    return html;
  }

  global.CodingErrorFormat = {
    enrichExec,
    resolveRunStatus,
    errorBlockHtml,
    executionSucceeded,
    apiExecutionRow,
    reconcilePracticeRunRow,
    normalizePracticeRunResponse,
    coerceOkFlag,
    ERROR_STATUSES,
  };
})(window);
