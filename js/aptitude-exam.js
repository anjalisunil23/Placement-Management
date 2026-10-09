/* PlaceHub — aptitude exam experience (instructions → timed MCQ → results) */
(function (global) {
  const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function sanitizeRichHtml(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = String(html || '');
    tpl.content.querySelectorAll('script,style,iframe,object,embed,form').forEach((node) => node.remove());
    tpl.content.querySelectorAll('*').forEach((node) => {
      const tag = node.tagName;
      const allowedImgAttrs = new Set(['src', 'alt', 'width', 'height', 'class']);
      [...node.attributes].forEach((attr) => {
        const name = attr.name.toLowerCase();
        if (name.startsWith('on') || name === 'srcdoc') {
          node.removeAttribute(attr.name);
          return;
        }
        if (tag === 'IMG') {
          if (!allowedImgAttrs.has(name)) node.removeAttribute(attr.name);
          else if (name === 'src' && !isSafeRichImageSrc(attr.value)) node.removeAttribute(attr.name);
        }
      });
    });
    tpl.content.querySelectorAll('img:not([src])').forEach((node) => node.remove());
    return tpl.innerHTML;
  }

  function isSafeRichImageSrc(src) {
    const s = String(src || '').trim();
    if (!s) return false;
    if (/^data:image\/(png|jpe?g|webp|gif);base64,/i.test(s)) return true;
    if (s.startsWith('/backend/api/media/') || s.startsWith('/api/media/')) return true;
    try {
      const u = new URL(s, location.origin);
      return u.origin === location.origin
        && (u.pathname.includes('/api/media/') || u.pathname.includes('/backend/api/media/'));
    } catch {
      return false;
    }
  }

  function renderRichHtml(html, { preWrap = false } = {}) {
    const style = preWrap ? ' style="white-space:pre-wrap"' : '';
    return `<div class="apt-rich"${style}>${sanitizeRichHtml(html)}</div>`;
  }

  function splitSyllogismStatementLines(text) {
    let t = String(text || '').replace(/\s+/g, ' ').trim();
    if (!t) return '';
    return t.replace(/\s+(?=(?:All|Some|No|Only|Every|None|Most|A few)\b)/gi, '\n').trim();
  }

  function formatSyllogismConclusionLines(text) {
    let t = String(text || '').replace(/\s+/g, ' ').trim();
    if (!t) return '';
    const m = t.match(/^I\)\s*(.+?)\s*II\)\s*(.+)$/iu);
    if (m) return `I) ${m[1].trim()}\nII) ${m[2].trim()}`;
    t = t.replace(/\s*(II\))\s*/gi, '\n$1 ');
    t = t.replace(/(?<!I)(I\))\s*/g, '\n$1 ');
    return t.trim();
  }

  function formatStatementsConclusionsPrompt(prompt) {
    let text = String(prompt || '').trim();
    if (!text) return '';
    text = text.replace(/^\s*\d{1,3}\)\s*/, '');
    text = text.replace(/[^\S\n]+/g, ' ').trim();
    text = text.replace(/\s*\n\s*/g, '\n');
    const flat = text.replace(/\s+/g, ' ').trim();
    const scMatch = flat.match(/^Statements\s+(.+?)\s+Conclusions\s+(.+)$/iu);
    if (scMatch) {
      return [
        'Statements',
        splitSyllogismStatementLines(scMatch[1]),
        'Conclusions',
        formatSyllogismConclusionLines(scMatch[2]),
      ].join('\n');
    }
    text = text.replace(/\bStatements\b\s*/gi, 'Statements\n');
    text = text.replace(/\s*\bConclusions\b\s*/gi, '\nConclusions\n');
    text = text.replace(/\s+(II\))\s+/g, '\n$1 ');
    text = text.replace(/(?<!I)(I\))\s+/g, '\n$1 ');
    return text.replace(/\n{3,}/g, '\n\n').trim();
  }

  function isStatementsConclusionsQuestion(q, prompt) {
    if (q?.questionType === 'STATEMENTS_CONCLUSIONS') return true;
    const p = String(prompt || '').trim();
    return /\bStatements\b/i.test(p) && /\bConclusions\b/i.test(p);
  }

  function resolveExamPromptText(q) {
    let raw = String(q?.prompt ?? q?.question ?? '').trim();
    if (!raw) return '';
    if (isStatementsConclusionsQuestion(q, raw)) {
      raw = formatStatementsConclusionsPrompt(raw);
    }
    return raw;
  }

  function renderExamPrompt(q) {
    const raw = resolveExamPromptText(q);
    const isSc = isStatementsConclusionsQuestion(q, raw);
    const isDs = q?.questionType === 'DATA_SUFFICIENCY';
    const preWrap = isSc || isDs || /\n/.test(raw);
    const style = preWrap ? ' style="white-space:pre-wrap"' : '';
    if (/<[^>]+>/.test(raw)) {
      return `<div class="apt-rich"${style}>${sanitizeRichHtml(raw)}</div>`;
    }
    return `<div class="apt-rich"${style}>${esc(raw)}</div>`;
  }

  function testCategoryLabel(test) {
    const direct = String(test?.category || '').trim();
    if (direct) return direct;
    const fromQuestion = (test?.questions || []).map((q) => String(q?.category || '').trim()).find(Boolean);
    return fromQuestion || 'General Aptitude';
  }

  function formatTimer(sec) {
    sec = Math.max(0, Math.floor(sec));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }

  const ANSWER_FIELD_KEYS = [
    'correctIndex', 'correct_answer', 'correctAnswer', 'correctAnswerIndex',
    'correct', 'correctOption', 'correctOptionLetter', 'explanation', 'solution',
    'lockCorrectIndex', 'isCorrect', 'answerIndex',
  ];

  function stripExamQuestion(q) {
    if (!q || typeof q !== 'object') return q;
    const safe = { ...q };
    ANSWER_FIELD_KEYS.forEach((key) => { delete safe[key]; });
    return safe;
  }

  function stripExamQuestions(list) {
    return (list || []).map(stripExamQuestion);
  }

  function normalizeExamQuestions(list) {
    return stripExamQuestions(list).map((q, i) => ({
      ...q,
      id: String(q.id || q.bankId || `q${i + 1}`),
    }));
  }

  function questionKey(q) {
    return String(q?.id || '');
  }

  function paletteState(idx, answers, marked, visited) {
    const qid = answers._order?.[idx];
    const answered = qid != null && answers[qid] != null && answers[qid] >= 0;
    const rev = !!(marked && marked[qid]);
    if (answered && rev) return 'answered-review';
    if (rev) return 'review';
    if (answered) return 'answered';
    if (visited && visited[qid]) return 'not-answered';
    return 'not-visited';
  }

  function computePaletteCounts(questions, answers, marked, visited) {
    const counts = {
      'not-visited': 0,
      'not-answered': 0,
      answered: 0,
      review: 0,
      'answered-review': 0,
    };
    if (!questions?.length) return counts;
    const order = questions.map((x) => questionKey(x));
    const answersWithOrder = { ...answers, _order: order };
    questions.forEach((q, i) => {
      const st = paletteState(i, answersWithOrder, marked, visited);
      counts[st] = (counts[st] || 0) + 1;
    });
    return counts;
  }

  function buildSubmitSummaryHtml(counts) {
    const rows = [
      { key: 'not-visited', label: 'Not visited', swatch: 'background:#fff;border:1px solid #cbd5e1' },
      { key: 'not-answered', label: 'Not answered', swatch: 'background:#dbeafe;border:1px solid #93c5fd' },
      { key: 'answered', label: 'Answered', swatch: 'background:#22c55e;border:1px solid #16a34a' },
      { key: 'review', label: 'Review', swatch: 'background:#ef4444;border:1px solid #dc2626' },
      { key: 'answered-review', label: 'Answered + review', swatch: 'background:#22c55e;border:1px solid #dc2626;box-shadow:inset 0 0 0 1px #ef4444' },
    ];
    const items = rows.map((r) => `
      <div class="d-flex justify-content-between align-items-center small py-1 gap-2">
        <span class="d-inline-flex align-items-center gap-2 text-muted">
          <i style="width:.75rem;height:.75rem;border-radius:.2rem;display:inline-block;${r.swatch}"></i>
          ${esc(r.label)}
        </span>
        <strong class="text-nowrap">${counts[r.key] || 0}</strong>
      </div>`).join('');
    return `<p class="mb-2">Submit your answers now? You cannot change them after submission.</p>
      <div class="border rounded-3 p-2 bg-light">${items}</div>`;
  }

  function createExamController(opts) {
    const root = opts.root;
    const onExit = opts.onExit || (() => {});
    let state = null;
    let timerId = null;
    let submitting = false;
    let examLockdown = false;
    let remainingMs = 0;
    let timerDeadline = 0;
    let lockOverlay = null;
    let lockdownGuardsBound = false;
    let beforeUnloadBound = false;
    let focusViolationCount = 0;
    let violationAckRequired = false;
    let finalizingViolation = false;
    let lastFocusViolationAt = 0;
    let keydownGuardBound = false;
    let focusEnforceTimer = null;
    let hiddenViolationPending = false;
    let fullscreenListenerBound = false;
    let allowFullscreenExit = false;
    let fullscreenEnforceTimer = null;
    let windowBlurGuardBound = false;
    const MAX_MALPRACTICE_WARNINGS = 2;
    const INCIDENT_DEBOUNCE_MS = 800;
    const TAB_SWITCH_PROHIBITED_MSG = 'You left the test tab. Tab switching is not allowed. Return here immediately to see your malpractice warning.';
    const ACK_REQUIRED_MSG = 'You must return to this tab and tap "I understand — continue test" before the test can continue. Tab switching and minimizing are not allowed.';
    let malpracticeDeadlineAt = 0;
    let malpracticeFinalizeInFlight = false;
    let warningCountdownTimer = null;
    let malpracticeStatePollTimer = null;
    let timerPauseStartedAt = 0;
    let lastServerWarningMessage = '';
    let lastServerWarningTitle = '';
    let incidentReporting = false;
    let lockOverlayActionsBound = false;
    let lockOverlayDismissHandler = null;

    function el(id) {
      return root.querySelector(`[data-exam="${id}"]`);
    }

    function isContestAttempt(meta = state?.test) {
      const type = String(meta?.contestType || 'none');
      return type === 'weekly' || type === 'monthly';
    }

    function serverMalpracticeEnabled() {
      return isContestAttempt()
        && typeof Auth !== 'undefined'
        && Auth.hasRealAuth()
        && !Auth.isDemo()
        && !!state?.attemptId
        && !String(state.attemptId).startsWith('demo-');
    }

    function ensureLockOverlay() {
      if (lockOverlay) return lockOverlay;
      const overlay = document.createElement('div');
      overlay.setAttribute('data-exam-lock-overlay', '');
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
        <div data-exam-lock-panel style="max-width:34rem;width:min(34rem,100%);border-radius:1rem;padding:1rem 1.1rem;background:#fff;box-shadow:0 20px 60px rgba(15,23,42,.22);border:1px solid rgba(148,163,184,.35);pointer-events:auto;position:relative;z-index:1">
          <div data-exam-lock-title style="font-size:1rem;font-weight:700;margin-bottom:.35rem">Test Ended</div>
          <div data-exam-lock-message style="font-size:.95rem;line-height:1.45;color:#334155">You left the test window. Submitting your answers and signing you out…</div>
          <div data-exam-lock-countdown class="small fw-semibold mt-2 d-none" style="color:#b45309"></div>
          <button type="button" class="btn btn-primary btn-sm mt-3 d-none" data-exam-lock-dismiss style="pointer-events:auto;cursor:pointer">I understand — continue test</button>
        </div>`;
      document.body.appendChild(overlay);
      lockOverlay = overlay;
      bindLockOverlayActionsOnce();
      return lockOverlay;
    }

    function bindLockOverlayActionsOnce() {
      const overlay = ensureLockOverlay();
      if (lockOverlayActionsBound) return;
      lockOverlayActionsBound = true;
      overlay.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-exam-lock-dismiss]');
        if (!btn || btn.classList.contains('d-none') || btn.disabled) return;
        e.preventDefault();
        e.stopPropagation();
        btn.disabled = true;
        const handler = lockOverlayDismissHandler;
        if (typeof handler === 'function') handler();
      }, true);
      overlay.addEventListener('mousedown', (e) => {
        if (e.target.closest('[data-exam-lock-panel]')) e.stopPropagation();
      }, true);
    }

    function malpracticeAckOverlayVisible() {
      if (!lockOverlay || lockOverlay.style.display !== 'flex') return false;
      const dismiss = lockOverlay.querySelector('[data-exam-lock-dismiss]');
      return !!(dismiss && !dismiss.classList.contains('d-none') && violationAckRequired);
    }

    function showLockOverlay(message, opts = {}) {
      mountLockOverlayForExam();
      const overlay = ensureLockOverlay();
      const title = overlay.querySelector('[data-exam-lock-title]');
      const msg = overlay.querySelector('[data-exam-lock-message]');
      const dismiss = overlay.querySelector('[data-exam-lock-dismiss]');
      const countdownEl = overlay.querySelector('[data-exam-lock-countdown]');
      if (title) title.textContent = opts.title || 'Test Ended';
      if (msg) msg.textContent = message || 'You have left the test window. Return to this tab to continue.';
      if (countdownEl) {
        if (opts.showCountdown) {
          countdownEl.classList.remove('d-none');
          syncWarningCountdownUi();
        } else {
          countdownEl.classList.add('d-none');
          countdownEl.textContent = '';
        }
      }
      if (dismiss) {
        if (opts.dismissible) {
          dismiss.classList.remove('d-none');
          dismiss.disabled = false;
          dismiss.textContent = opts.dismissText || 'I understand — continue test';
          lockOverlayDismissHandler = () => { opts.onDismiss?.(); };
        } else {
          dismiss.classList.add('d-none');
          dismiss.disabled = false;
          lockOverlayDismissHandler = null;
        }
      } else {
        lockOverlayDismissHandler = null;
      }
      overlay.style.display = 'flex';
    }

    function hideLockOverlay(force = false) {
      if (violationAckRequired && !force) return;
      if (lockOverlay) lockOverlay.style.display = 'none';
    }

    function mountLockOverlayForExam() {
      const overlay = ensureLockOverlay();
      const parent = serverMalpracticeEnabled() ? document.body : (getFullscreenElement() || document.documentElement);
      if (overlay.parentElement !== parent) {
        parent.appendChild(overlay);
      }
    }

    function applyMalpracticeServerPayload(data) {
      if (!data || typeof data !== 'object') return;
      const st = data.state || data;
      if (st.violationCount != null) focusViolationCount = Number(st.violationCount) || 0;
      if (data.violationCount != null) focusViolationCount = Number(data.violationCount) || focusViolationCount;
      violationAckRequired = !!(st.ackRequired ?? data.ackRequired);
      if (data.warningMessage) lastServerWarningMessage = String(data.warningMessage);
      if (data.warningTitle) lastServerWarningTitle = String(data.warningTitle);
      const deadline = st.warningDeadlineAt ?? data.warningDeadlineAt;
      if (deadline != null && Number(deadline) > 0) {
        malpracticeDeadlineAt = Number(deadline);
      } else if (!violationAckRequired) {
        malpracticeDeadlineAt = 0;
      }
    }

    function stopWarningCountdown() {
      if (warningCountdownTimer) {
        clearInterval(warningCountdownTimer);
        warningCountdownTimer = null;
      }
    }

    function stopMalpracticeStatePoll() {
      if (malpracticeStatePollTimer) {
        clearInterval(malpracticeStatePollTimer);
        malpracticeStatePollTimer = null;
      }
    }

    function syncWarningCountdownUi() {
      const countdownEl = lockOverlay?.querySelector('[data-exam-lock-countdown]');
      if (!countdownEl || !malpracticeDeadlineAt || !violationAckRequired) return;
      const sec = Math.max(0, Math.ceil((malpracticeDeadlineAt - Date.now()) / 1000));
      countdownEl.textContent = `Time remaining: ${sec} second${sec === 1 ? '' : 's'}`;
      if (sec <= 0 && !malpracticeFinalizeInFlight && !finalizingViolation) {
        finalizeMalpracticeFromClient('timeout');
      }
    }

    function startWarningCountdown() {
      stopWarningCountdown();
      syncWarningCountdownUi();
      warningCountdownTimer = window.setInterval(syncWarningCountdownUi, 200);
    }

    function startMalpracticeStatePoll() {
      stopMalpracticeStatePoll();
      if (!violationAckRequired || !state?.attemptId) return;
      malpracticeStatePollTimer = window.setInterval(() => {
        syncMalpracticeStateFromServer().catch(() => {});
      }, 1500);
    }

    async function fetchMalpracticeState(attemptId) {
      const res = await api(`/aptitude/attempts/${encodeURIComponent(attemptId)}/malpractice/state`).catch(() => null);
      if (!res?.success) throw new Error(res?.message || 'Could not load malpractice state.');
      return res.data;
    }

    async function reportMalpracticeIncident(attemptId, body) {
      const res = await api(`/aptitude/attempts/${encodeURIComponent(attemptId)}/malpractice/incident`, {
        method: 'POST',
        body: JSON.stringify(body || {}),
      }).catch(() => null);
      if (!res?.success) throw new Error(res?.message || 'Could not record malpractice incident.');
      return res.data;
    }

    async function acknowledgeMalpracticeApi(attemptId, body) {
      const res = await api(`/aptitude/attempts/${encodeURIComponent(attemptId)}/malpractice/ack`, {
        method: 'POST',
        body: JSON.stringify(body || {}),
      }).catch(() => null);
      if (!res?.success) throw new Error(res?.message || 'Could not acknowledge warning.');
      return res.data;
    }

    function pauseTimerForWarning() {
      if (!timerPauseStartedAt) timerPauseStartedAt = Date.now();
      stopTimer();
    }

    function resumeTimerAfterAck(endsAtFromServer) {
      if (endsAtFromServer != null && Number.isFinite(Number(endsAtFromServer))) {
        state.endsAt = Number(endsAtFromServer);
        timerDeadline = state.endsAt;
      } else if (timerPauseStartedAt) {
        const paused = Date.now() - timerPauseStartedAt;
        timerDeadline += paused;
        state.endsAt = timerDeadline;
      }
      timerPauseStartedAt = 0;
      if (state?.started && !state?.submitted) startTimer(Math.max(0, timerDeadline - Date.now()));
    }

    async function handleMalpracticeTermination(data) {
      if (finalizingViolation) return;
      finalizingViolation = true;
      malpracticeFinalizeInFlight = true;
      stopWarningCountdown();
      stopMalpracticeStatePoll();
      stopTimer();
      bindLockdownGuards(false);
      bindUnload(false);
      examLockdown = false;
      violationAckRequired = false;
      freezeExamInteractions();
      const term = String(data?.terminationReason || data?.state?.terminationReason || '');
      const msg = term.startsWith('MALPRACTICE_WARNING')
        ? 'Your test has been automatically submitted because the malpractice warning was not resolved in time.'
        : 'Your test has been automatically submitted because you exceeded the maximum number of permitted violations.';
      showLockOverlay(msg, { title: 'Test ended', dismissible: false });
      try {
        if (data?.submitResult) {
          state.lastResult = data.submitResult;
          state.submitted = true;
          state.attemptId = null;
        } else if (state?.attemptId) {
          await submitExam(true, { logoutAfter: true });
        }
      } catch (_) { /* still sign out */ }
      logoutAfterViolation();
    }

    async function acknowledgeMalpracticeWarning() {
      if (!state?.attemptId || finalizingViolation || malpracticeFinalizeInFlight) return;
      if (malpracticeDeadlineAt > 0 && Date.now() >= malpracticeDeadlineAt) {
        return finalizeMalpracticeFromClient('expired');
      }
      malpracticeFinalizeInFlight = true;
      const dismiss = lockOverlay?.querySelector('[data-exam-lock-dismiss]');
      if (dismiss) dismiss.disabled = true;
      const pausedMs = timerPauseStartedAt ? Date.now() - timerPauseStartedAt : 0;
      try {
        const data = await acknowledgeMalpracticeApi(state.attemptId, { reason: 'ack', pausedMs });
        applyMalpracticeServerPayload(data);
        if (data?.terminated || data?.shouldAutoSubmit || data?.submitResult) {
          await handleMalpracticeTermination(data);
          return;
        }
        stopWarningCountdown();
        stopMalpracticeStatePoll();
        violationAckRequired = false;
        malpracticeDeadlineAt = 0;
        malpracticeFinalizeInFlight = false;
        hideLockOverlay(true);
        resumeTimerAfterAck(data?.endsAt ?? data?.state?.endsAt);
        if (focusLockActive() && state?.started && !state?.submitted) {
          restoreExamInteractions();
        }
        await tryEnterExamFullscreen();
      } catch (err) {
        malpracticeFinalizeInFlight = false;
        if (dismiss) dismiss.disabled = false;
        toast(err?.message || 'Could not acknowledge warning.', 'error');
        if (violationAckRequired) showMalpracticeAckOverlay();
      }
    }

    async function finalizeMalpracticeFromClient(reason) {
      if (!state?.attemptId || finalizingViolation || malpracticeFinalizeInFlight) return;
      if (reason === 'ack') return acknowledgeMalpracticeWarning();
      malpracticeFinalizeInFlight = true;
      stopWarningCountdown();
      stopMalpracticeStatePoll();
      freezeExamInteractions();
      pauseTimerForWarning();
      const dismiss = lockOverlay?.querySelector('[data-exam-lock-dismiss]');
      if (dismiss) dismiss.disabled = true;
      try {
        const data = await acknowledgeMalpracticeApi(state.attemptId, {
          reason,
          answers: buildSubmitAnswers(),
          markedForReview: Object.keys(state.marked).filter((k) => state.marked[k]),
          timeTakenSeconds: Math.max(0, Math.round((Date.now() - state.startedAt) / 1000)),
        });
        applyMalpracticeServerPayload(data);
        if (data?.terminated || data?.shouldAutoSubmit || data?.submitResult) {
          await handleMalpracticeTermination(data);
          return;
        }
        malpracticeFinalizeInFlight = false;
        if (dismiss) dismiss.disabled = false;
      } catch (err) {
        malpracticeFinalizeInFlight = false;
        toast(err?.message || 'Could not complete malpractice submission.', 'error');
        if (violationAckRequired) {
          if (dismiss) dismiss.disabled = false;
          showMalpracticeAckOverlay();
        }
      }
    }

    async function syncMalpracticeStateFromServer() {
      if (!serverMalpracticeEnabled() || !state?.attemptId || finalizingViolation) return null;
      const data = await fetchMalpracticeState(state.attemptId);
      if (data?.shouldAutoSubmit || data?.submitResult || data?.terminated) {
        applyMalpracticeServerPayload(data);
        await handleMalpracticeTermination(data);
        return data;
      }
      const st = data?.state || data;
      const clientPastDeadline = malpracticeDeadlineAt > 0 && Date.now() >= malpracticeDeadlineAt;
      if (clientPastDeadline || (st?.warningExpired && clientPastDeadline)) {
        return finalizeMalpracticeFromClient('expired');
      }
      applyMalpracticeServerPayload({ state: st, ...data });
      if (st?.ackRequired || data?.ackRequired) {
        if (!malpracticeAckOverlayVisible()) showMalpracticeAckOverlay();
        else syncWarningCountdownUi();
      }
      return data;
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
        const data = await reportMalpracticeIncident(state.attemptId, { source: String(source || '') });
        applyMalpracticeServerPayload(data);
        if (data?.terminated || data?.shouldAutoSubmit) {
          await handleMalpracticeTermination(data);
          return;
        }
        if (data?.ackRequired) {
          if (data?.warningTitle) lastServerWarningTitle = String(data.warningTitle);
          if (data?.incremented !== false) pauseTimerForWarning();
          if (document.hidden) {
            hiddenViolationPending = true;
          } else {
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

    function malpracticeWarningMessage() {
      if (lastServerWarningMessage) return lastServerWarningMessage;
      const n = Math.min(focusViolationCount, MAX_MALPRACTICE_WARNINGS);
      if (serverMalpracticeEnabled()) {
        if (n === 1) {
          return 'WARNING 1 OF 2: You have left the active contest window. Further violations may result in automatic submission.';
        }
        if (n === 2) {
          return 'FINAL WARNING: This is your second malpractice violation. One more violation will automatically submit your test and end your session.';
        }
      }
      const tail = n < MAX_MALPRACTICE_WARNINGS
        ? 'One more warning is allowed before your test is auto-submitted and you are signed out.'
        : 'The next blocked switch attempt will auto-submit your test and sign you out.';
      return `Malpractice warning ${n} of ${MAX_MALPRACTICE_WARNINGS}. Switching tabs is not allowed — this attempt was blocked. ${tail}`;
    }

    function shouldFinalizeAfterViolation() {
      return focusViolationCount > MAX_MALPRACTICE_WARNINGS;
    }

    function acknowledgeFocusViolation() {
      if (serverMalpracticeEnabled()) {
        acknowledgeMalpracticeWarning();
        return;
      }
      violationAckRequired = false;
      if (focusLockActive() && state?.started && !state?.submitted) {
        restoreExamInteractions();
      }
    }

    function showMalpracticeAckOverlay() {
      if (!violationAckRequired || finalizingViolation || malpracticeFinalizeInFlight) return;
      if (serverMalpracticeEnabled()) {
        if (malpracticeDeadlineAt > 0 && Date.now() >= malpracticeDeadlineAt) {
          finalizeMalpracticeFromClient('expired');
          return;
        }
        if (malpracticeAckOverlayVisible()) {
          syncWarningCountdownUi();
          return;
        }
        freezeExamInteractions();
        pauseTimerForWarning();
        mountLockOverlayForExam();
        const title = lastServerWarningTitle
          || (focusViolationCount >= 2 ? 'FINAL MALPRACTICE WARNING' : 'MALPRACTICE WARNING');
        showLockOverlay(malpracticeWarningMessage(), {
          title,
          dismissible: true,
          dismissText: 'I Understand — Continue Test',
          showCountdown: true,
          onDismiss: () => { acknowledgeMalpracticeWarning(); },
        });
        startWarningCountdown();
        startMalpracticeStatePoll();
        tryEnterExamFullscreen().catch(() => {});
        return;
      }
      freezeExamInteractions();
      tryEnterExamFullscreen().finally(() => {
        mountLockOverlayForExam();
        showLockOverlay(malpracticeWarningMessage(), {
          title: 'Malpractice warning',
          dismissible: true,
          onDismiss: acknowledgeFocusViolation,
        });
      });
    }

    function recordBlockedMalpracticeAttempt(source, opts = {}) {
      if (!focusLockActive() || finalizingViolation) return;
      if (serverMalpracticeEnabled()) {
        reportUnifiedMalpracticeIncident(source);
        return;
      }
      const now = Date.now();
      if (now - lastFocusViolationAt < INCIDENT_DEBOUNCE_MS) return;
      lastFocusViolationAt = now;
      focusViolationCount += 1;
      violationAckRequired = true;
      freezeExamInteractions();
      if (shouldFinalizeAfterViolation()) {
        finalizeAfterMaxViolations();
        return;
      }
      const n = Math.min(focusViolationCount, MAX_MALPRACTICE_WARNINGS);
      const blocked = source === 'keyboard' || source === 'fullscreen';
      const deferOverlay = opts.deferOverlay === true || document.hidden;
      if (!deferOverlay) {
        toast(
          blocked
            ? `Warning ${n} of ${MAX_MALPRACTICE_WARNINGS}: switching is blocked — stay on this test tab.`
            : `Warning ${n} of ${MAX_MALPRACTICE_WARNINGS}: tab switching is not allowed.`,
          'warning'
        );
        showMalpracticeAckOverlay();
      }
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
      if (e.key === 'Escape') {
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

    async function tryEnterExamFullscreen() {
      if (!focusLockActive() || allowFullscreenExit) return;
      const node = document.documentElement;
      try {
        if (node.requestFullscreen) await node.requestFullscreen();
        else if (node.webkitRequestFullscreen) await node.webkitRequestFullscreen();
      } catch (_) { /* ignore */ }
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
        if (allowFullscreenExit || !focusLockActive() || finalizingViolation) return;
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
      if (allowFullscreenExit || !focusLockActive() || finalizingViolation) return;
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

    function startFocusEnforce() {
      stopFocusEnforce();
      focusEnforceTimer = window.setInterval(() => {
        if (!focusLockActive() || finalizingViolation || document.hidden) return;
        try {
          if (!getFullscreenElement()) tryEnterExamFullscreen();
          if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
            window.focus();
          }
          if (violationAckRequired && !document.hidden && !malpracticeAckOverlayVisible()) {
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
      if (!state?.started || state?.submitted) return;
      e.preventDefault();
      e.returnValue = '';
    }

    function freezeExamInteractions() {
      root.querySelectorAll('[data-opt-select], [data-goto], [data-exam-action]').forEach((btn) => {
        btn.disabled = true;
      });
    }

    function restoreExamInteractions() {
      if (violationAckRequired) return;
      const locked = !!state?.submitted;
      root.querySelectorAll('[data-opt-select]').forEach((btn) => { btn.disabled = locked; });
      root.querySelectorAll('[data-goto]').forEach((btn) => { btn.disabled = locked; });
      root.querySelectorAll('[data-exam-action]').forEach((btn) => {
        const action = btn.getAttribute('data-exam-action');
        if (action === 'prev') btn.disabled = locked || state.index <= 0;
        else if (action === 'next') btn.disabled = locked || state.index >= (state.questions?.length || 1) - 1;
        else if (action === 'submit') btn.disabled = locked || submitting;
        else btn.disabled = locked;
      });
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

    function focusLockActive() {
      return examLockdown && state?.started && !state?.submitted && !submitting;
    }

    async function finalizeAfterMaxViolations() {
      if (finalizingViolation || !focusLockActive()) return;
      finalizingViolation = true;
      stopTimer();
      bindLockdownGuards(false);
      bindUnload(false);
      examLockdown = false;
      freezeExamInteractions();
      showLockOverlay('You exceeded the maximum number of malpractice warnings. Your test is being submitted and you will be signed out.');
      try {
        await submitExam(true, { logoutAfter: true });
      } catch (_) { /* still sign out below */ }
      logoutAfterViolation();
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
        if (!wasPending) {
          recordBlockedMalpracticeAttempt('tab', { deferOverlay: true });
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
      hideLockOverlay();
      if (state?.started && !state?.submitted) restoreExamInteractions();
    }

    function onExamWindowBlur() {
      if (!focusLockActive() || finalizingViolation || document.hidden) return;
      window.setTimeout(() => {
        if (!focusLockActive() || finalizingViolation || document.hidden) return;
        window.focus();
        tryEnterExamFullscreen();
        if (violationAckRequired) showMalpracticeAckOverlay();
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

    function onClipboardBlock(e) {
      if (!examLockdown || !state?.started || state.submitted) return;
      e.preventDefault();
      e.stopPropagation();
      if (e.type === 'paste') {
        toast('Pasting answers is not allowed during the test.', 'warning');
      }
    }

    function onContextMenuBlock(e) {
      if (!examLockdown || !state?.started || state.submitted) return;
      e.preventDefault();
    }

    function onSelectStartBlock(e) {
      if (!examLockdown || !state?.started || state.submitted) return;
      e.preventDefault();
    }

    function bindLockdownGuards(on) {
      if (on && !lockdownGuardsBound) {
        document.addEventListener('visibilitychange', onVisibilityChange);
        bindKeydownGuard(true);
        bindWindowBlurGuard(true);
        startFocusEnforce();
        bindFullscreenGuard(true);
        startFullscreenEnforce();
        root.addEventListener('copy', onClipboardBlock, true);
        root.addEventListener('cut', onClipboardBlock, true);
        root.addEventListener('paste', onClipboardBlock, true);
        root.addEventListener('contextmenu', onContextMenuBlock, true);
        root.addEventListener('selectstart', onSelectStartBlock, true);
        lockdownGuardsBound = true;
      }
      if (!on && lockdownGuardsBound) {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        bindKeydownGuard(false);
        bindWindowBlurGuard(false);
        stopFocusEnforce();
        bindFullscreenGuard(false);
        stopFullscreenEnforce();
        hiddenViolationPending = false;
        root.removeEventListener('copy', onClipboardBlock, true);
        root.removeEventListener('cut', onClipboardBlock, true);
        root.removeEventListener('paste', onClipboardBlock, true);
        root.removeEventListener('contextmenu', onContextMenuBlock, true);
        root.removeEventListener('selectstart', onSelectStartBlock, true);
        lockdownGuardsBound = false;
      }
      if (!on) {
        hideLockOverlay();
        violationAckRequired = false;
        allowFullscreenExit = true;
        exitExamFullscreen();
        allowFullscreenExit = false;
      }
    }

    function teardownLockdown() {
      examLockdown = false;
      focusViolationCount = 0;
      violationAckRequired = false;
      hiddenViolationPending = false;
      finalizingViolation = false;
      lastFocusViolationAt = 0;
      malpracticeDeadlineAt = 0;
      malpracticeFinalizeInFlight = false;
      timerPauseStartedAt = 0;
      lastServerWarningMessage = '';
      lastServerWarningTitle = '';
      incidentReporting = false;
      stopWarningCountdown();
      stopMalpracticeStatePoll();
      bindLockdownGuards(false);
      bindUnload(false);
      root.removeAttribute('data-exam-locked');
    }

    function showPanel(name) {
      root.querySelectorAll('[data-exam-panel]').forEach((p) => {
        p.classList.toggle('d-none', p.getAttribute('data-exam-panel') !== name);
      });
    }

    function stopTimer() {
      if (timerId) {
        clearInterval(timerId);
        timerId = null;
      }
    }

    function renderInstructions(test) {
      showPanel('instructions');
      el('instr-title').textContent = test.title || 'Aptitude test';
      el('instr-body').innerHTML = `
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Questions</div><strong>${esc(test.questionCount || (test.questions || []).length)}</strong></div></div>
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Duration</div><strong>${esc(test.durationMinutes || 30)} min</strong></div></div>
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Total marks</div><strong>${esc(test.totalMarks || 0)}</strong></div></div>
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Negative marking</div><strong>${test.negativeMarking ? `Yes (−${esc(test.negativeMarks || 0)})` : 'No'}</strong></div></div>
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Category</div><strong>${esc(testCategoryLabel(test))}</strong></div></div>
          <div class="col-6 col-md-4"><div class="card-surface p-3"><div class="small text-muted-2">Difficulty</div><strong>${esc(test.difficulty || '—')}</strong></div></div>
        </div>
        <div class="alert alert-warning py-2 px-3 small mb-3">
          <strong>During the test:</strong> copying questions and pasting answers are disabled.
          <strong>Fullscreen is required</strong> until you submit — do not press Esc or exit fullscreen.
          <strong>Tab switching is not allowed</strong> — stay on this test window. Each blocked attempt gives a malpractice warning (${MAX_MALPRACTICE_WARNINGS} warnings allowed); you must acknowledge within 5 seconds to continue, or your test is auto-submitted and you are signed out.
          ${isContestAttempt(test) ? ' Contest malpractice rules match the coding contest (server-tracked warnings).' : ''}
        </div>
        <h6 class="fw-bold">Instructions</h6>
        <div class="text-muted-2" style="white-space:pre-wrap">${esc(test.instructions || 'Read each question carefully. Choose one option. Submit before time ends.')}</div>`;
    }

    function currentQ() {
      return state.questions[state.index] || null;
    }

    function getSelectedIndex(q) {
      const qid = questionKey(q);
      if (!qid || !Object.prototype.hasOwnProperty.call(state.answers, qid)) return null;
      const picked = Number(state.answers[qid]);
      return Number.isFinite(picked) && picked >= 0 ? picked : null;
    }

    function selectOption(q, idx) {
      if (state?.submitted || idx < 0) return;
      const qid = questionKey(q);
      if (!qid) return;
      state.answers[qid] = idx;
      updateOptionHighlights(q);
      renderPalette();
    }

    function updateOptionHighlights(q) {
      const qid = questionKey(q);
      const selected = getSelectedIndex(q);
      el('q-options')?.querySelectorAll('[data-opt-select]').forEach((row) => {
        const idx = Number(row.getAttribute('data-opt-select'));
        const on = selected !== null && idx === selected;
        row.classList.toggle('is-selected', on);
        row.classList.toggle('border-primary', on);
        row.classList.toggle('bg-light', false);
        row.classList.toggle('shadow-sm', on);
        row.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function renderQuestion() {
      const q = currentQ();
      if (!q) return;
      const qid = questionKey(q);
      state.visited[qid] = true;
      el('q-num').textContent = `Question ${state.index + 1} of ${state.questions.length}`;
      el('q-prompt').innerHTML = renderExamPrompt(q);
      el('q-marks').textContent = `${q.marks ?? 1} mark${Number(q.marks) === 1 ? '' : 's'}`;
      const selected = getSelectedIndex(q);
      el('q-options').innerHTML = (q.options || []).map((opt, i) => `
        <button type="button"
          class="apt-option w-100 text-start d-flex gap-2 align-items-start mb-2 p-2 border rounded-3 ${selected === i ? 'is-selected shadow-sm' : 'bg-white'}"
          data-opt-select="${i}"
          aria-pressed="${selected === i ? 'true' : 'false'}">
          <span class="flex-shrink-0 fw-semibold text-muted-2">${LETTERS[i] || i + 1}.</span>
          <span class="flex-grow-1">${esc(opt)}</span>
        </button>`).join('');
      const marked = !!state.marked[qid];
      el('btn-mark').classList.toggle('btn-warning', marked);
      el('btn-mark').classList.toggle('btn-outline-warning', !marked);
      el('btn-mark').textContent = marked ? 'Marked for review' : 'Mark for review';
      el('btn-prev').disabled = state.index <= 0;
      el('btn-next').disabled = state.index >= state.questions.length - 1;
      renderPalette();
    }

    function buildSubmitAnswers() {
      const out = {};
      (state.questions || []).forEach((q, pos) => {
        const qid = questionKey(q) || `q${pos + 1}`;
        if (!Object.prototype.hasOwnProperty.call(state.answers, qid)) return;
        const idx = Number(state.answers[qid]);
        if (!Number.isFinite(idx) || idx < 0) return;
        const opts = q.options || [];
        out[qid] = {
          index: idx,
          option: opts[idx] != null ? String(opts[idx]) : '',
        };
      });
      return out;
    }

    function bindOptionPicker() {
      const box = el('q-options');
      if (!box || box.dataset.bound === '1') return;
      box.dataset.bound = '1';
      box.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-opt-select]');
        if (!btn || state?.submitted) return;
        e.preventDefault();
        const q = currentQ();
        if (!q) return;
        const idx = Number(btn.getAttribute('data-opt-select'));
        if (!Number.isFinite(idx)) return;
        selectOption(q, idx);
      });
    }

    function paletteCounts() {
      return computePaletteCounts(state?.questions || [], state?.answers || {}, state?.marked || {}, state?.visited || {});
    }

    function renderPaletteLegendCounts() {
      const counts = paletteCounts();
      const map = {
        'count-not-visited': counts['not-visited'],
        'count-not-answered': counts['not-answered'],
        'count-answered': counts.answered,
        'count-review': counts.review,
        'count-answered-review': counts['answered-review'],
      };
      Object.entries(map).forEach(([id, value]) => {
        const node = el(id);
        if (node) node.textContent = String(value);
      });
    }

    function renderPalette() {
      const box = el('palette');
      box.innerHTML = state.questions.map((q, i) => {
        const st = paletteState(i, { ...state.answers, _order: state.questions.map((x) => questionKey(x)) }, state.marked, state.visited);
        return `<button type="button" class="apt-pal apt-pal-${st} ${i === state.index ? 'is-current' : ''}" data-goto="${i}" title="Q${i + 1}">${i + 1}</button>`;
      }).join('');
      box.querySelectorAll('[data-goto]').forEach((btn) => {
        btn.addEventListener('click', () => {
          state.index = Number(btn.getAttribute('data-goto'));
          renderQuestion();
        });
      });
      renderPaletteLegendCounts();
    }

    function startTimer(initialMs) {
      stopTimer();
      if (initialMs != null) {
        timerDeadline = Date.now() + Math.max(0, initialMs);
        state.endsAt = timerDeadline;
      } else {
        timerDeadline = state.endsAt;
      }
      const tick = () => {
        const left = timerDeadline - Date.now();
        el('timer').textContent = formatTimer(left / 1000);
        el('timer').classList.toggle('text-danger', left < 60000);
        if (left <= 0) {
          stopTimer();
          submitExam(true);
        }
      };
      tick();
      timerId = setInterval(tick, 250);
    }

    async function beginExam() {
      if (!state?.test) return;
      let attemptId = 'demo-' + Date.now();
      let questions = state.test.questions || [];
      if (typeof Auth !== 'undefined' && Auth.hasRealAuth() && !Auth.isDemo()) {
        const res = await api(`/aptitude/tests/${encodeURIComponent(state.test.id)}/start`, { method: 'POST' });
        if (!res?.success) {
          toast(res?.message || 'Could not start test.', 'error');
          return;
        }
        attemptId = res.data.attemptId;
        questions = (res.data.test && res.data.test.questions) || questions;
        if (res.data.endsAt != null && Number.isFinite(Number(res.data.endsAt))) {
          state = state || {};
          state._serverEndsAt = Number(res.data.endsAt);
        }
        if (res.data.malpractice) {
          state = state || {};
          state._startMalpractice = res.data.malpractice;
        }
      } else if (opts.resolveDemoQuestions) {
        questions = opts.resolveDemoQuestions(state.test.id) || questions;
      }
      questions = normalizeExamQuestions(questions);
      const durationMs = Math.max(1, Number(state.test.durationMinutes || 30)) * 60 * 1000;
      state.attemptId = attemptId;
      state.questions = questions;
      state.index = 0;
      state.answers = {};
      state.marked = {};
      state.visited = {};
      state.startedAt = Date.now();
      state.endsAt = state._serverEndsAt != null ? state._serverEndsAt : Date.now() + durationMs;
      delete state._serverEndsAt;
      state.started = true;
      state.submitted = false;
      state.status = 'ACTIVE';
      focusViolationCount = 0;
      violationAckRequired = false;
      finalizingViolation = false;
      malpracticeDeadlineAt = 0;
      malpracticeFinalizeInFlight = false;
      timerPauseStartedAt = 0;
      lastServerWarningMessage = '';
      lastServerWarningTitle = '';
      allowFullscreenExit = false;
      examLockdown = true;
      timerDeadline = state.endsAt;
      remainingMs = Math.max(0, state.endsAt - Date.now());
      if (state._startMalpractice) {
        applyMalpracticeServerPayload({ state: state._startMalpractice });
        delete state._startMalpractice;
        if (violationAckRequired) {
          window.setTimeout(() => showMalpracticeAckOverlay(), 0);
        }
      }
      showPanel('exam');
      el('exam-title').textContent = state.test.title || 'Examination';
      root.setAttribute('data-exam-locked', '1');
      bindUnload(true);
      bindLockdownGuards(true);
      bindOptionPicker();
      renderQuestion();
      restoreExamInteractions();
      startTimer();
      tryEnterExamFullscreen();
    }

    async function submitExam(auto = false, options = {}) {
      if (submitting || !state) return false;
      if (!auto) {
        const counts = paletteCounts();
        const ok = typeof confirmAction === 'function'
          ? await confirmAction({
              title: 'Submit test',
              messageHtml: buildSubmitSummaryHtml(counts),
              confirmText: 'Submit',
              variant: 'primary',
            })
          : window.confirm('Submit test now?');
        if (!ok) return false;
      }
      submitting = true;
      stopTimer();
      if (!options.logoutAfter) teardownLockdown();
      const timeTakenSeconds = Math.max(0, Math.round((Date.now() - state.startedAt) / 1000));
      const payload = {
        answers: buildSubmitAnswers(),
        markedForReview: Object.keys(state.marked).filter((k) => state.marked[k]),
        timeTakenSeconds,
        autoSubmitted: !!auto,
      };
      let result = null;
      if (typeof Auth !== 'undefined' && Auth.hasRealAuth() && !Auth.isDemo()) {
        const res = await api(`/aptitude/attempts/${encodeURIComponent(state.attemptId)}/submit`, {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        if (!res?.success) {
          submitting = false;
          if (!options.logoutAfter) toast(res?.message || 'Submit failed.', 'error');
          if (!auto && !options.logoutAfter) {
            examLockdown = true;
            root.setAttribute('data-exam-locked', '1');
            bindLockdownGuards(true);
            bindUnload(true);
            startTimer(Math.max(0, timerDeadline - Date.now()));
          }
          return false;
        }
        result = res.data;
      } else if (opts.scoreLocally) {
        result = opts.scoreLocally(state.test, state.questions, payload.answers, payload);
      } else {
        result = { score: 0, maximumScore: 0, percentage: 0, questionAnalysis: [] };
      }
      submitting = false;
      state.submitted = true;
      if (options.logoutAfter) return true;
      if (auto) toast('Time is up — test submitted automatically.', 'info');
      renderResult(result);
      return true;
    }

    function resultVisibility(result) {
      if (result?.resultVisibility) return String(result.resultVisibility);
      const contest = result?.contestType === 'weekly' || result?.contestType === 'monthly';
      if (!contest) return 'full';
      if (result?.resultStatus === 'PUBLISHED' || result?.resultsPublished) return 'published';
      return 'pending';
    }

    function formatPublishedAt(value) {
      if (!value) return '—';
      const d = new Date(value);
      if (Number.isNaN(d.getTime())) return '—';
      return d.toLocaleString(undefined, {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
      });
    }

    function explanationNeedsPreWrap(text) {
      const raw = String(text || '').trim();
      if (!raw) return false;
      if (/\n/.test(raw)) return true;
      return /(?:^|\n)\s*(?:\d+[\.\)]\s|[-•*]\s|Step\s+\d)/i.test(raw);
    }

    function renderExplanationHtml(explanation) {
      const raw = String(explanation || '').trim();
      if (!raw) return '';
      const preWrap = explanationNeedsPreWrap(raw);
      const style = preWrap ? ' style="white-space:pre-wrap"' : '';
      if (/<[^>]+>/.test(raw)) {
        return `<div class="small apt-rich"${style}>${sanitizeRichHtml(raw)}</div>`;
      }
      return `<div class="small apt-explanation"${style}>${esc(raw)}</div>`;
    }

    function explanationFromAnalysis(a) {
      const raw = String(a?.explanation || a?.solution || '').trim();
      const text = raw.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/gi, ' ').trim();
      if (text) return raw;
      const letters = ['A', 'B', 'C', 'D', 'E', 'F'];
      const idx = Number(a?.correctAnswerIndex);
      const ans = String(a?.correctAnswer || '').trim();
      const letter = Number.isFinite(idx) && idx >= 0 ? (letters[idx] || '') : '';
      if (letter && ans) return `The correct option is ${letter}. ${ans}.`;
      if (ans) return `The correct answer is ${ans}.`;
      return '';
    }

    function renderResult(result) {
      showPanel('result');
      const mode = resultVisibility(result);
      if (mode === 'pending') {
        el('result-summary').innerHTML = `
          <div class="alert alert-info mb-0">
            <div class="fw-semibold mb-1">Result Not Published</div>
            <div>${esc(result.message || 'The challenge has ended. The result will be available after the administrator publishes it.')}</div>
          </div>`;
        el('result-analysis').innerHTML = '';
        return;
      }
      const score = result.score ?? result.marksObtained ?? 0;
      const maxScore = result.maximumScore ?? result.totalMarks ?? 0;
      const contestTitle = result.testName || result.testTitle || 'Challenge';
      const publishedLine = mode === 'published' && result.resultPublishedAt
        ? `<div class="small text-muted-2 mt-2">Result published on: ${esc(formatPublishedAt(result.resultPublishedAt))}</div>`
        : '';
      el('result-summary').innerHTML = `
        ${mode === 'published' ? `<h6 class="fw-bold mb-2">Challenge Result</h6><p class="mb-3"><span class="text-muted-2">Challenge Name:</span> <strong>${esc(contestTitle)}</strong></p>` : ''}
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Score</div><strong>${esc(score)} / ${esc(maxScore)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Percentage</div><strong>${esc(result.percentage ?? 0)}%</strong></div></div>
          ${mode === 'full' ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Accuracy</div><strong>${esc(result.accuracy ?? 0)}%</strong></div></div>` : ''}
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Time taken</div><strong>${esc(result.timeTakenLabel || formatTimer(result.timeTakenSeconds || 0))}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Correct</div><strong class="text-success">${esc(result.correctAnswers ?? result.correctCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Wrong</div><strong class="text-danger">${esc(result.incorrectAnswers ?? result.wrongCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Unanswered</div><strong>${esc(result.unansweredQuestions ?? result.unansweredCount ?? 0)}</strong></div></div>
          ${(mode === 'full' || mode === 'published') && result.rank != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Overall rank</div><strong>#${esc(result.rank)}${Number(result.overallTotal) > 0 ? ` <span class="text-muted-2 fw-normal">of ${esc(result.overallTotal)}</span>` : ''}</strong></div></div>` : ''}
          ${(mode === 'full' || mode === 'published') && result.departmentRank != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">${esc(result.departmentName ? `${result.departmentName} rank` : 'Department rank')}</div><strong>#${esc(result.departmentRank)}${Number(result.departmentTotal) > 0 ? ` <span class="text-muted-2 fw-normal">of ${esc(result.departmentTotal)}</span>` : ''}</strong></div></div>` : ''}
          ${mode === 'full' && result.percentile != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Overall percentile</div><strong>${esc(result.percentile)}%</strong></div></div>` : ''}
          ${mode === 'full' && result.departmentPercentile != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Department percentile</div><strong>${esc(result.departmentPercentile)}%</strong></div></div>` : ''}
        </div>
        ${publishedLine}`;
      if (mode === 'published' || mode === 'score') {
        el('result-analysis').innerHTML = '<p class="text-muted-2 mb-0">Question-level review is hidden for contest attempts.</p>';
        return;
      }
      const analysis = result.questionAnalysis || [];
      el('result-analysis').innerHTML = analysis.length ? analysis.map((a, i) => `
        <div class="border rounded-3 p-3 mb-2">
          <div class="d-flex justify-content-between gap-2 mb-1">
            <div class="flex-grow-1"><strong>Q${i + 1}.</strong> ${renderExamPrompt({ prompt: a.question, questionType: a.questionType })}</div>
            <span class="badge-soft ${a.status === 'correct' ? 'success' : a.status === 'incorrect' ? 'danger' : 'muted'}">${esc(a.status)} · ${esc(a.marksObtained)}/${esc(a.marks)}</span>
          </div>
          ${(a.options || []).length ? `<div class="small mt-2 mb-1">${(a.options || []).map((o, oi) => {
            const letters = ['A', 'B', 'C', 'D'];
            const isCorrect = oi === a.correctAnswerIndex;
            const isPicked = oi === a.studentAnswerIndex;
            let cls = '';
            if (isCorrect) cls = 'text-success fw-semibold';
            else if (isPicked && a.status !== 'correct') cls = 'text-danger fw-semibold';
            const mark = isCorrect ? ' ✓' : (isPicked ? ' (your choice)' : '');
            return `<div class="${cls}">${letters[oi] || oi + 1}. ${esc(o)}${mark}</div>`;
          }).join('')}</div>` : `<div class="small">Your answer: <strong>${esc(a.studentAnswer ?? '—')}</strong></div>
          <div class="small">Correct answer: <strong>${esc(a.correctAnswer ?? '—')}</strong></div>`}
          ${(() => {
            const exp = explanationFromAnalysis(a);
            return exp
              ? `<div class="mt-2 pt-2 border-top">
                  <div class="small fw-semibold mb-1">Explanation</div>
                  ${renderExplanationHtml(exp)}
                </div>`
              : '';
          })()}
        </div>`).join('') : '<p class="text-muted-2 mb-0">No question analysis available.</p>';
    }

    function activePanel() {
      const panel = root.querySelector('[data-exam-panel]:not(.d-none)');
      return panel?.getAttribute('data-exam-panel') || 'instructions';
    }

    async function exitExam(force = false) {
      if (!force && state?.started && state.questions?.length && !state.submitted) {
        const ok = typeof confirmAction === 'function'
          ? await confirmAction({
              title: 'Leave test',
              message: 'Go back to the test list? Your answers so far will not be submitted.',
              confirmText: 'Leave',
              variant: 'danger',
            })
          : window.confirm('Go back to the test list? Your answers so far will not be submitted.');
        if (!ok) return;
      }
      stopTimer();
      teardownLockdown();
      onExit(state?.lastResult);
    }

    root.addEventListener('click', (e) => {
      const t = e.target.closest('[data-exam-action]');
      if (!t) return;
      const action = t.getAttribute('data-exam-action');
      if (action === 'start') beginExam();
      if (action === 'back') {
        if (activePanel() === 'instructions') {
          exitExam(true);
        } else {
          exitExam(false);
        }
      }
      if (action === 'cancel') exitExam(false);
      if (action === 'prev') {
        if (state.index > 0) {
          state.index -= 1;
          renderQuestion();
        }
      }
      if (action === 'next') {
        if (state.index < state.questions.length - 1) {
          state.index += 1;
          renderQuestion();
        }
      }
      if (action === 'clear') {
        const q = currentQ();
        if (q) {
          delete state.answers[questionKey(q)];
          renderQuestion();
        }
      }
      if (action === 'mark') {
        const q = currentQ();
        if (q) {
          const qid = questionKey(q);
          state.marked[qid] = !state.marked[qid];
          renderQuestion();
        }
      }
      if (action === 'submit') submitExam(false);
      if (action === 'done') exitExam(true);
    });

    return {
      open(test) {
        stopTimer();
        teardownLockdown();
        submitting = false;
        const safeTest = {
          ...test,
          questions: normalizeExamQuestions(test?.questions || []),
        };
        state = {
          test: safeTest,
          questions: [],
          answers: {},
          marked: {},
          visited: {},
          index: 0,
          started: false,
          submitted: false,
        };
        bindOptionPicker();
        renderInstructions(safeTest);
        showPanel('instructions');
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
        state = { test: null, lastResult: result, submitted: true, started: true };
        renderResult(result);
        showPanel('result');
        root.classList.remove('d-none');
      },
    };
  }

  global.AptitudeExam = {
    createExamController,
    formatTimer,
    esc,
    stripExamQuestion,
    stripExamQuestions,
    normalizeExamQuestions,
  };
})(window);
