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

  function createAnswerState(q, overrides = {}) {
    const language = overrides.language || 'Python';
    const codes = overrides.codes && typeof overrides.codes === 'object'
      ? { ...overrides.codes }
      : {};
    if (Object.keys(codes).length === 0 && overrides.code != null) {
      codes[language] = String(overrides.code);
    }
    const code = Object.prototype.hasOwnProperty.call(codes, language)
      ? codes[language]
      : String(overrides.code ?? q?.starterCode?.[language] ?? '');
    const lastRuns = overrides.lastRuns && typeof overrides.lastRuns === 'object'
      ? { ...overrides.lastRuns }
      : {};
    const lastRun = overrides.lastRun ?? null;
    if (lastRun && !lastRuns[language]) {
      lastRuns[language] = lastRun;
    }

    return {
      language,
      codes,
      code,
      customInput: overrides.customInput ?? '',
      lastRun,
      lastRuns,
    };
  }

  function normalizeAnswer(q, ans) {
    if (!ans) return createAnswerState(q);
    if (!ans.codes || typeof ans.codes !== 'object') {
      ans.codes = {};
      const lang = ans.language || 'Python';
      if (ans.code != null) {
        ans.codes[lang] = String(ans.code);
      }
    }
    if (!ans.lastRuns || typeof ans.lastRuns !== 'object') {
      ans.lastRuns = {};
      if (ans.lastRun && ans.language) {
        ans.lastRuns[ans.language] = ans.lastRun;
      }
    }
    return ans;
  }

  function getLanguageCode(ans, q, language) {
    if (Object.prototype.hasOwnProperty.call(ans.codes, language)) {
      return ans.codes[language];
    }
    return q?.starterCode?.[language] ?? '';
  }

  function setLanguageCode(ans, language, code) {
    ans.codes[language] = code;
    if (ans.language === language) {
      ans.code = code;
    }
  }

  function draftFieldsForAnswer(ans, q, language, code, customInput) {
    const normalized = normalizeAnswer(q, ans);
    setLanguageCode(normalized, language, code);
    normalized.language = language;
    normalized.code = code;
    normalized.customInput = customInput;
    return {
      language,
      code,
      customInput,
      codes: normalized.codes,
      lastRuns: normalized.lastRuns,
    };
  }

  function createExamController(opts) {
    const root = opts.root;
    const onExit = opts.onExit || (() => {});
    let state = null;
    let timerId = null;
    let editor = null;
    let running = false;
    let runSeq = 0;
    let submitting = false;
    let beforeUnloadBound = false;
    let examLockdown = false;
    let lockdownGuardsBound = false;
    let navigationGuardBound = false;
    let windowBlurGuardBound = false;
    let clipboardGuardsEnabled = false;
    let focusGuardsEnabled = false;
    let focusViolationCount = 0;
    let violationAckRequired = false;
    let finalizingViolation = false;
    let lastFocusViolationAt = 0;
    let fullscreenListenerBound = false;
    let allowFullscreenExit = false;
    let fullscreenEnforceTimer = null;
    let keydownGuardBound = false;
    let focusEnforceTimer = null;
    let hiddenViolationPending = false;
    let incidentReporting = false;
    let lastServerWarningMessage = '';
    let timerPauseStartedAt = 0;
    const INCIDENT_DEBOUNCE_MS = 1500;
    /** Warnings shown as 1 of 2 and 2 of 2; the 3rd blocked switch attempt ends the test. */
    const MAX_MALPRACTICE_WARNINGS = 2;
    const TAB_SWITCH_PROHIBITED_MSG = 'You left the contest tab. Tab switching is not allowed. Return here immediately to see your malpractice warning.';
    const ACK_REQUIRED_MSG = 'You must return to this tab and tap "I understand — continue test" before the contest can continue. Tab switching and minimizing are not allowed.';
    let remainingMs = 0;
    let timerDeadline = 0;
    let lockOverlay = null;
    let editorInputBound = false;

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
      overlay.style.zIndex = '2147483000';
      overlay.style.pointerEvents = 'auto';
      overlay.innerHTML = `
        <div style="max-width:34rem;width:min(34rem,100%);border-radius:1rem;padding:1rem 1.1rem;background:#fff;box-shadow:0 20px 60px rgba(15,23,42,.22);border:1px solid rgba(148,163,184,.35)">
          <div data-cod-lock-title style="font-size:1rem;font-weight:700;margin-bottom:.35rem">Test Ended</div>
          <div data-cod-lock-message style="font-size:.95rem;line-height:1.45;color:#334155">You left the test window. Submitting your answers and signing you out…</div>
          <button type="button" class="btn btn-primary btn-sm mt-3 d-none" data-cod-lock-dismiss>I understand — continue test</button>
        </div>`;
      document.body.appendChild(overlay);
      lockOverlay = overlay;
      return lockOverlay;
    }

    function showLockOverlay(message, opts = {}) {
      mountLockOverlayForExam();
      const overlay = ensureLockOverlay();
      const title = overlay.querySelector('[data-cod-lock-title]');
      const msg = overlay.querySelector('[data-cod-lock-message]');
      const dismiss = overlay.querySelector('[data-cod-lock-dismiss]');
      if (title) title.textContent = opts.title || 'Test Ended';
      if (msg) msg.textContent = message || 'You have left the test window. Please return to continue.';
      if (dismiss) {
        if (opts.dismissible) {
          dismiss.classList.remove('d-none');
          dismiss.textContent = opts.dismissText || 'I understand — continue test';
          dismiss.onclick = () => {
            opts.onDismiss?.();
            hideLockOverlay(true);
          };
        } else {
          dismiss.classList.add('d-none');
          dismiss.onclick = null;
        }
      }
      overlay.style.display = 'flex';
    }

    function hideLockOverlay(force = false) {
      if (violationAckRequired && !force) return;
      if (lockOverlay) {
        lockOverlay.style.display = 'none';
      }
    }

    function mountLockOverlayForExam() {
      const overlay = ensureLockOverlay();
      const fsRoot = getFullscreenElement() || document.documentElement;
      if (overlay.parentElement !== fsRoot) {
        fsRoot.appendChild(overlay);
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
      if (violationAckRequired) return;
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
      if (typeof apiFetch === 'function') {
        apiFetch('/auth/logout', { method: 'POST', skipAuthRedirect: true, skipAuthRetry: true }).catch(() => {});
      }
      if (typeof Auth !== 'undefined' && typeof Auth.clear === 'function') {
        Auth.clear();
      }
      window.location.href = 'login.html';
    }

    function clipboardLockActive() {
      return (clipboardGuardsEnabled || examLockdown) && !!state?.attemptId && !state?.submitted;
    }

    function focusLockActive() {
      return focusGuardsEnabled && examLockdown && !!state?.attemptId && !state?.submitted;
    }

    function applyMalpracticeServerPayload(data) {
      if (!data || typeof data !== 'object') return;
      const st = data.state || data;
      if (st.violationCount != null) focusViolationCount = Number(st.violationCount) || 0;
      if (data.violationCount != null) focusViolationCount = Number(data.violationCount) || focusViolationCount;
      violationAckRequired = !!(st.ackRequired ?? data.ackRequired);
      if (data.warningMessage) lastServerWarningMessage = String(data.warningMessage);
    }

    function malpracticeWarningMessage() {
      if (lastServerWarningMessage) return lastServerWarningMessage;
      const n = Math.min(focusViolationCount, MAX_MALPRACTICE_WARNINGS);
      if (n === 1) {
        return 'WARNING 1 OF 2: You have left the active examination window. Further violations may result in automatic submission.';
      }
      if (n === 2) {
        return 'FINAL WARNING: This is your second malpractice violation. One more violation will automatically submit your examination and end your session.';
      }
      return 'Switching tabs is not allowed during this examination.';
    }

    function pauseTimerForWarning() {
      if (!timerPauseStartedAt) timerPauseStartedAt = Date.now();
      stopTimer();
    }

    function resumeTimerAfterAck(endsAtFromServer) {
      if (endsAtFromServer != null && Number.isFinite(Number(endsAtFromServer))) {
        state.endsAt = Number(endsAtFromServer);
        remainingMs = Math.max(0, state.endsAt - Date.now());
        timerDeadline = Date.now() + remainingMs;
      } else if (timerPauseStartedAt) {
        const paused = Date.now() - timerPauseStartedAt;
        timerDeadline += paused;
        remainingMs = Math.max(0, timerDeadline - Date.now());
      }
      timerPauseStartedAt = 0;
      if (state?.status === 'ACTIVE' && !state?.submitted) startTimer(remainingMs);
    }

    async function handleMalpracticeTermination(data) {
      if (finalizingViolation) return;
      finalizingViolation = true;
      stopTimer();
      bindLockdownGuards(false);
      bindUnload(false);
      examLockdown = false;
      freezeExamInteractions();
      showLockOverlay(
        'Your examination has been automatically submitted because you exceeded the maximum number of permitted violations.',
        { title: 'Examination ended', dismissible: false }
      );
      try {
        if (data?.submitResult) {
          state.lastResult = data.submitResult;
          state.submitted = true;
          state.attemptId = null;
        } else {
          await submitExam(true, { logoutAfter: true });
        }
      } catch (_) { /* still sign out */ }
      logoutAfterViolation();
    }

    async function acknowledgeFocusViolation() {
      if (!state?.attemptId || finalizingViolation) return;
      const pausedMs = timerPauseStartedAt ? Date.now() - timerPauseStartedAt : 0;
      try {
        const data = await CodingService.acknowledgeMalpractice(state.attemptId, { pausedMs });
        applyMalpracticeServerPayload(data);
        violationAckRequired = !!(data?.ackRequired ?? data?.state?.ackRequired);
        resumeTimerAfterAck(data?.endsAt ?? data?.state?.endsAt);
        if (focusLockActive() && state?.status === 'ACTIVE' && !state?.submitted) {
          restoreExamInteractions();
        }
      } catch (err) {
        toast(err?.message || 'Could not acknowledge warning.', 'error');
        violationAckRequired = true;
        showMalpracticeAckOverlay();
      }
    }

    function showMalpracticeAckOverlay() {
      if (!violationAckRequired || finalizingViolation) return;
      freezeExamInteractions();
      pauseTimerForWarning();
      tryEnterExamFullscreen().finally(() => {
        mountLockOverlayForExam();
        const title = focusViolationCount >= 2 ? 'Final malpractice warning' : 'Malpractice warning';
        showLockOverlay(malpracticeWarningMessage(), {
          title,
          dismissible: true,
          dismissText: 'I Understand — Continue Test',
          onDismiss: () => { acknowledgeFocusViolation(); },
        });
      });
    }

    async function reportUnifiedMalpracticeIncident(source) {
      if (!focusLockActive() || finalizingViolation || !state?.attemptId) return;
      const now = Date.now();
      if (now - lastFocusViolationAt < INCIDENT_DEBOUNCE_MS) return;
      if (incidentReporting) return;
      incidentReporting = true;
      lastFocusViolationAt = now;
      freezeExamInteractions();
      try {
        persistCurrent();
        const data = await CodingService.reportMalpracticeIncident(state.attemptId, { source });
        applyMalpracticeServerPayload(data);
        if (data?.terminated || data?.shouldAutoSubmit) {
          await handleMalpracticeTermination(data);
          return;
        }
        if (data?.ackRequired) {
          if (data?.incremented !== false) pauseTimerForWarning();
          const deferOverlay = document.hidden;
          if (!deferOverlay) {
            toast(`Malpractice warning ${Math.min(focusViolationCount, MAX_MALPRACTICE_WARNINGS)} of ${MAX_MALPRACTICE_WARNINGS}.`, 'warning');
            showMalpracticeAckOverlay();
          }
        }
      } catch (err) {
        toast(err?.message || 'Could not record malpractice incident.', 'error');
      } finally {
        incidentReporting = false;
      }
    }

    function recordBlockedMalpracticeAttempt(source) {
      reportUnifiedMalpracticeIncident(source);
    }

    function registerFocusViolation() {
      recordBlockedMalpracticeAttempt('nav');
    }

    function isLeaveShortcut(e) {
      const key = e.key;
      const ctrl = e.ctrlKey || e.metaKey;
      const alt = e.altKey;
      if (key === 'F6' || key === 'F11') return true;
      if (ctrl && (key === 'Tab' || key === 'PageUp' || key === 'PageDown'
        || key === 't' || key === 'T' || key === 'n' || key === 'N'
        || key === 'w' || key === 'W')) return true;
      if (alt && (key === 'Tab' || key === 'F4')) return true;
      return false;
    }

    function onExamKeydown(e) {
      if (!focusLockActive() || finalizingViolation) return;
      if (!isPracticeMode() && e.key === 'Escape') {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (!getFullscreenElement()) tryEnterExamFullscreen();
        toast('Stay in fullscreen until you submit the test.', 'warning');
        return;
      }
      if (!isLeaveShortcut(e)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      recordBlockedMalpracticeAttempt('keyboard');
    }

    function getFullscreenElement() {
      return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    function startFocusEnforce() {
      stopFocusEnforce();
      focusEnforceTimer = window.setInterval(() => {
        if (!focusLockActive() || finalizingViolation || document.hidden) return;
        try {
          if (!getFullscreenElement()) tryEnterExamFullscreen();
          if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
            window.focus();
          }
          if (violationAckRequired && !document.hidden) {
            mountLockOverlayForExam();
            if (lockOverlay?.style.display !== 'flex') showMalpracticeAckOverlay();
          }
        } catch (_) { /* ignore */ }
      }, 350);
    }

    function stopFocusEnforce() {
      if (focusEnforceTimer) {
        clearInterval(focusEnforceTimer);
        focusEnforceTimer = null;
      }
    }

    function bindKeydownGuard(on) {
      if (on && !keydownGuardBound) {
        document.addEventListener('keydown', onExamKeydown, true);
        keydownGuardBound = true;
      }
      if (!on && keydownGuardBound) {
        document.removeEventListener('keydown', onExamKeydown, true);
        keydownGuardBound = false;
      }
    }

    async function tryEnterExamFullscreen() {
      if (isPracticeMode() || !focusLockActive() || allowFullscreenExit) return;
      const node = document.documentElement;
      try {
        if (node.requestFullscreen) await node.requestFullscreen();
        else if (node.webkitRequestFullscreen) await node.webkitRequestFullscreen();
      } catch (_) { /* browser may block without user gesture — attempt after Start click */ }
    }

    function exitExamFullscreen() {
      if (!allowFullscreenExit) return;
      try {
        if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen();
        else if (document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
      } catch (_) { /* ignore */ }
    }

    function startFullscreenEnforce() {
      stopFullscreenEnforce();
      fullscreenEnforceTimer = window.setInterval(() => {
        if (allowFullscreenExit || !focusLockActive() || isPracticeMode() || finalizingViolation) return;
        if (!getFullscreenElement()) tryEnterExamFullscreen();
      }, 400);
    }

    function stopFullscreenEnforce() {
      if (fullscreenEnforceTimer) {
        clearInterval(fullscreenEnforceTimer);
        fullscreenEnforceTimer = null;
      }
    }

    function onFullscreenChange() {
      if (allowFullscreenExit || !focusLockActive() || isPracticeMode() || finalizingViolation) return;
      if (getFullscreenElement()) return;
      recordBlockedMalpracticeAttempt('fullscreen');
      tryEnterExamFullscreen();
    }

    function bindFullscreenGuard(on) {
      if (on && !fullscreenListenerBound) {
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        fullscreenListenerBound = true;
      }
      if (!on && fullscreenListenerBound) {
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
        fullscreenListenerBound = false;
      }
    }

    function setDocumentFocusLockFlag(on) {
      if (on) document.documentElement.setAttribute('data-ph-exam-focus-lock', 'coding');
      else document.documentElement.removeAttribute('data-ph-exam-focus-lock');
    }

    function syncTimedExamChrome() {
      const stayOnPage = focusLockActive() && !isPracticeMode() && state?.status === 'ACTIVE';
      root.querySelectorAll('[data-cod-action="back"], [data-cod-action="cancel"]').forEach((btn) => {
        btn.disabled = stayOnPage;
        if (stayOnPage) {
          btn.setAttribute('title', 'Stay on this contest until you submit or time runs out.');
        } else {
          btn.removeAttribute('title');
        }
      });
    }

    function onVisibilityChange() {
      if (!focusLockActive() || finalizingViolation) return;
      if (document.hidden) {
        const wasPending = hiddenViolationPending;
        hiddenViolationPending = true;
        freezeExamInteractions();
        if (violationAckRequired) {
          showLockOverlay(ACK_REQUIRED_MSG, { title: 'Acknowledgement required', dismissible: false });
          return;
        }
        if (!wasPending && !violationAckRequired) {
          recordBlockedMalpracticeAttempt('tab');
        }
        showLockOverlay(TAB_SWITCH_PROHIBITED_MSG, { title: 'Tab switching prohibited', dismissible: false });
        return;
      }
      hiddenViolationPending = false;
      window.focus();
      tryEnterExamFullscreen();
      if (violationAckRequired) {
        showMalpracticeAckOverlay();
        return;
      }
      hideLockOverlay(true);
      if (state?.status === 'ACTIVE' && !state?.submitted) restoreExamInteractions();
    }

    function onExamWindowBlur() {
      if (!focusLockActive() || finalizingViolation || document.hidden) return;
      window.setTimeout(() => {
        if (!focusLockActive() || finalizingViolation || document.hidden) return;
        if (violationAckRequired) {
          window.focus();
          tryEnterExamFullscreen();
          showMalpracticeAckOverlay();
          return;
        }
        reportUnifiedMalpracticeIncident('blur');
      }, 0);
    }

    function bindWindowBlurGuard(on) {
      if (on && !windowBlurGuardBound) {
        window.addEventListener('blur', onExamWindowBlur);
        windowBlurGuardBound = true;
      }
      if (!on && windowBlurGuardBound) {
        window.removeEventListener('blur', onExamWindowBlur);
        windowBlurGuardBound = false;
      }
    }

    function onDocumentLeaveAttempt(e) {
      if (!focusLockActive() || finalizingViolation) return;
      if (e.target.closest('[data-cod-lock-overlay]')) return;
      const inExam = root.contains(e.target);
      const sidebarLink = e.target.closest('#sidebar a[href]');
      const topLogout = e.target.closest('#logoutBtn, #topbarLogoutBtn');
      const crumbHome = e.target.closest('.ph-crumb-home');
      const viewNav = e.target.closest('#codViewNav [data-view]');
      const externalLink = e.target.closest('a[href]');
      let block = sidebarLink || topLogout || crumbHome || viewNav;
      if (!block && externalLink && !inExam) {
        const href = String(externalLink.getAttribute('href') || '');
        if (href && href !== '#' && !href.startsWith('javascript:')) block = externalLink;
      }
      if (!block) return;
      e.preventDefault();
      e.stopPropagation();
      registerFocusViolation();
    }

    function bindNavigationGuards(on) {
      if (on && !navigationGuardBound) {
        document.addEventListener('click', onDocumentLeaveAttempt, true);
        navigationGuardBound = true;
      }
      if (!on && navigationGuardBound) {
        document.removeEventListener('click', onDocumentLeaveAttempt, true);
        navigationGuardBound = false;
      }
    }

    function onClipboardBlock(e) {
      if (!clipboardLockActive()) return;
      if (e.type === 'paste') {
        e.preventDefault();
        e.stopPropagation();
        toast('Pasting code or answers is not allowed.', 'warning');
        return;
      }
      if (isProblemArea(e.target) || selectionInProblemArea()
        || isEditorArea(e.target)) {
        e.preventDefault();
        e.stopPropagation();
      }
    }

    function onContextMenuBlock(e) {
      if (!clipboardLockActive()) return;
      if (isProblemArea(e.target) || isEditorArea(e.target)) e.preventDefault();
    }

    function onSelectStartBlock(e) {
      if (!clipboardLockActive()) return;
      if (isProblemArea(e.target)) e.preventDefault();
    }

    function bindLockdownGuards(on, options = {}) {
      const useClipboard = on && options.clipboard !== false;
      const useFocus = on && options.focus === true;
      if (on) {
        if (!lockdownGuardsBound) {
          root.addEventListener('copy', onClipboardBlock, true);
          root.addEventListener('cut', onClipboardBlock, true);
          root.addEventListener('paste', onClipboardBlock, true);
          root.addEventListener('contextmenu', onContextMenuBlock, true);
          root.addEventListener('selectstart', onSelectStartBlock, true);
          lockdownGuardsBound = true;
        }
        document.removeEventListener('visibilitychange', onVisibilityChange);
        if (useFocus) {
          document.addEventListener('visibilitychange', onVisibilityChange);
          bindNavigationGuards(true);
          bindFullscreenGuard(true);
          bindKeydownGuard(true);
          bindWindowBlurGuard(true);
          startFocusEnforce();
          startFullscreenEnforce();
          setDocumentFocusLockFlag(true);
        }
        clipboardGuardsEnabled = useClipboard;
        focusGuardsEnabled = useFocus;
        syncTimedExamChrome();
        return;
      }
      if (lockdownGuardsBound) {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        bindNavigationGuards(false);
        bindFullscreenGuard(false);
        bindKeydownGuard(false);
        bindWindowBlurGuard(false);
        stopFocusEnforce();
        stopFullscreenEnforce();
        hiddenViolationPending = false;
        setDocumentFocusLockFlag(false);
        root.removeEventListener('copy', onClipboardBlock, true);
        root.removeEventListener('cut', onClipboardBlock, true);
        root.removeEventListener('paste', onClipboardBlock, true);
        root.removeEventListener('contextmenu', onContextMenuBlock, true);
        root.removeEventListener('selectstart', onSelectStartBlock, true);
        lockdownGuardsBound = false;
      }
      clipboardGuardsEnabled = false;
      focusGuardsEnabled = false;
      hideLockOverlay();
      violationAckRequired = false;
      allowFullscreenExit = true;
      exitExamFullscreen();
      allowFullscreenExit = false;
      syncTimedExamChrome();
    }

    function teardownLockdown() {
      examLockdown = false;
      focusViolationCount = 0;
      violationAckRequired = false;
      hiddenViolationPending = false;
      finalizingViolation = false;
      lastFocusViolationAt = 0;
      bindLockdownGuards(false);
      bindUnload(false);
      root.removeAttribute('data-cod-locked');
      syncTimedExamChrome();
    }

    function answeredCount() {
      if (!state?.test) return 0;
      return state.test.items.filter((q) => {
        const ans = normalizeAnswer(q, state.answers[q.id]);
        if (!ans) return false;
        const lang = ans.language || 'Python';
        if (!Object.prototype.hasOwnProperty.call(ans.codes, lang)) return false;
        const starter = String(q.starterCode?.[lang] || '').trim();
        const code = String(ans.codes[lang] || '').trim();
        return code !== '' && code !== starter;
      }).length;
    }

    function bindEditorInput() {
      if (editorInputBound || !editor) return;
      const input = el('editor')?.querySelector('[data-input]');
      if (!input) return;
      editorInputBound = true;
      input.addEventListener('input', () => {
        if (!state || state.submitted || state.status !== 'ACTIVE' || !editor) return;
        const q = currentQ();
        if (!q) return;
        const language = el('language')?.value || 'Python';
        const ans = normalizeAnswer(q, state.answers[q.id] || createAnswerState(q));
        state.answers[q.id] = ans;
        setLanguageCode(ans, language, editor.getValue());
      });
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
      const ans = normalizeAnswer(q, state.answers[q.id] || createAnswerState(q));
      state.answers[q.id] = ans;
      const draft = draftFieldsForAnswer(ans, q, language, code, customInput);
      if (state.attemptId) {
        if (isPracticeMode()) {
          CodingService.savePracticeDraft(state.attemptId, draft);
        } else {
          CodingService.saveDraft(state.attemptId, q.id, draft);
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
          <strong>During the test:</strong> copying questions and pasting answers are disabled.
          <strong>Fullscreen is required</strong> until you submit — do not press Esc or exit fullscreen.
          <strong>Tab switching is not allowed</strong> — stay on this contest window. Each blocked attempt gives a malpractice warning (${MAX_MALPRACTICE_WARNINGS} warnings allowed); the next attempt auto-submits your test and signs you out.
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

    function updateSubmitVisibility(run) {
      const passed = run && Number(run.passedCount) > 0
        ? Number(run.passedCount)
        : (run?.results || []).filter((r) => r.passed).length;
      const show = passed >= 1;
      el('btn-submit')?.classList.toggle('d-none', !show);
      el('btn-submit-practice')?.classList.toggle('d-none', !show);
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
      if (s === 'Passed' || s === 'Accepted') return { cls: 'success', text: '✓ Test Case Passed' };
      if (s === 'Execution Successful') return { cls: 'danger', text: '✕ Wrong Answer' };
      if (s === 'Custom Input Error') return { cls: 'danger', text: '✕ Custom Input Error' };
      if (s === 'Judge Error') return { cls: 'warning', text: '⚠ Judge Error' };
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
      if (s === 'Passed' || s === 'Accepted') return { cls: 'success', text: '✓ Passed' };
      if (s === 'Custom Input Error') return { cls: 'danger', text: '✕ Custom Input Error' };
      if (s === 'Execution Successful' || s === 'Wrong Answer' || s === 'Failed') return { cls: 'danger', text: '✕ Failed' };
      if (s === 'Syntax Error' || s === 'Runtime Error' || s === 'Compilation Error') {
        return { cls: 'danger', text: '✕ ' + s };
      }
      if (s === 'Time Limit Exceeded') return { cls: 'warning', text: '⏱ TLE' };
      return { cls: 'muted', text: 'Not Run' };
    }

    function formatRunError(custom) {
      if (typeof CodingErrorFormat !== 'undefined' && CodingErrorFormat.errorBlockHtml) {
        return CodingErrorFormat.errorBlockHtml(custom, esc);
      }
      const status = String(custom?.status || '');
      const stderr = String(custom?.stderr || '').trim();
      const isError = ['Syntax Error', 'Runtime Error', 'Compilation Error', 'Time Limit Exceeded', 'Custom Input Error', 'Judge Error'].includes(status);
      if (!isError && !stderr) return '';
      const parts = [];
      if (isError) parts.push(status);
      if (stderr && stderr !== status) parts.push(stderr);
      return parts.join('\n');
    }

    function reconcileRunCustom(custom) {
      if (typeof CodingErrorFormat !== 'undefined' && CodingErrorFormat.reconcilePracticeRunRow) {
        return CodingErrorFormat.reconcilePracticeRunRow(custom || {});
      }
      return custom || {};
    }

    function resolveOutputCustom(run) {
      return reconcileRunCustom(run?.custom || {});
    }

    function sampleExpectedOutput(q) {
      const sample = (q?.testCases || []).find((t) => t.sample);
      const fromSample = sample?.expected ?? sample?.output ?? sample?.expectedOutput;
      if (fromSample != null && String(fromSample) !== '') return String(fromSample);
      const example = (q?.examples || [])[0];
      return example ? String(example.output ?? '') : '';
    }

    function visibleExpectedOutput(runExpected, q, runStatus) {
      if (runStatus === 'Custom Input Error' || runStatus === 'Judge Error') return String(runExpected ?? '');
      const fromRun = String(runExpected ?? '');
      if (fromRun !== '') return fromRun;
      if (runStatus) return '';
      return sampleExpectedOutput(q);
    }

    function renderRunPanel(run, runningNow) {
      const out = el('output');
      const expected = el('expected');
      const stderr = el('stderr');
      const status = el('run-status');
      const q = currentQ();

      if (runningNow) {
        if (status) status.innerHTML = '<span class="badge-soft info">Running code...</span>';
        if (out) out.textContent = '';
        if (expected) expected.textContent = sampleExpectedOutput(q);
        if (stderr) {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
        renderCaseTable(null, q);
        return;
      }

      if (!run) {
        if (out) out.textContent = '';
        if (expected) expected.textContent = sampleExpectedOutput(q);
        if (stderr) {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
        if (status) status.innerHTML = '<span class="small text-muted-2">Run code to see output.</span>';
        renderCaseTable(null, q);
        return;
      }

      const custom = resolveOutputCustom(run);
      const runStatus = (typeof CodingErrorFormat !== 'undefined' && CodingErrorFormat.resolveRunStatus)
        ? CodingErrorFormat.resolveRunStatus(custom)
        : (custom.status || run.overall);
      const badge = statusBadge(runStatus);
      if (status) status.innerHTML = `<span class="badge-soft ${badge.cls}">${esc(badge.text)}</span>`;
      if (out) {
        const outText = String(custom.output ?? '');
        out.textContent = outText !== '' ? outText : ((runStatus === 'Wrong Answer' || runStatus === 'Execution Successful') ? 'No output' : '');
      }
      if (expected) expected.textContent = visibleExpectedOutput(custom.expected, currentQ(), runStatus);
      const detail = formatRunError(custom);
      if (stderr) {
        if (runStatus === 'Custom Input Error' || runStatus === 'Judge Error') {
          if (detail) {
            if (detail.includes('<')) stderr.innerHTML = detail;
            else stderr.textContent = detail;
            stderr.classList.remove('d-none');
          } else {
            stderr.textContent = String(custom.errorSummary || custom.stderr || runStatus);
            stderr.classList.remove('d-none');
          }
        } else if ((runStatus === 'Wrong Answer' || runStatus === 'Execution Successful') && String(custom.output ?? '').trim() === '') {
          stderr.innerHTML = '<div class="small text-muted-2">The program ran, but the output did not match the expected output.</div>';
          stderr.classList.remove('d-none');
        } else if (detail) {
          if (detail.includes('<')) stderr.innerHTML = detail;
          else stderr.textContent = detail;
          stderr.classList.remove('d-none');
        } else {
          stderr.textContent = '';
          stderr.classList.add('d-none');
        }
      }
      renderCaseTable(run, currentQ());
      updateSubmitVisibility(run);
    }

    function renderCaseTable(run, q) {
      const table = el('case-table') || el('cases');
      const summary = el('case-summary');
      if (!table) return;
      const rows = run?.results?.length
        ? run.results
        : (() => {
            const all = q?.testCases || [];
            if (!all.length) return [];
            const tc = all[0];
            return [{
              index: 1,
              label: tc.label || (tc.sample ? 'Sample Test Case' : 'Test Case 1'),
              status: 'Not Run',
              passed: false,
            }];
          })();
      table.innerHTML = `
        <div class="table-wrap">
          <table class="table-modern mb-0">
            <thead><tr><th>Test Case</th><th>Status</th></tr></thead>
            <tbody>
              ${rows.map((tc, i) => {
                const badge = caseBadge(tc.status);
                const showErr = tc.status && !['Passed', 'Not Run', 'Accepted'].includes(String(tc.status));
                const errHtml = showErr && typeof CodingErrorFormat !== 'undefined'
                  ? CodingErrorFormat.errorBlockHtml(tc, esc)
                  : '';
                return `<tr>
                  <td>
                    ${esc(tc.label || `Test Case ${tc.index || i + 1}`)}
                    ${errHtml || ''}
                  </td>
                  <td><span class="badge-soft ${badge.cls}">${esc(badge.text)}</span></td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>`;
      const pass = rows.filter((r) => r.passed).length;
      const total = run?.totalCount ?? (q?.testCases || []).length ?? rows.length;
      if (summary) summary.textContent = `${pass} / ${total} Test Cases Passed`;
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

    function defaultCustomInput(q) {
      if (typeof CodingData !== 'undefined' && CodingData.resolveSampleInput) {
        return CodingData.resolveSampleInput(q) || '';
      }
      const sample = (q?.testCases || []).find((t) => t.sample);
      return sample?.input ? String(sample.input) : '';
    }

    function renderQuestion() {
      const q = currentQ();
      if (!q) return;
      const defaultIn = defaultCustomInput(q);
      const ans = normalizeAnswer(q, state.answers[q.id] || createAnswerState(q, { customInput: defaultIn }));
      state.answers[q.id] = ans;
      const language = ans.language || 'Python';
      const code = getLanguageCode(ans, q, language);
      ans.language = language;
      ans.code = code;
      ans.lastRun = ans.lastRuns?.[language] ?? ans.lastRun ?? null;
      el('language').value = language;
      editor.setLanguage(language);
      editor.setValue(code);
      if (el('stdin')) {
        const stored = ans.customInput;
        el('stdin').value = stored != null && String(stored).trim() !== '' ? stored : defaultIn;
        el('stdin').placeholder = defaultIn
          ? 'Leave empty to run with the sample input above'
          : 'Optional — leave empty to use sample input when available';
      }
      renderProblem(q);
      renderRunPanel(ans.lastRun, false);
      updateSubmitVisibility(ans.lastRun);
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
        focusViolationCount = 0;
        violationAckRequired = false;
        lastServerWarningMessage = '';
        timerPauseStartedAt = 0;
        finalizingViolation = false;
        allowFullscreenExit = false;
        if (started.malpractice) {
          applyMalpracticeServerPayload({ state: started.malpractice });
        }
        examLockdown = true;
        clipboardGuardsEnabled = true;
        focusGuardsEnabled = true;
        remainingMs = Math.max(0, (started.endsAt || 0) - Date.now());
        const serverAnswers = started.answers && typeof started.answers === 'object' ? started.answers : {};
        (started.test.items || []).forEach((item) => {
          const sample = (item.testCases || []).find((tc) => tc.sample);
          state.answers[item.id] = createAnswerState(item, {
            ...(serverAnswers[item.id] || {}),
            language: serverAnswers[item.id]?.language || 'Python',
            customInput: serverAnswers[item.id]?.customInput ?? (sample ? sample.input : ''),
          });
        });
        state.index = 0;
        if (!editor) editor = createCodeEditor(el('editor'));
        bindEditorInput();
        showPanel('exam');
        el('exam-title').textContent = started.test.title || 'Coding Test';
        root.setAttribute('data-cod-locked', '1');
        bindUnload(true);
        bindLockdownGuards(true, { clipboard: true, focus: true });
        renderQuestion();
        restoreExamInteractions();
        syncTimedExamChrome();
        startTimer(remainingMs);
        editor.focus();
        tryEnterExamFullscreen();
      } catch (err) {
        toast(err?.message || 'Could not start test.', 'error');
      }
    }

    async function runCurrent() {
      if (running || !state?.attemptId || state.submitted || state.status !== 'ACTIVE') return;
      persistCurrent();
      const q = currentQ();
      const ans = state.answers[q.id];
      const language = el('language')?.value || ans?.language || 'Python';
      const source = editor ? editor.getValue() : String(ans?.code || '');
      let stdin = el('stdin') ? el('stdin').value : String(ans?.customInput ?? '');
      if (isPracticeMode() && typeof CodingData !== 'undefined' && CodingData.effectiveRunStdin) {
        const effective = CodingData.effectiveRunStdin(q, stdin);
        if (effective !== stdin) {
          stdin = effective;
          if (el('stdin')) el('stdin').value = effective;
        }
      }
      if (ans) {
        const normalized = normalizeAnswer(q, ans);
        state.answers[q.id] = normalized;
        setLanguageCode(normalized, language, source);
        normalized.language = language;
        normalized.code = source;
        normalized.customInput = stdin;
      }
      running = true;
      const seq = ++runSeq;
      setBusy(true);
      el('run-state').textContent = 'Running code...';
      renderRunPanel(null, true);
      try {
        const result = isPracticeMode()
          ? await CodingService.runPracticeCode({
            attemptId: state.attemptId,
            language,
            code: source,
            stdin,
          })
          : await CodingService.runCode({
            attemptId: state.attemptId,
            questionId: q.id,
            language,
            code: source,
            stdin,
          });
        if (seq !== runSeq) return;
        const normalized = normalizeAnswer(q, state.answers[q.id] || createAnswerState(q));
        normalized.lastRuns[language] = result;
        normalized.lastRun = result;
        state.answers[q.id] = normalized;
        el('run-state').textContent = '';
        renderRunPanel(result, false);
        renderNav();
      } catch (err) {
        if (seq !== runSeq) return;
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
        if (seq === runSeq) {
          running = false;
          setBusy(false);
        }
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
        state.answers = started.problem.id ? {
          [started.problem.id]: createAnswerState(started.problem, {
            language: 'Python',
            customInput: (started.problem.testCases || []).find((t) => t.sample)?.input || '',
          }),
        } : {};
        if (!editor) editor = createCodeEditor(el('editor'));
        bindEditorInput();
        clipboardGuardsEnabled = true;
        root.setAttribute('data-cod-locked', '1');
        bindLockdownGuards(true, { clipboard: true, focus: false });
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
          bindLockdownGuards(true, { clipboard: true, focus: true });
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
            <div class="small fw-semibold mb-1">${winnersReady ? '🏆 Challenge result saved' : '⚔️ Your challenge score is in'}</div>
            <div class="small text-muted-2">${winnersReady
              ? 'Winners are now visible in Challenge arena on the coding page.'
              : 'Your score is saved now. The winner is published after the challenge closes.'}</div>
          </div>`
        : '';
      el('result-hero').innerHTML = `
        <div class="text-center py-2">
          <div class="text-muted-2 mb-1">${isContest ? 'Challenge Result' : 'Coding Test Result'}</div>
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

    el('stdin')?.addEventListener('input', () => {
      const q = currentQ();
      if (!q || running) return;
      const ans = state.answers[q.id];
      if (!ans?.lastRun) return;
      const lang = ans.language || el('language')?.value || 'Python';
      ans.lastRun = null;
      if (ans.lastRuns) ans.lastRuns[lang] = null;
      renderRunPanel(null, false);
      updateSubmitVisibility(null);
    });

    el('language')?.addEventListener('change', () => {
      const q = currentQ();
      if (!q || !editor) return;
      const ans = normalizeAnswer(q, state.answers[q.id] || createAnswerState(q));
      state.answers[q.id] = ans;
      const fromLang = ans.language || el('language').value;
      const toLang = el('language').value;
      const fromCode = editor.getValue();
      setLanguageCode(ans, fromLang, fromCode);
      ans.language = toLang;
      const nextCode = getLanguageCode(ans, q, toLang);
      ans.code = nextCode;
      ans.lastRun = ans.lastRuns?.[toLang] ?? null;
      editor.setLanguage(toLang);
      editor.setValue(nextCode);
      renderRunPanel(ans.lastRun, false);
      updateSubmitVisibility(ans.lastRun);
      if (state.attemptId) {
        const draft = draftFieldsForAnswer(ans, q, toLang, nextCode, ans.customInput ?? '');
        if (isPracticeMode()) {
          CodingService.savePracticeDraft(state.attemptId, draft);
        } else {
          CodingService.saveDraft(state.attemptId, q.id, draft);
        }
      }
    });

    root.addEventListener('click', (e) => {
      const t = e.target.closest('[data-cod-action]');
      if (!t) return;
      const action = t.getAttribute('data-cod-action');
      if (action === 'start') beginExam();
      if (action === 'start-practice') beginPractice();
      if (action === 'submit-practice') submitPracticeSolution();
      if (action === 'cancel' || action === 'back') {
        if (focusLockActive() && !isPracticeMode() && state.status === 'ACTIVE') {
          recordBlockedMalpracticeAttempt('nav');
          return;
        }
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
      isFocusLocked() {
        return focusLockActive();
      },
      registerFocusViolation() {
        registerFocusViolation();
      },
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
