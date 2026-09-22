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
      setReadOnly(on) {
        input.readOnly = !!on;
        input.classList.toggle('is-locked', !!on);
      },
      focus() { if (!input.readOnly) input.focus(); },
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
    let examLockdown = false;
    let lockdownGuardsBound = false;
    let focusViolationHandled = false;
    let remainingMs = 0;
    let timerDeadline = 0;
    let lockOverlay = null;

    function el(id) {
      return root.querySelector(`[data-cod="${id}"]`);
    }

    function showPanel(name) {
      root.querySelectorAll('[data-cod-panel]').forEach((p) => {
        p.classList.toggle('d-none', p.getAttribute('data-cod-panel') !== name);
      });
    }

    function problemColumn() {
      return root.querySelector('[data-cod="q-body"]')?.closest('.col-lg-5') || null;
    }

    function isProblemArea(node) {
      if (!node) return false;
      const col = problemColumn();
      const elNode = node instanceof Element ? node : node.parentElement;
      return !!(col && elNode && col.contains(elNode));
    }

    function selectionInProblemArea() {
      const sel = window.getSelection();
      if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;
      const node = sel.anchorNode;
      return isProblemArea(node instanceof Element ? node : node?.parentElement);
    }

    function isEditorArea(node) {
      if (!node) return false;
      const elNode = node instanceof Element ? node : node.parentElement;
      return !!(elNode && (
        elNode.closest('[data-cod="editor"]')
        || elNode.closest('[data-cod="stdin"]')
        || elNode.closest('.cod-input')
      ));
    }

    function ensureLockOverlay() {
      if (lockOverlay) return lockOverlay;
      const overlay = document.createElement('div');
      overlay.setAttribute('data-cod-lock-overlay', '');
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.display = 'none';
      overlay.style.alignItems = 'center';
      overlay.style.justifyContent = 'center';
      overlay.style.padding = '1rem';
      overlay.style.background = 'rgba(15, 23, 42, 0.78)';
      overlay.style.backdropFilter = 'blur(4px)';
      overlay.style.zIndex = '1080';
      overlay.style.pointerEvents = 'auto';
      overlay.innerHTML = `
        <div style="max-width:34rem;width:min(34rem,100%);border-radius:1rem;padding:1rem 1.1rem;background:#fff;box-shadow:0 20px 60px rgba(15,23,42,.22);border:1px solid rgba(148,163,184,.35)">
          <div style="font-size:1rem;font-weight:700;margin-bottom:.35rem">Test Ended</div>
          <div data-cod-lock-message style="font-size:.95rem;line-height:1.45;color:#334155">You left the test window. Submitting your answers and signing you out…</div>
        </div>`;
      document.body.appendChild(overlay);
      lockOverlay = overlay;
      return lockOverlay;
    }

    function showLockOverlay(message) {
      const overlay = ensureLockOverlay();
      const msg = overlay.querySelector('[data-cod-lock-message]');
      if (msg) msg.textContent = message || 'You have left the test window. Please return to continue.';
      overlay.style.display = 'flex';
    }

    function hideLockOverlay() {
      if (lockOverlay) {
        lockOverlay.style.display = 'none';
      }
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

    function syncTimerDisplay() {
      if (!el('timer')) return;
      el('timer').innerHTML = `<i class="bi bi-stopwatch"></i> ${CodingService.formatTimer(remainingMs / 1000)}`;
      el('timer').classList.toggle('is-low', remainingMs < 60000);
    }

    function freezeExamInteractions() {
      if (editor) editor.setReadOnly(true);
      if (el('stdin')) el('stdin').readOnly = true;
      if (el('language')) el('language').disabled = true;
      document.querySelectorAll('[data-cod-action="run"], [data-cod-action="submit-answer"], [data-cod-action="submit"]').forEach((btn) => {
        btn.disabled = true;
      });
      root.querySelectorAll('[data-goto]').forEach((btn) => { btn.disabled = true; });
      if (el('btn-prev')) el('btn-prev').disabled = true;
      if (el('btn-next')) el('btn-next').disabled = true;
    }

    function restoreExamInteractions() {
      const locked = !!state?.submitted;
      if (editor) editor.setReadOnly(locked);
      if (el('stdin')) el('stdin').readOnly = locked;
      if (el('language')) el('language').disabled = locked;
      document.querySelectorAll('[data-cod-action="run"], [data-cod-action="submit-answer"], [data-cod-action="submit"]').forEach((btn) => {
        btn.disabled = locked || submitting || running;
      });
      root.querySelectorAll('[data-goto]').forEach((btn) => { btn.disabled = locked; });
      if (el('btn-prev')) el('btn-prev').disabled = locked || state.index <= 0;
      if (el('btn-next')) el('btn-next').disabled = locked || state.index >= state.test.items.length - 1;
    }

    function logoutAfterViolation() {
      if (typeof Auth !== 'undefined' && typeof Auth.logout === 'function') {
        Auth.logout();
        return;
      }
      window.location.href = 'public-stats.html';
    }

    async function handleFocusViolation() {
      if (focusViolationHandled || !examLockdown || state?.submitted || !state?.attemptId || submitting) return;
      if (!document.hidden && document.hasFocus()) return;
      focusViolationHandled = true;
      stopTimer();
      bindLockdownGuards(false);
      bindUnload(false);
      examLockdown = false;
      freezeExamInteractions();
      showLockOverlay('You switched tabs or left the test window. Your test is being submitted and you will be signed out.');
      try {
        await submitExam(true, { logoutAfter: true });
      } catch (_) { /* still sign out below */ }
      logoutAfterViolation();
    }

    function onVisibilityChange() {
      if (document.hidden) handleFocusViolation();
    }

    function onClipboardBlock(e) {
      if (!examLockdown || !state?.attemptId || state.submitted) return;
      if (e.type === 'paste') {
        if (!isEditorArea(e.target)) {
          e.preventDefault();
          e.stopPropagation();
        }
        return;
      }
      if (isProblemArea(e.target) || selectionInProblemArea()) {
        e.preventDefault();
        e.stopPropagation();
      }
    }

    function onContextMenuBlock(e) {
      if (!examLockdown || !state?.attemptId || state.submitted) return;
      if (isProblemArea(e.target)) e.preventDefault();
    }

    function onSelectStartBlock(e) {
      if (!examLockdown || !state?.attemptId || state.submitted) return;
      if (isProblemArea(e.target)) e.preventDefault();
    }

    function bindLockdownGuards(on) {
      if (on && !lockdownGuardsBound) {
        document.addEventListener('visibilitychange', onVisibilityChange);
        root.addEventListener('copy', onClipboardBlock, true);
        root.addEventListener('cut', onClipboardBlock, true);
        root.addEventListener('paste', onClipboardBlock, true);
        root.addEventListener('contextmenu', onContextMenuBlock, true);
        root.addEventListener('selectstart', onSelectStartBlock, true);
        lockdownGuardsBound = true;
      }
      if (!on && lockdownGuardsBound) {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        root.removeEventListener('copy', onClipboardBlock, true);
        root.removeEventListener('cut', onClipboardBlock, true);
        root.removeEventListener('paste', onClipboardBlock, true);
        root.removeEventListener('contextmenu', onContextMenuBlock, true);
        root.removeEventListener('selectstart', onSelectStartBlock, true);
        lockdownGuardsBound = false;
      }
      if (!on) hideLockOverlay();
    }

    function teardownLockdown() {
      examLockdown = false;
      focusViolationHandled = false;
      bindLockdownGuards(false);
      bindUnload(false);
      root.removeAttribute('data-cod-locked');
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
      const customInput = el('stdin') ? el('stdin').value : '';
      state.answers[q.id] = state.answers[q.id] || {};
      state.answers[q.id].language = language;
      state.answers[q.id].code = code;
      state.answers[q.id].customInput = customInput;
      if (state.attemptId) {
        if (isPracticeMode()) {
          CodingService.savePracticeDraft(state.attemptId, { language, code, customInput });
        } else {
          CodingService.saveDraft(state.attemptId, q.id, { language, code, customInput });
        }
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
      const lockdownNote = `
        <div class="alert alert-warning py-2 px-3 small mb-3">
          <strong>During the test:</strong> problem statements cannot be copied. Switching tabs or windows will automatically submit your test and sign you out.
          You can still edit code in the editor. These rules apply for the full duration.
        </div>`;
      el('instr-list').innerHTML = `${lockdownNote}<ul class="text-muted-2 mb-0 ps-3">${lines.length ? lines.map((line) => `<li>${esc(line)}</li>`).join('') : '<li>Read each problem carefully. Write and run your code before submitting.</li>'}</ul>`;
    }

    function isPracticeMode() {
      return !!state?.practiceMode;
    }

    function applyPracticeUi(on) {
      root.setAttribute('data-cod-mode', on ? 'practice' : 'test');
      if (el('timer')) el('timer').classList.toggle('d-none', on);
      root.querySelector('[data-cod="exam-nav"]')?.classList.toggle('d-none', on);
      root.querySelector('[data-cod="practice-actions"]')?.classList.toggle('d-none', !on);
      root.querySelector('[data-cod="test-actions"]')?.classList.toggle('d-none', on);
      if (el('btn-submit-test')) el('btn-submit-test').classList.toggle('d-none', on);
      if (el('q-kicker')) el('q-kicker').classList.toggle('d-none', on);
    }

    function renderProblem(q) {
      const example = (q.examples && q.examples[0]) || null;
      if (isPracticeMode()) {
        const diff = difficultyClass(q.difficulty || 'Medium');
        el('q-kicker').textContent = '';
        el('q-title').innerHTML = `${esc(q.title)} <span class="badge-soft ${diff} ms-1">${esc(q.difficulty || 'Medium')}</span>`;
      } else {
        el('q-kicker').textContent = `Question ${state.index + 1} of ${state.test.items.length}`;
        el('q-title').textContent = q.title;
      }
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

    function statusBadge(status) {
      const s = String(status || '');
      if (s === 'Passed') return { cls: 'success', text: '✓ Test Case Passed' };
      if (s === 'Wrong Answer') return { cls: 'danger', text: '✕ Wrong Answer' };
      if (s === 'Syntax Error') return { cls: 'danger', text: '✕ Syntax Error' };
      if (s === 'Runtime Error') return { cls: 'danger', text: '✕ Runtime Error' };
      if (s === 'Compilation Error') return { cls: 'danger', text: '✕ Compilation Error' };
      if (s === 'Time Limit Exceeded') return { cls: 'warning', text: '⏱ Time Limit Exceeded' };
      if (s === 'Not Run') return { cls: 'muted', text: 'Not Run' };
      if (s === 'Running') return { cls: 'info', text: 'Running code...' };
      return { cls: 'muted', text: s || '—' };
    }

    function caseBadge(status) {
      const s = String(status || '');
      if (s === 'Passed') return { cls: 'success', text: '✓ Passed' };
      if (s === 'Wrong Answer' || s === 'Failed') return { cls: 'danger', text: '✕ Failed' };
      if (s === 'Syntax Error' || s === 'Runtime Error' || s === 'Compilation Error') {
        return { cls: 'danger', text: '✕ ' + s };
      }
      if (s === 'Time Limit Exceeded') return { cls: 'warning', text: '⏱ TLE' };
      return { cls: 'muted', text: 'Not Run' };
    }

    function formatRunError(custom) {
      const status = String(custom?.status || '');
      const stderr = String(custom?.stderr || '').trim();
      const isError = ['Syntax Error', 'Runtime Error', 'Compilation Error', 'Time Limit Exceeded'].includes(status);
      if (!isError && !stderr) return '';
      const parts = [];
      if (isError) parts.push(status);
      if (stderr && stderr !== status) parts.push(stderr);
      return parts.join('\n');
    }

    function renderRunPanel(run, runningNow) {
      const out = el('output');
      const expected = el('expected');
      const stderr = el('stderr');
      const status = el('run-status');

      if (runningNow) {
        if (status) status.innerHTML = '<span class="badge-soft info">Running code...</span>';
        if (out) out.textContent = '';
        if (expected) expected.textContent = '';
        if (stderr) {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
        renderCaseTable(null, currentQ());
        return;
      }

      if (!run) {
        const q = currentQ();
        const sample = (q?.testCases || []).find((t) => t.sample);
        if (out) out.textContent = '';
        if (expected) expected.textContent = sample ? sample.expected : '';
        if (stderr) {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
        if (status) status.innerHTML = '<span class="small text-muted-2">Run code to see output.</span>';
        renderCaseTable(null, q);
        return;
      }

      const custom = run.custom || {};
      const badge = statusBadge(custom.status || run.overall);
      if (status) status.innerHTML = `<span class="badge-soft ${badge.cls}">${esc(badge.text)}</span>`;
      if (out) out.textContent = custom.output || '';
      if (expected) expected.textContent = custom.expected || '';
      const detail = formatRunError(custom);
      if (stderr) {
        if (detail) {
          stderr.textContent = detail;
          stderr.classList.remove('d-none');
        } else {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
      }
      renderCaseTable(run, currentQ());
    }

    function renderCaseTable(run, q) {
      const table = el('case-table') || el('cases');
      const summary = el('case-summary');
      if (!table) return;
      const rows = run?.results?.length
        ? run.results
        : (q?.testCases || []).map((tc, i) => ({
            index: i + 1,
            label: `Test Case ${i + 1}`,
            status: 'Not Run',
            passed: false,
          }));
      table.innerHTML = `
        <div class="table-wrap">
          <table class="table-modern mb-0">
            <thead><tr><th>Test Case</th><th>Status</th></tr></thead>
            <tbody>
              ${rows.map((tc, i) => {
                const badge = caseBadge(tc.status);
                const err = String(tc.stderr || '').trim();
                const showErr = err && !['Passed', 'Not Run'].includes(String(tc.status || ''));
                return `<tr>
                  <td>
                    ${esc(tc.label || `Test Case ${tc.index || i + 1}`)}
                    ${showErr ? `<div class="small text-danger mt-1" style="white-space:pre-wrap">${esc(err)}</div>` : ''}
                  </td>
                  <td><span class="badge-soft ${badge.cls}">${esc(badge.text)}</span></td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>`;
      const pass = rows.filter((r) => r.passed).length;
      if (summary) summary.textContent = `${pass} / ${rows.length} Test Cases Passed`;
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
          if (state.submitted) return;
          persistCurrent();
          state.index = Number(btn.getAttribute('data-goto'));
          renderQuestion();
        });
      });
      el('btn-prev').disabled = state.index <= 0 || state.submitted;
      el('btn-next').disabled = state.index >= state.test.items.length - 1 || state.submitted;
    }

    function setBusy(on) {
      const locked = !!(state?.submitted);
      el('btn-run') && (el('btn-run').disabled = on || locked);
      el('btn-submit') && (el('btn-submit').disabled = on || locked);
      el('btn-submit-test') && (el('btn-submit-test').disabled = on || locked);
      document.querySelectorAll('[data-cod-action="submit"], [data-cod-action="submit-answer"]').forEach((btn) => {
        btn.disabled = on || locked;
      });
      if (editor) editor.setReadOnly(locked);
      if (el('stdin')) el('stdin').readOnly = locked;
      if (el('language')) el('language').disabled = locked;
    }

    function renderQuestion() {
      const q = currentQ();
      if (!q) return;
      const sample = (q.testCases || []).find((t) => t.sample);
      const ans = state.answers[q.id] || {
        language: 'Python',
        code: q.starterCode.Python,
        customInput: sample ? sample.input : '',
        lastRun: null,
      };
      state.answers[q.id] = ans;
      el('language').value = ans.language || 'Python';
      editor.setLanguage(ans.language || 'Python');
      editor.setValue(ans.code || q.starterCode[ans.language] || '');
      if (el('stdin')) el('stdin').value = ans.customInput != null ? ans.customInput : (sample ? sample.input : '');
      renderProblem(q);
      renderRunPanel(ans.lastRun, false);
      el('run-state').textContent = '';
      renderNav();
      setBusy(false);
    }

    function startTimer(initialMs) {
      stopTimer();
      if (typeof initialMs === 'number' && Number.isFinite(initialMs)) {
        remainingMs = Math.max(0, initialMs);
      } else if (!remainingMs) {
        remainingMs = Math.max(0, (state.endsAt || 0) - Date.now());
      }
      timerDeadline = Date.now() + remainingMs;
      const tick = () => {
        remainingMs = Math.max(0, timerDeadline - Date.now());
        syncTimerDisplay();
        if (remainingMs <= 0) {
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
        state.submitted = false;
        state.status = 'ACTIVE';
        state.answers = {};
        focusViolationHandled = false;
        examLockdown = true;
        remainingMs = Math.max(0, (started.endsAt || 0) - Date.now());
        (started.test.items || []).forEach((item) => {
          const sample = (item.testCases || []).find((tc) => tc.sample);
          state.answers[item.id] = {
            language: 'Python',
            code: item.starterCode.Python,
            customInput: sample ? sample.input : '',
            lastRun: null,
          };
        });
        state.index = 0;
        if (!editor) editor = createCodeEditor(el('editor'));
        showPanel('exam');
        el('exam-title').textContent = started.test.title || 'Coding Test';
        root.setAttribute('data-cod-locked', '1');
        bindUnload(true);
        bindLockdownGuards(true);
        renderQuestion();
        restoreExamInteractions();
        startTimer(remainingMs);
        editor.focus();
      } catch (err) {
        toast(err?.message || 'Could not start test.', 'error');
      }
    }

    async function runCurrent() {
      if (running || !state?.attemptId || state.submitted || state.status !== 'ACTIVE') return;
      persistCurrent();
      const q = currentQ();
      const ans = state.answers[q.id];
      running = true;
      setBusy(true);
      el('run-state').textContent = 'Running code...';
      renderRunPanel(null, true);
      try {
        const result = isPracticeMode()
          ? await CodingService.runPracticeCode({
            attemptId: state.attemptId,
            language: ans.language,
            code: ans.code,
            stdin: ans.customInput,
          })
          : await CodingService.runCode({
            attemptId: state.attemptId,
            questionId: q.id,
            language: ans.language,
            code: ans.code,
            stdin: ans.customInput,
          });
        ans.lastRun = result;
        el('run-state').textContent = '';
        renderRunPanel(result, false);
        renderNav();
      } catch (err) {
        el('run-state').textContent = '';
        const raw = String(err?.message || '');
        const message = /unavailable|is not defined|failed to load|Failed to fetch|NetworkError/i.test(raw)
          ? 'Code execution service unavailable, please try again'
          : (raw || 'Could not run code.');
        renderRunPanel({
          overall: 'Runtime Error',
          custom: { output: '', expected: '', stderr: message, status: 'Runtime Error', passed: false },
          results: [],
          passedCount: 0,
          totalCount: 0,
        }, false);
        toast(message, 'error');
      } finally {
        running = false;
        setBusy(false);
      }
    }

    function submitAnswer() {
      if (submitting || running || !state?.attemptId || state.submitted || state.status !== 'ACTIVE') return;
      persistCurrent();
      const last = (state.test.items || []).length - 1;
      if (state.index < last) {
        state.index += 1;
        renderQuestion();
        toast('Answer saved.', 'success');
        return;
      }
      renderNav();
      toast('Answer saved. Click Finish Test to end the exam.', 'success');
    }

    async function submitPracticeSolution() {
      if (submitting || running || !state?.attemptId || state.submitted || !isPracticeMode()) return false;
      persistCurrent();
      const ok = typeof confirmAction === 'function'
        ? await confirmAction({
          title: 'Submit solution?',
          message: 'Your code will be judged against all test cases.',
          confirmText: 'Submit',
          cancelText: 'Cancel',
          variant: 'primary',
        })
        : window.confirm('Submit your solution?');
      if (!ok) return false;
      submitting = true;
      setBusy(true);
      const timeTakenSeconds = Math.max(0, Math.round((Date.now() - state.startedAt) / 1000));
      try {
        const result = await CodingService.submitPracticeProblem(state.attemptId, { timeTakenSeconds });
        state.lastResult = result;
        state.submitted = true;
        state.attemptId = null;
        renderPracticeResult(result);
      } catch (err) {
        submitting = false;
        setBusy(false);
        toast(err?.message || 'Submit failed.', 'error');
        return false;
      }
      submitting = false;
      return true;
    }

    function renderPracticeResult(result) {
      showPanel('result');
      const accepted = !!result.accepted;
      el('result-hero').innerHTML = `
        <div class="text-center py-2">
          <div class="text-muted-2 mb-1">Practice Result</div>
          <div class="cod-score">${accepted ? 'Accepted' : 'Wrong Answer'}</div>
          <div class="cod-pct">${esc(result.testsPassed ?? 0)} / ${esc(result.testsTotal ?? 0)} test cases</div>
          <span class="badge-soft ${accepted ? 'success' : 'danger'} mt-2">${esc(result.status || (accepted ? 'Accepted' : 'Wrong Answer'))}</span>
        </div>`;
      el('result-stats').innerHTML = [
        ['Test cases', `${result.testsPassed ?? 0} / ${result.testsTotal ?? 0}`],
        ['Score', `${result.score ?? 0} / ${result.totalMarks ?? 0}`],
        ['Time', result.timeTakenLabel || '—'],
        ['Status', result.practiceStatus || (accepted ? 'solved' : 'attempted')],
      ].map(([lbl, val]) => `
        <div class="col-6 col-md"><div class="card-surface p-3 apt-stat">
          <div class="small text-muted-2">${esc(lbl)}</div>
          <div class="val" style="font-size:1.2rem">${esc(val)}</div>
        </div></div>`).join('');
      el('result-bar').innerHTML = '';
      el('result-questions').innerHTML = (result.questionResults || []).map((row) => {
        const cls = row.status === 'Correct' ? 'success' : 'danger';
        return `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
          <div>${esc(row.title)}</div>
          <span class="badge-soft ${cls}">${esc(row.status)} · ${esc(row.testsPassed)}/${esc(row.testsTotal)}</span>
        </div>`;
      }).join('') || '';
    }

    async function beginPractice() {
      if (!state?.practiceMeta) return;
      try {
        const started = CodingService.startPracticeAttempt(state.practiceMeta);
        state.attemptId = started.attemptId;
        state.test = { title: started.problem.title, items: [started.problem] };
        state.startedAt = started.startedAt;
        state.submitted = false;
        state.status = 'ACTIVE';
        state.index = 0;
        state.practiceMode = true;
        state.answers = started.problem.id ? { [started.problem.id]: {
          language: 'Python',
          code: started.problem.starterCode?.Python || '',
          customInput: (started.problem.testCases || []).find((t) => t.sample)?.input || '',
          lastRun: null,
        } } : {};
        if (!editor) editor = createCodeEditor(el('editor'));
        showPanel('exam');
        applyPracticeUi(true);
        el('exam-title').textContent = started.problem.title || 'Coding Problem';
        renderQuestion();
        editor.focus();
      } catch (err) {
        toast(err?.message || 'Could not open problem.', 'error');
      }
    }

    async function submitExam(auto = false, options = {}) {
      if (submitting || !state?.attemptId || state.submitted) return false;
      persistCurrent();
      if (!auto) {
        const ok = typeof confirmAction === 'function'
          ? await confirmAction({
              title: 'Finish Test?',
              message: 'Are you sure you want to finish this test? You may not be able to modify your answers after submission.',
              confirmText: 'Finish Test',
              cancelText: 'Cancel',
              variant: 'primary',
            })
          : window.confirm('Finish this test? You may not be able to modify your answers after submission.');
        if (!ok) return false;
      }
      submitting = true;
      state.status = 'SUBMITTED';
      setBusy(true);
      if (el('btn-submit-test')) el('btn-submit-test').textContent = 'Submitting…';
      stopTimer();
      if (!options.logoutAfter) teardownLockdown();
      const timeTakenSeconds = Math.max(0, Math.round((Date.now() - state.startedAt) / 1000));
      try {
        const result = await CodingService.submitAttempt(state.attemptId, { timeTakenSeconds });
        state.lastResult = result;
        state.submitted = true;
        state.attemptId = null;
        if (editor) editor.setReadOnly(true);
        if (options.logoutAfter) {
          submitting = false;
          state.submitted = true;
          return true;
        }
        if (auto) toast('Time is up — test submitted automatically.', 'info');
        if (result.saveWarning) toast(result.saveWarning, 'info');
        renderResult(result);
      } catch (err) {
        submitting = false;
        if (options.logoutAfter) return false;
        state.status = 'ACTIVE';
        if (el('btn-submit-test')) el('btn-submit-test').textContent = 'Finish Test';
        toast(err?.message || 'Submit failed.', 'error');
        if (!auto) {
          examLockdown = true;
          root.setAttribute('data-cod-locked', '1');
          bindUnload(true);
          bindLockdownGuards(true);
          restoreExamInteractions();
          startTimer(remainingMs);
          setBusy(false);
        }
        return false;
      }
      submitting = false;
      return true;
    }

    function renderResult(result) {
      showPanel('result');
      const pct = Math.max(0, Math.min(100, Number(result.percentage) || 0));
      const contestType = String(result.contestType || state?.test?.contestType || state?.testMeta?.contestType || '');
      const isContest = contestType === 'weekly' || contestType === 'monthly';
      const winnersReady = !!result.winnersPublished || !!result.contestClosed;
      const contestNote = isContest
        ? `<div class="border rounded-3 p-3 mt-3">
            <div class="small fw-semibold mb-1">${winnersReady ? '🏆 Contest result saved' : '⚔️ Your contest score is in'}</div>
            <div class="small text-muted-2">${winnersReady
              ? 'Winners are now visible in Contest arena on the coding page.'
              : 'Your score is saved now. The winner is published after the contest closes.'}</div>
          </div>`
        : '';
      el('result-hero').innerHTML = `
        <div class="text-center py-2">
          <div class="text-muted-2 mb-1">${isContest ? 'Contest Result' : 'Coding Test Result'}</div>
          <div class="cod-score">${esc(result.score)} / ${esc(result.totalMarks)}</div>
          <div class="cod-pct">${esc(result.percentage)}%</div>
          <span class="badge-soft ${result.passed ? 'success' : 'danger'} mt-2">${esc(result.status)}</span>
        </div>
        <div class="border rounded-3 p-3 mt-3">
          <div class="small fw-semibold mb-2">Submission Result</div>
          <div class="small">Passed: <strong>${esc(result.testsPassed ?? 0)} / ${esc(result.testsTotal ?? 0)}</strong> test cases</div>
          <div class="small">Score: <strong>${esc(result.score)} / ${esc(result.totalMarks)}</strong></div>
          <div class="small">Status: <strong>${esc(result.status)}</strong></div>
        </div>${contestNote}`;
      const rankCards = [];
      if (result.rank != null) {
        rankCards.push(['Overall rank', `#${result.rank}${Number(result.overallTotal) > 0 ? ` of ${result.overallTotal}` : ''}`]);
      }
      if (result.departmentRank != null) {
        const deptLabel = result.departmentName ? `${result.departmentName} rank` : 'Department rank';
        rankCards.push([deptLabel, `#${result.departmentRank}${Number(result.departmentTotal) > 0 ? ` of ${result.departmentTotal}` : ''}`]);
      }
      el('result-stats').innerHTML = [
        ...rankCards,
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
          <span class="badge-soft ${cls}">${esc(row.status)}${row.testsTotal ? ` · ${esc(row.testsPassed)}/${esc(row.testsTotal)}` : ''}</span>
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
      renderRunPanel(ans.lastRun, false);
      if (state.attemptId) CodingService.saveDraft(state.attemptId, q.id, { language, code: ans.code, customInput: ans.customInput });
    });

    root.addEventListener('click', (e) => {
      const t = e.target.closest('[data-cod-action]');
      if (!t) return;
      const action = t.getAttribute('data-cod-action');
      if (action === 'start') beginExam();
      if (action === 'start-practice') beginPractice();
      if (action === 'submit-practice') submitPracticeSolution();
      if (action === 'cancel' || action === 'back') {
        if (state.test && !state.submitted && state.status === 'ACTIVE') {
          const msg = isPracticeMode()
            ? 'Leave this problem? Your draft is not submitted yet.'
            : 'Leave this test and go back? Your code is saved as a draft.';
          if (!window.confirm(msg)) {
            return;
          }
        }
        persistCurrent();
        stopTimer();
        teardownLockdown();
        onExit(state?.lastResult);
      }
      if (action === 'done') {
        persistCurrent();
        stopTimer();
        teardownLockdown();
        onExit(state?.lastResult);
      }
      if (action === 'prev') {
        if (state.submitted || state.status !== 'ACTIVE') return;
        persistCurrent();
        if (state.index > 0) {
          state.index -= 1;
          renderQuestion();
        }
      }
      if (action === 'next') {
        if (state.submitted || state.status !== 'ACTIVE') return;
        persistCurrent();
        if (state.index < state.test.items.length - 1) {
          state.index += 1;
          renderQuestion();
        }
      }
      if (action === 'run' && state.status === 'ACTIVE') runCurrent();
      if (action === 'submit-answer' && state.status === 'ACTIVE') submitAnswer();
      if (action === 'submit' && state.status !== 'SUBMITTED') submitExam(false);
    });

    return {
      open(testMeta) {
        stopTimer();
        teardownLockdown();
        submitting = false;
        running = false;
        remainingMs = 0;
        timerDeadline = 0;
        applyPracticeUi(false);
        state = { testMeta, test: null, answers: {}, index: 0, attemptId: null, submitted: false, status: 'NOT_STARTED', practiceMode: false };
        renderInstructions(testMeta);
        root.classList.remove('d-none');
      },
      openPractice(problemMeta) {
        stopTimer();
        teardownLockdown();
        submitting = false;
        running = false;
        state = {
          practiceMeta: problemMeta,
          test: null,
          answers: {},
          index: 0,
          attemptId: null,
          submitted: false,
          status: 'NOT_STARTED',
          practiceMode: true,
        };
        applyPracticeUi(true);
        beginPractice();
        root.classList.remove('d-none');
      },
      hide() {
        stopTimer();
        teardownLockdown();
        root.classList.add('d-none');
      },
      showResult(result) {
        stopTimer();
        teardownLockdown();
        submitting = false;
        running = false;
        state = { test: null, testMeta: null, lastResult: result, submitted: true, status: 'SUBMITTED' };
        renderResult(result);
        root.classList.remove('d-none');
      },
    };
  }

  global.CodingExam = { createExamController, createCodeEditor, esc, difficultyClass };
})(window);
