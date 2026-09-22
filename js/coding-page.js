/* PlaceHub — coding practice hub (student take + admin manage/progress) */
(function () {
  if (!window.CodeExecutionService || window.CodeExecutionService.ready === false) {
    console.error('CodeExecutionService is not loaded. Include js/coding-execution.js before js/coding-service.js.');
  }

  const CATEGORIES = (typeof CodingData !== 'undefined' && CodingData.CATEGORIES) || ['Algorithms', 'Database', 'Shell', 'Concurrency', 'JavaScript', 'pandas'];
  const TOPIC_FILTERS = (typeof CodingData !== 'undefined' && CodingData.TOPIC_FILTERS) || [
    { value: '', label: 'All Topics', icon: 'bi-collection', tone: 'all' },
    { value: 'Algorithms', label: 'Algorithms', icon: 'bi-diagram-3', tone: 'algorithms' },
    { value: 'Database', label: 'Database', icon: 'bi-database', tone: 'database' },
    { value: 'Shell', label: 'Shell', icon: 'bi-terminal', tone: 'shell' },
    { value: 'Concurrency', label: 'Concurrency', icon: 'bi-shuffle', tone: 'concurrency' },
    { value: 'JavaScript', label: 'JavaScript', icon: 'bi-filetype-js', tone: 'javascript' },
    { value: 'pandas', label: 'pandas', icon: 'bi-bar-chart-line', tone: 'pandas' },
  ];
  const DIFFICULTIES = (typeof CodingData !== 'undefined' && CodingData.DIFFICULTIES) || ['Easy', 'Medium', 'Hard'];

  function normalizeCodingTopic(category) {
    if (typeof CodingData !== 'undefined' && typeof CodingData.normalizeTopic === 'function') {
      return CodingData.normalizeTopic(category);
    }
    const legacy = {
      Programming: 'Algorithms',
      Python: 'Shell',
      'Data Structures': 'Algorithms',
      'Programming Logic': 'Algorithms',
    };
    const c = String(category || '').trim();
    return legacy[c] || c || 'Algorithms';
  }
  const CONTEST_WEEKDAYS = [
    { value: 1, label: 'Monday' }, { value: 2, label: 'Tuesday' }, { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' }, { value: 5, label: 'Friday' }, { value: 6, label: 'Saturday' }, { value: 7, label: 'Sunday' },
  ];
  const DEFAULT_CONTEST_START_TIME = '09:00';
  const DEFAULT_CONTEST_END_TIME = '23:59';

  let access = { canTake: false, canManage: false, canViewDirectory: false, scope: null };
  let tests = [];
  let bank = [];
  let managePanel = 'tests';
  let manageContestType = 'weekly';
  let jdSelectedCompanyId = null;
  let adminJdBlockView = 'tests';
  let jdCompanyBlocks = [];
  let jdLibrarySets = [];
  let jdCompanies = [];
  const jdSetDetailsCache = {};
  let progressPanel = 'tests';
  let takeListPanel = 'tests';
  let takeContestType = 'weekly';
  let myResultsView = 'tests';
  let myResultsContestType = 'weekly';
  let myProgress = null;
  let studentJdCompanyBlocks = [];
  let studentJdSelectedCompanyId = null;
  let dirFilterBranch = '';
  let dirSearch = '';
  let dirSearchTimer = 0;
  let bankDifficultyFilter = '';
  let bankCategoryFilter = '';
  const selectedBankIds = new Set();
  const selectedManageTestIds = new Set();
  const selectedJdSetIds = new Set();
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

  function isCompanyTest(t) {
    if (!t) return false;
    if (String(t.testKind || '') === 'company') return true;
    return String(t.companyId || '').trim() !== '';
  }

  function isRegularTest(t) {
    return !isContestTest(t) && !isCompanyTest(t);
  }

  function syncContestTypeNav(rootId, activeType, attr) {
    document.querySelectorAll(`#${rootId} .nav-link`).forEach((link) => {
      link.classList.toggle('active', link.getAttribute(attr) === activeType);
    });
  }

  function contestTypeOf(entry) {
    const type = String(entry?.contestType || '').toLowerCase();
    if (type === 'weekly' || type === 'monthly') return type;
    const test = tests.find((t) => String(t.id) === String(entry?.testId || ''));
    const resolvedType = String(test?.contestType || '').toLowerCase();
    return resolvedType === 'monthly' ? 'monthly' : 'weekly';
  }

  function historyEntryIsCompany(h) {
    const row = h && typeof h === 'object' ? h : {};
    const kind = String(row.testKind || '').toLowerCase();
    if (kind === 'company') return true;
    if (String(row.companyId || '').trim()) return true;
    const test = tests.find((t) => String(t.id) === String(row.testId || ''));
    return isCompanyTest(test);
  }

  function renderJdCompanyGridHtml(blocks) {
    return (blocks || []).map((block) => {
      const companyId = String(block.companyId || '_unassigned');
      return `<div class="col-12 col-md-6 col-xl-4">
        <button type="button" class="card h-100 w-100 text-start border rounded-3 p-3 jd-company-card" data-jd-company-id="${esc(companyId)}">
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="fw-semibold text-truncate">${esc(block.companyName || 'Company')}</div>
            <i class="bi bi-chevron-right text-muted-2 flex-shrink-0"></i>
          </div>
        </button>
      </div>`;
    }).join('');
  }

  function studentCompanyTestsFor(companyId) {
    const id = String(companyId || '');
    return tests.filter((t) => {
      if (!isCompanyTest(t)) return false;
      if (String(t.companyId || '') !== id) return false;
      if (access.canManage) return true;
      return (t.status || 'published') === 'published';
    });
  }

  function stripHtml(text) {
    const div = document.createElement('div');
    div.innerHTML = String(text || '');
    return (div.textContent || div.innerText || '').trim();
  }

  function normalizeContestTimeClient(raw, fallback) {
    const m = String(raw || '').trim().match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return fallback;
    const h = Number(m[1]);
    const min = Number(m[2]);
    if (h < 0 || h > 23 || min < 0 || min > 59) return fallback;
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`;
  }

  function contestTimesClient(test) {
    return {
      start: normalizeContestTimeClient(test?.contestStartTime, DEFAULT_CONTEST_START_TIME),
      end: normalizeContestTimeClient(test?.contestEndTime, DEFAULT_CONTEST_END_TIME),
    };
  }

  function applyContestTimesToDate(date, test) {
    const { start, end } = contestTimesClient(test);
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    const startDt = new Date(date);
    startDt.setHours(sh, sm, 0, 0);
    const endDt = new Date(date);
    endDt.setHours(eh, em, 59, 999);
    if (endDt <= startDt) endDt.setTime(startDt.getTime() + 60 * 60 * 1000);
    return { start: startDt, end: endDt };
  }

  function parseContestCreatedAt(test) {
    const raw = test?.createdAt;
    if (!raw) return null;
    const d = new Date(raw);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function lastWeeklyOccurrenceStart(want, now = new Date()) {
    const today = now.getDay() === 0 ? 7 : now.getDay();
    const daysSince = (today - want + 7) % 7;
    const d = new Date(now);
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - daysSince);
    return d;
  }

  function lastMonthlyOccurrenceStart(want, now = new Date()) {
    const dom = now.getDate();
    let year = now.getFullYear();
    let month = now.getMonth();
    if (dom < want) {
      month -= 1;
      if (month < 0) {
        month = 11;
        year -= 1;
      }
    }
    return new Date(year, month, want, 0, 0, 0);
  }

  function contestStatusClient(test) {
    if (!isContestTest(test)) return test?.contestStatus ? String(test.contestStatus) : 'ACTIVE';
    if (typeof test?.contestOpen === 'boolean') return test.contestOpen ? 'ACTIVE' : 'UPCOMING';
    const type = String(test?.contestType || 'none');
    const now = new Date();
    const created = parseContestCreatedAt(test);
    if (type === 'weekly') {
      const want = Number(test?.contestWeekday);
      if (!Number.isFinite(want) || want < 1 || want > 7) return 'UPCOMING';
      const today = now.getDay() === 0 ? 7 : now.getDay();
      if (today === want) {
        const occ = lastWeeklyOccurrenceStart(want, now);
        const { start, end } = applyContestTimesToDate(occ, test);
        if (now < start) return 'UPCOMING';
        return now <= end ? 'ACTIVE' : 'COMPLETED';
      }
      const daysSince = (today - want + 7) % 7;
      if (daysSince >= 1 && daysSince <= 3) {
        const lastOcc = lastWeeklyOccurrenceStart(want, now);
        if (created && created <= lastOcc) return 'COMPLETED';
        return 'UPCOMING';
      }
      return 'UPCOMING';
    }
    if (type === 'monthly') {
      const want = Number(test?.contestMonthDay);
      if (!Number.isFinite(want) || want < 1 || want > 28) return 'UPCOMING';
      const dom = now.getDate();
      if (dom === want) {
        const occ = lastMonthlyOccurrenceStart(want, now);
        const { start, end } = applyContestTimesToDate(occ, test);
        if (now < start) return 'UPCOMING';
        return now <= end ? 'ACTIVE' : 'COMPLETED';
      }
      if (dom > want) {
        const lastOcc = lastMonthlyOccurrenceStart(want, now);
        if (created && created <= lastOcc) return 'COMPLETED';
        return 'UPCOMING';
      }
      return 'UPCOMING';
    }
    return 'UPCOMING';
  }

  function groupJdSetsIntoBlocks(sets) {
    const blocks = new Map();
    (sets || []).forEach((set) => {
      const companyId = String(set.companyId || '');
      const companyName = String(set.companyName || (companyId ? 'Company' : 'Unassigned'));
      const key = companyId || '_unassigned';
      if (!blocks.has(key)) {
        blocks.set(key, { companyId, companyName, setCount: 0, questionCount: 0, sets: [] });
      }
      const block = blocks.get(key);
      block.setCount += 1;
      block.questionCount += Number(set.problemCount || set.questionCount || 0);
      block.sets.push(set);
    });
    return [...blocks.values()].sort((a, b) => String(a.companyName).localeCompare(String(b.companyName)));
  }

  async function ensureJdCompaniesLoaded() {
    if (jdCompanies.length) return jdCompanies;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/coding/company-block/companies').catch(() => null);
      jdCompanies = res?.data?.companies || [];
    }
    return jdCompanies;
  }

  async function getJdSetDetail(setId) {
    const id = String(setId || '');
    if (!id) return null;
    if (jdSetDetailsCache[id]) return jdSetDetailsCache[id];
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/coding/company-block/sets/${encodeURIComponent(id)}`).catch(() => null);
      if (res?.data) jdSetDetailsCache[id] = res.data;
    }
    return jdSetDetailsCache[id] || null;
  }

  async function loadJdLibrary() {
    if (!access.canManage) return;
    await ensureJdCompaniesLoaded().catch(() => {});
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/coding/company-block').catch(() => null);
      jdLibrarySets = res?.data?.sets || [];
      jdCompanyBlocks = res?.data?.blocks || groupJdSetsIntoBlocks(jdLibrarySets);
    } else {
      jdLibrarySets = [];
      jdCompanyBlocks = groupJdSetsIntoBlocks([]);
    }
    renderJdBlock();
  }

  function jdDocumentUrl(detail) {
    return String(detail?.jdFileUrl || detail?.jdFileDataUrl || '');
  }

  function isJdImageDocument(detail) {
    const mime = String(detail?.jdMimeType || '');
    if (mime.startsWith('image/')) return true;
    return /\.(jpg|jpeg|png)$/i.test(String(detail?.jdFilename || ''));
  }

  function renderJdDocumentPanel(detail) {
    const url = jdDocumentUrl(detail);
    if (!url) return '<p class="small text-muted-2 mb-0">No uploaded document for this set.</p>';
    const fn = esc(detail.jdFilename || 'JD document');
    if (isJdImageDocument(detail)) {
      return `<div class="border rounded-2 p-2 bg-light">
        <div class="small text-muted-2 mb-2">${fn}</div>
        <img src="${esc(url)}" alt="${fn}" class="img-fluid rounded border" style="max-height:480px;object-fit:contain"/>
      </div>`;
    }
    return `<div class="border rounded-2 p-2 bg-light">
      <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <span class="small text-muted-2">${fn}</span>
        <a href="${esc(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">Open in new tab</a>
      </div>
      <iframe src="${esc(url)}" class="w-100 rounded border" style="height:480px" title="${fn}"></iframe>
    </div>`;
  }

  function renderCodingProblemDetailHtml(p, index) {
    const desc = String(p.description || '').trim();
    const meta = [normalizeCodingTopic(p.category), p.difficulty || 'Medium', `${p.marks || 2} marks`].filter(Boolean).join(' · ');
    return `<div class="border rounded-2 p-3 bg-white">
      <div class="fw-semibold mb-1">P${index + 1} · ${esc(p.title || 'Problem')}</div>
      <div class="small text-muted-2 mb-2">${esc(meta)}</div>
      ${desc ? `<div class="apt-q-card-text small mb-0">${esc(desc)}</div>` : '<p class="small text-muted-2 mb-0">No description.</p>'}
    </div>`;
  }

  function companySetTitle(set) {
    return set?.setTitle || set?.jdTitle || 'Untitled set';
  }

  function companySetProblemCount(set) {
    return Number(set?.problemCount ?? set?.questionCount ?? 0);
  }

  function renderJdSetCardsHtml(sets, { selectable = false } = {}) {
    return (sets || []).map((set) => {
      const id = String(set.id || '');
      const hasDoc = !!(set.hasDocument || set.jdFileUrl);
      const checked = selectedJdSetIds.has(id);
      return `<div class="border rounded-3 p-3" data-jd-set-card="${esc(id)}">
        <div class="d-flex align-items-start justify-content-between gap-2">
          ${selectable ? `<input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-jd-select="${esc(id)}" ${checked ? 'checked' : ''} aria-label="Select JD set"/>` : ''}
          <div class="min-w-0">
            <div class="fw-semibold">${esc(companySetTitle(set))}</div>
            <div class="small text-muted-2 mt-1">${esc(companySetProblemCount(set))} problem(s)${set.jdFilename ? ` · ${esc(set.jdFilename)}` : ''}</div>
          </div>
          <div class="d-flex gap-2 flex-shrink-0">
            ${hasDoc ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-jd-doc="${esc(id)}">Document</button>` : ''}
            <button type="button" class="btn btn-sm btn-outline-primary" data-jd-view="${esc(id)}">Problems</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-jd-delete="${esc(id)}" title="Delete"><i class="bi bi-trash"></i></button>
          </div>
        </div>
        <div class="d-none mt-3" data-jd-doc-panel="${esc(id)}"></div>
        <div class="d-none mt-3" data-jd-questions="${esc(id)}"></div>
      </div>`;
    }).join('');
  }

  function visibleBankProblems() {
    return bank.filter((q) => {
      if (bankCategoryFilter && normalizeCodingTopic(q.category) !== bankCategoryFilter) return false;
      if (bankDifficultyFilter && String(q.difficulty || 'Medium') !== bankDifficultyFilter) return false;
      return true;
    });
  }

  function renderBankTopicNav() {
    const nav = document.getElementById('bankTopicNav');
    if (!nav) return;
    nav.innerHTML = TOPIC_FILTERS.map((topic) => {
      const active = bankCategoryFilter === topic.value;
      return `<li><button type="button" class="cod-topic-pill cod-topic-${esc(topic.tone)}${active ? ' active' : ''}" data-bank-topic="${esc(topic.value)}" aria-pressed="${active ? 'true' : 'false'}">
        <i class="bi ${esc(topic.icon)} cod-topic-icon" aria-hidden="true"></i>
        <span>${esc(topic.label)}</span>
      </button></li>`;
    }).join('');
  }

  function updateBankSelectionToolbar() {
    const count = selectedBankIds.size;
    document.getElementById('bankSelectedCount') && (document.getElementById('bankSelectedCount').textContent = `${count} selected`);
    document.getElementById('btnBankDeleteSelected')?.classList.toggle('d-none', count === 0);
    const visibleIds = visibleBankProblems().map((q) => String(q.id || '')).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedBankIds.has(id));
    const selectAll = document.getElementById('bankSelectAllVisible');
    if (selectAll) {
      selectAll.indeterminate = count > 0 && !allVisibleSelected;
      selectAll.checked = allVisibleSelected;
    }
  }

  function updateManageTestsSelectionToolbar() {
    const count = selectedManageTestIds.size;
    document.getElementById('manageTestsSelectedCount') && (document.getElementById('manageTestsSelectedCount').textContent = `${count} selected`);
    document.getElementById('btnManageTestsDeleteSelected')?.classList.toggle('d-none', count === 0);
    const visibleIds = tests.filter((t) => isRegularTest(t)).map((t) => String(t.id || '')).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedManageTestIds.has(id));
    const selectAll = document.getElementById('manageTestsSelectAllVisible');
    if (selectAll) {
      selectAll.indeterminate = count > 0 && !allVisibleSelected;
      selectAll.checked = allVisibleSelected;
    }
  }

  function updateContestSelectionToolbar(contestType) {
    const prefix = contestType === 'monthly' ? 'manageMonthlyContests' : 'manageWeeklyContests';
    const count = [...selectedManageTestIds].filter((id) => {
      const t = tests.find((x) => String(x.id) === id);
      return t && String(t.contestType) === contestType;
    }).length;
    document.getElementById(`${prefix}SelectedCount`) && (document.getElementById(`${prefix}SelectedCount`).textContent = `${count} selected`);
    document.getElementById(`btn${prefix.charAt(0).toUpperCase()}${prefix.slice(1)}DeleteSelected`)?.classList.toggle('d-none', count === 0);
    const visibleIds = tests.filter((t) => String(t.contestType) === contestType && isContestManageActive(t)).map((t) => String(t.id || '')).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedManageTestIds.has(id));
    const selectAll = document.getElementById(`${prefix}SelectAllVisible`);
    if (selectAll) {
      selectAll.indeterminate = count > 0 && !allVisibleSelected;
      selectAll.checked = allVisibleSelected;
    }
  }

  function updateJdSelectionToolbar(sets) {
    const count = selectedJdSetIds.size;
    document.getElementById('jdSelectedCount') && (document.getElementById('jdSelectedCount').textContent = `${count} selected`);
    document.getElementById('btnJdDeleteSelected')?.classList.toggle('d-none', count === 0);
    const visibleIds = (sets || []).map((s) => String(s.id || '')).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedJdSetIds.has(id));
    const selectAll = document.getElementById('jdSelectAllVisible');
    if (selectAll) {
      selectAll.indeterminate = count > 0 && !allVisibleSelected;
      selectAll.checked = allVisibleSelected;
    }
  }

  function bindJdSetCardEvents(root) {
    if (!root) return;
    root.querySelectorAll('[data-jd-select]').forEach((pick) => {
      pick.addEventListener('change', () => {
        const id = pick.getAttribute('data-jd-select');
        if (!id) return;
        if (pick.checked) selectedJdSetIds.add(String(id));
        else selectedJdSetIds.delete(String(id));
        const block = jdCompanyBlocks.find((b) => String(b.companyId || '') === String(jdSelectedCompanyId || ''));
        updateJdSelectionToolbar(block?.sets || []);
      });
    });
    root.querySelectorAll('[data-jd-doc]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-jd-doc');
        const card = btn.closest('[data-jd-set-card]');
        const panel = card?.querySelector('[data-jd-doc-panel]');
        if (!panel) return;
        if (!panel.classList.contains('d-none')) {
          panel.classList.add('d-none');
          btn.textContent = 'Document';
          return;
        }
        const detail = await getJdSetDetail(id);
        panel.innerHTML = renderJdDocumentPanel(detail || {});
        panel.classList.remove('d-none');
        btn.textContent = 'Hide doc';
      });
    });
    root.querySelectorAll('[data-jd-view]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-jd-view');
        const card = btn.closest('[data-jd-set-card]');
        const panel = card?.querySelector('[data-jd-questions]');
        if (!panel) return;
        if (!panel.classList.contains('d-none')) {
          panel.classList.add('d-none');
          btn.textContent = 'Problems';
          return;
        }
        const detail = await getJdSetDetail(id);
        const qs = detail?.problems || detail?.questions || [];
        panel.innerHTML = qs.length
          ? `<div class="d-flex flex-column gap-3">${qs.map((q, i) => renderCodingProblemDetailHtml(q, i)).join('')}</div>`
          : '<p class="small text-muted-2 mb-0">No problems in this set.</p>';
        panel.classList.remove('d-none');
        btn.textContent = 'Hide';
      });
    });
    root.querySelectorAll('[data-jd-delete]').forEach((btn) => {
      btn.addEventListener('click', () => deleteJdSet(btn.getAttribute('data-jd-delete')));
    });
  }

  async function deleteJdSet(id) {
    if (!id || !confirm('Delete this company problem set and all its problems?')) return;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      toastMsg('Company problem sets require a live session.', 'info');
      return;
    }
    const res = await api(`/coding/company-block/sets/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
    if (!res?.success) {
      toastMsg(res?.message || 'Could not delete company problem set.', 'error');
      return;
    }
    delete jdSetDetailsCache[String(id)];
    jdLibrarySets = jdLibrarySets.filter((s) => String(s.id) !== String(id));
    toastMsg('Company problem set deleted.', 'success');
    await loadJdLibrary();
  }

  function syncAdminJdBlockViewNav() {
    document.querySelectorAll('#adminJdBlockViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-admin-jd-block-view') === adminJdBlockView);
    });
  }

  function applyAdminJdBlockView(view) {
    adminJdBlockView = view === 'bank' ? 'bank' : 'tests';
    syncAdminJdBlockViewNav();
    if (jdSelectedCompanyId) showJdCompanyDetail(jdSelectedCompanyId);
    else renderJdBlock();
  }

  function showJdCompanyGrid() {
    jdSelectedCompanyId = null;
    document.getElementById('jdBlockCompanyDetail')?.classList.add('d-none');
    document.getElementById('jdBlockCompanyView')?.classList.remove('d-none');
    document.getElementById('btnJdBlockBack')?.classList.add('d-none');
    document.getElementById('adminJdBlockViewNav')?.classList.add('d-none');
    document.getElementById('btnNewCompanyTest')?.classList.add('d-none');
  }

  function showJdCompanyDetail(companyId) {
    const block = jdCompanyBlocks.find((b) => {
      const id = String(b.companyId || '');
      return (id || '_unassigned') === String(companyId || '_unassigned');
    });
    jdSelectedCompanyId = companyId;
    document.getElementById('jdBlockCompanyView')?.classList.add('d-none');
    document.getElementById('jdBlockCompanyDetail')?.classList.remove('d-none');
    document.getElementById('btnJdBlockBack')?.classList.remove('d-none');
    document.getElementById('adminJdBlockViewNav')?.classList.remove('d-none');
    syncAdminJdBlockViewNav();
    const showTests = adminJdBlockView === 'tests';
    const testsRoot = document.getElementById('jdBlockCompanyTests');
    const bankSection = document.getElementById('jdBlockBankSection');
    testsRoot?.classList.toggle('d-none', !showTests);
    bankSection?.classList.toggle('d-none', showTests);
    document.getElementById('btnNewCompanyTest')?.classList.toggle('d-none', !showTests);
    if (showTests) {
      const companyTests = studentCompanyTestsFor(companyId);
      if (testsRoot) {
        testsRoot.innerHTML = companyTests.length
          ? companyTests.map((t) => renderManageRow(t, { showCompanyBadge: true, selectable: true })).join('')
          : '<p class="small text-muted-2 mb-0">No company tests for this company yet.</p>';
        bindManageListActions(testsRoot);
      }
    } else {
      const list = document.getElementById('jdBlockSetsList');
      const sets = block?.sets || [];
      if (!list) return;
      const bulkBar = document.getElementById('jdBulkActions');
      if (!sets.length) {
        bulkBar?.classList.add('d-none');
        list.innerHTML = '<p class="text-muted-2 mb-0">No company problem sets for this company yet.</p>';
        updateJdSelectionToolbar([]);
        return;
      }
      bulkBar?.classList.remove('d-none');
      list.innerHTML = renderJdSetCardsHtml(sets, { selectable: true });
      bindJdSetCardEvents(list);
      updateJdSelectionToolbar(sets);
    }
  }

  function renderJdBlock() {
    const grid = document.getElementById('jdBlockCompanyGrid');
    if (!grid) return;
    const activeCompanyId = jdSelectedCompanyId;
    if (!jdCompanyBlocks.length) {
      showJdCompanyGrid();
      grid.innerHTML = '<div class="col-12"><p class="text-muted-2 mb-0">No coding company block entries yet. Add company tests or problem sets from the Company Block tab.</p></div>';
      return;
    }
    grid.innerHTML = renderJdCompanyGridHtml(jdCompanyBlocks);
    grid.querySelectorAll('[data-jd-company-id]').forEach((btn) => {
      btn.addEventListener('click', () => showJdCompanyDetail(btn.getAttribute('data-jd-company-id')));
    });
    if (activeCompanyId) showJdCompanyDetail(activeCompanyId);
    else showJdCompanyGrid();
  }

  function formIsCompanyTest() {
    return String(document.getElementById('tfTestKind')?.value || '') === 'company';
  }

  function selectedCompanyFromTestForm() {
    const sel = document.getElementById('tfCompanyId');
    const companyId = String(sel?.value || '').trim();
    const companyName = String(sel?.selectedOptions?.[0]?.textContent || '').trim();
    return { companyId, companyName: companyName === 'Select company…' ? '' : companyName };
  }

  async function fillTfCompanySelect(selectedId = '') {
    await ensureJdCompaniesLoaded().catch(() => {});
    const sel = document.getElementById('tfCompanyId');
    if (!sel) return;
    const pick = String(selectedId || '');
    sel.innerHTML = `<option value="">Select company…</option>${jdCompanies.map((c) => {
      const id = String(c.id || '');
      return `<option value="${esc(id)}"${id === pick ? ' selected' : ''}>${esc(c.name || 'Company')}</option>`;
    }).join('')}`;
  }

  function syncCompanyTestFormUi() {
    const company = formIsCompanyTest();
    document.getElementById('tfCompanyPanel')?.classList.toggle('d-none', !company);
    document.getElementById('tfContestWrap')?.classList.toggle('d-none', company || !canManageContests());
    if (company) document.getElementById('tfContestType').value = 'none';
    syncContestFormFields();
  }

  function showStudentJdCompanyDetail(companyId) {
    studentJdSelectedCompanyId = companyId;
    document.getElementById('studentJdBlockCompanyView')?.classList.add('d-none');
    document.getElementById('studentJdBlockCompanyDetail')?.classList.remove('d-none');
    document.getElementById('studentJdBlockDetailNav')?.classList.remove('d-none');
    const testsRoot = document.getElementById('studentJdBlockCompanyTests');
    const companyTests = studentCompanyTestsFor(companyId);
    if (testsRoot) {
      testsRoot.innerHTML = companyTests.length
        ? `<div class="apt-prob-list">${companyTests.map((t, i) => codingProbRowHtml(t, i)).join('')}</div>`
        : '<p class="small text-muted-2 mb-0">No company coding tests published for this company yet.</p>';
      bindOpenTests(testsRoot, companyTests);
    }
  }

  function showStudentJdCompanyGrid() {
    studentJdSelectedCompanyId = null;
    document.getElementById('studentJdBlockCompanyDetail')?.classList.add('d-none');
    document.getElementById('studentJdBlockCompanyView')?.classList.remove('d-none');
    document.getElementById('studentJdBlockDetailNav')?.classList.add('d-none');
  }

  function renderStudentJdBlock() {
    const grid = document.getElementById('studentJdBlockCompanyGrid');
    if (!grid) return;
    const activeCompanyId = studentJdSelectedCompanyId;
    if (!studentJdCompanyBlocks.length) {
      showStudentJdCompanyGrid();
      grid.innerHTML = '<div class="col-12"><p class="text-muted-2 mb-0">No Company Block entries are available yet. Check back later.</p></div>';
      return;
    }
    grid.innerHTML = renderJdCompanyGridHtml(studentJdCompanyBlocks);
    grid.querySelectorAll('[data-jd-company-id]').forEach((btn) => {
      btn.addEventListener('click', () => showStudentJdCompanyDetail(btn.getAttribute('data-jd-company-id')));
    });
    if (activeCompanyId) showStudentJdCompanyDetail(activeCompanyId);
    else showStudentJdCompanyGrid();
  }

  async function loadStudentJdBlock() {
    if (!access.canTake) return;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/coding/student/company-block').catch(() => null);
      studentJdCompanyBlocks = (res?.data?.blocks || []).filter((b) => String(b.companyId || '').trim() !== '');
    } else {
      studentJdCompanyBlocks = buildDemoStudentCompanyBlocks();
    }
    renderStudentJdBlock();
  }

  function buildDemoStudentCompanyBlocks() {
    const map = new Map();
    tests.filter((t) => isCompanyTest(t) && (t.status || 'published') === 'published').forEach((t) => {
      const companyId = String(t.companyId || '').trim();
      if (!companyId) return;
      if (!map.has(companyId)) {
        map.set(companyId, {
          companyId,
          companyName: t.companyName || 'Company',
          setCount: 0,
          problemCount: 0,
          sets: [],
        });
      }
    });
    return [...map.values()];
  }

  function applyTakeListPanel(panel) {
    takeListPanel = panel === 'contests' ? 'contests' : (panel === 'jdblock' ? 'jdblock' : 'tests');
    document.querySelectorAll('#takeListNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-take-list') === takeListPanel);
    });
    document.getElementById('takeContestTypeNav')?.classList.toggle('d-none', takeListPanel !== 'contests');
    document.getElementById('testList')?.classList.toggle('d-none', takeListPanel === 'jdblock');
    document.getElementById('studentJdBlockPanel')?.classList.toggle('d-none', takeListPanel !== 'jdblock');
    syncContestTypeNav('takeContestTypeNav', takeContestType, 'data-take-contest-type');
    if (takeListPanel === 'jdblock') {
      loadStudentJdBlock().catch(() => {});
    } else {
      renderTestList();
    }
  }

  function applyTakeContestType(type) {
    takeContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('takeContestTypeNav', takeContestType, 'data-take-contest-type');
    renderTestList();
  }

  function setupTakeListNav() {
    document.querySelector('#takeListNav [data-take-list="jdblock"]')
      ?.closest('.nav-item')
      ?.classList.toggle('d-none', !access.canTake);
  }

  function applyMyResultsPanel(panel) {
    myResultsView = panel === 'contests' ? 'contests' : (panel === 'company' ? 'company' : 'tests');
    document.querySelectorAll('#myResultsNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-results-view') === myResultsView);
    });
    document.getElementById('myResultsContestTypeNav')?.classList.toggle('d-none', myResultsView !== 'contests');
    syncContestTypeNav('myResultsContestTypeNav', myResultsContestType, 'data-results-contest-type');
    syncMyResultsPanels();
    if (myResultsView === 'contests') renderContestArena();
    else if (myProgress) renderHistory(myProgress);
  }

  function applyMyResultsContestType(type) {
    myResultsContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('myResultsContestTypeNav', myResultsContestType, 'data-results-contest-type');
    if (myProgress) renderHistory(myProgress);
    if (myResultsView === 'contests') renderContestArena();
  }

  function formatIst(value, options = {}) {
    if (!value) return '';
    const dt = new Date(value);
    if (Number.isNaN(dt.getTime())) return '';
    return new Intl.DateTimeFormat('en-IN', {
      timeZone: 'Asia/Kolkata',
      weekday: options.weekday || undefined,
      day: options.day || undefined,
      month: options.month || undefined,
      hour: options.hour || undefined,
      minute: options.minute || undefined,
      hour12: false,
    }).format(dt);
  }

  function contestWindowSummary(test) {
    const bounds = test?.contestWindowBounds || {};
    const open = isContestOpenClient(test);
    const end = bounds.end ? formatIst(bounds.end, { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
    if (open && end) return `Open until ${end} IST`;
    if (open) return 'Open now';
    return 'Closed';
  }

  function isContestOpenClient(test) {
    if (test && typeof test.contestOpen === 'boolean') return test.contestOpen;
    const bounds = test?.contestWindowBounds || {};
    if (bounds.start && bounds.end) {
      const start = Date.parse(bounds.start);
      const end = Date.parse(bounds.end);
      const now = Date.now();
      if (Number.isFinite(start) && Number.isFinite(end)) {
        return now >= start && now < end;
      }
    }
    const type = String(test?.contestType || 'none');
    return type === 'none' || !type;
  }

  function contestScheduleLabel(test) {
    const type = String(test?.contestType || 'none');
    const startTime = String(test?.contestStartTime || '09:00');
    if (type === 'weekly') {
      const hit = CONTEST_WEEKDAYS.find((d) => d.value === Number(test?.contestWeekday));
      return hit ? `Weekly · ${hit.label}, ${startTime} IST` : 'Weekly contest';
    }
    if (type === 'monthly') {
      const day = Number(test?.contestMonthDay);
      return Number.isFinite(day) && day > 0 ? `Monthly · day ${day}, ${startTime} IST` : 'Monthly contest';
    }
    return '';
  }

  function contestBadgeHtml(t) {
    const type = String(t?.contestType || 'none');
    if (type === 'none') return '';
    const label = contestScheduleLabel(t);
    const open = isContestOpenClient(t);
    const windowLabel = contestWindowSummary(t);
    return `<div class="mt-1 d-flex flex-wrap gap-1"><span class="badge-soft info">${esc(label || type)}</span><span class="badge-soft ${open ? 'success' : 'muted'}">${esc(windowLabel)}</span></div>`;
  }

  function testMetaLine(t) {
    const qn = t.questionCount || t.questions || (t.items || []).length || 0;
    const dur = t.durationMinutes || t.duration || 0;
    const marks = t.totalMarks || t.marks || 0;
    return `${esc(normalizeCodingTopic(t.category || 'Algorithms'))} · ${esc(t.difficulty || 'Medium')} · ${esc(qn)} Qs · ${esc(dur)} min · ${esc(marks)} marks`;
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
      category: 'Algorithms',
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
    } else if (role === 'admin') {
      next.canTake = false;
      next.canManage = true;
      next.canViewDirectory = true;
    } else if (role === 'staff') {
      next.canTake = false;
      next.canManage = false;
      next.canViewDirectory = true;
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
    if (views.includes('manage') && (role === 'placement_officer' || role === 'admin')) return 'manage';
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
    if (myResultsView === 'company') return historyEntryIsCompany(h);
    const contest = isContestTest(h) || String(h.contestType || '') === 'weekly' || String(h.contestType || '') === 'monthly';
    if (myResultsView === 'contests') {
      return contest && !historyEntryIsCompany(h) && contestTypeOf(h) === myResultsContestType;
    }
    return !contest && !historyEntryIsCompany(h);
  }

  function syncMyResultsPanels() {
    const showArena = myResultsView === 'contests';
    document.getElementById('myHistory')?.classList.toggle('d-none', showArena);
    document.getElementById('contestArena')?.classList.toggle('d-none', !showArena);
  }

  function renderHistory(p) {
    syncMyResultsPanels();
    const hist = (p.history || []).filter(historyMatchesView);
    const root = document.getElementById('myHistory');
    if (!root || myResultsView === 'contests') return;
    root.innerHTML = hist.length
      ? hist.slice(0, 8).map((h) => `
          <div class="d-flex justify-content-between align-items-start border-bottom py-2 gap-2">
            <div class="min-w-0">
              <div class="text-truncate fw-medium">${esc(h.testTitle)}</div>
              <div class="small text-muted-2">${esc(h.dateLabel || h.submittedAt || '')}</div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="small fw-semibold">${esc(h.score)} / ${esc(h.totalMarks)}</div>
              <div class="d-flex justify-content-end align-items-center gap-1 mt-1">
                <span class="small text-muted-2">${esc(h.percentage)}%</span>
                <span class="badge-soft ${h.status === 'Passed' ? 'success' : 'danger'}">${esc(h.status)}</span>
              </div>
            </div>
          </div>`).join('')
      : `<p class="text-muted-2 mb-0">${myResultsView === 'company'
        ? 'No company coding test attempts yet.'
        : myResultsView === 'contests'
          ? `No ${myResultsContestType === 'monthly' ? 'monthly' : 'weekly'} contest attempts yet.`
          : 'No coding attempts yet. Select a test on the left to begin.'}</p>`;
  }

  function bestHistoryForTest(testId) {
    const rows = (myProgress?.history || []).filter((h) => {
      const listId = String(h.listTestId || h.testId || '');
      return listId === String(testId);
    });
    if (!rows.length) return null;
    return rows.reduce((best, h) => {
      const pct = Number(h.percentage);
      const bestPct = Number(best?.percentage);
      return Number.isFinite(pct) && (!Number.isFinite(bestPct) || pct > bestPct) ? h : best;
    }, rows[0]);
  }

  function difficultyListLabel(value) {
    const d = String(value || 'Medium').toLowerCase();
    if (d === 'easy') return { text: 'Easy', cls: 'is-easy' };
    if (d === 'hard') return { text: 'Hard', cls: 'is-hard' };
    return { text: 'Med.', cls: 'is-medium' };
  }

  function formatListPercentage(t, mine) {
    if (mine) {
      const pct = Number(mine.percentage);
      if (Number.isFinite(pct)) return `${pct}%`;
    }
    return '—';
  }

  function codingProbRowHtml(t, index, { allowStart = true } = {}) {
    const open = !isContestTest(t) || isContestOpenClient(t);
    const mine = bestHistoryForTest(t.id);
    const passPct = typeof CodingService !== 'undefined' ? Number(CodingService.passPercent || 40) : 40;
    const solved = !!mine && (String(mine.status || '') === 'Passed' || Number(mine.percentage) >= passPct);
    const diff = difficultyListLabel(t.difficulty);
    const canOpen = access.canTake && allowStart && open;
    const tag = canOpen ? 'button' : 'div';
    const extra = canOpen ? ` type="button" data-open-test="${esc(t.id)}"` : '';
    let title = t.title || 'Coding problem';
    if (isContestTest(t) && !open) {
      title = `${title} · ${contestScheduleLabel(t)}`;
    }
    return `<${tag} class="apt-prob-row ${canOpen ? 'is-clickable' : ''}"${extra}>
      <span class="apt-prob-check">${solved ? '<i class="bi bi-check-lg"></i>' : ''}</span>
      <span class="apt-prob-title">${index + 1}. ${esc(title)}</span>
      <span class="apt-prob-pct">${esc(formatListPercentage(t, mine))}</span>
      <span class="apt-prob-diff ${diff.cls}">${esc(diff.text)}</span>
    </${tag}>`;
  }

  function testCardHtml(t, { allowStart = true } = {}) {
    return codingProbRowHtml(t, 0, { allowStart });
  }

  function bindOpenTests(root, list) {
    root?.querySelectorAll('[data-open-test]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = list.find((x) => String(x.id) === String(btn.getAttribute('data-open-test')));
        if (t) openExam(t);
      });
    });
  }

  function renderTestList(list) {
    const root = document.getElementById('testList');
    if (!root || takeListPanel === 'jdblock') return;
    const published = (list || tests || []).filter((t) => (t.status || 'published') === 'published');
    const wantContests = takeListPanel === 'contests';
    const visible = published.filter((t) => {
      const isContest = isContestTest(t);
      if (wantContests !== isContest) return false;
      if (!wantContests && isCompanyTest(t)) return false;
      if (isContest && String(t.contestType || '') !== takeContestType) return false;
      return true;
    });
    if (!visible.length) {
      const contestLabel = takeContestType === 'monthly' ? 'monthly' : 'weekly';
      const msg = wantContests
        ? `No ${contestLabel} coding contests are open right now, or none are published yet.`
        : 'No published coding tests yet. Check back later or contact your placement officer.';
      root.innerHTML = `<p class="text-muted-2 mb-0">${msg}</p>`;
      return;
    }
    root.innerHTML = `<div class="apt-prob-list">${visible.map((t, i) => codingProbRowHtml(t, i)).join('')}</div>`;
    bindOpenTests(root, visible);
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
      tests = list || [];
      myProgress = progress;
      renderStats(progress);
      syncMyResultsPanels();
      if (myResultsView === 'contests') await renderContestArena();
      else renderHistory(progress);
      if (takeListPanel === 'jdblock') await loadStudentJdBlock();
      else renderTestList();
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

  function applyManageContestType(type) {
    manageContestType = type === 'monthly' ? 'monthly' : 'weekly';
    document.getElementById('manageWeeklyContestsSection')?.classList.toggle('d-none', manageContestType !== 'weekly');
    document.getElementById('manageMonthlyContestsSection')?.classList.toggle('d-none', manageContestType !== 'monthly');
    syncContestTypeNav('manageContestTypeNav', manageContestType, 'data-contest-type');
  }

  function openManageContests(type = manageContestType) {
    applyManagePanel('contests');
    applyManageContestType(type);
  }

  function applyManagePanel(panel) {
    if (panel === 'weekly-contests' && canManageContests()) {
      managePanel = 'contests';
      manageContestType = 'weekly';
    } else if (panel === 'monthly-contests' && canManageContests()) {
      managePanel = 'contests';
      manageContestType = 'monthly';
    } else if (panel === 'contests' && canManageContests()) {
      managePanel = 'contests';
    } else if (panel === 'bank') {
      managePanel = 'bank';
    } else if (panel === 'jd') {
      managePanel = 'jd';
    } else {
      managePanel = 'tests';
    }
    document.getElementById('manageTestsPanel')?.classList.toggle('d-none', managePanel !== 'tests');
    document.getElementById('manageContestsPanel')?.classList.toggle('d-none', managePanel !== 'contests');
    document.getElementById('manageBankPanel')?.classList.toggle('d-none', managePanel !== 'bank');
    document.getElementById('manageJdPanel')?.classList.toggle('d-none', managePanel !== 'jd');
    document.querySelectorAll('#manageViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-manage-view') === managePanel);
    });
    if (managePanel === 'contests') applyManageContestType(manageContestType);
    if (managePanel === 'bank') renderBank();
    if (managePanel === 'jd') loadJdLibrary().catch(() => {});
  }

  function syncManageContestActions() {
    const show = canManageContests();
    document.getElementById('manageContestNavItem')?.classList.toggle('d-none', !show);
    if (!show && managePanel === 'contests') applyManagePanel('tests');
    syncCompanyTestFormUi();
  }

  function isContestManageActive(test) {
    const status = contestStatusClient(test);
    return status === 'ACTIVE' || status === 'UPCOMING';
  }

  function contestScheduleTimeInputs(t, { inline = false } = {}) {
    const id = esc(t.id);
    const hasStoredTimes = String(t?.contestStartTime || '').trim() !== '';
    const startVal = hasStoredTimes ? contestTimesClient(t).start : DEFAULT_CONTEST_START_TIME;
    const inputStyle = 'width:auto;min-width:6.5rem';
    return `<div class="d-flex flex-wrap align-items-center gap-2">
      <label class="small text-muted-2 mb-0" for="contest-start-${id}">Start</label>
      <input type="time" class="form-control form-control-sm" id="contest-start-${id}" style="${inputStyle}" value="${esc(startVal)}" data-contest-schedule="${id}" data-schedule-field="contestStartTime"/>
    </div>`;
  }

  function contestScheduleControls(t, { inline = false } = {}) {
    const type = String(t?.contestType || 'none');
    const id = esc(t.id);
    const wrapCls = inline ? 'd-flex flex-wrap align-items-center gap-2' : 'd-flex flex-wrap align-items-center gap-2 mt-2';
    const timeInputs = contestScheduleTimeInputs(t, { inline });
    if (type === 'weekly') {
      const current = Number(t.contestWeekday) || 1;
      const opts = CONTEST_WEEKDAYS.map((d) =>
        `<option value="${d.value}" ${d.value === current ? 'selected' : ''}>${esc(d.label)}</option>`
      ).join('');
      return `<div class="${wrapCls}">
        <label class="small text-muted-2 mb-0" for="contest-day-${id}">Runs every</label>
        <select class="form-select form-select-sm" id="contest-day-${id}" style="width:auto;min-width:9rem" data-contest-schedule="${id}" data-schedule-field="contestWeekday">${opts}</select>
        ${timeInputs}
      </div>`;
    }
    if (type === 'monthly') {
      const current = Number(t.contestMonthDay) || 1;
      const opts = Array.from({ length: 28 }, (_, i) => {
        const day = i + 1;
        return `<option value="${day}" ${day === current ? 'selected' : ''}>${day}</option>`;
      }).join('');
      return `<div class="${wrapCls}">
        <label class="small text-muted-2 mb-0" for="contest-day-${id}">Day of month</label>
        <select class="form-select form-select-sm" id="contest-day-${id}" style="width:auto;min-width:6rem" data-contest-schedule="${id}" data-schedule-field="contestMonthDay">${opts}</select>
        ${timeInputs}
      </div>`;
    }
    return '';
  }

  function companyTestBadgeHtml(t) {
    if (!isCompanyTest(t)) return '';
    const name = esc(t.companyName || 'Company');
    return `<span class="badge text-bg-light border mt-1">Company · ${name}</span>`;
  }

  function renderManageContestRow(t) {
    const published = (t.status || 'unpublished') === 'published';
    const publishLabel = published ? 'Published' : 'Unpublished';
    const publishCls = published ? 'success' : 'warning';
    const life = contestStatusClient(t);
    const lifeCls = life === 'ACTIVE' ? 'success' : (life === 'COMPLETED' ? 'warning' : 'muted');
    const lifeLabel = life === 'ACTIVE' ? 'Active' : (life === 'COMPLETED' ? 'Completed' : 'Upcoming');
    const type = String(t.contestType || 'none');
    const typeLabel = type === 'monthly' ? 'Monthly contest' : 'Weekly contest';
    const id = String(t.id || '');
    const checked = selectedManageTestIds.has(id);
    return `<tr>
      <td class="text-nowrap"><input class="form-check-input" type="checkbox" data-manage-test-select="${esc(id)}" ${checked ? 'checked' : ''} aria-label="Select contest"/></td>
      <td class="fw-semibold">${esc(t.title)}</td>
      <td class="small text-muted-2">${testMetaLine(t)}</td>
      <td>
        <div class="d-flex flex-wrap gap-1">
          <span class="badge-soft ${publishCls}">${esc(publishLabel)}</span>
          <span class="badge-soft info">${esc(typeLabel)}</span>
          <span class="badge-soft ${lifeCls}">${esc(lifeLabel)}</span>
        </div>
      </td>
      <td>${contestScheduleControls(t, { inline: true })}</td>
      <td class="text-nowrap">
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-edit="${esc(t.id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-delete-test="${esc(t.id)}">Delete</button>
        </div>
      </td>
    </tr>`;
  }

  function renderManageContestActiveTable(contests, emptyMsg) {
    if (!contests.length) return `<p class="text-muted-2 mb-0">${emptyMsg}</p>`;
    return `<div class="table-wrap mb-0"><table class="table-modern table-sm mb-0"><thead><tr>
      <th style="width:2rem"></th><th>Title</th><th>Details</th><th>Status</th><th>Schedule</th><th>Actions</th>
    </tr></thead><tbody>${contests.map((t) => renderManageContestRow(t)).join('')}</tbody></table></div>`;
  }

  function renderManageContestSections(contestType, listRoot) {
    const all = tests.filter((t) => String(t.contestType) === contestType);
    const active = all.filter((t) => isContestManageActive(t));
    const label = contestType === 'monthly' ? 'monthly' : 'weekly';
    const bulkBar = document.getElementById(contestType === 'monthly' ? 'manageMonthlyContestsBulkActions' : 'manageWeeklyContestsBulkActions');
    if (!listRoot) return;
    if (!active.length) {
      bulkBar?.classList.add('d-none');
      listRoot.innerHTML = `<p class="text-muted-2 mb-0">No active ${label} contests.</p>`;
      updateContestSelectionToolbar(contestType);
      return;
    }
    bulkBar?.classList.remove('d-none');
    listRoot.innerHTML = renderManageContestActiveTable(active, `No active ${label} contests.`);
    bindManageListActions(listRoot);
    updateContestSelectionToolbar(contestType);
  }

  async function saveContestSchedule(id, field, value) {
    const scheduleFields = ['contestWeekday', 'contestMonthDay', 'contestStartTime'];
    if (!id || !scheduleFields.includes(field)) return;
    const test = tests.find((t) => String(t.id) === String(id));
    if (!test) {
      toastMsg('Contest not found.', 'error');
      return;
    }
    const patch = { [field]: value };
    if (field === 'contestWeekday' || field === 'contestMonthDay') {
      const n = Number(value);
      if (!Number.isFinite(n)) return;
      patch[field] = n;
    } else {
      patch[field] = normalizeContestTimeClient(value, DEFAULT_CONTEST_START_TIME);
    }
    try {
      await CodingService.saveTest({ ...test, ...patch, items: test.items || [] });
      toastMsg('Contest schedule updated.', 'success');
      await loadManaged();
      renderManage();
    } catch (err) {
      toastMsg(err?.message || 'Could not update contest schedule.', 'error');
    }
  }

  function renderManageRow(t, { showContestBadge = false, showCompanyBadge = false, selectable = false } = {}) {
    const published = (t.status || 'unpublished') === 'published';
    const id = String(t.id || '');
    const checked = selectedManageTestIds.has(id);
    return `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div class="d-flex align-items-start gap-2 min-w-0">
          ${selectable ? `<input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-manage-test-select="${esc(id)}" ${checked ? 'checked' : ''} aria-label="Select test"/>` : ''}
          <div class="min-w-0">
          <strong>${esc(t.title)}</strong>
          <div class="small text-muted-2">${published ? 'Published' : 'Unpublished (hidden from students)'} · ${testMetaLine(t)}</div>
          ${showCompanyBadge ? companyTestBadgeHtml(t) : ''}
          ${showContestBadge ? contestBadgeHtml(t) : ''}
          ${showContestBadge ? contestScheduleControls(t) : ''}
          </div>
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
    root.querySelectorAll('[data-contest-schedule]').forEach((el) => {
      el.addEventListener('change', () => {
        saveContestSchedule(
          el.getAttribute('data-contest-schedule'),
          el.getAttribute('data-schedule-field'),
          el.value
        );
      });
    });
    root.querySelectorAll('[data-manage-test-select]').forEach((el) => {
      el.addEventListener('change', () => {
        const id = el.getAttribute('data-manage-test-select');
        if (!id) return;
        if (el.checked) selectedManageTestIds.add(String(id));
        else selectedManageTestIds.delete(String(id));
        updateManageTestsSelectionToolbar();
        updateContestSelectionToolbar('weekly');
        updateContestSelectionToolbar('monthly');
      });
    });
  }

  async function deleteSelectedManageTests(contestType = null) {
    let ids = [...selectedManageTestIds];
    if (contestType) {
      ids = ids.filter((id) => {
        const t = tests.find((x) => String(x.id) === id);
        return t && String(t.contestType) === contestType;
      });
    }
    if (!ids.length) {
      toastMsg('Select at least one item to delete.', 'error');
      return;
    }
    if (!confirm(`Delete ${ids.length} selected item(s)? This cannot be undone.`)) return;
    let deleted = 0;
    for (const id of ids) {
      try {
        await CodingService.deleteTest(id);
        deleted += 1;
        selectedManageTestIds.delete(String(id));
      } catch (_) {
        /* continue with remaining */
      }
    }
    if (!deleted) {
      toastMsg('Could not delete selected items.', 'error');
      return;
    }
    toastMsg(`Deleted ${deleted} item(s).`, 'success');
    await loadManaged();
    renderManage();
  }

  async function deleteSelectedBankProblems() {
    const ids = [...selectedBankIds];
    if (!ids.length) {
      toastMsg('Select at least one problem to delete.', 'error');
      return;
    }
    if (!confirm(`Delete ${ids.length} selected problem(s) from the bank?`)) return;
    try {
      const data = await CodingService.bulkDeleteBankProblems(ids);
      ids.forEach((id) => selectedBankIds.delete(String(id)));
      toastMsg(`Deleted ${data?.deleted ?? ids.length} problem(s).`, 'success');
      bank = await CodingService.listBank();
      renderBank();
    } catch (err) {
      toastMsg(err?.message || 'Could not delete selected problems.', 'error');
    }
  }

  async function deleteSelectedJdSets() {
    const ids = [...selectedJdSetIds];
    if (!ids.length) {
      toastMsg('Select at least one company problem set to delete.', 'error');
      return;
    }
    if (!confirm(`Delete ${ids.length} selected company problem set(s)?`)) return;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      toastMsg('Company problem sets require a live session.', 'info');
      return;
    }
    let deleted = 0;
    for (const id of ids) {
      const res = await api(`/coding/company-block/sets/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
      if (res?.success) {
        deleted += 1;
        delete jdSetDetailsCache[String(id)];
        selectedJdSetIds.delete(String(id));
      }
    }
    if (!deleted) {
      toastMsg('Could not delete selected company problem sets.', 'error');
      return;
    }
    toastMsg(`Deleted ${deleted} company problem set(s).`, 'success');
    await loadJdLibrary();
  }

  function renderManage() {
    if (!access.canManage) return;
    syncManageContestActions();
    applyManagePanel(managePanel);
    const regular = tests.filter((t) => isRegularTest(t));
    const testsRoot = document.getElementById('manageTestsList');
    const testsBulkBar = document.getElementById('manageTestsBulkActions');
    if (testsRoot) {
      if (!regular.length) {
        testsBulkBar?.classList.add('d-none');
        testsRoot.innerHTML = '<p class="text-muted-2 mb-0">No regular tests yet.</p>';
      } else {
        testsBulkBar?.classList.remove('d-none');
        testsRoot.innerHTML = regular.map((t) => renderManageRow(t, { selectable: true })).join('');
        bindManageListActions(testsRoot);
      }
      updateManageTestsSelectionToolbar();
    }
    if (managePanel === 'jd' && jdSelectedCompanyId) {
      showJdCompanyDetail(jdSelectedCompanyId);
    }
    renderManageContestSections('weekly', document.getElementById('manageWeeklyContestsList'));
    renderManageContestSections('monthly', document.getElementById('manageMonthlyContestsList'));
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
            <select class="form-select form-select-sm" data-f="category">${CATEGORIES.map((c) => `<option ${c === normalizeCodingTopic(q.category || 'Algorithms') ? 'selected' : ''}>${c}</option>`).join('')}</select>
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
      category: v('category') || 'Algorithms',
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
    document.getElementById('tfContestStartTimeWrap')?.classList.toggle('d-none', type === 'none');
    document.getElementById('tfContestWeekdayWrap')?.classList.toggle('d-none', type !== 'weekly');
    document.getElementById('tfContestMonthDayWrap')?.classList.toggle('d-none', type !== 'monthly');
  }

  async function openTestForm(test = null, preset = null) {
    const isContestPreset = preset?.contestType === 'weekly' || preset?.contestType === 'monthly';
    const isCompanyPreset = preset?.testKind === 'company' || isCompanyTest(test);
    const isContest = isContestTest(test) || isContestPreset;
    document.getElementById('testFormTitle').textContent = test
      ? (isContest ? 'Edit contest' : (isCompanyPreset ? 'Edit company test' : 'Edit test'))
      : (isContestPreset ? `New ${preset.contestType} contest` : (isCompanyPreset ? 'New company test' : 'Create test'));
    document.getElementById('tfTestKind').value = isCompanyPreset ? 'company' : 'regular';
    document.getElementById('tfId').value = test?.id || '';
    document.getElementById('tfTitle').value = test?.title || preset?.title || '';
    document.getElementById('tfDescription').value = test?.description || '';
    fillSelect(document.getElementById('tfCategory'), CATEGORIES, normalizeCodingTopic(test?.category || 'Algorithms'));
    fillSelect(document.getElementById('tfDifficulty'), DIFFICULTIES, test?.difficulty || 'Medium');
    document.getElementById('tfDuration').value = test?.duration || test?.durationMinutes || 20;
    document.getElementById('tfStatus').value = test?.status === 'published' ? 'published' : 'unpublished';
    initContestMonthDaySelect();
    const contestType = isCompanyPreset ? 'none' : (test?.contestType || preset?.contestType || 'none');
    document.getElementById('tfContestType').value = ['weekly', 'monthly'].includes(contestType) ? contestType : 'none';
    document.getElementById('tfContestWeekday').value = String(test?.contestWeekday || preset?.contestWeekday || 1);
    document.getElementById('tfContestMonthDay').value = String(test?.contestMonthDay || preset?.contestMonthDay || 1);
    document.getElementById('tfContestStartTime').value = test?.contestStartTime || preset?.contestStartTime || DEFAULT_CONTEST_START_TIME;
    await fillTfCompanySelect(test?.companyId || preset?.companyId || '');
    syncCompanyTestFormUi();
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

  function bindBankListEvents() {
    const list = document.getElementById('bankQuestionsList');
    if (!list || list.dataset.bankListBound === '1') return;
    list.dataset.bankListBound = '1';
    list.addEventListener('change', (e) => {
      const pick = e.target.closest('[data-bank-select]');
      if (!pick) return;
      const id = pick.getAttribute('data-bank-select');
      if (!id) return;
      if (pick.checked) selectedBankIds.add(String(id));
      else selectedBankIds.delete(String(id));
      updateBankSelectionToolbar();
    });
    list.addEventListener('click', (e) => {
      const editBtn = e.target.closest('[data-bank-edit]');
      if (editBtn) {
        const q = bank.find((x) => String(x.id) === String(editBtn.getAttribute('data-bank-edit')));
        if (q) openBankProblemForm(q);
        return;
      }
      const delBtn = e.target.closest('[data-bank-del]');
      if (!delBtn) return;
      e.preventDefault();
      (async () => {
        if (!confirm('Delete this problem from the bank?')) return;
        try {
          await CodingService.deleteBankProblem(delBtn.getAttribute('data-bank-del'));
          selectedBankIds.delete(String(delBtn.getAttribute('data-bank-del')));
          toastMsg('Problem deleted.', 'success');
          bank = await CodingService.listBank();
          renderBank();
        } catch (err) {
          toastMsg(err?.message || 'Could not delete problem.', 'error');
        }
      })();
    });
  }

  function renderBank() {
    renderBankTopicNav();
    document.querySelectorAll('#bankDifficultyNav .nav-link').forEach((link) => {
      link.classList.toggle('active', (link.getAttribute('data-bank-difficulty') || '') === bankDifficultyFilter);
    });
    const rows = visibleBankProblems();
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
    const bulkBar = document.getElementById('bankBulkActions');
    bindBankListEvents();
    if (!rows.length) {
      bulkBar?.classList.add('d-none');
      list.innerHTML = '<p class="text-muted-2 mb-0">No problems in the bank yet. Add one manually or generate with AI.</p>';
      updateBankSelectionToolbar();
      return;
    }
    bulkBar?.classList.remove('d-none');
    list.innerHTML = rows.map((q) => {
      const id = String(q.id || '');
      const checked = selectedBankIds.has(id);
      return `<div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div class="d-flex align-items-start gap-2 min-w-0">
          <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-bank-select="${esc(id)}" ${checked ? 'checked' : ''} aria-label="Select problem"/>
          <div class="min-w-0">
            <div class="fw-semibold">${esc(q.title || 'Untitled problem')}</div>
            <div class="small text-muted-2">${esc(normalizeCodingTopic(q.category))} · ${esc((q.testCases || []).length)} test case(s) · ${esc(q.marks || 2)} mark(s)</div>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="badge-soft ${difficultyClass(q.difficulty)}">${esc(q.difficulty || 'Medium')}</span>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bank-edit="${esc(id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-bank-del="${esc(id)}"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    }).join('');
    updateBankSelectionToolbar();
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

  function hasDirectoryLookup() {
    return !!(dirSearch.trim() || dirFilterBranch);
  }

  function renderClassChart(rows) {
    const wrap = document.getElementById('dirClassChartWrap');
    const chart = document.getElementById('dirClassChart');
    if (!wrap || !chart) return;
    const show = progressPanel === 'tests' && !!dirFilterBranch;
    wrap.classList.toggle('d-none', !show);
    if (!show) {
      chart.innerHTML = '';
      return;
    }
    if (!rows.length) {
      chart.innerHTML = '<p class="small text-muted-2 mb-0">No students found for this selection yet.</p>';
      return;
    }
    const sorted = [...rows].sort((a, b) => (Number(b.bestScore) || 0) - (Number(a.bestScore) || 0));
    chart.innerHTML = sorted.map((r) => {
      const pct = Math.max(0, Math.min(100, Number(r.bestScore) || 0));
      return `<div class="cod-class-bar">
        <div class="lbl" title="${esc(r.name || '')}">${esc(r.name || 'Student')}</div>
        <div class="track"><span style="width:${pct}%"></span></div>
        <div class="pct">${esc(pct)}%</div>
      </div>`;
    }).join('');
  }

  function renderDirectoryTable(rows, summary, scope = {}, needsFilter = false) {
    document.getElementById('dirTestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.add('d-none');
    const waiting = needsFilter || !hasDirectoryLookup();
    document.getElementById('dirStats').innerHTML = waiting ? '' : [
      ['Students', summary.students ?? 0],
      ['With attempts', summary.withAttempts ?? 0],
      ['Total attempts', summary.totalAttempts ?? 0],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Avg best', `${summary.avgBestScore ?? 0}%`],
      ['Highest best', `${summary.highestBestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md-2"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');
    renderClassChart(waiting ? [] : rows);
    if (!waiting && dirFilterBranch) {
      const title = document.getElementById('dirChartTitle');
      if (title) title.textContent = `${dirFilterBranch} progress`;
      document.getElementById('dirClassChartWrap')?.classList.remove('d-none');
    }
    let emptyMsg = 'Search a student by name or roll number, or select a branch to view progress.';
    if (!waiting && Auth.role() === 'staff' && !staffAssignedBatches().length && !(scope.assignedClassBatches || []).length) {
      emptyMsg = 'No class is assigned to your account. Contact the placement office to monitor student coding progress.';
    } else if (!waiting) {
      emptyMsg = dirFilterBranch
        ? 'No students found for this branch.'
        : 'No matching student with coding attempts.';
    }
    document.getElementById('dirRows').innerHTML = (!waiting && rows.length) ? rows.map((r) => {
      const uid = String(r.userId || '');
      return `<tr>
        <td class="fw-semibold">${esc(r.name)}</td>
        <td>${esc(studentIdLabel(r))}</td>
        <td>${esc(r.classBatch || '—')}</td>
        <td>${esc(r.testsAttempted ?? r.attempts ?? 0)}</td>
        <td>${esc(r.averageScore ?? r.percentage ?? 0)}%</td>
        <td>${esc(r.bestScore ?? 0)}%</td>
        <td>${uid
          ? `<button type="button" class="btn btn-sm btn-outline-primary" data-detail="${esc(uid)}">View</button>`
          : `<span class="small text-muted-2" title="No PlaceHub login is linked, so coding history cannot be opened.">—</span>`}</td>
      </tr>`;
    }).join('') : `<tr><td colspan="7" class="text-muted-2 p-3">${emptyMsg}</td></tr>`;
    document.querySelectorAll('[data-detail]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentDetail(btn.getAttribute('data-detail')));
    });
  }

  function medalMeta(rank) {
    if (rank === 1) return { cls: 'is-gold', icon: '🥇', label: 'Champion' };
    if (rank === 2) return { cls: 'is-silver', icon: '🥈', label: 'Runner-up' };
    if (rank === 3) return { cls: 'is-bronze', icon: '🥉', label: 'Third place' };
    return { cls: '', icon: `#${rank}`, label: `Rank ${rank}` };
  }

  function podiumHtml(winners) {
    const slots = [1, 0, 2].map((i) => winners[i] || null);
    const rankOf = (w) => Number(w?.rank) || (winners.indexOf(w) + 1);
    return `<div class="cod-podium mb-3">${slots.map((w) => {
      if (!w) return '<div class="cod-podium-card"><div class="small text-muted-2">Awaiting a finisher</div></div>';
      const medal = medalMeta(rankOf(w));
      return `<div class="cod-podium-card ${medal.cls}">
        <div class="cod-medal">${medal.icon}</div>
        <div class="fw-bold">${esc(w.name)}</div>
        <div class="small text-muted-2">${esc(studentIdLabel(w))}</div>
        <div class="fw-semibold mt-1">${esc(w.percentage ?? 0)}%</div>
        <div class="small">${esc(medal.label)} · ${esc(w.points ?? Math.round((w.percentage || 0) * 10))} pts</div>
      </div>`;
    }).join('')}</div>`;
  }

  function contestCardHtml(c, { student = false, myUserId = '' } = {}) {
    const published = !!c.winnersPublished;
    const open = !!c.contestOpen;
    const winners = c.winners || [];
    const participants = c.participants || [];
    const mine = c.myResult || participants.find((p) => String(p.userId || '') === String(myUserId)) || null;
    const typeLabel = c.contestScheduleLabel || (c.contestType === 'monthly' ? 'Monthly contest' : 'Weekly contest');
    const status = open
      ? (c.contestType === 'monthly' ? 'Open this month' : 'Open this week')
      : (published ? 'Winners published' : 'Closed');
    const statusCls = open ? 'success' : (published ? 'warning' : 'muted');
    let body = '';
    if (published) {
      body = `${podiumHtml(winners)}
        <div class="table-wrap"><table class="table-modern"><thead><tr>
          <th>Rank</th><th>Name</th><th>Roll no</th><th>Score</th><th>XP</th>
        </tr></thead><tbody>
          ${participants.map((p) => {
            const me = String(p.userId || '') === String(myUserId);
            return `<tr class="${me ? 'table-warning' : ''}">
              <td><span class="cod-rank is-${esc(p.rank || '')}">${esc(p.rank || '—')}</span></td>
              <td>${esc(p.name)}${me ? ' <span class="badge-soft warning">You</span>' : ''}</td>
              <td>${esc(studentIdLabel(p))}</td>
              <td>${esc(p.percentage ?? 0)}%</td>
              <td>${esc(p.points ?? Math.round((p.percentage || 0) * 10))}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="5" class="text-muted-2 p-3">No finishers yet.</td></tr>'}
        </tbody></table></div>`;
    } else {
      body = `<div class="border rounded-3 p-3 mb-2">
        <div class="fw-semibold">${open ? 'Contest is live' : 'Contest closed'}</div>
        <p class="small text-muted-2 mb-1">${open
          ? 'Winner names stay hidden until closing time.'
          : (Number(c.participantCount || 0) === 0
            ? 'No submissions, so there is no winner to publish.'
            : 'Winners will appear here once results are published.')}</p>
        <div class="small">${esc(c.participantCount || 0)} student${Number(c.participantCount) === 1 ? '' : 's'} submitted.</div>
      </div>`;
      if (student && mine) {
        body += `<div class="border rounded-3 p-3">
          <div class="small text-muted-2">Your score</div>
          <div class="fw-bold">${esc(mine.percentage ?? 0)}% · ${esc(mine.score ?? 0)} / ${esc(mine.totalMarks ?? 0)}</div>
          <div class="small text-muted-2">Rank and medals unlock after the contest closes.</div>
        </div>`;
      } else if (student && open) {
        body += '<p class="small text-muted-2 mb-0">Join from the left to earn a podium finish.</p>';
      }
    }
    return `<div class="border rounded-3 p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
          <div class="fw-bold">${open ? '⚔️ ' : '🏆 '}${esc(c.title)}</div>
          <div class="small text-muted-2">${esc(typeLabel)}</div>
        </div>
        <span class="badge-soft ${statusCls}">${esc(status)}</span>
      </div>
      ${body}
    </div>`;
  }

  function contestBoardsHtml(contests, opts = {}) {
    if (!contests.length) {
      return `<p class="text-muted-2 mb-0">${opts.student
        ? 'No weekly or monthly contests yet. Your own score still appears here after you finish.'
        : 'No weekly or monthly contests in your scope yet.'}</p>`;
    }
    const weekly = contests.filter((c) => c.contestType === 'weekly');
    const monthly = contests.filter((c) => c.contestType === 'monthly');
    const other = contests.filter((c) => c.contestType !== 'weekly' && c.contestType !== 'monthly');
    return [
      ['Weekly contests', weekly],
      ['Monthly contests', monthly],
      ['Other contests', other],
    ].filter(([, list]) => list.length).map(([title, list]) => `
      <div class="mb-3">
        <h6 class="fw-bold mb-2">${esc(title)}</h6>
        ${list.map((c) => contestCardHtml(c, opts)).join('')}
      </div>`).join('');
  }

  async function renderContestArena() {
    syncMyResultsPanels();
    if (myResultsView !== 'contests') return;
    const root = document.getElementById('contestArena');
    if (!root) return;
    root.innerHTML = '<p class="text-muted-2 mb-0">Loading contest arena…</p>';
    try {
      const board = await CodingService.contestBoard();
      root.innerHTML = contestBoardsHtml(board.contests || [], { student: true, myUserId: board.myUserId || '' });
    } catch (err) {
      root.innerHTML = `<p class="text-muted-2 mb-0">${esc(err?.message || 'Could not load contest arena.')}</p>`;
    }
  }

  function renderContestResults(contests, summary) {
    document.getElementById('dirTestResultsWrap')?.classList.add('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirClassChartWrap')?.classList.add('d-none');
    const published = contests.filter((c) => c.winnersPublished).length;
    document.getElementById('dirStats').innerHTML = [
      ['Contests', contests.length],
      ['Winners published', published],
      ['Live now', contests.filter((c) => c.contestOpen).length],
      ['Submissions', contests.reduce((n, c) => n + Number(c.participantCount || 0), 0)],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Highest best', `${summary.highestBestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md-2"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');
    const root = document.getElementById('dirContestSections');
    if (!root) return;
    root.innerHTML = contestBoardsHtml(contests, { student: false });
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
      }).toString()).then((r) => r?.success ? r.data : null).catch(() => null);
      if (!data) {
        data = await api('/aptitude/progress/filters?' + new URLSearchParams({
          department: document.getElementById('fDepartment')?.value || '',
          course: dirFilterBranch,
        }).toString()).then((r) => r?.success ? r.data : null).catch(() => null);
      }
    }
    if (!data) {
      data = { departments: [], branches: [], batches: staffAssignedBatches(), types: [] };
    }
    applyDirDepartmentFromData(data.departments || []);
    fillSelect(document.getElementById('fBranch'), [{ value: '', label: 'Select a branch' }, ...(data.branches || []).map((b) => ({ value: b, label: b }))], dirFilterBranch);
  }

  let dirFiltersReady = false;
  async function initDirFilters() {
    if (dirFiltersReady) return;
    dirFiltersReady = true;
    await loadDirFilterOptions();
    document.getElementById('fDepartmentSelect')?.addEventListener('change', async () => {
      document.getElementById('fDepartment').value = document.getElementById('fDepartmentSelect').value;
      dirFilterBranch = '';
      await loadDirFilterOptions();
      await loadDirectory();
    });
    document.getElementById('fBranch')?.addEventListener('change', async () => {
      dirFilterBranch = document.getElementById('fBranch').value;
      await loadDirectory();
    });
    document.getElementById('fType')?.addEventListener('change', () => loadDirectory());
    document.getElementById('fSearch')?.addEventListener('input', () => {
      dirSearch = document.getElementById('fSearch').value || '';
      window.clearTimeout(dirSearchTimer);
      dirSearchTimer = window.setTimeout(() => loadDirectory(), 280);
    });
  }

  function syncProgressFilters() {
    const tests = progressPanel === 'tests';
    document.getElementById('dirFilterRow')?.classList.toggle('d-none', !tests);
    if (!tests) document.getElementById('dirClassChartWrap')?.classList.add('d-none');
  }

  function buildDirectoryQuery() {
    const qs = new URLSearchParams();
    const dept = document.getElementById('fDepartment')?.value || '';
    if (dept) qs.set('department', dept);
    if (dirFilterBranch) qs.set('course', dirFilterBranch);
    const q = dirSearch.trim();
    if (q) qs.set('q', q);
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
    syncProgressFilters();
    let scope = access.scope || {};
    updateDirScopeHint(scope);
    if (progressPanel !== 'contests' && !hasDirectoryLookup()) {
      renderDirectoryTable([], { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 }, scope, true);
      return;
    }
    try {
      const live = await CodingService.directory(buildDirectoryQuery());
      if (live?.scope) {
        access.scope = live.scope;
        scope = live.scope;
        updateDirScopeHint(scope);
      }
      if (live && (progressPanel === 'contests' || live.view === 'contests')) {
        renderContestResults(live.contests || [], live.summary || {});
        return;
      }
      renderDirectoryTable(live?.rows || [], live?.summary || {}, scope, !!live?.needsFilter);
    } catch (err) {
      toastMsg(err?.message || 'Could not load coding progress.', 'error');
      if (progressPanel === 'contests') {
        renderContestResults([], { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 });
      } else {
        renderDirectoryTable([], { students: 0, withAttempts: 0, totalAttempts: 0, avgPercentage: 0, avgBestScore: 0, highestBestScore: 0 }, scope);
      }
    }
  }

  function showCodAiForm() {
    document.getElementById('codAiFormPanel')?.classList.remove('d-none');
    document.getElementById('codAiPreviewPanel')?.classList.add('d-none');
  }

  function openCodAiModal(opts = {}) {
    fillSelect(document.getElementById('codAiCategory'), CATEGORIES, 'Algorithms');
    fillSelect(document.getElementById('codAiDifficulty'), DIFFICULTIES, 'Medium');
    aiLastFormParams = { companyId: opts.companyId || '' };
    showCodAiForm();
    document.getElementById('codAiPreviewList').innerHTML = '';
    document.getElementById('codAiGenerateStatus')?.classList.add('d-none');
    document.getElementById('codAiSaveStatus')?.classList.add('d-none');
    codAiModal?.show();
  }

  function collectCodAiParams() {
    return {
      category: document.getElementById('codAiCategory')?.value || 'Algorithms',
      topic: (document.getElementById('codAiTopic')?.value || '').trim() || (document.getElementById('codAiCategory')?.value || 'Algorithms'),
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
    document.getElementById('takeListNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-take-list]');
      if (!link) return;
      e.preventDefault();
      applyTakeListPanel(link.getAttribute('data-take-list'));
    });
    document.getElementById('takeContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-take-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyTakeContestType(link.getAttribute('data-take-contest-type'));
    });
    document.getElementById('btnStudentJdBlockBack')?.addEventListener('click', () => {
      showStudentJdCompanyGrid();
      renderStudentJdBlock();
    });
    document.getElementById('myResultsNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-view]');
      if (!link) return;
      e.preventDefault();
      applyMyResultsPanel(link.getAttribute('data-results-view') || 'tests');
    });
    document.getElementById('myResultsContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyMyResultsContestType(link.getAttribute('data-results-contest-type'));
    });
    document.getElementById('btnNewTest')?.addEventListener('click', () => {
      applyManagePanel('tests');
      openTestForm(null, { contestType: 'none' });
    });
    document.getElementById('btnNewCompanyTest')?.addEventListener('click', () => {
      applyManagePanel('jd');
      const companyId = jdSelectedCompanyId && jdSelectedCompanyId !== '_unassigned' ? jdSelectedCompanyId : '';
      openTestForm(null, { testKind: 'company', contestType: 'none', companyId });
    });
    document.getElementById('adminJdBlockViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-admin-jd-block-view]');
      if (!link) return;
      e.preventDefault();
      applyAdminJdBlockView(link.getAttribute('data-admin-jd-block-view'));
    });
    document.getElementById('btnJdBlockBack')?.addEventListener('click', () => showJdCompanyGrid());
    document.getElementById('btnJdAiGenerate')?.addEventListener('click', () => {
      const companyId = jdSelectedCompanyId && jdSelectedCompanyId !== '_unassigned' ? jdSelectedCompanyId : '';
      openCodAiModal({ companyId });
    });
    document.getElementById('btnNewWeeklyContest')?.addEventListener('click', () => {
      openManageContests('weekly');
      openTestForm(null, {
        contestType: 'weekly',
        contestWeekday: new Date().getDay() === 0 ? 7 : new Date().getDay(),
        contestStartTime: DEFAULT_CONTEST_START_TIME,
        title: 'Weekly coding contest',
      });
    });
    document.getElementById('btnNewMonthlyContest')?.addEventListener('click', () => {
      openManageContests('monthly');
      openTestForm(null, {
        contestType: 'monthly',
        contestMonthDay: Math.min(28, new Date().getDate()),
        contestStartTime: DEFAULT_CONTEST_START_TIME,
        title: 'Monthly coding contest',
      });
    });
    document.getElementById('manageContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyManageContestType(link.getAttribute('data-contest-type'));
    });
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
      const isCompany = formIsCompanyTest();
      const company = selectedCompanyFromTestForm();
      if (isCompany && !company.companyId) {
        toastMsg('Select a company for this test.', 'error');
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
        testKind: isCompany ? 'company' : 'regular',
        companyId: isCompany ? company.companyId : '',
        companyName: isCompany ? company.companyName : '',
        contestType: isCompany ? 'none' : (canManageContests() ? (document.getElementById('tfContestType').value || 'none') : 'none'),
        contestWeekday: Number(document.getElementById('tfContestWeekday').value || 1),
        contestMonthDay: Number(document.getElementById('tfContestMonthDay').value || 1),
        contestStartTime: document.getElementById('tfContestStartTime').value || DEFAULT_CONTEST_START_TIME,
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
        toastMsg(isContestTest(payload) || isContestTest(saved) ? 'Contest saved.' : (isCompany ? 'Company test saved.' : 'Test saved.'), 'success');
        testFormModal.hide();
        await loadManaged();
        if (isCompany) applyManagePanel('jd');
        else if (canManageContests() && (isContestTest(payload) || isContestTest(saved))) {
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
    document.getElementById('bankSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      visibleBankProblems().forEach((q) => {
        const id = String(q.id || '');
        if (!id) return;
        if (on) selectedBankIds.add(id);
        else selectedBankIds.delete(id);
      });
      renderBank();
    });
    document.getElementById('btnBankDeleteSelected')?.addEventListener('click', () => deleteSelectedBankProblems());
    document.getElementById('manageTestsSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      tests.filter((t) => isRegularTest(t)).forEach((t) => {
        const id = String(t.id || '');
        if (!id) return;
        if (on) selectedManageTestIds.add(id);
        else selectedManageTestIds.delete(id);
      });
      renderManage();
    });
    document.getElementById('btnManageTestsDeleteSelected')?.addEventListener('click', () => deleteSelectedManageTests());
    document.getElementById('manageWeeklyContestsSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      tests.filter((t) => String(t.contestType) === 'weekly' && isContestManageActive(t)).forEach((t) => {
        const id = String(t.id || '');
        if (!id) return;
        if (on) selectedManageTestIds.add(id);
        else selectedManageTestIds.delete(id);
      });
      renderManage();
    });
    document.getElementById('btnManageWeeklyContestsDeleteSelected')?.addEventListener('click', () => deleteSelectedManageTests('weekly'));
    document.getElementById('manageMonthlyContestsSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      tests.filter((t) => String(t.contestType) === 'monthly' && isContestManageActive(t)).forEach((t) => {
        const id = String(t.id || '');
        if (!id) return;
        if (on) selectedManageTestIds.add(id);
        else selectedManageTestIds.delete(id);
      });
      renderManage();
    });
    document.getElementById('btnManageMonthlyContestsDeleteSelected')?.addEventListener('click', () => deleteSelectedManageTests('monthly'));
    document.getElementById('jdSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      const block = jdCompanyBlocks.find((b) => String(b.companyId || '') === String(jdSelectedCompanyId || ''));
      (block?.sets || []).forEach((s) => {
        const id = String(s.id || '');
        if (!id) return;
        if (on) selectedJdSetIds.add(id);
        else selectedJdSetIds.delete(id);
      });
      if (jdSelectedCompanyId) showJdCompanyDetail(jdSelectedCompanyId);
    });
    document.getElementById('btnJdDeleteSelected')?.addEventListener('click', () => deleteSelectedJdSets());
    document.getElementById('bankTopicNav')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-bank-topic]');
      if (!btn) return;
      e.preventDefault();
      bankCategoryFilter = btn.getAttribute('data-bank-topic') || '';
      renderBank();
    });
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
      payload.category = payload.category || 'Algorithms';
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
    setupTakeListNav();
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
