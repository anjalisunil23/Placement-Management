/* Student Placement JDs + AI Self-Practice */
(function () {
  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function fmtDate(raw) {
    if (!raw) return '—';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return esc(String(raw).slice(0, 10));
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function toast(msg, type) {
    if (typeof window.toast === 'function') window.toast(msg, type);
    else if (type === 'error') console.error(msg);
    else console.log(msg);
  }

  let jds = [];
  let practiceSession = null;
  let practiceQuestions = [];
  let practiceAnswers = {};
  let practiceIndex = 0;
  let jdViewModal = null;
  let preselectDriveId = null;

  function sourceLabel(mode) {
    if (mode === 'jd') return 'JD';
    if (mode === 'jd_topic') return 'JD + Topic';
    return 'Topic';
  }

  function getPracticeMode() {
    const checked = document.querySelector('input[name="stuPracticeMode"]:checked');
    return checked ? checked.value : 'topic';
  }

  function updatePracticeFormVisibility() {
    const mode = getPracticeMode();
    document.getElementById('stuTopicWrap')?.classList.toggle('d-none', mode === 'jd');
    document.getElementById('stuJdSelectWrap')?.classList.toggle('d-none', mode === 'topic');
  }

  async function loadJds() {
    const root = document.getElementById('jdCardsRoot');
    if (!root) return;
    root.innerHTML = '<p class="text-muted-2 mb-0"><span class="spinner-border spinner-border-sm me-2"></span>Loading job descriptions…</p>';
    const res = await api('/student/jds').catch(() => null);
    jds = res?.data?.jds || [];
    fillJdSelect();
    if (!jds.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">No published job descriptions are available right now. Check back when new placement drives are posted.</p>';
      return;
    }
    root.innerHTML = jds.map((jd) => {
      const skills = (jd.skills || []).slice(0, 8);
      const skillsHtml = skills.length
        ? `<div class="mt-2">${skills.map((s) => `<span class="jd-skill">${esc(s)}</span>`).join('')}</div>`
        : '';
      const deadline = jd.applicationDeadline ? `<span class="ms-2">· Deadline: ${esc(fmtDate(jd.applicationDeadline))}</span>` : '';
      return `<div class="jd-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div class="min-w-0">
            <div class="fw-semibold fs-5">${esc(jd.jobTitle || 'Role')}</div>
            <div class="text-muted-2 small">${esc(jd.companyName || 'Company')}</div>
            ${skillsHtml}
            <div class="small text-muted-2 mt-2">Posted: ${fmtDate(jd.postedDate)}${deadline}</div>
          </div>
          <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <button type="button" class="btn btn-sm btn-outline-primary" data-jd-view="${esc(jd.id)}">View JD</button>
            ${jd.hasDocument ? `<a class="btn btn-sm btn-outline-secondary" href="${esc(jd.documentUrl || jd.jdFileUrl)}" target="_blank" rel="noopener">Download</a>` : ''}
            <button type="button" class="btn btn-sm btn-primary" data-jd-practice="${esc(jd.id)}">Practice with JD</button>
          </div>
        </div>
      </div>`;
    }).join('');

    root.querySelectorAll('[data-jd-view]').forEach((btn) => {
      btn.addEventListener('click', () => openJdView(btn.getAttribute('data-jd-view')));
    });
    root.querySelectorAll('[data-jd-practice]').forEach((btn) => {
      btn.addEventListener('click', () => startPracticeWithJd(btn.getAttribute('data-jd-practice')));
    });
  }

  function fillJdSelect() {
    const sel = document.getElementById('stuJdSelect');
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">Select a published JD…</option>' + jds.map((jd) => {
      const label = `${jd.jobTitle || 'Role'} — ${jd.companyName || 'Company'}`;
      return `<option value="${esc(jd.id)}">${esc(label)}</option>`;
    }).join('');
    if (preselectDriveId) {
      sel.value = preselectDriveId;
      preselectDriveId = null;
    } else if (current) {
      sel.value = current;
    }
  }

  async function openJdView(id) {
    if (!id) return;
    const res = await api(`/student/jds/${encodeURIComponent(id)}`).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not load job description.', 'error');
      return;
    }
    const jd = res.data || {};
    document.getElementById('jdViewTitle').textContent = `${jd.jobTitle || 'Job Description'} — ${jd.companyName || ''}`.trim();
    const body = document.getElementById('jdViewBody');
    const download = document.getElementById('jdViewDownload');
    let html = '';
    if (jd.hasDocument && jd.documentUrl) {
      const mime = String(jd.jdMimeType || '');
      if (mime.startsWith('image/')) {
        html += `<img src="${esc(jd.documentUrl)}" class="img-fluid rounded border mb-3" alt="JD document"/>`;
      } else {
        html += `<iframe src="${esc(jd.documentUrl)}" class="w-100 rounded border mb-3" style="height:480px" title="JD PDF"></iframe>`;
      }
      download.href = jd.documentUrl;
      download.classList.remove('d-none');
    } else {
      download.classList.add('d-none');
    }
    if (jd.description) {
      html += `<div class="border rounded-3 p-3 mb-3"><div class="small fw-semibold mb-2">Description</div><div class="small">${esc(jd.description).replace(/\n/g, '<br>')}</div></div>`;
    }
    if (jd.extractedText) {
      html += `<div class="border rounded-3 p-3"><div class="small fw-semibold mb-2">Extracted JD text</div><div class="small" style="white-space:pre-wrap">${esc(jd.extractedText)}</div></div>`;
    }
    body.innerHTML = html || '<p class="text-muted-2 mb-0">No readable content for this job description.</p>';
    document.getElementById('jdViewPracticeBtn').onclick = () => {
      jdViewModal?.hide();
      startPracticeWithJd(id);
    };
    jdViewModal = jdViewModal || new bootstrap.Modal(document.getElementById('jdViewModal'));
    jdViewModal.show();
  }

  function switchTab(tab) {
    document.querySelectorAll('#jdMainNav .nav-link').forEach((a) => {
      a.classList.toggle('active', a.getAttribute('data-jd-tab') === tab);
    });
    document.getElementById('jdListPanel')?.classList.toggle('d-none', tab !== 'jds');
    document.getElementById('jdPracticePanel')?.classList.toggle('d-none', tab !== 'practice');
    document.getElementById('jdHistoryPanel')?.classList.toggle('d-none', tab !== 'history');
    if (tab === 'history') loadHistory();
  }

  function startPracticeWithJd(driveId) {
    preselectDriveId = driveId;
    document.getElementById('stuModeJd').checked = true;
    updatePracticeFormVisibility();
    fillJdSelect();
    switchTab('practice');
    document.getElementById('stuJdSelect').value = driveId;
  }

  function hidePracticeViews() {
    document.getElementById('practiceExamShell')?.classList.add('d-none');
    document.getElementById('practiceResultShell')?.classList.add('d-none');
  }

  async function generatePractice() {
    const mode = getPracticeMode();
    const topic = (document.getElementById('stuTopicInput')?.value || '').trim();
    const jdDriveId = document.getElementById('stuJdSelect')?.value || '';
    const difficulty = document.getElementById('stuDifficulty')?.value || 'Medium';
    const count = Number(document.getElementById('stuQuestionCount')?.value || 10);
    const instructions = (document.getElementById('stuInstructions')?.value || '').trim();

    if (mode === 'topic' && !topic) {
      toast('Enter or select a topic.', 'error');
      return;
    }
    if ((mode === 'jd' || mode === 'jd_topic') && !jdDriveId) {
      toast('Select a published job description.', 'error');
      return;
    }
    if (mode === 'jd_topic' && !topic) {
      toast('Enter a topic for Topic + Job Description mode.', 'error');
      return;
    }

    const status = document.getElementById('stuGenerateStatus');
    const statusText = document.getElementById('stuGenerateStatusText');
    const btn = document.getElementById('btnStuGenerate');
    status?.classList.remove('d-none');
    btn?.setAttribute('disabled', 'disabled');
    statusText.textContent = mode.includes('jd') ? 'Analyzing JD… Generating questions…' : 'Generating questions…';

    const res = await api('/student/ai-practice/generate', {
      method: 'POST',
      body: JSON.stringify({
        sourceMode: mode,
        topic,
        jdDriveId,
        difficulty,
        count,
        instructions,
      }),
    }).catch(() => null);

    status?.classList.add('d-none');
    btn?.removeAttribute('disabled');

    if (!res?.success) {
      toast(res?.message || 'Unable to generate questions. Please try again.', 'error');
      return;
    }

    practiceSession = res.data;
    practiceQuestions = res.data?.questions || [];
    practiceAnswers = {};
    practiceIndex = 0;
    hidePracticeViews();
    document.getElementById('practiceExamShell')?.classList.remove('d-none');
    renderPracticeQuestion();
    toast('Questions ready — start your practice.', 'success');
  }

  function questionKey(q, i) {
    return String(q?.id || `q${i + 1}`);
  }

  function renderPracticeQuestion() {
    const q = practiceQuestions[practiceIndex];
    const root = document.getElementById('stuPracticeQuestion');
    const prog = document.getElementById('stuPracticeProgress');
    if (!q || !root) return;
    prog.textContent = `Question ${practiceIndex + 1} of ${practiceQuestions.length}`;
    const qid = questionKey(q, practiceIndex);
    const picked = practiceAnswers[qid];
    const opts = (q.options || []).slice(0, 4);
    root.innerHTML = `
      <div class="fw-semibold mb-2">${esc(q.prompt || 'Question')}</div>
      <div class="d-flex flex-column gap-2">
        ${opts.map((opt, oi) => {
          const selected = picked === oi;
          return `<button type="button" class="btn btn-outline-secondary text-start practice-option ${selected ? 'is-selected' : ''}" data-opt="${oi}">
            <span class="fw-semibold me-2">${String.fromCharCode(65 + oi)}.</span>${esc(opt)}
          </button>`;
        }).join('')}
      </div>`;
    root.querySelectorAll('[data-opt]').forEach((btn) => {
      btn.addEventListener('click', () => {
        practiceAnswers[qid] = Number(btn.getAttribute('data-opt'));
        renderPracticeQuestion();
      });
    });
    document.getElementById('btnStuPrev').disabled = practiceIndex <= 0;
    document.getElementById('btnStuNext').disabled = practiceIndex >= practiceQuestions.length - 1;
  }

  async function submitPractice() {
    if (!practiceSession?.sessionId) return;
    const answers = practiceQuestions.map((q, i) => ({
      questionId: questionKey(q, i),
      selectedIndex: practiceAnswers[questionKey(q, i)] ?? -1,
    }));
    const res = await api('/student/ai-practice/submit', {
      method: 'POST',
      body: JSON.stringify({ sessionId: practiceSession.sessionId, answers }),
    }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not submit practice.', 'error');
      return;
    }
    showPracticeResult(res.data || {});
  }

  function showPracticeResult(result) {
    document.getElementById('practiceExamShell')?.classList.add('d-none');
    document.getElementById('practiceResultShell')?.classList.remove('d-none');
    const total = Number(result.questionCount || 0);
    const score = Number(result.score || 0);
    const pct = total > 0 ? Math.round((score / total) * 100) : 0;
    document.getElementById('stuResultStats').innerHTML = `
      <div class="col-6 col-md-3"><div class="border rounded-3 p-2 small"><div class="text-muted-2">Score</div><strong>${score} / ${total}</strong></div></div>
      <div class="col-6 col-md-3"><div class="border rounded-3 p-2 small"><div class="text-muted-2">Percentage</div><strong>${pct}%</strong></div></div>
      <div class="col-6 col-md-3"><div class="border rounded-3 p-2 small"><div class="text-muted-2">Correct</div><strong class="text-success">${score}</strong></div></div>
      <div class="col-6 col-md-3"><div class="border rounded-3 p-2 small"><div class="text-muted-2">Wrong</div><strong class="text-danger">${Math.max(0, total - score)}</strong></div></div>`;
    const analysis = result.analysis || [];
    document.getElementById('stuResultReview').innerHTML = analysis.map((a, i) => {
      const ok = a.status === 'correct';
      const icon = ok ? '<span class="text-success">✓ Correct</span>' : '<span class="text-danger">✗ Incorrect</span>';
      const picked = a.studentAnswerIndex >= 0 ? String.fromCharCode(65 + a.studentAnswerIndex) : '—';
      const correct = a.correctAnswerIndex >= 0 ? String.fromCharCode(65 + a.correctAnswerIndex) : '—';
      return `<div class="border rounded-3 p-3 small">
        <div class="d-flex justify-content-between gap-2 mb-1"><strong>Q${i + 1}.</strong>${icon}</div>
        <div class="mb-2">${esc(a.question || '')}</div>
        <div>Your answer: <strong>${esc(picked)}</strong> · Correct answer: <strong>${esc(correct)}</strong></div>
        ${a.explanation ? `<div class="mt-2 pt-2 border-top text-muted-2">${esc(a.explanation)}</div>` : ''}
      </div>`;
    }).join('') || '<p class="text-muted-2 mb-0">No review available.</p>';
  }

  async function loadHistory() {
    const body = document.getElementById('stuHistoryBody');
    if (!body) return;
    body.innerHTML = '<tr><td colspan="6" class="text-muted-2">Loading…</td></tr>';
    const res = await api('/student/ai-practice/history').catch(() => null);
    const sessions = res?.data?.sessions || [];
    if (!sessions.length) {
      body.innerHTML = '<tr><td colspan="6" class="text-muted-2">No practice history yet.</td></tr>';
      return;
    }
    body.innerHTML = sessions.map((s) => {
      const label = s.jdTitle
        ? `${s.jdTitle}${s.companyName ? ` — ${s.companyName}` : ''}${s.topic && s.sourceMode === 'jd_topic' ? ` (${s.topic})` : ''}`
        : (s.topic || '—');
      return `<tr>
        <td>${fmtDate(s.completedAt || s.createdAt)}</td>
        <td>${esc(sourceLabel(s.sourceMode))}</td>
        <td>${esc(label)}</td>
        <td>${esc(s.questionCount || 0)}</td>
        <td>${s.status === 'completed' ? `${esc(s.score)}/${esc(s.questionCount)}` : '—'}</td>
        <td>${s.status === 'completed' ? `<button type="button" class="btn btn-sm btn-outline-primary" data-hist-view="${esc(s.id)}">Review</button>` : ''}</td>
      </tr>`;
    }).join('');
    body.querySelectorAll('[data-hist-view]').forEach((btn) => {
      btn.addEventListener('click', () => viewHistorySession(btn.getAttribute('data-hist-view')));
    });
  }

  async function viewHistorySession(id) {
    const res = await api(`/student/ai-practice/${encodeURIComponent(id)}`).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not load practice result.', 'error');
      return;
    }
    switchTab('practice');
    hidePracticeViews();
    document.getElementById('practiceResultShell')?.classList.remove('d-none');
    showPracticeResult(res.data || {});
  }

  function bindEvents() {
    document.querySelectorAll('#jdMainNav [data-jd-tab]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        switchTab(a.getAttribute('data-jd-tab'));
      });
    });
    document.querySelectorAll('input[name="stuPracticeMode"]').forEach((r) => {
      r.addEventListener('change', updatePracticeFormVisibility);
    });
    document.getElementById('btnStuGenerate')?.addEventListener('click', generatePractice);
    document.getElementById('btnStuPrev')?.addEventListener('click', () => {
      if (practiceIndex > 0) { practiceIndex -= 1; renderPracticeQuestion(); }
    });
    document.getElementById('btnStuNext')?.addEventListener('click', () => {
      if (practiceIndex < practiceQuestions.length - 1) { practiceIndex += 1; renderPracticeQuestion(); }
    });
    document.getElementById('btnStuSubmitPractice')?.addEventListener('click', submitPractice);
    document.getElementById('btnStuTryAgain')?.addEventListener('click', () => {
      hidePracticeViews();
      practiceSession = null;
      practiceQuestions = [];
    });
    document.getElementById('btnStuNewQuestions')?.addEventListener('click', () => {
      hidePracticeViews();
      generatePractice();
    });

    const hash = (location.hash || '').replace(/^#/, '');
    if (hash === 'practice' || hash === 'history') switchTab(hash);
    const params = new URLSearchParams(location.search);
    const jd = params.get('jd');
    if (jd) startPracticeWithJd(jd);
  }

  onAppReady(() => {
    if (Auth.role() !== 'student') return;
    updatePracticeFormVisibility();
    bindEvents();
    loadJds();
  });
})();
