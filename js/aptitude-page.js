/* PlaceHub — aptitude hub page (list / manage / launch exam) */
(function () {
  const APTITUDE_CATEGORIES = [
    'Quantitative Aptitude', 'Logical Reasoning', 'Verbal Ability',
    'Data Interpretation', 'Numerical Ability', 'General Aptitude',
  ];
  const APTITUDE_DIFFICULTIES = ['Easy', 'Medium', 'Hard'];
  const CONTEST_WEEKDAYS = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
  ];

  const DEMO_TESTS = [
    {
      id: 'demo-quant',
      title: 'Quantitative Aptitude — Basics',
      description: 'Arithmetic and ratios for placement screening.',
      category: 'Quantitative Aptitude',
      difficulty: 'Easy',
      durationMinutes: 15,
      questionCount: 2,
      totalMarks: 2,
      negativeMarking: false,
      negativeMarks: 0,
      instructions: 'Each question has one correct option.\nNo negative marking.\nSubmit before the timer ends.',
      status: 'published',
      questions: [
        { id: 'q1', type: 'mcq', prompt: 'What is 15% of 240?', options: ['24', '36', '30', '48'], correctIndex: 1, marks: 1, explanation: '15% of 240 = 0.15 × 240 = 36.', category: 'Quantitative Aptitude' },
        { id: 'q2', type: 'mcq', prompt: 'A train covers 120 km in 2 hours. Average speed?', options: ['40 km/h', '50 km/h', '60 km/h', '80 km/h'], correctIndex: 2, marks: 1, explanation: 'Speed = distance/time = 120/2 = 60 km/h.', category: 'Quantitative Aptitude' },
      ],
    },
  ];

  const DEMO_TESTS_KEY = 'ph-aptitude-demo-tests';
  const DEMO_BANK_KEY = 'ph-aptitude-demo-bank';

  function cloneDemoTests() {
    return DEMO_TESTS.map((t) => JSON.parse(JSON.stringify(t)));
  }

  function loadDemoTestsStore() {
    try {
      const raw = localStorage.getItem(DEMO_TESTS_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed) && parsed.length) return parsed;
      }
    } catch { /* ignore */ }
    return cloneDemoTests();
  }

  function saveDemoTestsStore(list) {
    localStorage.setItem(DEMO_TESTS_KEY, JSON.stringify(list));
  }

  function fullDemoTest(id) {
    return loadDemoTestsStore().find((x) => String(x.id) === String(id));
  }

  function loadDemoBankStore() {
    try {
      const raw = localStorage.getItem(DEMO_BANK_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed;
      }
    } catch { /* ignore */ }
    return [];
  }

  function saveDemoBankStore(list) {
    localStorage.setItem(DEMO_BANK_KEY, JSON.stringify(list));
  }

  function parseCorrectIndex(correct, options) {
    const c = String(correct ?? '').trim();
    if (!c) return 0;
    if (/^\d+$/.test(c)) {
      const idx = Number(c);
      if (idx >= 1 && idx <= options.length) return idx - 1;
      return Math.max(0, Math.min(options.length - 1, idx));
    }
    const letter = c.toUpperCase();
    if (letter.length === 1 && letter >= 'A' && letter <= 'E') return letter.charCodeAt(0) - 65;
    const found = options.findIndex((o) => String(o).trim().toLowerCase() === c.toLowerCase());
    return found >= 0 ? found : 0;
  }

  function rowField(row, ...keys) {
    for (const k of keys) {
      if (row[k] != null && String(row[k]).trim() !== '') return String(row[k]).trim();
      const lk = String(k).toLowerCase();
      for (const [rk, rv] of Object.entries(row)) {
        if (String(rk).toLowerCase() === lk && String(rv).trim() !== '') return String(rv).trim();
      }
    }
    return '';
  }

  function normalizeDifficulty(value, fallback = 'Medium') {
    const raw = String(value || fallback).trim();
    const hit = APTITUDE_DIFFICULTIES.find((d) => d.toLowerCase() === raw.toLowerCase());
    return hit || 'Medium';
  }

  function normalizeBulkRow(row, fallbackCategory, index, fallbackDifficulty = 'Medium') {
    const prompt = rowField(row, 'prompt', 'question', 'question text');
    const options = Array.isArray(row.options) && row.options.length >= 2
      ? row.options.map((o) => String(o || '').trim()).filter(Boolean)
      : [
        rowField(row, 'optionA', 'option_a', 'a', 'option1'),
        rowField(row, 'optionB', 'option_b', 'b', 'option2'),
        rowField(row, 'optionC', 'option_c', 'c', 'option3'),
        rowField(row, 'optionD', 'option_d', 'd', 'option4'),
      ].filter(Boolean);
    if (!prompt || options.length < 2) return null;
    const correct = rowField(row, 'correct', 'answer', 'correctIndex', 'correct_option');
    const marks = Number(rowField(row, 'marks', 'mark') || 1) || 1;
    return {
      id: `q${index + 1}`,
      type: 'mcq',
      prompt,
      options,
      correctIndex: parseCorrectIndex(correct, options),
      marks,
      explanation: rowField(row, 'explanation', 'solution'),
      category: rowField(row, 'category') || fallbackCategory,
      difficulty: normalizeDifficulty(rowField(row, 'difficulty', 'level', 'difficulty level'), fallbackDifficulty),
    };
  }

  function normalizeBulkRows(rows, fallbackCategory, fallbackDifficulty = 'Medium') {
    const out = [];
    rows.forEach((row) => {
      const norm = normalizeBulkRow(row, fallbackCategory, out.length, fallbackDifficulty);
      if (norm) out.push(norm);
    });
    return out;
  }

  function demoBulkUpload(rawRows, mode) {
    const fallbackCategory = document.getElementById('bulkCategory')?.value || 'General Aptitude';
    const fallbackDifficulty = document.getElementById('bulkDifficulty')?.value || 'Medium';
    const normalized = normalizeBulkRows(rawRows, fallbackCategory, fallbackDifficulty);
    if (!normalized.length) {
      throw new Error('No valid questions found in the Excel file.');
    }
    if (mode === 'bank') {
      const bank = loadDemoBankStore();
      normalized.forEach((q, i) => bank.push({ ...q, id: `bank-${bank.length + i + 1}` }));
      saveDemoBankStore(bank);
      return { added: normalized.length };
    }
    const testId = document.getElementById('bulkTestId').value;
    const replace = document.getElementById('bulkReplace').checked;
    const store = loadDemoTestsStore();
    const idx = store.findIndex((t) => String(t.id) === String(testId));
    if (idx < 0) throw new Error('Test not found.');
    const test = store[idx];
    const baseQs = replace ? [] : [...(test.questions || [])];
    const merged = normalized.map((q, i) => ({ ...q, id: `q${baseQs.length + i + 1}` }));
    store[idx] = {
      ...test,
      questions: [...baseQs, ...merged],
      questionCount: baseQs.length + merged.length,
      totalMarks: [...baseQs, ...merged].reduce((s, q) => s + Number(q.marks || 1), 0),
    };
    saveDemoTestsStore(store);
    return { added: normalized.length };
  }

  let access = { canTake: false, canManage: false, canViewDirectory: false, scope: null };
  let tests = [];
  let meta = { categories: APTITUDE_CATEGORIES, difficulties: APTITUDE_DIFFICULTIES };
  let testFormModal;
  let bulkModal;
  let studentAptModal;
  let exam;
  let mcqCounter = 0;
  let dirFilterBranch = '';
  let dirFilterBatch = '';
  let dirFiltersBound = false;
  let progressPanel = 'tests';
  let myResultsPanel = 'tests';
  let bankDifficultyFilter = '';
  let bankCategoryFilter = '';
  let bankQuestions = [];
  let bankSummary = { Easy: 0, Medium: 0, Hard: 0, total: 0 };
  let bankPickerQuestions = [];
  const selectedBankIds = new Set();

  function dirOptionLabel(value) {
    const raw = String(value || '').trim();
    if (!raw) return raw;
    if (typeof courseLevelProgrammeLabel === 'function') {
      const course = courseLevelProgrammeLabel(raw);
      if (course) return course;
    }
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
    const res = await api('/aptitude/progress/filters' + (q ? `?${q}` : '')).catch(() => null);
    return res?.success ? res.data : null;
  }

  function resolveDepartmentLabel(id, fallbackName = '', fallbackCode = '') {
    if (Array.isArray(window.__aptDeptCache) && id) {
      const hit = window.__aptDeptCache.find((d) => String(d.id || '') === String(id));
      if (hit?.name) return String(hit.name);
    }
    if (typeof DepartmentStore !== 'undefined' && id) {
      const hit = DepartmentStore.all().find((d) => String(d.id || d._id || '') === String(id));
      if (hit?.name) return String(hit.name);
    }
    if (fallbackName) return String(fallbackName);
    if (id && typeof departmentDisplayName === 'function') return departmentDisplayName(id);
    if (fallbackCode && typeof departmentDisplayName === 'function') return departmentDisplayName(fallbackCode);
    return fallbackCode || fallbackName || '';
  }

  function applyDirDepartmentFromData(departments = []) {
    window.__aptDeptCache = Array.isArray(departments) ? departments : [];
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

  function staffAssignedBatches() {
    if (Auth.role() !== 'staff') return [];
    if (typeof staffClassInchargeBatches === 'function') return staffClassInchargeBatches();
    const u = Auth.user() || {};
    return Array.isArray(u.assignedClassBatches) ? u.assignedClassBatches : [];
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
    if (role === 'placement_officer' && (scope.departmentName || scope.departmentId)) {
      const name = resolveDepartmentLabel(scope.departmentId || '', scope.departmentName || '', '');
      hint.textContent = name ? `Department scope: ${name}.` : '';
      hint.classList.toggle('d-none', !hint.textContent);
      return;
    }
    hint.textContent = '';
    hint.classList.add('d-none');
  }

  function demoDirectoryRows(resultType = progressPanel) {
    const role = Auth.role();
    const u = Auth.user() || {};
    const qs = buildDirectoryQuery();
    const branchFilter = qs.get('course') || '';
    const batchFilter = qs.get('class') || '';

    let students = [];
    if (typeof UserRegistry !== 'undefined') {
      students = UserRegistry.all().filter((s) => s.role === 'student');
    }

    if (role === 'staff') {
      const batches = staffAssignedBatches();
      if (!batches.length) {
        return { rows: [], summary: {}, noClass: true };
      }
      students = students.filter((s) => batches.includes(String(s.classBatch || '')));
    } else if (role === 'placement_officer') {
      const dept = String(u.department || access.scope?.departmentCode || '').trim();
      if (dept) students = students.filter((s) => String(s.department || '') === dept);
    }

    if (branchFilter) {
      students = students.filter((s) => {
        const course = String(s.department || s.course || '');
        return course === branchFilter
          || (typeof courseLevelProgrammeLabel === 'function' && courseLevelProgrammeLabel(course) === branchFilter);
      });
    }
    if (batchFilter) {
      students = students.filter((s) => String(s.classBatch || '') === batchFilter);
    }

    const demoStats = {
      'u-s1': { testsAttempted: 1, averageScore: 85, bestScore: 85, accuracy: 85, recentScore: 85 },
      'u-s2': { testsAttempted: 2, averageScore: 72, bestScore: 80, accuracy: 70, recentScore: 75 },
      'u-s3': { testsAttempted: 3, averageScore: 91, bestScore: 95, accuracy: 88, recentScore: 90 },
    };

    const rows = students.map((s) => {
      const stats = demoStats[s.id] || { testsAttempted: 0, averageScore: 0, bestScore: 0, accuracy: 0, recentScore: 0 };
      return {
        userId: s.id,
        name: s.name,
        registerNumber: s.registerNumber,
        classBatch: s.classBatch,
        course: s.department,
        ...stats,
        categoryPerformance: stats.testsAttempted
          ? { 'Quantitative Aptitude': { percentage: stats.averageScore } }
          : {},
      };
    }).filter((r) => {
      if (resultType === 'contests') {
        return (r.testsAttempted || 0) > 0 && (r.userId === 'u-s2' || r.userId === 'u-s1');
      }
      return true;
    }).map((r) => {
      if (resultType !== 'contests') return r;
      return {
        ...r,
        testsAttempted: Math.min(Number(r.testsAttempted) || 0, 1),
        averageScore: r.userId === 'u-s2' ? 68 : 74,
        bestScore: r.userId === 'u-s2' ? 72 : 74,
        accuracy: r.userId === 'u-s2' ? 65 : 74,
        recentScore: r.userId === 'u-s2' ? 72 : 74,
      };
    });

    const withAttempts = rows.filter((r) => (r.testsAttempted || 0) > 0);
    const avg = (key) => {
      if (!withAttempts.length) return 0;
      const sum = withAttempts.reduce((acc, r) => acc + (Number(r[key]) || 0), 0);
      return Math.round((sum / withAttempts.length) * 10) / 10;
    };
    const bestScores = withAttempts.map((r) => Number(r.bestScore) || 0);

    return {
      rows,
      summary: {
        students: rows.length,
        withAttempts: withAttempts.length,
        totalAttempts: withAttempts.reduce((acc, r) => acc + (Number(r.testsAttempted) || 0), 0),
        avgPercentage: avg('averageScore'),
        avgBestScore: avg('bestScore'),
        highestBestScore: bestScores.length ? Math.max(...bestScores) : 0,
      },
      noClass: false,
    };
  }

  function demoContestResults() {
    const demo = demoDirectoryRows('contests');
    if (demo.noClass) {
      return { contests: [], summary: {}, noClass: true };
    }

    const participants = (demo.rows || [])
      .filter((r) => (r.testsAttempted || 0) > 0)
      .map((r, i) => ({
        attemptId: `demo-contest-${r.userId}`,
        userId: r.userId,
        name: r.name,
        registerNumber: r.registerNumber,
        studentCode: r.registerNumber,
        classBatch: r.classBatch,
        course: r.course,
        rank: i + 1,
        percentage: r.recentScore ?? r.bestScore ?? 0,
        marksObtained: Math.round(((r.recentScore ?? r.bestScore ?? 0) / 100) * 20),
        totalMarks: 20,
        correctCount: Math.round(((r.recentScore ?? 0) / 100) * 18),
        wrongCount: 2,
        unansweredCount: 0,
        timeTakenSeconds: 900 + i * 120,
        timeTakenLabel: i === 0 ? '15m 00s' : '17m 00s',
        completedAt: new Date(Date.now() - i * 86400000).toISOString(),
        accuracy: r.accuracy ?? 0,
      }))
      .sort((a, b) => (Number(b.percentage) || 0) - (Number(a.percentage) || 0))
      .map((p, i) => ({ ...p, rank: i + 1 }));

    const percentages = participants.map((p) => Number(p.percentage) || 0);
    const avgPct = percentages.length
      ? Math.round((percentages.reduce((a, b) => a + b, 0) / percentages.length) * 10) / 10
      : 0;

    return {
      contests: participants.length ? [{
        testId: 'demo-weekly-contest',
        title: 'Weekly aptitude contest',
        category: 'Quantitative Aptitude',
        contestType: 'weekly',
        contestScheduleLabel: 'Weekly · Friday',
        participantCount: participants.length,
        participants,
      }] : [],
      summary: {
        contestCount: participants.length ? 1 : 0,
        totalParticipants: participants.length,
        uniqueParticipants: participants.length,
        avgPercentage: avgPct,
        highestScore: percentages.length ? Math.max(...percentages) : 0,
      },
      noClass: false,
    };
  }

  function formatContestScore(p) {
    const obtained = Number(p.marksObtained ?? p.score);
    const total = Number(p.totalMarks ?? p.maximumScore);
    const pct = Number(p.percentage);
    if (Number.isFinite(obtained) && Number.isFinite(total) && total > 0) {
      return Number.isFinite(pct) ? `${obtained}/${total} (${pct}%)` : `${obtained}/${total}`;
    }
    return Number.isFinite(pct) ? `${pct}%` : '—';
  }

  function formatContestBreakdown(p) {
    const bits = [];
    if (p.correctCount != null) bits.push(`${p.correctCount} correct`);
    if (p.wrongCount != null) bits.push(`${p.wrongCount} wrong`);
    if (p.unansweredCount != null) bits.push(`${p.unansweredCount} skipped`);
    return bits.length ? bits.join(' · ') : '—';
  }

  function formatCompletedAt(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(undefined, {
      day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  }

  function renderContestResults(contests, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.add('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.remove('d-none');

    document.getElementById('dirStats').innerHTML = [
      ['Contests', summary.contestCount ?? 0],
      ['Participants', summary.totalParticipants ?? 0],
      ['Unique students', summary.uniqueParticipants ?? 0],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Top score', `${summary.highestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');

    const role = Auth.role();
    const emptyMsg = role === 'staff' && (!staffAssignedBatches().length && !(scope.assignedClassBatches || []).length)
      ? 'No class is assigned to your account. Contact the placement office to monitor contest results.'
      : 'No contest attempts in your authorized scope yet.';

    const root = document.getElementById('dirContestSections');
    if (!root) return;

    if (!contests.length) {
      root.innerHTML = `<p class="text-muted-2 mb-0">${emptyMsg}</p>`;
      return;
    }

    const canViewDetail = Auth.hasRealAuth() && !Auth.isDemo();
    root.innerHTML = contests.map((c) => {
      const badge = c.contestScheduleLabel
        ? `<span class="badge-soft info ms-2">${esc(c.contestScheduleLabel)}</span>`
        : '';
      const cat = c.category ? `<span class="text-muted-2 ms-2">${esc(c.category)}</span>` : '';
      const rows = (c.participants || []).map((p) => {
        const viewBtn = canViewDetail && (p.attemptId || p.id)
          ? `<button type="button" class="btn btn-sm btn-outline-primary" data-view-attempt="${esc(p.attemptId || p.id)}">View</button>`
          : `<button type="button" class="btn btn-sm btn-outline-secondary" data-detail="${esc(p.userId || '')}">Profile</button>`;
        return `<tr>
          <td class="text-muted-2">${esc(p.rank ?? '—')}</td>
          <td class="fw-semibold">${esc(p.name || '—')}</td>
          <td>${esc(studentIdLabel(p))}</td>
          <td>${esc(p.classBatch || '—')}</td>
          <td>${esc(formatContestScore(p))}</td>
          <td>${esc(p.timeTakenLabel || '—')}</td>
          <td class="small">${esc(formatContestBreakdown(p))}</td>
          <td class="small text-muted-2">${esc(formatCompletedAt(p.completedAt))}</td>
          <td>${viewBtn}</td>
        </tr>`;
      }).join('');

      return `<div class="card-surface p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <div>
            <div class="fw-bold">${esc(c.title || 'Contest')}${badge}${cat}</div>
            <div class="small text-muted-2">${esc(c.participantCount ?? (c.participants || []).length)} participant(s)</div>
          </div>
        </div>
        <div class="table-wrap mb-0"><table class="table-modern table-sm mb-0"><thead><tr>
          <th>Rank</th><th>Name</th><th>Student ID</th><th>Class</th><th>Score</th><th>Time</th><th>Breakdown</th><th>Submitted</th><th></th>
        </tr></thead><tbody>${rows || `<tr><td colspan="9" class="text-muted-2 p-3">No participants yet.</td></tr>`}</tbody></table></div>
      </div>`;
    }).join('');

    root.querySelectorAll('[data-view-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt')));
    });
    root.querySelectorAll('[data-detail]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentDetail(btn.getAttribute('data-detail')));
    });
  }

  function renderDirectoryTable(rows, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.add('d-none');

    document.getElementById('dirStats').innerHTML = [
      ['Students', summary.students ?? summary.subjects ?? 0],
      ['With attempts', summary.withAttempts ?? 0],
      ['Total attempts', summary.totalAttempts ?? 0],
      ['Avg score', `${summary.avgPercentage ?? 0}%`],
      ['Avg best', `${summary.avgBestScore ?? 0}%`],
      ['Highest best', `${summary.highestBestScore ?? 0}%`],
    ].map(([lbl, val]) =>
      `<div class="col-6 col-md-2"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
    ).join('');

    const role = Auth.role();
    const emptyMsg = progressPanel === 'contests'
      ? (role === 'staff' && (!staffAssignedBatches().length && !(scope.assignedClassBatches || []).length)
        ? 'No class is assigned to your account. Contact the placement office to monitor contest results.'
        : 'No contest results in your authorized scope yet.')
      : (role === 'staff' && (!staffAssignedBatches().length && !(scope.assignedClassBatches || []).length)
        ? 'No class is assigned to your account. Contact the placement office to monitor student aptitude progress.'
        : 'No test results in your authorized scope yet.');

    document.getElementById('dirRows').innerHTML = rows.length ? rows.map((r) => {
      const uid = String(r.userId || '');
      return `<tr>
        <td class="fw-semibold">${esc(r.name)}</td>
        <td>${esc(studentIdLabel(r))}</td>
        <td>${esc(r.classBatch || '—')}</td>
        <td>${esc(r.testsAttempted)}</td>
        <td>${esc(r.averageScore ?? r.percentage ?? 0)}%</td>
        <td>${esc(r.bestScore ?? 0)}%</td>
        <td>${esc(r.accuracy ?? 0)}%</td>
        <td>${esc(r.recentScore ?? r.recentPerformance ?? 0)}%</td>
        <td class="small">${esc(categoryShort(r))}</td>
        <td><button type="button" class="btn btn-sm btn-outline-primary" data-detail="${esc(uid)}">View</button></td>
      </tr>`;
    }).join('')
      : `<tr><td colspan="10" class="text-muted-2 p-3">${emptyMsg}</td></tr>`;

    document.querySelectorAll('[data-detail]').forEach((btn) => {
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
    const batches = assigned.length ? assigned : (role === 'staff' ? [] : ['MCAINT2022-27']);
    const branches = ['INMCA', 'Integrated MCA', 'MCA'];
    const types = role === 'admin'
      ? [{ value: 'student', label: 'Students' }, { value: 'alumni', label: 'Alumni' }]
      : [{ value: 'student', label: 'Students' }];
    return { departments, branches, batches, types };
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
    }

    let data = null;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      if (typeof DepartmentStore !== 'undefined') {
        await DepartmentStore.fetch().catch(() => {});
      }
      data = await fetchProgressFilterOptions({
        department: document.getElementById('fDepartment')?.value || '',
        course: changed === 'fDepartmentSelect' ? '' : dirFilterBranch,
        class: (changed === 'fDepartmentSelect' || changed === 'fBranch') ? '' : dirFilterBatch,
      });
    }
    if (!data) {
      data = demoProgressFilterOptions();
    }
    if (!data) return;

    applyDirDepartmentFromData(data.departments || []);
    fillDirSelect(document.getElementById('fBranch'), data.branches || [], 'All branches', dirFilterBranch);
    dirFilterBranch = document.getElementById('fBranch')?.value || '';
    fillDirSelect(document.getElementById('fBatch'), data.batches || [], 'All batches', dirFilterBatch);
    dirFilterBatch = document.getElementById('fBatch')?.value || '';
    fillDirTypeSelect(data.types || []);

    const batchEl = document.getElementById('fBatch');
    if (batchEl) batchEl.disabled = (data.branches || []).length > 0 && !dirFilterBranch;
    document.getElementById('fTypeWrap')?.classList.toggle('d-none', role !== 'admin');
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
    if (progressPanel === 'tests' || progressPanel === 'contests') qs.set('resultType', progressPanel);
    return qs;
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

  function applyProgressPanel(panel) {
    progressPanel = panel === 'contests' ? 'contests' : 'tests';
    document.querySelectorAll('#progressViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-progress-view') === progressPanel);
    });
  }

  function applyMyResultsPanel(panel) {
    myResultsPanel = panel === 'contests' ? 'contests' : 'tests';
    document.querySelectorAll('#myResultsNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-results-view') === myResultsPanel);
    });
  }

  function historyEntryIsContest(h) {
    const type = String(h?.contestType || '');
    if (type === 'weekly' || type === 'monthly') return true;
    if (type === 'none') return false;
    const test = resolveHistoryTest(h);
    return test ? isContestTest(test) : false;
  }

  function bindDirFilterEvents() {
    if (dirFiltersBound) return;
    dirFiltersBound = true;

    document.getElementById('fBranch')?.addEventListener('change', async (e) => {
      dirFilterBranch = e.target.value || '';
      dirFilterBatch = '';
      const batchEl = document.getElementById('fBatch');
      if (batchEl) batchEl.value = '';
      await loadDirFilterOptions('fBranch');
      loadDirectory();
    });

    document.getElementById('fBatch')?.addEventListener('change', (e) => {
      dirFilterBatch = e.target.value || '';
      loadDirectory();
    });

    document.getElementById('fType')?.addEventListener('change', () => loadDirectory());

    document.getElementById('fDepartmentSelect')?.addEventListener('change', async (e) => {
      const hidden = document.getElementById('fDepartment');
      if (hidden) hidden.value = e.target.value || '';
      await loadDirFilterOptions('fDepartmentSelect');
      loadDirectory();
    });
  }

  async function initDirFilters() {
    bindDirFilterEvents();
    await loadDirFilterOptions();
  }

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function fillSelect(el, options, selected = '', allowEmpty = false) {
    if (!el) return;
    const emptyOpt = allowEmpty ? `<option value="">All categories</option>` : '';
    el.innerHTML = emptyOpt + options.map((o) => `<option value="${esc(o)}" ${o === selected ? 'selected' : ''}>${esc(o)}</option>`).join('');
  }

  function stripHtml(text) {
    const div = document.createElement('div');
    div.innerHTML = String(text || '');
    return (div.textContent || div.innerText || '').trim();
  }

  function bankDifficultyBadge(level) {
    const map = { Easy: 'success', Medium: 'warning', Hard: 'danger' };
    const cls = map[normalizeDifficulty(level)] || 'secondary';
    return `<span class="badge bg-${cls}-subtle text-${cls} border border-${cls}-subtle">${esc(normalizeDifficulty(level))}</span>`;
  }

  function demoBankSummary(questions) {
    const summary = { Easy: 0, Medium: 0, Hard: 0, total: 0 };
    (questions || []).forEach((q) => {
      const level = normalizeDifficulty(q.difficulty);
      summary[level] += 1;
      summary.total += 1;
    });
    return summary;
  }

  async function loadQuestionBank() {
    if (!access.canManage) return;
    const categoryEl = document.getElementById('bankFilterCategory');
    if (categoryEl && categoryEl.options.length <= 1) {
      fillSelect(categoryEl, meta.categories || APTITUDE_CATEGORIES, bankCategoryFilter, true);
    }
    bankCategoryFilter = categoryEl?.value || bankCategoryFilter || '';

    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const qs = new URLSearchParams();
      if (bankCategoryFilter) qs.set('category', bankCategoryFilter);
      if (bankDifficultyFilter) qs.set('difficulty', bankDifficultyFilter);
      const res = await api('/aptitude/question-bank' + (qs.toString() ? `?${qs}` : '')).catch(() => null);
      bankQuestions = res?.data?.questions || [];
      bankSummary = res?.data?.summary || demoBankSummary(bankQuestions);
    } else {
      const all = loadDemoBankStore();
      bankQuestions = all.filter((q) => {
        if (bankCategoryFilter && String(q.category || '') !== bankCategoryFilter) return false;
        if (bankDifficultyFilter && normalizeDifficulty(q.difficulty) !== bankDifficultyFilter) return false;
        return true;
      });
      bankSummary = demoBankSummary(all.filter((q) => (
        !bankCategoryFilter || String(q.category || '') === bankCategoryFilter
      )));
    }
    renderQuestionBank();
  }

  function renderQuestionBank() {
    const statsRoot = document.getElementById('bankStats');
    if (statsRoot) {
      statsRoot.innerHTML = [
        ['Total in bank', bankSummary.total ?? 0],
        ['Easy', bankSummary.Easy ?? 0],
        ['Medium', bankSummary.Medium ?? 0],
        ['Hard', bankSummary.Hard ?? 0],
      ].map(([lbl, val]) =>
        `<div class="col-6 col-md-3"><div class="card-surface p-2 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val" style="font-size:1.1rem">${esc(val)}</div></div></div>`
      ).join('');
    }

    document.querySelectorAll('#bankDifficultyNav .nav-link').forEach((link) => {
      link.classList.toggle('active', (link.getAttribute('data-bank-difficulty') || '') === bankDifficultyFilter);
    });

    const root = document.getElementById('bankQuestionsList');
    if (!root) return;
    if (!bankQuestions.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">No questions in this bucket yet. Use bulk upload to add Easy, Medium, and Hard MCQs.</p>';
      return;
    }

    root.innerHTML = bankQuestions.slice(0, 100).map((q) => {
      const prompt = stripHtml(q.prompt).slice(0, 160) || 'Question';
      return `<div class="border rounded-3 p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div class="min-w-0 flex-grow-1">
            <div class="fw-medium text-truncate">${esc(prompt)}</div>
            <div class="small text-muted-2">${esc(q.category || 'General Aptitude')} · ${esc(q.options?.length || 0)} options · ${esc(q.marks ?? 1)} mark(s)</div>
          </div>
          ${bankDifficultyBadge(q.difficulty)}
        </div>
      </div>`;
    }).join('') + (bankQuestions.length > 100
      ? `<p class="small text-muted-2 mb-0">Showing first 100 of ${bankQuestions.length} questions.</p>`
      : '');
  }

  function getQuestionSource() {
    return document.getElementById('tfSourceRandom')?.checked ? 'random' : 'manual';
  }

  function syncQuestionSourcePanels() {
    const random = getQuestionSource() === 'random';
    document.getElementById('tfRandomPanel')?.classList.toggle('d-none', !random);
    document.getElementById('tfManualPanel')?.classList.toggle('d-none', random);
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl) {
      countEl.readOnly = random;
      if (random) updateRandomSummary();
    }
  }

  function addRandomRuleRow(rule = {}) {
    const root = document.getElementById('tfRandomRules');
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'tf-random-rule row g-2 align-items-end';
    const categories = meta.categories || APTITUDE_CATEGORIES;
    const catOpts = categories.map((c) =>
      `<option value="${esc(c)}" ${c === (rule.category || categories[0]) ? 'selected' : ''}>${esc(c)}</option>`
    ).join('');
    const diff = normalizeDifficulty(rule.difficulty || 'Medium');
    wrap.innerHTML = `
      <div class="col-md-5">
        <label class="form-label small mb-1">Category</label>
        <select class="form-select form-select-sm" data-f="category">${catOpts}</select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-f="difficulty">
          ${APTITUDE_DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">No. of questions</label>
        <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(rule.count ?? 5)}"/>
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-rule>Remove</button>
      </div>`;
    wrap.querySelector('[data-remove-rule]')?.addEventListener('click', () => {
      wrap.remove();
      updateRandomSummary();
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', updateRandomSummary);
      el.addEventListener('input', updateRandomSummary);
    });
    root.appendChild(wrap);
    updateRandomSummary();
  }

  function collectRandomRules() {
    return [...document.querySelectorAll('#tfRandomRules .tf-random-rule')].map((row) => ({
      category: row.querySelector('[data-f="category"]')?.value || 'General Aptitude',
      difficulty: row.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(row.querySelector('[data-f="count"]')?.value || 1)),
    }));
  }

  function updateRandomSummary() {
    const rules = collectRandomRules();
    const total = rules.reduce((sum, r) => sum + (Number(r.count) || 0), 0);
    const summary = document.getElementById('tfRandomSummary');
    if (summary) summary.textContent = `${total} question(s) from ${rules.length} rule(s)`;
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl && getQuestionSource() === 'random') countEl.value = String(total || 1);
  }

  function addBankFilterRuleRow(rule = {}) {
    const root = document.getElementById('tfBankFilterRules');
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'tf-bank-filter-rule row g-2 align-items-end';
    const categories = meta.categories || APTITUDE_CATEGORIES;
    const catOpts = categories.map((c) =>
      `<option value="${esc(c)}" ${c === (rule.category || categories[0]) ? 'selected' : ''}>${esc(c)}</option>`
    ).join('');
    const diff = normalizeDifficulty(rule.difficulty || 'Medium');
    wrap.innerHTML = `
      <div class="col-md-5">
        <label class="form-label small mb-1">Category</label>
        <select class="form-select form-select-sm" data-f="category">${catOpts}</select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-f="difficulty">
          ${APTITUDE_DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">No. of questions</label>
        <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(rule.count ?? 5)}"/>
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-bank-rule>Remove</button>
      </div>`;
    wrap.querySelector('[data-remove-bank-rule]')?.addEventListener('click', () => {
      wrap.remove();
      loadBankPickerQuestions().catch(() => renderBankPicker());
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => {
        loadBankPickerQuestions().catch(() => renderBankPicker());
      });
      el.addEventListener('input', () => {
        updateBankPickSummary();
        updateManualQuestionCount();
      });
    });
    root.appendChild(wrap);
    loadBankPickerQuestions().catch(() => renderBankPicker());
  }

  function collectBankFilterRules() {
    return [...document.querySelectorAll('#tfBankFilterRules .tf-bank-filter-rule')].map((row) => ({
      category: row.querySelector('[data-f="category"]')?.value || 'General Aptitude',
      difficulty: row.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(row.querySelector('[data-f="count"]')?.value || 1)),
    }));
  }

  function bankFilterRulesNeeded() {
    return collectBankFilterRules().reduce((sum, r) => sum + (Number(r.count) || 0), 0);
  }

  function updateBankPickSummary() {
    const summary = document.getElementById('tfBankPickSummary');
    if (!summary) return;
    const needed = bankFilterRulesNeeded();
    summary.textContent = `${selectedBankIds.size} selected · ${bankPickerQuestions.length} shown · ${needed} needed from rules`;
  }

  async function loadBankPickerQuestions() {
    const rules = collectBankFilterRules();
    if (!rules.length) {
      bankPickerQuestions = [];
      updateBankPickSummary();
      renderBankPicker();
      return;
    }

    let all = [];
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/question-bank').catch(() => null);
      all = res?.data?.questions || [];
    } else {
      all = loadDemoBankStore();
    }

    bankPickerQuestions = all.filter((q) => rules.some((r) => {
      if (r.category && String(q.category || '') !== r.category) return false;
      if (r.difficulty && normalizeDifficulty(q.difficulty) !== normalizeDifficulty(r.difficulty)) return false;
      return true;
    }));

    updateBankPickSummary();
    updateManualQuestionCount();
    renderBankPicker();
  }

  function renderBankPicker() {
    const root = document.getElementById('tfBankPickList');
    if (!root) return;
    updateBankPickSummary();
    if (!bankPickerQuestions.length) {
      root.innerHTML = '<p class="small text-muted-2 mb-0">No bank questions match these filters. Upload to the question bank first.</p>';
      return;
    }
    root.innerHTML = bankPickerQuestions.map((q) => {
      const id = String(q.id || q.bankId || '');
      const checked = selectedBankIds.has(id) ? 'checked' : '';
      const prompt = stripHtml(q.prompt).slice(0, 120) || 'Question';
      return `<label class="d-flex align-items-start gap-2 border rounded-2 p-2 mb-0">
        <input type="checkbox" class="form-check-input mt-1" data-bank-pick="${esc(id)}" ${checked}/>
        <span class="small min-w-0">
          <span class="d-block text-truncate">${esc(prompt)}</span>
          <span class="text-muted-2">${esc(q.category || '')} · ${esc(normalizeDifficulty(q.difficulty))}</span>
        </span>
      </label>`;
    }).join('');
    root.querySelectorAll('[data-bank-pick]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = cb.getAttribute('data-bank-pick');
        if (!id) return;
        if (cb.checked) selectedBankIds.add(id);
        else selectedBankIds.delete(id);
        updateManualQuestionCount();
        updateBankPickSummary();
      });
    });
  }

  function updateManualQuestionCount() {
    if (getQuestionSource() !== 'manual') return;
    const mcqCount = collectMcqs().length;
    const useBank = document.getElementById('tfUseBankManual')?.checked;
    const bankPart = useBank ? bankFilterRulesNeeded() : 0;
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl) countEl.value = String(Math.max(mcqCount + bankPart, 1));
  }

  function demoPickRandomRules(rules) {
    const picked = [];
    const used = new Set();
    const all = loadDemoBankStore();
    rules.forEach((rule) => {
      let pool = all.filter((q) => {
        const id = String(q.id || q.bankId || '');
        if (used.has(id)) return false;
        if (rule.category && String(q.category || '') !== rule.category) return false;
        if (rule.difficulty && normalizeDifficulty(q.difficulty) !== normalizeDifficulty(rule.difficulty)) return false;
        return true;
      });
      const count = Math.max(1, Number(rule.count) || 1);
      if (pool.length < count) {
        throw new Error(`Not enough ${rule.difficulty} questions in ${rule.category} (need ${count}, found ${pool.length}).`);
      }
      pool = pool.sort(() => Math.random() - 0.5).slice(0, count);
      pool.forEach((q) => {
        const id = String(q.id || q.bankId || '');
        used.add(id);
        picked.push({ ...q, bankId: id });
      });
    });
    return picked;
  }

  function resolveDemoTestQuestions(payload) {
    if (payload.questionSource === 'random') {
      const rules = payload.randomRules || [];
      if (!rules.length) throw new Error('Add at least one random rule.');
      payload.questions = demoPickRandomRules(rules);
      payload.questionCount = payload.questions.length;
      payload.bankQuestionIds = [];
      payload.category = rules[0]?.category || 'General Aptitude';
      payload.difficulty = rules[0]?.difficulty || 'Medium';
      return payload;
    }
    const bankIds = payload.bankQuestionIds || [];
    const all = loadDemoBankStore();
    const fromBank = bankIds.map((id) => all.find((q) => String(q.id) === String(id))).filter(Boolean);
    const inline = payload.questions || [];
    payload.questions = [...fromBank, ...inline];
    payload.questionCount = payload.questions.length;
    if (!payload.questions.length) throw new Error('Select bank questions or add MCQs.');
    payload.category = fromBank[0]?.category || inline[0]?.category || 'General Aptitude';
    payload.difficulty = fromBank[0]?.difficulty || inline[0]?.difficulty || 'Medium';
    return payload;
  }

  function canManageContests() {
    return typeof Auth.canManageAptitudeContests === 'function' && Auth.canManageAptitudeContests();
  }

  function initContestMonthDaySelect() {
    const el = document.getElementById('tfContestMonthDay');
    if (!el || el.options.length > 0) return;
    el.innerHTML = Array.from({ length: 28 }, (_, i) => {
      const day = i + 1;
      return `<option value="${day}">${day}</option>`;
    }).join('');
  }

  function syncContestFormFields() {
    const wrap = document.getElementById('tfContestWrap');
    const type = document.getElementById('tfContestType')?.value || 'none';
    wrap?.classList.toggle('d-none', !canManageContests());
    document.getElementById('tfContestWeekdayWrap')?.classList.toggle('d-none', type !== 'weekly');
    document.getElementById('tfContestMonthDayWrap')?.classList.toggle('d-none', type !== 'monthly');
  }

  let managePanel = 'tests';

  function isContestTest(t) {
    const type = String(t?.contestType || 'none');
    return type === 'weekly' || type === 'monthly';
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
    if (managePanel === 'bank') loadQuestionBank().catch(() => {});
  }

  function syncManageContestActions() {
    const show = canManageContests();
    document.getElementById('manageContestNavItem')?.classList.toggle('d-none', !show);
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
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="${esc(t.id)}">Bulk questions</button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-edit="${esc(t.id)}">Edit</button>
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
    root.querySelectorAll('[data-bulk]').forEach((btn) => {
      btn.addEventListener('click', () => openBulk('test', btn.getAttribute('data-bulk')));
    });
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
    if (test?.contestScheduleLabel) return String(test.contestScheduleLabel);
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

  function collectContestPayload() {
    const type = document.getElementById('tfContestType')?.value || 'none';
    const payload = { contestType: type };
    if (type === 'weekly') {
      payload.contestWeekday = Number(document.getElementById('tfContestWeekday')?.value || 1);
    } else if (type === 'monthly') {
      payload.contestMonthDay = Number(document.getElementById('tfContestMonthDay')?.value || 1);
    }
    return payload;
  }

  function contestBadgeHtml(t) {
    const type = String(t?.contestType || 'none');
    if (type === 'none') return '';
    const label = contestScheduleLabel(t);
    const open = isContestOpenClient(t);
    const stateCls = open ? 'success' : 'muted';
    const state = open ? 'Open today' : 'Scheduled';
    return `<div class="mt-1 d-flex flex-wrap gap-1"><span class="badge-soft info">${esc(label || type)}</span><span class="badge-soft ${stateCls}">${state}</span></div>`;
  }

  function testMetaLine(t) {
    const neg = t.negativeMarking ? ` · −${t.negativeMarks || 0}/wrong` : '';
    return `${esc(t.category)} · ${esc(t.difficulty || 'Medium')} · ${esc(t.questionCount || (t.questions || []).length)} Qs · ${esc(t.durationMinutes)} min · ${esc(t.totalMarks || 0)} marks${neg}`;
  }

  function demoProgress() {
    try {
      const p = JSON.parse(localStorage.getItem('ph-aptitude-demo-progress') || '{"history":[],"testsAttempted":0,"bestScore":0,"percentage":0,"accuracy":0,"recentPerformance":0}');
      if (Array.isArray(p.history)) {
        p.history = p.history.map((h) => enrichHistoryEntry(h));
      }
      return p;
    } catch {
      return { history: [], testsAttempted: 0, bestScore: 0, percentage: 0, accuracy: 0, recentPerformance: 0 };
    }
  }

  function saveDemoProgress(p) {
    if (Array.isArray(p.history)) {
      p.history = p.history.map((h) => enrichHistoryEntry(h));
    }
    localStorage.setItem('ph-aptitude-demo-progress', JSON.stringify(p));
  }

  function resolveHistoryTest(h) {
    if (h.testId) {
      const byId = fullDemoTest(h.testId) || loadDemoTestsStore().find((t) => String(t.id) === String(h.testId));
      if (byId) return byId;
    }
    const title = String(h.testTitle || h.testName || '').trim();
    if (!title) return null;
    return loadDemoTestsStore().find((t) => String(t.title || '') === title) || null;
  }

  function enrichHistoryEntry(h) {
    if (!h || typeof h !== 'object') return h;
    const copy = { ...h };
    const test = resolveHistoryTest(copy);
    if (test) {
      copy.testId = copy.testId || test.id;
      if (!copy.contestType) copy.contestType = test.contestType || 'none';
      if (!copy.totalMarks && !copy.maximumScore) {
        const total = Number(test.totalMarks) || (Array.isArray(test.questions) ? test.questions.length : 0);
        if (total > 0) {
          copy.totalMarks = total;
          copy.maximumScore = total;
        }
      }
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

  function formatHistoryMeta(h) {
    const row = enrichHistoryEntry(h);
    const bits = [];
    const sec = Number(row.timeTakenSeconds);
    if (row.timeTakenLabel) {
      bits.push(String(row.timeTakenLabel));
    } else if (Number.isFinite(sec) && sec > 0) {
      bits.push(typeof AptitudeExam !== 'undefined' && AptitudeExam.formatTimer
        ? AptitudeExam.formatTimer(sec)
        : `${Math.floor(sec / 60)}m ${String(sec % 60).padStart(2, '0')}s`);
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

  function scoreLocally(test, questions, answers, metaPayload) {
    let marks = 0;
    let total = 0;
    let correct = 0;
    let wrong = 0;
    let unanswered = 0;
    const neg = test.negativeMarking ? Number(test.negativeMarks || 0) : 0;
    const analysis = [];
    (questions || []).forEach((q) => {
      const full = (fullDemoTest(test.id)?.questions || []).find((x) => x.id === q.id) || q;
      const qMarks = Number(full.marks ?? 1);
      total += qMarks;
      const picked = Object.prototype.hasOwnProperty.call(answers, q.id) ? Number(answers[q.id]) : -1;
      const opts = full.options || q.options || [];
      const correctIndex = Number(full.correctIndex);
      let status = 'unanswered';
      let marksForQ = 0;
      if (picked < 0) unanswered += 1;
      else if (picked === correctIndex) {
        correct += 1;
        marksForQ = qMarks;
        marks += qMarks;
        status = 'correct';
      } else {
        wrong += 1;
        if (neg > 0) {
          marksForQ = -neg;
          marks -= neg;
        }
        status = 'incorrect';
      }
      analysis.push({
        question: full.prompt || q.prompt,
        studentAnswer: picked >= 0 ? opts[picked] : null,
        correctAnswer: opts[correctIndex],
        explanation: full.explanation || '',
        marks: qMarks,
        marksObtained: marksForQ,
        status,
      });
    });
    if (marks < 0) marks = 0;
    const pct = total > 0 ? Math.round((marks / total) * 1000) / 10 : 0;
    const accuracy = correct + wrong > 0 ? Math.round((correct / (correct + wrong)) * 1000) / 10 : 0;
    const result = {
      score: marks,
      marksObtained: marks,
      maximumScore: total,
      totalMarks: total,
      percentage: pct,
      accuracy,
      correctAnswers: correct,
      incorrectAnswers: wrong,
      unansweredQuestions: unanswered,
      timeTakenSeconds: metaPayload.timeTakenSeconds || 0,
      timeTakenLabel: AptitudeExam.formatTimer(metaPayload.timeTakenSeconds || 0),
      rank: 1,
      percentile: 100,
      questionAnalysis: analysis,
    };
    const p = demoProgress();
    p.history = [{
      testTitle: test.title,
      testId: test.id,
      contestType: test.contestType || 'none',
      percentage: pct,
      category: test.category,
      marksObtained: marks,
      score: marks,
      totalMarks: total,
      maximumScore: total,
      timeTakenSeconds: metaPayload.timeTakenSeconds || 0,
      timeTakenLabel: typeof AptitudeExam !== 'undefined' && AptitudeExam.formatTimer
        ? AptitudeExam.formatTimer(metaPayload.timeTakenSeconds || 0)
        : '',
      attemptId: `demo-${Date.now()}`,
    }, ...(p.history || []).map((h) => enrichHistoryEntry(h))];
    p.testsAttempted = (p.testsAttempted || 0) + 1;
    p.bestScore = Math.max(p.bestScore || 0, pct);
    p.recentPerformance = pct;
    const scores = p.history.map((h) => Number(h.percentage) || 0);
    p.percentage = scores.length ? Math.round((scores.reduce((a, b) => a + b, 0) / scores.length) * 10) / 10 : 0;
    p.accuracy = p.percentage;
    saveDemoProgress(p);
    return result;
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
    const role = Auth.role();
    if (views.includes('progress') && (role === 'placement_officer' || role === 'admin' || role === 'staff')) return 'progress';
    if (views.includes('manage') && (role === 'admin' || role === 'staff')) return 'manage';
    if (views.includes('take')) return 'take';
    if (views.includes('progress')) return 'progress';
    return views[0] || 'take';
  }

  function setupViewNav() {
    const nav = document.getElementById('aptViewNav');
    if (!nav) return;
    const views = allowedViews();
    nav.classList.toggle('d-none', views.length <= 1);
    nav.querySelectorAll('[data-view]').forEach((link) => {
      const view = link.getAttribute('data-view');
      const show = views.includes(view);
      link.closest('.nav-item')?.classList.toggle('d-none', !show);
    });
  }

  async function applyView(requested) {
    const views = allowedViews();
    let view = requested || defaultView();
    if (!views.includes(view)) view = defaultView();

    const hash = `#${view}`;
    if (location.hash !== hash) {
      history.replaceState(null, '', hash);
    }

    document.getElementById('aptTake')?.classList.toggle('d-none', view !== 'take');
    document.getElementById('aptDirectory')?.classList.toggle('d-none', view !== 'progress');
    document.getElementById('aptManage')?.classList.toggle('d-none', view !== 'manage');

    document.querySelectorAll('#aptViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-view') === view);
    });

    if (view === 'take' && access.canTake) await loadMyProgress();
    if (view === 'progress' && access.canViewDirectory) {
      await initDirFilters();
      await loadDirectory();
    }
    if (view === 'manage' && access.canManage) renderManage();

    if (typeof renderShell === 'function') {
      renderShell(`${document.body?.dataset?.page || 'mock-aptitude.html'}${hash}`);
    }
  }

  async function loadAccess() {
    access = {
      canTake: typeof Auth.canTakeAptitudeMock === 'function' && Auth.canTakeAptitudeMock(),
      canManage: typeof Auth.canManageAptitudeMocks === 'function' && Auth.canManageAptitudeMocks(),
      canViewDirectory: typeof Auth.canViewAptitudeDirectory === 'function' && Auth.canViewAptitudeDirectory(),
      scope: null,
    };
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/access').catch(() => null);
      if (res?.success && res.data) {
        access = {
          canTake: !!res.data.canTake,
          canManage: !!res.data.canManage,
          canViewDirectory: !!res.data.canViewDirectory,
          scope: res.data.scope || null,
        };
      }
      const metaRes = await api('/aptitude/meta').catch(() => null);
      if (metaRes?.success && metaRes.data) meta = { ...meta, ...metaRes.data };
    } else if (Auth.role() === 'staff') {
      const u = Auth.user() || {};
      access.scope = {
        role: 'staff',
        departmentId: u.departmentId || '',
        departmentName: u.departmentName || u.department || '',
        assignedClassBatches: staffAssignedBatches(),
      };
      access.canTake = false;
      access.canManage = typeof Auth.canManageAptitudeMocks === 'function' && Auth.canManageAptitudeMocks();
      access.canViewDirectory = typeof Auth.canViewAptitudeDirectory === 'function' && Auth.canViewAptitudeDirectory();
    }
  }

  function studentIdLabel(r) {
    return r.registerNumber || r.studentCode || r.studentId || '—';
  }

  function categoryShort(r) {
    const cats = r.categoryPerformance || r.categoryWise || {};
    const entries = Object.entries(cats);
    if (!entries.length) return '—';
    return entries.slice(0, 2).map(([k, v]) => `${k}: ${v.percentage ?? 0}%`).join(' · ')
      + (entries.length > 2 ? ` · +${entries.length - 2}` : '');
  }

  function renderProgressDetail(p) {
    const cats = Object.entries(p.categoryPerformance || p.categoryWise || {}).map(([k, v]) =>
      `<div class="d-flex justify-content-between small border-bottom py-1"><span>${esc(k)}</span><strong>${esc(v.percentage ?? 0)}%</strong></div>`
    ).join('') || '<div class="small text-muted-2">No category scores yet.</div>';
    const hist = (p.history || []).slice(0, 12).map((h) =>
      `<div class="d-flex justify-content-between small border-bottom py-1 gap-2">
        <span class="text-truncate">${esc(h.testTitle || 'Test')}</span>
        <span class="text-end text-nowrap">${esc(formatHistoryMeta(h))}</span>
      </div>`
    ).join('') || '<div class="small text-muted-2">No test history.</div>';
    return `
      <div class="mb-3">
        <div class="fw-bold">${esc(p.name || 'Student')}</div>
        <div class="small text-muted-2">${esc(studentIdLabel(p))} · ${esc(p.classBatch || '—')} · ${esc(p.course || '—')}</div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-4"><div class="card-surface p-2"><div class="small text-muted-2">Attempts</div><strong>${esc(p.testsAttempted ?? 0)}</strong></div></div>
        <div class="col-6 col-md-4"><div class="card-surface p-2"><div class="small text-muted-2">Average</div><strong>${esc(p.averageScore ?? p.percentage ?? 0)}%</strong></div></div>
        <div class="col-6 col-md-4"><div class="card-surface p-2"><div class="small text-muted-2">Best</div><strong>${esc(p.bestScore ?? 0)}%</strong></div></div>
        <div class="col-6 col-md-4"><div class="card-surface p-2"><div class="small text-muted-2">Accuracy</div><strong>${esc(p.accuracy ?? 0)}%</strong></div></div>
        <div class="col-6 col-md-4"><div class="card-surface p-2"><div class="small text-muted-2">Recent</div><strong>${esc(p.recentScore ?? p.recentPerformance ?? 0)}%</strong></div></div>
      </div>
      <h6 class="fw-bold">Category performance</h6>
      <div class="mb-3">${cats}</div>
      <h6 class="fw-bold">Detailed progress</h6>
      <div>${hist}</div>`;
  }

  async function openStudentDetail(userId) {
    const body = document.getElementById('studentAptBody');
    body.innerHTML = '<p class="text-muted-2 mb-0">Loading…</p>';
    studentAptModal?.show();
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      const student = typeof UserRegistry !== 'undefined' ? UserRegistry.get(userId) : null;
      const row = demoDirectoryRows().rows.find((r) => String(r.userId) === String(userId));
      if (!student && !row) {
        body.innerHTML = '<p class="text-danger mb-0">Could not load student progress.</p>';
        return;
      }
      body.innerHTML = renderProgressDetail({
        name: student?.name || row?.name || 'Student',
        registerNumber: student?.registerNumber || row?.registerNumber,
        classBatch: student?.classBatch || row?.classBatch,
        course: student?.department || row?.course,
        testsAttempted: row?.testsAttempted || 0,
        bestScore: row?.bestScore || 0,
        percentage: row?.averageScore || 0,
        accuracy: row?.accuracy || 0,
        recentPerformance: row?.recentScore || 0,
        categoryPerformance: row?.categoryPerformance || {},
        history: (row?.testsAttempted || 0) > 0
          ? [{ testTitle: 'Quantitative Aptitude — Basics', percentage: row?.recentScore || row?.averageScore || 0 }]
          : [],
      });
      return;
    }
    const res = await api(`/aptitude/subjects/${encodeURIComponent(userId)}`).catch(() => null);
    if (!res?.success) {
      body.innerHTML = `<p class="text-danger mb-0">${esc(res?.message || 'Could not load student progress.')}</p>`;
      return;
    }
    body.innerHTML = renderProgressDetail(res.data || {});
  }

  async function loadTests() {
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/tests').catch(() => null);
      if (res?.success) {
        tests = res.data?.tests || [];
        return;
      }
    }
    tests = loadDemoTestsStore().map((t) => {
      const copy = JSON.parse(JSON.stringify(t));
      if (!access.canManage) {
        copy.questions = (copy.questions || []).map(({ correctIndex, explanation, ...q }) => q);
      }
      return copy;
    });
  }

  function renderMyStats(p) {
    document.getElementById('myStatsRow').innerHTML = [
      ['Attempts', p.testsAttempted || 0],
      ['Best %', p.bestScore ?? 0],
      ['Average %', p.percentage ?? 0],
      ['Recent %', p.recentPerformance ?? 0],
    ].map(([lbl, val]) => `<div class="col-6 col-md-3"><div class="card-surface p-3 apt-stat"><div class="small text-muted-2">${lbl}</div><div class="val">${esc(val)}</div></div></div>`).join('');
  }

  function formatHistoryDuration(h) {
    const row = enrichHistoryEntry(h);
    if (row.timeTakenLabel) return String(row.timeTakenLabel);
    const sec = Number(row.timeTakenSeconds);
    if (Number.isFinite(sec) && sec > 0) {
      if (typeof AptitudeExam !== 'undefined' && AptitudeExam.formatTimer) {
        return AptitudeExam.formatTimer(sec);
      }
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return `${m}m ${String(s).padStart(2, '0')}s`;
    }
    return '';
  }

  function formatHistoryMarks(h) {
    const row = enrichHistoryEntry(h);
    const obtained = Number(row.marksObtained ?? row.score);
    const total = Number(row.totalMarks ?? row.maximumScore);
    if (Number.isFinite(obtained) && Number.isFinite(total) && total > 0) {
      const shown = `${obtained}/${total}`;
      const pct = Number(row.percentage);
      return Number.isFinite(pct) ? `${shown} (${pct}%)` : shown;
    }
    const pct = Number(row.percentage);
    return Number.isFinite(pct) ? `${pct}%` : '';
  }

  function renderHistory(p) {
    const hist = (p.history || []).map((h) => enrichHistoryEntry(h));
    const filtered = hist.filter((h) => (
      myResultsPanel === 'contests' ? historyEntryIsContest(h) : !historyEntryIsContest(h)
    ));
    const canReview = access.canTake;
    const emptyLabel = myResultsPanel === 'contests'
      ? 'No contest attempts yet.'
      : 'No attempts yet. Select a test on the left to begin.';
    document.getElementById('myHistory').innerHTML = filtered.length
      ? filtered.slice(0, 8).map((h) => {
          const attemptId = h.attemptId || h.id;
          const viewBtn = canReview && attemptId && Auth.hasRealAuth() && !Auth.isDemo()
            ? `<button type="button" class="btn btn-link btn-sm p-0" data-view-attempt="${esc(attemptId)}">View</button>`
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
    document.getElementById('myHistory').querySelectorAll('[data-view-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt')));
    });
  }

  async function viewAttemptResult(attemptId) {
    if (!attemptId) return;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      toast('Detailed results are available in a live student session.', 'info');
      return;
    }
    const res = await api(`/aptitude/attempts/${encodeURIComponent(attemptId)}/result`).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not load result.', 'error');
      return;
    }
    document.getElementById('hubView').classList.add('d-none');
    exam.showResult(res.data);
  }

  function renderTestList() {
    const root = document.getElementById('testList');
    let visible = access.canManage
      ? tests
      : tests.filter((t) => (t.status || 'published') === 'published' && isContestOpenClient(t));
    if (!visible.length) {
      const msg = Auth.role() === 'student'
        ? 'No aptitude mocks are published yet. Check back later or contact your placement officer.'
        : 'No published aptitude tests yet.';
      root.innerHTML = `<p class="text-muted-2 mb-0">${msg}</p>`;
      return;
    }
    root.innerHTML = visible.map((t) => {
      const contestLine = String(t.contestType || 'none') !== 'none'
        ? `<div class="small mt-1"><span class="badge-soft info">${esc(contestScheduleLabel(t))}</span></div>`
        : '';
      return `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div class="flex-grow-1">
          <div class="fw-semibold">${esc(t.title)}</div>
          <div class="small text-muted-2">${testMetaLine(t)}</div>
          ${contestLine}
          <div class="small mt-1">${esc(t.description || '')}</div>
        </div>
        ${access.canTake && (t.status || 'published') === 'published'
          ? `<button type="button" class="btn btn-sm btn-primary" data-open-test="${esc(t.id)}">Select</button>`
          : ''}
      </div>`;
    }).join('');
    root.querySelectorAll('[data-open-test]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = tests.find((x) => String(x.id) === String(btn.getAttribute('data-open-test')));
        if (t) openExam(t);
      });
    });
  }

  function openExam(test) {
    document.getElementById('hubView').classList.add('d-none');
    exam.open(test);
  }

  function closeExam() {
    exam.hide();
    document.getElementById('hubView').classList.remove('d-none');
    loadMyProgress();
    if (access.canTake) renderTestList();
  }

  const MCQ_QUILL_TOOLBAR = [
    ['bold', 'italic', 'underline', 'strike'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ script: 'sub' }, { script: 'super' }],
    ['blockquote', 'code-block'],
    ['link', 'image'],
    ['clean'],
  ];

  const MCQ_IMAGE_MAX_BYTES = 2 * 1024 * 1024;

  async function uploadMcqImage(file) {
    if (!file || !file.type.startsWith('image/')) {
      throw new Error('Please choose an image file.');
    }
    if (file.size > MCQ_IMAGE_MAX_BYTES) {
      throw new Error('Image must be 2 MB or smaller.');
    }
    const live = typeof Auth !== 'undefined' && Auth.hasRealAuth && Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      return await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(new Error('Could not read image.'));
        reader.readAsDataURL(file);
      });
    }
    const fd = new FormData();
    fd.append('image', file);
    const res = await api('/aptitude/media', { method: 'POST', body: fd });
    if (!res?.success || !res.data?.url) {
      throw new Error(res?.message || 'Image upload failed.');
    }
    return res.data.url;
  }

  function pickMcqImage(quill) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp,image/gif';
    input.onchange = async () => {
      const file = input.files?.[0];
      if (!file || !quill) return;
      try {
        const url = await uploadMcqImage(file);
        const range = quill.getSelection(true);
        const index = range ? range.index : quill.getLength();
        quill.insertEmbed(index, 'image', url, 'user');
        quill.setSelection(index + 1);
      } catch (err) {
        toast(err?.message || 'Could not upload image.', 'error');
      }
    };
    input.click();
  }

  function richTextPlain(html) {
    const node = document.createElement('div');
    node.innerHTML = String(html || '');
    return (node.textContent || '').replace(/\u200B/g, '').trim();
  }

  function richTextHasContent(html) {
    const node = document.createElement('div');
    node.innerHTML = String(html || '');
    if (node.querySelector('img[src]')) return true;
    return richTextPlain(html).length > 0;
  }

  function initMcqQuill(container, initialHtml = '', compact = false) {
    if (!container || typeof Quill === 'undefined') return null;
    container.classList.add('mcq-quill-wrap');
    if (compact) container.classList.add('mcq-quill-sm');
    const editor = document.createElement('div');
    container.appendChild(editor);
    let quill;
    quill = new Quill(editor, {
      theme: 'snow',
      modules: {
        toolbar: {
          container: MCQ_QUILL_TOOLBAR,
          handlers: {
            image() { pickMcqImage(quill); },
          },
        },
      },
      placeholder: compact ? 'Explanation shown after submission' : 'Enter the question',
    });
    const html = String(initialHtml || '').trim();
    if (html) {
      if (html.includes('<')) quill.clipboard.dangerouslyPasteHTML(html);
      else quill.setText(html);
    }
    container._quill = quill;
    return quill;
  }

  function getMcqEditorHtml(container) {
    const quill = container?._quill;
    if (!quill) return '';
    const html = quill.root.innerHTML.trim();
    return html === '<p><br></p>' ? '' : html;
  }

  function syncFormQuestionTotals() {
    updateManualQuestionCount();
  }

  async function importMcqsFromFormExcel(file) {
    const rows = await parseExcelFile(file);
    const category = (meta.categories || APTITUDE_CATEGORIES)[0] || 'General Aptitude';
    const normalized = normalizeBulkRows(rows, category, 'Medium');
    if (!normalized.length) {
      throw new Error('No valid questions found in the Excel file.');
    }
    const list = document.getElementById('mcqList');
    if (!list) return 0;
    normalized.forEach((q) => addMcqRow(q));
    syncFormQuestionTotals();
    return normalized.length;
  }

  function addMcqRow(q = {}) {
    mcqCounter += 1;
    const opts = Array.isArray(q.options) && q.options.length ? q.options.slice() : ['', '', '', ''];
    while (opts.length < 4) opts.push('');
    const wrap = document.createElement('div');
    wrap.className = 'border rounded-3 p-3';
    wrap.dataset.mcq = '1';
    wrap.innerHTML = `
      <div class="d-flex justify-content-between mb-2"><strong class="small">MCQ</strong><button type="button" class="btn btn-sm btn-outline-danger" data-remove-mcq>Remove</button></div>
      <div class="mb-2">
        <label class="form-label small mb-1">Question</label>
        <div data-f="prompt-editor"></div>
      </div>
      <div class="row g-2 mb-2">${opts.slice(0, 4).map((o, i) => `<div class="col-md-6"><label class="form-label small mb-1">Option ${i + 1}</label><input class="form-control form-control-sm" data-f="opt${i}" placeholder="Option ${i + 1}" value="${esc(o)}" ${i < 2 ? 'required' : ''}/></div>`).join('')}</div>
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label small mb-0">Correct</label><select class="form-select form-select-sm" data-f="correct">${[0, 1, 2, 3].map((i) => `<option value="${i}" ${Number(q.correctIndex) === i ? 'selected' : ''}>Option ${i + 1}</option>`).join('')}</select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Marks</label><input class="form-control form-control-sm" type="number" min="0.5" step="0.5" data-f="marks" value="${esc(q.marks ?? 1)}"/></div>
        <div class="col-md-6">
          <label class="form-label small mb-0">Explanation</label>
          <div data-f="explanation-editor"></div>
        </div>
      </div>`;
    wrap.querySelector('[data-remove-mcq]').addEventListener('click', () => {
      wrap.remove();
      updateManualQuestionCount();
    });
    document.getElementById('mcqList').appendChild(wrap);
    initMcqQuill(wrap.querySelector('[data-f="prompt-editor"]'), q.prompt || '');
    initMcqQuill(wrap.querySelector('[data-f="explanation-editor"]'), q.explanation || '', true);
  }

  function collectMcqs() {
    return [...document.querySelectorAll('#mcqList [data-mcq]')].map((el, i) => {
      const options = [0, 1, 2, 3].map((n) => String(el.querySelector(`[data-f="opt${n}"]`)?.value || '').trim()).filter(Boolean);
      const prompt = getMcqEditorHtml(el.querySelector('[data-f="prompt-editor"]'));
      const explanation = getMcqEditorHtml(el.querySelector('[data-f="explanation-editor"]'));
      return {
        id: `q${i + 1}`,
        type: 'mcq',
        prompt,
        options,
        correctIndex: Number(el.querySelector('[data-f="correct"]')?.value || 0),
        marks: Number(el.querySelector('[data-f="marks"]')?.value || 1),
        explanation,
      };
    }).filter((q) => richTextHasContent(q.prompt) && q.options.length >= 2);
  }

  function openTestForm(test = null, preset = null) {
    const isContestPreset = preset?.contestType === 'weekly' || preset?.contestType === 'monthly';
    const isContest = isContestTest(test) || isContestPreset;
    document.getElementById('testFormTitle').textContent = test
      ? (isContest ? 'Edit contest' : 'Edit aptitude test')
      : (isContestPreset ? `New ${preset.contestType} contest` : 'New aptitude test');
    document.getElementById('tfId').value = test?.id || '';
    document.getElementById('tfTitle').value = test?.title || preset?.title || '';
    document.getElementById('tfDescription').value = test?.description || '';
    document.getElementById('tfQuestionCount').value = test?.questionCount || (test?.questions || []).length || 10;
    document.getElementById('tfDuration').value = test?.durationMinutes || 30;
    document.getElementById('tfNegative').checked = !!test?.negativeMarking;
    document.getElementById('tfNegativeMarks').value = test?.negativeMarks ?? 0;
    document.getElementById('tfStatus').value = test?.status === 'published' ? 'published' : 'unpublished';

    const source = test?.questionSource === 'random' ? 'random' : 'manual';
    document.getElementById('tfSourceManual').checked = source === 'manual';
    document.getElementById('tfSourceRandom').checked = source === 'random';

    selectedBankIds.clear();
    (test?.bankQuestionIds || []).forEach((id) => selectedBankIds.add(String(id)));

    document.getElementById('tfRandomRules').innerHTML = '';
    const rules = test?.randomRules?.length ? test.randomRules : [{ category: 'General Aptitude', difficulty: 'Medium', count: 5 }];
    if (source === 'random') rules.forEach((r) => addRandomRuleRow(r));
    else addRandomRuleRow({ category: 'General Aptitude', difficulty: 'Medium', count: 5 });

    const useBank = (test?.bankQuestionIds || []).length > 0;
    document.getElementById('tfUseBankManual').checked = useBank;
    document.getElementById('tfBankPicker')?.classList.toggle('d-none', !useBank);

    document.getElementById('tfBankFilterRules').innerHTML = '';
    if (useBank) {
      addBankFilterRuleRow({
        category: test?.category || 'General Aptitude',
        difficulty: test?.difficulty || 'Medium',
        count: Math.max(1, (test?.bankQuestionIds || []).length || 5),
      });
    }

    initContestMonthDaySelect();
    const contestType = test?.contestType || preset?.contestType || 'none';
    document.getElementById('tfContestType').value = ['weekly', 'monthly'].includes(contestType) ? contestType : 'none';
    document.getElementById('tfContestWeekday').value = String(test?.contestWeekday || preset?.contestWeekday || 1);
    document.getElementById('tfContestMonthDay').value = String(test?.contestMonthDay || preset?.contestMonthDay || 1);
    syncContestFormFields();
    syncQuestionSourcePanels();
    document.getElementById('tfBulkFile').value = '';
    document.getElementById('mcqList').innerHTML = '';
    const qs = source === 'manual' ? (test?.questions || []).filter((q) => !q.bankId) : [];
    if (qs.length) qs.forEach((q) => addMcqRow(q));
    loadBankPickerQuestions().catch(() => renderBankPicker());
    testFormModal.show();
  }

  function collectTestFormPayload() {
    const source = getQuestionSource();
    const payload = {
      title: document.getElementById('tfTitle').value.trim(),
      description: document.getElementById('tfDescription').value.trim(),
      questionCount: Number(document.getElementById('tfQuestionCount').value || 0),
      durationMinutes: Number(document.getElementById('tfDuration').value || 30),
      negativeMarking: document.getElementById('tfNegative').checked,
      negativeMarks: Number(document.getElementById('tfNegativeMarks').value || 0),
      status: document.getElementById('tfStatus').value,
      questionSource: source,
      instructions: '',
    };
    if (source === 'random') {
      payload.randomRules = collectRandomRules();
      payload.questions = [];
      payload.bankQuestionIds = [];
    } else {
      payload.randomRules = [];
      payload.bankQuestionIds = document.getElementById('tfUseBankManual')?.checked
        ? [...selectedBankIds]
        : [];
      payload.questions = collectMcqs();
    }
    if (canManageContests()) Object.assign(payload, collectContestPayload());
    return payload;
  }

  function openBulk(mode, testId = '') {
    document.getElementById('bulkMode').value = mode;
    document.getElementById('bulkTestId').value = testId || '';
    document.getElementById('bulkTitle').textContent = mode === 'bank' ? 'Upload question bank' : 'Bulk upload to test';
    document.getElementById('bulkCategoryWrap').classList.toggle('d-none', mode !== 'bank');
    document.getElementById('bulkDifficultyWrap').classList.toggle('d-none', mode !== 'bank');
    document.getElementById('bulkReplaceWrap').classList.toggle('d-none', mode !== 'test');
    fillSelect(document.getElementById('bulkCategory'), meta.categories || APTITUDE_CATEGORIES, 'General Aptitude');
    document.getElementById('bulkFile').value = '';
    bulkModal.show();
  }

  const BULK_EXCEL_HEADERS = ['prompt', 'optionA', 'optionB', 'optionC', 'optionD', 'correct', 'marks', 'explanation', 'category', 'difficulty'];
  const BULK_SHEET_DIFFICULTIES = { easy: 'Easy', medium: 'Medium', hard: 'Hard' };

  function downloadExcelTemplate() {
    if (typeof XLSX === 'undefined') {
      toast('Excel library is still loading. Try again in a moment.', 'info');
      return;
    }
    const wb = XLSX.utils.book_new();
    const samples = {
      Easy: ['What is 2+2?', '3', '4', '5', '6', 'B', 1, 'Basic arithmetic', 'Quantitative Aptitude', 'Easy'],
      Medium: ['If x+3=10, x=?', '5', '6', '7', '8', 'C', 2, 'Linear equation', 'Quantitative Aptitude', 'Medium'],
      Hard: ['Train A 60km/h, B 90km/h, opposite. Total 450km. Meet in?', '2h', '3h', '4h', '5h', 'B', 3, 'Relative speed', 'Quantitative Aptitude', 'Hard'],
    };
    Object.entries(samples).forEach(([sheetName, sample]) => {
      const ws = XLSX.utils.aoa_to_sheet([BULK_EXCEL_HEADERS, sample]);
      XLSX.utils.book_append_sheet(wb, ws, sheetName);
    });
    XLSX.writeFile(wb, 'aptitude-question-bank-template.xlsx');
  }

  async function parseExcelFile(file) {
    if (typeof XLSX === 'undefined') {
      throw new Error('Excel library not loaded.');
    }
    const name = String(file?.name || '').toLowerCase();
    if (!/\.(xlsx|xls)$/.test(name)) {
      throw new Error('Please choose an Excel file (.xlsx or .xls).');
    }
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    if (!wb.SheetNames?.length) {
      throw new Error('The Excel file has no worksheets.');
    }

    const allRows = [];
    wb.SheetNames.forEach((sheetName) => {
      const rows = XLSX.utils.sheet_to_json(wb.Sheets[sheetName], { defval: '' });
      const sheetDifficulty = BULK_SHEET_DIFFICULTIES[String(sheetName).trim().toLowerCase()] || null;
      rows.forEach((row) => {
        if (!row || typeof row !== 'object') return;
        if (sheetDifficulty && !rowField(row, 'difficulty', 'level', 'difficulty level')) {
          row.difficulty = sheetDifficulty;
        }
        allRows.push(row);
      });
    });

    if (!allRows.length) {
      throw new Error('No question rows found in the workbook.');
    }
    return allRows;
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
        : '<p class="text-muted-2 mb-0">No regular tests yet.</p>';
      bindManageListActions(testsRoot);
    }
    if (contestsRoot) {
      contestsRoot.innerHTML = contests.length
        ? contests.map((t) => renderManageRow(t, { showContestBadge: true })).join('')
        : '<p class="text-muted-2 mb-0">No weekly or monthly contests yet.</p>';
      bindManageListActions(contestsRoot);
    }
  }

  async function loadMyProgress() {
    if (!access.canTake) return;
    let p = demoProgress();
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/me').catch(() => null);
      if (res?.success) p = res.data || p;
    }
    if (Array.isArray(p.history)) {
      p.history = p.history.map((h) => enrichHistoryEntry(h));
    }
    if (Auth.isDemo()) saveDemoProgress(p);
    renderMyStats(p);
    renderHistory(p);
  }

  async function loadDirectory() {
    if (!access.canViewDirectory) return;
    const role = Auth.role();
    document.getElementById('dirTitle').textContent = progressDirTitle(role, progressPanel);
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      const scope = {
        ...(access.scope || {}),
        assignedClassBatches: staffAssignedBatches(),
      };
      access.scope = scope;
      updateDirScopeHint(scope);
      if (progressPanel === 'contests') {
        const demo = demoContestResults();
        renderContestResults(demo.contests || [], demo.summary || {}, scope);
      } else {
        const demo = demoDirectoryRows(progressPanel);
        renderDirectoryTable(demo.rows || [], demo.summary || {}, scope);
      }
      return;
    }
    const qs = buildDirectoryQuery();
    const res = await api('/aptitude/progress?' + qs.toString()).catch(() => null);
    const summary = res?.data?.summary || {};
    const scope = res?.data?.scope || access.scope || {};
    access.scope = scope;
    if (scope.departmentId || scope.departmentName) {
      applyDirDepartmentFromData([{
        id: String(scope.departmentId || document.getElementById('fDepartment')?.value || ''),
        name: String(scope.departmentName || ''),
      }]);
    }
    updateDirScopeHint(scope);
    if (progressPanel === 'contests' || res?.data?.view === 'contests') {
      renderContestResults(res?.data?.contests || [], summary, scope);
      return;
    }
    renderDirectoryTable(res?.data?.rows || [], summary, scope);
  }

  onAppReady(async () => {
    testFormModal = new bootstrap.Modal(document.getElementById('testFormModal'));
    bulkModal = new bootstrap.Modal(document.getElementById('bulkModal'));
    exam = AptitudeExam.createExamController({
      root: document.getElementById('examShell'),
      onExit: () => closeExam(),
      resolveDemoQuestions: (id) => {
        const t = fullDemoTest(id);
        return (t?.questions || []).map(({ correctIndex, explanation, ...q }) => q);
      },
      scoreLocally,
    });

    studentAptModal = new bootstrap.Modal(document.getElementById('studentAptModal'));
    await loadAccess();
    initContestMonthDaySelect();
    syncManageContestActions();
    const any = access.canTake || access.canManage || access.canViewDirectory;
    if (!any) {
      document.getElementById('aptDenied').classList.remove('d-none');
      return;
    }

    await loadTests();
    if (access.canTake || access.canManage) renderTestList();

    setupViewNav();
    const initialView = (location.hash || '').replace(/^#/, '') || defaultView();
    await applyView(initialView);

    window.addEventListener('hashchange', () => {
      const view = (location.hash || '').replace(/^#/, '') || defaultView();
      applyView(view).catch(() => {});
    });
    document.getElementById('aptViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-view]');
      if (!link) return;
      e.preventDefault();
      applyView(link.getAttribute('data-view')).catch(() => {});
    });

    document.getElementById('progressViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-progress-view]');
      if (!link) return;
      e.preventDefault();
      applyProgressPanel(link.getAttribute('data-progress-view'));
      loadDirectory().catch(() => {});
    });
    document.getElementById('myResultsNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-view]');
      if (!link) return;
      e.preventDefault();
      applyMyResultsPanel(link.getAttribute('data-results-view'));
      if (access.canTake) {
        const p = Auth.isDemo() ? demoProgress() : { history: [] };
        if (Auth.hasRealAuth() && !Auth.isDemo()) {
          api('/aptitude/me').then((res) => {
            renderHistory(res?.success ? (res.data || p) : p);
          }).catch(() => renderHistory(p));
        } else {
          renderHistory(p);
        }
      }
    });

    document.getElementById('btnAddMcq')?.addEventListener('click', () => {
      addMcqRow();
      updateManualQuestionCount();
    });
    document.querySelectorAll('input[name="tfQuestionSource"]').forEach((el) => {
      el.addEventListener('change', syncQuestionSourcePanels);
    });
    document.getElementById('btnAddRandomRule')?.addEventListener('click', () => addRandomRuleRow());
    document.getElementById('tfUseBankManual')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      document.getElementById('tfBankPicker')?.classList.toggle('d-none', !on);
      if (on && !document.querySelector('#tfBankFilterRules .tf-bank-filter-rule')) {
        addBankFilterRuleRow({ category: 'General Aptitude', difficulty: 'Medium', count: 5 });
      } else if (on) {
        loadBankPickerQuestions().catch(() => renderBankPicker());
      }
      updateManualQuestionCount();
    });
    document.getElementById('btnAddBankFilterRule')?.addEventListener('click', () => addBankFilterRuleRow());
    document.getElementById('btnFormBulkTemplate')?.addEventListener('click', () => downloadExcelTemplate());
    document.getElementById('btnFormBulkImport')?.addEventListener('click', () => {
      document.getElementById('tfBulkFile')?.click();
    });
    document.getElementById('tfBulkFile')?.addEventListener('change', async (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      try {
        const added = await importMcqsFromFormExcel(file);
        toast(`Imported ${added} question(s) into the form.`, 'success');
      } catch (err) {
        toast(err?.message || 'Could not import Excel file.', 'error');
      } finally {
        e.target.value = '';
      }
    });
    document.getElementById('btnNewTest')?.addEventListener('click', () => {
      applyManagePanel('tests');
      openTestForm(null, { contestType: 'none' });
    });
    document.getElementById('btnNewWeeklyContest')?.addEventListener('click', () => {
      applyManagePanel('contests');
      openTestForm(null, {
        contestType: 'weekly',
        contestWeekday: new Date().getDay() === 0 ? 7 : new Date().getDay(),
        title: 'Weekly aptitude contest',
      });
    });
    document.getElementById('btnNewMonthlyContest')?.addEventListener('click', () => {
      applyManagePanel('contests');
      openTestForm(null, {
        contestType: 'monthly',
        contestMonthDay: Math.min(28, new Date().getDate()),
        title: 'Monthly aptitude contest',
      });
    });
    document.getElementById('manageViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-manage-view]');
      if (!link) return;
      e.preventDefault();
      applyManagePanel(link.getAttribute('data-manage-view'));
    });
    document.getElementById('tfContestType')?.addEventListener('change', syncContestFormFields);
    document.getElementById('btnBankUpload')?.addEventListener('click', () => openBulk('bank'));
    document.getElementById('btnBankUploadPanel')?.addEventListener('click', () => openBulk('bank'));

    document.getElementById('bankFilterCategory')?.addEventListener('change', () => {
      bankCategoryFilter = document.getElementById('bankFilterCategory')?.value || '';
      loadQuestionBank().catch(() => {});
    });
    document.getElementById('bankDifficultyNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-bank-difficulty]');
      if (!link) return;
      e.preventDefault();
      bankDifficultyFilter = link.getAttribute('data-bank-difficulty') || '';
      loadQuestionBank().catch(() => {});
    });

    document.getElementById('testForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      let payload = collectTestFormPayload();
      if (!payload.title) {
        toast('Enter a test title.', 'error');
        return;
      }
      if (payload.questionSource === 'random') {
        if (!payload.randomRules?.length) {
          toast('Add at least one random rule.', 'error');
          return;
        }
        const ruleTotal = payload.randomRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        if (ruleTotal !== payload.questionCount) {
          payload.questionCount = ruleTotal;
        }
      } else {
        const mcqCount = payload.questions?.length || 0;
        const useBank = document.getElementById('tfUseBankManual')?.checked;
        const bankNeeded = useBank ? bankFilterRulesNeeded() : 0;
        const bankSelected = useBank ? selectedBankIds.size : 0;
        if (useBank && bankNeeded > 0 && bankSelected !== bankNeeded) {
          toast(`Select ${bankNeeded} question(s) from the bank (${bankSelected} selected).`, 'error');
          return;
        }
        const total = mcqCount + (useBank ? bankNeeded : bankSelected);
        if (!total) {
          toast('Select bank questions or add at least one MCQ.', 'error');
          return;
        }
        payload.questionCount = total;
      }
      payload.totalMarks = 0;
      if (canManageContests()) applyManagePanel(isContestTest(payload) ? 'contests' : 'tests');
      const live = Auth.hasRealAuth() && !Auth.isDemo();
      if (!live) {
        if (!Auth.isDemo() || !access.canManage) {
          toast('Saving tests requires a live session with manage access.', 'info');
          return;
        }
        try {
          payload = resolveDemoTestQuestions(payload);
        } catch (err) {
          toast(err?.message || 'Could not build test questions.', 'error');
          return;
        }
        payload.totalMarks = (payload.questions || []).reduce((s, q) => s + Number(q.marks || 1), 0);
        const store = loadDemoTestsStore();
        const id = document.getElementById('tfId').value.trim() || `demo-${Date.now()}`;
        const next = { ...payload, id };
        const idx = store.findIndex((t) => String(t.id) === String(id));
        if (idx >= 0) store[idx] = { ...store[idx], ...next };
        else store.push(next);
        saveDemoTestsStore(store);
        toast('Test saved (demo).', 'success');
        testFormModal.hide();
        await loadTests();
        renderTestList();
        renderManage();
        return;
      }
      const id = document.getElementById('tfId').value.trim();
      const res = id
        ? await api(`/aptitude/tests/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(payload) })
        : await api('/aptitude/tests', { method: 'POST', body: JSON.stringify(payload) });
      if (!res?.success) {
        toast(res?.message || 'Could not save test.', 'error');
        return;
      }
      toast('Test saved.', 'success');
      testFormModal.hide();
      await loadTests();
      renderTestList();
      renderManage();
    });

    document.getElementById('btnBulkTemplate')?.addEventListener('click', () => downloadExcelTemplate());

    document.getElementById('btnBulkUpload')?.addEventListener('click', async () => {
      const live = Auth.hasRealAuth() && !Auth.isDemo();
      if (!live && (!Auth.isDemo() || !access.canManage)) {
        toast('Bulk upload requires a live session with manage access.', 'info');
        return;
      }
      const file = document.getElementById('bulkFile').files?.[0];
      if (!file) {
        toast('Choose an Excel file (.xlsx or .xls).', 'error');
        return;
      }
      let questions;
      try {
        questions = await parseExcelFile(file);
      } catch (err) {
        toast(err?.message || 'Could not read Excel file.', 'error');
        return;
      }
      const mode = document.getElementById('bulkMode').value;
      const fallbackCategory = document.getElementById('bulkCategory')?.value
        || (meta.categories || APTITUDE_CATEGORIES)[0]
        || 'General Aptitude';
      const fallbackDifficulty = document.getElementById('bulkDifficulty')?.value || 'Medium';
      const normalized = normalizeBulkRows(questions, fallbackCategory, fallbackDifficulty);
      if (!normalized.length) {
        toast('No valid questions found in the Excel file.', 'error');
        return;
      }
      if (!live) {
        try {
          const result = demoBulkUpload(normalized.map((q) => ({ ...q })), mode);
          toast(`Imported ${result.added} question(s) (demo).`, 'success');
          bulkModal.hide();
          await loadTests();
          renderTestList();
          renderManage();
          if (mode === 'bank') await loadQuestionBank();
        } catch (err) {
          toast(err?.message || 'Upload failed.', 'error');
        }
        return;
      }
      let res;
      if (mode === 'bank') {
        res = await api('/aptitude/question-bank/bulk', {
          method: 'POST',
          body: JSON.stringify({
            questions: normalized,
            category: fallbackCategory,
          }),
        });
      } else {
        const testId = document.getElementById('bulkTestId').value;
        res = await api(`/aptitude/tests/${encodeURIComponent(testId)}/questions/bulk`, {
          method: 'POST',
          body: JSON.stringify({
            questions: normalized,
            replace: document.getElementById('bulkReplace').checked,
          }),
        });
      }
      if (!res?.success) {
        toast(res?.message || 'Upload failed.', 'error');
        return;
      }
      toast(`Imported ${res.data?.added ?? 0} question(s).`, 'success');
      bulkModal.hide();
      await loadTests();
      renderTestList();
      renderManage();
      if (mode === 'bank') await loadQuestionBank();
    });
  });
})();
