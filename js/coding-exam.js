/* PlaceHub — coding exam (instructions → editor → results) */
(function (global) {
  const KEYWORDS = {
    Python: ['and', 'as', 'assert', 'break', 'class', 'continue', 'def', 'elif', 'else', 'except', 'for', 'from', 'if', 'import', 'in', 'is', 'lambda', 'not', 'or', 'pass', 'print', 'return', 'try', 'while', 'with', 'True', 'False', 'None'],
    Java: ['abstract', 'boolean', 'break', 'case', 'catch', 'class', 'const', 'continue', 'default', 'do', 'else', 'extends', 'final', 'finally', 'for', 'if', 'implements', 'import', 'int', 'interface', 'long', 'new', 'package', 'private', 'public', 'return', 'static', 'this', 'throw', 'try', 'void', 'while', 'true', 'false', 'null'],
    C: ['auto', 'break', 'case', 'char', 'const', 'continue', 'default', 'do', 'double', 'else', 'enum', 'extern', 'float', 'for', 'goto', 'if', 'int', 'long', 'return', 'short', 'sizeof', 'static', 'struct', 'switch', 'typedef', 'union', 'unsigned', 'void', 'while'],
    'C++': ['auto', 'bool', 'break', 'case', 'catch', 'char', 'class', 'const', 'continue', 'default', 'delete', 'do', 'double', 'else', 'enum', 'false', 'float', 'for', 'if', 'int', 'long', 'namespace', 'new', 'private', 'public', 'return', 'short', 'sizeof', 'static', 'struct', 'switch', 'template', 'this', 'true', 'try', 'typedef', 'using', 'virtual', 'void', 'while'],
    JavaScript: ['async', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'default', 'else', 'export', 'false', 'finally', 'for', 'function', 'if', 'import', 'let', 'new', 'null', 'of', 'return', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'undefined', 'var', 'void', 'while'],
  };

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function highlight(code, language) {
    const keywords = KEYWORDS[language] || KEYWORDS.Python;
    const kw = keywords.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
    const comment = language === 'Python' ? '#.*$' : '//.*$|/\\*[\\s\\S]*?\\*/';
    const re = new RegExp(
      `${comment}|"(?:\\\\.|[^"\\\\])*"|'(?:\\\\.|[^'\\\\])*'|\`(?:\\\\.|[^\`\\\\])*\`|\\b(?:${kw})\\b|\\b\\d+(?:\\.\\d+)?\\b`,
      'gm'
    );
    const src = String(code ?? '');
    let last = 0;
    let html = '';
    src.replace(re, (token, offset) => {
      html += esc(src.slice(last, offset));
      if (token.startsWith('#') || token.startsWith('//') || token.startsWith('/*')) {
        html += `<span class="tok-cmt">${esc(token)}</span>`;
      } else if (token.startsWith('"') || token.startsWith("'") || token.startsWith('`')) {
        html += `<span class="tok-str">${esc(token)}</span>`;
      } else if (/^\d/.test(token)) {
        html += `<span class="tok-num">${esc(token)}</span>`;
      } else {
        html += `<span class="tok-kw">${esc(token)}</span>`;
      }
      last = offset + token.length;
      return token;
    });
    html += esc(src.slice(last));
    return html;
  }

  function createCodeEditor(mount) {
    mount.innerHTML = `
      <div class="cod-editor" data-editor>
        <div class="cod-gutter" data-gutter>1</div>
        <div class="cod-surface">
          <pre class="cod-highlight" data-highlight aria-hidden="true"></pre>
          <textarea class="cod-input" data-input spellcheck="false" autocomplete="off" autocapitalize="off" wrap="off" aria-label="Code editor"></textarea>
        </div>
      </div>`;
    const input = mount.querySelector('[data-input]');
    const gutter = mount.querySelector('[data-gutter]');
    const hi = mount.querySelector('[data-highlight]');
    let language = 'Python';

    function paint() {
      const value = input.value || '';
      const lines = value.split('\n');
      const count = Math.max(1, lines.length);
      gutter.textContent = Array.from({ length: count }, (_, i) => i + 1).join('\n');
      hi.innerHTML = highlight(value, language) + '\n';
    }

    function syncScroll() {
      hi.scrollTop = input.scrollTop;
      hi.scrollLeft = input.scrollLeft;
      gutter.scrollTop = input.scrollTop;
    }

    input.addEventListener('input', paint);
    input.addEventListener('scroll', syncScroll);
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;
      e.preventDefault();
      const start = input.selectionStart;
      const end = input.selectionEnd;
      input.value = `${input.value.slice(0, start)}  ${input.value.slice(end)}`;
      input.selectionStart = input.selectionEnd = start + 2;
      paint();
    });

    paint();
    return {
      getValue() { return input.value; },
      setValue(value) {
        input.value = value || '';
        paint();
        input.scrollTop = 0;
        syncScroll();
      },
      setLanguage(lang) {
        language = lang || 'Python';
        paint();
      },
      focus() { input.focus(); },
    };
  }

  function difficultyClass(diff) {
    const d = String(diff || '').toLowerCase();
    if (d === 'easy') return 'success';
    if (d === 'hard') return 'danger';
    return 'warning';
  }

  function createExamController(opts) {
    const root = opts.root;
    const onExit = opts.onExit || (() => {});
    let state = null;
    let timerId = null;
    let editor = null;
    let running = false;
    let submitting = false;
    let beforeUnloadBound = false;

    function el(id) {
      return root.querySelector(`[data-cod="${id}"]`);
    }

    function showPanel(name) {
      root.querySelectorAll('[data-cod-panel]').forEach((p) => {
        p.classList.toggle('d-none', p.getAttribute('data-cod-panel') !== name);
      });
    }

    function stopTimer() {
      if (timerId) {
        clearInterval(timerId);
        timerId = null;
      }
    }

    function bindUnload(on) {
      if (on && !beforeUnloadBound) {
        window.addEventListener('beforeunload', onBeforeUnload);
        beforeUnloadBound = true;
      }
      if (!on && beforeUnloadBound) {
        window.removeEventListener('beforeunload', onBeforeUnload);
        beforeUnloadBound = false;
      }
    }

    function onBeforeUnload(e) {
      if (!state?.attemptId) return;
      e.preventDefault();
      e.returnValue = '';
    }

    function answeredCount() {
      if (!state?.test) return 0;
      return state.test.items.filter((q) => {
        const ans = state.answers[q.id];
        if (!ans) return false;
        const starter = String(q.starterCode?.[ans.language] || '').trim();
        return String(ans.code || '').trim() && String(ans.code).trim() !== starter;
      }).length;
    }

    function currentQ() {
      return state?.test?.items?.[state.index] || null;
    }

    function persistCurrent() {
      const q = currentQ();
      if (!q || !editor) return;
      const language = el('language').value;
      const code = editor.getValue();
      state.answers[q.id] = state.answers[q.id] || {};
      state.answers[q.id].language = language;
      state.answers[q.id].code = code;
      if (state.attemptId) {
        CodingService.saveDraft(state.attemptId, q.id, language, code);
      }
    }

    function renderInstructions(test) {
      showPanel('instructions');
      el('instr-title').textContent = test.title || 'Coding Test';
      el('instr-meta').innerHTML = `
        <div class="row g-2">
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Difficulty</div><strong>${esc(test.difficulty || '—')}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Questions</div><strong>${esc(test.questions || (test.items || []).length)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Duration</div><strong>${esc(test.duration || 20)} minutes</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Maximum Marks</div><strong>${esc(test.marks || 0)}</strong></div></div>
        </div>`;
      const lines = test.instructions || [];
      el('instr-list').innerHTML = lines.map((line) => `<li>${esc(line)}</li>`).join('');
    }

    function renderProblem(q) {
      const example = (q.examples && q.examples[0]) || null;
      el('q-kicker').textContent = `Question ${state.index + 1} of ${state.test.items.length}`;
      el('q-title').textContent = q.title;
      el('q-body').innerHTML = `
        <p class="mb-3">${esc(q.description)}</p>
        <div class="mb-3">
          <div class="small fw-semibold mb-1">Input Format</div>
          <div class="text-muted-2" style="white-space:pre-wrap">${esc(q.inputFormat)}</div>
        </div>
        <div class="mb-3">
          <div class="small fw-semibold mb-1">Output Format</div>
          <div class="text-muted-2" style="white-space:pre-wrap">${esc(q.outputFormat)}</div>
        </div>
        ${example ? `
        <div class="mb-3">
          <div class="small fw-semibold mb-1">Example</div>
          <div class="cod-io"><div><span>Input</span><pre>${esc(example.input)}</pre></div><div><span>Output</span><pre>${esc(example.output)}</pre></div></div>
        </div>` : ''}
        <div>
          <div class="small fw-semibold mb-1">Constraints</div>
          <div class="text-muted-2">${esc(q.constraints)}</div>
        </div>`;
    }

    function renderCases(run) {
      const box = el('cases');
      if (!run) {
        const q = currentQ();
        const sample = (q.testCases || []).find((t) => t.sample);
        box.innerHTML = sample ? `
          <div class="border rounded-3 p-3">
            <div class="small fw-semibold mb-2">${esc(sample.label || 'Sample Test Case')}</div>
            <div class="cod-io mb-0">
              <div><span>Input</span><pre>${esc(sample.input)}</pre></div>
              <div><span>Expected Output</span><pre>${esc(sample.expected)}</pre></div>
            </div>
            <div class="small text-muted-2 mt-2 mb-0">Run code to see your output.</div>
          </div>` : '<p class="text-muted-2 mb-0">No sample test case.</p>';
        return;
      }
      box.innerHTML = (run.results || []).map((tc) => {
        const ok = tc.status === 'Passed';
        const cls = ok ? 'success' : (tc.status === 'Compilation Error' || tc.status === 'Runtime Error' ? 'danger' : 'warning');
        return `
          <div class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="small fw-semibold">${esc(tc.label || 'Sample Test Case')}</div>
              <span class="badge-soft ${cls}">${ok ? '✓ Passed' : esc(tc.status)}</span>
            </div>
            <div class="cod-io">
              <div><span>Input</span><pre>${esc(tc.input)}</pre></div>
              <div><span>Expected Output</span><pre>${esc(tc.expected)}</pre></div>
              <div><span>Your Output</span><pre>${esc(tc.output || '')}</pre></div>
            </div>
          </div>`;
      }).join('');
    }

    function renderNav() {
      el('q-nav').innerHTML = state.test.items.map((q, i) => {
        const ans = state.answers[q.id];
        const starter = String(q.starterCode?.[ans?.language || 'Python'] || '').trim();
        const done = String(ans?.code || '').trim() && String(ans.code).trim() !== starter;
        const current = i === state.index;
        return `<button type="button" class="cod-qbtn ${current ? 'is-current' : ''} ${done ? 'is-done' : ''}" data-goto="${i}">${i + 1}</button>`;
      }).join('');
      el('q-nav').querySelectorAll('[data-goto]').forEach((btn) => {
        btn.addEventListener('click', () => {
          persistCurrent();
          state.index = Number(btn.getAttribute('data-goto'));
          renderQuestion();
        });
      });
      el('btn-prev').disabled = state.index <= 0;
      el('btn-next').disabled = state.index >= state.test.items.length - 1;
    }

    function renderQuestion() {
      const q = currentQ();
      if (!q) return;
      const ans = state.answers[q.id] || {
        language: 'Python',
        code: q.starterCode.Python,
        lastRun: null,
      };
      state.answers[q.id] = ans;
      el('language').value = ans.language || 'Python';
      editor.setLanguage(ans.language || 'Python');
      editor.setValue(ans.code || q.starterCode[ans.language] || '');
      renderProblem(q);
      renderCases(ans.lastRun);
      el('run-state').textContent = ans.lastRun?.overall ? ans.lastRun.overall : '';
      renderNav();
    }

    function startTimer() {
      stopTimer();
      const tick = () => {
        const left = state.endsAt - Date.now();
        el('timer').innerHTML = `<i class="bi bi-stopwatch"></i> ${CodingService.formatTimer(left / 1000)}`;
        el('timer').classList.toggle('is-low', left < 60000);
        if (left <= 0) {
          stopTimer();
          submitExam(true);
        }
      };
      tick();
      timerId = setInterval(tick, 250);
    }

    async function beginExam() {
      if (!state?.testMeta) return;
      try {
        const started = await CodingService.startAttempt(state.testMeta.id);
        state.attemptId = started.attemptId;
        state.test = started.test;
        state.endsAt = started.endsAt;
        state.startedAt = started.startedAt;
        state.answers = {};
        (started.test.items || []).forEach((item) => {
          state.answers[item.id] = {
            language: 'Python',
            code: item.starterCode.Python,
            lastRun: null,
          };
        });
        state.index = 0;
        if (!editor) editor = createCodeEditor(el('editor'));
        showPanel('exam');
        el('exam-title').textContent = started.test.title || 'Coding Test';
        bindUnload(true);
        renderQuestion();
        startTimer();
        editor.focus();
      } catch (err) {
        toast(err?.message || 'Could not start test.', 'error');
      }
    }

    async function runCurrent() {
      if (running || !state?.attemptId) return;
      persistCurrent();
      const q = currentQ();
      const ans = state.answers[q.id];
      running = true;
      el('run-state').textContent = 'Running...';
      el('btn-run').disabled = true;
      try {
        const result = await CodingService.runCode({
          attemptId: state.attemptId,
          questionId: q.id,
          language: ans.language,
          code: ans.code,
        });
        ans.lastRun = result;
        el('run-state').textContent = result.overall;
        renderCases(result);
        renderNav();
      } catch (err) {
        el('run-state').textContent = 'Runtime Error';
        toast(err?.message || 'Could not run code.', 'error');
      } finally {
        running = false;
        el('btn-run').disabled = false;
      }
    }

    async function submitExam(auto = false) {
      if (submitting || !state?.attemptId) return;
      persistCurrent();
      if (!auto) {
        const answered = answeredCount();
        const total = state.test.items.length;
        const ok = typeof confirmAction === 'function'
          ? await confirmAction({
              title: 'Submit Test?',
              message: `You have answered ${answered} of ${total} questions. Are you sure you want to submit?`,
              confirmText: 'Submit Test',
              cancelText: 'Cancel',
              variant: 'primary',
            })
          : window.confirm('Submit test now?');
        if (!ok) return;
      }
      submitting = true;
      stopTimer();
      bindUnload(false);
      const timeTakenSeconds = Math.max(0, Math.round((Date.now() - state.startedAt) / 1000));
      try {
        const result = await CodingService.submitAttempt(state.attemptId, { timeTakenSeconds });
        state.lastResult = result;
        state.attemptId = null;
        if (auto) toast('Time is up — test submitted automatically.', 'info');
        renderResult(result);
      } catch (err) {
        submitting = false;
        toast(err?.message || 'Submit failed.', 'error');
        if (!auto) {
          bindUnload(true);
          startTimer();
        }
        return;
      }
      submitting = false;
    }

    function renderResult(result) {
      showPanel('result');
      const pct = Math.max(0, Math.min(100, Number(result.percentage) || 0));
      el('result-hero').innerHTML = `
        <div class="text-center py-2">
          <div class="text-muted-2 mb-1">Coding Test Result</div>
          <div class="cod-score">${esc(result.score)} / ${esc(result.totalMarks)}</div>
          <div class="cod-pct">${esc(result.percentage)}%</div>
          <span class="badge-soft ${result.passed ? 'success' : 'danger'} mt-2">${esc(result.status)}</span>
        </div>`;
      el('result-stats').innerHTML = [
        ['Questions', result.questions],
        ['Correct', result.correct],
        ['Incorrect', result.incorrect],
        ['Skipped', result.skipped],
        ['Time Taken', result.timeTakenLabel],
      ].map(([lbl, val]) => `
        <div class="col-6 col-md"><div class="card-surface p-3 apt-stat">
          <div class="small text-muted-2">${esc(lbl)}</div>
          <div class="val" style="font-size:1.2rem">${esc(val)}</div>
        </div></div>`).join('');
      el('result-bar').innerHTML = `
        <div class="d-flex justify-content-between small text-muted-2 mb-1">
          <span>Performance Summary</span><span>${esc(result.percentage)}%</span>
        </div>
        <div class="cod-bar"><span style="width:${pct}%"></span></div>`;
      el('result-questions').innerHTML = (result.questionResults || []).map((row) => {
        const cls = row.status === 'Correct' ? 'success' : row.status === 'Incorrect' ? 'danger' : 'muted';
        return `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
          <div>Question ${esc(row.index)} — ${esc(row.title)}</div>
          <span class="badge-soft ${cls}">${esc(row.status)}</span>
        </div>`;
      }).join('') || '<p class="text-muted-2 mb-0">No question analysis available.</p>';
    }

    el('language')?.addEventListener('change', () => {
      const q = currentQ();
      if (!q || !editor) return;
      const language = el('language').value;
      const ans = state.answers[q.id] || { language: 'Python', code: editor.getValue(), lastRun: null };
      state.answers[q.id] = ans;
      const prevLang = ans.language;
      const prevCode = editor.getValue();
      const wasStarter = String(prevCode || '').trim() === String(q.starterCode[prevLang] || '').trim();
      ans.language = language;
      if (wasStarter || !String(prevCode || '').trim()) {
        ans.code = q.starterCode[language] || '';
        ans.lastRun = null;
      } else {
        ans.code = prevCode;
      }
      editor.setLanguage(language);
      editor.setValue(ans.code);
      renderCases(ans.lastRun);
      if (state.attemptId) CodingService.saveDraft(state.attemptId, q.id, language, ans.code);
    });

    root.addEventListener('click', (e) => {
      const t = e.target.closest('[data-cod-action]');
      if (!t) return;
      const action = t.getAttribute('data-cod-action');
      if (action === 'start') beginExam();
      if (action === 'cancel' || action === 'done') {
        persistCurrent();
        stopTimer();
        bindUnload(false);
        onExit(state?.lastResult);
      }
      if (action === 'prev') {
        persistCurrent();
        if (state.index > 0) {
          state.index -= 1;
          renderQuestion();
        }
      }
      if (action === 'next') {
        persistCurrent();
        if (state.index < state.test.items.length - 1) {
          state.index += 1;
          renderQuestion();
        }
      }
      if (action === 'run') runCurrent();
      if (action === 'submit') submitExam(false);
    });

    return {
      open(testMeta) {
        stopTimer();
        submitting = false;
        running = false;
        state = { testMeta, test: null, answers: {}, index: 0, attemptId: null };
        renderInstructions(testMeta);
        root.classList.remove('d-none');
      },
      hide() {
        stopTimer();
        bindUnload(false);
        root.classList.add('d-none');
      },
    };
  }

  global.CodingExam = { createExamController, createCodeEditor, esc, difficultyClass };
})(window);
