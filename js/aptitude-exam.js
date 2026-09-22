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

  function renderRichHtml(html) {
    return `<div class="apt-rich">${sanitizeRichHtml(html)}</div>`;
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
    let focusViolationHandled = false;

    function el(id) {
      return root.querySelector(`[data-exam="${id}"]`);
    }

    function isContestAttempt(meta = state?.test) {
      const type = String(meta?.contestType || 'none');
      return type === 'weekly' || type === 'monthly';
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
      overlay.style.zIndex = '1080';
      overlay.style.pointerEvents = 'auto';
      overlay.innerHTML = `
        <div style="max-width:34rem;width:min(34rem,100%);border-radius:1rem;padding:1rem 1.1rem;background:#fff;box-shadow:0 20px 60px rgba(15,23,42,.22);border:1px solid rgba(148,163,184,.35)">
          <div style="font-size:1rem;font-weight:700;margin-bottom:.35rem">Test Ended</div>
          <div data-exam-lock-message style="font-size:.95rem;line-height:1.45;color:#334155">You left the test window. Submitting your answers and signing you out…</div>
        </div>`;
      document.body.appendChild(overlay);
      lockOverlay = overlay;
      return lockOverlay;
    }

    function showLockOverlay(message) {
      const overlay = ensureLockOverlay();
      const msg = overlay.querySelector('[data-exam-lock-message]');
      if (msg) msg.textContent = message || 'You have left the test window. Return to this tab to continue.';
      overlay.style.display = 'flex';
    }

    function hideLockOverlay() {
      if (lockOverlay) lockOverlay.style.display = 'none';
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
      if (typeof Auth !== 'undefined' && typeof Auth.logout === 'function') {
        Auth.logout();
        return;
      }
      window.location.href = 'public-stats.html';
    }

    async function handleFocusViolation() {
      if (focusViolationHandled || !examLockdown || state?.submitted || !state?.started || submitting) return;
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
      if (!examLockdown || !state?.started || state.submitted) return;
      e.preventDefault();
      e.stopPropagation();
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
          <strong>During the test:</strong> copy, cut, and paste are disabled. Switching tabs or windows will automatically submit your test and sign you out.
          ${isContestAttempt(test) ? ' Contest rules apply for the full duration.' : ''}
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
      el('q-prompt').innerHTML = renderRichHtml(q.prompt || '');
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
      state.endsAt = Date.now() + durationMs;
      state.started = true;
      state.submitted = false;
      state.status = 'ACTIVE';
      focusViolationHandled = false;
      examLockdown = true;
      timerDeadline = state.endsAt;
      remainingMs = durationMs;
      showPanel('exam');
      el('exam-title').textContent = state.test.title || 'Examination';
      root.setAttribute('data-exam-locked', '1');
      bindUnload(true);
      bindLockdownGuards(true);
      bindOptionPicker();
      renderQuestion();
      restoreExamInteractions();
      startTimer();
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
            <div>${esc(result.message || 'The contest has ended. The result will be available after the administrator publishes it.')}</div>
          </div>`;
        el('result-analysis').innerHTML = '';
        return;
      }
      const score = result.score ?? result.marksObtained ?? 0;
      const maxScore = result.maximumScore ?? result.totalMarks ?? 0;
      const contestTitle = result.testName || result.testTitle || 'Contest';
      const publishedLine = mode === 'published' && result.resultPublishedAt
        ? `<div class="small text-muted-2 mt-2">Result published on: ${esc(formatPublishedAt(result.resultPublishedAt))}</div>`
        : '';
      el('result-summary').innerHTML = `
        ${mode === 'published' ? `<h6 class="fw-bold mb-2">Contest Result</h6><p class="mb-3"><span class="text-muted-2">Contest Name:</span> <strong>${esc(contestTitle)}</strong></p>` : ''}
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Score</div><strong>${esc(score)} / ${esc(maxScore)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Percentage</div><strong>${esc(result.percentage ?? 0)}%</strong></div></div>
          ${mode === 'full' ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Accuracy</div><strong>${esc(result.accuracy ?? 0)}%</strong></div></div>` : ''}
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Time taken</div><strong>${esc(result.timeTakenLabel || formatTimer(result.timeTakenSeconds || 0))}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Correct</div><strong class="text-success">${esc(result.correctAnswers ?? result.correctCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Wrong</div><strong class="text-danger">${esc(result.incorrectAnswers ?? result.wrongCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Unanswered</div><strong>${esc(result.unansweredQuestions ?? result.unansweredCount ?? 0)}</strong></div></div>
          ${(mode === 'full' || mode === 'published') && result.rank != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Rank</div><strong>#${esc(result.rank)}</strong></div></div>` : ''}
          ${mode === 'full' && result.percentile != null ? `<div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Percentile</div><strong>${esc(result.percentile)}%</strong></div></div>` : ''}
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
            <div class="flex-grow-1"><strong>Q${i + 1}.</strong> ${renderRichHtml(a.question)}</div>
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
                  <div class="small apt-rich">${sanitizeRichHtml(exp)}</div>
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
