/* PlaceHub — coding practice hub (student take + admin manage/progress) */
(function () {
  if (!window.CodeExecutionService || window.CodeExecutionService.ready === false) {
    console.error('CodeExecutionService is not loaded. Include js/coding-execution.js before js/coding-service.js.');
  }

  const CATEGORIES = (typeof CodingData !== 'undefined' && CodingData.CATEGORIES) || ['Programming', 'Python', 'Data Structures', 'Programming Logic', 'Algorithms'];
  const DIFFICULTIES = (typeof CodingData !== 'undefined' && CodingData.DIFFICULTIES) || ['Easy', 'Medium', 'Hard'];
  const CONTEST_WEEKDAYS = [
    { value: 1, label: 'Monday' }, { value: 2, label: 'Tuesday' }, { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' }, { value: 5, label: 'Friday' }, { value: 6, label: 'Saturday' }, { value: 7, label: 'Sunday' },
  ];

  let access = { canTake: false, canManage: false, canViewDirectory: false, scope: null };
  let tests = [];
  let bank = [];
  let managePanel = 'tests';
  let progressPanel = 'tests';
  let myResultsView = 'tests';
  let dirFilterBranch = '';
  let dirFilterBatch = '';
  let bankDifficultyFilter = '';
  let testFormModal = null;
  let bankPickModal = null;
  let bankProblemModal = null;
  let studentCodModal = null;
  let exam = null;
  let codAiModal = null;
  let aiPreviewProblems = [];
  let aiLastFormParams = null;

  function esc(s) {
    return CodingExam.esc(s);
  }

  function difficultyClass(diff) {
    return CodingExam.difficultyClass(diff);
  }

  function toastMsg(msg, kind) {
    if (typeof toast === 'function') toast(msg, kind);
  }

  function canManageContests() {
    if (typeof Auth.canManageCodingContests === 'function') return Auth.canManageCodingContests();
    return typeof Auth.canManageAptitudeContests === 'function' && Auth.canManageAptitudeContests();
  }

  function staffAssignedBatches() {
    if (typeof staffClassInchargeBatches === 'function') return staffClassInchargeBatches();
    const u = Auth.user() || {};
    return Array.isArray(u.assignedClassBatches) ? u.assignedClassBatches : [];
  }

  function fillSelect(el, values, selected) {
    if (!el) return;
    el.innerHTML = values.map((v) => {
      const val = typeof v === 'object' ? v.value : v;
      const label = typeof v === 'object' ? v.label : v;
      return `<option value="${esc(val)}" ${String(val) === String(selected) ? 'selected' : ''}>${esc(label)}</option>`;
    }).join('');
  }

  function isContestTest(t) {
    const type = String(t?.contestType || 'none');
    return type === 'weekly' || type === 'monthly';
  }

  function isContestOpenClient(test) {
    if (test && typeof test.contestOpen === 'boolean') return test.contestOpen;
    const type = String(test?.contestType || 'none');
    if (type === 'none' || !type) return true;
    const now = new Date();
    if (type === 'weekly') {
      const want = Number(test?.contestWeekday);
      if (!Number.isFinite(want) || want < 1 || want > 7) return false;
      const iso = now.getDay() === 0 ? 7 : now.getDay();
      return iso === want;
    }
    if (type === 'monthly') {
      const want = Number(test?.contestMonthDay);
      return Number.isFinite(want) && want >= 1 && want <= 28 && now.getDate() === want;
    }
    return true;
  }

  function contestScheduleLabel(test) {
    const type = String(test?.contestType || 'none');
    if (type === 'weekly') {
      const hit = CONTEST_WEEKDAYS.find((d) => d.value === Number(test?.contestWeekday));
      return hit ? `Weekly · ${hit.label}` : 'Weekly contest';
    }
    if (type === 'monthly') {
      const day = Number(test?.contestMonthDay);
      return Number.isFinite(day) && day > 0 ? `Monthly · day ${day}` : 'Monthly contest';
    }
    return '';
  }

  function contestBadgeHtml(t) {
    const type = String(t?.contestType || 'none');
    if (type === 'none') return '';
    const label = contestScheduleLabel(t);
    const open = isContestOpenClient(t);
    return `<div class="mt-1 d-flex flex-wrap gap-1"><span class="badge-soft info">${esc(label || type)}</span><span class="badge-soft ${open ? 'success' : 'muted'}">${open ? 'Open today' : 'Scheduled'}</span></div>`;
  }

  function testMetaLine(t) {
    const qn = t.questionCount || t.questions || (t.items || []).length || 0;
    const dur = t.durationMinutes || t.duration || 0;
    const marks = t.totalMarks || t.marks || 0;
    return `${esc(t.category || 'Programming')} · ${esc(t.difficulty || 'Medium')} · ${esc(qn)} Qs · ${esc(dur)} min · ${esc(marks)} marks`;
  }

  function emptyProblem() {
    const starters = typeof CodingData !== 'undefined' && CodingData.defaultStarters
      ? CodingData.defaultStarters('# Write your solution\n')
      : { Python: '# Write your solution\n' };
    return {
      id: 'p-' + Date.now() + '-' + Math.floor(Math.random() * 999),
      title: '',
      description: '',
      inputFormat: '',
      outputFormat: '',
      constraints: '',
      examples: [{ input: '', output: '' }],
      starterCode: starters,
      marks: 2,
      difficulty: 'Medium',
      category: 'Programming',
      keywords: { Python: [] },
      testCases: [
        { id: 's1', label: 'Sample Test Case', input: '', expected: '', sample: true },
        { id: 'h1', input: '', expected: '', sample: false },
        { id: 'h2', input: '', expected: '', sample: false },
      ],
    };
  }

  function currentRole() {
    if (typeof Auth !== 'undefined' && Auth.role) {
      const role = Auth.role();
      if (role) return role;
    }
    try {
      const user = JSON.parse(localStorage.getItem('ph-user') || 'null') || {};
      let role = user.role || localStorage.getItem('ph-role') || '';
      const designation = String(user.designation || '').toUpperCase();
      if ((role === 'staff' || !role) && (user.isHod === true || user.isHod === 1 || user.isHod === '1' || /\bHOD\b/.test(designation) || /HEAD OF/.test(designation))) {
        role = 'placement_officer';
      }
      return role || '';
    } catch (e) {
      return '';
    }
  }

  function applyRoleAccess(base = access) {
    const role = currentRole();
    const next = { ...base };
    if (role === 'placement_officer') {
      next.canTake = false;
      next.canManage = true;
      next.canViewDirectory = true;
    } else if (role === 'admin' || role === 'staff') {
      next.canTake = false;
      next.canViewDirectory = true;
      if (role === 'admin') next.canManage = false;
    } else if (role === 'student') {
      next.canTake = true;
      next.canManage = false;
      next.canViewDirectory = false;
    } else {
      next.canTake = false;
      next.canManage = false;
      next.canViewDirectory = false;
    }
    return next;
  }

  function allowedViews() {
    const views = [];
    if (access.canTake) views.push('take');
    if (access.canViewDirectory) views.push('progress');
    if (access.canManage) views.push('manage');
    return views;
  }

  function defaultView() {
    const views = allowedViews();
    const role = currentRole();
    if (views.includes('progress') && (role === 'placement_officer' || role === 'admin' || role === 'staff')) return 'progress';
    if (views.includes('manage') && role === 'placement_officer') return 'manage';
    if (views.includes('take')) return 'take';
    if (views.includes('progress')) return 'progress';
    return views[0] || (access.canViewDirectory ? 'progress' : 'take');
  }

  function setupViewNav() {
    const nav = document.getElementById('codViewNav');
    if (!nav) return;
    const views = allowedViews();
    nav.classList.toggle('d-none', views.length <= 1);
    nav.querySelectorAll('[data-view]').forEach((link) => {
      const view = link.getAttribute('data-view');
      link.closest('.nav-item')?.classList.toggle('d-none', !views.includes(view));
    });
  }

  async function applyView(requested) {
    const views = allowedViews();
    let view = requested || defaultView();
    const role = currentRole();
    if (!access.canTake || role === 'placement_officer' || role === 'admin' || role === 'staff') {
      if (view === 'take' || !views.includes(view)) {
        view = views.includes('progress') ? 'progress' : (views.includes('manage') ? 'manage' : defaultView());
      }
    }
    if (!views.includes(view)) view = defaultView();
    const hash = `#${view}`;
    if (location.hash !== hash) history.replaceState(null, '', hash);

    document.getElementById('codTake')?.classList.toggle('d-none', !(view === 'take' && access.canTake));
    document.getElementById('codDirectory')?.classList.toggle('d-none', view !== 'progress');
    document.getElementById('codManage')?.classList.toggle('d-none', view !== 'manage');
    document.querySelectorAll('#codViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-view') === view);
    });

    if (view === 'take' && access.canTake) await loadHub();
    if (view === 'progress' && access.canViewDirectory) {
      await initDirFilters();
      await loadDirectory();
    }
    if (view === 'manage' && access.canManage) {
      await loadManaged();
      renderManage();
    }
    if (typeof renderShell === 'function') {
      renderShell(`${document.body?.dataset?.page || 'mock-coding.html'}${hash}`);
    }
  }

  async function loadAccess() {
    const u = (typeof Auth !== 'undefined' && Auth.user && Auth.user()) || {};
    access = applyRoleAccess({
      canTake: typeof Auth !== 'undefined' && typeof Auth.canTakeCodingMock === 'function' && Auth.canTakeCodingMock(),
      canManage: typeof Auth !== 'undefined' && typeof Auth.canManageCodingTests === 'function' && Auth.canManageCodingTests(),
      canViewDirectory: typeof Auth !== 'undefined' && typeof Auth.canViewCodingDirectory === 'function' && Auth.canViewCodingDirectory(),
      scope: null,
    });
    if (typeof Auth !== 'undefined' && Auth.hasRealAuth() && !Auth.isDemo()) {
      let res = await api('/coding/access').catch(() => null);
      if (!res?.success) res = await api('/aptitude/access').catch(() => null);
      if (res?.success && res.data) {
        access.scope = res.data.scope || access.scope;
      }
    }
    access = applyRoleAccess(access);
    const role = currentRole();
    if ((role === 'staff' || role === 'placement_officer') && !access.scope) {
      access.scope = {
        role,
        departmentId: u.departmentId || '',
        departmentName: u.departmentName || u.department || '',
        assignedClassBatches: role === 'staff' ? staffAssignedBatches() : [],
      };
    }
  }

  function renderStats(p) {
    const row = document.getElementById('myStatsRow');
    if (!row) return;
    row.innerHTML = [
      ['Problems Solved', p.problemsSolved || 0],
      ['Best Score', p.bestScore || 0],
      ['Average Score', p.averageScore || '0%'],
      ['Recent Score', p.recentScore || '0%'],
    ].map(([lbl, val]) => `
      <div class="col-6 col-md-3">
        <div class="card-surface p-3 apt-stat">
          <div class="small text-muted-2">${esc(lbl)}</div>
          <div class="val">${esc(val)}</div>
        </div>
      </div>`).join('');
  }

  function historyMatchesView(h) {
    const contest = isContestTest(h) || String(h.contestType || '') === 'weekly' || String(h.contestType || '') === 'monthly';
    return myResultsView === 'contests' ? contest : !contest;
  }

  function renderHistory(p) {
    const hist = (p.history || []).filter(historyMatchesView);
    const root = document.getElementById('myHistory');
    if (!root) return;
    root.innerHTML = hist.length
      ? hist.slice(0, 8).map((h) => `
          <div class="d-flex justify-content-between align-items-start border-bottom py-2 gap-2">
            <div class="min-w-0">
              <div class="text-truncate fw-medium">${esc(h.testTitle)}</div>
              <div class="small text-muted-2">${esc(h.dateLabel || '')}</div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="small fw-semibold">${esc(h.score)} / ${esc(h.totalMarks)}</div>
              <div class="d-flex justify-content-end align-items-center gap-1 mt-1">
                <span class="small text-muted-2">${esc(h.percentage)}%</span>
                <span class="badge-soft ${h.status === 'Passed' ? 'success' : 'danger'}">${esc(h.status)}</span>
              </div>
            </div>
          </div>`).join('')
      : `<p class="text-muted-2 mb-0">${myResultsView === 'contests' ? 'No contest attempts yet.' : 'No coding attempts yet. Select a test on the left to begin.'}</p>`;
  }

  function renderTestList(list) {
    const root = document.getElementById('testList');
    if (!root) return;
    const visible = access.canManage
      ? list
      : list.filter((t) => (t.status || 'published') === 'published' && isContestOpenClient(t));
    if (!visible.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">No coding tests are available yet.</p>';
      return;
    }
    root.innerHTML = visible.map((t) => `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-start gap-2 cod-test-card">
        <div class="flex-grow-1">
          <div class="fw-semibold">${esc(t.title)}</div>
          <div class="small text-muted-2 d-flex flex-wrap align-items-center gap-1 mt-1">
            <span>${esc(t.category || '')}</span>
            <span>·</span>
            <span class="badge-soft ${difficultyClass(t.difficulty)}">${esc(t.difficulty || 'Medium')}</span>
            <span>·</span>
            <span>${esc(t.questions || t.questionCount || 0)} Questions</span>
            <span>·</span>
            <span>${esc(t.duration || t.durationMinutes || 0)} min</span>
            <span>·</span>
            <span>${esc(t.marks || t.totalMarks || 0)} marks</span>
          </div>
          <div class="small mt-1">${esc(t.description || '')}</div>
          ${isContestTest(t) ? contestBadgeHtml(t) : ''}
        </div>
        ${access.canTake ? `<button type="button" class="btn btn-sm btn-primary" data-open-test="${esc(t.id)}">Select</button>` : ''}
      </div>`).join('');
    root.querySelectorAll('[data-open-test]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = visible.find((x) => String(x.id) === String(btn.getAttribute('data-open-test')));
        if (t) openExam(t);
      });
    });
  }

  function openExam(test) {
    if (!exam) return;
    document.getElementById('hubView').classList.add('d-none');
    exam.open(test);
  }

  async function closeExam() {
    exam?.hide();
    document.getElementById('hubView').classList.remove('d-none');
    await loadHub();
  }

  async function loadHub() {
    try {
      const [list, progress] = await Promise.all([
        CodingService.listTests(),
        CodingService.getProgress(),
      ]);
      renderStats(progress);
      renderHistory(progress);
      renderTestList(list);
    } catch (err) {
      toastMsg(err?.message || 'Could not load coding tests.', 'error');
    }
  }

  async function loadManaged() {
    try {
      tests = await CodingService.listManagedTests();
      bank = await CodingService.listBank();
    } catch (err) {
      tests = [];
      bank = [];
      toastMsg(err?.message || 'Could not load coding tests.', 'error');
    }
  }

  function applyManagePanel(panel) {
    if (panel === 'contests' && canManageContests()) managePanel = 'contests';
    else if (panel === 'bank') managePanel = 'bank';
    else managePanel = 'tests';
    document.getElementById('manageTestsPanel')?.classList.toggle('d-none', managePanel !== 'tests');
    document.getElementById('manageContestsPanel')?.classList.toggle('d-none', managePanel !== 'contests');
    document.getElementById('manageBankPanel')?.classList.toggle('d-none', managePanel !== 'bank');
    document.querySelectorAll('#manageViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-manage-view') === managePanel);
    });
    if (managePanel === 'bank') renderBank();
  }

  function syncManageContestActions() {
    const show = canManageContests();
    document.getElementById('manageContestNavItem')?.classList.toggle('d-none', !show);
    document.getElementById('tfContestWrap')?.classList.toggle('d-none', !show);
    if (!show && managePanel === 'contests') applyManagePanel('tests');
  }

  function renderManageRow(t, { showContestBadge = false } = {}) {
    return `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
          <strong>${esc(t.title)}</strong>
          <div class="small text-muted-2">${esc(t.status || 'unpublished')} · ${testMetaLine(t)}</div>
          ${showContestBadge ? contestBadgeHtml(t) : ''}
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="${esc(t.id)}">Bulk problems</button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-edit="${esc(t.id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-delete-test="${esc(t.id)}">Delete</button>
        </div>
      </div>`;
  }

  function bindManageListActions(root) {
    if (!root) return;
    root.querySelectorAll('[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = tests.find((x) => String(x.id) === String(btn.getAttribute('data-edit')));
        if (t) openTestForm(t);
      });
    });
    root.querySelectorAll('[data-delete-test]').forEach((btn) => {
      btn.addEventListener('click', () => deleteTest(btn.getAttribute('data-delete-test')));
    });
    root.querySelectorAll('[data-bulk]').forEach((btn) => {
      btn.addEventListener('click', () => openBulkProblems(btn.getAttribute('data-bulk')));
    });
  }

  function renderManage() {
    if (!access.canManage) return;
    syncManageContestActions();
    applyManagePanel(managePanel);
    const regular = tests.filter((t) => !isContestTest(t));
    const contests = tests.filter((t) => isContestTest(t));
    const testsRoot = document.getElementById('manageTestsList');
    const contestsRoot = document.getElementById('manageContestsList');
    if (testsRoot) {
      testsRoot.innerHTML = regular.length
        ? regular.map((t) => renderManageRow(t)).join('')
        : '<p class="text-muted-2 mb-0">No regular tests yet. Click <strong>New test</strong> to create one for your department.</p>';
      bindManageListActions(testsRoot);
    }
    if (contestsRoot) {
      contestsRoot.innerHTML = contests.length
        ? contests.map((t) => renderManageRow(t, { showContestBadge: true })).join('')
        : '<p class="text-muted-2 mb-0">No weekly or monthly contests yet. Create a contest, publish it, then track results on Progress.</p>';
      bindManageListActions(contestsRoot);
    }
  }

  function problemEditorHtml(q) {
    const sample = (q.testCases || []).find((t) => t.sample) || { input: '', expected: '' };
    const hidden = (q.testCases || []).filter((t) => !t.sample);
    const h1 = hidden[0] || { input: '', expected: '' };
    const h2 = hidden[1] || { input: '', expected: '' };
    const py = q.starterCode?.Python || '';
    return `
      <div class="border rounded-3 p-3" data-problem="${esc(q.id)}">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small fw-semibold">Problem</div>
          <button type="button" class="btn btn-sm btn-outline-danger" data-remove-problem="${esc(q.id)}">Remove</button>
        </div>
        <div class="row g-2">
          <div class="col-md-8"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" data-f="title" value="${esc(q.title || '')}"/></div>
          <div class="col-md-2"><label class="form-label small mb-1">Marks</label><input class="form-control form-control-sm" type="number" data-f="marks" min="1" value="${esc(q.marks || 2)}"/></div>
          <div class="col-md-2"><label class="form-label small mb-1">Difficulty</label>
            <select class="form-select form-select-sm" data-f="difficulty">${DIFFICULTIES.map((d) => `<option ${d === (q.difficulty || 'Medium') ? 'selected' : ''}>${d}</option>`).join('')}</select>
          </div>
          <div class="col-md-6"><label class="form-label small mb-1">Category</label>
            <select class="form-select form-select-sm" data-f="category">${CATEGORIES.map((c) => `<option ${c === (q.category || 'Programming') ? 'selected' : ''}>${c}</option>`).join('')}</select>
          </div>
          <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" data-f="description" rows="2">${esc(q.description || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Input format</label><textarea class="form-control form-control-sm" data-f="inputFormat" rows="2">${esc(q.inputFormat || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Output format</label><textarea class="form-control form-control-sm" data-f="outputFormat" rows="2">${esc(q.outputFormat || '')}</textarea></div>
          <div class="col-12"><label class="form-label small mb-1">Constraints</label><input class="form-control form-control-sm" data-f="constraints" value="${esc(q.constraints || '')}"/></div>
          <div class="col-md-6"><label class="form-label small mb-1">Example input</label><textarea class="form-control form-control-sm font-monospace" data-f="exIn" rows="2">${esc(q.examples?.[0]?.input || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Example output</label><textarea class="form-control form-control-sm font-monospace" data-f="exOut" rows="2">${esc(q.examples?.[0]?.output || '')}</textarea></div>
          <div class="col-12"><label class="form-label small mb-1">Python starter</label><textarea class="form-control form-control-sm font-monospace" data-f="python" rows="4">${esc(py)}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Sample input</label><textarea class="form-control form-control-sm font-monospace" data-f="sIn" rows="2">${esc(sample.input || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Sample expected</label><textarea class="form-control form-control-sm font-monospace" data-f="sOut" rows="2">${esc(sample.expected || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Hidden case 1 input</label><textarea class="form-control form-control-sm font-monospace" data-f="h1In" rows="2">${esc(h1.input || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Hidden case 1 expected</label><textarea class="form-control form-control-sm font-monospace" data-f="h1Out" rows="2">${esc(h1.expected || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Hidden case 2 input</label><textarea class="form-control form-control-sm font-monospace" data-f="h2In" rows="2">${esc(h2.input || '')}</textarea></div>
          <div class="col-md-6"><label class="form-label small mb-1">Hidden case 2 expected</label><textarea class="form-control form-control-sm font-monospace" data-f="h2Out" rows="2">${esc(h2.expected || '')}</textarea></div>
        </div>
      </div>`;
  }

  function collectProblem(el) {
    const v = (name) => el.querySelector(`[data-f="${name}"]`)?.value || '';
    const starters = typeof CodingData !== 'undefined' && CodingData.defaultStarters
      ? CodingData.defaultStarters(v('python'))
      : { Python: v('python') };
    const testCases = [
      { id: 's1', label: 'Sample Test Case', input: v('sIn'), expected: v('sOut'), sample: true },
    ];
    if (v('h1In') || v('h1Out')) testCases.push({ id: 'h1', input: v('h1In'), expected: v('h1Out'), sample: false });
    if (v('h2In') || v('h2Out')) testCases.push({ id: 'h2', input: v('h2In'), expected: v('h2Out'), sample: false });
    return {
      id: el.getAttribute('data-problem'),
      title: v('title').trim(),
      description: v('description').trim(),
      inputFormat: v('inputFormat').trim(),
      outputFormat: v('outputFormat').trim(),
      constraints: v('constraints').trim(),
      examples: [{ input: v('exIn'), output: v('exOut') }],
      starterCode: starters,
      marks: Number(v('marks') || 2),
      difficulty: v('difficulty') || 'Medium',
      category: v('category') || 'Programming',
      testCases,
    };
  }

  function bindProblemList(root) {
    root?.querySelectorAll('[data-remove-problem]').forEach((btn) => {
      btn.addEventListener('click', () => btn.closest('[data-problem]')?.remove());
    });
  }

  function addProblemToForm(q) {
    const list = document.getElementById('problemList');
    if (!list) return;
    list.insertAdjacentHTML('beforeend', problemEditorHtml(q || emptyProblem()));
    bindProblemList(list);
  }

  function initContestMonthDaySelect() {
    const el = document.getElementById('tfContestMonthDay');
    if (!el || el.options.length > 0) return;
    el.innerHTML = Array.from({ length: 28 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join('');
  }

  function syncContestFormFields() {
    const wrap = document.getElementById('tfContestWrap');
    const type = document.getElementById('tfContestType')?.value || 'none';
    wrap?.classList.toggle('d-none', !canManageContests());
    document.getElementById('tfContestWeekdayWrap')?.classList.toggle('d-none', type !== 'weekly');
    document.getElementById('tfContestMonthDayWrap')?.classList.toggle('d-none', type !== 'monthly');
  }

  function openTestForm(test = null, preset = null) {
    document.getElementById('testFormTitle').textContent = test
      ? (isContestTest(test) ? 'Edit contest' : 'Edit test')
      : (preset?.contestType ? `New ${preset.contestType} contest` : 'Create test');
    document.getElementById('tfId').value = test?.id || '';
    document.getElementById('tfTitle').value = test?.title || preset?.title || '';
    document.getElementById('tfDescription').value = test?.description || '';
    fillSelect(document.getElementById('tfCategory'), CATEGORIES, test?.category || 'Programming');
    fillSelect(document.getElementById('tfDifficulty'), DIFFICULTIES, test?.difficulty || 'Medium');
    document.getElementById('tfDuration').value = test?.duration || test?.durationMinutes || 20;
    document.getElementById('tfStatus').value = test?.status === 'published' ? 'published' : 'unpublished';
    initContestMonthDaySelect();
    const contestType = test?.contestType || preset?.contestType || 'none';
    document.getElementById('tfContestType').value = ['weekly', 'monthly'].includes(contestType) ? contestType : 'none';
    document.getElementById('tfContestWeekday').value = String(test?.contestWeekday || 1);
    document.getElementById('tfContestMonthDay').value = String(test?.contestMonthDay || 1);
    syncContestFormFields();
    const list = document.getElementById('problemList');
    list.innerHTML = '';
    const items = test?.items || [];
    if (items.length) items.forEach((q) => addProblemToForm(q));
    else addProblemToForm(emptyProblem());
    testFormModal.show();
  }

  async function showBankPicker() {
    bank = await CodingService.listBank();
    const list = document.getElementById('bankPickList');
    if (!list) return;
    list.innerHTML = bank.length
      ? bank.map((q) => `<label class="border rounded-3 p-2 d-flex gap-2 align-items-start"><input type="checkbox" value="${esc(q.id)}"/><span><strong>${esc(q.title)}</strong><div class="small text-muted-2">${esc(q.difficulty || '')} · ${esc(q.marks || 2)} marks</div></span></label>`).join('')
      : '<p class="text-muted-2 mb-0">Problem bank is empty. Add problems first.</p>';
    bankPickModal.show();
  }

  async function openBulkProblems(id) {
    const t = tests.find((x) => String(x.id) === String(id));
    if (!t) return;
    openTestForm(t);
    await showBankPicker();
  }

  async function deleteTest(id) {
    if (!id || !confirm('Delete this test? This cannot be undone.')) return;
    try {
      await CodingService.deleteTest(id);
      toastMsg('Test deleted.', 'success');
      await loadManaged();
      renderManage();
    } catch (err) {
      toastMsg(err?.message || 'Could not delete test.', 'error');
    }
  }

  function renderBank() {
    const cat = document.getElementById('bankFilterCategory')?.value || '';
    fillSelect(document.getElementById('bankFilterCategory'), [{ value: '', label: 'All categories' }, ...CATEGORIES.map((c) => ({ value: c, label: c }))], cat);
    document.querySelectorAll('#bankDifficultyNav .nav-link').forEach((link) => {
      link.classList.toggle('active', (link.getAttribute('data-bank-difficulty') || '') === bankDifficultyFilter);
    });
    const rows = bank.filter((q) => {
      if (cat && String(q.category || '') !== cat) return false;
      if (bankDifficultyFilter && String(q.difficulty || 'Medium') !== bankDifficultyFilter) return false;
      return true;
    });
    const counts = { total: bank.length, Easy: 0, Medium: 0, Hard: 0 };
    bank.forEach((q) => {
      const d = q.difficulty || 'Medium';
      if (counts[d] != null) counts[d] += 1;
    });
    document.getElementById('bankStats').innerHTML = [
      ['Total in bank', counts.total],
      ['Easy', counts.Easy],
      ['Medium', counts.Medium],
      ['Hard', counts.Hard],
    ].map(([lbl, val]) => `<div class="col-6 col-md-3"><div class="card-surface p-3 apt-stat"><div class="small text-muted-2">${esc(lbl)}</div><div class="val">${esc(val)}</div></div></div>`).join('');
    const list = document.getElementById('bankQuestionsList');
    list.innerHTML = rows.length
      ? rows.map((q) => `
          <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div class="min-w-0">
              <div class="fw-semibold">${esc(q.title || 'Untitled problem')}</div>
              <div class="small text-muted-2">${esc(q.category || 'Programming')} · ${esc((q.testCases || []).length)} test case(s) · ${esc(q.marks || 2)} mark(s)</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge-soft ${difficultyClass(q.difficulty)}">${esc(q.difficulty || 'Medium')}</span>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bank-edit="${esc(q.id)}">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-danger" data-bank-del="${esc(q.id)}"><i class="bi bi-trash"></i></button>
            </div>
          </div>`).join('')
      : '<p class="text-muted-2 mb-0">No problems in the bank yet. Add one manually or generate with AI.</p>';
    list.querySelectorAll('[data-bank-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const q = bank.find((x) => String(x.id) === String(btn.getAttribute('data-bank-edit')));
        if (q) openBankProblemForm(q);
      });
    });
    list.querySelectorAll('[data-bank-del]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this problem from the bank?')) return;
        try {
          await CodingService.deleteBankProblem(btn.getAttribute('data-bank-del'));
          toastMsg('Problem deleted.', 'success');
          bank = await CodingService.listBank();
          renderBank();
        } catch (err) {
          toastMsg(err?.message || 'Could not delete problem.', 'error');
        }
      });
    });
  }

  function openBankProblemForm(q = null) {
    document.getElementById('bankProblemTitle').textContent = q ? 'Edit bank problem' : 'Add bank problem';
    document.getElementById('bpId').value = q?.id || '';
    const host = document.getElementById('bpEditor');
    host.innerHTML = problemEditorHtml(q || emptyProblem());
    host.querySelector('[data-remove-problem]')?.classList.add('d-none');
    bankProblemModal.show();
  }

  function progressDirTitle(role, panel = progressPanel) {
    const contest = panel === 'contests';
    const map = {
      placement_officer: contest ? 'Department contest results' : 'Department test results',
      staff: contest ? 'Class contest results' : 'Class test results',
      admin: contest ? 'Institution contest results' : 'Institution test results',
    };
    return map[role] || (contest ? 'Contest results' : 'Test results');
  }

  function studentIdLabel(r) {
    return r.registerNumber || r.studentCode || r.studentId || '—';
  }

  function categoryShort(r) {
    const cats = r.categoryPerformance || r.categoryWise || {};
    const entries = Object.entries(cats);
    if (!entries.length) return '—';
    return entries.map(([k, v]) => {
      const pct = (v && typeof v === 'object') ? (v.percentage ?? 0) : v;
      return `${k}: ${pct}%`;
    }).join(' · ');
  }

  function renderDirectoryTable(rows, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.add('d-none');
    document.getElementById('dirStats').innerHTML = [
      ['Students', summary.students ?? 0],
      ['With attempts', summary.withAttempts ?? 0],
      ['Total attempts', summary.totalAttempts ?? 0],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Avg best', `${summary.avgBestScore ?? 0}%`],
      ['Highest best', `${summary.highestBestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md-2"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');
    const emptyMsg = Auth.role() === 'staff' && !staffAssignedBatches().length && !(scope.assignedClassBatches || []).length
      ? 'No class is assigned to your account. Contact the placement office to monitor student coding progress.'
      : 'No test results in your authorized scope yet.';
    document.getElementById('dirRows').innerHTML = rows.length ? rows.map((r) => {
      const uid = String(r.userId || '');
      return `<tr>
        <td class="fw-semibold">${esc(r.name)}</td>
        <td>${esc(studentIdLabel(r))}</td>
        <td>${esc(r.classBatch || '—')}</td>
        <td>${esc(r.testsAttempted ?? r.attempts ?? 0)}</td>
        <td>${esc(r.averageScore ?? r.percentage ?? 0)}%</td>
        <td>${esc(r.bestScore ?? 0)}%</td>
        <td>${esc(r.accuracy ?? 0)}%</td>
        <td>${esc(r.recentScore ?? 0)}%</td>
        <td class="small">${esc(categoryShort(r))}</td>
        <td>${uid ? `<button type="button" class="btn btn-sm btn-outline-primary" data-detail="${esc(uid)}">View</button>` : ''}</td>
      </tr>`;
    }).join('') : `<tr><td colspan="10" class="text-muted-2 p-3">${emptyMsg}</td></tr>`;
    document.querySelectorAll('[data-detail]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentDetail(btn.getAttribute('data-detail')));
    });
  }

  function renderContestResults(contests, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.add('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirStats').innerHTML = [
      ['Contests', contests.length],
      ['Participants', summary.withAttempts ?? 0],
      ['Total attempts', summary.totalAttempts ?? 0],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Avg best', `${summary.avgBestScore ?? 0}%`],
      ['Highest best', `${summary.highestBestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md-2"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');
    const root = document.getElementById('dirContestSections');
    if (!contests.length) {
      root.innerHTML = `<p class="text-muted-2 mb-0">${Auth.role() === 'staff' && !staffAssignedBatches().length ? 'No class is assigned to your account.' : 'No contest results in your authorized scope yet.'}</p>`;
      return;
    }
    root.innerHTML = contests.map((c) => `
      <div>
        <h6 class="fw-bold mb-2">${esc(c.title)}</h6>
        <div class="table-wrap"><table class="table-modern"><thead><tr>
          <th>Name</th><th>Student ID</th><th>Score</th><th>Status</th>
        </tr></thead><tbody>
          ${(c.participants || []).map((p) => `<tr><td>${esc(p.name)}</td><td>${esc(studentIdLabel(p))}</td><td>${esc(p.percentage ?? p.score ?? 0)}%</td><td>${esc(p.status || '')}</td></tr>`).join('') || '<tr><td colspan="4" class="text-muted-2 p-3">No participants yet.</td></tr>'}
        </tbody></table></div>
      </div>`).join('');
  }

  function demoDirectoryFromLocal() {
    const u = Auth.user() || {};
    return (async () => {
      const mine = await CodingService.getProgress();
      const hist = mine.history || [];
      if (!hist.length) {
        return { rows: [], summary: { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 } };
      }
      const percents = hist.map((h) => Number(h.percentage) || 0);
      const avg = percents.length ? Math.round(percents.reduce((a, b) => a + b, 0) / percents.length) : 0;
      const best = percents.length ? Math.max(...percents) : 0;
      return {
        rows: [{
          userId: u.id || 'me',
          name: u.name || 'You',
          registerNumber: u.registerNumber || u.studentId || '—',
          classBatch: u.classBatch || '—',
          testsAttempted: hist.length,
          averageScore: avg,
          bestScore: best,
          accuracy: avg,
          recentScore: percents[0] || 0,
          categoryPerformance: {},
        }],
        summary: {
          students: 1,
          withAttempts: 1,
          totalAttempts: hist.length,
          avgPercentage: avg,
          avgBestScore: best,
          highestBestScore: best,
        },
      };
    })();
  }

  async function openStudentDetail(userId) {
    const body = document.getElementById('studentCodBody');
    body.innerHTML = '<p class="text-muted-2 mb-0">Loading…</p>';
    studentCodModal?.show();
    let data = null;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/coding/subjects/${encodeURIComponent(userId)}`).catch(() => null);
      if (res?.success) data = res.data;
    }
    if (!data) {
      try {
        const mine = await CodingService.getProgress();
        data = { name: Auth.user()?.name || 'Student', history: mine.history || [] };
      } catch {
        data = { name: 'Student', history: [] };
      }
    }
    const hist = data.history || [];
    body.innerHTML = `
      <div class="fw-semibold mb-1">${esc(data.name || 'Student')}</div>
      <div class="small text-muted-2 mb-3">${esc(data.registerNumber || '')}${data.classBatch ? ' · ' + esc(data.classBatch) : ''}</div>
      ${hist.length ? hist.map((h) => {
        const contest = String(h.contestType || '') === 'weekly' || String(h.contestType || '') === 'monthly';
        return `<div class="d-flex justify-content-between align-items-start border-bottom py-2 gap-2">
          <div><div>${esc(h.testTitle || 'Coding test')}</div><div class="small text-muted-2">${esc(h.submittedAt || '')}${contest ? ' · Contest' : ''}</div></div>
          <div class="text-end"><div class="fw-semibold">${esc(h.percentage ?? 0)}%</div><div class="small text-muted-2">${esc(h.status || '')}</div></div>
        </div>`;
      }).join('') : '<p class="text-muted-2 mb-0">No coding attempts yet.</p>'}`;
  }

  function applyDirDepartmentFromData(departments) {
    const select = document.getElementById('fDepartmentSelect');
    const hidden = document.getElementById('fDepartment');
    const label = document.getElementById('fDepartmentLabel');
    if (Auth.role() === 'admin') {
      if (select && !select.dataset.filled) {
        select.innerHTML = '<option value="">All departments</option>' + departments.map((d) => `<option value="${esc(d.id)}">${esc(d.name || d.code || d.id)}</option>`).join('');
        select.dataset.filled = '1';
      }
    } else {
      const first = departments[0] || {};
      if (label) label.value = first.name || first.code || access.scope?.departmentName || '';
      if (hidden) hidden.value = first.id || access.scope?.departmentId || '';
    }
  }

  async function loadDirFilterOptions() {
    const role = Auth.role();
    document.getElementById('fDepartmentLabel')?.classList.toggle('d-none', role === 'admin');
    document.getElementById('fDepartmentSelect')?.classList.toggle('d-none', role !== 'admin');
    document.getElementById('fTypeWrap')?.classList.toggle('d-none', role !== 'admin');
    let data = null;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      data = await api('/coding/progress/filters?' + new URLSearchParams({
        department: document.getElementById('fDepartment')?.value || '',
        course: dirFilterBranch,
        class: dirFilterBatch,
      }).toString()).then((r) => r?.success ? r.data : null).catch(() => null);
      if (!data) {
        data = await api('/aptitude/progress/filters?' + new URLSearchParams({
          department: document.getElementById('fDepartment')?.value || '',
          course: dirFilterBranch,
          class: dirFilterBatch,
        }).toString()).then((r) => r?.success ? r.data : null).catch(() => null);
      }
    }
    if (!data) {
      data = { departments: [], branches: [], batches: staffAssignedBatches(), types: [] };
    }
    applyDirDepartmentFromData(data.departments || []);
    fillSelect(document.getElementById('fBranch'), [{ value: '', label: 'All branches' }, ...(data.branches || []).map((b) => ({ value: b, label: b }))], dirFilterBranch);
    fillSelect(document.getElementById('fBatch'), [{ value: '', label: 'All batches' }, ...(data.batches || []).map((b) => ({ value: b, label: b }))], dirFilterBatch);
  }

  let dirFiltersReady = false;
  async function initDirFilters() {
    if (dirFiltersReady) return;
    dirFiltersReady = true;
    await loadDirFilterOptions();
    document.getElementById('fDepartmentSelect')?.addEventListener('change', async () => {
      document.getElementById('fDepartment').value = document.getElementById('fDepartmentSelect').value;
      dirFilterBranch = '';
      dirFilterBatch = '';
      await loadDirFilterOptions();
      await loadDirectory();
    });
    document.getElementById('fBranch')?.addEventListener('change', async () => {
      dirFilterBranch = document.getElementById('fBranch').value;
      dirFilterBatch = '';
      await loadDirFilterOptions();
      await loadDirectory();
    });
    document.getElementById('fBatch')?.addEventListener('change', async () => {
      dirFilterBatch = document.getElementById('fBatch').value;
      await loadDirectory();
    });
    document.getElementById('fType')?.addEventListener('change', () => loadDirectory());
  }

  function buildDirectoryQuery() {
    const qs = new URLSearchParams();
    const dept = document.getElementById('fDepartment')?.value || '';
    if (dept) qs.set('department', dept);
    if (dirFilterBranch) qs.set('course', dirFilterBranch);
    if (dirFilterBatch) qs.set('class', dirFilterBatch);
    const type = document.getElementById('fType')?.value || '';
    if (type) qs.set('userType', type);
    qs.set('resultType', progressPanel === 'contests' ? 'contests' : 'tests');
    return qs;
  }

  function updateDirScopeHint(scope) {
    const hint = document.getElementById('dirScopeHint');
    if (!hint) return;
    const role = Auth.role();
    if (role === 'staff') {
      const batches = scope.assignedClassBatches || staffAssignedBatches();
      hint.textContent = batches.length ? `Showing students in ${batches.join(', ')}.` : '';
      hint.classList.toggle('d-none', !hint.textContent);
    } else if (role === 'placement_officer') {
      hint.textContent = scope.departmentName ? `Showing ${scope.departmentName} students.` : '';
      hint.classList.toggle('d-none', !hint.textContent);
    } else {
      hint.classList.add('d-none');
    }
  }

  async function loadDirectory() {
    if (!access.canViewDirectory) return;
    const role = Auth.role();
    document.getElementById('dirTitle').textContent = progressDirTitle(role, progressPanel);
    let scope = access.scope || {};
    updateDirScopeHint(scope);
    try {
      const live = await CodingService.directory(buildDirectoryQuery());
      if (live?.scope) {
        access.scope = live.scope;
        scope = live.scope;
        updateDirScopeHint(scope);
      }
      if (live && (progressPanel === 'contests' || live.view === 'contests')) {
        renderContestResults(live.contests || [], live.summary || {}, scope);
        return;
      }
      renderDirectoryTable(live?.rows || [], live?.summary || {}, scope);
    } catch (err) {
      toastMsg(err?.message || 'Could not load coding progress.', 'error');
      if (progressPanel === 'contests') {
        renderContestResults([], { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 }, scope);
      } else {
        renderDirectoryTable([], { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 }, scope);
      }
    }
  }

  function showCodAiForm() {
    document.getElementById('codAiFormPanel')?.classList.remove('d-none');
    document.getElementById('codAiPreviewPanel')?.classList.add('d-none');
  }

  function openCodAiModal() {
    fillSelect(document.getElementById('codAiCategory'), CATEGORIES, 'Programming');
    fillSelect(document.getElementById('codAiDifficulty'), DIFFICULTIES, 'Medium');
    showCodAiForm();
    document.getElementById('codAiPreviewList').innerHTML = '';
    document.getElementById('codAiGenerateStatus')?.classList.add('d-none');
    document.getElementById('codAiSaveStatus')?.classList.add('d-none');
    codAiModal?.show();
  }

  function collectCodAiParams() {
    return {
      category: document.getElementById('codAiCategory')?.value || 'Programming',
      topic: (document.getElementById('codAiTopic')?.value || '').trim() || (document.getElementById('codAiCategory')?.value || 'Programming'),
      difficulty: document.getElementById('codAiDifficulty')?.value || 'Medium',
      count: Number(document.getElementById('codAiCount')?.value || 5),
      instructions: document.getElementById('codAiInstructions')?.value || '',
    };
  }

  function renderCodAiPreview() {
    const list = document.getElementById('codAiPreviewList');
    const countEl = document.getElementById('codAiPreviewCount');
    if (countEl) countEl.textContent = String(aiPreviewProblems.length);
    if (!list) return;
    list.innerHTML = aiPreviewProblems.map((q, i) => `
      <label class="border rounded-3 p-3 d-flex gap-2 align-items-start">
        <input class="form-check-input mt-1" type="checkbox" data-ai-idx="${i}" ${q.selected ? 'checked' : ''}/>
        <div class="min-w-0">
          <div class="fw-semibold">${esc(q.title || 'Untitled')}</div>
          <div class="small text-muted-2">${esc(q.difficulty || '')} · ${esc(q.category || '')} · ${esc(q.marks || 2)} marks</div>
          <div class="small mt-1">${esc((q.description || '').slice(0, 180))}${(q.description || '').length > 180 ? '…' : ''}</div>
        </div>
      </label>`).join('');
    list.querySelectorAll('[data-ai-idx]').forEach((el) => {
      el.addEventListener('change', () => {
        const i = Number(el.getAttribute('data-ai-idx'));
        if (aiPreviewProblems[i]) aiPreviewProblems[i].selected = el.checked;
      });
    });
  }

  async function runCodAiGenerate() {
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      toastMsg('Sign in as a placement officer to generate problems.', 'info');
      return;
    }
    const params = collectCodAiParams();
    if (!params.topic) {
      toastMsg('Enter a topic.', 'error');
      return;
    }
    const status = document.getElementById('codAiGenerateStatus');
    const btn = document.getElementById('btnCodAiRun');
    status?.classList.remove('d-none');
    btn?.setAttribute('disabled', 'disabled');
    try {
      const data = await CodingService.generateAiProblems(params);
      aiPreviewProblems = (data.problems || data.questions || []).map((q) => ({ ...q, selected: q.selected !== false }));
      aiLastFormParams = params;
      if (!aiPreviewProblems.length) {
        toastMsg('No problems were generated.', 'error');
        return;
      }
      document.getElementById('codAiFormPanel')?.classList.add('d-none');
      document.getElementById('codAiPreviewPanel')?.classList.remove('d-none');
      renderCodAiPreview();
    } catch (err) {
      toastMsg(err?.message || 'AI generation failed.', 'error');
    } finally {
      status?.classList.add('d-none');
      btn?.removeAttribute('disabled');
    }
  }

  async function saveCodAiSelected() {
    const selected = aiPreviewProblems.filter((q) => q.selected);
    if (!selected.length) {
      toastMsg('Select at least one problem to save.', 'error');
      return;
    }
    const status = document.getElementById('codAiSaveStatus');
    const btn = document.getElementById('btnCodAiSave');
    status?.classList.remove('d-none');
    btn?.setAttribute('disabled', 'disabled');
    try {
      const data = await CodingService.saveAiProblems(selected);
      toastMsg(`Saved ${data?.added ?? selected.length} problem(s) to the bank.`, 'success');
      codAiModal?.hide();
      bank = await CodingService.listBank();
      applyManagePanel('bank');
      renderBank();
    } catch (err) {
      toastMsg(err?.message || 'Could not save AI problems.', 'error');
    } finally {
      status?.classList.add('d-none');
      btn?.removeAttribute('disabled');
    }
  }

  function bindUi() {
    testFormModal = document.getElementById('testFormModal') ? new bootstrap.Modal(document.getElementById('testFormModal')) : null;
    bankPickModal = document.getElementById('bankPickModal') ? new bootstrap.Modal(document.getElementById('bankPickModal')) : null;
    bankProblemModal = document.getElementById('bankProblemModal') ? new bootstrap.Modal(document.getElementById('bankProblemModal')) : null;
    studentCodModal = document.getElementById('studentCodModal') ? new bootstrap.Modal(document.getElementById('studentCodModal')) : null;
    codAiModal = document.getElementById('codAiBankModal') ? new bootstrap.Modal(document.getElementById('codAiBankModal')) : null;

    document.getElementById('codViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-view]');
      if (!link) return;
      e.preventDefault();
      applyView(link.getAttribute('data-view'));
    });
    document.getElementById('manageViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-manage-view]');
      if (!link) return;
      e.preventDefault();
      applyManagePanel(link.getAttribute('data-manage-view'));
    });
    document.getElementById('progressViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-progress-view]');
      if (!link) return;
      e.preventDefault();
      progressPanel = link.getAttribute('data-progress-view') || 'tests';
      document.querySelectorAll('#progressViewNav .nav-link').forEach((a) => {
        a.classList.toggle('active', a.getAttribute('data-progress-view') === progressPanel);
      });
      loadDirectory();
    });
    document.getElementById('myResultsNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-view]');
      if (!link) return;
      e.preventDefault();
      myResultsView = link.getAttribute('data-results-view') || 'tests';
      document.querySelectorAll('#myResultsNav .nav-link').forEach((a) => {
        a.classList.toggle('active', a.getAttribute('data-results-view') === myResultsView);
      });
      loadHub();
    });
    document.getElementById('btnNewTest')?.addEventListener('click', () => openTestForm());
    document.getElementById('btnNewWeeklyContest')?.addEventListener('click', () => openTestForm(null, { contestType: 'weekly', title: 'Weekly coding contest' }));
    document.getElementById('btnNewMonthlyContest')?.addEventListener('click', () => openTestForm(null, { contestType: 'monthly', title: 'Monthly coding contest' }));
    document.getElementById('tfContestType')?.addEventListener('change', syncContestFormFields);
    document.getElementById('btnAddProblem')?.addEventListener('click', () => addProblemToForm(emptyProblem()));
    document.getElementById('btnPickBankProblems')?.addEventListener('click', () => showBankPicker());
    document.getElementById('btnUseBankPicked')?.addEventListener('click', () => {
      const ids = [...document.querySelectorAll('#bankPickList input:checked')].map((i) => i.value);
      ids.forEach((id) => {
        const q = bank.find((x) => String(x.id) === String(id));
        if (q) addProblemToForm({ ...q, id: 'p-' + Date.now() + '-' + Math.floor(Math.random() * 99) });
      });
      bankPickModal.hide();
    });
    document.getElementById('testForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const items = [...document.querySelectorAll('#problemList [data-problem]')].map(collectProblem).filter((q) => q.title);
      if (!items.length) {
        toastMsg('Add at least one problem with a title.', 'error');
        return;
      }
      const payload = {
        id: document.getElementById('tfId').value.trim(),
        title: document.getElementById('tfTitle').value.trim(),
        description: document.getElementById('tfDescription').value.trim(),
        category: document.getElementById('tfCategory').value,
        difficulty: document.getElementById('tfDifficulty').value,
        duration: Number(document.getElementById('tfDuration').value || 20),
        status: document.getElementById('tfStatus').value,
        contestType: canManageContests() ? (document.getElementById('tfContestType').value || 'none') : 'none',
        contestWeekday: Number(document.getElementById('tfContestWeekday').value || 1),
        contestMonthDay: Number(document.getElementById('tfContestMonthDay').value || 1),
        instructions: [
          'Read each problem carefully.',
          'Select the programming language before submitting.',
          'Your code will be evaluated against test cases.',
          'Do not refresh the page during the test.',
        ],
        items,
      };
      try {
        const saved = await CodingService.saveTest(payload);
        toastMsg(isContestTest(payload) || isContestTest(saved) ? 'Contest saved.' : 'Test saved.', 'success');
        testFormModal.hide();
        await loadManaged();
        if (canManageContests() && (isContestTest(payload) || isContestTest(saved))) {
          applyManagePanel('contests');
        }
        renderManage();
      } catch (err) {
        toastMsg(err?.message || 'Could not save test.', 'error');
      }
    });
    document.getElementById('btnNewBankProblem')?.addEventListener('click', () => openBankProblemForm());
    document.getElementById('btnAiGenerate')?.addEventListener('click', () => openCodAiModal());
    document.getElementById('btnCodAiRun')?.addEventListener('click', () => runCodAiGenerate());
    document.getElementById('btnCodAiSave')?.addEventListener('click', () => saveCodAiSelected());
    document.getElementById('btnCodAiCancelPreview')?.addEventListener('click', () => showCodAiForm());
    document.getElementById('bankFilterCategory')?.addEventListener('change', () => renderBank());
    document.getElementById('bankDifficultyNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-bank-difficulty]');
      if (!link) return;
      e.preventDefault();
      bankDifficultyFilter = link.getAttribute('data-bank-difficulty') || '';
      renderBank();
    });
    document.getElementById('bankProblemForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const host = document.getElementById('bpEditor').querySelector('[data-problem]');
      if (!host) return;
      const payload = collectProblem(host);
      payload.id = document.getElementById('bpId').value.trim() || payload.id;
      payload.category = payload.category || 'Programming';
      if (!payload.title) {
        toastMsg('Enter a problem title.', 'error');
        return;
      }
      try {
        await CodingService.saveBankProblem(payload);
        toastMsg('Problem saved.', 'success');
        bankProblemModal.hide();
        bank = await CodingService.listBank();
        renderBank();
      } catch (err) {
        toastMsg(err?.message || 'Could not save problem.', 'error');
      }
    });
    window.addEventListener('hashchange', () => {
      const view = String(location.hash || '').replace('#', '');
      applyView(view);
    });
  }

  async function boot() {
    if (typeof CodingExam !== 'undefined' && CodingExam.createExamController) {
      exam = CodingExam.createExamController({
        root: document.getElementById('examShell'),
        onExit() {
          closeExam();
        },
      });
    }
    bindUi();
    await loadAccess();
    const any = access.canTake || access.canManage || access.canViewDirectory;
    document.getElementById('codDenied')?.classList.toggle('d-none', any);
    document.getElementById('codTake')?.classList.toggle('d-none', true);
    if (!any) return;
    setupViewNav();
    const hash = String(location.hash || '').replace('#', '');
    await applyView(hash || defaultView());
  }

  let bootPromise = null;
  const start = () => {
    if (bootPromise) {
      return loadAccess().then(() => {
        const any = access.canTake || access.canManage || access.canViewDirectory;
        document.getElementById('codDenied')?.classList.toggle('d-none', any);
        if (!any) return;
        setupViewNav();
        return applyView((location.hash || '').replace('#', '') || defaultView());
      });
    }
    bootPromise = boot().catch((err) => {
      toastMsg(err?.message || 'Could not load coding practice.', 'error');
    });
    return bootPromise;
  };
  start();
  if (typeof onAppReady === 'function') onAppReady(start);
})();
