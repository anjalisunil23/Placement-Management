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
  let selectedTestCategory = null;
  let selectedManageTestCategory = null;
  let practiceProblems = [];
  let practiceSearch = '';
  const practiceDiffFilters = new Set();
  const practiceTopicFilters = new Set();
  const practiceStatusFilters = new Set();
  let managePracticeSearch = '';
  const managePracticeDiffFilters = new Set();
  const managePracticeTopicFilters = new Set();
  let takeContestType = 'weekly';
  let myResultsView = 'tests';
  let myResultsContestType = 'weekly';
  let myProgress = null;
  let studentJdCompanyBlocks = [];
  let studentJdSelectedCompanyId = null;
  let studentJdBlockView = 'tests';
  let dirFilterBranch = '';
  let dirFilterBatch = '';
  let progressContestType = 'weekly';
  let dirLoadTimer = 0;
  let dirLoadSeq = 0;
  let dirResultsCacheKey = '';
  let dirResultsCache = null;
  let dirFilterCacheKey = '';
  let dirFilterCache = null;
  let dirFiltersBound = false;
  let deptStorePrimed = false;
  let contestPreviewId = null;
  let contestResultsModal = null;
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
  let manualBankAllProblems = [];
  let manualBankRuleCounter = 0;

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
    const test = resolveHistoryTest(row);
    return isCompanyTest(test);
  }

  function historyEntryIsContest(h) {
    const row = h && typeof h === 'object' ? h : {};
    const type = String(row.contestType || '').toLowerCase();
    if (type === 'weekly' || type === 'monthly') return true;
    return isContestTest(resolveHistoryTest(row));
  }

  function resolveHistoryTest(h) {
    const id = String(h?.testId || '');
    const title = String(h?.testTitle || h?.testName || '').trim();
    const byId = id ? tests.find((t) => String(t.id) === id) : null;
    if (byId) return byId;
    return title ? tests.find((t) => String(t.title || '') === title) : null;
  }

  function historyResultMode(h) {
    const row = h && typeof h === 'object' ? h : {};
    if (row.resultVisibility) return String(row.resultVisibility);
    if (access.canManage || access.canViewDirectory) return 'full';
    if (!historyEntryIsContest(row)) return 'full';
    const test = resolveHistoryTest(row);
    if (test && (test.resultStatus === 'PUBLISHED' || test.resultsPublished)) return 'published';
    if (test && Object.prototype.hasOwnProperty.call(test, 'resultsPublished')) {
      return test.resultsPublished ? 'published' : 'pending';
    }
    return row.resultsPublished ? 'published' : 'pending';
  }

  function enrichHistoryEntry(h) {
    if (!h || typeof h !== 'object') return h;
    const copy = { ...h };
    const test = resolveHistoryTest(copy);
    if (test) {
      copy.testId = copy.testId || test.id;
      if (!copy.contestType) copy.contestType = test.contestType || 'none';
      if (!copy.testKind) copy.testKind = isCompanyTest(test) ? 'company' : 'regular';
      if (!copy.companyId && test.companyId) copy.companyId = test.companyId;
      if (!copy.companyName && test.companyName) copy.companyName = test.companyName;
    }
    const pct = Number(copy.percentage);
    const total = Number(copy.totalMarks ?? copy.maximumScore);
    const obtained = Number(copy.marksObtained ?? copy.score);
    if ((!Number.isFinite(obtained) || obtained <= 0) && Number.isFinite(pct) && Number.isFinite(total) && total > 0) {
      copy.marksObtained = Math.round((pct / 100) * total * 100) / 100;
      copy.score = copy.marksObtained;
    }
    if (copy.timeTakenLabel && !copy.timeTakenSeconds) {
      const m = String(copy.timeTakenLabel).match(/^(\d{2}):(\d{2})$/);
      if (m) copy.timeTakenSeconds = Number(m[1]) * 60 + Number(m[2]);
    }
    return copy;
  }

  function formatTimeTaken(seconds) {
    const sec = Math.max(0, Number(seconds) || 0);
    const m = Math.floor(sec / 60);
    return `${String(m).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`;
  }

  function formatHistoryMeta(h) {
    const row = enrichHistoryEntry(h);
    const bits = [];
    if (historyEntryIsContest(row)) {
      bits.push(row.contestScheduleLabel || (row.contestType === 'monthly' ? 'Monthly contest' : 'Weekly contest'));
    }
    const mode = historyResultMode(row);
    if (mode === 'pending') {
      bits.push('Results pending');
      return bits.join(' · ');
    }
    const sec = Number(row.timeTakenSeconds);
    if (row.timeTakenLabel) {
      bits.push(String(row.timeTakenLabel));
    } else if (Number.isFinite(sec) && sec > 0) {
      bits.push(formatTimeTaken(sec));
    }
    const obtained = Number(row.marksObtained ?? row.score);
    const total = Number(row.totalMarks ?? row.maximumScore);
    const pct = Number(row.percentage);
    if (Number.isFinite(obtained) && Number.isFinite(total) && total > 0) {
      const markStr = `${obtained}/${total}`;
      bits.push(Number.isFinite(pct) ? `Score ${markStr} (${pct}%)` : `Score ${markStr}`);
    } else if (Number.isFinite(pct)) {
      bits.push(`Score ${pct}%`);
    }
    return bits.length ? bits.join(' · ') : '—';
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

  async function getStudentJdSetDetail(setId) {
    const id = String(setId || '');
    if (!id) return null;
    const cacheKey = `student:${id}`;
    if (jdSetDetailsCache[cacheKey]) return jdSetDetailsCache[cacheKey];
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/coding/student/company-block/sets/${encodeURIComponent(id)}`).catch(() => null);
      if (res?.data) jdSetDetailsCache[cacheKey] = res.data;
    } else {
      const detail = await getJdSetDetail(id);
      if (detail) jdSetDetailsCache[cacheKey] = detail;
    }
    return jdSetDetailsCache[cacheKey] || null;
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

  function renderJdSetCardsHtml(sets, { allowDelete = true, selectable = false } = {}) {
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
            ${allowDelete ? `<button type="button" class="btn btn-sm btn-outline-danger" data-jd-delete="${esc(id)}" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
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

  function bindJdSetCardEvents(root, { getDetail = getJdSetDetail, allowDelete = true } = {}) {
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
        const detail = await getDetail(id);
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
        const detail = await getDetail(id);
        const qs = detail?.problems || detail?.questions || [];
        panel.innerHTML = qs.length
          ? `<div class="d-flex flex-column gap-3">${qs.map((q, i) => renderCodingProblemDetailHtml(q, i)).join('')}</div>`
          : '<p class="small text-muted-2 mb-0">No problems in this set.</p>';
        panel.classList.remove('d-none');
        btn.textContent = 'Hide';
      });
    });
    if (allowDelete) {
      root.querySelectorAll('[data-jd-delete]').forEach((btn) => {
        btn.addEventListener('click', () => deleteJdSet(btn.getAttribute('data-jd-delete')));
      });
    }
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

  function getQuestionSource() {
    if (document.getElementById('tfSourceRandom')?.checked) return 'random';
    return 'manual';
  }

  function formIsContest() {
    const type = String(document.getElementById('tfContestType')?.value || 'none');
    return type === 'weekly' || type === 'monthly';
  }

  function sumCategoryRuleCounts(rootId) {
    return collectCategoryRules(rootId).reduce((sum, r) => sum + (Number(r.count) || 0), 0);
  }

  function sumManualBankRuleCounts() {
    return collectManualBankRules().reduce((sum, r) => sum + (Number(r.count) || 0), 0);
  }

  function formatProblemCountSummary(allocated) {
    return `${allocated} problem${allocated === 1 ? '' : 's'} assigned`;
  }

  function collectInlineProblems() {
    return [...document.querySelectorAll('#problemList [data-problem]')].map(collectProblem).filter((q) => q.title);
  }

  function getTestFormAllocatedTotal(source = getQuestionSource()) {
    if (source === 'random') return sumCategoryRuleCounts('tfRandomRules');
    const useBank = !formIsCompanyTest() && document.getElementById('tfUseBankManual')?.checked;
    const bankTotal = useBank ? sumManualBankRuleCounts() : 0;
    return bankTotal + collectInlineProblems().length;
  }

  function collectCategoryRules(rootId) {
    return [...document.querySelectorAll(`#${rootId} .tf-category-rule`)].map((row) => ({
      category: row.querySelector('[data-f="category"]')?.value || CATEGORIES[0],
      difficulty: row.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(row.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(1, Number(row.querySelector('[data-f="marks"]')?.value || 2)),
    }));
  }

  function addCategoryRuleRow(rootId, rule = {}, onChange = null) {
    const root = document.getElementById(rootId);
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'tf-category-rule row g-2 align-items-end';
    const cat = normalizeCodingTopic(rule.category || CATEGORIES[0]);
    const diff = rule.difficulty || 'Medium';
    wrap.innerHTML = `
      <div class="col-md-4">
        <label class="form-label small mb-1">Topic</label>
        <select class="form-select form-select-sm" data-f="category">${CATEGORIES.map((c) =>
          `<option value="${esc(c)}" ${c === cat ? 'selected' : ''}>${esc(c)}</option>`
        ).join('')}</select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-f="difficulty">${DIFFICULTIES.map((d) =>
          `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`
        ).join('')}</select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">No. of problems</label>
        <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(rule.count ?? 1)}"/>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Marks each</label>
        <input class="form-control form-control-sm" type="number" min="1" step="1" data-f="marks" value="${esc(rule.marks ?? 2)}"/>
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-rule>Remove</button>
      </div>`;
    wrap.querySelector('[data-remove-rule]')?.addEventListener('click', () => {
      wrap.remove();
      if (onChange) onChange();
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => { if (onChange) onChange(); });
      el.addEventListener('input', () => { if (onChange) onChange(); });
    });
    root.appendChild(wrap);
    if (onChange) onChange();
  }

  function addRandomRuleRow(rule = {}, opts = {}) {
    if (!opts.loading && rule.count == null) rule = { ...rule, count: 1 };
    addCategoryRuleRow('tfRandomRules', rule, updateRandomSummary);
  }

  function collectRandomRules() {
    return collectCategoryRules('tfRandomRules');
  }

  function updateRandomSummary() {
    const rules = collectRandomRules();
    const allocated = rules.reduce((sum, r) => sum + (Number(r.count) || 0), 0);
    const summary = document.getElementById('tfRandomSummary');
    if (summary) {
      summary.textContent = `${formatProblemCountSummary(allocated)} · ${rules.length} topic${rules.length === 1 ? '' : 's'}`;
    }
  }

  function bankProblemMatchesRule(q, criteria) {
    return normalizeCodingTopic(q.category) === normalizeCodingTopic(criteria.category)
      && String(q.difficulty || 'Medium') === String(criteria.difficulty || 'Medium');
  }

  async function ensureManualBankProblemsLoaded() {
    if (manualBankAllProblems.length) return;
    manualBankAllProblems = await CodingService.listBank().catch(() => []);
  }

  function filterManualBankPool(criteria) {
    return manualBankAllProblems.filter((q) => bankProblemMatchesRule(q, criteria));
  }

  function manualRuleCriteriaFromWrap(wrap) {
    return {
      category: wrap.querySelector('[data-f="category"]')?.value || CATEGORIES[0],
      difficulty: wrap.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(wrap.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(1, Number(wrap.querySelector('[data-f="marks"]')?.value || 2)),
    };
  }

  function renderProblemPickDetailHtml(q) {
    return `<div class="fw-semibold">${esc(q.title || 'Untitled')}</div><div class="small text-muted-2">${esc(normalizeCodingTopic(q.category))} · ${esc(q.difficulty || 'Medium')} · ${esc(q.marks || 2)} marks</div>`;
  }

  function bindManualRulePicker(wrap, pool, needed) {
    const state = wrap._manualRuleState;
    wrap.querySelectorAll('[data-manual-pick]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = cb.getAttribute('data-manual-pick');
        if (!id) return;
        if (cb.checked) {
          if (state.selectedIds.size >= needed) { cb.checked = false; return; }
          state.selectedIds.add(String(id));
        } else {
          state.selectedIds.delete(String(id));
          state.collapsed = false;
        }
        if (state.selectedIds.size === needed) {
          state.editing = false;
          state.collapsed = true;
        }
        refreshManualBankBlock(wrap);
      });
    });
  }

  function renderManualRulePicker(wrap, pool, criteria) {
    const picker = wrap.querySelector('.manual-bank-picker');
    if (!picker) return;
    const state = wrap._manualRuleState;
    const needed = criteria.count;
    const selected = state.selectedIds.size;
    const atLimit = selected >= needed;
    if (!pool.length) {
      picker.innerHTML = '<p class="small text-muted-2 mb-0">No problems available for this topic and difficulty.</p>';
      return;
    }
    picker.innerHTML = `
      <div class="small fw-semibold mb-1">Select ${needed} problem${needed === 1 ? '' : 's'}</div>
      <div class="small mb-2 ${selected === needed ? 'text-success fw-semibold' : ''}">Selected: ${selected} / ${needed}</div>
      <div class="d-flex flex-column gap-2">${pool.map((q) => {
        const id = String(q.id || '');
        const checked = state.selectedIds.has(id);
        const disabled = (atLimit && !checked);
        return `<label class="d-block border rounded-2 p-2 mb-0 bg-white ${disabled ? 'opacity-50' : ''}">
          <div class="d-flex align-items-start gap-2">
            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-manual-pick="${esc(id)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}/>
            <div class="min-w-0">${renderProblemPickDetailHtml(q)}</div>
          </div>
        </label>`;
      }).join('')}</div>`;
    bindManualRulePicker(wrap, pool, needed);
  }

  async function refreshManualBankBlock(wrap) {
    if (!wrap?._manualRuleState) return;
    await ensureManualBankProblemsLoaded();
    const state = wrap._manualRuleState;
    const criteria = manualRuleCriteriaFromWrap(wrap);
    const pool = filterManualBankPool(criteria);
    const errorEl = wrap.querySelector('.manual-bank-error');
    const pickerEl = wrap.querySelector('.manual-bank-picker');
    const summaryEl = wrap.querySelector('.manual-bank-summary');
    [...state.selectedIds].forEach((id) => {
      if (!pool.some((q) => String(q.id) === id)) state.selectedIds.delete(id);
    });
    if (pool.length < criteria.count) {
      errorEl?.classList.remove('d-none');
      if (errorEl) {
        errorEl.textContent = pool.length
          ? `Only ${pool.length} problem(s) available for ${criteria.category} — ${criteria.difficulty}. Reduce the count or change topic/difficulty.`
          : `No problems available for ${criteria.category} — ${criteria.difficulty}.`;
      }
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.add('d-none');
      updateManualBankSummary();
      return;
    }
    errorEl?.classList.add('d-none');
    const complete = state.selectedIds.size === criteria.count;
    if (complete && !state.editing) state.collapsed = true;
    if (state.collapsed && !state.editing) {
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.remove('d-none');
      if (summaryEl) {
        summaryEl.innerHTML = `<div class="small text-success"><div>✓ ${esc(criteria.category)}</div><div>✓ ${esc(criteria.difficulty)}</div><div>✓ ${esc(criteria.count)} problem(s) selected</div></div><button type="button" class="btn btn-sm btn-link p-0 mt-1" data-edit-manual-pick>Edit selection</button>`;
        summaryEl.querySelector('[data-edit-manual-pick]')?.addEventListener('click', () => {
          state.editing = true;
          state.collapsed = false;
          refreshManualBankBlock(wrap);
        });
      }
    } else {
      summaryEl?.classList.add('d-none');
      pickerEl?.classList.remove('d-none');
      renderManualRulePicker(wrap, pool, criteria);
    }
    updateManualBankSummary();
  }

  function addManualBankRuleRow(rule = {}, opts = {}) {
    manualBankRuleCounter += 1;
    const root = document.getElementById('tfManualBankRules');
    if (!root) return;
    const cat = normalizeCodingTopic(rule.category || CATEGORIES[0]);
    const diff = rule.difficulty || 'Medium';
    const selectedIds = (rule.selectedQuestionIds || []).map(String);
    const count = Math.max(1, Number(rule.count ?? 1));
    const wrap = document.createElement('div');
    wrap.className = 'tf-manual-bank-block border rounded-3 p-3 mb-2 bg-white';
    wrap.innerHTML = `
      <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Topic</label><select class="form-select form-select-sm" data-f="category">${CATEGORIES.map((c) => `<option value="${esc(c)}" ${c === cat ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Difficulty</label><select class="form-select form-select-sm" data-f="difficulty">${DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`).join('')}</select></div>
        <div class="col-md-2"><label class="form-label small mb-1">No. of problems</label><input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(count)}"/></div>
        <div class="col-md-2"><label class="form-label small mb-1">Marks each</label><input class="form-control form-control-sm" type="number" min="1" data-f="marks" value="${esc(rule.marks ?? 2)}"/></div>
        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-manual-block>Remove</button></div>
      </div>
      <div class="manual-bank-error small text-danger mt-2 d-none"></div>
      <div class="manual-bank-picker mt-2"></div>
      <div class="manual-bank-summary border rounded-2 p-2 mt-2 d-none"></div>`;
    wrap._manualRuleState = { selectedIds: new Set(selectedIds), collapsed: selectedIds.length >= count && selectedIds.length > 0, editing: false };
    wrap.querySelector('[data-remove-manual-block]')?.addEventListener('click', () => { wrap.remove(); updateManualBankSummary(); });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => refreshManualBankBlock(wrap));
      el.addEventListener('input', () => refreshManualBankBlock(wrap));
    });
    root.appendChild(wrap);
    refreshManualBankBlock(wrap);
  }

  function collectManualBankRules() {
    return [...document.querySelectorAll('.tf-manual-bank-block')].map((wrap) => ({
      ...manualRuleCriteriaFromWrap(wrap),
      selectedQuestionIds: [...(wrap._manualRuleState?.selectedIds || [])],
    }));
  }

  function validateManualBankRulesComplete() {
    for (const wrap of document.querySelectorAll('.tf-manual-bank-block')) {
      const c = manualRuleCriteriaFromWrap(wrap);
      const state = wrap._manualRuleState;
      const pool = filterManualBankPool(c);
      if (pool.length < c.count) {
        return pool.length
          ? `Only ${pool.length} problem(s) available for ${c.category} — ${c.difficulty}.`
          : `No problems available for ${c.category} — ${c.difficulty}.`;
      }
      if (!state || state.selectedIds.size !== c.count) {
        return `Select exactly ${c.count} problem(s) for ${c.category} — ${c.difficulty}.`;
      }
    }
    return '';
  }

  function inferBankRulesFromItems(items) {
    const groups = new Map();
    (items || []).filter((q) => q?.bankId).forEach((q) => {
      const category = normalizeCodingTopic(q.category || CATEGORIES[0]);
      const difficulty = q.difficulty || 'Medium';
      const marks = Number(q.marks ?? 2) || 2;
      const key = `${category}|${difficulty}|${marks}`;
      if (!groups.has(key)) {
        groups.set(key, { category, difficulty, count: 0, marks, selectedQuestionIds: [] });
      }
      const g = groups.get(key);
      g.count += 1;
      g.selectedQuestionIds.push(String(q.bankId));
    });
    return [...groups.values()];
  }

  function updateManualBankSummary() {
    const rules = collectManualBankRules();
    const complete = rules.filter((r, i) => {
      const wrap = document.querySelectorAll('.tf-manual-bank-block')[i];
      return wrap?._manualRuleState?.selectedIds?.size === r.count;
    }).length;
    const allocated = getTestFormAllocatedTotal('manual');
    const summary = document.getElementById('tfManualBankSummary');
    if (summary) {
      summary.textContent = `${formatProblemCountSummary(allocated)} · ${rules.length} topic${rules.length === 1 ? '' : 's'} · ${complete}/${rules.length} complete`;
    }
  }

  function syncQuestionSourcePanels() {
    const contest = formIsContest();
    const company = formIsCompanyTest();
    document.getElementById('tfSourceGroup')?.classList.toggle('d-none', company);
    document.getElementById('tfContestBankHint')?.classList.toggle('d-none', !contest);
    syncCompanyTestFormUi();
    const source = getQuestionSource();
    document.getElementById('tfRandomPanel')?.classList.toggle('d-none', source !== 'random');
    document.getElementById('tfManualPanel')?.classList.toggle('d-none', source === 'random');
    if (contest && source === 'manual') {
      const useBankEl = document.getElementById('tfUseBankManual');
      if (useBankEl) useBankEl.checked = true;
      document.getElementById('tfBankPicker')?.classList.remove('d-none');
      document.getElementById('tfManualProblemsSection')?.classList.add('d-none');
    } else {
      document.getElementById('tfManualProblemsSection')?.classList.toggle('d-none', contest);
    }
    updateTestFormQuestionCount();
  }

  function updateTestFormQuestionCount() {
    const source = getQuestionSource();
    if (source === 'random') updateRandomSummary();
    else updateManualBankSummary();
  }

  function collectContestPayload() {
    return {
      contestType: document.getElementById('tfContestType')?.value || 'none',
      contestWeekday: Number(document.getElementById('tfContestWeekday')?.value || 1),
      contestMonthDay: Number(document.getElementById('tfContestMonthDay')?.value || 1),
      contestStartTime: document.getElementById('tfContestStartTime')?.value || DEFAULT_CONTEST_START_TIME,
      contestEndTime: document.getElementById('tfContestEndTime')?.value || '18:00',
    };
  }

  function collectTestFormPayload() {
    const source = getQuestionSource();
    const payload = {
      id: document.getElementById('tfId')?.value?.trim() || '',
      title: document.getElementById('tfTitle')?.value?.trim() || '',
      description: document.getElementById('tfDescription')?.value?.trim() || '',
      questionCount: getTestFormAllocatedTotal(source),
      duration: Number(document.getElementById('tfDuration')?.value || 20),
      durationMinutes: Number(document.getElementById('tfDuration')?.value || 20),
      status: document.getElementById('tfStatus')?.value || 'published',
      questionSource: source,
      instructions: [
        'Read each problem carefully.',
        'Select the programming language before submitting.',
        'Your code will be evaluated against test cases.',
        'Do not refresh the page during the test.',
      ],
      testKind: formIsCompanyTest() ? 'company' : 'regular',
    };
    if (source === 'random') {
      payload.randomRules = collectRandomRules();
      payload.items = [];
      payload.bankFilterRules = [];
      payload.bankProblemIds = [];
    } else {
      payload.randomRules = [];
      const useBank = !formIsCompanyTest() && document.getElementById('tfUseBankManual')?.checked;
      payload.bankFilterRules = useBank ? collectManualBankRules() : [];
      payload.bankProblemIds = useBank
        ? payload.bankFilterRules.flatMap((r) => r.selectedQuestionIds || [])
        : [];
      payload.items = formIsCompanyTest()
        ? collectInlineProblems()
        : (formIsContest() ? [] : collectInlineProblems());
    }
    if (formIsCompanyTest()) {
      const { companyId, companyName } = selectedCompanyFromTestForm();
      payload.companyId = companyId;
      payload.companyName = companyName;
      payload.contestType = 'none';
    } else if (canManageContests()) {
      Object.assign(payload, collectContestPayload());
    }
    return payload;
  }

  function syncCompanyTestFormUi() {
    const company = formIsCompanyTest();
    document.getElementById('tfCompanyPanel')?.classList.toggle('d-none', !company);
    document.getElementById('tfUseBankManual')?.closest('.form-check')?.classList.toggle('d-none', company);
    document.getElementById('tfManualProblemsSection')?.classList.toggle('d-none', false);
    if (company) document.getElementById('tfContestType').value = 'none';
  }

  function syncStudentJdBlockViewNav() {
    document.querySelectorAll('#studentJdBlockViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-jd-block-view') === studentJdBlockView);
    });
  }

  function applyStudentJdBlockView(view) {
    studentJdBlockView = view === 'bank' ? 'bank' : 'tests';
    syncStudentJdBlockViewNav();
    if (studentJdSelectedCompanyId) {
      showStudentJdCompanyDetail(studentJdSelectedCompanyId);
    } else {
      renderStudentJdBlock();
    }
  }

  function showStudentJdCompanyDetail(companyId) {
    const block = studentJdCompanyBlocks.find((b) => String(b.companyId || '') === String(companyId || ''));
    studentJdSelectedCompanyId = companyId;
    document.getElementById('studentJdBlockCompanyView')?.classList.add('d-none');
    document.getElementById('studentJdBlockCompanyDetail')?.classList.remove('d-none');
    document.getElementById('studentJdBlockDetailNav')?.classList.remove('d-none');
    syncStudentJdBlockViewNav();
    const showTests = studentJdBlockView === 'tests';
    const testsRoot = document.getElementById('studentJdBlockCompanyTests');
    const bankSection = document.getElementById('studentJdBlockBankSection');
    testsRoot?.classList.toggle('d-none', !showTests);
    bankSection?.classList.toggle('d-none', showTests);
    if (showTests) {
      const companyTests = studentCompanyTestsFor(companyId);
      if (testsRoot) {
        testsRoot.innerHTML = companyTests.length
          ? `<div class="apt-prob-list">${companyTests.map((t, i) => codingProbRowHtml(t, i)).join('')}</div>`
          : '<p class="small text-muted-2 mb-0">No company coding tests published for this company yet.</p>';
        bindOpenTests(testsRoot, companyTests);
      }
    } else {
      const list = document.getElementById('studentJdBlockSetsList');
      const sets = block?.sets || [];
      if (!list) return;
      list.innerHTML = sets.length
        ? renderJdSetCardsHtml(sets, { allowDelete: false })
        : '<p class="text-muted-2 mb-0">No company problem sets for this company yet.</p>';
      bindJdSetCardEvents(list, { getDetail: getStudentJdSetDetail, allowDelete: false });
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
    syncStudentJdBlockViewNav();
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

  function practiceStatusIcon(status) {
    if (status === 'solved') return '<i class="bi bi-check-circle-fill status-solved"></i>';
    if (status === 'attempted') return '<i class="bi bi-circle status-attempted"></i>';
    return '<i class="bi bi-circle status-unsolved"></i>';
  }

  function practiceProblemRowHtml(p, index) {
    const diff = difficultyClass(p.difficulty);
    return `<div class="cod-problem-row" data-open-practice="${esc(p.id || p.bankId)}">
      <span>${practiceStatusIcon(p.practiceStatus || 'unsolved')}</span>
      <div class="min-w-0">
        <div class="fw-semibold text-truncate">${index + 1}. ${esc(p.title || 'Untitled')}</div>
        <div class="small text-muted-2">${esc(normalizeCodingTopic(p.category))}${p.attemptCount ? ` · ${esc(p.attemptCount)} attempt${p.attemptCount === 1 ? '' : 's'}` : ''}</div>
      </div>
      <span class="apt-prob-diff ${diff.cls}">${esc(p.difficulty || 'Medium')}</span>
    </div>`;
  }

  function groupProblemsByCategory(list) {
    const map = new Map();
    (list || []).forEach((p) => {
      const cat = normalizeCodingTopic(p.category);
      if (!map.has(cat)) map.set(cat, []);
      map.get(cat).push(p);
    });
    return map;
  }

  function categoryProgress(problems) {
    const total = (problems || []).length;
    const solved = (problems || []).filter((p) => p.practiceStatus === 'solved').length;
    return { total, solved, completed: total > 0 && solved === total };
  }

  function topicFilterMeta(category) {
    return TOPIC_FILTERS.find((t) => t.value === category) || { label: category, icon: 'bi-collection', tone: 'all' };
  }

  function categoryTestCardHtml(category, problems) {
    const { total, solved, completed } = categoryProgress(problems);
    if (!total) return '';
    const meta = topicFilterMeta(category);
    return `<button type="button" class="cod-category-card cod-test-card ${completed ? 'is-complete' : ''}" data-open-test-category="${esc(category)}">
      <div class="cod-category-icon"><i class="bi ${esc(meta.icon || 'bi-collection')}"></i></div>
      <div class="fw-semibold">${esc(meta.label || category)}</div>
      <div class="small text-muted-2 mt-1">${esc(solved)}/${esc(total)} solved</div>
      ${completed ? '<div class="small text-success mt-1"><i class="bi bi-check-circle-fill me-1"></i>Completed</div>' : ''}
    </button>`;
  }

  function categoryProblemRowHtml(p, index) {
    const solved = p.practiceStatus === 'solved';
    const diff = difficultyListLabel(p.difficulty);
    return `<button type="button" class="apt-prob-row is-clickable" data-open-category-problem="${esc(p.id || p.bankId)}">
      <span class="apt-prob-check">${solved ? '<i class="bi bi-check-lg"></i>' : ''}</span>
      <span class="apt-prob-title">${index + 1}. ${esc(p.title || 'Untitled')}</span>
      <span class="apt-prob-pct">${p.attemptCount ? esc(p.attemptCount) : '—'}</span>
      <span class="apt-prob-diff ${diff.cls}">${esc(diff.text)}</span>
    </button>`;
  }

  function renderCategoryTestsView() {
    const root = document.getElementById('testList');
    if (!root) return;

    if (selectedTestCategory) {
      const problems = practiceProblems
        .filter((p) => normalizeCodingTopic(p.category) === selectedTestCategory)
        .sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
      const { total, solved, completed } = categoryProgress(problems);
      root.innerHTML = `
        <div class="pt-1">
          <div class="d-flex align-items-center gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-test-category-back aria-label="Back to topics"><i class="bi bi-arrow-left"></i></button>
            <div>
              <h6 class="fw-bold mb-0">${esc(selectedTestCategory)}</h6>
              <div class="small text-muted-2">${esc(solved)}/${esc(total)} solved${completed ? ' · Test completed' : ''}</div>
            </div>
          </div>
          ${problems.length
            ? `<div class="apt-prob-list">${problems.map((p, i) => categoryProblemRowHtml(p, i)).join('')}</div>`
            : '<p class="text-muted-2 mb-0">No problems in this topic yet.</p>'}
        </div>`;
      root.querySelector('[data-test-category-back]')?.addEventListener('click', () => {
        selectedTestCategory = null;
        renderTestList();
      });
      root.querySelectorAll('[data-open-category-problem]').forEach((btn) => {
        btn.addEventListener('click', () => openPracticeProblem(btn.getAttribute('data-open-category-problem')));
      });
      return;
    }

    const byCat = groupProblemsByCategory(practiceProblems);
    const cards = CATEGORIES.map((cat) => categoryTestCardHtml(cat, byCat.get(cat) || [])).filter(Boolean);
    root.innerHTML = `
      <div class="pt-1">
        <h6 class="fw-bold mb-1">Topic tests</h6>
        <p class="small text-muted-2 mb-3">Pick a topic, then solve each problem. Finish every problem to complete that test.</p>
        ${cards.length
          ? `<div class="cod-category-grid">${cards.join('')}</div>`
          : '<p class="text-muted-2 mb-0">No problems in the question bank yet. Ask your placement officer to add problems.</p>'}
      </div>`;
    root.querySelectorAll('[data-open-test-category]').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedTestCategory = btn.getAttribute('data-open-test-category');
        renderTestList();
      });
    });
  }

  function renderManageCategoryTests() {
    const root = document.getElementById('manageTestsList');
    if (!root) return;

    if (selectedManageTestCategory) {
      const problems = bank
        .filter((p) => normalizeCodingTopic(p.category) === selectedManageTestCategory)
        .sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
      root.innerHTML = `
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-manage-test-category-back aria-label="Back to topics"><i class="bi bi-arrow-left"></i></button>
            <div>
              <h6 class="fw-bold mb-0">${esc(selectedManageTestCategory)}</h6>
              <div class="small text-muted-2">${esc(problems.length)} problem${problems.length === 1 ? '' : 's'} in this test</div>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" data-manage-open-bank="${esc(selectedManageTestCategory)}"><i class="bi bi-pencil me-1"></i>Edit in bank</button>
        </div>
        ${problems.length
          ? `<div class="apt-prob-list">${problems.map((p, i) => `<div class="apt-prob-row">
              <span class="apt-prob-check"></span>
              <span class="apt-prob-title">${i + 1}. ${esc(p.title || 'Untitled')}</span>
              <span class="apt-prob-pct">${esc(p.marks || 2)} m</span>
              <span class="apt-prob-diff ${difficultyListLabel(p.difficulty).cls}">${esc(difficultyListLabel(p.difficulty).text)}</span>
            </div>`).join('')}</div>`
          : '<p class="text-muted-2 mb-0">No problems in this topic. Add them from the question bank.</p>'}`;
      root.querySelector('[data-manage-test-category-back]')?.addEventListener('click', () => {
        selectedManageTestCategory = null;
        renderManageCategoryTests();
      });
      root.querySelector('[data-manage-open-bank]')?.addEventListener('click', () => {
        bankCategoryFilter = selectedManageTestCategory;
        applyManagePanel('bank');
        renderBank();
      });
      return;
    }

    const byCat = groupProblemsByCategory(bank);
    const cards = CATEGORIES.map((cat) => {
      const problems = byCat.get(cat) || [];
      if (!problems.length) return '';
      const meta = topicFilterMeta(cat);
      return `<button type="button" class="cod-category-card cod-test-card" data-open-manage-test-category="${esc(cat)}">
        <div class="cod-category-icon"><i class="bi ${esc(meta.icon || 'bi-collection')}"></i></div>
        <div class="fw-semibold">${esc(meta.label || cat)}</div>
        <div class="small text-muted-2 mt-1">${esc(problems.length)} problem${problems.length === 1 ? '' : 's'}</div>
      </button>`;
    }).filter(Boolean);
    root.innerHTML = cards.length
      ? `<div class="cod-category-grid">${cards.join('')}</div>`
      : '<p class="text-muted-2 mb-0">No problems in the bank yet. Add problems under Question bank.</p>';
    root.querySelectorAll('[data-open-manage-test-category]').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedManageTestCategory = btn.getAttribute('data-open-manage-test-category');
        renderManageCategoryTests();
      });
    });
  }

  function filterPracticeProblems(list, { search, diffs, topics, statuses }) {
    const q = String(search || '').trim().toLowerCase();
    return (list || []).filter((p) => {
      if (q) {
        const hay = `${p.title || ''} ${p.category || ''} ${p.difficulty || ''}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      if (diffs.size && !diffs.has(String(p.difficulty || ''))) return false;
      if (topics.size && !topics.has(normalizeCodingTopic(p.category))) return false;
      if (statuses.size && !statuses.has(String(p.practiceStatus || 'unsolved'))) return false;
      return true;
    });
  }

  function renderPracticeFilterGroup(rootId, items, selectedSet, attr) {
    const root = document.getElementById(rootId);
    if (!root) return;
    root.innerHTML = items.map((item) => {
      const val = typeof item === 'object' ? item.value : item;
      const label = typeof item === 'object' ? item.label : item;
      const checked = selectedSet.has(val) ? 'checked' : '';
      return `<label class="form-check small mb-0"><input class="form-check-input" type="checkbox" data-${attr}="${esc(val)}" ${checked}/> ${esc(label)}</label>`;
    }).join('');
  }

  function bindPracticeFilters(prefix) {
    const isManage = prefix === 'manage';
    const diffRoot = isManage ? 'managePracticeDiffFilters' : 'practiceDiffFilters';
    const topicRoot = isManage ? 'managePracticeTopicFilters' : 'practiceTopicFilters';
    const statusRoot = isManage ? null : 'practiceStatusFilters';
    const diffSet = isManage ? managePracticeDiffFilters : practiceDiffFilters;
    const topicSet = isManage ? managePracticeTopicFilters : practiceTopicFilters;
    const statusSet = practiceStatusFilters;
    renderPracticeFilterGroup(diffRoot, DIFFICULTIES, diffSet, `${prefix}-practice-diff`);
    renderPracticeFilterGroup(topicRoot, CATEGORIES, topicSet, `${prefix}-practice-topic`);
    if (statusRoot) {
      renderPracticeFilterGroup(statusRoot, [
        { value: 'solved', label: 'Solved' },
        { value: 'attempted', label: 'Attempted' },
        { value: 'unsolved', label: 'Unsolved' },
      ], statusSet, 'practice-status');
    }
    document.getElementById(diffRoot)?.querySelectorAll(`[data-${prefix}-practice-diff]`).forEach((cb) => {
      cb.addEventListener('change', () => {
        const val = cb.getAttribute(`data-${prefix}-practice-diff`);
        if (cb.checked) diffSet.add(val); else diffSet.delete(val);
        renderPracticeProblemsList(prefix);
      });
    });
    document.getElementById(topicRoot)?.querySelectorAll(`[data-${prefix}-practice-topic]`).forEach((cb) => {
      cb.addEventListener('change', () => {
        const val = cb.getAttribute(`data-${prefix}-practice-topic`);
        if (cb.checked) topicSet.add(val); else topicSet.delete(val);
        renderPracticeProblemsList(prefix);
      });
    });
    if (statusRoot) {
      document.getElementById(statusRoot)?.querySelectorAll('[data-practice-status]').forEach((cb) => {
        cb.addEventListener('change', () => {
          const val = cb.getAttribute('data-practice-status');
          if (cb.checked) statusSet.add(val); else statusSet.delete(val);
          renderPracticeProblemsList(prefix);
        });
      });
    }
  }

  function renderPracticeProblemsList(prefix = 'student') {
    const isManage = prefix === 'manage';
    const listRoot = document.getElementById(isManage ? 'managePracticeProblemsList' : 'practiceProblemsList');
    if (!listRoot) return;
    const filtered = filterPracticeProblems(practiceProblems, {
      search: isManage ? managePracticeSearch : practiceSearch,
      diffs: isManage ? managePracticeDiffFilters : practiceDiffFilters,
      topics: isManage ? managePracticeTopicFilters : practiceTopicFilters,
      statuses: isManage ? new Set() : practiceStatusFilters,
    });
    listRoot.innerHTML = filtered.length
      ? filtered.map((p, i) => practiceProblemRowHtml(p, i)).join('')
      : '<p class="text-muted-2 mb-0 p-3">No problems match your filters.</p>';
    listRoot.querySelectorAll('[data-open-practice]').forEach((row) => {
      row.addEventListener('click', () => openPracticeProblem(row.getAttribute('data-open-practice')));
    });
  }

  async function loadPracticeProblems() {
    practiceProblems = await CodingService.listPracticeProblems();
  }

  async function renderPracticeSubmissions() {
    const root = document.getElementById('practiceSubmissionsList');
    if (!root) return;
    try {
      const rows = await CodingService.listPracticeSubmissions();
      root.innerHTML = rows.length
        ? `<div class="apt-prob-list">${rows.map((r) => `<div class="apt-prob-row">
            <span class="apt-prob-check">${r.accepted ? '<i class="bi bi-check-lg"></i>' : ''}</span>
            <span class="apt-prob-title">${esc(r.problemTitle || 'Problem')}</span>
            <span class="apt-prob-pct">${esc(r.dateLabel || '')}</span>
            <span class="apt-prob-diff ${r.accepted ? 'is-easy' : 'is-hard'}">${esc(r.status || '')}</span>
          </div>`).join('')}</div>`
        : '<p class="text-muted-2 mb-0">No practice submissions yet. Open a test topic and solve a problem.</p>';
    } catch (err) {
      root.innerHTML = `<p class="text-muted-2 mb-0">${esc(err?.message || 'Could not load submissions.')}</p>`;
    }
  }

  async function openPracticeProblem(id) {
    if (!id || !exam) return;
    try {
      const problem = await CodingService.getPracticeProblem(id);
      document.getElementById('hubView').classList.add('d-none');
      exam.openPractice(problem);
    } catch (err) {
      toastMsg(err?.message || 'Could not open problem.', 'error');
    }
  }

  function applyTakeListPanel(panel) {
    const allowed = ['tests', 'contests', 'submissions', 'jdblock'];
    if (panel !== 'tests') selectedTestCategory = null;
    takeListPanel = allowed.includes(panel) ? panel : 'tests';
    document.querySelectorAll('#takeListNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-take-list') === takeListPanel);
    });
    document.getElementById('takeContestTypeNav')?.classList.toggle('d-none', takeListPanel !== 'contests');
    document.getElementById('practiceSubmissionsPanel')?.classList.toggle('d-none', takeListPanel !== 'submissions');
    document.getElementById('testList')?.classList.toggle('d-none', takeListPanel !== 'tests' && takeListPanel !== 'contests');
    document.getElementById('studentJdBlockPanel')?.classList.toggle('d-none', takeListPanel !== 'jdblock');
    syncContestTypeNav('takeContestTypeNav', takeContestType, 'data-take-contest-type');
    if (takeListPanel === 'jdblock') {
      loadStudentJdBlock().catch(() => {});
    } else if (takeListPanel === 'submissions') {
      renderPracticeSubmissions().catch(() => {});
    } else if (takeListPanel === 'tests') {
      loadPracticeProblems().then(() => renderTestList()).catch(() => renderTestList());
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
    if (myProgress) renderHistory(myProgress);
  }

  function applyMyResultsContestType(type) {
    myResultsContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('myResultsContestTypeNav', myResultsContestType, 'data-results-contest-type');
    if (myProgress) renderHistory(myProgress);
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
      next.canManage = typeof Auth !== 'undefined' && typeof Auth.canManageCodingTests === 'function'
        ? Auth.canManageCodingTests()
        : !!base.canManage;
      next.canViewDirectory = typeof Auth !== 'undefined' && typeof Auth.canViewCodingDirectory === 'function'
        ? Auth.canViewCodingDirectory()
        : !!base.canViewDirectory;
    } else if (role === 'admin') {
      next.canTake = false;
      next.canManage = typeof Auth !== 'undefined' && typeof Auth.canManageCodingTests === 'function'
        ? Auth.canManageCodingTests()
        : !!base.canManage;
      next.canViewDirectory = typeof Auth !== 'undefined' && typeof Auth.canViewCodingDirectory === 'function'
        ? Auth.canViewCodingDirectory()
        : !!base.canViewDirectory;
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
    if (views.includes('manage') && (role === 'placement_officer' || role === 'admin')) return 'manage';
    if (views.includes('progress') && (role === 'placement_officer' || role === 'admin' || role === 'staff')) return 'progress';
    if (views.includes('take')) return 'take';
    if (views.includes('progress')) return 'progress';
    return views[0] || 'take';
  }

  function resolveActiveView(requested) {
    const views = allowedViews();
    let view = requested || defaultView();
    const role = currentRole();
    if (!access.canTake || role === 'placement_officer' || role === 'admin' || role === 'staff') {
      if (view === 'take' || !views.includes(view)) {
        view = views.includes('manage') && (role === 'placement_officer' || role === 'admin')
          ? 'manage'
          : (views.includes('progress') ? 'progress' : defaultView());
      }
    }
    if (!views.includes(view)) view = defaultView();
    return view;
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
    const view = resolveActiveView(requested);
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
        access = applyRoleAccess({
          canTake: res.data.canTake !== undefined ? !!res.data.canTake : access.canTake,
          canManage: res.data.canManage !== undefined ? !!res.data.canManage : access.canManage,
          canViewDirectory: res.data.canViewDirectory !== undefined ? !!res.data.canViewDirectory : access.canViewDirectory,
          scope: res.data.scope || access.scope,
        });
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
    updateManageScopeHint(access.scope || {});
  }

  function updateManageScopeHint(scope = access.scope || {}) {
    const hint = document.getElementById('manageScopeHint');
    if (!hint) return;
    const role = currentRole();
    if (role === 'placement_officer') {
      const name = resolveDepartmentLabel(
        scope.departmentId || '',
        scope.departmentName || '',
        Auth.user()?.department || ''
      );
      hint.textContent = name
        ? `Same tools as admin — scoped to ${name} students and tests only.`
        : (scope.label || 'No department assigned — contact admin to manage coding tests.');
      hint.classList.remove('d-none');
      return;
    }
    hint.textContent = '';
    hint.classList.add('d-none');
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

  function renderHistory(p) {
    const hist = (p.history || []).map((h) => enrichHistoryEntry(h));
    const filtered = hist.filter((h) => {
      if (myResultsView === 'contests') {
        return historyEntryIsContest(h) && contestTypeOf(h) === myResultsContestType;
      }
      if (myResultsView === 'company') {
        return historyEntryIsCompany(h);
      }
      return !historyEntryIsContest(h) && !historyEntryIsCompany(h);
    });
    const canReview = access.canTake;
    const contestLabel = myResultsContestType === 'monthly' ? 'monthly' : 'weekly';
    const emptyLabel = myResultsView === 'contests'
      ? `No ${contestLabel} contest attempts yet.`
      : (myResultsView === 'company'
        ? 'No company test attempts yet.'
        : 'No attempts yet. Open a topic test or contest to begin.');
    const root = document.getElementById('myHistory');
    if (!root) return;
    root.innerHTML = filtered.length
      ? filtered.slice(0, 8).map((h) => {
          const attemptId = h.attemptId || h.id;
          const mode = historyResultMode(h);
          const viewBtn = canReview && attemptId && Auth.hasRealAuth() && !Auth.isDemo()
            ? `<button type="button" class="btn btn-link btn-sm p-0" data-view-attempt="${esc(attemptId)}">${mode === 'pending' ? 'Status' : (mode === 'published' ? 'Result' : 'View')}</button>`
            : '';
          return `<div class="d-flex justify-content-between align-items-start border-bottom py-2 gap-2">
            <div class="min-w-0">
              <div class="text-truncate fw-medium">${esc(h.testTitle || h.testName || 'Test')}</div>
              <div class="small text-muted-2">${esc(formatHistoryMeta(h))}</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0 pt-1">${viewBtn}</div>
          </div>`;
        }).join('')
      : `<p class="text-muted-2 mb-0">${emptyLabel}</p>`;
    root.querySelectorAll('[data-view-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => viewCodingAttemptResult(btn.getAttribute('data-view-attempt')));
    });
  }

  async function viewCodingAttemptResult(attemptId) {
    if (!attemptId) return;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      toastMsg('Detailed results are available in a live student session.', 'info');
      return;
    }
    const res = await api(`/coding/attempts/${encodeURIComponent(attemptId)}/result`).catch(() => null);
    if (!res?.success) {
      toastMsg(res?.message || 'Could not load result.', 'error');
      return;
    }
    document.getElementById('hubView')?.classList.add('d-none');
    exam?.showResult(res.data);
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
    if (!root || takeListPanel === 'jdblock' || takeListPanel === 'submissions') return;

    if (takeListPanel === 'tests') {
      renderCategoryTestsView();
      return;
    }

    const published = (list || tests || []).filter((t) => (t.status || 'published') === 'published');
    const visible = published.filter((t) => {
      if (!isContestTest(t)) return false;
      if (String(t.contestType || '') !== takeContestType) return false;
      return true;
    });
    if (!visible.length) {
      const contestLabel = takeContestType === 'monthly' ? 'monthly' : 'weekly';
      root.innerHTML = `<p class="text-muted-2 mb-0">No ${contestLabel} coding contests are open right now, or none are published yet.</p>`;
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
      renderHistory(progress);
      if (takeListPanel === 'jdblock') await loadStudentJdBlock();
      else if (takeListPanel === 'submissions') await renderPracticeSubmissions();
      else {
        if (takeListPanel === 'tests') await loadPracticeProblems();
        renderTestList();
      }
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
    if (managePanel !== 'tests') selectedManageTestCategory = null;
    document.getElementById('manageTestsPanel')?.classList.toggle('d-none', managePanel !== 'tests');
    document.getElementById('manageContestsPanel')?.classList.toggle('d-none', managePanel !== 'contests');
    document.getElementById('manageBankPanel')?.classList.toggle('d-none', managePanel !== 'bank');
    document.getElementById('manageJdPanel')?.classList.toggle('d-none', managePanel !== 'jd');
    document.querySelectorAll('#manageViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-manage-view') === managePanel);
    });
    if (managePanel === 'contests') applyManageContestType(manageContestType);
    if (managePanel === 'tests') renderManageCategoryTests();
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
    let lastError = '';
    for (const id of ids) {
      try {
        await CodingService.deleteTest(id);
        deleted += 1;
        selectedManageTestIds.delete(String(id));
      } catch (err) {
        lastError = err?.message || lastError;
      }
    }
    if (!deleted) {
      toastMsg(lastError || 'Could not delete selected items.', 'error');
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
    let lastError = '';
    for (const id of ids) {
      const res = await api(`/coding/company-block/sets/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
      if (res?.success) {
        deleted += 1;
        delete jdSetDetailsCache[String(id)];
        selectedJdSetIds.delete(String(id));
      } else if (res?.message) {
        lastError = res.message;
      }
    }
    if (!deleted) {
      toastMsg(lastError || 'Could not delete selected company problem sets.', 'error');
      return;
    }
    toastMsg(`Deleted ${deleted} company problem set(s).`, 'success');
    await loadJdLibrary();
  }

  function renderManage() {
    if (!access.canManage) return;
    updateManageScopeHint(access.scope || {});
    syncManageContestActions();
    applyManagePanel(managePanel);
    if (managePanel === 'tests') renderManageCategoryTests();
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
          <div class="col-md-6"><label class="form-label small mb-1">Topic</label>
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
    updateManualBankSummary();
  }

  function initContestMonthDaySelect() {
    const el = document.getElementById('tfContestMonthDay');
    if (!el || String(el.tagName || '').toUpperCase() !== 'SELECT') return;
    if (el.options.length > 0) return;
    el.innerHTML = Array.from({ length: 28 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join('');
  }

  function ensureTestFormModal() {
    const el = document.getElementById('testFormModal');
    if (!el) return null;
    if (!testFormModal) testFormModal = new bootstrap.Modal(el);
    return testFormModal;
  }

  async function openTestForm(test = null, preset = null) {
    try {
      const modal = ensureTestFormModal();
      if (!modal) {
        toastMsg('Test form is not available on this page.', 'error');
        return;
      }

      const isContestPreset = preset?.contestType === 'weekly' || preset?.contestType === 'monthly';
      const isCompanyPreset = preset?.testKind === 'company' || isCompanyTest(test);
      const isContest = isContestTest(test) || isContestPreset;
      document.getElementById('testFormTitle').textContent = test
        ? (isContest ? 'Edit contest' : (isCompanyPreset ? 'Edit company test' : 'Edit coding test'))
        : (isContestPreset ? `New ${preset.contestType} contest` : (isCompanyPreset ? 'New company test' : 'New coding test'));
      document.getElementById('tfTestKind').value = isCompanyPreset ? 'company' : 'regular';
      document.getElementById('tfId').value = test?.id || '';
      document.getElementById('tfTitle').value = test?.title || preset?.title || '';
      document.getElementById('tfDescription').value = test?.description || '';
      document.getElementById('tfDuration').value = test?.duration || test?.durationMinutes || 20;
      document.getElementById('tfStatus').value = test
        ? (test.status === 'unpublished' ? 'unpublished' : 'published')
        : 'published';

      let source = test?.questionSource === 'random' ? 'random' : 'manual';
      if (isCompanyPreset) source = 'manual';
      else if (isContestPreset && !test) source = 'random';
      document.getElementById('tfSourceManual').checked = source === 'manual';
      document.getElementById('tfSourceRandom').checked = source === 'random';

      document.getElementById('tfRandomRules').innerHTML = '';
      const rules = test?.randomRules?.length ? test.randomRules : [];
      if (source === 'random' && rules.length) rules.forEach((r) => addRandomRuleRow(r, { loading: true }));

      document.getElementById('tfManualBankRules').innerHTML = '';
      manualBankRuleCounter = 0;
      manualBankAllProblems = [];
      let bankRules = test?.bankFilterRules?.length ? test.bankFilterRules : [];
      if (!bankRules.length && source === 'manual' && (test?.items || []).some((q) => q.bankId)) {
        bankRules = inferBankRulesFromItems(test?.items || []);
      }
      const useBank = bankRules.length > 0;
      const useBankEl = document.getElementById('tfUseBankManual');
      if (useBankEl) useBankEl.checked = useBank;
      document.getElementById('tfBankPicker')?.classList.toggle('d-none', !useBank);
      if (useBank) {
        ensureManualBankProblemsLoaded().then(() => {
          bankRules.forEach((r) => addManualBankRuleRow(r, { loading: true }));
          updateManualBankSummary();
        }).catch(() => bankRules.forEach((r) => addManualBankRuleRow(r, { loading: true })));
      }

      initContestMonthDaySelect();
      const contestType = isCompanyPreset ? 'none' : (test?.contestType || preset?.contestType || 'none');
      document.getElementById('tfContestType').value = ['weekly', 'monthly'].includes(contestType) ? contestType : 'none';
      document.getElementById('tfContestWeekday').value = String(test?.contestWeekday || preset?.contestWeekday || 1);
      document.getElementById('tfContestMonthDay').value = String(test?.contestMonthDay || preset?.contestMonthDay || 1);
      document.getElementById('tfContestStartTime').value = test?.contestStartTime || preset?.contestStartTime || DEFAULT_CONTEST_START_TIME;
      document.getElementById('tfContestEndTime').value = test?.contestEndTime || preset?.contestEndTime || '18:00';

      const list = document.getElementById('problemList');
      if (list) list.innerHTML = '';
      const inlineItems = source === 'manual'
        ? (test?.items || []).filter((q) => !q.bankId)
        : [];
      if (inlineItems.length) inlineItems.forEach((q) => addProblemToForm(q));

      syncQuestionSourcePanels();
      updateTestFormQuestionCount();
      modal.show();

      if (isCompanyPreset || isCompanyTest(test)) {
        fillTfCompanySelect(test?.companyId || preset?.companyId || '').catch(() => {});
      }
    } catch (err) {
      console.error('openTestForm failed', err);
      toastMsg(err?.message || 'Could not open test form.', 'error');
    }
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
    if (managePanel === 'tests') renderManageCategoryTests();
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

  function studentIdLabel(r) {
    return r.registerNumber || r.studentCode || r.studentId || '—';
  }

  function staffNeedsClassFilter() {
    return Auth.role() === 'staff';
  }

  function hasStaffDirectoryLookup() {
    return !!(dirFilterBranch || dirFilterBatch);
  }

  function dirOptionLabel(value) {
    const raw = String(value || '').trim();
    if (typeof resolveCollegeProgrammeLabel === 'function') {
      const catalog = resolveCollegeProgrammeLabel(raw);
      if (catalog) return catalog;
    }
    return raw;
  }

  function fillDirSelect(el, values, allLabel, selected = '') {
    if (!el) return;
    const current = selected || el.value;
    el.innerHTML = `<option value="">${esc(allLabel)}</option>${(values || []).map((v) => {
      const value = String(v || '').trim();
      if (!value) return '';
      return `<option value="${esc(value)}">${esc(dirOptionLabel(value))}</option>`;
    }).join('')}`;
    if (current && [...el.options].some((o) => o.value === current)) el.value = current;
    else el.value = '';
  }

  function fillDirTypeSelect(types = []) {
    const el = document.getElementById('fType');
    if (!el) return;
    const current = el.value;
    const items = types.length ? types : [
      { value: 'student', label: 'Students' },
      { value: 'alumni', label: 'Alumni' },
    ];
    el.innerHTML = `<option value="">All types</option>${items.map((t) =>
      `<option value="${esc(t.value)}">${esc(t.label || t.value)}</option>`).join('')}`;
    if (current && [...el.options].some((o) => o.value === current)) el.value = current;
    else el.value = '';
  }

  async function fetchProgressFilterOptions(params = {}) {
    const qs = new URLSearchParams();
    if (params.department) qs.set('department', params.department);
    if (params.course) qs.set('course', params.course);
    if (params.class) qs.set('class', params.class);
    const q = qs.toString();
    const res = await api('/coding/progress/filters' + (q ? `?${q}` : '')).catch(() => null);
    return res?.success ? res.data : null;
  }

  function resolveDepartmentLabel(id, fallbackName = '', fallbackCode = '') {
    if (typeof DepartmentStore !== 'undefined' && id) {
      const hit = DepartmentStore.all().find((d) => String(d.id || d._id || '') === String(id));
      if (hit?.name) return String(hit.name);
    }
    if (fallbackName) return String(fallbackName);
    if (id && typeof departmentDisplayName === 'function') return departmentDisplayName(id);
    if (fallbackCode && typeof departmentDisplayName === 'function') return departmentDisplayName(fallbackCode);
    return fallbackCode || fallbackName || '';
  }

  function syncContestTypeNav(navId, type, attr) {
    document.querySelectorAll(`#${navId} .nav-link`).forEach((link) => {
      link.classList.toggle('active', link.getAttribute(attr) === type);
    });
  }

  function contestResultStatusLabel(contest) {
    const status = contest?.resultStatus || (contest?.resultsPublished ? 'PUBLISHED' : 'PENDING');
    return status === 'PUBLISHED' ? 'Published' : 'Not published';
  }

  function formatContestDateTime(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(undefined, {
      day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  }

  function formatAttemptScore(row) {
    const obtained = Number(row.marksObtained ?? row.score);
    const total = Number(row.totalMarks ?? row.maximumScore);
    const pct = Number(row.percentage);
    if (Number.isFinite(obtained) && Number.isFinite(total) && total > 0) {
      return Number.isFinite(pct) ? `${obtained}/${total} (${pct}%)` : `${obtained}/${total}`;
    }
    return Number.isFinite(pct) ? `${pct}%` : '—';
  }

  function mergeCompletedContestRows(apiContests = [], completedContests = []) {
    const byId = new Map();
    (completedContests || []).forEach((c) => {
      const id = String(c.id || c.testId || '');
      if (id) byId.set(id, { ...c, testId: id, id });
    });
    (apiContests || []).forEach((c) => {
      const id = String(c.testId || c.id || '');
      if (!id) return;
      const prev = byId.get(id) || {};
      byId.set(id, {
        ...prev,
        ...c,
        testId: id,
        id,
        participants: c.participants || prev.participants || [],
        participantCount: c.participantCount ?? prev.participantCount ?? (c.participants || prev.participants || []).length,
      });
    });
    if (byId.size === 0) {
      tests.filter((t) => isContestTest(t) && contestStatusClient(t) === 'COMPLETED').forEach((t) => {
        const id = String(t.id);
        byId.set(id, {
          ...t,
          testId: id,
          id,
          participants: [],
          participantCount: Number(t.attemptCount ?? 0),
        });
      });
    }
    return [...byId.values()].sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
  }

  function bindDirContestActions(root) {
    if (!root) return;
    root.querySelectorAll('[data-view-contest-results]').forEach((btn) => {
      btn.addEventListener('click', () => openContestResultsPreview(btn.getAttribute('data-view-contest-results')));
    });
    root.querySelectorAll('[data-publish-results]').forEach((btn) => {
      btn.addEventListener('click', () => setContestResultsPublished(btn.getAttribute('data-publish-results'), true));
    });
    root.querySelectorAll('[data-unpublish-results]').forEach((btn) => {
      btn.addEventListener('click', () => setContestResultsPublished(btn.getAttribute('data-unpublish-results'), false));
    });
    root.querySelectorAll('[data-view-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt'), btn.getAttribute('data-detail')));
    });
    root.querySelectorAll('[data-detail]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentDetail(btn.getAttribute('data-detail')));
    });
  }

  function renderProgressContestRow(c) {
    const id = String(c.testId || c.id || '');
    const window = {
      start: c.contestStartAt || c.contestWindow?.start,
      end: c.contestEndAt || c.contestWindow?.end,
    };
    const resultStatus = c.resultStatus || (c.resultsPublished ? 'PUBLISHED' : 'PENDING');
    const resultLabel = contestResultStatusLabel(c);
    const resultCls = resultStatus === 'PUBLISHED' ? 'success' : 'warning';
    const participants = Number(c.participantCount ?? (c.participants || []).length ?? 0);
    const canManageResults = access.canManage;
    const publishBtn = !canManageResults
      ? ''
      : (resultStatus === 'PUBLISHED'
        ? `<button type="button" class="btn btn-sm btn-outline-warning" data-unpublish-results="${esc(id)}">Hide results</button>`
        : `<button type="button" class="btn btn-sm btn-success" data-publish-results="${esc(id)}">Publish Result</button>`);
    const scheduleLines = [
      esc(c.contestScheduleLabel || contestScheduleLabel(c)),
      window.start ? `Start: ${esc(formatContestDateTime(window.start))}` : '',
      window.end ? `End: ${esc(formatContestDateTime(window.end))}` : '',
    ].filter(Boolean);
    const publishedAt = c.resultPublishedAt
      ? `<div class="small text-muted-2 mt-1">Published ${esc(formatContestDateTime(c.resultPublishedAt))}</div>`
      : '';

    return `<tr>
      <td class="fw-semibold">${esc(c.title || 'Contest')}</td>
      <td class="small text-muted-2">${scheduleLines.join('<br>')}</td>
      <td>
        <div class="d-flex flex-wrap gap-1">
          <span class="badge-soft muted">Completed</span>
          <span class="badge-soft ${resultCls}">${esc(resultLabel)}</span>
        </div>
        ${publishedAt}
      </td>
      <td>${esc(participants)}</td>
      <td class="text-nowrap">
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-view-contest-results="${esc(id)}">View Results</button>
          ${publishBtn}
        </div>
      </td>
    </tr>`;
  }

  function renderProgressContestTable(contests, emptyMsg) {
    if (!contests.length) {
      return `<p class="text-muted-2 mb-0">${emptyMsg}</p>`;
    }
    return `<div class="table-wrap mb-0"><table class="table-modern table-sm mb-0"><thead><tr>
      <th>Title</th><th>Schedule</th><th>Status</th><th>Participants</th><th>Actions</th>
    </tr></thead><tbody>${contests.map((c) => renderProgressContestRow(c)).join('')}</tbody></table></div>`;
  }

  function renderContestResults(contests, summary, scope = {}, completedContests = []) {
    document.getElementById('dirTestResultsWrap')?.classList.add('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirStats')?.classList.add('d-none');
    document.getElementById('dirStats').innerHTML = '';
    document.getElementById('progressContestTypeNav')?.classList.remove('d-none');

    const merged = mergeCompletedContestRows(contests, completedContests)
      .filter((c) => String(c.contestType || '') === progressContestType);

    const role = Auth.role();
    const label = progressContestType === 'monthly' ? 'monthly' : 'weekly';
    const emptyMsg = role === 'staff' && (!staffAssignedBatches().length && !(scope.assignedClassBatches || []).length)
      ? 'No class is assigned to your account. Contact the placement office to monitor contest results.'
      : `No completed ${label} contests yet. Finished contests will appear here after their scheduled day ends.`;

    const root = document.getElementById('dirContestSections');
    if (!root) return;

    if (!merged.length) {
      root.innerHTML = `<p class="text-muted-2 mb-0">${emptyMsg}</p>`;
      return;
    }

    root.innerHTML = renderProgressContestTable(merged, emptyMsg);
    bindDirContestActions(root);
  }

  function renderDirectoryTable(rows, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.add('d-none');
    document.getElementById('progressContestTypeNav')?.classList.add('d-none');
    document.getElementById('dirStats')?.classList.add('d-none');
    document.getElementById('dirStats').innerHTML = '';

    const role = Auth.role();
    const staffBatches = staffAssignedBatches();
    const hasAssignedClass = staffBatches.length || (scope.assignedClassBatches || []).length;
    const emptyMsg = progressPanel === 'contests'
      ? (role === 'staff' && !hasAssignedClass
        ? 'No class is assigned to your account. Contact the placement office to monitor contest results.'
        : 'No contest results in your authorized scope yet.')
      : (role === 'staff' && !staffBatches.length
        ? 'No class is assigned to your account. Contact the placement office to monitor student coding progress.'
        : (role === 'staff' && !hasStaffDirectoryLookup()
          ? 'Select your class batch above to view coding results for your students.'
          : (progressPanel === 'company'
            ? 'No company test results in your authorized scope yet.'
            : 'No test results in your authorized scope yet.')));

    const canViewDetail = Auth.hasRealAuth() && !Auth.isDemo();
    document.getElementById('dirRows').innerHTML = rows.length ? rows.map((r) => {
      const attemptId = String(r.attemptId || r.id || '');
      const userId = String(r.userId || '');
      const viewBtn = canViewDetail && userId
        ? `<button type="button" class="btn btn-sm btn-outline-primary" data-view-attempt="${esc(attemptId)}" data-detail="${esc(userId)}">View</button>`
        : `<button type="button" class="btn btn-sm btn-outline-secondary" data-detail="${esc(userId)}">Profile</button>`;
      return `<tr>
        <td class="fw-semibold">${esc(r.name || '—')}</td>
        <td>${esc(r.registerNumber || studentIdLabel(r))}</td>
        <td>${esc(r.classBatch || '—')}</td>
        <td>${esc(r.attemptCount ?? '—')}</td>
        <td>${esc(formatAttemptScore(r))}</td>
        <td>${esc(r.testTitle || r.testName || '—')}</td>
        <td>${viewBtn}</td>
      </tr>`;
    }).join('')
      : `<tr><td colspan="7" class="text-muted-2 p-3">${emptyMsg}</td></tr>`;

    document.getElementById('dirRows').querySelectorAll('[data-view-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt'), btn.getAttribute('data-detail')));
    });
    document.getElementById('dirRows').querySelectorAll('[data-detail]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentDetail(btn.getAttribute('data-detail')));
    });
  }

  function demoProgressFilterOptions() {
    const u = Auth.user() || {};
    const role = Auth.role();
    const departments = [];
    if (role === 'admin') {
      const depts = typeof listStudentAcademicDepartments === 'function'
        ? listStudentAcademicDepartments()
        : (typeof DepartmentStore !== 'undefined' ? DepartmentStore.all() : []);
      depts.forEach((d) => {
        departments.push({
          id: String(d._id || d.id || ''),
          name: String(d.name || d.code || ''),
          code: String(d.code || ''),
        });
      });
    } else if (u.departmentId || u.departmentName || u.department || access.scope?.departmentId) {
      const deptId = String(u.departmentId || access.scope?.departmentId || '');
      departments.push({
        id: deptId,
        name: resolveDepartmentLabel(deptId, u.departmentName || access.scope?.departmentName || '', u.department || ''),
        code: String(u.department || ''),
      });
    }
    const assigned = role === 'staff' && typeof staffClassInchargeBatches === 'function'
      ? staffClassInchargeBatches()
      : (Array.isArray(u.assignedClassBatches) ? u.assignedClassBatches : []);
    const batches = assigned.length
      ? assigned
      : (role === 'staff' ? [] : [
        'MCAINT2022-27-S9',
        'MCA2025-27-S3',
      ]);
    const branches = ['INMCA', 'MCA', 'BCA'];
    const types = role === 'admin'
      ? [{ value: 'student', label: 'Students' }, { value: 'alumni', label: 'Alumni' }]
      : [{ value: 'student', label: 'Students' }];
    return { departments, branches, batches, types };
  }

  function showDirectoryLoading() {
    if (progressPanel === 'contests') {
      const root = document.getElementById('dirContestSections');
      if (root) root.innerHTML = '<p class="text-muted-2 mb-0">Loading contest results…</p>';
      return;
    }
    const rows = document.getElementById('dirRows');
    if (rows) rows.innerHTML = '<tr><td colspan="7" class="text-muted-2 p-3">Loading results…</td></tr>';
  }

  function scheduleLoadDirectory(delay = 180) {
    window.clearTimeout(dirLoadTimer);
    dirLoadTimer = window.setTimeout(() => {
      loadDirectory().catch(() => {});
    }, delay);
  }

  async function viewAttemptResult(attemptId, userId) {
    if (userId) {
      openStudentDetail(userId);
      return;
    }
    if (!attemptId) return;
    toastMsg('Could not open student result.', 'error');
  }

  function renderContestPreviewTable(participants) {
    if (!participants.length) {
      return '<p class="text-muted-2 mb-0">No participants submitted this contest yet.</p>';
    }
    const rows = participants.map((p) => `<tr>
      <td>${esc(p.rank ?? '—')}</td>
      <td class="fw-semibold">${esc(p.name || '—')}</td>
      <td>${esc(p.registerNumber || p.studentCode || '—')}</td>
      <td>${esc(p.marksObtained ?? p.score ?? '—')} / ${esc(p.totalMarks ?? '—')}</td>
      <td>${esc(p.percentage ?? '—')}%</td>
    </tr>`).join('');
    return `<div class="table-wrap"><table class="table-modern table-sm mb-0"><thead><tr>
      <th>Rank</th><th>Student</th><th>Register No.</th><th>Score</th><th>Percentage</th>
    </tr></thead><tbody>${rows}</tbody></table></div>`;
  }

  async function openContestResultsPreview(id) {
    if (!id) return;
    contestPreviewId = id;
    const titleEl = document.getElementById('contestResultsModalTitle');
    const metaEl = document.getElementById('contestResultsModalMeta');
    const bodyEl = document.getElementById('contestResultsModalBody');
    const footerEl = document.getElementById('contestResultsModalFooter');
    if (!bodyEl || !footerEl) return;
    bodyEl.innerHTML = '<p class="text-muted-2 mb-0">Loading results…</p>';
    footerEl.innerHTML = '';
    contestResultsModal?.show();

    let data = null;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/coding/tests/${encodeURIComponent(id)}/contest-results`).catch(() => null);
      data = res?.success ? res.data : null;
    }
    if (!data?.contest) {
      bodyEl.innerHTML = '<p class="text-danger mb-0">Could not load contest results.</p>';
      return;
    }
    const c = data.contest;
    if (titleEl) titleEl.textContent = c.title || 'Contest results';
    const window = {
      start: c.contestStartAt || c.contestWindow?.start,
      end: c.contestEndAt || c.contestWindow?.end,
    };
    const resultStatus = c.resultStatus || (c.resultsPublished ? 'PUBLISHED' : 'PENDING');
    const resultLabel = contestResultStatusLabel(c);
    if (metaEl) {
      metaEl.innerHTML = [
        window.start ? `Start: ${formatContestDateTime(window.start)}` : '',
        window.end ? `End: ${formatContestDateTime(window.end)}` : '',
        `${data.summary?.participantCount ?? c.participantCount ?? 0} participant(s)`,
        `Result: ${resultLabel}`,
        c.resultPublishedAt ? `Published: ${formatContestDateTime(c.resultPublishedAt)}` : '',
      ].filter(Boolean).join(' · ');
    }
    bodyEl.innerHTML = renderContestPreviewTable(data.participants || []);
    if (resultStatus === 'PUBLISHED') {
      footerEl.innerHTML = `<span class="badge-soft success me-auto">Published${c.resultPublishedAt ? ` · ${esc(formatContestDateTime(c.resultPublishedAt))}` : ''}</span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>`;
    } else {
      footerEl.innerHTML = `
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success" id="btnConfirmPublishContest"><i class="bi bi-megaphone me-1"></i>Publish Result</button>`;
      document.getElementById('btnConfirmPublishContest')?.addEventListener('click', () => {
        setContestResultsPublished(id, true);
      });
    }
  }

  async function setContestResultsPublished(id, published) {
    if (!id) return;
    const msg = published
      ? 'Are you sure you want to publish this result? Students will be able to view their results after publication.'
      : 'Hide contest results from students again?';
    if (!confirm(msg)) return;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      toastMsg('Publishing results requires a live session with manage access.', 'info');
      return;
    }
    const res = await api(`/coding/tests/${encodeURIComponent(id)}/publish-results`, {
      method: 'POST',
      body: JSON.stringify({ published: !!published }),
    }).catch(() => null);
    if (!res?.success) {
      toastMsg(res?.message || 'Could not update contest results.', 'error');
      return;
    }
    toastMsg(res.message || (published ? 'Contest results published.' : 'Contest results hidden.'), 'success');
    if (contestPreviewId && String(contestPreviewId) === String(id)) {
      openContestResultsPreview(id).catch(() => {});
    }
    if (access.canViewDirectory && progressPanel === 'contests') {
      loadDirectory().catch(() => {});
    }
    if (access.canManage) {
      await loadManaged().catch(() => {});
      renderManage();
    }
  }

  function applyProgressPanel(panel) {
    progressPanel = panel === 'contests' ? 'contests' : (panel === 'company' ? 'company' : 'tests');
    document.querySelectorAll('#progressViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-progress-view') === progressPanel);
    });
    document.getElementById('progressContestTypeNav')?.classList.toggle('d-none', progressPanel !== 'contests');
    syncContestTypeNav('progressContestTypeNav', progressContestType, 'data-progress-contest-type');
  }

  function applyProgressContestType(type) {
    progressContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('progressContestTypeNav', progressContestType, 'data-progress-contest-type');
    if (progressPanel === 'contests') loadDirectory().catch(() => {});
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

  function applyDirDepartmentFromData(departments = []) {
    const labelInput = document.getElementById('fDepartmentLabel');
    const hidden = document.getElementById('fDepartment');
    const select = document.getElementById('fDepartmentSelect');
    const u = Auth.user() || {};
    let id = String(hidden?.value || u.departmentId || access.scope?.departmentId || '').trim();
    let label = resolveDepartmentLabel(id, u.departmentName || access.scope?.departmentName || '', u.department || '');
    if (Array.isArray(departments) && departments.length) {
      const match = departments.find((d) => String(d.id || '') === id) || (departments.length === 1 ? departments[0] : null);
      if (match) {
        id = String(match.id || id).trim();
        label = String(match.name || match.code || label).trim();
      }
    }
    if (hidden) hidden.value = id;
    if (labelInput) labelInput.value = label || '—';
    if (hidden && !hidden.value && departments.length === 1) {
      hidden.value = String(departments[0].id || '');
    }
    if (select && Auth.role() === 'admin') {
      const current = hidden?.value || select.value || '';
      select.innerHTML = `<option value="">All departments</option>${departments.map((d) => {
        const deptId = String(d.id || '');
        const name = String(d.name || d.code || deptId);
        return `<option value="${esc(deptId)}">${esc(name)}</option>`;
      }).join('')}`;
      if (current && [...select.options].some((o) => o.value === current)) select.value = current;
      if (hidden) hidden.value = select.value;
    }
  }

  async function loadDirFilterOptions(changed = '') {
    const role = Auth.role();
    const labelInput = document.getElementById('fDepartmentLabel');
    const select = document.getElementById('fDepartmentSelect');

    if (role === 'admin') {
      labelInput?.classList.add('d-none');
      select?.classList.remove('d-none');
    } else {
      select?.classList.add('d-none');
      labelInput?.classList.remove('d-none');
    }

    if (changed === 'fDepartmentSelect' || changed === 'fBranch') {
      if (changed === 'fDepartmentSelect') {
        dirFilterBranch = '';
        dirFilterBatch = '';
      }
      if (changed === 'fBranch') dirFilterBatch = '';
      dirResultsCacheKey = '';
      dirResultsCache = null;
    }

    let data = null;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      if (typeof DepartmentStore !== 'undefined' && !deptStorePrimed) {
        await DepartmentStore.fetch().catch(() => {});
        deptStorePrimed = true;
      }
      const filterParams = {
        department: document.getElementById('fDepartment')?.value || '',
        course: changed === 'fDepartmentSelect' ? '' : dirFilterBranch,
        class: (changed === 'fDepartmentSelect' || changed === 'fBranch') ? '' : dirFilterBatch,
      };
      const cacheKey = JSON.stringify(filterParams);
      if (cacheKey === dirFilterCacheKey && dirFilterCache) {
        data = dirFilterCache;
      } else {
        data = await fetchProgressFilterOptions(filterParams);
        if (data) {
          dirFilterCacheKey = cacheKey;
          dirFilterCache = data;
        }
      }
    }
    if (!data) {
      data = demoProgressFilterOptions();
    }
    if (!data) return;

    applyDirDepartmentFromData(data.departments || []);
    let branches = data.branches || [];
    let batches = data.batches || [];
    if (role === 'staff') {
      const assigned = staffAssignedBatches();
      batches = assigned.length ? assigned : [];
      if (assigned.length === 1 && !dirFilterBatch) dirFilterBatch = assigned[0];
    }
    fillDirSelect(document.getElementById('fBranch'), branches, 'All branches', dirFilterBranch);
    dirFilterBranch = document.getElementById('fBranch')?.value || '';
    const batchLabel = role === 'staff'
      ? (batches.length > 1 ? 'All my classes' : 'Select class')
      : 'All batches';
    fillDirSelect(document.getElementById('fBatch'), batches, batchLabel, dirFilterBatch);
    dirFilterBatch = document.getElementById('fBatch')?.value || '';
    fillDirTypeSelect(data.types || []);

    const batchEl = document.getElementById('fBatch');
    if (batchEl) {
      batchEl.disabled = !batches.length;
      batchEl.required = role === 'staff' && batches.length > 0;
    }
    document.getElementById('fTypeWrap')?.classList.toggle('d-none', role !== 'admin');
  }

  function bindDirFilterEvents() {
    if (dirFiltersBound) return;
    dirFiltersBound = true;

    document.getElementById('fBranch')?.addEventListener('change', async (e) => {
      dirFilterBranch = e.target.value || '';
      dirFilterBatch = '';
      const batchEl = document.getElementById('fBatch');
      if (batchEl) batchEl.value = '';
      showDirectoryLoading();
      await loadDirFilterOptions('fBranch');
      scheduleLoadDirectory(0);
    });

    document.getElementById('fBatch')?.addEventListener('change', (e) => {
      dirFilterBatch = e.target.value || '';
      dirResultsCacheKey = '';
      dirResultsCache = null;
      showDirectoryLoading();
      scheduleLoadDirectory(120);
    });

    document.getElementById('fType')?.addEventListener('change', () => {
      dirResultsCacheKey = '';
      dirResultsCache = null;
      showDirectoryLoading();
      scheduleLoadDirectory(120);
    });

    document.getElementById('fDepartmentSelect')?.addEventListener('change', async (e) => {
      const hidden = document.getElementById('fDepartment');
      if (hidden) hidden.value = e.target.value || '';
      showDirectoryLoading();
      await loadDirFilterOptions('fDepartmentSelect');
      scheduleLoadDirectory(0);
    });
  }

  async function initDirFilters() {
    bindDirFilterEvents();
    await loadDirFilterOptions();
  }

  function buildDirectoryQuery() {
    const qs = new URLSearchParams();
    const dept = document.getElementById('fDepartment')?.value.trim();
    const branch = document.getElementById('fBranch')?.value.trim();
    const batch = document.getElementById('fBatch')?.value.trim();
    const type = document.getElementById('fType')?.value.trim();
    if (dept) qs.set('department', dept);
    if (branch) qs.set('course', branch);
    if (batch) qs.set('class', batch);
    if (type) qs.set('userType', type);
    if (progressPanel === 'tests' || progressPanel === 'contests' || progressPanel === 'company') {
      qs.set('resultType', progressPanel);
    }
    return qs;
  }

  function updateDirScopeHint(scope = access.scope || {}) {
    const hint = document.getElementById('dirScopeHint');
    if (!hint) return;
    const role = Auth.role();
    if (role === 'staff') {
      const batches = (scope.assignedClassBatches && scope.assignedClassBatches.length)
        ? scope.assignedClassBatches
        : staffAssignedBatches();
      if (!batches.length) {
        hint.textContent = 'No class is assigned to your account yet. Contact the placement office to view student progress.';
        hint.classList.remove('d-none');
        return;
      }
      hint.textContent = `Showing students in your assigned class${batches.length > 1 ? 'es' : ''}: ${batches.join(', ')}.`;
      hint.classList.remove('d-none');
      return;
    }
    if (role === 'placement_officer') {
      const name = resolveDepartmentLabel(scope.departmentId || '', scope.departmentName || '', '');
      hint.textContent = scope.label || (name ? `Department scope: ${name}.` : 'No department assigned.');
      hint.classList.toggle('d-none', !hint.textContent);
      return;
    }
    hint.textContent = '';
    hint.classList.add('d-none');
  }

  async function loadDirectory() {
    if (!access.canViewDirectory) return;
    const seq = ++dirLoadSeq;
    applyProgressPanel(progressPanel);
    if (staffNeedsClassFilter() && progressPanel !== 'contests') {
      const batches = staffAssignedBatches();
      if (!batches.length) {
        const scope = { ...(access.scope || {}), assignedClassBatches: batches };
        updateDirScopeHint(scope);
        renderDirectoryTable([], {}, scope);
        return;
      }
      if (!hasStaffDirectoryLookup()) {
        const scope = { ...(access.scope || {}), assignedClassBatches: batches };
        updateDirScopeHint(scope);
        renderDirectoryTable([], {}, scope);
        return;
      }
    }
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      renderDirectoryTable([], {}, access.scope || {});
      return;
    }
    const qs = buildDirectoryQuery();
    const cacheKey = qs.toString();
    let data = null;
    if (cacheKey === dirResultsCacheKey && dirResultsCache) {
      data = dirResultsCache;
    } else {
      const res = await api('/coding/progress?' + qs.toString()).catch(() => null);
      if (seq !== dirLoadSeq) return;
      data = res?.success ? res.data : null;
      if (data) {
        dirResultsCacheKey = cacheKey;
        dirResultsCache = data;
      }
    }
    if (seq !== dirLoadSeq) return;
    const summary = data?.summary || {};
    const scope = data?.scope || access.scope || {};
    access.scope = scope;
    if (scope.departmentId || scope.departmentName) {
      applyDirDepartmentFromData([{
        id: String(scope.departmentId || document.getElementById('fDepartment')?.value || ''),
        name: String(scope.departmentName || ''),
      }]);
    }
    updateDirScopeHint(scope);
    if (progressPanel === 'contests' || data?.view === 'contests') {
      renderContestResults(
        data?.contests || [],
        summary,
        scope,
        data?.completedContests || []
      );
      return;
    }
    renderDirectoryTable(data?.rows || [], summary, scope);
  }

  const COD_AI_GEN_MAX_TOTAL = 30;

  const COD_AI_GEN_ROW_DEFAULTS = [
    { difficulty: 'Easy', count: 3 },
    { difficulty: 'Medium', count: 3 },
  ];

  function showCodAiForm() {
    document.getElementById('codAiFormPanel')?.classList.remove('d-none');
    document.getElementById('codAiPreviewPanel')?.classList.add('d-none');
  }

  function updateCodAiGenTotal() {
    const el = document.getElementById('codAiGenTotal');
    if (!el) return;
    const total = collectCodAiGenRows().reduce((sum, row) => sum + row.count, 0);
    if (total <= 0) {
      el.className = 'small text-muted-2 mt-2';
      el.textContent = '';
      return;
    }
    const over = total > COD_AI_GEN_MAX_TOTAL;
    el.className = over ? 'small text-danger mt-2 fw-semibold' : 'small text-muted-2 mt-2';
    el.textContent = over
      ? `${total} problems — total cannot exceed ${COD_AI_GEN_MAX_TOTAL}. Reduce counts across rows.`
      : `${total} problem${total === 1 ? '' : 's'} (max ${COD_AI_GEN_MAX_TOTAL} total across all rows)`;
  }

  function addCodAiGenRow(row = {}) {
    const root = document.getElementById('codAiGenRows');
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'cod-ai-gen-row row g-2 align-items-end';
    const difficulty = row.difficulty || 'Medium';
    const count = row.count ?? 3;
    wrap.innerHTML = `
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-cod-ai-gen="difficulty">
          ${DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === difficulty ? 'selected' : ''}>${esc(d)}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1">Number of problems</label>
        <input class="form-control form-control-sm" type="number" data-cod-ai-gen="count" min="0" max="${COD_AI_GEN_MAX_TOTAL}" value="${esc(count)}"/>
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-cod-ai-gen-remove title="Remove row"><i class="bi bi-trash"></i></button>
      </div>`;
    wrap.querySelector('[data-cod-ai-gen-remove]')?.addEventListener('click', () => {
      if (root.querySelectorAll('.cod-ai-gen-row').length <= 1) {
        toastMsg('Keep at least one generation row.', 'error');
        return;
      }
      wrap.remove();
      updateCodAiGenTotal();
    });
    wrap.querySelector('[data-cod-ai-gen="count"]')?.addEventListener('input', updateCodAiGenTotal);
    root.appendChild(wrap);
    updateCodAiGenTotal();
  }

  function initCodAiGenRows(rows = COD_AI_GEN_ROW_DEFAULTS) {
    const root = document.getElementById('codAiGenRows');
    if (!root) return;
    root.innerHTML = '';
    (rows.length ? rows : COD_AI_GEN_ROW_DEFAULTS).forEach((row) => addCodAiGenRow(row));
    updateCodAiGenTotal();
  }

  function collectCodAiGenRows() {
    return [...document.querySelectorAll('.cod-ai-gen-row')].map((el) => ({
      difficulty: el.querySelector('[data-cod-ai-gen="difficulty"]')?.value || 'Medium',
      count: Math.max(0, Math.min(COD_AI_GEN_MAX_TOTAL, Number(el.querySelector('[data-cod-ai-gen="count"]')?.value || 0))),
    })).filter((row) => row.count > 0);
  }

  function codAiTopicHierarchy() {
    return (typeof CodingData !== 'undefined' && CodingData.TOPIC_HIERARCHY) || {};
  }

  function fillCodAiCategorySelect(selected = 'Algorithms') {
    const sel = document.getElementById('codAiCategory');
    if (!sel) return;
    const cats = Object.keys(codAiTopicHierarchy());
    sel.innerHTML = `<option value="">Select category…</option>${cats.map((c) =>
      `<option value="${esc(c)}"${c === selected ? ' selected' : ''}>${esc(c)}</option>`
    ).join('')}`;
    fillCodAiTopicSelect(selected || '');
  }

  function fillCodAiTopicSelect(category, selected = '') {
    const sel = document.getElementById('codAiTopic');
    if (!sel) return;
    const groups = codAiTopicHierarchy()[category];
    if (!category || !groups) {
      sel.innerHTML = '<option value="">Select category first…</option>';
      sel.disabled = true;
      sel.value = '';
      return;
    }
    sel.disabled = false;
    let html = '<option value="">Select topic…</option>';
    Object.entries(groups).forEach(([groupLabel, topics]) => {
      html += `<optgroup label="${esc(groupLabel)}">`;
      (topics || []).forEach((topic) => {
        html += `<option value="${esc(topic)}"${topic === selected ? ' selected' : ''}>${esc(topic)}</option>`;
      });
      html += '</optgroup>';
    });
    sel.innerHTML = html;
  }

  function openCodAiModal(opts = {}) {
    fillCodAiCategorySelect('Algorithms');
    initCodAiGenRows();
    aiLastFormParams = { companyId: opts.companyId || '' };
    showCodAiForm();
    document.getElementById('codAiPreviewList').innerHTML = '';
    document.getElementById('codAiGenerateStatus')?.classList.add('d-none');
    document.getElementById('codAiSaveStatus')?.classList.add('d-none');
    codAiModal?.show();
  }

  function collectCodAiParams() {
    const batches = collectCodAiGenRows();
    const first = batches[0] || { difficulty: 'Medium', count: 3 };
    return {
      category: (document.getElementById('codAiCategory')?.value || '').trim(),
      topic: (document.getElementById('codAiTopic')?.value || '').trim(),
      batches,
      difficulty: first.difficulty,
      count: batches.reduce((sum, row) => sum + row.count, 0),
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
      toastMsg('Sign in as an admin or placement officer to generate problems.', 'info');
      return;
    }
    const params = collectCodAiParams();
    if (!params.category) {
      toastMsg('Select a category.', 'error');
      return;
    }
    if (!params.topic) {
      toastMsg('Select a topic.', 'error');
      return;
    }
    if (!params.batches?.length) {
      toastMsg('Add at least one generation row with a problem count.', 'error');
      return;
    }
    if (params.count > COD_AI_GEN_MAX_TOTAL) {
      toastMsg(`Total problems cannot exceed ${COD_AI_GEN_MAX_TOTAL}. Reduce counts across rows.`, 'error');
      updateCodAiGenTotal();
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
      const requested = Number(data.requested || params.count || 0);
      const received = Number(data.received || aiPreviewProblems.length);
      if (requested > 0 && received >= requested) {
        toastMsg(`Generated ${received} problem${received === 1 ? '' : 's'}.`, 'success');
      } else if (requested > 0 && received < requested) {
        toastMsg(`Generated ${received} of ${requested} requested problems.`, 'info');
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
    contestResultsModal = document.getElementById('contestResultsModal')
      ? new bootstrap.Modal(document.getElementById('contestResultsModal'))
      : null;
    document.getElementById('progressViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-progress-view]');
      if (!link) return;
      e.preventDefault();
      applyProgressPanel(link.getAttribute('data-progress-view') || 'tests');
      dirResultsCacheKey = '';
      dirResultsCache = null;
      showDirectoryLoading();
      loadDirectory().catch(() => {});
    });
    document.getElementById('progressContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-progress-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyProgressContestType(link.getAttribute('data-progress-contest-type'));
    });
    document.getElementById('takeListNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-take-list]');
      if (!link) return;
      e.preventDefault();
      applyTakeListPanel(link.getAttribute('data-take-list'));
    });
    document.getElementById('practiceSearch')?.addEventListener('input', (e) => {
      practiceSearch = e.target.value || '';
      renderPracticeProblemsList('student');
    });
    document.getElementById('managePracticeSearch')?.addEventListener('input', (e) => {
      managePracticeSearch = e.target.value || '';
      renderPracticeProblemsList('manage');
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
    document.getElementById('studentJdBlockViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-jd-block-view]');
      if (!link) return;
      e.preventDefault();
      applyStudentJdBlockView(link.getAttribute('data-jd-block-view'));
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
    document.getElementById('btnNewCompanyTest')?.addEventListener('click', () => {
      applyManagePanel('jd');
      const companyId = jdSelectedCompanyId && jdSelectedCompanyId !== '_unassigned' ? jdSelectedCompanyId : '';
      openTestForm(null, { testKind: 'company', contestType: 'none', companyId }).catch(() => {});
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
    document.querySelectorAll('input[name="tfQuestionSource"]').forEach((el) => {
      el.addEventListener('change', syncQuestionSourcePanels);
    });
    document.getElementById('btnAddRandomRule')?.addEventListener('click', () => addRandomRuleRow());
    document.getElementById('btnAddManualBankRule')?.addEventListener('click', () => addManualBankRuleRow());
    document.getElementById('tfUseBankManual')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      document.getElementById('tfBankPicker')?.classList.toggle('d-none', !on);
      if (on) ensureManualBankProblemsLoaded().then(() => updateManualBankSummary()).catch(() => updateManualBankSummary());
      else updateManualBankSummary();
    });
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
      const payload = collectTestFormPayload();
      if (!payload.title) {
        toastMsg('Enter a test title.', 'error');
        return;
      }
      const isCompany = formIsCompanyTest();
      if (isCompany && !selectedCompanyFromTestForm().companyId) {
        toastMsg('Select a company for this test.', 'error');
        return;
      }
      if (payload.questionSource === 'random') {
        if (!payload.randomRules?.length) {
          toastMsg('Add at least one topic rule.', 'error');
          return;
        }
        const ruleTotal = payload.randomRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        if (ruleTotal < 1) {
          toastMsg('Each topic rule must include at least one problem.', 'error');
          return;
        }
      } else {
        const useBank = !isCompany && document.getElementById('tfUseBankManual')?.checked;
        const inlineCount = collectInlineProblems().length;
        if (useBank) {
          await ensureManualBankProblemsLoaded();
          const bankErr = validateManualBankRulesComplete();
          if (bankErr) {
            toastMsg(bankErr, 'error');
            return;
          }
        }
        if (isCompany && !inlineCount) {
          toastMsg('Add at least one problem to the company test.', 'error');
          return;
        }
        if (formIsContest() && !useBank) {
          toastMsg('Pick contest problems from the question bank.', 'error');
          return;
        }
        if (!isCompany && !formIsContest() && !useBank && !inlineCount) {
          toastMsg('Add problems from the bank or add them directly.', 'error');
          return;
        }
        const allocated = getTestFormAllocatedTotal('manual');
        if (allocated < 1) {
          toastMsg('Add at least one problem to the test.', 'error');
          return;
        }
      }
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
    document.getElementById('codAiCategory')?.addEventListener('change', (e) => {
      fillCodAiTopicSelect(e.target.value || '');
    });
    document.getElementById('btnCodAiAddRow')?.addEventListener('click', () => addCodAiGenRow());
    document.getElementById('btnCodAiRun')?.addEventListener('click', () => runCodAiGenerate());
    document.getElementById('btnCodAiSave')?.addEventListener('click', () => saveCodAiSelected());
    document.getElementById('btnCodAiCancelPreview')?.addEventListener('click', () => {
      showCodAiForm();
      if (aiLastFormParams) {
        document.getElementById('codAiCategory').value = aiLastFormParams.category || '';
        fillCodAiTopicSelect(aiLastFormParams.category || '', aiLastFormParams.topic || '');
        document.getElementById('codAiInstructions').value = aiLastFormParams.instructions || '';
        initCodAiGenRows(
          aiLastFormParams.batches?.length
            ? aiLastFormParams.batches
            : [{ difficulty: aiLastFormParams.difficulty || 'Medium', count: aiLastFormParams.count || 3 }]
        );
      }
    });
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
