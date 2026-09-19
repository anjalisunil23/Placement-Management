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
  const DEMO_JD_KEY = 'ph-aptitude-demo-jd-sets';
  const DEMO_JD_COMPANIES = [
    { id: 'demo-co-soti', name: 'SOTI' },
    { id: 'demo-co-infosys', name: 'Infosys' },
    { id: 'demo-co-tcs', name: 'TCS' },
    { id: 'demo-co-wipro', name: 'Wipro' },
  ];

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

  function ensureDemoBankSeed() {
    if (loadDemoBankStore().length) return;
    saveDemoBankStore([
      { id: 'bank-seed-1', category: 'General Aptitude', difficulty: 'Easy', marks: 1, prompt: 'What is 25% of 80?', options: ['15', '20', '25', '30'], correctIndex: 1, explanation: '25% of 80 = 20.' },
      { id: 'bank-seed-2', category: 'General Aptitude', difficulty: 'Medium', marks: 1, prompt: 'A can finish a job in 10 days and B in 15 days. Working together, how many days?', options: ['5', '6', '7', '8'], correctIndex: 1, explanation: 'Combined rate 1/10 + 1/15 = 1/6 → 6 days.' },
      { id: 'bank-seed-3', category: 'General Aptitude', difficulty: 'Medium', marks: 1, prompt: 'Find the next number: 2, 6, 12, 20, ?', options: ['28', '30', '32', '36'], correctIndex: 1, explanation: 'Differences +4, +6, +8, +10 → 30.' },
      { id: 'bank-seed-4', category: 'General Aptitude', difficulty: 'Medium', marks: 1, prompt: 'If 3x + 5 = 20, what is x?', options: ['3', '4', '5', '6'], correctIndex: 2, explanation: '3x = 15 → x = 5.' },
      { id: 'bank-seed-5', category: 'General Aptitude', difficulty: 'Medium', marks: 1, prompt: 'Average of 10, 20, and 30 is?', options: ['15', '20', '25', '30'], correctIndex: 1, explanation: '(10+20+30)/3 = 20.' },
      { id: 'bank-seed-6', category: 'General Aptitude', difficulty: 'Hard', marks: 1, prompt: 'A shopkeeper marks goods 40% above cost and gives 10% discount. Profit %?', options: ['26%', '30%', '36%', '40%'], correctIndex: 0, explanation: 'SP = 1.4 × 0.9 = 1.26 → 26% profit.' },
      { id: 'bank-seed-7', category: 'Quantitative Aptitude', difficulty: 'Easy', marks: 1, prompt: 'What is 15% of 240?', options: ['24', '36', '30', '48'], correctIndex: 1, explanation: '0.15 × 240 = 36.' },
      { id: 'bank-seed-8', category: 'Logical Reasoning', difficulty: 'Medium', marks: 1, prompt: 'All cats are animals. Some animals are pets. Which is definitely true?', options: ['All pets are cats', 'Some cats may be pets', 'No cats are pets', 'All animals are cats'], correctIndex: 1, explanation: 'Overlap is possible; not guaranteed for all.' },
    ]);
  }

  function normalizeBankCategory(value) {
    const raw = String(value || '').trim();
    const categories = meta.categories || APTITUDE_CATEGORIES;
    for (const cat of categories) {
      if (cat.toLowerCase() === raw.toLowerCase()) return cat;
    }
    const map = {
      quantitative: 'Quantitative Aptitude',
      logical: 'Logical Reasoning',
      verbal: 'Verbal Ability',
      'data interpretation': 'Data Interpretation',
      numerical: 'Numerical Ability',
      general: 'General Aptitude',
      'general aptitude': 'General Aptitude',
    };
    return map[raw.toLowerCase()] || 'General Aptitude';
  }

  function bankQuestionMatchesRule(q, rule) {
    const qCat = normalizeBankCategory(q.category);
    const rCat = normalizeBankCategory(rule.category);
    if (rCat && qCat !== rCat) return false;
    const qDiff = normalizeDifficulty(q.difficulty);
    const rDiff = normalizeDifficulty(rule.difficulty);
    if (rDiff && qDiff !== rDiff) return false;
    return true;
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

  function isLiveAptitudeId(id) {
    return /^[a-f\d]{24}$/i.test(String(id || '').trim());
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
      normalized.forEach((q, i) => bank.push({ ...q, id: `bank-${bank.length + i + 1}`, marks: 1 }));
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
  let myProgress = { history: [] };
  let meta = { categories: APTITUDE_CATEGORIES, difficulties: APTITUDE_DIFFICULTIES };
  let testFormModal;
  let bulkModal;
  let studentAptModal;
  let contestResultsModal;
  let contestPreviewId = '';
  let exam;
  let mcqCounter = 0;
  let dirFilterBranch = '';
  let dirFilterBatch = '';
  let dirFiltersBound = false;
  let dirLoadTimer = null;
  let dirLoadSeq = 0;
  let dirFilterCacheKey = '';
  let dirFilterCache = null;
  let dirResultsCacheKey = '';
  let dirResultsCache = null;
  let deptStorePrimed = false;
  let progressPanel = 'tests';
  let myResultsPanel = 'tests';
  let takeListPanel = 'tests';
  let takeContestType = 'weekly';
  let myResultsContestType = 'weekly';
  let progressContestType = 'weekly';
  let manageContestType = 'weekly';
  let bankDifficultyFilter = '';
  let bankCategoryFilter = '';
  let bankQuestions = [];
  let manualBankAllQuestions = [];
  let manualBankRuleCounter = 0;
  let manualJdSetSummaries = [];
  let manualJdRuleCounter = 0;
  let randomJdRuleCounter = 0;
  let jdLibrarySets = [];
  let jdCompanyBlocks = [];
  let studentJdCompanyBlocks = [];
  let jdCompanies = [];
  let jdSelectedCompanyId = null;
  let studentJdSelectedCompanyId = null;
  let studentJdBlockView = 'tests';
  const jdSetDetailsCache = {};
  let aptAiModal;
  let aiPreviewQuestions = [];
  let aiLastFormParams = null;
  let aiGenerateContext = 'bank';
  let aiJdUploadMeta = null;
  let aiGenProgressTimer = null;
  let aptAiIncompleteModal;

  const AI_CATEGORY_DEFAULT_INSTRUCTIONS = 'Generate questions suitable for campus placement aptitude tests.';
  const AI_JD_DEFAULT_INSTRUCTIONS = 'Generate questions relevant to the uploaded job description and suitable for campus placement assessment. Focus on the technical skills, concepts, tools, and responsibilities mentioned in the JD.';
  const AI_JD_MAX_FILE_BYTES = 5 * 1024 * 1024;
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

    const studentSummaries = students.map((s) => {
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
    });

    if (resultType === 'contests') {
      const rows = studentSummaries.filter((r) => (r.testsAttempted || 0) > 0 && (r.userId === 'u-s2' || r.userId === 'u-s1')).map((r) => ({
        ...r,
        testsAttempted: Math.min(Number(r.testsAttempted) || 0, 1),
        averageScore: r.userId === 'u-s2' ? 68 : 74,
        bestScore: r.userId === 'u-s2' ? 72 : 74,
        accuracy: r.userId === 'u-s2' ? 65 : 74,
        recentScore: r.userId === 'u-s2' ? 72 : 74,
      }));
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

    const titles = [
      'Quantitative Aptitude — Basics',
      'Logical Reasoning mock',
      'Verbal Ability check',
    ];
    const rows = [];
    studentSummaries.forEach((s) => {
      const count = Number(s.testsAttempted) || 0;
      if (count === 0) return;
      const pct = Math.max(40, Math.min(100, Number(s.recentScore ?? s.averageScore) || 0));
      const totalMarks = 20;
      const marksObtained = Math.round((pct / 100) * totalMarks);
      rows.push({
        attemptId: `demo-attempt-${s.userId}-final`,
        userId: s.userId,
        name: s.name,
        registerNumber: s.registerNumber,
        classBatch: s.classBatch,
        attemptCount: count,
        testTitle: titles[0],
        marksObtained,
        totalMarks,
        score: marksObtained,
        percentage: pct,
        completedAt: new Date().toISOString(),
      });
    });

    const percentages = rows.map((r) => Number(r.percentage) || 0);
    const studentIds = new Set(rows.map((r) => r.userId));

    return {
      rows,
      summary: {
        attemptCount: rows.length,
        students: studentIds.size,
        avgPercentage: percentages.length
          ? Math.round((percentages.reduce((a, b) => a + b, 0) / percentages.length) * 10) / 10
          : 0,
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

  function contestResultStatusLabel(contest) {
    const status = contest?.resultStatus || (contest?.resultsPublished ? 'PUBLISHED' : 'PENDING');
    return status === 'PUBLISHED' ? 'Published' : 'Not published';
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
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt')));
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
      esc(contestScheduleLabel(c)),
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

  function contestTypeOf(entry) {
    const type = String(entry?.contestType || '').toLowerCase();
    if (type === 'weekly' || type === 'monthly') return type;
    const resolved = resolveHistoryTest(entry);
    const resolvedType = String(resolved?.contestType || '').toLowerCase();
    return resolvedType === 'monthly' ? 'monthly' : (resolvedType === 'weekly' ? 'weekly' : '');
  }

  function syncContestTypeNav(navId, type, attr) {
    document.querySelectorAll(`#${navId} .nav-link`).forEach((link) => {
      link.classList.toggle('active', link.getAttribute(attr) === type);
    });
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

  function formatAttemptScore(row) {
    const obtained = Number(row.marksObtained ?? row.score);
    const total = Number(row.totalMarks ?? row.maximumScore);
    const pct = Number(row.percentage);
    if (Number.isFinite(obtained) && Number.isFinite(total) && total > 0) {
      return Number.isFinite(pct) ? `${obtained}/${total} (${pct}%)` : `${obtained}/${total}`;
    }
    return Number.isFinite(pct) ? `${pct}%` : '—';
  }

  function renderDirectoryTable(rows, summary, scope = {}) {
    document.getElementById('dirTestResultsWrap')?.classList.remove('d-none');
    document.getElementById('dirContestResultsWrap')?.classList.add('d-none');
    document.getElementById('progressContestTypeNav')?.classList.add('d-none');
    document.getElementById('dirStats')?.classList.add('d-none');
    document.getElementById('dirStats').innerHTML = '';

    const role = Auth.role();
    const emptyMsg = role === 'staff' && (!staffAssignedBatches().length && !(scope.assignedClassBatches || []).length)
      ? 'No class is assigned to your account. Contact the placement office to monitor student aptitude progress.'
      : 'No test results in your authorized scope yet.';

    const canViewDetail = Auth.hasRealAuth() && !Auth.isDemo();
    document.getElementById('dirRows').innerHTML = rows.length ? rows.map((r) => {
      const attemptId = String(r.attemptId || r.id || '');
      const viewBtn = canViewDetail && attemptId
        ? `<button type="button" class="btn btn-sm btn-outline-primary" data-view-attempt="${esc(attemptId)}">View</button>`
        : `<button type="button" class="btn btn-sm btn-outline-secondary" data-detail="${esc(r.userId || '')}">Profile</button>`;
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
      btn.addEventListener('click', () => viewAttemptResult(btn.getAttribute('data-view-attempt')));
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
        'BCAH2022-25-S5',
        'BCAH2023-26-S3',
        'BCA2024-27-S1',
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
    fillDirSelect(document.getElementById('fBranch'), data.branches || [], 'All branches', dirFilterBranch);
    dirFilterBranch = document.getElementById('fBranch')?.value || '';
    fillDirSelect(document.getElementById('fBatch'), data.batches || [], 'All batches', dirFilterBatch);
    dirFilterBatch = document.getElementById('fBatch')?.value || '';
    fillDirTypeSelect(data.types || []);

    const batchEl = document.getElementById('fBatch');
    if (batchEl) batchEl.disabled = !(data.batches || []).length;
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

  function applyProgressPanel(panel) {
    progressPanel = panel === 'contests' ? 'contests' : 'tests';
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

  function applyMyResultsPanel(panel) {
    myResultsPanel = panel === 'contests' ? 'contests' : 'tests';
    document.querySelectorAll('#myResultsNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-results-view') === myResultsPanel);
    });
    document.getElementById('myResultsContestTypeNav')?.classList.toggle('d-none', myResultsPanel !== 'contests');
    syncContestTypeNav('myResultsContestTypeNav', myResultsContestType, 'data-results-contest-type');
  }

  function applyMyResultsContestType(type) {
    myResultsContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('myResultsContestTypeNav', myResultsContestType, 'data-results-contest-type');
    renderHistory(myProgress);
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

  function setupTakeListNav() {
    document.querySelector('#takeListNav [data-take-list="jdblock"]')
      ?.closest('.nav-item')
      ?.classList.toggle('d-none', !access.canTake);
  }

  function applyTakeContestType(type) {
    takeContestType = type === 'monthly' ? 'monthly' : 'weekly';
    syncContestTypeNav('takeContestTypeNav', takeContestType, 'data-take-contest-type');
    renderTestList();
  }

  function historyEntryIsContest(h) {
    const type = String(h?.contestType || '').toLowerCase();
    if (type === 'weekly' || type === 'monthly') return true;
    if (type === 'none') return false;
    return isContestTest(resolveHistoryTest(h));
  }

  function historyResultMode(h) {
    const row = h && typeof h === 'object' ? h : {};
    if (row.resultVisibility) return String(row.resultVisibility);
    if (access.canManage || access.canViewDirectory) return 'full';
    if (!historyEntryIsContest(row)) return 'full';
    const test = resolveHistoryTest(row);
    if (test && (test.resultStatus === 'PUBLISHED' || test.resultsPublished)) {
      return 'published';
    }
    if (test && Object.prototype.hasOwnProperty.call(test, 'resultsPublished')) {
      return test.resultsPublished ? 'published' : 'pending';
    }
    return row.resultsPublished ? 'published' : 'pending';
  }

  function applyContestResultView(test, result) {
    if (!isContestTest(test) || access.canManage || access.canViewDirectory) {
      return { ...result, resultVisibility: 'full', resultsPublished: true, resultStatus: 'PUBLISHED' };
    }
    if (test.resultsPublished || test.resultStatus === 'PUBLISHED') {
      return {
        ...result,
        resultVisibility: 'published',
        resultsPublished: true,
        resultStatus: 'PUBLISHED',
        resultPublishedAt: test.resultPublishedAt || result.resultPublishedAt || null,
        questionAnalysis: [],
        percentile: null,
      };
    }
    return {
      ...result,
      resultVisibility: 'pending',
      resultsPublished: false,
      score: null,
      marksObtained: null,
      percentage: null,
      accuracy: null,
      correctAnswers: null,
      incorrectAnswers: null,
      unansweredQuestions: null,
      rank: null,
      percentile: null,
      questionAnalysis: [],
      message: 'Your attempt is submitted. The score will appear after the admin publishes contest results.',
    };
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
    } else {
      ensureDemoBankSeed();
      const all = loadDemoBankStore();
      bankQuestions = all.filter((q) => {
        if (bankCategoryFilter && String(q.category || '') !== bankCategoryFilter) return false;
        if (bankDifficultyFilter && normalizeDifficulty(q.difficulty) !== bankDifficultyFilter) return false;
        return true;
      });
    }
    renderQuestionBank();
  }

  function visibleBankQuestions() {
    return bankQuestions.slice(0, 100);
  }

  function updateBankSelectionToolbar() {
    const count = selectedBankIds.size;
    const countEl = document.getElementById('bankSelectedCount');
    const deleteBtn = document.getElementById('btnBankDeleteSelected');
    const selectAll = document.getElementById('bankSelectAllVisible');
    if (countEl) countEl.textContent = `${count} selected`;
    deleteBtn?.classList.toggle('d-none', count === 0);

    const visible = visibleBankQuestions();
    const visibleIds = visible.map((q) => String(q.id || q.bankId || '')).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedBankIds.has(id));
    if (selectAll) {
      selectAll.indeterminate = count > 0 && !allVisibleSelected;
      selectAll.checked = allVisibleSelected;
    }
  }

  function bindBankListEvents() {
    const root = document.getElementById('bankQuestionsList');
    if (!root || root.dataset.bankListBound === '1') return;
    root.dataset.bankListBound = '1';

    root.addEventListener('change', (e) => {
      const pick = e.target.closest('[data-bank-select]');
      if (!pick) return;
      const id = pick.getAttribute('data-bank-select');
      if (!id) return;
      if (pick.checked) selectedBankIds.add(String(id));
      else selectedBankIds.delete(String(id));
      updateBankSelectionToolbar();
    });

    root.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-bank-delete]');
      if (!btn) return;
      e.preventDefault();
      deleteBankQuestion(btn.getAttribute('data-bank-delete'));
    });
  }

  function renderQuestionBank() {
    document.querySelectorAll('#bankDifficultyNav .nav-link').forEach((link) => {
      link.classList.toggle('active', (link.getAttribute('data-bank-difficulty') || '') === bankDifficultyFilter);
    });

    const root = document.getElementById('bankQuestionsList');
    const bulkBar = document.getElementById('bankBulkActions');
    if (!root) return;
    bindBankListEvents();

    if (!bankQuestions.length) {
      bulkBar?.classList.add('d-none');
      root.innerHTML = '<p class="text-muted-2 mb-0">No questions in this bucket yet. Use bulk upload to add Easy, Medium, and Hard MCQs.</p>';
      updateBankSelectionToolbar();
      return;
    }

    bulkBar?.classList.remove('d-none');
    const visible = visibleBankQuestions();
    root.innerHTML = visible.map((q) => {
      const id = String(q.id || q.bankId || '');
      const prompt = stripHtml(q.prompt) || 'Question';
      const checked = selectedBankIds.has(id);
      return `<div class="border rounded-3 p-3 apt-q-card">
        <div class="d-flex align-items-start gap-2">
          <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-bank-select="${esc(id)}" ${checked ? 'checked' : ''} aria-label="Select question"/>
          <div class="min-w-0 flex-grow-1">
            <div class="fw-medium apt-q-card-text">${esc(prompt)}</div>
            <div class="small text-muted-2 mt-1">${esc(q.category || 'General Aptitude')} · ${esc(q.difficulty || '')}${q.source ? ` · ${esc(q.source)}` : ''} · ${esc(q.options?.length || 0)} options</div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            ${bankDifficultyBadge(q.difficulty)}
            <button type="button" class="btn btn-sm btn-outline-danger" data-bank-delete="${esc(id)}" title="Delete"><i class="bi bi-trash"></i></button>
          </div>
        </div>
      </div>`;
    }).join('') + (bankQuestions.length > 100
      ? `<p class="small text-muted-2 mb-0">Showing first 100 of ${bankQuestions.length} questions.</p>`
      : '');

    updateBankSelectionToolbar();
  }

  async function deleteSelectedBankQuestions() {
    const ids = [...selectedBankIds];
    if (!ids.length) {
      toast('Select at least one question to delete.', 'error');
      return;
    }
    if (!confirm(`Delete ${ids.length} selected question(s) from the bank?`)) return;

    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      if (!Auth.isDemo() || !access.canManage) {
        toast('Delete requires a live session with manage access.', 'info');
        return;
      }
      const drop = new Set(ids.map(String));
      saveDemoBankStore(loadDemoBankStore().filter((q) => !drop.has(String(q.id || q.bankId))));
      ids.forEach((id) => selectedBankIds.delete(String(id)));
      toast(`Deleted ${ids.length} question(s) (demo).`, 'success');
      await loadQuestionBank();
      return;
    }

    const res = await api('/aptitude/question-bank/bulk-delete', {
      method: 'POST',
      body: JSON.stringify({ ids }),
    }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not delete selected questions.', 'error');
      return;
    }
    const deleted = Number(res.data?.deleted || ids.length);
    ids.forEach((id) => selectedBankIds.delete(String(id)));
    toast(`Deleted ${deleted} question(s).`, 'success');
    await loadQuestionBank();
  }

  function loadDemoJdStore() {
    try {
      const raw = localStorage.getItem(DEMO_JD_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  }

  function saveDemoJdStore(sets) {
    localStorage.setItem(DEMO_JD_KEY, JSON.stringify(sets || []));
    Object.keys(jdSetDetailsCache).forEach((k) => delete jdSetDetailsCache[k]);
    manualJdSetSummaries = [];
  }

  function groupJdSetsIntoBlocks(sets) {
    const blocks = new Map();
    (sets || []).forEach((set) => {
      const companyId = String(set.companyId || '');
      const companyName = String(set.companyName || (companyId ? 'Company' : 'Unassigned'));
      const key = companyId || '_unassigned';
      if (!blocks.has(key)) {
        blocks.set(key, {
          companyId,
          companyName,
          setCount: 0,
          questionCount: 0,
          sets: [],
        });
      }
      const block = blocks.get(key);
      block.setCount += 1;
      block.questionCount += Number(set.questionCount || 0);
      block.sets.push(set);
    });
    return [...blocks.values()].sort((a, b) => String(a.companyName).localeCompare(String(b.companyName)));
  }

  async function ensureJdCompaniesLoaded() {
    if (jdCompanies.length) return jdCompanies;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/jd-companies').catch(() => null);
      jdCompanies = res?.data?.companies || [];
    } else {
      jdCompanies = DEMO_JD_COMPANIES.slice();
    }
    return jdCompanies;
  }

  function fillAptAiJdCompanySelect(selectedId = '') {
    const sel = document.getElementById('aptAiJdCompany');
    if (!sel) return;
    const pick = String(selectedId || '');
    sel.innerHTML = `<option value="">Select company…</option>${jdCompanies.map((c) => {
      const id = String(c.id || '');
      return `<option value="${esc(id)}"${id === pick ? ' selected' : ''}>${esc(c.name || 'Company')}</option>`;
    }).join('')}`;
  }

  function selectedJdCompanyFromForm() {
    const sel = document.getElementById('aptAiJdCompany');
    const companyId = String(sel?.value || '').trim();
    const companyName = String(sel?.selectedOptions?.[0]?.textContent || '').trim();
    return { companyId, companyName: companyName === 'Select company…' ? '' : companyName };
  }

  async function ensureJdSetSummariesLoaded() {
    if (manualJdSetSummaries.length) return;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/jd-sets').catch(() => null);
      manualJdSetSummaries = res?.data?.sets || [];
    } else {
      manualJdSetSummaries = loadDemoJdStore().map((s) => ({
        id: String(s.id || ''),
        companyId: String(s.companyId || ''),
        companyName: String(s.companyName || ''),
        jdTitle: String(s.jdTitle || ''),
        jdFilename: String(s.jdFilename || ''),
        jdFileUrl: String(s.jdFileUrl || s.jdFileDataUrl || ''),
        jdMimeType: String(s.jdMimeType || ''),
        hasDocument: !!(s.jdFileUrl || s.jdFileDataUrl),
        questionCount: Number(s.questionCount || s.questions?.length || 0),
      }));
    }
  }

  async function getJdSetDetail(setId) {
    const id = String(setId || '');
    if (!id) return null;
    if (jdSetDetailsCache[id]) return jdSetDetailsCache[id];
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/aptitude/jd-sets/${encodeURIComponent(id)}`).catch(() => null);
      if (res?.data) jdSetDetailsCache[id] = res.data;
    } else {
      const set = loadDemoJdStore().find((s) => String(s.id) === id);
      if (set) jdSetDetailsCache[id] = set;
    }
    return jdSetDetailsCache[id] || null;
  }

  async function getStudentJdSetDetail(setId) {
    const id = String(setId || '');
    if (!id) return null;
    const cacheKey = `student:${id}`;
    if (jdSetDetailsCache[cacheKey]) return jdSetDetailsCache[cacheKey];
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api(`/aptitude/student/jd-sets/${encodeURIComponent(id)}`).catch(() => null);
      if (res?.data) jdSetDetailsCache[cacheKey] = res.data;
    } else {
      const detail = await getJdSetDetail(id);
      if (detail) jdSetDetailsCache[cacheKey] = detail;
    }
    return jdSetDetailsCache[cacheKey] || null;
  }

  function mapDemoJdSetSummary(s) {
    return {
      id: String(s.id || ''),
      companyId: String(s.companyId || ''),
      companyName: String(s.companyName || ''),
      jdTitle: String(s.jdTitle || ''),
      jdFilename: String(s.jdFilename || ''),
      jdFileUrl: String(s.jdFileUrl || s.jdFileDataUrl || ''),
      jdMimeType: String(s.jdMimeType || ''),
      hasDocument: !!(s.jdFileUrl || s.jdFileDataUrl),
      questionCount: Number(s.questionCount || s.questions?.length || 0),
    };
  }

  async function loadStudentJdBlock() {
    if (!access.canTake) return;
    await loadTests().catch(() => {});
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/student/jd-block').catch(() => null);
      studentJdCompanyBlocks = res?.data?.blocks || [];
    } else {
      const sets = loadDemoJdStore()
        .filter((s) => String(s.companyId || '').trim() !== '')
        .map(mapDemoJdSetSummary);
      studentJdCompanyBlocks = groupJdSetsIntoBlocks(sets);
    }
    syncStudentJdBlockViewNav();
    renderStudentJdBlock();
  }

  async function loadJdLibrary() {
    if (!access.canManage) return;
    await ensureJdCompaniesLoaded().catch(() => {});
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/jd-sets').catch(() => null);
      jdLibrarySets = res?.data?.sets || [];
      jdCompanyBlocks = res?.data?.blocks || groupJdSetsIntoBlocks(jdLibrarySets);
    } else {
      jdLibrarySets = loadDemoJdStore().map((s) => ({
        id: String(s.id || ''),
        companyId: String(s.companyId || ''),
        companyName: String(s.companyName || ''),
        jdTitle: String(s.jdTitle || ''),
        jdFilename: String(s.jdFilename || ''),
        jdFileUrl: String(s.jdFileUrl || s.jdFileDataUrl || ''),
        jdMimeType: String(s.jdMimeType || ''),
        hasDocument: !!(s.jdFileUrl || s.jdFileDataUrl),
        questionCount: Number(s.questionCount || s.questions?.length || 0),
      }));
      jdCompanyBlocks = groupJdSetsIntoBlocks(jdLibrarySets);
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

  function renderJdQuestionDetailHtml(q, index) {
    const letters = ['A', 'B', 'C', 'D'];
    const opts = (q.options || []).slice(0, 4);
    const correct = Math.max(0, Math.min(3, Number(q.correctIndex ?? 0)));
    const promptRaw = String(q.prompt || '').trim();
    const promptBlock = /<[^>]+>/.test(promptRaw)
      ? `<div class="mb-2 apt-q-card-text apt-rich">${promptRaw}</div>`
      : `<div class="mb-2 apt-q-card-text">${esc(stripHtml(promptRaw) || 'Question')}</div>`;
    const meta = [q.topic, q.difficulty].filter(Boolean).map((v) => esc(String(v))).join(' · ');
    const explanation = String(q.explanation || '').trim();
    return `<div class="border rounded-2 p-3 bg-white">
      <div class="fw-semibold mb-2">Q${index + 1}${meta ? `<span class="text-muted-2 fw-normal"> · ${meta}</span>` : ''}</div>
      ${promptBlock}
      <div class="small mb-2">${opts.length
        ? opts.map((o, oi) => {
          const label = esc(stripHtml(String(o || '')) || String(o || ''));
          const isCorrect = oi === correct;
          return `<div class="apt-q-card-text ${isCorrect ? 'text-success fw-semibold' : ''}">${letters[oi]}. ${label}${isCorrect ? ' ✓' : ''}</div>`;
        }).join('')
        : '<div class="text-muted-2">No options</div>'}</div>
      <div class="small mb-1"><span class="fw-semibold">Answer:</span> ${letters[correct]}. ${esc(stripHtml(String(opts[correct] || '')) || opts[correct] || '—')}</div>
      ${explanation ? `<div class="small mt-2"><span class="fw-semibold">Explanation:</span> ${esc(explanation)}</div>` : ''}
    </div>`;
  }

  function renderJdDocumentPanel(detail) {
    const url = jdDocumentUrl(detail);
    if (!url) {
      return '<p class="small text-muted-2 mb-0">No uploaded document for this set.</p>';
    }
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

  function renderJdSetCardsHtml(sets, { allowDelete = true } = {}) {
    return (sets || []).map((set) => {
      const id = String(set.id || '');
      const hasDoc = !!(set.hasDocument || set.jdFileUrl);
      return `<div class="border rounded-3 p-3" data-jd-set-card="${esc(id)}">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div class="min-w-0">
            <div class="fw-semibold">${esc(set.jdTitle || 'Untitled JD')}</div>
            <div class="small text-muted-2 mt-1">${esc(set.questionCount || 0)} question(s)${set.jdFilename ? ` · ${esc(set.jdFilename)}` : ''}</div>
          </div>
          <div class="d-flex gap-2 flex-shrink-0">
            ${hasDoc ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-jd-doc="${esc(id)}">Document</button>` : ''}
            <button type="button" class="btn btn-sm btn-outline-primary" data-jd-view="${esc(id)}">Questions</button>
            ${allowDelete ? `<button type="button" class="btn btn-sm btn-outline-danger" data-jd-delete="${esc(id)}" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
          </div>
        </div>
        <div class="d-none mt-3" data-jd-doc-panel="${esc(id)}"></div>
        <div class="d-none mt-3" data-jd-questions="${esc(id)}"></div>
      </div>`;
    }).join('');
  }

  function bindJdSetCardEvents(root, { getDetail = getJdSetDetail, allowDelete = true } = {}) {
    if (!root) return;
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
          btn.textContent = 'Questions';
          return;
        }
        const detail = await getDetail(id);
        const qs = detail?.questions || [];
        panel.innerHTML = qs.length
          ? `<div class="d-flex flex-column gap-3">${qs.map((q, i) => renderJdQuestionDetailHtml(q, i)).join('')}</div>`
          : '<p class="small text-muted-2 mb-0">No questions in this set.</p>';
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

  function showJdCompanyDetail(companyId) {
    const block = jdCompanyBlocks.find((b) => {
      const id = String(b.companyId || '');
      return (id || '_unassigned') === String(companyId || '_unassigned');
    });
    jdSelectedCompanyId = companyId;
    document.getElementById('jdBlockCompanyView')?.classList.add('d-none');
    document.getElementById('jdBlockCompanyDetail')?.classList.remove('d-none');
    const titleEl = document.getElementById('jdBlockDetailTitle');
    if (titleEl) titleEl.textContent = block?.companyName || 'Company';
    const list = document.getElementById('jdBlockSetsList');
    if (!list) return;
    const sets = block?.sets || [];
    list.innerHTML = sets.length
      ? renderJdSetCardsHtml(sets)
      : '<p class="text-muted-2 mb-0">No JD titles for this company yet.</p>';
    bindJdSetCardEvents(list);
  }

  function showJdCompanyGrid() {
    jdSelectedCompanyId = null;
    document.getElementById('jdBlockCompanyDetail')?.classList.add('d-none');
    document.getElementById('jdBlockCompanyView')?.classList.remove('d-none');
  }

  function renderJdCompanyGridHtml(blocks, { forStudent = false } = {}) {
    return (blocks || []).map((block) => {
      const companyId = String(block.companyId || '_unassigned');
      const meta = `${esc(block.setCount || 0)} JD title(s) · ${esc(block.questionCount || 0)} question(s)`;
      return `<div class="col-12 col-md-6 col-xl-4">
        <button type="button" class="card h-100 w-100 text-start border rounded-3 p-3 jd-company-card" data-jd-company-id="${esc(companyId)}">
          <div class="d-flex align-items-start justify-content-between gap-2">
            <div class="min-w-0">
              <div class="fw-semibold">${esc(block.companyName || 'Company')}</div>
              ${forStudent ? '' : `<div class="small text-muted-2 mt-2">${meta}</div>`}
            </div>
            <i class="bi bi-chevron-right text-muted-2 flex-shrink-0"></i>
          </div>
        </button>
      </div>`;
    }).join('');
  }

  function renderJdBlock() {
    const grid = document.getElementById('jdBlockCompanyGrid');
    if (!grid) return;
    const activeCompanyId = jdSelectedCompanyId;
    if (!jdCompanyBlocks.length) {
      showJdCompanyGrid();
      grid.innerHTML = '<div class="col-12"><p class="text-muted-2 mb-0">No JD Block entries yet. Use AI Generate from the JD Block tab to create one.</p></div>';
      return;
    }
    grid.innerHTML = renderJdCompanyGridHtml(jdCompanyBlocks);
    grid.querySelectorAll('[data-jd-company-id]').forEach((btn) => {
      btn.addEventListener('click', () => showJdCompanyDetail(btn.getAttribute('data-jd-company-id')));
    });
    if (activeCompanyId) {
      showJdCompanyDetail(activeCompanyId);
    } else {
      showJdCompanyGrid();
    }
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

  function renderStudentCompanyTestsHtml(companyTests) {
    if (!companyTests.length) {
      return '<p class="small text-muted-2 mb-0">No company tests published for this company yet.</p>';
    }
    return `<div class="d-flex flex-column gap-2">${companyTests.map((t) => {
      const mine = bestHistoryForTest(t.id);
      const canOpen = access.canTake && (t.status || 'published') === 'published';
      return `<div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="min-w-0">
          <div class="fw-semibold">${esc(t.title)}</div>
          <div class="small text-muted-2">${esc(testMetaLine(t))}${mine ? ` · Best ${esc(mine.percentage)}%` : ''}</div>
        </div>
        ${canOpen
          ? `<button type="button" class="btn btn-sm btn-primary flex-shrink-0" data-open-company-test="${esc(t.id)}">${mine ? 'Retake' : 'Take test'}</button>`
          : ''}
      </div>`;
    }).join('')}</div>`;
  }

  function bindStudentCompanyTestEvents(root) {
    if (!root) return;
    root.querySelectorAll('[data-open-company-test]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = tests.find((x) => String(x.id) === String(btn.getAttribute('data-open-company-test')));
        if (t) openExam(t);
      });
    });
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
    document.getElementById('btnStudentJdBlockBack')?.classList.remove('d-none');
    const showTests = studentJdBlockView === 'tests';
    const testsRoot = document.getElementById('studentJdBlockCompanyTests');
    const bankSection = document.getElementById('studentJdBlockBankSection');
    testsRoot?.classList.toggle('d-none', !showTests);
    bankSection?.classList.toggle('d-none', showTests);
    if (showTests) {
      const companyTests = studentCompanyTestsFor(companyId);
      if (testsRoot) {
        testsRoot.innerHTML = renderStudentCompanyTestsHtml(companyTests);
        bindStudentCompanyTestEvents(testsRoot);
      }
    } else {
      const list = document.getElementById('studentJdBlockSetsList');
      const sets = block?.sets || [];
      if (!list) return;
      list.innerHTML = sets.length
        ? renderJdSetCardsHtml(sets, { allowDelete: false })
        : '<p class="text-muted-2 mb-0">No JD question sets for this company yet.</p>';
      bindJdSetCardEvents(list, { getDetail: getStudentJdSetDetail, allowDelete: false });
    }
  }

  function showStudentJdCompanyGrid() {
    studentJdSelectedCompanyId = null;
    document.getElementById('studentJdBlockCompanyDetail')?.classList.add('d-none');
    document.getElementById('studentJdBlockCompanyView')?.classList.remove('d-none');
    document.getElementById('btnStudentJdBlockBack')?.classList.add('d-none');
  }

  function renderStudentJdBlock() {
    const grid = document.getElementById('studentJdBlockCompanyGrid');
    if (!grid) return;
    const activeCompanyId = studentJdSelectedCompanyId;
    if (!studentJdCompanyBlocks.length) {
      showStudentJdCompanyGrid();
      grid.innerHTML = '<div class="col-12"><p class="text-muted-2 mb-0">No JD Block entries are available yet. Check back later.</p></div>';
      return;
    }
    grid.innerHTML = renderJdCompanyGridHtml(studentJdCompanyBlocks, { forStudent: true });
    grid.querySelectorAll('[data-jd-company-id]').forEach((btn) => {
      btn.addEventListener('click', () => showStudentJdCompanyDetail(btn.getAttribute('data-jd-company-id')));
    });
    if (activeCompanyId) {
      showStudentJdCompanyDetail(activeCompanyId);
    } else {
      showStudentJdCompanyGrid();
    }
  }

  async function deleteJdSet(id) {
    if (!id) return;
    if (!confirm('Delete this JD question set and all its questions?')) return;
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      saveDemoJdStore(loadDemoJdStore().filter((s) => String(s.id) !== String(id)));
      toast('JD set deleted (demo).', 'success');
      await loadJdLibrary();
      return;
    }
    const res = await api(`/aptitude/jd-sets/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not delete JD set.', 'error');
      return;
    }
    delete jdSetDetailsCache[String(id)];
    manualJdSetSummaries = manualJdSetSummaries.filter((s) => String(s.id) !== String(id));
    toast('JD set deleted.', 'success');
    await loadJdLibrary();
  }

  async function deleteBankQuestion(id) {
    if (!id) return;
    if (!confirm('Delete this question from the bank?')) return;
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      if (!Auth.isDemo() || !access.canManage) {
        toast('Delete requires a live session with manage access.', 'info');
        return;
      }
      saveDemoBankStore(loadDemoBankStore().filter((q) => String(q.id || q.bankId) !== String(id)));
      selectedBankIds.delete(String(id));
      toast('Question deleted (demo).', 'success');
      await loadQuestionBank();
      return;
    }
    const res = await api(`/aptitude/question-bank/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not delete question.', 'error');
      return;
    }
    selectedBankIds.delete(String(id));
    toast('Question deleted.', 'success');
    await loadQuestionBank();
  }

  async function deleteTest(id) {
    if (!id) return;
    if (!confirm('Delete this test? This cannot be undone.')) return;
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      if (!Auth.isDemo() || !access.canManage) {
        toast('Delete requires a live session with manage access.', 'info');
        return;
      }
      saveDemoTestsStore(loadDemoTestsStore().filter((t) => String(t.id) !== String(id)));
      toast('Test deleted (demo).', 'success');
      await loadTests();
      renderTestList();
      renderManage();
      return;
    }
    if (!isLiveAptitudeId(id)) {
      toast('This test is not on the server. Refresh the page and try again.', 'error');
      return;
    }
    const res = await api(`/aptitude/tests/${encodeURIComponent(id)}`, { method: 'DELETE' }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not delete test.', 'error');
      return;
    }
    toast('Test deleted.', 'success');
    await loadTests();
    renderTestList();
    renderManage();
  }

  async function setContestResultsPublished(id, published) {
    if (!id) return;
    const msg = published
      ? 'Are you sure you want to publish this result? Students will be able to view their results after publication.'
      : 'Hide contest results from students again?';
    if (!confirm(msg)) return;
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      if (!Auth.isDemo() || !access.canManage) {
        toast('Publishing results requires a live session with manage access.', 'info');
        return;
      }
      const store = loadDemoTestsStore();
      const idx = store.findIndex((t) => String(t.id) === String(id));
      if (idx < 0) {
        toast('Contest not found.', 'error');
        return;
      }
      store[idx] = {
        ...store[idx],
        resultsPublished: !!published,
        resultStatus: published ? 'PUBLISHED' : 'PENDING',
        resultPublishedAt: published ? new Date().toISOString() : null,
      };
      saveDemoTestsStore(store);
      toast(published ? 'Contest results published (demo).' : 'Contest results hidden (demo).', 'success');
      await loadTests();
      renderTestList();
      renderManage();
      if (access.canTake) loadMyProgress();
      return;
    }
    if (!isLiveAptitudeId(id)) {
      toast('This contest is not on the server. Refresh the page and try again.', 'error');
      return;
    }
    const res = await api(`/aptitude/tests/${encodeURIComponent(id)}/publish-results`, {
      method: 'POST',
      body: JSON.stringify({ published: !!published }),
    }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not update contest results.', 'error');
      return;
    }
    toast(res.message || (published ? 'Contest results published.' : 'Contest results hidden.'), 'success');
    await loadTests();
    renderTestList();
    renderManage();
    if (contestPreviewId && String(contestPreviewId) === String(id)) {
      openContestResultsPreview(id).catch(() => {});
    }
    if (access.canViewDirectory && progressPanel === 'contests') {
      loadDirectory().catch(() => {});
    }
    if (access.canTake) loadMyProgress();
  }

  function formatContestDateTime(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(undefined, {
      day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
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

  function nextWeeklyOccurrenceStart(want, now = new Date()) {
    const today = now.getDay() === 0 ? 7 : now.getDay();
    let daysUntil = (want - today + 7) % 7;
    if (daysUntil === 0) {
      const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
      if (now > end) daysUntil = 7;
    }
    const d = new Date(now);
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + daysUntil);
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

  function nextMonthlyOccurrenceStart(want, now = new Date()) {
    const dom = now.getDate();
    let year = now.getFullYear();
    let month = now.getMonth();
    if (dom > want) {
      month += 1;
      if (month > 11) {
        month = 0;
        year += 1;
      }
    }
    return new Date(year, month, want, 0, 0, 0);
  }

  function contestOccurrenceWindowFromStart(start) {
    const end = new Date(start);
    end.setHours(23, 59, 59, 999);
    return { start: start.toISOString(), end: end.toISOString() };
  }

  function contestStatusClient(test) {
    if (!isContestTest(test)) {
      return test?.contestStatus ? String(test.contestStatus) : 'ACTIVE';
    }
    const type = String(test?.contestType || 'none');
    const now = new Date();
    const created = parseContestCreatedAt(test);
    if (type === 'weekly') {
      const want = Number(test?.contestWeekday);
      if (!Number.isFinite(want) || want < 1 || want > 7) return 'UPCOMING';
      const today = now.getDay() === 0 ? 7 : now.getDay();
      if (today === want) {
        const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
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
        const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
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

  function contestWindowClient(test) {
    if (test?.contestWindow?.start || test?.contestWindow?.end) return test.contestWindow;
    const now = new Date();
    const type = String(test?.contestType || 'none');
    const status = contestStatusClient(test);
    if (type === 'weekly') {
      const want = Number(test?.contestWeekday);
      if (!Number.isFinite(want)) return { start: null, end: null };
      const start = status === 'UPCOMING'
        ? nextWeeklyOccurrenceStart(want, now)
        : lastWeeklyOccurrenceStart(want, now);
      return contestOccurrenceWindowFromStart(start);
    }
    if (type === 'monthly') {
      const want = Number(test?.contestMonthDay);
      if (!Number.isFinite(want)) return { start: null, end: null };
      const start = status === 'UPCOMING'
        ? nextMonthlyOccurrenceStart(want, now)
        : lastMonthlyOccurrenceStart(want, now);
      return contestOccurrenceWindowFromStart(start);
    }
    return { start: null, end: null };
  }

  function demoContestPreview(id) {
    const test = tests.find((t) => String(t.id) === String(id)) || fullDemoTest(id);
    if (!test) return null;
    const hist = (myProgress.history || []).filter((h) => String(h.testId || '') === String(id));
    const participants = hist.map((h, i) => ({
      rank: i + 1,
      name: Auth.user()?.name || 'Student',
      registerNumber: Auth.user()?.registerNumber || Auth.user()?.studentCode || '—',
      studentCode: Auth.user()?.studentCode || '—',
      correctCount: h.correctCount ?? 0,
      wrongCount: h.wrongCount ?? 0,
      marksObtained: h.marksObtained ?? h.score ?? 0,
      totalMarks: h.totalMarks ?? test.totalMarks ?? 0,
      percentage: h.percentage ?? 0,
      timeTakenLabel: h.timeTakenLabel || '—',
      timeTakenSeconds: h.timeTakenSeconds ?? 0,
    }));
    const window = contestWindowClient(test);
    return {
      contest: {
        ...test,
        participantCount: participants.length,
        contestStartAt: window.start,
        contestEndAt: window.end,
        resultStatus: test.resultsPublished ? 'PUBLISHED' : 'PENDING',
        resultPublishedAt: test.resultPublishedAt || null,
      },
      participants,
      summary: {
        participantCount: participants.length,
        resultStatus: test.resultsPublished ? 'PUBLISHED' : 'PENDING',
        resultPublishedAt: test.resultPublishedAt || null,
      },
    };
  }

  function renderContestPreviewTable(participants) {
    if (!participants.length) {
      return '<p class="text-muted-2 mb-0">No participants submitted this contest yet.</p>';
    }
    const rows = participants.map((p) => `<tr>
      <td>${esc(p.rank ?? '—')}</td>
      <td class="fw-semibold">${esc(p.name || '—')}</td>
      <td>${esc(p.registerNumber || p.studentCode || '—')}</td>
      <td>${esc(p.correctCount ?? '—')}</td>
      <td>${esc(p.wrongCount ?? '—')}</td>
      <td>${esc(p.marksObtained ?? p.score ?? '—')} / ${esc(p.totalMarks ?? '—')}</td>
      <td>${esc(p.percentage ?? '—')}%</td>
      <td>${esc(p.timeTakenLabel || '—')}</td>
    </tr>`).join('');
    return `<div class="table-wrap"><table class="table-modern table-sm mb-0"><thead><tr>
      <th>Rank</th><th>Student</th><th>Register No.</th><th>Correct</th><th>Wrong</th><th>Score</th><th>Percentage</th><th>Time Taken</th>
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
      const res = await api(`/aptitude/tests/${encodeURIComponent(id)}/contest-results`).catch(() => null);
      data = res?.success ? res.data : null;
    } else {
      data = demoContestPreview(id);
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

  async function saveContestSchedule(id, field, value) {
    if (!id || (field !== 'contestWeekday' && field !== 'contestMonthDay')) return;
    const n = Number(value);
    if (!Number.isFinite(n)) return;
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    if (!live) {
      if (!Auth.isDemo() || !access.canManage) {
        toast('Updating the contest day requires a live session with manage access.', 'info');
        return;
      }
      const store = loadDemoTestsStore();
      const idx = store.findIndex((t) => String(t.id) === String(id));
      if (idx < 0) {
        toast('Contest not found.', 'error');
        return;
      }
      store[idx] = { ...store[idx], [field]: n };
      saveDemoTestsStore(store);
      toast('Contest day updated (demo).', 'success');
      await loadTests();
      renderTestList();
      renderManage();
      return;
    }
    if (!isLiveAptitudeId(id)) {
      toast('This contest is not on the server. Refresh the page and try again.', 'error');
      return;
    }
    const res = await api(`/aptitude/tests/${encodeURIComponent(id)}/schedule`, {
      method: 'POST',
      body: JSON.stringify({ [field]: n }),
    }).catch(() => null);
    if (!res?.success) {
      toast(res?.message || 'Could not update contest schedule.', 'error');
      return;
    }
    toast(res.message || 'Contest day updated.', 'success');
    await loadTests();
    renderTestList();
    renderManage();
  }

  function getQuestionSource() {
    if (formIsContest()) return 'random';
    if (formIsCompanyTest() && document.getElementById('tfSourceRandom')?.checked) return 'random_jd';
    if (document.getElementById('tfSourceRandom')?.checked) return 'random';
    return 'manual';
  }

  function formIsContest() {
    const type = String(document.getElementById('tfContestType')?.value || 'none');
    return type === 'weekly' || type === 'monthly';
  }

  function formIsCompanyTest() {
    return String(document.getElementById('tfTestKind')?.value || '') === 'company';
  }

  function selectedCompanyFromTestForm() {
    const sel = document.getElementById('tfCompanyId');
    const companyId = String(sel?.value || '').trim();
    const companyName = String(sel?.selectedOptions?.[0]?.textContent || '').trim();
    return {
      companyId,
      companyName: companyName === 'Select company…' ? '' : companyName,
    };
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

  function jdSetsForTestForm() {
    const companyId = formIsCompanyTest() ? selectedCompanyFromTestForm().companyId : '';
    const sets = manualJdSetSummaries || [];
    if (!companyId) return sets;
    return sets.filter((s) => String(s.companyId || '') === companyId);
  }

  function syncCompanyTestFormUi() {
    const company = formIsCompanyTest();
    document.getElementById('tfCompanyPanel')?.classList.toggle('d-none', !company);
    document.getElementById('tfCompanyJdHint')?.classList.toggle('d-none', !company || getQuestionSource() !== 'manual');
    document.getElementById('tfUseJdManualWrap')?.classList.toggle('d-none', company);
    document.getElementById('tfUseBankManual')?.closest('.form-check')?.classList.toggle('d-none', company);
    document.getElementById('tfManualMcqSection')?.classList.toggle('d-none', company);
    const jdTitle = document.getElementById('tfJdPickerTitle');
    if (jdTitle) {
      jdTitle.textContent = company ? 'Pick JD-generated questions manually' : 'Pick questions by JD title';
    }
    const randomLabel = document.querySelector('label[for="tfSourceRandom"]');
    if (randomLabel) {
      randomLabel.textContent = company ? 'Random from JD' : 'Random from bank';
    }
    if (company && getQuestionSource() === 'manual') {
      document.getElementById('tfUseJdManual').checked = true;
      document.getElementById('tfJdPicker')?.classList.remove('d-none');
    }
  }

  function syncQuestionSourcePanels() {
    const contest = formIsContest();
    document.getElementById('tfSourceGroup')?.classList.toggle('d-none', contest);
    document.getElementById('tfContestBankHint')?.classList.toggle('d-none', !contest);
    syncCompanyTestFormUi();
    if (contest) {
      document.getElementById('tfSourceRandom').checked = true;
      document.getElementById('tfSourceManual').checked = false;
    }
    const source = getQuestionSource();
    const randomBank = source === 'random';
    const randomJd = source === 'random_jd';
    document.getElementById('tfRandomPanel')?.classList.toggle('d-none', !randomBank);
    document.getElementById('tfRandomJdPanel')?.classList.toggle('d-none', !randomJd);
    document.getElementById('tfManualPanel')?.classList.toggle('d-none', randomBank || randomJd);
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl) {
      countEl.readOnly = randomBank || randomJd;
      updateTestFormQuestionCount();
    }
  }

  function fillAiTopicDatalist(category) {
    const list = document.getElementById('aptAiTopicList');
    if (!list) return;
    const topics = (meta.aiTopicsByCategory || {})[category] || [];
    list.innerHTML = topics.map((t) => `<option value="${esc(t)}"></option>`).join('');
  }

  const AI_GEN_MAX_TOTAL = 200;

  const AI_GEN_ROW_DEFAULTS = [
    { difficulty: 'Easy', count: 5 },
    { difficulty: 'Medium', count: 5 },
  ];

  function getAiSourceMode() {
    return aiGenerateContext === 'jd' ? 'jd' : 'category';
  }

  function updateAiGenTotal() {
    const el = document.getElementById('aptAiGenTotal');
    if (!el) return;
    const maxTotal = meta.aiQuestionCountMax || AI_GEN_MAX_TOTAL;
    const total = collectAiGenRows().reduce((sum, row) => sum + row.count, 0);
    if (total <= 0) {
      el.className = 'small text-muted-2 mt-2';
      el.textContent = '';
      return;
    }
    const over = total > maxTotal;
    el.className = over ? 'small text-danger mt-2 fw-semibold' : 'small text-muted-2 mt-2';
    el.textContent = over
      ? `${total} questions — total cannot exceed ${maxTotal}. Reduce counts across rows.`
      : `${total} question${total === 1 ? '' : 's'} (max ${maxTotal} total across all rows)`;
  }

  function updateAptAiModalTitle() {
    const el = document.getElementById('aptAiModalTitle');
    const preview = document.getElementById('aptAiPreviewPanel');
    if (!el || !preview?.classList.contains('d-none')) return;
    el.textContent = getAiSourceMode() === 'jd' ? 'AI Generate — JD Block' : 'AI Generate — question bank';
  }

  function updateAiSourceModeUI() {
    const jd = getAiSourceMode() === 'jd';
    document.getElementById('aptAiSourceModePanel')?.classList.add('d-none');
    document.getElementById('aptAiCategoryPanel')?.classList.toggle('d-none', jd);
    document.getElementById('aptAiJdPanel')?.classList.toggle('d-none', !jd);
    document.getElementById('aptAiModeCategory').checked = !jd;
    document.getElementById('aptAiModeJd').checked = jd;
    updateAptAiModalTitle();
    const hint = document.getElementById('aptAiStatusHint');
    if (hint) {
      hint.textContent = jd
        ? 'Generate JD-based MCQs with OpenAI. Review and edit before saving to the JD Block — nothing is published automatically.'
        : 'Generate aptitude MCQs with OpenAI. Review and edit before saving to the question bank — nothing is published automatically.';
    }
    const instr = document.getElementById('aptAiInstructions');
    if (!instr) return;
    if (jd) {
      if (!instr.dataset.userEdited || instr.value === AI_CATEGORY_DEFAULT_INSTRUCTIONS) {
        instr.value = AI_JD_DEFAULT_INSTRUCTIONS;
        delete instr.dataset.userEdited;
      }
      instr.placeholder = AI_JD_DEFAULT_INSTRUCTIONS;
    } else {
      if (!instr.dataset.userEdited || instr.value === AI_JD_DEFAULT_INSTRUCTIONS) {
        instr.value = AI_CATEGORY_DEFAULT_INSTRUCTIONS;
        delete instr.dataset.userEdited;
      }
      instr.placeholder = AI_CATEGORY_DEFAULT_INSTRUCTIONS;
    }
  }

  function showAptAiJdFileUi(filename) {
    document.getElementById('aptAiJdDropzoneIdle')?.classList.toggle('d-none', !!filename);
    document.getElementById('aptAiJdDropzoneFile')?.classList.toggle('d-none', !filename);
    const nameEl = document.getElementById('aptAiJdFilename');
    if (nameEl) nameEl.textContent = filename || '';
    document.getElementById('aptAiJdDropzone')?.classList.toggle('has-file', !!filename);
  }

  function clearAptAiJdUpload() {
    aiJdUploadMeta = null;
    const input = document.getElementById('aptAiJdFileInput');
    if (input) input.value = '';
    showAptAiJdFileUi('');
  }

  function todayIsoDate() {
    return new Date().toISOString().slice(0, 10);
  }

  function formatJdTitleDate(dateValue) {
    const raw = String(dateValue || '').trim();
    if (!raw) return '';
    const d = new Date(`${raw}T12:00:00`);
    if (Number.isNaN(d.getTime())) return raw;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function resolveJdTitle(baseTitle, addDate, dateValue) {
    const base = String(baseTitle || '').trim();
    if (!base) return '';
    if (!addDate) return base;
    const dateLabel = formatJdTitleDate(dateValue || todayIsoDate());
    if (!dateLabel) return base;
    const suffix = ` — ${dateLabel}`;
    if (base.endsWith(suffix) || base.endsWith(dateLabel)) return base;
    return `${base}${suffix}`;
  }

  function getJdTitleFormState() {
    const base = (document.getElementById('aptAiJdTitle')?.value || '').trim();
    const addDate = document.getElementById('aptAiJdTitleAddDate')?.checked === true;
    const dateValue = document.getElementById('aptAiJdTitleDate')?.value || todayIsoDate();
    return {
      jdTitleBase: base,
      jdTitleAddDate: addDate,
      jdTitleDate: dateValue,
      jdTitle: resolveJdTitle(base, addDate, dateValue),
    };
  }

  function resetJdTitleDateOptions() {
    const addDateEl = document.getElementById('aptAiJdTitleAddDate');
    const dateEl = document.getElementById('aptAiJdTitleDate');
    if (addDateEl) addDateEl.checked = false;
    if (dateEl) dateEl.value = todayIsoDate();
    updateJdTitleDateUi();
  }

  function updateJdTitleDateUi() {
    const addDate = document.getElementById('aptAiJdTitleAddDate')?.checked === true;
    document.getElementById('aptAiJdTitleDateWrap')?.classList.toggle('d-none', !addDate);
    const hint = document.getElementById('aptAiJdTitleHint');
    const preview = document.getElementById('aptAiJdTitlePreview');
    const { jdTitleBase, jdTitle } = getJdTitleFormState();
    if (hint) {
      hint.textContent = addDate
        ? 'The selected date is appended to the title when saving to JD Block.'
        : 'Role or JD title saved under the selected company in JD Block.';
    }
    if (preview) {
      if (addDate && jdTitleBase && jdTitle !== jdTitleBase) {
        preview.textContent = `Saved as: ${jdTitle}`;
        preview.classList.remove('d-none');
      } else {
        preview.textContent = '';
        preview.classList.add('d-none');
      }
    }
  }

  function getJdPastedText() {
    return (document.getElementById('aptAiJdText')?.value || '').trim();
  }

  function getJdUploadedText() {
    return (aiJdUploadMeta?.text || '').trim();
  }

  function resolveJdTextForGeneration() {
    const pasted = getJdPastedText();
    if (pasted) return pasted;
    return getJdUploadedText();
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error('Could not read file.'));
      reader.readAsDataURL(file);
    });
  }

  async function extractAptAiJdFile(file) {
    if (!file) return;
    const ext = (file.name || '').split('.').pop()?.toLowerCase() || '';
    const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!allowed.includes(ext)) {
      toast('Supported formats: PDF, JPG, JPEG, PNG.', 'error');
      return;
    }
    if (file.size > AI_JD_MAX_FILE_BYTES) {
      toast('File must be 5 MB or smaller.', 'error');
      return;
    }

    const live = Auth.hasRealAuth() && !Auth.isDemo();
    const status = document.getElementById('aptAiJdExtractStatus');
    status?.classList.remove('d-none');

    try {
      if (!live) {
        if (!Auth.isDemo() || !access.canManage) {
          toast('Sign in as a placement officer to extract JD text.', 'info');
          return;
        }
        const demoText = `Software Developer\n\nResponsibilities:\n- Develop web applications\n- Build REST APIs\n- Work with databases\n\nRequirements:\n- Java\n- Python\n- JavaScript\n- React\n- SQL\n- Data Structures\n- OOP`;
        const jdFileDataUrl = await readFileAsDataUrl(file);
        aiJdUploadMeta = {
          filename: file.name,
          demo: true,
          text: demoText,
          method: ext,
          jdFileDataUrl,
          jdMimeType: file.type || (ext === 'pdf' ? 'application/pdf' : `image/${ext === 'jpg' ? 'jpeg' : ext}`),
        };
        showAptAiJdFileUi(file.name);
        toast('File ready for generation (demo).', 'info');
        return;
      }

      const fd = new FormData();
      fd.append('jd', file);
      const res = await api('/aptitude/ai/extract-jd', { method: 'POST', body: fd });
      if (!res?.success) throw new Error(res?.message || 'Could not extract text from file.');
      const text = (res.data?.text || '').trim();
      if (!text) {
        throw new Error('Unable to extract text from this file. Please upload a clearer PDF/image or paste the JD text manually.');
      }
      aiJdUploadMeta = {
        filename: res.data?.filename || file.name,
        method: res.data?.method || ext,
        text,
        jdFile: res.data?.jdFile || '',
        jdFileUrl: res.data?.jdFileUrl || '',
        jdMimeType: res.data?.jdMimeType || '',
      };
      showAptAiJdFileUi(aiJdUploadMeta.filename);
      toast('File ready for generation.', 'success');
    } catch (err) {
      toast(err?.message || 'Unable to extract text from this file. Please upload a clearer PDF/image or paste the JD text manually.', 'error');
      clearAptAiJdUpload();
    } finally {
      status?.classList.add('d-none');
    }
  }

  function bindAptAiJdDropzone() {
    const dropzone = document.getElementById('aptAiJdDropzone');
    const input = document.getElementById('aptAiJdFileInput');
    if (!dropzone || !input || dropzone.dataset.bound === '1') return;
    dropzone.dataset.bound = '1';

    dropzone.addEventListener('click', () => input.click());
    dropzone.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (file) extractAptAiJdFile(file);
    });
    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('dragover');
      const file = e.dataTransfer?.files?.[0];
      if (file) extractAptAiJdFile(file);
    });
    document.getElementById('btnAptAiJdRemove')?.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      clearAptAiJdUpload();
    });
  }

  function addAiGenRow(row = {}) {
    const root = document.getElementById('aptAiGenRows');
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'apt-ai-gen-row row g-2 align-items-end';
    const difficulty = row.difficulty || 'Medium';
    const count = row.count ?? 5;
    wrap.innerHTML = `
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-ai-gen="difficulty">
          ${APTITUDE_DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === difficulty ? 'selected' : ''}>${esc(d)}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1">Number of questions</label>
        <input class="form-control form-control-sm" type="number" data-ai-gen="count" min="0" max="${meta.aiQuestionCountMax || AI_GEN_MAX_TOTAL}" value="${esc(count)}"/>
      </div>
      <div class="col-md-2">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-ai-gen-remove title="Remove row"><i class="bi bi-trash"></i></button>
      </div>`;
    wrap.querySelector('[data-ai-gen-remove]')?.addEventListener('click', () => {
      if (root.querySelectorAll('.apt-ai-gen-row').length <= 1) {
        toast('Keep at least one generation row.', 'error');
        return;
      }
      wrap.remove();
      updateAiGenTotal();
    });
    wrap.querySelector('[data-ai-gen="count"]')?.addEventListener('input', updateAiGenTotal);
    root.appendChild(wrap);
    updateAiGenTotal();
  }

  function initAiGenRows(rows = AI_GEN_ROW_DEFAULTS) {
    const root = document.getElementById('aptAiGenRows');
    if (!root) return;
    root.innerHTML = '';
    (rows.length ? rows : AI_GEN_ROW_DEFAULTS).forEach((row) => addAiGenRow(row));
    updateAiGenTotal();
  }

  function collectAiGenRows() {
    const maxTotal = meta.aiQuestionCountMax || AI_GEN_MAX_TOTAL;
    return [...document.querySelectorAll('.apt-ai-gen-row')].map((el) => ({
      difficulty: el.querySelector('[data-ai-gen="difficulty"]')?.value || 'Medium',
      count: Math.max(0, Math.min(maxTotal, Number(el.querySelector('[data-ai-gen="count"]')?.value || 0))),
      marks: 1,
    })).filter((row) => row.count > 0);
  }

  function initAiFormFields(rows = AI_GEN_ROW_DEFAULTS) {
    fillSelect(document.getElementById('aptAiCategory'), meta.categories || APTITUDE_CATEGORIES, 'Quantitative Aptitude');
    initAiGenRows(rows);
    const cat = document.getElementById('aptAiCategory')?.value || 'Quantitative Aptitude';
    fillAiTopicDatalist(cat);
  }

  function collectAiFormParams() {
    const mode = getAiSourceMode();
    const batches = collectAiGenRows();
    const first = batches[0] || { difficulty: 'Medium', count: 5, marks: 1 };
    const base = {
      generationMode: mode,
      batches,
      difficulty: first.difficulty,
      count: batches.reduce((sum, row) => sum + row.count, 0),
      marks: first.marks,
      language: 'English',
      negativeMarking: false,
      negativeMarks: 0,
      instructions: document.getElementById('aptAiInstructions')?.value || '',
    };
    if (mode === 'jd') {
      const jobDescriptionPasted = getJdPastedText();
      const jobDescriptionUploaded = getJdUploadedText();
      const titleState = getJdTitleFormState();
      return {
        ...base,
        jdTitle: titleState.jdTitle,
        jdTitleBase: titleState.jdTitleBase,
        jdTitleAddDate: titleState.jdTitleAddDate,
        jdTitleDate: titleState.jdTitleDate,
        ...selectedJdCompanyFromForm(),
        jobDescription: jobDescriptionPasted || jobDescriptionUploaded,
        jobDescriptionPasted,
        jobDescriptionUploaded,
        jdUploadFilename: aiJdUploadMeta?.filename || '',
        jdFile: aiJdUploadMeta?.jdFile || '',
        jdFileUrl: aiJdUploadMeta?.jdFileUrl || '',
        jdFileDataUrl: aiJdUploadMeta?.jdFileDataUrl || '',
        jdMimeType: aiJdUploadMeta?.jdMimeType || '',
        category: 'General Aptitude',
        topic: 'Job Description',
      };
    }
    return {
      ...base,
      category: document.getElementById('aptAiCategory')?.value || 'General Aptitude',
      topic: (document.getElementById('aptAiTopic')?.value || '').trim(),
    };
  }

  function showAptAiFormPanel() {
    document.getElementById('aptAiFormPanel')?.classList.remove('d-none');
    document.getElementById('aptAiPreviewPanel')?.classList.add('d-none');
    updateAptAiModalTitle();
    updateAptAiSaveButtonLabel();
  }

  function updateAptAiSaveButtonLabel() {
    const btn = document.getElementById('btnAptAiSaveBank');
    if (!btn) return;
    const isJd = getAiSourceMode() === 'jd' || aiLastFormParams?.generationMode === 'jd';
    btn.innerHTML = isJd
      ? '<i class="bi bi-briefcase me-1"></i>Save to JD Block'
      : '<i class="bi bi-database-add me-1"></i>Save to question bank';
  }

  function showAptAiPreviewPanel() {
    document.getElementById('aptAiFormPanel')?.classList.add('d-none');
    document.getElementById('aptAiPreviewPanel')?.classList.remove('d-none');
    document.getElementById('aptAiModalTitle').textContent = 'Review generated questions';
    document.getElementById('aptAiModal')?.querySelector('.modal-body')?.scrollTo(0, 0);
    updateAptAiSaveButtonLabel();
  }

  async function openAptAiModal(opts = {}) {
    aiGenerateContext = opts.jd === true ? 'jd' : 'bank';
    document.getElementById('aptAiJdText').value = '';
    document.getElementById('aptAiJdTitle').value = '';
    resetJdTitleDateOptions();
    clearAptAiJdUpload();
    if (aiGenerateContext === 'jd') {
      await ensureJdCompaniesLoaded().catch(() => {});
      fillAptAiJdCompanySelect(opts.companyId || '');
    }
    const instr = document.getElementById('aptAiInstructions');
    if (instr) {
      instr.value = aiGenerateContext === 'jd' ? AI_JD_DEFAULT_INSTRUCTIONS : AI_CATEGORY_DEFAULT_INSTRUCTIONS;
      delete instr.dataset.userEdited;
    }
    initAiFormFields();
    updateAiSourceModeUI();
    bindAptAiJdDropzone();
    showAptAiFormPanel();
    document.getElementById('aptAiPreviewList').innerHTML = '';
    document.getElementById('aptAiGenerateStatus')?.classList.add('d-none');
    document.getElementById('aptAiSaveStatus')?.classList.add('d-none');
    aptAiModal?.show();
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      api('/aptitude/ai/status').then((res) => {
        const hint = document.getElementById('aptAiStatusHint');
        if (hint && res?.data?.message) hint.textContent = res.data.message;
      }).catch(() => {});
    }
  }

  function demoGenerateAiQuestions(params) {
    const n = Math.max(1, Number(params.count || 5));
    const questions = [];
    for (let i = 0; i < n; i += 1) {
      const pct = 10 + i * 5;
      questions.push({
        tempId: `demo-ai-${i + 1}`,
        prompt: `[Demo AI] ${params.topic || 'Sample'} — What is ${pct}% of 200?`,
        options: [`${pct - 5}`, `${pct}`, `${pct + 5}`, `${pct + 10}`],
        correctIndex: 1,
        explanation: `${pct}% of 200 = ${(200 * pct / 100).toFixed(0)}.`,
        category: params.category || 'Quantitative Aptitude',
        topic: params.topic || 'Percentage',
        difficulty: params.difficulty || 'Medium',
        marks: params.marks || 1,
        source: 'AI',
        selected: true,
        duplicateInBank: false,
        duplicateInBatch: false,
      });
    }
    return { questions, requested: n, received: n, demo: true };
  }

  function demoGenerateJdAiQuestions(params) {
    const jd = (params.jobDescription || '').toLowerCase();
    const skillPool = [
      { topic: 'OOP', q: 'Which OOP concept allows a child class to provide a specific implementation of a method defined in its parent class?', opts: ['Encapsulation', 'Inheritance', 'Polymorphism', 'Abstraction'], correct: 2 },
      { topic: 'SQL', q: 'Which SQL clause is used to filter grouped records?', opts: ['WHERE', 'GROUP BY', 'HAVING', 'ORDER BY'], correct: 2 },
      { topic: 'REST APIs', q: 'Which HTTP method is commonly used to retrieve data from a REST API?', opts: ['POST', 'GET', 'PUT', 'DELETE'], correct: 1 },
      { topic: 'Java', q: 'Which Java keyword is used to inherit a class?', opts: ['implements', 'extends', 'inherits', 'superclass'], correct: 1 },
      { topic: 'React', q: 'In React, which hook is used for side effects in functional components?', opts: ['useState', 'useEffect', 'useContext', 'useReducer'], correct: 1 },
      { topic: 'Data Structures', q: 'Which data structure follows FIFO order?', opts: ['Stack', 'Queue', 'Tree', 'Graph'], correct: 1 },
    ];
    const relevant = skillPool.filter((s) => {
      const key = s.topic.toLowerCase();
      return jd.includes(key) || jd.includes(key.split(' ')[0]);
    });
    const pool = relevant.length ? relevant : skillPool.slice(0, 4);
    const n = Math.max(1, Number(params.count || 5));
    const questions = [];
    for (let i = 0; i < n; i += 1) {
      const skill = pool[i % pool.length];
      questions.push({
        tempId: `demo-ai-jd-${i + 1}`,
        prompt: `[Demo JD] ${skill.q}`,
        options: skill.opts,
        correctIndex: skill.correct,
        explanation: `${skill.opts[skill.correct]} is the correct answer because it directly relates to ${skill.topic} in the job description.`,
        category: 'General Aptitude',
        topic: skill.topic,
        difficulty: params.difficulty || 'Medium',
        marks: params.marks || 1,
        source: 'AI_JD',
        selected: true,
        duplicateInBank: false,
        duplicateInBatch: false,
      });
    }
    return { questions, requested: n, received: n, demo: true };
  }

  function sanitizeAiOptionText(value) {
    return String(value ?? '').trim().replace(/^[A-Da-d][\).\:\-\s]+/, '').trim();
  }

  function parseAiOptionNumeric(option) {
    const text = sanitizeAiOptionText(option);
    const stripped = text.replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);
    return stripped ? Number(stripped[0]) : null;
  }

  function extractComputedNumericFromExplanation(explanation) {
    const text = String(explanation ?? '');
    const matches = [...text.matchAll(/=\s*(?:\$|₹|Rs\.?\s*)?([\d,]+(?:\.\d+)?)/gi)];
    if (!matches.length) return null;
    const last = matches[matches.length - 1][1].replace(/,/g, '');
    const n = Number(last);
    return Number.isFinite(n) ? n : null;
  }

  function formatNumericLikeOptions(value, options) {
    const usesDollar = (options || []).some((opt) => String(opt).includes('$'));
    const rounded = Math.abs(value - Math.round(value)) < 0.001
      ? String(Math.round(value))
      : String(Math.round(value * 100) / 100);
    return usesDollar ? `$${rounded}` : rounded;
  }

  function parseLetterFromExplanation(explanation) {
    const exp = String(explanation ?? '');
    let m = exp.match(/\b(?:option|choice|answer)\s*([A-Da-d])\b/i);
    if (m) return m[1].toUpperCase().charCodeAt(0) - 65;
    m = exp.match(/\b([A-Da-d])\s+(?:is|are)\s+(?:correct|the correct|right)\b/i);
    if (m) return m[1].toUpperCase().charCodeAt(0) - 65;
    return null;
  }

  function ensureExplanationMentionsCorrectOption(options, correctIndex, explanation) {
    const idx = Math.max(0, Math.min(3, Number(correctIndex) || 0));
    const opt = sanitizeAiOptionText((options || [])[idx] || '');
    const exp = String(explanation ?? '').trim();
    if (!opt || !exp) return exp;
    if (explanationSupportsOption(exp, opt)) return exp;
    return `${exp.replace(/[.\s]+$/, '')}. The correct answer is ${opt}.`;
  }

  function findUniqueOptionInExplanation(options, explanation) {
    const letterIndex = parseLetterFromExplanation(explanation);
    if (letterIndex != null && (options || [])[letterIndex]) return letterIndex;
    const exp = String(explanation ?? '').toLowerCase();
    const matches = [];
    (options || []).slice(0, 4).forEach((opt, i) => {
      const optText = sanitizeAiOptionText(opt);
      if (!optText) return;
      if (explanationSupportsOption(explanation, optText)) {
        matches.push({ index: i, len: optText.length });
      }
    });
    if (!matches.length) return null;
    matches.sort((a, b) => b.len - a.len);
    const bestLen = matches[0].len;
    const tied = matches.filter((m) => m.len === bestLen);
    return tied.length === 1 ? tied[0].index : null;
  }

  function alignAiPreviewOptions(options, explanation, hintIndex = 0) {
    const opts = (options || []).slice(0, 4).map((o) => sanitizeAiOptionText(o));
    const hint = Math.max(0, Math.min(3, Number(hintIndex) || 0));
    const fromExplanation = findUniqueOptionInExplanation(opts, explanation);
    if (fromExplanation != null) {
      return { options: opts, correctIndex: fromExplanation };
    }
    const computed = extractComputedNumericFromExplanation(explanation);
    if (computed == null) {
      return { options: opts, correctIndex: hint };
    }
    for (let i = 0; i < opts.length; i += 1) {
      const optNum = parseAiOptionNumeric(opts[i]);
      if (optNum != null && Math.abs(optNum - computed) < 0.01) {
        return { options: opts, correctIndex: i };
      }
    }
    let replaceIndex = hint;
    const hintNum = parseAiOptionNumeric(opts[replaceIndex]);
    if (hintNum != null && Math.abs(hintNum - computed) >= 0.01) {
      opts[replaceIndex] = formatNumericLikeOptions(computed, opts);
    } else {
      let bestIdx = hint;
      let bestDiff = Infinity;
      opts.forEach((opt, i) => {
        const n = parseAiOptionNumeric(opt);
        if (n == null) return;
        const diff = Math.abs(n - computed);
        if (diff < bestDiff) {
          bestDiff = diff;
          bestIdx = i;
        }
      });
      replaceIndex = bestIdx;
      opts[replaceIndex] = formatNumericLikeOptions(computed, opts);
    }
    return { options: opts, correctIndex: replaceIndex };
  }

  function explanationSupportsOption(explanation, optionText) {
    const exp = String(explanation ?? '').toLowerCase();
    const opt = sanitizeAiOptionText(optionText).toLowerCase();
    if (!exp || !opt || opt.length < 2) return true;
    const numeric = parseAiOptionNumeric(optionText);
    if (numeric != null) {
      const candidates = new Set([
        opt,
        formatNumericLikeOptions(numeric, [optionText]).toLowerCase(),
        String(Math.round(numeric)),
        String(Math.round(numeric * 100) / 100),
      ]);
      for (const candidate of candidates) {
        if (!candidate) continue;
        if (/^-?\d+(?:\.\d+)?%?$/.test(candidate)) {
          const re = new RegExp(`(?<!\\d)${candidate.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?!\\d)`);
          if (re.test(exp)) return true;
        } else if (exp.includes(candidate)) {
          return true;
        }
      }
      return false;
    }
    if (opt.length >= 2) {
      const re = new RegExp(`\\b${opt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i');
      if (re.test(exp)) return true;
    }
    return exp.includes(opt);
  }

  function reconcileAiPreviewQuestion(q) {
    if (!q || typeof q !== 'object') return q;
    if (q.lockCorrectIndex) {
      q.options = (q.options || []).slice(0, 4).map((o) => sanitizeAiOptionText(o));
      q.correctIndex = resolveAiPreviewCorrectIndex({ ...q, options: q.options });
      q.explanation = ensureExplanationMentionsCorrectOption(q.options, q.correctIndex, q.explanation || '');
      q.answerAligned = true;
      return q;
    }
    const options = (q.options || []).slice(0, 4).map((o) => sanitizeAiOptionText(o));
    const hintIndex = resolveAiPreviewCorrectIndex({ ...q, options });
    const aligned = alignAiPreviewOptions(options, q.explanation || '', hintIndex);
    q.options = aligned.options;
    q.correctIndex = aligned.correctIndex;
    q.explanation = ensureExplanationMentionsCorrectOption(q.options, q.correctIndex, q.explanation || '');
    q.answerAligned = true;
    return q;
  }

  function resolveAiPreviewCorrectIndex(q) {
    if (q?.correctIndex != null && q.correctIndex !== '') {
      const idx = Number(q.correctIndex);
      if (Number.isInteger(idx) && idx >= 0 && idx <= 3) return idx;
    }
    const raw = q?.correctAnswer ?? q?.correct_answer;
    if (raw != null && raw !== '') {
      if (typeof raw === 'string' && /^[A-Da-d]$/.test(raw.trim())) {
        return raw.trim().toUpperCase().charCodeAt(0) - 65;
      }
      const n = Number(raw);
      if (Number.isInteger(n)) {
        if (n >= 0 && n <= 3) return n;
        if (n >= 1 && n <= 4) return n - 1;
      }
    }
    return 0;
  }

  function saveAiPreviewEdit(idx) {
    const list = document.getElementById('aptAiPreviewList');
    const card = list?.querySelector(`[data-ai-card="${idx}"]`);
    const q = aiPreviewQuestions[idx];
    if (!card || !q) return;
    q.prompt = card.querySelector('[data-ai-field="prompt"]')?.value || '';
    q.options = [0, 1, 2, 3].map((oi) => String(card.querySelector(`[data-ai-field="opt${oi}"]`)?.value || '').trim());
    q.correctIndex = Number(card.querySelector('[data-ai-field="correct"]')?.value || 0);
    q.lockCorrectIndex = true;
    q.explanation = card.querySelector('[data-ai-field="explanation"]')?.value || '';
    delete q._editing;
    renderAptAiPreview();
  }

  function bindAptAiPreviewEvents() {
    const list = document.getElementById('aptAiPreviewList');
    if (!list || list.dataset.aiPreviewBound === '1') return;
    list.dataset.aiPreviewBound = '1';

    list.addEventListener('click', (e) => {
      const editBtn = e.target.closest('[data-ai-edit-btn]');
      if (editBtn) {
        e.preventDefault();
        const idx = Number(editBtn.getAttribute('data-ai-edit-btn'));
        aiPreviewQuestions.forEach((item, i) => {
          if (i !== idx && item?._editing) delete item._editing;
        });
        if (aiPreviewQuestions[idx]) aiPreviewQuestions[idx]._editing = true;
        renderAptAiPreview();
        return;
      }

      const saveBtn = e.target.closest('[data-ai-save-edit]');
      if (saveBtn) {
        e.preventDefault();
        saveAiPreviewEdit(Number(saveBtn.getAttribute('data-ai-save-edit')));
        return;
      }

      const cancelBtn = e.target.closest('[data-ai-cancel-edit]');
      if (cancelBtn) {
        e.preventDefault();
        const idx = Number(cancelBtn.getAttribute('data-ai-cancel-edit'));
        if (aiPreviewQuestions[idx]) delete aiPreviewQuestions[idx]._editing;
        renderAptAiPreview();
        return;
      }

      const deleteBtn = e.target.closest('[data-ai-delete]');
      if (deleteBtn) {
        e.preventDefault();
        aiPreviewQuestions.splice(Number(deleteBtn.getAttribute('data-ai-delete')), 1);
        renderAptAiPreview();
      }
    });

    list.addEventListener('change', (e) => {
      const pick = e.target.closest('[data-ai-set-correct]');
      if (pick) {
        const idx = Number(pick.getAttribute('data-ai-set-correct'));
        const val = Number(pick.value);
        if (aiPreviewQuestions[idx] && Number.isInteger(val) && val >= 0 && val <= 3) {
          const item = aiPreviewQuestions[idx];
          item.correctIndex = val;
          item.lockCorrectIndex = true;
          item.explanation = ensureExplanationMentionsCorrectOption(item.options || [], val, item.explanation || '');
          item.answerAligned = true;
          renderAptAiPreview();
        }
        return;
      }

      const sel = e.target.closest('[data-ai-idx]');
      if (sel) {
        const idx = Number(sel.getAttribute('data-ai-idx'));
        if (aiPreviewQuestions[idx]) aiPreviewQuestions[idx].selected = sel.checked;
      }
    });
  }

  function renderAptAiPreview() {
    const list = document.getElementById('aptAiPreviewList');
    const countEl = document.getElementById('aptAiPreviewCount');
    if (countEl) countEl.textContent = String(aiPreviewQuestions.length);
    if (!list) return;
    bindAptAiPreviewEvents();
    if (!aiPreviewQuestions.length) {
      const isJd = getAiSourceMode() === 'jd' || aiLastFormParams?.generationMode === 'jd';
      const viewLabel = isJd ? 'View JD Block' : 'View question bank';
      list.innerHTML = `<p class="text-muted-2 mb-0">No questions in preview. Generate again or close this dialog.</p>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button type="button" class="btn btn-sm btn-primary" id="btnAptAiGenerateMore"><i class="bi bi-stars me-1"></i>Generate more</button>
          <button type="button" class="btn btn-sm btn-outline-primary" id="btnAptAiViewBank">${esc(viewLabel)}</button>
        </div>`;
      document.getElementById('btnAptAiGenerateMore')?.addEventListener('click', () => showAptAiFormPanel());
      document.getElementById('btnAptAiViewBank')?.addEventListener('click', () => {
        aptAiModal?.hide();
        applyManagePanel(isJd ? 'jd' : 'bank');
        applyView('manage').catch(() => {});
      });
      return;
    }
    const letters = ['A', 'B', 'C', 'D'];
    list.innerHTML = aiPreviewQuestions.map((q, i) => {
      const opts = (q.options || []).slice(0, 4);
      const correct = resolveAiPreviewCorrectIndex(q);
      q.correctIndex = correct;
      const mismatch = !q.answerAligned && !explanationSupportsOption(q.explanation, opts[correct]);
      const mismatchWarn = mismatch
        ? '<div class="small text-warning mt-1">Marked answer may not match the explanation — pick the correct option below or edit.</div>'
        : '';
      const dup = q.duplicateMessage ? `<div class="small text-warning mt-1">${esc(q.duplicateMessage)}</div>` : '';
      const editing = q._editing;
      if (editing) {
        return `<div class="border rounded-3 p-3" data-ai-card="${i}">
          <div class="fw-semibold mb-2">Edit question ${i + 1}</div>
          <label class="form-label small mb-1">Question</label>
          <textarea class="form-control form-control-sm mb-2" data-ai-field="prompt" rows="2">${esc(q.prompt || '')}</textarea>
          ${[0, 1, 2, 3].map((oi) => `<label class="form-label small mb-1">Option ${letters[oi]}</label><input class="form-control form-control-sm mb-2" data-ai-field="opt${oi}" value="${esc(opts[oi] || '')}"/>`).join('')}
          <div class="mb-2">
            <label class="form-label small mb-1">Correct answer</label>
            <select class="form-select form-select-sm" data-ai-field="correct">${[0, 1, 2, 3].map((oi) => `<option value="${oi}" ${correct === oi ? 'selected' : ''}>${letters[oi]} — ${esc(opts[oi] || '')}</option>`).join('')}</select>
          </div>
          <label class="form-label small mb-1">Explanation</label>
          <textarea class="form-control form-control-sm mb-2" data-ai-field="explanation" rows="2">${esc(q.explanation || '')}</textarea>
          <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-primary" data-ai-save-edit="${i}">Save</button><button type="button" class="btn btn-sm btn-outline-secondary" data-ai-cancel-edit="${i}">Cancel</button></div>
        </div>`;
      }
      return `<div class="border rounded-3 p-3 apt-q-card" data-ai-card="${i}">
        <div class="d-flex gap-2 align-items-start">
          <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-ai-idx="${i}" ${q.selected !== false ? 'checked' : ''}/>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold mb-1">Question ${i + 1}</div>
            <div class="mb-2 apt-q-card-text">${esc(q.prompt || '')}</div>
            <div class="small mb-2">${opts.map((o, oi) => `<div class="apt-q-card-text">${letters[oi]}. ${esc(o)}${oi === correct ? ' <span class="text-success fw-semibold">✓</span>' : ''}</div>`).join('')}</div>
            <div class="small mb-2">
              <span class="fw-semibold">Correct answer:</span>
              <span class="ms-1">${letters[correct]}. ${esc(opts[correct] || '—')}</span>
              <span class="text-muted-2 ms-2">Change:</span>
              ${[0, 1, 2, 3].map((oi) => `<label class="form-check form-check-inline ms-1"><input class="form-check-input" type="radio" name="ai-correct-${i}" data-ai-set-correct="${i}" value="${oi}" ${correct === oi ? 'checked' : ''}/> ${letters[oi]}</label>`).join('')}
            </div>
            <div class="small text-muted-2">${esc(q.category || '')} · ${esc(q.topic || '')} · ${esc(q.difficulty || '')}</div>
            <div class="small mt-1"><span class="fw-semibold">Explanation:</span> ${esc(q.explanation || '')}</div>
            ${mismatchWarn}
            ${dup}
            <div class="d-flex flex-wrap gap-2 mt-2">
              <button type="button" class="btn btn-sm btn-outline-primary" data-ai-edit-btn="${i}">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-danger" data-ai-delete="${i}">Delete</button>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');
  }

  function createAiProgressKey() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return `ai-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  function updateAiGenerateProgressUi(progress) {
    const textEl = document.getElementById('aptAiGenerateStatusText');
    const barEl = document.getElementById('aptAiGenerateProgressBar');
    const countEl = document.getElementById('aptAiGenerateProgressCount');
    if (!progress) return;
    const message = progress.message || 'Generating questions…';
    const generated = Number(progress.generated || 0);
    const requested = Number(progress.requested || 0);
    const percent = requested > 0
      ? Math.min(100, Math.max(0, Number(progress.percent ?? Math.round((generated / requested) * 100))))
      : Number(progress.percent || 0);
    if (textEl) textEl.textContent = message;
    if (barEl) {
      barEl.style.width = `${percent}%`;
      barEl.setAttribute('aria-valuenow', String(percent));
    }
    if (countEl) {
      countEl.textContent = requested > 0 ? `${generated} / ${requested} generated` : '';
    }
  }

  function stopAiGenProgressPoll() {
    if (aiGenProgressTimer) {
      clearInterval(aiGenProgressTimer);
      aiGenProgressTimer = null;
    }
  }

  function startAiGenProgressPoll(progressKey) {
    stopAiGenProgressPoll();
    if (!progressKey) return;
    aiGenProgressTimer = setInterval(async () => {
      const res = await api(`/aptitude/ai/generate-progress?key=${encodeURIComponent(progressKey)}`).catch(() => null);
      if (!res?.success || !res.data?.active) return;
      updateAiGenerateProgressUi(res.data);
      if (res.data.done) stopAiGenProgressPoll();
    }, 800);
  }

  function showAiIncompleteDialog(data) {
    return new Promise((resolve) => {
      const modalEl = document.getElementById('aptAiIncompleteModal');
      const bodyEl = document.getElementById('aptAiIncompleteBody');
      const useBtn = document.getElementById('btnAptAiIncompleteUse');
      const retryBtn = document.getElementById('btnAptAiIncompleteRetry');
      if (!modalEl || !bodyEl || !useBtn || !retryBtn) {
        resolve('use');
        return;
      }
      const requested = Number(data.requested || 0);
      const received = Number(data.received || 0);
      const shortfall = Number(data.shortfall || Math.max(0, requested - received));
      bodyEl.innerHTML = `<p class="mb-2">${esc(data.message || `We generated ${received} valid questions out of the requested ${requested}.`)}</p>
        <p class="small text-muted-2 mb-0">The AI could not generate ${esc(String(shortfall))} additional unique question(s) from the available Job Description content after multiple attempts.</p>`;
      aptAiIncompleteModal = aptAiIncompleteModal || new bootstrap.Modal(modalEl);
      const cleanup = () => {
        useBtn.removeEventListener('click', onUse);
        retryBtn.removeEventListener('click', onRetry);
        modalEl.removeEventListener('hidden.bs.modal', onHide);
      };
      const onUse = () => {
        cleanup();
        aptAiIncompleteModal.hide();
        resolve('use');
      };
      const onRetry = () => {
        cleanup();
        aptAiIncompleteModal.hide();
        resolve('retry');
      };
      const onHide = () => {
        cleanup();
        resolve('use');
      };
      useBtn.addEventListener('click', onUse);
      retryBtn.addEventListener('click', onRetry);
      modalEl.addEventListener('hidden.bs.modal', onHide, { once: true });
      aptAiIncompleteModal.show();
    });
  }

  function finishAiPreview(data, params) {
    aiPreviewQuestions = (data.questions || []).map((q) => reconcileAiPreviewQuestion({ ...q, selected: q.selected !== false }));
    aiLastFormParams = params;
    if (!aiPreviewQuestions.length) {
      toast('No questions were generated.', 'error');
      return false;
    }
    showAptAiPreviewPanel();
    renderAptAiPreview();
    return true;
  }

  async function runAptAiGenerate() {
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    const params = collectAiFormParams();
    if (params.generationMode === 'jd') {
      const { companyId, companyName } = selectedJdCompanyFromForm();
      params.companyId = companyId;
      params.companyName = companyName;
      const titleState = getJdTitleFormState();
      params.jdTitle = titleState.jdTitle;
      params.jdTitleBase = titleState.jdTitleBase;
      params.jdTitleAddDate = titleState.jdTitleAddDate;
      params.jdTitleDate = titleState.jdTitleDate;
      if (!companyId) {
        toast('Select a company.', 'error');
        return;
      }
      if (!titleState.jdTitleBase) {
        toast('Enter a title.', 'error');
        return;
      }
      const jdText = resolveJdTextForGeneration();
      params.jobDescription = jdText;
      if (jdText.length < 40) {
        toast('Paste a job description or upload a PDF/image (at least 40 characters of JD content required).', 'error');
        return;
      }
    } else if (!params.topic) {
      toast('Enter a topic.', 'error');
      return;
    }
    if (!params.batches?.length) {
      toast('Add at least one generation row with a question count.', 'error');
      return;
    }
    const maxTotal = meta.aiQuestionCountMax || AI_GEN_MAX_TOTAL;
    if (params.count > maxTotal) {
      toast(`Total questions cannot exceed ${maxTotal}. Reduce counts across rows.`, 'error');
      updateAiGenTotal();
      return;
    }
    const status = document.getElementById('aptAiGenerateStatus');
    const btn = document.getElementById('btnAptAiRun');
    status?.classList.remove('d-none');
    updateAiGenerateProgressUi({ message: params.generationMode === 'jd' ? 'Analyzing Job Description…' : 'Generating questions…', generated: 0, requested: params.count, percent: 0 });
    btn?.setAttribute('disabled', 'disabled');
    let progressKey = '';
    let skipGenCleanup = false;
    try {
      let data;
      if (!live) {
        if (!Auth.isDemo() || !access.canManage) {
          toast('Sign in as a placement officer to generate questions.', 'info');
          return;
        }
        const merged = [];
        params.batches.forEach((batch) => {
          const chunk = params.generationMode === 'jd'
            ? demoGenerateJdAiQuestions({ ...params, ...batch })
            : demoGenerateAiQuestions({ ...params, ...batch });
          merged.push(...(chunk.questions || []));
        });
        data = { questions: merged, requested: params.count, received: merged.length };
        toast('Demo AI preview (no OpenAI call).', 'info');
      } else {
        if (params.generationMode === 'jd') {
          progressKey = createAiProgressKey();
          params.progressKey = progressKey;
          startAiGenProgressPoll(progressKey);
        }
        const res = await api('/aptitude/ai/generate', { method: 'POST', body: JSON.stringify(params) });
        if (!res?.success) throw new Error(res?.message || 'AI generation failed.');
        data = res.data || {};
      }
      stopAiGenProgressPoll();
      if (data.incomplete) {
        const choice = await showAiIncompleteDialog(data);
        if (choice === 'retry') {
          skipGenCleanup = true;
          setTimeout(() => runAptAiGenerate(), 50);
          return;
        }
      } else {
        const requested = Number(data.requested || params.count || 0);
        const received = Number(data.received || (data.questions || []).length);
        if (requested > 0 && received >= requested) {
          toast(`✓ ${received} questions generated successfully`, 'success');
        }
      }
      finishAiPreview(data, params);
    } catch (err) {
      stopAiGenProgressPoll();
      toast(err?.message || 'AI question generation is temporarily unavailable. Please try again.', 'error');
    } finally {
      stopAiGenProgressPoll();
      if (!skipGenCleanup) {
        status?.classList.add('d-none');
        btn?.removeAttribute('disabled');
      }
    }
  }

  function selectedAiPreviewQuestions() {
    return aiPreviewQuestions.filter((q) => q.selected !== false);
  }

  async function saveAptAiToBank() {
    const selected = selectedAiPreviewQuestions();
    if (!selected.length) {
      toast('Select at least one question to save.', 'error');
      return;
    }
    const isJd = aiLastFormParams?.generationMode === 'jd';
    const titleState = isJd ? getJdTitleFormState() : null;
    const jdTitle = isJd
      ? (titleState?.jdTitle || aiLastFormParams?.jdTitle || '')
      : (document.getElementById('aptAiJdTitle')?.value || aiLastFormParams?.jdTitle || '').trim();
    const companyId = selectedJdCompanyFromForm().companyId || aiLastFormParams?.companyId || '';
    const companyName = selectedJdCompanyFromForm().companyName || aiLastFormParams?.companyName || '';
    if (isJd && !companyId) {
      toast('Select a company before saving.', 'error');
      return;
    }
    if (isJd && !(titleState?.jdTitleBase || aiLastFormParams?.jdTitleBase || aiLastFormParams?.jdTitle)) {
      toast('Enter a title before saving.', 'error');
      return;
    }
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    const status = document.getElementById('aptAiSaveStatus');
    const btn = document.getElementById('btnAptAiSaveBank');
    status?.classList.remove('d-none');
    btn?.setAttribute('disabled', 'disabled');
    try {
      const category = aiLastFormParams?.category || selected[0]?.category || 'General Aptitude';
      if (!live) {
        if (!Auth.isDemo() || !access.canManage) {
          toast('Saving requires a live session.', 'info');
          return;
        }
        if (isJd) {
          const setId = `demo-jd-${Date.now()}`;
          const questions = selected.map((q, i) => ({
            id: `jdq-${i + 1}-${Date.now()}`,
            prompt: q.prompt,
            options: q.options,
            correctIndex: q.correctIndex,
            explanation: q.explanation,
            category: q.category || 'General Aptitude',
            difficulty: q.difficulty || 'Medium',
            topic: q.topic || '',
            source: 'AI_JD',
          }));
          const store = loadDemoJdStore();
          const docUrl = aiJdUploadMeta?.jdFileDataUrl || aiLastFormParams?.jdFileDataUrl || '';
          store.unshift({
            id: setId,
            companyId,
            companyName,
            jdTitle,
            jdFilename: aiLastFormParams?.jdUploadFilename || aiJdUploadMeta?.filename || '',
            jdFileDataUrl: docUrl,
            jdFileUrl: docUrl,
            jdMimeType: aiJdUploadMeta?.jdMimeType || aiLastFormParams?.jdMimeType || '',
            hasDocument: !!docUrl,
            questionCount: questions.length,
            questions,
          });
          saveDemoJdStore(store);
          toast(`Saved ${questions.length} question(s) to JD Block: ${companyName} · ${jdTitle}`, 'success');
          await loadJdLibrary();
        } else {
          const bank = loadDemoBankStore();
          selected.forEach((q, i) => {
            bank.push({
              id: `demo-bank-ai-${Date.now()}-${i}`,
              prompt: q.prompt,
              options: q.options,
              correctIndex: q.correctIndex,
              explanation: q.explanation,
              category: q.category || category,
              difficulty: q.difficulty || 'Medium',
              topic: q.topic || '',
              source: q.source || 'AI',
            });
          });
          saveDemoBankStore(bank);
          toast(`Saved ${selected.length} question(s) to demo bank.`, 'success');
          await loadQuestionBank();
        }
      } else if (isJd) {
        const res = await api('/aptitude/ai/save-jd', {
          method: 'POST',
          body: JSON.stringify({
            questions: selected,
            jdTitle,
            companyId,
            companyName,
            jdFilename: aiLastFormParams?.jdUploadFilename || aiJdUploadMeta?.filename || '',
            jdFile: aiJdUploadMeta?.jdFile || aiLastFormParams?.jdFile || '',
            jdFileUrl: aiJdUploadMeta?.jdFileUrl || aiLastFormParams?.jdFileUrl || '',
            jdMimeType: aiJdUploadMeta?.jdMimeType || aiLastFormParams?.jdMimeType || '',
          }),
        });
        if (!res?.success) throw new Error(res?.message || 'Could not save JD questions.');
        toast(`Saved ${res.data?.questionCount ?? selected.length} question(s) to JD Block.`, 'success');
        delete jdSetDetailsCache[String(res.data?.id || '')];
        manualJdSetSummaries = [];
        await loadJdLibrary();
      } else {
        const res = await api('/aptitude/ai/save', {
          method: 'POST',
          body: JSON.stringify({ questions: selected, category }),
        });
        if (!res?.success) throw new Error(res?.message || 'Could not save questions.');
        toast(`Saved ${res.data?.added ?? selected.length} question(s) to the bank.`, 'success');
        await loadQuestionBank();
      }
      aiPreviewQuestions = aiPreviewQuestions.filter((q) => q.selected === false);
      applyManagePanel(isJd ? 'jd' : 'bank');
      showAptAiPreviewPanel();
      renderAptAiPreview();
    } catch (err) {
      toast(err?.message || 'Could not save AI questions.', 'error');
    } finally {
      status?.classList.add('d-none');
      btn?.removeAttribute('disabled');
    }
  }

  function addCategoryRuleRow(rootId, rule = {}, onChange = null) {
    const root = document.getElementById(rootId);
    if (!root) return;
    const wrap = document.createElement('div');
    wrap.className = 'tf-category-rule row g-2 align-items-end';
    const categories = meta.categories || APTITUDE_CATEGORIES;
    const catOpts = categories.map((c) =>
      `<option value="${esc(c)}" ${c === (rule.category || categories[0]) ? 'selected' : ''}>${esc(c)}</option>`
    ).join('');
    const diff = normalizeDifficulty(rule.difficulty || 'Medium');
    wrap.innerHTML = `
      <div class="col-md-4">
        <label class="form-label small mb-1">Category</label>
        <select class="form-select form-select-sm" data-f="category">${catOpts}</select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Difficulty</label>
        <select class="form-select form-select-sm" data-f="difficulty">
          ${APTITUDE_DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">No. of questions</label>
        <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(rule.count ?? 1)}"/>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Marks each</label>
        <input class="form-control form-control-sm" type="number" min="0.5" step="0.5" data-f="marks" value="${esc(rule.marks ?? 1)}"/>
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

  function collectCategoryRules(rootId) {
    return [...document.querySelectorAll(`#${rootId} .tf-category-rule`)].map((row) => ({
      category: row.querySelector('[data-f="category"]')?.value || 'General Aptitude',
      difficulty: row.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(row.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(0.5, Number(row.querySelector('[data-f="marks"]')?.value || 1)),
    }));
  }

  function addRandomRuleRow(rule = {}) {
    addCategoryRuleRow('tfRandomRules', rule, updateRandomSummary);
  }

  function collectRandomRules() {
    return collectCategoryRules('tfRandomRules');
  }

  async function ensureManualBankQuestionsLoaded() {
    if (manualBankAllQuestions.length) return;
    if (Auth.hasRealAuth() && !Auth.isDemo()) {
      const res = await api('/aptitude/question-bank').catch(() => null);
      manualBankAllQuestions = res?.data?.questions || [];
    } else {
      ensureDemoBankSeed();
      manualBankAllQuestions = loadDemoBankStore();
    }
  }

  function manualRuleCriteriaFromWrap(wrap) {
    return {
      category: wrap.querySelector('[data-f="category"]')?.value || 'General Aptitude',
      difficulty: wrap.querySelector('[data-f="difficulty"]')?.value || 'Medium',
      count: Math.max(1, Number(wrap.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(0.5, Number(wrap.querySelector('[data-f="marks"]')?.value || 1)),
    };
  }

  function manualRuleCriteriaKey(c) {
    return `${c.category}|${c.difficulty}|${c.count}`;
  }

  function filterManualBankPool(criteria) {
    return manualBankAllQuestions.filter((q) => bankQuestionMatchesRule(q, criteria));
  }

  function getManualBankIdsUsedExcept(excludeWrap) {
    const used = new Set();
    document.querySelectorAll('.tf-manual-bank-block').forEach((wrap) => {
      if (wrap === excludeWrap) return;
      wrap._manualRuleState?.selectedIds?.forEach((id) => used.add(String(id)));
    });
    return used;
  }

  function bindManualRulePicker(wrap, pool, needed) {
    const state = wrap._manualRuleState;
    wrap.querySelectorAll('[data-manual-pick]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = cb.getAttribute('data-manual-pick');
        if (!id) return;
        if (cb.checked) {
          if (state.selectedIds.size >= needed) {
            cb.checked = false;
            return;
          }
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
    const usedElsewhere = getManualBankIdsUsedExcept(wrap);

    if (!pool.length) {
      picker.innerHTML = '<p class="small text-muted-2 mb-0">No questions available for this category and difficulty.</p>';
      return;
    }

    picker.innerHTML = `
      <div class="small fw-semibold mb-1">Select ${needed} question${needed === 1 ? '' : 's'}</div>
      <div class="small mb-2 ${selected === needed ? 'text-success fw-semibold' : ''}">Selected: ${selected} / ${needed}</div>
      <div class="d-flex flex-column gap-1 manual-bank-pick-list">
        ${pool.map((q, i) => {
          const id = String(q.id || q.bankId || '');
          const checked = state.selectedIds.has(id);
          const disabled = (atLimit && !checked) || (usedElsewhere.has(id) && !checked);
          const prompt = stripHtml(q.prompt) || 'Question';
          return `<label class="d-flex align-items-start gap-2 border rounded-2 p-2 mb-0 bg-white ${disabled ? 'opacity-50' : ''}">
            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-manual-pick="${esc(id)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}/>
            <span class="small min-w-0 apt-q-card-text">Question ${i + 1}: ${esc(prompt)}</span>
          </label>`;
        }).join('')}
      </div>`;
    bindManualRulePicker(wrap, pool, needed);
  }

  function renderManualRuleSummary(wrap, criteria) {
    const summary = wrap.querySelector('.manual-bank-summary');
    if (!summary) return;
    summary.innerHTML = `
      <div class="small text-success">
        <div>✓ ${esc(criteria.category)}</div>
        <div>✓ ${esc(criteria.difficulty)}</div>
        <div>✓ ${esc(criteria.count)} question${criteria.count === 1 ? '' : 's'} selected</div>
      </div>
      <button type="button" class="btn btn-sm btn-link p-0 mt-1" data-edit-manual-pick>Edit Questions</button>`;
    summary.querySelector('[data-edit-manual-pick]')?.addEventListener('click', () => {
      wrap._manualRuleState.editing = true;
      wrap._manualRuleState.collapsed = false;
      refreshManualBankBlock(wrap);
    });
  }

  async function refreshManualBankBlock(wrap) {
    if (!wrap?._manualRuleState) return;
    await ensureManualBankQuestionsLoaded();
    const state = wrap._manualRuleState;
    const criteria = manualRuleCriteriaFromWrap(wrap);
    const key = manualRuleCriteriaKey(criteria);
    const pool = filterManualBankPool(criteria);
    const errorEl = wrap.querySelector('.manual-bank-error');
    const pickerEl = wrap.querySelector('.manual-bank-picker');
    const summaryEl = wrap.querySelector('.manual-bank-summary');

    if (state.criteriaKey && state.criteriaKey !== key) {
      state.selectedIds.clear();
      state.collapsed = false;
      state.editing = false;
    }
    state.criteriaKey = key;

    [...state.selectedIds].forEach((id) => {
      if (!pool.some((q) => String(q.id || q.bankId || '') === id)) {
        state.selectedIds.delete(id);
      }
    });

    if (pool.length < criteria.count) {
      errorEl?.classList.remove('d-none');
      if (errorEl) {
        errorEl.textContent = pool.length
          ? `Only ${pool.length} question${pool.length === 1 ? '' : 's'} ${pool.length === 1 ? 'is' : 'are'} available for ${criteria.category} — ${criteria.difficulty}. Please reduce the required number of questions or choose another category/difficulty.`
          : `No questions available for ${criteria.category} — ${criteria.difficulty}. Please choose another category/difficulty.`;
      }
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.add('d-none');
      state.collapsed = false;
      updateManualBankSummary();
      return;
    }

    errorEl?.classList.add('d-none');

    const complete = state.selectedIds.size === criteria.count;
    if (complete && !state.editing) {
      state.collapsed = true;
    }

    if (state.collapsed && !state.editing) {
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.remove('d-none');
      renderManualRuleSummary(wrap, criteria);
    } else {
      summaryEl?.classList.add('d-none');
      pickerEl?.classList.remove('d-none');
      renderManualRulePicker(wrap, pool, criteria);
    }
    updateManualBankSummary();
  }

  function addManualBankRuleRow(rule = {}) {
    manualBankRuleCounter += 1;
    const root = document.getElementById('tfManualBankRules');
    if (!root) return;
    const categories = meta.categories || APTITUDE_CATEGORIES;
    const catOpts = categories.map((c) =>
      `<option value="${esc(c)}" ${c === (rule.category || categories[0]) ? 'selected' : ''}>${esc(c)}</option>`
    ).join('');
    const diff = normalizeDifficulty(rule.difficulty || 'Medium');
    const selectedIds = (rule.selectedQuestionIds || []).map(String);
    const count = Math.max(1, Number(rule.count ?? 1));
    const wrap = document.createElement('div');
    wrap.className = 'tf-manual-bank-block border rounded-3 p-3 mb-2 bg-white';
    wrap.innerHTML = `
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label small mb-1">Category</label>
          <select class="form-select form-select-sm" data-f="category">${catOpts}</select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Difficulty</label>
          <select class="form-select form-select-sm" data-f="difficulty">
            ${APTITUDE_DIFFICULTIES.map((d) => `<option value="${esc(d)}" ${d === diff ? 'selected' : ''}>${esc(d)}</option>`).join('')}
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">No. of questions</label>
          <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(rule.count ?? 1)}"/>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Marks each</label>
          <input class="form-control form-control-sm" type="number" min="0.5" step="0.5" data-f="marks" value="${esc(rule.marks ?? 1)}"/>
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-manual-block>Remove</button>
        </div>
      </div>
      <div class="manual-bank-error small text-danger mt-2 d-none"></div>
      <div class="manual-bank-picker mt-2"></div>
      <div class="manual-bank-summary border rounded-2 p-2 mt-2 d-none"></div>`;
    wrap._manualRuleState = {
      selectedIds: new Set(selectedIds),
      collapsed: selectedIds.length >= count && selectedIds.length > 0,
      editing: false,
      criteriaKey: '',
    };
    wrap.querySelector('[data-remove-manual-block]')?.addEventListener('click', () => {
      wrap.remove();
      updateManualBankSummary();
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => refreshManualBankBlock(wrap));
      el.addEventListener('input', () => refreshManualBankBlock(wrap));
    });
    root.appendChild(wrap);
    refreshManualBankBlock(wrap);
  }

  function collectManualBankRules() {
    return [...document.querySelectorAll('.tf-manual-bank-block')].map((wrap) => {
      const c = manualRuleCriteriaFromWrap(wrap);
      return {
        ...c,
        selectedQuestionIds: [...(wrap._manualRuleState?.selectedIds || [])],
      };
    });
  }

  function validateManualBankRulesComplete() {
    const blocks = [...document.querySelectorAll('.tf-manual-bank-block')];
    if (!blocks.length) return 'Add at least one category rule for the question bank.';
    for (const wrap of blocks) {
      const c = manualRuleCriteriaFromWrap(wrap);
      const state = wrap._manualRuleState;
      const pool = filterManualBankPool(c);
      if (pool.length < c.count) {
        return pool.length
          ? `Only ${pool.length} question(s) available for ${c.category} — ${c.difficulty}. Reduce the count or change category/difficulty.`
          : `No questions available for ${c.category} — ${c.difficulty}.`;
      }
      if (!state || state.selectedIds.size !== c.count) {
        return `Select exactly ${c.count} question(s) for ${c.category} — ${c.difficulty} (${state?.selectedIds.size || 0} selected).`;
      }
    }
    return '';
  }

  function inferJdRulesFromQuestions(questions) {
    const groups = new Map();
    (questions || []).filter((q) => q?.jdSetId).forEach((q) => {
      const setId = String(q.jdSetId);
      if (!groups.has(setId)) {
        groups.set(setId, {
          jdSetId: setId,
          jdTitle: String(q.jdTitle || ''),
          count: 0,
          marks: Number(q.marks ?? 1) || 1,
          selectedQuestionIds: [],
        });
      }
      const g = groups.get(setId);
      g.count += 1;
      g.selectedQuestionIds.push(String(q.id || ''));
    });
    return [...groups.values()];
  }

  function demoResolveJdWithPreferred(rules) {
    const all = loadDemoJdStore();
    const picked = [];
    rules.forEach((rule) => {
      const set = all.find((s) => String(s.id) === String(rule.jdSetId));
      if (!set) throw new Error(`JD set not found: ${rule.jdTitle || rule.jdSetId}`);
      const selectedIds = (rule.selectedQuestionIds || []).map(String);
      if (selectedIds.length !== Number(rule.count)) {
        throw new Error(`Select exactly ${rule.count} question(s) for ${rule.jdTitle || set.jdTitle}.`);
      }
      selectedIds.forEach((qid) => {
        const q = (set.questions || []).find((item) => String(item.id) === qid);
        if (!q) throw new Error('Selected JD question not found.');
        picked.push({
          ...q,
          marks: Number(rule.marks) || 1,
          jdSetId: String(set.id),
          jdTitle: set.jdTitle || rule.jdTitle || '',
          source: 'AI_JD',
        });
      });
    });
    return picked;
  }

  function manualJdRuleFromWrap(wrap) {
    const setId = wrap.querySelector('[data-f="jdSetId"]')?.value || '';
    const selected = manualJdSetSummaries.find((s) => String(s.id) === String(setId));
    return {
      jdSetId: setId,
      jdTitle: selected?.jdTitle || wrap.querySelector('[data-f="jdSetId"] option:checked')?.textContent?.trim() || '',
      count: Math.max(1, Number(wrap.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(0.5, Number(wrap.querySelector('[data-f="marks"]')?.value || 1)),
    };
  }

  function getManualJdIdsUsedExcept(excludeWrap) {
    const used = new Set();
    document.querySelectorAll('.tf-manual-jd-block').forEach((wrap) => {
      if (wrap === excludeWrap) return;
      wrap._manualJdState?.selectedIds?.forEach((id) => used.add(String(id)));
    });
    return used;
  }

  function bindManualJdPicker(wrap, pool, needed) {
    const state = wrap._manualJdState;
    wrap.querySelectorAll('[data-jd-pick]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const id = cb.getAttribute('data-jd-pick');
        if (!id) return;
        if (cb.checked) {
          if (state.selectedIds.size >= needed) {
            cb.checked = false;
            return;
          }
          state.selectedIds.add(String(id));
        } else {
          state.selectedIds.delete(String(id));
          state.collapsed = false;
        }
        if (state.selectedIds.size === needed) {
          state.editing = false;
          state.collapsed = true;
        }
        refreshManualJdBlock(wrap);
      });
    });
  }

  function renderManualJdPicker(wrap, pool, criteria) {
    const picker = wrap.querySelector('.manual-jd-picker');
    if (!picker) return;
    const state = wrap._manualJdState;
    const needed = criteria.count;
    const selected = state.selectedIds.size;
    const atLimit = selected >= needed;
    const usedElsewhere = getManualJdIdsUsedExcept(wrap);
    if (!pool.length) {
      picker.innerHTML = '<p class="small text-muted-2 mb-0">No questions in this JD set.</p>';
      return;
    }
    picker.innerHTML = `
      <div class="small fw-semibold mb-1">Select ${needed} question${needed === 1 ? '' : 's'} from ${esc(criteria.jdTitle || 'JD')}</div>
      <div class="small mb-2 ${selected === needed ? 'text-success fw-semibold' : ''}">Selected: ${selected} / ${needed}</div>
      <div class="d-flex flex-column gap-2">${pool.map((q, i) => {
        const id = String(q.id || '');
        const checked = state.selectedIds.has(id);
        const disabled = (atLimit && !checked) || (usedElsewhere.has(id) && !checked);
        return `<label class="d-block border rounded-2 p-2 mb-0 bg-white ${disabled ? 'opacity-50' : ''}">
          <div class="d-flex align-items-start gap-2">
            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" data-jd-pick="${esc(id)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}/>
            <div class="flex-grow-1 min-w-0">${renderJdQuestionDetailHtml(q, i)}</div>
          </div>
        </label>`;
      }).join('')}</div>`;
    bindManualJdPicker(wrap, pool, needed);
  }

  function renderManualJdSummary(wrap, criteria) {
    const summary = wrap.querySelector('.manual-jd-summary');
    if (!summary) return;
    summary.innerHTML = `
      <div class="small text-success">
        <div>✓ ${esc(criteria.jdTitle || 'JD set')}</div>
        <div>✓ ${esc(criteria.count)} question${criteria.count === 1 ? '' : 's'} selected</div>
      </div>
      <button type="button" class="btn btn-sm btn-link p-0 mt-1" data-edit-jd-pick>Edit Questions</button>`;
    summary.querySelector('[data-edit-jd-pick]')?.addEventListener('click', () => {
      wrap._manualJdState.editing = true;
      wrap._manualJdState.collapsed = false;
      refreshManualJdBlock(wrap);
    });
  }

  async function refreshManualJdBlock(wrap) {
    if (!wrap?._manualJdState) return;
    await ensureJdSetSummariesLoaded();
    const state = wrap._manualJdState;
    const criteria = manualJdRuleFromWrap(wrap);
    const setId = criteria.jdSetId;
    const errorEl = wrap.querySelector('.manual-jd-error');
    const pickerEl = wrap.querySelector('.manual-jd-picker');
    const summaryEl = wrap.querySelector('.manual-jd-summary');

    if (state.criteriaKey && state.criteriaKey !== `${setId}|${criteria.count}`) {
      state.selectedIds.clear();
      state.collapsed = false;
      state.editing = false;
    }
    state.criteriaKey = `${setId}|${criteria.count}`;

    if (!setId) {
      errorEl?.classList.remove('d-none');
      if (errorEl) errorEl.textContent = 'Select a JD title.';
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.add('d-none');
      updateManualJdSummary();
      return;
    }

    const detail = await getJdSetDetail(setId);
    const pool = detail?.questions || [];
    if (pool.length < criteria.count) {
      errorEl?.classList.remove('d-none');
      if (errorEl) {
        errorEl.textContent = pool.length
          ? `Only ${pool.length} question(s) available in this JD set. Reduce the count.`
          : 'This JD set has no questions.';
      }
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.add('d-none');
      state.collapsed = false;
      updateManualJdSummary();
      return;
    }

    errorEl?.classList.add('d-none');
    const complete = state.selectedIds.size === criteria.count;
    if (complete && !state.editing) state.collapsed = true;

    if (state.collapsed && !state.editing) {
      pickerEl?.classList.add('d-none');
      summaryEl?.classList.remove('d-none');
      renderManualJdSummary(wrap, criteria);
    } else {
      summaryEl?.classList.add('d-none');
      pickerEl?.classList.remove('d-none');
      renderManualJdPicker(wrap, pool, criteria);
    }
    updateManualJdSummary();
  }

  async function populateManualJdSelect(wrap, selectedId = '') {
    await ensureJdSetSummariesLoaded();
    const sel = wrap.querySelector('[data-f="jdSetId"]');
    if (!sel) return;
    const sets = jdSetsForTestForm();
    const opts = sets.length
      ? sets.map((s) => {
        const label = formIsCompanyTest()
          ? `${s.jdTitle || 'Untitled'} (${s.questionCount || 0})`
          : `${s.companyName ? `${s.companyName} · ` : ''}${s.jdTitle || 'Untitled'} (${s.questionCount || 0})`;
        return `<option value="${esc(String(s.id))}" ${String(s.id) === String(selectedId) ? 'selected' : ''}>${esc(label)}</option>`;
      }).join('')
      : `<option value="">${formIsCompanyTest() && !selectedCompanyFromTestForm().companyId ? 'Select a company first' : 'No JD sets available'}</option>`;
    sel.innerHTML = opts;
  }

  async function refreshAllManualJdSelects() {
    const blocks = [...document.querySelectorAll('.tf-manual-jd-block')];
    await Promise.all(blocks.map(async (wrap) => {
      const current = wrap.querySelector('[data-f="jdSetId"]')?.value || '';
      await populateManualJdSelect(wrap, current);
      refreshManualJdBlock(wrap);
    }));
    updateManualJdSummary();
  }

  function randomJdRuleFromWrap(wrap) {
    const setId = wrap.querySelector('[data-f="jdSetId"]')?.value || '';
    const selected = manualJdSetSummaries.find((s) => String(s.id) === String(setId));
    return {
      jdSetId: setId,
      jdTitle: selected?.jdTitle || wrap.querySelector('[data-f="jdSetId"] option:checked')?.textContent?.trim() || '',
      count: Math.max(1, Number(wrap.querySelector('[data-f="count"]')?.value || 1)),
      marks: Math.max(0.5, Number(wrap.querySelector('[data-f="marks"]')?.value || 1)),
    };
  }

  async function populateRandomJdSelect(wrap, selectedId = '') {
    await ensureJdSetSummariesLoaded();
    const sel = wrap.querySelector('[data-f="jdSetId"]');
    if (!sel) return;
    const sets = jdSetsForTestForm();
    const opts = sets.length
      ? sets.map((s) => {
        const label = formIsCompanyTest()
          ? `${s.jdTitle || 'Untitled'} (${s.questionCount || 0})`
          : `${s.companyName ? `${s.companyName} · ` : ''}${s.jdTitle || 'Untitled'} (${s.questionCount || 0})`;
        return `<option value="${esc(String(s.id))}" ${String(s.id) === String(selectedId) ? 'selected' : ''}>${esc(label)}</option>`;
      }).join('')
      : `<option value="">${formIsCompanyTest() && !selectedCompanyFromTestForm().companyId ? 'Select a company first' : 'No JD sets available'}</option>`;
    sel.innerHTML = opts;
  }

  function addRandomJdRuleRow(rule = {}) {
    randomJdRuleCounter += 1;
    const root = document.getElementById('tfRandomJdRules');
    if (!root) return;
    const count = Math.max(1, Number(rule.count ?? 1));
    const wrap = document.createElement('div');
    wrap.className = 'tf-random-jd-block border rounded-3 p-3 mb-2 bg-white';
    wrap.innerHTML = `
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label small mb-1">JD title</label>
          <select class="form-select form-select-sm" data-f="jdSetId"><option value="">Loading…</option></select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">No. of questions</label>
          <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(count)}"/>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Marks each</label>
          <input class="form-control form-control-sm" type="number" min="0.5" step="0.5" data-f="marks" value="${esc(rule.marks ?? 1)}"/>
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-random-jd-block>Remove</button>
        </div>
      </div>`;
    wrap.querySelector('[data-remove-random-jd-block]')?.addEventListener('click', () => {
      wrap.remove();
      updateRandomJdSummary();
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => updateRandomJdSummary());
      el.addEventListener('input', () => updateRandomJdSummary());
    });
    root.appendChild(wrap);
    populateRandomJdSelect(wrap, rule.jdSetId || '').then(() => updateRandomJdSummary());
  }

  function collectRandomJdRules() {
    return [...document.querySelectorAll('.tf-random-jd-block')].map((wrap) => randomJdRuleFromWrap(wrap));
  }

  function validateRandomJdRulesComplete() {
    const blocks = [...document.querySelectorAll('.tf-random-jd-block')];
    if (!blocks.length) return 'Add at least one JD random rule.';
    for (const wrap of blocks) {
      const c = randomJdRuleFromWrap(wrap);
      if (!c.jdSetId) return 'Select a JD title for each random rule.';
    }
    return '';
  }

  function updateRandomJdSummary() {
    const rules = collectRandomJdRules();
    const total = rules.reduce((sum, r) => sum + (Number(r.count) || 0), 0);
    const summary = document.getElementById('tfRandomJdSummary');
    if (summary) summary.textContent = `${total} question(s) from ${rules.length} JD rule(s)`;
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl && getQuestionSource() === 'random_jd') countEl.value = String(total || 1);
  }

  async function refreshAllRandomJdSelects() {
    const blocks = [...document.querySelectorAll('.tf-random-jd-block')];
    await Promise.all(blocks.map(async (wrap) => {
      const current = wrap.querySelector('[data-f="jdSetId"]')?.value || '';
      await populateRandomJdSelect(wrap, current);
    }));
    updateRandomJdSummary();
  }

  function addManualJdRuleRow(rule = {}) {
    manualJdRuleCounter += 1;
    const root = document.getElementById('tfManualJdRules');
    if (!root) return;
    const selectedIds = (rule.selectedQuestionIds || []).map(String);
    const count = Math.max(1, Number(rule.count ?? 1));
    const wrap = document.createElement('div');
    wrap.className = 'tf-manual-jd-block border rounded-3 p-3 mb-2 bg-white';
    wrap.innerHTML = `
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label small mb-1">JD title</label>
          <select class="form-select form-select-sm" data-f="jdSetId"><option value="">Loading…</option></select>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">No. of questions</label>
          <input class="form-control form-control-sm" type="number" min="1" data-f="count" value="${esc(count)}"/>
        </div>
        <div class="col-md-2">
          <label class="form-label small mb-1">Marks each</label>
          <input class="form-control form-control-sm" type="number" min="0.5" step="0.5" data-f="marks" value="${esc(rule.marks ?? 1)}"/>
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-jd-block>Remove</button>
        </div>
      </div>
      <div class="manual-jd-error small text-danger mt-2 d-none"></div>
      <div class="manual-jd-picker mt-2"></div>
      <div class="manual-jd-summary border rounded-2 p-2 mt-2 d-none"></div>`;
    wrap._manualJdState = {
      selectedIds: new Set(selectedIds),
      collapsed: selectedIds.length >= count && selectedIds.length > 0,
      editing: false,
      criteriaKey: '',
    };
    wrap.querySelector('[data-remove-jd-block]')?.addEventListener('click', () => {
      wrap.remove();
      updateManualJdSummary();
    });
    wrap.querySelectorAll('[data-f]').forEach((el) => {
      el.addEventListener('change', () => refreshManualJdBlock(wrap));
      el.addEventListener('input', () => refreshManualJdBlock(wrap));
    });
    root.appendChild(wrap);
    populateManualJdSelect(wrap, rule.jdSetId || '').then(() => refreshManualJdBlock(wrap));
  }

  function collectManualJdRules() {
    return [...document.querySelectorAll('.tf-manual-jd-block')].map((wrap) => ({
      ...manualJdRuleFromWrap(wrap),
      selectedQuestionIds: [...(wrap._manualJdState?.selectedIds || [])],
    }));
  }

  function validateManualJdRulesComplete() {
    const blocks = [...document.querySelectorAll('.tf-manual-jd-block')];
    if (!blocks.length) return 'Add at least one JD rule.';
    for (const wrap of blocks) {
      const c = manualJdRuleFromWrap(wrap);
      const state = wrap._manualJdState;
      if (!c.jdSetId) return 'Select a JD title for each JD rule.';
      if (!state || state.selectedIds.size !== c.count) {
        return `Select exactly ${c.count} question(s) for ${c.jdTitle || 'the JD set'} (${state?.selectedIds.size || 0} selected).`;
      }
    }
    return '';
  }

  function sumManualJdQuestionCount() {
    const blocks = [...document.querySelectorAll('.tf-manual-jd-block')];
    if (!blocks.length) return 0;
    let selectedTotal = 0;
    let hasSelection = false;
    blocks.forEach((wrap) => {
      const picked = wrap._manualJdState?.selectedIds?.size || 0;
      if (picked > 0) {
        hasSelection = true;
        selectedTotal += picked;
      }
    });
    if (hasSelection) return selectedTotal;
    return blocks.reduce((sum, wrap) => sum + (Number(manualJdRuleFromWrap(wrap).count) || 0), 0);
  }

  function updateManualJdSummary() {
    const rules = collectManualJdRules();
    const jdTotal = sumManualJdQuestionCount();
    const complete = rules.filter((r, i) => {
      const wrap = document.querySelectorAll('.tf-manual-jd-block')[i];
      return wrap?._manualJdState?.selectedIds?.size === r.count;
    }).length;
    const summary = document.getElementById('tfManualJdSummary');
    if (summary) {
      summary.textContent = `${jdTotal} question(s) from ${rules.length} JD set${rules.length === 1 ? '' : 's'} · ${complete}/${rules.length} complete`;
    }
    updateTestFormQuestionCount();
  }

  function updateTestFormQuestionCount() {
    const source = getQuestionSource();
    const countEl = document.getElementById('tfQuestionCount');
    if (!countEl) return;
    if (source === 'random_jd') {
      updateRandomJdSummary();
      return;
    }
    if (source === 'random') {
      updateRandomSummary();
      return;
    }
    const company = formIsCompanyTest();
    const useBank = !company && document.getElementById('tfUseBankManual')?.checked;
    const useJd = company || document.getElementById('tfUseJdManual')?.checked;
    const bankTotal = useBank
      ? collectManualBankRules().reduce((sum, r) => sum + (Number(r.count) || 0), 0)
      : 0;
    const jdTotal = useJd ? sumManualJdQuestionCount() : 0;
    const mcqCount = company ? 0 : collectMcqs().length;
    countEl.value = String(bankTotal + jdTotal + mcqCount || 1);
  }

  function updateRandomSummary() {
    const rules = collectRandomRules();
    const total = rules.reduce((sum, r) => sum + (Number(r.count) || 0), 0);
    const summary = document.getElementById('tfRandomSummary');
    if (summary) summary.textContent = `${total} question(s) from ${rules.length} rule(s)`;
    const countEl = document.getElementById('tfQuestionCount');
    if (countEl && getQuestionSource() === 'random') countEl.value = String(total || 1);
  }

  function updateManualBankSummary() {
    const rules = collectManualBankRules();
    const bankTotal = rules.reduce((sum, r) => sum + (Number(r.count) || 0), 0);
    const complete = rules.filter((r, i) => {
      const wrap = document.querySelectorAll('.tf-manual-bank-block')[i];
      return wrap?._manualRuleState?.selectedIds?.size === r.count;
    }).length;
    const summary = document.getElementById('tfManualBankSummary');
    if (summary) {
      summary.textContent = `${bankTotal} question(s) from ${rules.length} categor${rules.length === 1 ? 'y' : 'ies'} · ${complete}/${rules.length} complete`;
    }
    updateTestFormQuestionCount();
  }

  function inferBankRulesFromQuestions(questions) {
    const groups = new Map();
    (questions || []).filter((q) => q?.bankId).forEach((q) => {
      const category = String(q.category || 'General Aptitude');
      const difficulty = normalizeDifficulty(q.difficulty || 'Medium');
      const marks = Number(q.marks ?? 1) || 1;
      const key = `${category}|${difficulty}|${marks}`;
      if (!groups.has(key)) {
        groups.set(key, { category, difficulty, count: 0, marks, selectedQuestionIds: [] });
      }
      const g = groups.get(key);
      g.count += 1;
      g.selectedQuestionIds.push(String(q.bankId || q.id || ''));
    });
    return [...groups.values()];
  }

  function updateManualQuestionCount() {
    if (getQuestionSource() !== 'manual') return;
    updateManualBankSummary();
  }

  function applyRuleMarksToQuestion(q, rule) {
    const marks = Math.max(0.5, Number(rule?.marks) || 0);
    if (marks > 0) return { ...q, marks };
    return q;
  }

  function demoResolveBankWithPreferred(rules, preferredIds = []) {
    ensureDemoBankSeed();
    const all = loadDemoBankStore();
    const preferred = preferredIds
      .map((id) => all.find((q) => String(q.id || q.bankId) === String(id)))
      .filter(Boolean);
    const used = new Set();
    const picked = [];

    rules.forEach((rule) => {
      const count = Math.max(0, Number(rule.count) || 0);
      if (!count) return;

      const selectedIds = (rule.selectedQuestionIds || []).map(String).filter(Boolean);
      if (selectedIds.length) {
        if (selectedIds.length !== count) {
          throw new Error(`Select exactly ${count} question(s) for ${rule.category} — ${rule.difficulty}.`);
        }
        selectedIds.forEach((sid) => {
          if (used.has(sid)) {
            throw new Error('The same bank question cannot be used in more than one category row.');
          }
          const q = all.find((item) => String(item.id || item.bankId || '') === sid);
          if (!q || !bankQuestionMatchesRule(q, rule)) {
            throw new Error(`Selected question does not match ${rule.category} — ${rule.difficulty}.`);
          }
          used.add(sid);
          picked.push(applyRuleMarksToQuestion({ ...q, bankId: sid }, rule));
        });
        return;
      }

      const ruleManual = [];
      preferred.forEach((q) => {
        if (ruleManual.length >= count) return;
        const id = String(q.id || q.bankId || '');
        if (!id || used.has(id) || !bankQuestionMatchesRule(q, rule)) return;
        ruleManual.push(q);
      });
      ruleManual.forEach((q) => {
        const id = String(q.id || q.bankId || '');
        used.add(id);
        picked.push(applyRuleMarksToQuestion({ ...q, bankId: id }, rule));
      });

      const stillNeed = count - ruleManual.length;
      if (stillNeed > 0) {
        let pool = all.filter((q) => {
          const id = String(q.id || q.bankId || '');
          if (used.has(id) || !bankQuestionMatchesRule(q, rule)) return false;
          return true;
        });
        if (pool.length < stillNeed) {
          throw new Error(`Not enough ${rule.difficulty} questions in ${rule.category} (need ${stillNeed} more, found ${pool.length}).`);
        }
        pool = pool.sort(() => Math.random() - 0.5).slice(0, stillNeed);
        pool.forEach((q) => {
          const id = String(q.id || q.bankId || '');
          used.add(id);
          picked.push(applyRuleMarksToQuestion({ ...q, bankId: id }, rule));
        });
      }
    });

    return picked;
  }

  function demoPickRandomJdRules(rules) {
    const picked = [];
    const used = new Set();
    const all = loadDemoJdStore();
    rules.forEach((rule) => {
      const set = all.find((s) => String(s.id) === String(rule.jdSetId));
      if (!set) throw new Error(`JD set not found: ${rule.jdTitle || rule.jdSetId}`);
      const count = Math.max(1, Number(rule.count) || 1);
      let pool = (set.questions || []).filter((q) => {
        const id = String(q.id || '');
        return id && !used.has(`${set.id}:${id}`);
      });
      if (pool.length < count) {
        throw new Error(`Not enough questions in ${rule.jdTitle || set.jdTitle} (need ${count}, found ${pool.length}).`);
      }
      pool = pool.sort(() => Math.random() - 0.5).slice(0, count);
      pool.forEach((q) => {
        const id = String(q.id || '');
        used.add(`${set.id}:${id}`);
        picked.push({
          ...q,
          marks: Number(rule.marks) || 1,
          jdSetId: String(set.id),
          jdTitle: set.jdTitle || rule.jdTitle || '',
          source: 'AI_JD',
        });
      });
    });
    return picked;
  }

  function demoPickRandomRules(rules) {
    const picked = [];
    const used = new Set();
    ensureDemoBankSeed();
    const all = loadDemoBankStore();
    rules.forEach((rule) => {
      let pool = all.filter((q) => {
        const id = String(q.id || q.bankId || '');
        if (used.has(id)) return false;
        if (!bankQuestionMatchesRule(q, rule)) return false;
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
        picked.push(applyRuleMarksToQuestion({ ...q, bankId: id }, rule));
      });
    });
    return picked;
  }

  function resolveDemoTestQuestions(payload) {
    if (payload.questionSource === 'random_jd') {
      const rules = payload.jdFilterRules || [];
      if (!rules.length) throw new Error('Add at least one JD random rule.');
      payload.questions = demoPickRandomJdRules(rules);
      payload.questionCount = payload.questions.length;
      payload.bankQuestionIds = [];
      payload.randomRules = [];
      payload.category = payload.questions[0]?.category || 'General Aptitude';
      payload.difficulty = payload.questions[0]?.difficulty || 'Medium';
      return payload;
    }
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
    const rules = payload.bankFilterRules || [];
    const all = loadDemoBankStore();
    let fromBank = [];
    if (rules.length) {
      fromBank = demoResolveBankWithPreferred(rules, bankIds);
    } else if (bankIds.length) {
      fromBank = bankIds.map((id) => all.find((q) => String(q.id) === String(id))).filter(Boolean);
    }
    const jdRules = payload.jdFilterRules || [];
    let fromJd = [];
    if (jdRules.length) fromJd = demoResolveJdWithPreferred(jdRules);
    const inline = payload.questions || [];
    payload.questions = [...fromBank, ...fromJd, ...inline];
    payload.questionCount = payload.questions.length;
    payload.bankQuestionIds = fromBank.map((q) => String(q.bankId || q.id || '')).filter(Boolean);
    if (!payload.questions.length) throw new Error('Add bank rules, JD questions, or add MCQs.');
    payload.category = fromBank[0]?.category || fromJd[0]?.category || inline[0]?.category || 'General Aptitude';
    payload.difficulty = fromBank[0]?.difficulty || fromJd[0]?.difficulty || inline[0]?.difficulty || 'Medium';
    return payload;
  }

  function canManageContests() {
    return typeof Auth.canManageAptitudeContests === 'function' && Auth.canManageAptitudeContests();
  }

  let managePanel = 'tests';

  function isContestTest(t) {
    const type = String(t?.contestType || 'none');
    return type === 'weekly' || type === 'monthly';
  }

  function isCompanyTest(t) {
    return String(t?.testKind || '') === 'company';
  }

  function isRegularTest(t) {
    return !isContestTest(t) && !isCompanyTest(t);
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
    } else if (panel === 'company-tests') {
      managePanel = 'company-tests';
    } else if (panel === 'bank') {
      managePanel = 'bank';
    } else if (panel === 'jd') {
      managePanel = 'jd';
    } else {
      managePanel = 'tests';
    }
    document.getElementById('manageTestsPanel')?.classList.toggle('d-none', managePanel !== 'tests');
    document.getElementById('manageContestsPanel')?.classList.toggle('d-none', managePanel !== 'contests');
    document.getElementById('manageCompanyTestsPanel')?.classList.toggle('d-none', managePanel !== 'company-tests');
    document.getElementById('manageBankPanel')?.classList.toggle('d-none', managePanel !== 'bank');
    document.getElementById('manageJdPanel')?.classList.toggle('d-none', managePanel !== 'jd');
    document.querySelectorAll('#manageViewNav .nav-link').forEach((link) => {
      link.classList.toggle('active', link.getAttribute('data-manage-view') === managePanel);
    });
    if (managePanel === 'contests') applyManageContestType(manageContestType);
    if (managePanel === 'bank') loadQuestionBank().catch(() => {});
    if (managePanel === 'jd') loadJdLibrary().catch(() => {});
  }

  function syncManageContestActions() {
    const show = canManageContests();
    document.getElementById('manageContestNavItem')?.classList.toggle('d-none', !show);
    if (!show && managePanel === 'contests') applyManagePanel('tests');
  }

  function isContestManageActive(test) {
    const status = contestStatusClient(test);
    return status === 'ACTIVE' || status === 'UPCOMING';
  }

  function renderManageContestSections(contestType, listRoot) {
    const all = tests.filter((t) => String(t.contestType) === contestType);
    const active = all.filter((t) => isContestManageActive(t));
    const label = contestType === 'monthly' ? 'monthly' : 'weekly';

    if (!listRoot) return;
    listRoot.innerHTML = renderManageContestActiveTable(active, `No active ${label} contests.`);
    bindManageListActions(listRoot);
  }

  function contestScheduleControls(t, { inline = false } = {}) {
    const type = String(t?.contestType || 'none');
    const id = esc(t.id);
    const wrapCls = inline ? 'd-flex flex-wrap align-items-center gap-2' : 'd-flex flex-wrap align-items-center gap-2 mt-2';
    if (type === 'weekly') {
      const current = Number(t.contestWeekday) || 1;
      const opts = CONTEST_WEEKDAYS.map((d) =>
        `<option value="${d.value}" ${d.value === current ? 'selected' : ''}>${esc(d.label)}</option>`
      ).join('');
      return `<div class="${wrapCls}">
        <label class="small text-muted-2 mb-0" for="contest-day-${id}">Runs every</label>
        <select class="form-select form-select-sm" id="contest-day-${id}" style="width:auto;min-width:9rem" data-contest-schedule="${id}" data-schedule-field="contestWeekday">${opts}</select>
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
      </div>`;
    }
    return '';
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

    return `<tr>
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
    if (!contests.length) {
      return `<p class="text-muted-2 mb-0">${emptyMsg}</p>`;
    }
    return `<div class="table-wrap mb-0"><table class="table-modern table-sm mb-0"><thead><tr>
      <th>Title</th><th>Details</th><th>Status</th><th>Schedule</th><th>Actions</th>
    </tr></thead><tbody>${contests.map((t) => renderManageContestRow(t)).join('')}</tbody></table></div>`;
  }

  function companyTestBadgeHtml(t) {
    if (!isCompanyTest(t)) return '';
    const name = esc(t.companyName || 'Company');
    return `<span class="badge text-bg-light border mt-1">Company · ${name}</span>`;
  }

  function renderManageRow(t, { showContestBadge = false, showCompanyBadge = false } = {}) {
    return `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
          <strong>${esc(t.title)}</strong>
          <div class="small text-muted-2">${(t.status || 'unpublished') === 'published' ? 'Published' : 'Unpublished (hidden from students)'} · ${testMetaLine(t)}</div>
          ${showCompanyBadge ? companyTestBadgeHtml(t) : ''}
          ${showContestBadge ? contestBadgeHtml(t) : ''}
          ${showContestBadge ? contestScheduleControls(t) : ''}
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-edit="${esc(t.id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-delete-test="${esc(t.id)}">Delete</button>
        </div>
      </div>`;
  }

  function bindManageListActions(root) {
    if (!root) return;
    root.querySelectorAll('[data-view-contest-results]').forEach((btn) => {
      btn.addEventListener('click', () => openContestResultsPreview(btn.getAttribute('data-view-contest-results')));
    });
    root.querySelectorAll('[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = tests.find((x) => String(x.id) === String(btn.getAttribute('data-edit')));
        if (t) openTestForm(t);
      });
    });
    root.querySelectorAll('[data-delete-test]').forEach((btn) => {
      btn.addEventListener('click', () => deleteTest(btn.getAttribute('data-delete-test')));
    });
    root.querySelectorAll('[data-publish-results]').forEach((btn) => {
      btn.addEventListener('click', () => setContestResultsPublished(btn.getAttribute('data-publish-results'), true));
    });
    root.querySelectorAll('[data-unpublish-results]').forEach((btn) => {
      btn.addEventListener('click', () => setContestResultsPublished(btn.getAttribute('data-unpublish-results'), false));
    });
    root.querySelectorAll('[data-contest-schedule]').forEach((sel) => {
      sel.addEventListener('change', () => {
        saveContestSchedule(
          sel.getAttribute('data-contest-schedule'),
          sel.getAttribute('data-schedule-field'),
          sel.value
        );
      });
    });
  }

  function isContestOpenClient(test) {
    if (test?.contestStatus) return String(test.contestStatus) === 'ACTIVE';
    if (test && typeof test.contestOpen === 'boolean') return test.contestOpen;
    return contestStatusClient(test) === 'ACTIVE';
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
    const id = document.getElementById('tfId')?.value.trim() || '';
    const existing = (id && tests.find((t) => String(t.id) === String(id)))
      || (id && loadDemoTestsStore().find((t) => String(t.id) === String(id)))
      || null;
    const type = existing && isContestTest(existing)
      ? String(existing.contestType)
      : (document.getElementById('tfContestType')?.value || 'none');
    const payload = { contestType: type };
    if (type === 'weekly') {
      payload.contestWeekday = Number(
        existing?.contestWeekday
        || document.getElementById('tfContestWeekday')?.value
        || 1
      );
    } else if (type === 'monthly') {
      payload.contestMonthDay = Number(
        existing?.contestMonthDay
        || document.getElementById('tfContestMonthDay')?.value
        || 1
      );
    }
    return payload;
  }

  function contestBadgeHtml(t) {
    const type = String(t?.contestType || 'none');
    if (type === 'none') return '';
    const life = contestStatusClient(t);
    const lifeCls = life === 'ACTIVE' ? 'success' : (life === 'COMPLETED' ? 'warning' : 'muted');
    const lifeLabel = life === 'ACTIVE' ? 'Active' : (life === 'COMPLETED' ? 'Completed' : 'Upcoming');
    return `<div class="mt-1 d-flex flex-wrap gap-1">
      <span class="badge-soft info">${esc(type === 'monthly' ? 'Monthly contest' : 'Weekly contest')}</span>
      <span class="badge-soft ${lifeCls}">${esc(lifeLabel)}</span>
    </div>`;
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
    const id = String(h?.testId || '');
    const title = String(h?.testTitle || h?.testName || '').trim();
    const pools = [tests, loadDemoTestsStore()];
    for (const pool of pools) {
      if (!Array.isArray(pool)) continue;
      const byId = id ? pool.find((t) => String(t.id) === id) : null;
      if (byId) return byId;
      const byTitle = title ? pool.find((t) => String(t.title || '') === title) : null;
      if (byTitle) return byTitle;
    }
    return null;
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

  function resolveLocalAnswer(answers, qid, q, pos) {
    const keys = [String(qid), String(q?.bankId || ''), `q${pos + 1}`].filter(Boolean);
    for (const key of keys) {
      if (!Object.prototype.hasOwnProperty.call(answers, key)) continue;
      const raw = answers[key];
      if (raw != null && typeof raw === 'object') {
        if (Number.isFinite(Number(raw.index))) return Number(raw.index);
        const text = String(raw.option || '').trim().toLowerCase();
        if (text) {
          const opts = q.options || [];
          const hit = opts.findIndex((o) => String(o).trim().toLowerCase() === text);
          if (hit >= 0) return hit;
        }
        continue;
      }
      const idx = Number(raw);
      if (Number.isFinite(idx) && idx >= 0) return idx;
    }
    return -1;
  }

  function scoreLocally(test, questions, answers, metaPayload) {
    let marks = 0;
    let total = 0;
    let correct = 0;
    let wrong = 0;
    let unanswered = 0;
    const neg = test.negativeMarking ? Number(test.negativeMarks || 0) : 0;
    const analysis = [];
    const fullQuestions = fullDemoTest(test.id)?.questions || [];
    (questions || []).forEach((q, pos) => {
      const qid = String(q.id || q.bankId || `q${pos + 1}`);
      const full = fullQuestions.find((x) => String(x.id) === qid)
        || fullQuestions.find((x) => String(x.bankId || '') === String(q.bankId || ''))
        || fullQuestions[pos]
        || q;
      const qMarks = Number(full.marks ?? 1);
      total += qMarks;
      const picked = resolveLocalAnswer(answers, qid, q, pos);
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
        options: opts,
        studentAnswerIndex: picked >= 0 ? picked : null,
        correctAnswerIndex: correctIndex >= 0 ? correctIndex : null,
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
    return applyContestResultView(test, result);
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

    if (view === 'take' && access.canTake) {
      setupTakeListNav();
      await loadTests();
      await loadMyProgress();
      if (takeListPanel === 'jdblock') {
        await loadStudentJdBlock();
      } else {
        renderTestList();
      }
    }
    if (view === 'progress' && access.canViewDirectory) {
      await Promise.all([initDirFilters(), loadDirectory()]);
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
      tests = [];
      toast(res?.message || 'Could not load aptitude tests from the server.', 'error');
      return;
    }
    tests = loadDemoTestsStore().map((t) => {
      const copy = JSON.parse(JSON.stringify(t));
      if (!access.canManage && typeof AptitudeExam !== 'undefined' && AptitudeExam.stripExamQuestions) {
        copy.questions = AptitudeExam.stripExamQuestions(copy.questions || []);
      } else if (!access.canManage) {
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
    const filtered = hist.filter((h) => {
      if (myResultsPanel === 'contests') {
        return historyEntryIsContest(h) && contestTypeOf(h) === myResultsContestType;
      }
      return !historyEntryIsContest(h);
    });
    const canReview = access.canTake;
    const contestLabel = myResultsContestType === 'monthly' ? 'monthly' : 'weekly';
    const emptyLabel = myResultsPanel === 'contests'
      ? `No ${contestLabel} contest attempts yet.`
      : 'No attempts yet. Select a test on the left to begin.';
    document.getElementById('myHistory').innerHTML = filtered.length
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

  function bestHistoryForTest(testId) {
    const rows = (myProgress.history || []).filter((h) => String(h.testId || '') === String(testId));
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
    if (isContestTest(t) && !t.resultsPublished && !access.canManage && !access.canViewDirectory) {
      return '—';
    }
    if (mine && historyResultMode({ ...mine, contestType: t.contestType, testId: t.id }) !== 'pending') {
      const minePct = Number(mine.percentage);
      if (Number.isFinite(minePct)) return `${minePct}%`;
    }
    if (isContestTest(t) && !t.resultsPublished && !access.canManage) return '—';
    const avg = Number(t.averagePercentage);
    if (Number.isFinite(avg)) return `${avg}%`;
    return '—';
  }

  function renderTestList() {
    const root = document.getElementById('testList');
    if (!root) return;
    const wantContests = takeListPanel === 'contests';
    let visible = tests.filter((t) => {
      const isContest = isContestTest(t);
      if (wantContests !== isContest) return false;
      if (!access.canManage && !wantContests && isCompanyTest(t)) return false;
      if (isContest && String(t.contestType || '') !== takeContestType) return false;
      if (access.canManage) return true;
      if ((t.status || 'published') !== 'published') return false;
      if (isContest) {
        if (!isContestOpenClient(t)) return false;
        if (t.alreadyAttempted) return false;
        if (bestHistoryForTest(t.id) && !t.attemptInProgress) return false;
      }
      return true;
    });
    if (!visible.length) {
      const contestLabel = takeContestType === 'monthly' ? 'monthly' : 'weekly';
      const msg = wantContests
        ? (Auth.role() === 'student'
          ? `No ${contestLabel} contests are open today, or you have already taken them.`
          : `No ${contestLabel} aptitude contests are available yet.`)
        : (Auth.role() === 'student'
          ? 'No aptitude mocks are published yet. Check back later or contact your placement officer.'
          : 'No published aptitude tests yet.');
      root.innerHTML = `<p class="text-muted-2 mb-0 px-3 px-md-4 pb-3">${msg}</p>`;
      return;
    }
    root.innerHTML = `<div class="apt-prob-list">${visible.map((t, i) => {
      const mine = bestHistoryForTest(t.id);
      const solved = !!mine;
      const diff = difficultyListLabel(t.difficulty);
      const published = (t.status || 'published') === 'published';
      const openNow = !isContestTest(t) || isContestOpenClient(t);
      const alreadyDone = isContestTest(t) && (t.alreadyAttempted || (!!mine && !t.attemptInProgress));
      const canOpen = access.canTake && published && openNow && !alreadyDone;
      const tag = canOpen ? 'button' : 'div';
      const extra = canOpen ? ` type="button" data-open-test="${esc(t.id)}"` : '';
      let title = isContestTest(t) && !openNow
        ? `${t.title} · ${contestScheduleLabel(t)}`
        : t.title;
      if (isCompanyTest(t) && t.companyName) {
        title = `${title} · ${t.companyName}`;
      }
      return `<${tag} class="apt-prob-row ${canOpen ? 'is-clickable' : ''}"${extra}>
        <span class="apt-prob-check">${solved ? '<i class="bi bi-check-lg"></i>' : ''}</span>
        <span class="apt-prob-title">${i + 1}. ${esc(title)}</span>
        <span class="apt-prob-pct">${esc(formatListPercentage(t, mine))}</span>
        <span class="apt-prob-diff ${diff.cls}">${esc(diff.text)}</span>
      </${tag}>`;
    }).join('')}</div>`;
    root.querySelectorAll('[data-open-test]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = tests.find((x) => String(x.id) === String(btn.getAttribute('data-open-test')));
        if (t) openExam(t);
      });
    });
  }

  function openExam(test) {
    if (access.canTake && isContestTest(test)) {
      const life = contestStatusClient(test);
      if (life === 'UPCOMING') {
        toast('This contest is not open yet.', 'info');
        return;
      }
      if (life === 'COMPLETED') {
        toast('This contest has ended.', 'info');
        return;
      }
      if (!isContestOpenClient(test)) {
        toast('This contest is not open today.', 'info');
        return;
      }
      if (test.alreadyAttempted || (bestHistoryForTest(test.id) && !test.attemptInProgress)) {
        toast('You can take this contest only once.', 'info');
        return;
      }
    }
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
    const isCompanyPreset = preset?.testKind === 'company' || isCompanyTest(test);
    const isContest = isContestTest(test) || isContestPreset;
    document.getElementById('testFormTitle').textContent = test
      ? (isContest ? 'Edit contest' : (isCompanyPreset ? 'Edit company test' : 'Edit aptitude test'))
      : (isContestPreset ? `New ${preset.contestType} contest` : (isCompanyPreset ? 'New company test' : 'New aptitude test'));
    document.getElementById('tfTestKind').value = isCompanyPreset ? 'company' : 'regular';
    document.getElementById('tfId').value = test?.id || '';
    document.getElementById('tfTitle').value = test?.title || preset?.title || '';
    document.getElementById('tfDescription').value = test?.description || '';
    document.getElementById('tfQuestionCount').value = test?.questionCount || (test?.questions || []).length || 10;
    document.getElementById('tfDuration').value = test?.durationMinutes || 30;
    document.getElementById('tfStatus').value = test
      ? (test.status === 'unpublished' ? 'unpublished' : 'published')
      : 'published';

    let source = test?.questionSource === 'random_jd'
      ? 'random_jd'
      : (test?.questionSource === 'random' ? 'random' : 'manual');
    if (isContest) {
      source = 'random';
    } else if (isCompanyPreset && test?.questionSource !== 'random_jd') {
      source = 'manual';
    }
    document.getElementById('tfSourceManual').checked = source === 'manual';
    document.getElementById('tfSourceRandom').checked = source === 'random' || source === 'random_jd';

    document.getElementById('tfRandomRules').innerHTML = '';
    document.getElementById('tfRandomJdRules').innerHTML = '';
    randomJdRuleCounter = 0;
    const rules = test?.randomRules?.length
      ? test.randomRules
      : [{ category: 'General Aptitude', difficulty: 'Medium', count: 5, marks: 1 }];
    if (source === 'random') rules.forEach((r) => addRandomRuleRow(r));
    let jdRules = test?.jdFilterRules?.length ? test.jdFilterRules : [];
    if (!jdRules.length && source === 'manual' && (test?.questions || []).some((q) => q.jdSetId)) {
      jdRules = inferJdRulesFromQuestions(test?.questions || []);
    }
    if (isCompanyPreset && source === 'random_jd') {
      fillTfCompanySelect(test?.companyId || '').then(() => {
        ensureJdSetSummariesLoaded().then(() => {
          (jdRules.length ? jdRules : [{}]).forEach((r) => addRandomJdRuleRow(r));
          updateRandomJdSummary();
        }).catch(() => {
          (jdRules.length ? jdRules : [{}]).forEach((r) => addRandomJdRuleRow(r));
        });
      });
    }

    document.getElementById('tfManualBankRules').innerHTML = '';
    manualBankRuleCounter = 0;
    manualBankAllQuestions = [];
    let bankRules = test?.bankFilterRules?.length ? test.bankFilterRules : [];
    if (!bankRules.length && source === 'manual' && (test?.bankQuestionIds || []).length) {
      bankRules = inferBankRulesFromQuestions(test?.questions || []);
    }
    const useBank = bankRules.length > 0;
    document.getElementById('tfUseBankManual').checked = useBank;
    document.getElementById('tfBankPicker')?.classList.toggle('d-none', !useBank);
    if (useBank) {
      ensureManualBankQuestionsLoaded().then(() => {
        (bankRules.length ? bankRules : [{ category: 'General Aptitude', difficulty: 'Medium', count: 5, marks: 1 }])
          .forEach((r) => addManualBankRuleRow(r));
        updateManualBankSummary();
      }).catch(() => {
        (bankRules.length ? bankRules : [{ category: 'General Aptitude', difficulty: 'Medium', count: 5, marks: 1 }])
          .forEach((r) => addManualBankRuleRow(r));
      });
    }

    document.getElementById('tfManualJdRules').innerHTML = '';
    manualJdRuleCounter = 0;
    manualJdSetSummaries = [];
    const useJd = !isCompanyPreset && (jdRules.length > 0);
    document.getElementById('tfUseJdManual').checked = useJd || (isCompanyPreset && source === 'manual');
    document.getElementById('tfJdPicker')?.classList.toggle('d-none', isCompanyPreset ? source !== 'manual' : !useJd);
    if (isCompanyPreset && source === 'manual') {
      fillTfCompanySelect(test?.companyId || '').then(() => {
        ensureJdSetSummariesLoaded().then(() => {
          document.getElementById('tfManualJdRules').innerHTML = '';
          manualJdRuleCounter = 0;
          (jdRules.length ? jdRules : [{}]).forEach((r) => addManualJdRuleRow(r));
          updateManualJdSummary();
        }).catch(() => {
          (jdRules.length ? jdRules : [{}]).forEach((r) => addManualJdRuleRow(r));
        });
      });
    } else if (useJd) {
      ensureJdSetSummariesLoaded().then(() => {
        jdRules.forEach((r) => addManualJdRuleRow(r));
        updateManualJdSummary();
      }).catch(() => {
        jdRules.forEach((r) => addManualJdRuleRow(r));
      });
    }

    const contestType = test?.contestType || preset?.contestType || 'none';
    document.getElementById('tfContestType').value = ['weekly', 'monthly'].includes(contestType) ? contestType : 'none';
    document.getElementById('tfContestWeekday').value = String(test?.contestWeekday || preset?.contestWeekday || 1);
    document.getElementById('tfContestMonthDay').value = String(test?.contestMonthDay || preset?.contestMonthDay || 1);
    syncQuestionSourcePanels();
    document.getElementById('tfBulkFile').value = '';
    document.getElementById('mcqList').innerHTML = '';
    const qs = source === 'manual' ? (test?.questions || []).filter((q) => !q.bankId && !q.jdSetId) : [];
    if (qs.length) qs.forEach((q) => addMcqRow(q));
    updateManualBankSummary();
    updateTestFormQuestionCount();
    testFormModal.show();
  }

  function collectTestFormPayload() {
    const source = getQuestionSource();
    const payload = {
      title: document.getElementById('tfTitle').value.trim(),
      description: document.getElementById('tfDescription').value.trim(),
      questionCount: Number(document.getElementById('tfQuestionCount').value || 0),
      durationMinutes: Number(document.getElementById('tfDuration').value || 30),
      negativeMarking: false,
      negativeMarks: 0,
      status: document.getElementById('tfStatus').value,
      questionSource: source,
      instructions: '',
    };
    if (source === 'random_jd') {
      payload.jdFilterRules = collectRandomJdRules();
      payload.randomRules = [];
      payload.questions = [];
      payload.bankQuestionIds = [];
      payload.bankFilterRules = [];
    } else if (source === 'random') {
      payload.randomRules = collectRandomRules();
      payload.questions = [];
      payload.bankQuestionIds = [];
      payload.jdFilterRules = [];
    } else {
      payload.randomRules = [];
      const useBank = document.getElementById('tfUseBankManual')?.checked;
      payload.bankFilterRules = useBank ? collectManualBankRules() : [];
      payload.bankQuestionIds = useBank
        ? payload.bankFilterRules.flatMap((r) => r.selectedQuestionIds || [])
        : [];
      const useJd = document.getElementById('tfUseJdManual')?.checked;
      payload.jdFilterRules = useJd ? collectManualJdRules() : [];
      payload.questions = collectMcqs();
    }
    payload.testKind = formIsCompanyTest() ? 'company' : 'regular';
    if (formIsCompanyTest()) {
      const { companyId, companyName } = selectedCompanyFromTestForm();
      payload.companyId = companyId;
      payload.companyName = companyName;
      payload.contestType = 'none';
    }
    if (!payload.category) {
      const fromQuestions = (payload.questions || []).map((q) => String(q.category || '').trim()).find(Boolean);
      payload.category = fromQuestions
        || (payload.bankFilterRules?.[0]?.category)
        || (payload.randomRules?.[0]?.category)
        || 'General Aptitude';
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
    const help = document.getElementById('bulkHelpText');
    if (help) {
      help.innerHTML = mode === 'bank'
        ? 'Upload an Excel workbook (.xlsx or .xls). First row must be headers: <code>prompt, optionA, optionB, optionC, optionD, correct, explanation, category, difficulty</code>. Marks are set when you build a test, not in the bank. Use sheets named <strong>Easy</strong>, <strong>Medium</strong>, and <strong>Hard</strong> for difficulty buckets, or set <code>difficulty</code> per row.'
        : 'Upload an Excel workbook (.xlsx or .xls). First row must be headers: <code>prompt, optionA, optionB, optionC, optionD, correct, marks, explanation, category, difficulty</code>. Use sheets named <strong>Easy</strong>, <strong>Medium</strong>, and <strong>Hard</strong> for difficulty buckets, or set <code>difficulty</code> per row.';
    }
    fillSelect(document.getElementById('bulkCategory'), meta.categories || APTITUDE_CATEGORIES, 'General Aptitude');
    document.getElementById('bulkFile').value = '';
    bulkModal.show();
  }

  const BULK_EXCEL_HEADERS = ['prompt', 'optionA', 'optionB', 'optionC', 'optionD', 'correct', 'marks', 'explanation', 'category', 'difficulty'];
  const BANK_BULK_EXCEL_HEADERS = ['prompt', 'optionA', 'optionB', 'optionC', 'optionD', 'correct', 'explanation', 'category', 'difficulty'];
  const BULK_SHEET_DIFFICULTIES = { easy: 'Easy', medium: 'Medium', hard: 'Hard' };

  function downloadExcelTemplate(forBank = false) {
    if (typeof XLSX === 'undefined') {
      toast('Excel library is still loading. Try again in a moment.', 'info');
      return;
    }
    const wb = XLSX.utils.book_new();
    const headers = forBank ? BANK_BULK_EXCEL_HEADERS : BULK_EXCEL_HEADERS;
    const samples = forBank
      ? {
        Easy: ['What is 2+2?', '3', '4', '5', '6', 'B', 'Basic arithmetic', 'Quantitative Aptitude', 'Easy'],
        Medium: ['If x+3=10, x=?', '5', '6', '7', '8', 'C', 'Linear equation', 'Quantitative Aptitude', 'Medium'],
        Hard: ['Train A 60km/h, B 90km/h, opposite. Total 450km. Meet in?', '2h', '3h', '4h', '5h', 'B', 'Relative speed', 'Quantitative Aptitude', 'Hard'],
      }
      : {
        Easy: ['What is 2+2?', '3', '4', '5', '6', 'B', 1, 'Basic arithmetic', 'Quantitative Aptitude', 'Easy'],
        Medium: ['If x+3=10, x=?', '5', '6', '7', '8', 'C', 2, 'Linear equation', 'Quantitative Aptitude', 'Medium'],
        Hard: ['Train A 60km/h, B 90km/h, opposite. Total 450km. Meet in?', '2h', '3h', '4h', '5h', 'B', 3, 'Relative speed', 'Quantitative Aptitude', 'Hard'],
      };
    Object.entries(samples).forEach(([sheetName, sample]) => {
      const ws = XLSX.utils.aoa_to_sheet([headers, sample]);
      XLSX.utils.book_append_sheet(wb, ws, sheetName);
    });
    XLSX.writeFile(wb, forBank ? 'aptitude-question-bank-template.xlsx' : 'aptitude-test-mcq-template.xlsx');
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

    const regular = tests.filter((t) => isRegularTest(t));
    const companyTests = tests.filter((t) => isCompanyTest(t));

    const testsRoot = document.getElementById('manageTestsList');
    if (testsRoot) {
      testsRoot.innerHTML = regular.length
        ? regular.map((t) => renderManageRow(t)).join('')
        : '<p class="text-muted-2 mb-0">No regular tests yet.</p>';
      bindManageListActions(testsRoot);
    }

    const companyRoot = document.getElementById('manageCompanyTestsList');
    if (companyRoot) {
      companyRoot.innerHTML = companyTests.length
        ? companyTests.map((t) => renderManageRow(t, { showCompanyBadge: true })).join('')
        : '<p class="text-muted-2 mb-0">No company tests yet. Create one from JD Block questions.</p>';
      bindManageListActions(companyRoot);
    }

    renderManageContestSections('weekly', document.getElementById('manageWeeklyContestsList'));
    renderManageContestSections('monthly', document.getElementById('manageMonthlyContestsList'));
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
    if (Auth.isDemo() || !(Auth.hasRealAuth() && !Auth.isDemo())) {
      const scored = (p.history || []).filter((h) => historyResultMode(h) !== 'pending');
      const scores = scored.map((h) => Number(h.percentage)).filter((n) => Number.isFinite(n));
      p.testsAttempted = (p.history || []).length;
      p.bestScore = scores.length ? Math.max(...scores) : 0;
      p.percentage = scores.length ? Math.round((scores.reduce((a, b) => a + b, 0) / scores.length) * 10) / 10 : 0;
      p.recentPerformance = scores[0] ?? 0;
      saveDemoProgress({ ...p, history: p.history });
    }
    myProgress = p;
    renderMyStats(p);
    renderHistory(p);
    renderTestList();
  }

  async function loadDirectory() {
    if (!access.canViewDirectory) return;
    const seq = ++dirLoadSeq;
    if (!(Auth.hasRealAuth() && !Auth.isDemo())) {
      const scope = {
        ...(access.scope || {}),
        assignedClassBatches: staffAssignedBatches(),
      };
      access.scope = scope;
      updateDirScopeHint(scope);
      if (progressPanel === 'contests') {
        const demo = demoContestResults();
        const completed = tests.filter((t) => isContestTest(t) && contestStatusClient(t) === 'COMPLETED');
        renderContestResults(demo.contests || [], demo.summary || {}, scope, completed);
      } else {
        const demo = demoDirectoryRows(progressPanel);
        renderDirectoryTable(demo.rows || [], demo.summary || {}, scope);
      }
      return;
    }
    const qs = buildDirectoryQuery();
    const cacheKey = qs.toString();
    let data = null;
    if (cacheKey === dirResultsCacheKey && dirResultsCache) {
      data = dirResultsCache;
    } else {
      const res = await api('/aptitude/progress?' + qs.toString()).catch(() => null);
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

  onAppReady(async () => {
    testFormModal = new bootstrap.Modal(document.getElementById('testFormModal'));
    bulkModal = new bootstrap.Modal(document.getElementById('bulkModal'));
    aptAiModal = document.getElementById('aptAiModal') ? new bootstrap.Modal(document.getElementById('aptAiModal')) : null;
    exam = AptitudeExam.createExamController({
      root: document.getElementById('examShell'),
      onExit: () => closeExam(),
      resolveDemoQuestions: (id) => {
        const t = fullDemoTest(id);
        const qs = t?.questions || [];
        return typeof AptitudeExam !== 'undefined' && AptitudeExam.normalizeExamQuestions
          ? AptitudeExam.normalizeExamQuestions(qs)
          : qs.map(({ correctIndex, explanation, ...q }, i) => ({ ...q, id: String(q.id || q.bankId || `q${i + 1}`) }));
      },
      scoreLocally,
    });

    studentAptModal = new bootstrap.Modal(document.getElementById('studentAptModal'));
    contestResultsModal = document.getElementById('contestResultsModal')
      ? new bootstrap.Modal(document.getElementById('contestResultsModal'))
      : null;
    await loadAccess();
    setupTakeListNav();
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
    document.getElementById('myResultsNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-view]');
      if (!link) return;
      e.preventDefault();
      applyMyResultsPanel(link.getAttribute('data-results-view'));
      renderHistory(myProgress);
    });
    document.getElementById('myResultsContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-results-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyMyResultsContestType(link.getAttribute('data-results-contest-type'));
    });
    document.getElementById('progressContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-progress-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyProgressContestType(link.getAttribute('data-progress-contest-type'));
    });

    document.getElementById('btnAddMcq')?.addEventListener('click', () => {
      addMcqRow();
      updateManualQuestionCount();
    });
    document.querySelectorAll('input[name="tfQuestionSource"]').forEach((el) => {
      el.addEventListener('change', syncQuestionSourcePanels);
    });
    document.getElementById('btnAddRandomRule')?.addEventListener('click', () => addRandomRuleRow());
    document.getElementById('btnAddManualBankRule')?.addEventListener('click', () => addManualBankRuleRow());
    document.getElementById('tfUseBankManual')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      document.getElementById('tfBankPicker')?.classList.toggle('d-none', !on);
      if (on) {
        ensureManualBankQuestionsLoaded().then(() => {
          const root = document.getElementById('tfManualBankRules');
          if (root && !root.querySelector('.tf-manual-bank-block')) {
            addManualBankRuleRow({ category: 'General Aptitude', difficulty: 'Medium', count: 5, marks: 1 });
          }
          updateManualQuestionCount();
        }).catch(() => updateManualQuestionCount());
      } else {
        updateManualQuestionCount();
      }
    });
    document.getElementById('tfUseJdManual')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      document.getElementById('tfJdPicker')?.classList.toggle('d-none', !on);
      if (on) {
        ensureJdSetSummariesLoaded().then(() => {
          const root = document.getElementById('tfManualJdRules');
          if (root && !root.querySelector('.tf-manual-jd-block')) addManualJdRuleRow();
          updateManualJdSummary();
        }).catch(() => updateManualJdSummary());
      } else {
        updateManualJdSummary();
      }
    });
    document.getElementById('btnAddManualJdRule')?.addEventListener('click', () => addManualJdRuleRow());
    document.getElementById('tfQuestionCount')?.addEventListener('input', () => updateManualQuestionCount());
    document.getElementById('btnFormBulkTemplate')?.addEventListener('click', () => downloadExcelTemplate(false));
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
    document.getElementById('btnNewCompanyTest')?.addEventListener('click', () => {
      applyManagePanel('company-tests');
      openTestForm(null, { testKind: 'company', contestType: 'none' });
    });
    document.getElementById('tfCompanyId')?.addEventListener('change', () => {
      if (!formIsCompanyTest()) return;
      refreshAllManualJdSelects().catch(() => {});
      refreshAllRandomJdSelects().catch(() => {});
    });
    document.getElementById('btnAddRandomJdRule')?.addEventListener('click', () => addRandomJdRuleRow());
    document.getElementById('btnNewWeeklyContest')?.addEventListener('click', () => {
      openManageContests('weekly');
      openTestForm(null, {
        contestType: 'weekly',
        contestWeekday: new Date().getDay() === 0 ? 7 : new Date().getDay(),
        title: 'Weekly aptitude contest',
      });
    });
    document.getElementById('btnNewMonthlyContest')?.addEventListener('click', () => {
      openManageContests('monthly');
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
    document.getElementById('manageContestTypeNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-contest-type]');
      if (!link) return;
      e.preventDefault();
      applyManageContestType(link.getAttribute('data-contest-type'));
    });
    document.getElementById('btnBankUploadPanel')?.addEventListener('click', () => openBulk('bank'));
    document.getElementById('btnBankAiGenerate')?.addEventListener('click', () => openAptAiModal());
    document.getElementById('btnJdAiGenerate')?.addEventListener('click', () => {
      const companyId = jdSelectedCompanyId && jdSelectedCompanyId !== '_unassigned' ? jdSelectedCompanyId : '';
      openAptAiModal({ jd: true, companyId });
    });
    document.getElementById('btnJdBlockBack')?.addEventListener('click', () => showJdCompanyGrid());
    document.getElementById('btnStudentJdBlockBack')?.addEventListener('click', () => showStudentJdCompanyGrid());
    document.getElementById('studentJdBlockViewNav')?.addEventListener('click', (e) => {
      const link = e.target.closest('[data-jd-block-view]');
      if (!link) return;
      e.preventDefault();
      applyStudentJdBlockView(link.getAttribute('data-jd-block-view'));
    });
    document.getElementById('aptAiJdTitleAddDate')?.addEventListener('change', updateJdTitleDateUi);
    document.getElementById('aptAiJdTitleDate')?.addEventListener('change', updateJdTitleDateUi);
    document.getElementById('aptAiJdTitle')?.addEventListener('input', updateJdTitleDateUi);
    document.getElementById('btnAptAiAddRow')?.addEventListener('click', () => addAiGenRow());
    document.getElementById('aptAiModeCategory')?.addEventListener('change', () => {
      updateAiSourceModeUI();
      updateAptAiSaveButtonLabel();
    });
    document.getElementById('aptAiModeJd')?.addEventListener('change', () => {
      updateAiSourceModeUI();
      updateAptAiSaveButtonLabel();
    });
    document.getElementById('aptAiInstructions')?.addEventListener('input', (e) => {
      if (e.target.value.trim()) e.target.dataset.userEdited = '1';
    });
    document.getElementById('aptAiCategory')?.addEventListener('change', (e) => fillAiTopicDatalist(e.target.value));
    document.getElementById('btnAptAiRun')?.addEventListener('click', () => runAptAiGenerate());
    bindAptAiJdDropzone();
    document.getElementById('btnAptAiRegenerate')?.addEventListener('click', () => {
      showAptAiFormPanel();
      if (aiLastFormParams) {
        const jd = aiLastFormParams.generationMode === 'jd';
        aiGenerateContext = jd ? 'jd' : 'bank';
        updateAiSourceModeUI();
        if (jd) {
          ensureJdCompaniesLoaded().then(() => fillAptAiJdCompanySelect(aiLastFormParams.companyId || ''));
          document.getElementById('aptAiJdTitle').value = aiLastFormParams.jdTitleBase || aiLastFormParams.jdTitle || '';
          document.getElementById('aptAiJdTitleAddDate').checked = !!aiLastFormParams.jdTitleAddDate;
          document.getElementById('aptAiJdTitleDate').value = aiLastFormParams.jdTitleDate || todayIsoDate();
          updateJdTitleDateUi();
          document.getElementById('aptAiJdText').value = aiLastFormParams.jobDescriptionPasted || '';
          if (aiLastFormParams.jobDescriptionUploaded && aiLastFormParams.jdUploadFilename) {
            aiJdUploadMeta = {
              filename: aiLastFormParams.jdUploadFilename,
              text: aiLastFormParams.jobDescriptionUploaded,
              jdFile: aiLastFormParams.jdFile || '',
              jdFileUrl: aiLastFormParams.jdFileUrl || '',
              jdFileDataUrl: aiLastFormParams.jdFileDataUrl || '',
              jdMimeType: aiLastFormParams.jdMimeType || '',
            };
            showAptAiJdFileUi(aiLastFormParams.jdUploadFilename);
          } else {
            clearAptAiJdUpload();
          }
        } else {
          document.getElementById('aptAiCategory').value = aiLastFormParams.category || '';
          fillAiTopicDatalist(aiLastFormParams.category || 'Quantitative Aptitude');
          document.getElementById('aptAiTopic').value = aiLastFormParams.topic || '';
        }
        document.getElementById('aptAiInstructions').value = aiLastFormParams.instructions || '';
        if (aiLastFormParams.instructions) {
          document.getElementById('aptAiInstructions').dataset.userEdited = '1';
        }
        initAiGenRows(
          aiLastFormParams.batches?.length
            ? aiLastFormParams.batches
            : [{ difficulty: aiLastFormParams.difficulty || 'Medium', count: aiLastFormParams.count || 5, marks: aiLastFormParams.marks || 1 }]
        );
      }
    });
    document.getElementById('btnAptAiSelectAll')?.addEventListener('click', () => {
      aiPreviewQuestions.forEach((q) => { q.selected = true; });
      renderAptAiPreview();
    });
    document.getElementById('btnAptAiBack')?.addEventListener('click', showAptAiFormPanel);
    document.getElementById('btnAptAiSaveBank')?.addEventListener('click', () => saveAptAiToBank());

    document.getElementById('bankFilterCategory')?.addEventListener('change', () => {
      bankCategoryFilter = document.getElementById('bankFilterCategory')?.value || '';
      loadQuestionBank().catch(() => {});
    });
    document.getElementById('bankSelectAllVisible')?.addEventListener('change', (e) => {
      const on = e.target.checked;
      visibleBankQuestions().forEach((q) => {
        const id = String(q.id || q.bankId || '');
        if (!id) return;
        if (on) selectedBankIds.add(id);
        else selectedBankIds.delete(id);
      });
      renderQuestionBank();
    });
    document.getElementById('btnBankDeleteSelected')?.addEventListener('click', () => deleteSelectedBankQuestions());
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
      if (payload.questionSource === 'random_jd') {
        if (!payload.jdFilterRules?.length) {
          toast('Add at least one JD random rule.', 'error');
          return;
        }
        if (formIsCompanyTest() && !selectedCompanyFromTestForm().companyId) {
          toast('Select a company.', 'error');
          return;
        }
        await ensureJdSetSummariesLoaded();
        const jdRandomErr = validateRandomJdRulesComplete();
        if (jdRandomErr) {
          toast(jdRandomErr, 'error');
          return;
        }
        payload.jdFilterRules = collectRandomJdRules();
        const ruleTotal = payload.jdFilterRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        payload.questionCount = ruleTotal || payload.questionCount;
      } else if (payload.questionSource === 'random') {
        if (!payload.randomRules?.length) {
          toast('Add at least one random rule.', 'error');
          return;
        }
        const ruleTotal = payload.randomRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        if (ruleTotal !== payload.questionCount) {
          payload.questionCount = ruleTotal;
        }
      } else {
        const companyTest = formIsCompanyTest();
        const mcqCount = companyTest ? 0 : (payload.questions?.length || 0);
        const useBank = companyTest ? false : document.getElementById('tfUseBankManual')?.checked;
        const useJd = companyTest ? (getQuestionSource() === 'manual') : document.getElementById('tfUseJdManual')?.checked;
        const target = Number(document.getElementById('tfQuestionCount')?.value || 0);
        if (companyTest) {
          const { companyId } = selectedCompanyFromTestForm();
          if (!companyId) {
            toast('Select a company.', 'error');
            return;
          }
        }
        if (!target || target < 1) {
          toast('Enter total number of questions.', 'error');
          return;
        }
        const bankRules = useBank ? collectManualBankRules() : [];
        const jdRules = useJd ? collectManualJdRules() : [];
        const bankTotal = bankRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        const jdTotal = jdRules.reduce((s, r) => s + (Number(r.count) || 0), 0);
        if (useBank) {
          await ensureManualBankQuestionsLoaded();
          const bankErr = validateManualBankRulesComplete();
          if (bankErr) {
            toast(bankErr, 'error');
            return;
          }
        }
        if (useJd) {
          await ensureJdSetSummariesLoaded();
          const jdErr = validateManualJdRulesComplete();
          if (jdErr) {
            toast(jdErr, 'error');
            return;
          }
        }
        if (companyTest && !useJd) {
          toast('Add at least one JD question rule.', 'error');
          return;
        }
        if (!useBank && !useJd && !mcqCount) {
          toast('Add questions from the bank, JD Block, or add MCQs directly.', 'error');
          return;
        }
        if (bankTotal + jdTotal + mcqCount !== target) {
          payload.questionCount = bankTotal + jdTotal + mcqCount;
        } else {
          payload.questionCount = target;
        }
        if (useBank) {
          payload.bankFilterRules = bankRules;
          payload.bankQuestionIds = bankRules.flatMap((r) => r.selectedQuestionIds || []);
        }
        if (useJd) {
          payload.jdFilterRules = jdRules;
        }
      }
      payload.totalMarks = 0;
      if (formIsCompanyTest()) {
        payload.testKind = 'company';
        const { companyId, companyName } = selectedCompanyFromTestForm();
        payload.companyId = companyId;
        payload.companyName = companyName;
        payload.contestType = 'none';
      }
      if (canManageContests()) {
        if (isContestTest(payload)) openManageContests(payload.contestType);
        else if (formIsCompanyTest()) applyManagePanel('company-tests');
        else applyManagePanel('tests');
      } else if (formIsCompanyTest()) {
        applyManagePanel('company-tests');
      }
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
        if (idx >= 0) {
          const prev = store[idx];
          store[idx] = {
            ...prev,
            ...next,
            resultsPublished: isContestTest(next) ? !!prev.resultsPublished : true,
          };
        } else {
          store.push({
            ...next,
            createdAt: new Date().toISOString(),
            resultsPublished: isContestTest(next) ? false : true,
          });
        }
        saveDemoTestsStore(store);
        toast('Test saved (demo).', 'success');
        testFormModal.hide();
        await loadTests();
        renderTestList();
        renderManage();
        return;
      }
      const rawId = document.getElementById('tfId').value.trim();
      const id = isLiveAptitudeId(rawId) ? rawId : '';
      if (rawId && !id) {
        toast('Creating a new test on the server (previous demo id ignored).', 'info');
      }
      const res = id
        ? await api(`/aptitude/tests/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(payload) })
        : await api('/aptitude/tests', { method: 'POST', body: JSON.stringify(payload) });
      if (!res?.success) {
        toast(res?.message || 'Could not save test.', 'error');
        return;
      }
      if (payload.status !== 'published') {
        toast('Test saved as unpublished. Set status to Published for students to see it.', 'info');
      } else {
        toast('Test saved.', 'success');
      }
      testFormModal.hide();
      await loadTests();
      renderTestList();
      renderManage();
    });

    document.getElementById('btnBulkTemplate')?.addEventListener('click', () => downloadExcelTemplate(true));

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
      let normalized = normalizeBulkRows(questions, fallbackCategory, fallbackDifficulty);
      if (mode === 'bank') {
        normalized = normalized.map((q) => ({ ...q, marks: 1 }));
      }
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
        if (!isLiveAptitudeId(testId)) {
          toast('Test not found. Refresh the manage list and try again.', 'error');
          return;
        }
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
