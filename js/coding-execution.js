/* PlaceHub — code execution adapter
 * Browser UI → CodeExecutionService.run() → POST /coding/execute (when enabled) or Skulpt (Python).
 */
(function (global) {
  const API_ENABLED = false;
  const DEFAULT_TIME_LIMIT_MS = 3000;
  const MOCK_SPIN_MS = [650, 1100];

  function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function nowMs() {
    return Date.now();
  }

  function lineNo(source, index) {
    return String(source.slice(0, Math.max(0, index))).split('\n').length;
  }

  function withoutStringsAndComments(source, language) {
    let out = '';
    let i = 0;
    const s = String(source || '');
    const py = language === 'Python';
    while (i < s.length) {
      const a = s[i];
      const b = s[i + 1];
      if (py && a === '#') {
        while (i < s.length && s[i] !== '\n') i += 1;
        continue;
      }
      if (!py && a === '/' && b === '/') {
        i += 2;
        while (i < s.length && s[i] !== '\n') i += 1;
        continue;
      }
      if (!py && a === '/' && b === '*') {
        i += 2;
        while (i < s.length && !(s[i] === '*' && s[i + 1] === '/')) i += 1;
        i += 2;
        continue;
      }
      if (a === '"' || a === "'" || a === '`') {
        const q = a;
        i += 1;
        while (i < s.length && s[i] !== q) {
          if (s[i] === '\\') i += 1;
          i += 1;
        }
        i += 1;
        out += ' ';
        continue;
      }
      out += a;
      i += 1;
    }
    return out;
  }

  function unbalancedMessage(source) {
    const pairs = { '(': ')', '[': ']', '{': '}' };
    const stack = [];
    let inStr = null;
    const s = String(source || '');
    for (let i = 0; i < s.length; i += 1) {
      const ch = s[i];
      if (inStr) {
        if (ch === '\\') { i += 1; continue; }
        if (ch === inStr) inStr = null;
        continue;
      }
      if (ch === '"' || ch === "'" || ch === '`') { inStr = ch; continue; }
      if (pairs[ch]) stack.push({ expect: pairs[ch], at: i });
      else if (ch === ')' || ch === ']' || ch === '}') {
        const top = stack.pop();
        if (!top || top.expect !== ch) {
          return { line: lineNo(s, i), message: `unmatched '${ch}'` };
        }
      }
    }
    if (inStr) return { line: lineNo(s, s.length - 1), message: 'unterminated string literal' };
    if (stack.length) {
      const top = stack[stack.length - 1];
      return { line: lineNo(s, top.at), message: `unexpected EOF while parsing (missing '${top.expect}')` };
    }
    return null;
  }

  function pythonSyntaxError(source) {
    const lines = String(source || '').split(/\n/);
    const needsColon = /^(?:\s*)(?:if|elif|else|for|while|def|class|try|except|finally|with)\b/;
    for (let i = 0; i < lines.length; i += 1) {
      const raw = lines[i];
      const line = raw.replace(/#.*$/, '').trimEnd();
      if (!line.trim()) continue;
      if (needsColon.test(line) && !line.trim().endsWith(':') && !line.trim().endsWith('\\')) {
        return { line: i + 1, message: 'SyntaxError: invalid syntax' };
      }
    }
    const un = unbalancedMessage(source);
    if (un) return { line: un.line, message: `SyntaxError: ${un.message}` };
    return null;
  }

  function compiledSyntaxError(source, language) {
    const un = unbalancedMessage(source);
    if (un) {
      return {
        line: un.line,
        message: `Compilation failed\nerror: ${un.message} at line ${un.line}`,
      };
    }
    const body = withoutStringsAndComments(source, language);
    if (language === 'Java' && !/\bclass\s+\w+/.test(body)) {
      return { line: 1, message: 'Compilation failed\nerror: class, interface, or enum expected' };
    }
    if (language === 'Java' && !/\bpublic\s+static\s+void\s+main\s*\(/.test(String(source))) {
      return { line: 1, message: "Compilation failed\nerror: cannot find symbol\n  symbol:   method main(String[])" };
    }
    if ((language === 'C' || language === 'C++') && !/\bmain\s*\(/.test(body)) {
      return { line: 1, message: "Compilation failed\nerror: undefined reference to `main'" };
    }
    const lines = String(source || '').split(/\n/);
    for (let i = 0; i < lines.length; i += 1) {
      const trimmed = lines[i].replace(/\/\/.*$/, '').trim();
      if (!trimmed) continue;
      if (/^#include\b/.test(trimmed) || /^(using|namespace|template|public|private|protected|class|struct|enum|typedef|package|import)\b/.test(trimmed)) continue;
      if (/[{}]$/.test(trimmed) || trimmed.endsWith(';') || trimmed.endsWith(',') || trimmed.endsWith('(')) continue;
      if (/^(if|for|while|switch|else|do|try|catch|return)\b/.test(trimmed)) continue;
      if (language === 'Java' && /^(System\.|Scanner|int|long|double|float|boolean|char|String|void)\b/.test(trimmed) && !trimmed.endsWith('{') && !trimmed.endsWith(';')) {
        return { line: i + 1, message: `Compilation failed\nerror: expected ';' before '}'` };
      }
      if ((language === 'C' || language === 'C++') && /^(int|char|long|float|double|return|printf|cout|cin)\b/.test(trimmed) && !trimmed.endsWith('{') && !trimmed.endsWith(';')) {
        return { line: i + 1, message: `Compilation failed\nerror: expected ';' before '}'` };
      }
    }
    return null;
  }

  function jsSyntaxError(source) {
    const un = unbalancedMessage(source);
    if (un) return { line: un.line, message: `SyntaxError: ${un.message}` };
    return null;
  }

  function analyze(language, source) {
    const code = String(source || '');
    if (!code.trim()) {
      return null;
    }
    if (language === 'Python') {
      const err = pythonSyntaxError(code);
      if (err) return { errorType: 'Syntax Error', message: err.message + (err.line ? `\n  File "solution.py", line ${err.line}` : '') };
    } else if (language === 'JavaScript') {
      const err = jsSyntaxError(code);
      if (err) return { errorType: 'Syntax Error', message: err.message };
    } else {
      const err = compiledSyntaxError(code, language);
      if (err) return { errorType: 'Compilation Error', message: err.message };
    }
    return null;
  }

  function looksLikeInfiniteLoop(source) {
    const s = withoutStringsAndComments(source, 'C');
    return /while\s*\(\s*(true|1)\s*\)|while\s+True\s*:|for\s*\(\s*;\s*;\s*\)/i.test(s)
      && !/\bbreak\b/.test(s);
  }

  let skulptLoadPromise = null;

  function loadSkulpt() {
    if (global.Sk && global.Sk.builtin) {
      return Promise.resolve();
    }
    if (skulptLoadPromise) return skulptLoadPromise;
    skulptLoadPromise = new Promise((resolve, reject) => {
      const urls = [
        'https://cdn.jsdelivr.net/npm/skulpt@1.2.0/dist/skulpt.min.js',
        'https://cdn.jsdelivr.net/npm/skulpt@1.2.0/dist/skulpt-stdlib.js',
      ];
      let i = 0;
      const next = () => {
        if (i >= urls.length) {
          if (global.Sk) resolve();
          else reject(new Error('Skulpt failed to load'));
          return;
        }
        const script = document.createElement('script');
        script.src = urls[i];
        script.onload = () => {
          i += 1;
          next();
        };
        script.onerror = () => reject(new Error('Skulpt failed to load'));
        document.head.appendChild(script);
      };
      next();
    });
    return skulptLoadPromise;
  }

  function skulptErrorMessage(err) {
    const raw = err && typeof err.toString === 'function' ? err.toString() : String(err || '');
    if (/SyntaxError|IndentationError/i.test(raw)) {
      return { status: 'Syntax Error', message: raw };
    }
    if (/EOFError|ValueError|ZeroDivisionError|NameError|TypeError|IndexError|KeyError|AttributeError/i.test(raw)) {
      return { status: 'Runtime Error', message: raw };
    }
    return { status: 'Runtime Error', message: raw || 'Runtime Error' };
  }

  async function runPythonSource(source, stdin) {
    await loadSkulpt();
    const Sk = global.Sk;
    const lines = String(stdin ?? '').replace(/\r\n/g, '\n').split('\n');
    let lineIdx = 0;
    let stdout = '';
    Sk.configure({
      output: (text) => {
        stdout += text;
      },
      read: (file) => {
        if (Sk.builtinFiles === undefined || Sk.builtinFiles.files[file] === undefined) {
          throw new Error(`File not found: ${file}`);
        }
        return Sk.builtinFiles.files[file];
      },
      __future__: Sk.python3,
    });
    Sk.builtins.input = new Sk.builtin.func(function inputFunc() {
      if (lineIdx >= lines.length) {
        throw new Sk.builtin.EOFError('EOF when reading a line');
      }
      const line = lines[lineIdx];
      lineIdx += 1;
      return new Sk.builtin.str(line);
    });
    try {
      await Sk.misceval.asyncToPromise(() => Sk.importMainWithBody('__main__', false, String(source || ''), true));
      return { ok: true, status: 'OK', stdout, stderr: '' };
    } catch (err) {
      const parsed = skulptErrorMessage(err);
      return { ok: false, status: parsed.status, stdout: '', stderr: parsed.message };
    }
  }

  async function runJavaScriptSource(source, stdin) {
    const lines = String(stdin ?? '').replace(/\r\n/g, '\n').split('\n');
    let lineIdx = 0;
    const logs = [];
    const consoleShim = {
      log(...args) {
        logs.push(args.map((v) => String(v)).join(' '));
      },
    };
    const requireShim = (name) => {
      if (name === 'fs') {
        return {
          readFileSync: () => String(stdin ?? ''),
        };
      }
      throw new Error(`Cannot find module '${name}'`);
    };
    try {
      const fn = new Function('console', 'require', String(source || ''));
      fn(consoleShim, requireShim);
      return { ok: true, status: 'OK', stdout: logs.join('\n'), stderr: '' };
    } catch (err) {
      const msg = String(err?.message || err || 'Runtime Error');
      const status = /SyntaxError|Unexpected token/i.test(msg) ? 'Syntax Error' : 'Runtime Error';
      return { ok: false, status, stdout: '', stderr: msg };
    }
  }

  function sourceLineWith(source, re) {
    const lines = String(source || '').split('\n');
    const idx = lines.findIndex((line) => re.test(line));
    if (idx < 0) return { line: 1, text: lines[0] || '' };
    return { line: idx + 1, text: lines[idx] };
  }

  function runtimeFromInput(language, source, stdin) {
    const empty = !String(stdin || '').trim();
    if (language === 'Python' && /\binput\s*\(/.test(source) && empty) {
      const hit = sourceLineWith(source, /\binput\s*\(/);
      if (/int\s*\(\s*input\s*\(/.test(source)) {
        return `ValueError: invalid literal for int() with base 10: ''\n  File "solution.py", line ${hit.line}\n    ${hit.text}`;
      }
      return `EOFError: EOF when reading a line\n  File "solution.py", line ${hit.line}\n    ${hit.text}`;
    }
    if (language === 'Python' && /\braise\s+/.test(source)) {
      const m = source.match(/raise\s+(\w+)/);
      return `${m ? m[1] : 'RuntimeError'}: exception raised`;
    }
    if (/\bthrow\s+new\b/.test(source) || /\bthrow\s+\w+/.test(source)) {
      return 'Runtime Error: uncaught exception';
    }
    if (/\babort\s*\(/.test(source)) {
      return 'Runtime Error: abort()';
    }
    return null;
  }

  async function mockRun(opts) {
    const language = opts.language || 'Python';
    const source = String(opts.source || '');
    const stdin = String(opts.stdin ?? '');
    const limit = Number(opts.timeLimitMs) > 0 ? Number(opts.timeLimitMs) : DEFAULT_TIME_LIMIT_MS;
    const mock = opts._mock || {};
    const started = nowMs();
    const spin = opts.quick
      ? 40
      : MOCK_SPIN_MS[0] + Math.floor(Math.random() * (MOCK_SPIN_MS[1] - MOCK_SPIN_MS[0]));

    const syntax = analyze(language, source);
    if (syntax) {
      await delay(spin);
      return {
        ok: false,
        status: syntax.errorType,
        stdout: '',
        stderr: syntax.message,
        timedOut: false,
        durationMs: nowMs() - started,
      };
    }

    if (looksLikeInfiniteLoop(source)) {
      await delay(Math.min(limit, opts.quick ? 80 : 1600));
      return {
        ok: false,
        status: 'Time Limit Exceeded',
        stdout: '',
        stderr: 'Time Limit Exceeded',
        timedOut: true,
        durationMs: nowMs() - started,
      };
    }

    const rt = runtimeFromInput(language, source, stdin);
    if (rt) {
      await delay(spin);
      return {
        ok: false,
        status: 'Runtime Error',
        stdout: '',
        stderr: rt,
        timedOut: false,
        durationMs: nowMs() - started,
      };
    }

    await delay(spin);

    let ran;
    if (language === 'Python') {
      ran = await runPythonSource(source, stdin);
    } else if (language === 'JavaScript') {
      ran = await runJavaScriptSource(source, stdin);
    } else {
      return {
        ok: false,
        status: 'Runtime Error',
        stdout: '',
        stderr: `Running ${language} requires the server execution service. Use Python for Run Code here, or submit to grade all languages.`,
        timedOut: false,
        durationMs: nowMs() - started,
      };
    }

    if (!ran.ok) {
      return {
        ok: false,
        status: ran.status,
        stdout: ran.stdout || '',
        stderr: ran.stderr || ran.status,
        timedOut: false,
        durationMs: nowMs() - started,
      };
    }

    return {
      ok: true,
      status: 'OK',
      stdout: ran.stdout,
      stderr: '',
      timedOut: false,
      durationMs: nowMs() - started,
    };
  }

  async function apiRun(opts) {
    const res = await api('/coding/execute', {
      method: 'POST',
      body: JSON.stringify({
        language: opts.language,
        source: opts.source,
        stdin: opts.stdin || '',
        timeLimitMs: opts.timeLimitMs || DEFAULT_TIME_LIMIT_MS,
      }),
    });
    if (!res?.success) throw new Error(res?.message || 'Execution failed.');
    const data = res.data || {};
    return {
      ok: data.ok !== false && !data.timedOut && (data.status === 'OK' || !data.status),
      status: data.status || (data.timedOut ? 'Time Limit Exceeded' : 'OK'),
      stdout: data.stdout || '',
      stderr: data.stderr || '',
      timedOut: !!data.timedOut,
      durationMs: data.durationMs || 0,
    };
  }

  global.CodeExecutionService = {
    TIME_LIMIT_MS: DEFAULT_TIME_LIMIT_MS,
    ready: true,
    async run(opts) {
      try {
        if (API_ENABLED && typeof api === 'function') {
          return await apiRun(opts);
        }
        return await mockRun(opts);
      } catch (err) {
        const msg = String(err?.message || '');
        if (/Failed to fetch|NetworkError|Execution failed/i.test(msg)) {
          throw new Error('Code execution service unavailable, please try again');
        }
        throw err;
      }
    },
  };
})(window);
