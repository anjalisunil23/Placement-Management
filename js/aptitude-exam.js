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

  function createExamController(opts) {
    const root = opts.root;
    const onExit = opts.onExit || (() => {});
    let state = null;
    let timerId = null;
    let submitting = false;

    function el(id) {
      return root.querySelector(`[data-exam="${id}"]`);
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
        row.classList.toggle('border-primary', on);
        row.classList.toggle('bg-light', on);
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
          class="apt-option w-100 text-start d-flex gap-2 align-items-start mb-2 p-2 border rounded-3 bg-white ${selected === i ? 'border-primary bg-light shadow-sm' : ''}"
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
    }

    function startTimer() {
      stopTimer();
      const tick = () => {
        const left = state.endsAt - Date.now();
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
      showPanel('exam');
      el('exam-title').textContent = state.test.title || 'Examination';
      bindOptionPicker();
      renderQuestion();
      startTimer();
    }

    async function submitExam(auto = false) {
      if (submitting || !state) return;
      if (!auto) {
        const ok = typeof confirmAction === 'function'
          ? await confirmAction({
              title: 'Submit test',
              message: 'Submit your answers now? You cannot change them after submission.',
              confirmText: 'Submit',
              variant: 'primary',
            })
          : window.confirm('Submit test now?');
        if (!ok) return;
      }
      submitting = true;
      stopTimer();
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
          toast(res?.message || 'Submit failed.', 'error');
          if (!auto) startTimer();
          return;
        }
        result = res.data;
      } else if (opts.scoreLocally) {
        result = opts.scoreLocally(state.test, state.questions, payload.answers, payload);
      } else {
        result = { score: 0, maximumScore: 0, percentage: 0, questionAnalysis: [] };
      }
      submitting = false;
      state.submitted = true;
      if (auto) toast('Time is up — test submitted automatically.', 'info');
      renderResult(result);
    }

    function renderResult(result) {
      showPanel('result');
      el('result-summary').innerHTML = `
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Score</div><strong>${esc(result.score ?? result.marksObtained ?? 0)} / ${esc(result.maximumScore ?? result.totalMarks ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Percentage</div><strong>${esc(result.percentage ?? 0)}%</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Accuracy</div><strong>${esc(result.accuracy ?? 0)}%</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Time taken</div><strong>${esc(result.timeTakenLabel || formatTimer(result.timeTakenSeconds || 0))}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Correct</div><strong class="text-success">${esc(result.correctAnswers ?? result.correctCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Incorrect</div><strong class="text-danger">${esc(result.incorrectAnswers ?? result.wrongCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Unanswered</div><strong>${esc(result.unansweredQuestions ?? result.unansweredCount ?? 0)}</strong></div></div>
          <div class="col-6 col-md-3"><div class="card-surface p-3"><div class="small text-muted-2">Rank / Percentile</div><strong>${result.rank != null ? `#${esc(result.rank)}` : '—'} ${result.percentile != null ? `(${esc(result.percentile)}%)` : ''}</strong></div></div>
        </div>`;
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
          ${a.explanation ? `<div class="small text-muted-2 mt-1 apt-rich">${sanitizeRichHtml(a.explanation)}</div>` : ''}
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
        root.classList.add('d-none');
      },
      showResult(result) {
        stopTimer();
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
