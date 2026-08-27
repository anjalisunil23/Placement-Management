/* PlaceHub — coding practice service (local mock; swap in API later)
 * Depends on window.CodeExecutionService from js/coding-execution.js.
 * That script must load first; this file installs a throwing stub if it did not.
 */
(function (global) {
  const PASS_PERCENT = 60;
  const STORAGE_PREFIX = 'ph-coding-progress-';
  const API_ENABLED = false;
  const EXEC_UNAVAILABLE = 'Code execution service unavailable, please try again';

  if (typeof global.CodeExecutionService === 'undefined') {
    global.CodeExecutionService = {
      TIME_LIMIT_MS: 3000,
      ready: false,
      async run() {
        throw new Error(EXEC_UNAVAILABLE);
      },
    };
  }

  function userKey() {
    try {
      const user = typeof Auth !== 'undefined' ? Auth.user() : null;
      return String(user?.id || user?.email || user?.registerNumber || 'demo');
    } catch {
      return 'demo';
    }
  }

  function storageKey() {
    return STORAGE_PREFIX + userKey();
  }

  function emptyProgress() {
    return { history: [], solvedQuestionIds: [] };
  }

  function loadProgress() {
    try {
      const raw = localStorage.getItem(storageKey());
      if (!raw) return emptyProgress();
      const parsed = JSON.parse(raw);
      return {
        history: Array.isArray(parsed.history) ? parsed.history : [],
        solvedQuestionIds: Array.isArray(parsed.solvedQuestionIds) ? parsed.solvedQuestionIds : [],
      };
    } catch {
      return emptyProgress();
    }
  }

  function saveProgress(progress) {
    localStorage.setItem(storageKey(), JSON.stringify(progress));
  }

  function normalizeOut(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/[ \t]+$/gm, '').replace(/\s+$/g, '').trim();
  }

  function isStarter(question, language, code) {
    const starter = String(question?.starterCode?.[language] || '').trim();
    return !String(code || '').trim() || String(code).trim() === starter;
  }

  function expectedFor(question, stdin) {
    if (typeof question.solver === 'function') {
      try {
        return normalizeOut(question.solver(stdin));
      } catch {
        return '';
      }
    }
    const want = normalizeOut(stdin);
    const hit = (question.testCases || []).find((tc) => normalizeOut(tc.input) === want);
    return hit ? normalizeOut(hit.expected) : '';
  }

  function mockHints(question, language, stdin) {
    return {
      expectedStdout: expectedFor(question, stdin),
      keywords: question?.keywords?.[language] || question?.keywords?.Python || [],
      starterCode: question?.starterCode?.[language] || '',
    };
  }

  function statusFromExec(exec, passed) {
    if (exec.timedOut || exec.status === 'Time Limit Exceeded') return 'Time Limit Exceeded';
    if (exec.status === 'Syntax Error') return 'Syntax Error';
    if (exec.status === 'Compilation Error') return 'Compilation Error';
    if (exec.status === 'Runtime Error') return 'Runtime Error';
    return passed ? 'Passed' : 'Wrong Answer';
  }

  function isExecUnavailable(err) {
    const msg = String(err?.message || err || '');
    return /unavailable|is not defined|failed to load|Failed to fetch|NetworkError|Execution failed/i.test(msg);
  }

  async function executeOnce(question, language, code, stdin, quick) {
    const svc = global.CodeExecutionService;
    if (!svc || typeof svc.run !== 'function' || svc.ready === false) {
      throw new Error(EXEC_UNAVAILABLE);
    }
    let exec;
    try {
      exec = await svc.run({
        language,
        source: code,
        stdin: stdin || '',
        timeLimitMs: svc.TIME_LIMIT_MS || 3000,
        quick: !!quick,
        _mock: mockHints(question, language, stdin),
      });
    } catch (err) {
      throw new Error(isExecUnavailable(err) ? EXEC_UNAVAILABLE : (err?.message || EXEC_UNAVAILABLE));
    }
    const expected = expectedFor(question, stdin);
    const stdout = normalizeOut(exec.stdout);
    const error = exec.timedOut || exec.status !== 'OK';
    const passed = !error && stdout === expected;
    return {
      exec,
      expected,
      stdout,
      stderr: exec.stderr || '',
      status: statusFromExec(exec, passed),
      passed,
    };
  }

  function formatDate(iso) {
    try {
      return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
      return iso;
    }
  }

  function formatTimer(sec) {
    sec = Math.max(0, Math.floor(Number(sec) || 0));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }

  function summarizeProgress(progress) {
    const history = progress.history || [];
    const latest = history[0];
    const percents = history.map((h) => Number(h.percentage) || 0);
    const best = history.reduce((acc, h) => {
      if (!acc || Number(h.percentage) > Number(acc.percentage)) return h;
      return acc;
    }, null);
    return {
      problemsSolved: (progress.solvedQuestionIds || []).length,
      bestScore: best ? `${best.score} / ${best.totalMarks}` : '0',
      averageScore: percents.length
        ? `${Math.round((percents.reduce((a, b) => a + b, 0) / percents.length) * 10) / 10}%`
        : '0%',
      recentScore: latest ? `${latest.percentage}%` : '0%',
      history: history.map((h) => ({
        ...h,
        dateLabel: formatDate(h.submittedAt),
        timeTakenLabel: formatTimer(h.timeTakenSeconds),
      })),
    };
  }

  const MANAGE_KEY = 'ph-coding-managed-tests';
  const BANK_KEY = 'ph-coding-problem-bank';

  function liveApi() {
    return typeof Auth !== 'undefined' && typeof Auth.hasRealAuth === 'function'
      && Auth.hasRealAuth() && !Auth.isDemo();
  }

  function loadJson(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return fallback;
      const parsed = JSON.parse(raw);
      return parsed == null ? fallback : parsed;
    } catch {
      return fallback;
    }
  }

  function saveJson(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
  }

  function loadManagedTests() {
    let tests = loadJson(MANAGE_KEY, null);
    if (!Array.isArray(tests) || !tests.length) {
      tests = typeof CodingData !== 'undefined' && CodingData.seedManagedTests
        ? CodingData.seedManagedTests()
        : [];
      saveJson(MANAGE_KEY, tests);
    }
    return tests;
  }

  function saveManagedTests(tests) {
    saveJson(MANAGE_KEY, tests);
    return tests;
  }

  function loadBankStore() {
    return loadJson(BANK_KEY, []);
  }

  function saveBankStore(rows) {
    saveJson(BANK_KEY, rows);
    return rows;
  }

  function isContestOpen(test) {
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

  function testMetaFromFull(test) {
    const items = test.items || [];
    const marks = Number(test.marks || test.totalMarks) || items.reduce((s, q) => s + Number(q.marks || 0), 0);
    return {
      id: test.id,
      title: test.title,
      category: test.category,
      difficulty: test.difficulty,
      questions: items.length || test.questions || 0,
      questionCount: items.length || test.questionCount || 0,
      duration: test.duration || test.durationMinutes || 20,
      durationMinutes: test.duration || test.durationMinutes || 20,
      marks,
      totalMarks: marks,
      description: test.description || '',
      instructions: test.instructions || [],
      status: test.status || 'unpublished',
      contestType: test.contestType || 'none',
      contestWeekday: test.contestWeekday,
      contestMonthDay: test.contestMonthDay,
      contestOpen: isContestOpen(test),
    };
  }

  function publicFromFull(test) {
    const meta = testMetaFromFull(test);
    const publicQ = typeof CodingData !== 'undefined' && CodingData.publicQuestion
      ? (item) => CodingData.publicQuestion(item)
      : (item) => item;
    return {
      ...meta,
      items: (test.items || []).map((item) => publicQ(item)),
    };
  }

  function questionFromAttempt(attempt, questionId) {
    const fromFull = getFullTestLocal(attempt?.testId)?.items?.find((q) => String(q.id) === String(questionId));
    if (fromFull) return fromFull;
    const fromAttempt = (attempt?.test?.items || []).find((q) => String(q.id) === String(questionId));
    if (fromAttempt) return fromAttempt;
    return typeof CodingData !== 'undefined' ? CodingData.getQuestion(attempt?.testId, questionId) : null;
  }

  function getFullTestLocal(id) {
    const managed = loadManagedTests().find((t) => String(t.id) === String(id));
    if (managed) {
      const builtin = typeof CodingData !== 'undefined' ? CodingData.getTest(id) : null;
      if (builtin?.items) {
        managed.items = (managed.items || []).map((item) => {
          const src = builtin.items.find((q) => q.id === item.id);
          return src ? { ...item, solver: src.solver, keywords: item.keywords || src.keywords } : item;
        });
      }
      return managed;
    }
    return typeof CodingData !== 'undefined' ? CodingData.getTest(id) : null;
  }

  const CodingService = {
    formatTimer,
    passPercent: PASS_PERCENT,

    async listTests() {
      if (liveApi()) {
        const res = await api('/coding/tests').catch(() => null);
        if (res?.success) return res.data.tests || [];
      }
      return loadManagedTests()
        .filter((t) => (t.status || 'published') === 'published' && isContestOpen(t))
        .map(testMetaFromFull);
    },

    async listManagedTests() {
      if (liveApi()) {
        const res = await api('/coding/tests?manage=1').catch(() => null);
        if (res?.success) return res.data.tests || [];
      }
      return loadManagedTests();
    },

    getFullTest(id) {
      return getFullTestLocal(id);
    },

    async saveTest(payload) {
      const items = payload.items || [];
      const marks = items.reduce((s, q) => s + Number(q.marks || 0), 0);
      const next = {
        ...payload,
        questions: items.length,
        questionCount: items.length,
        marks,
        totalMarks: marks,
        duration: Number(payload.duration || payload.durationMinutes || 20),
        durationMinutes: Number(payload.duration || payload.durationMinutes || 20),
      };
      if (liveApi()) {
        const id = payload.id;
        const res = id
          ? await api(`/coding/tests/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(next) })
          : await api('/coding/tests', { method: 'POST', body: JSON.stringify(next) });
        if (!res?.success) throw new Error(res?.message || 'Could not save test.');
        return res.data;
      }
      const store = loadManagedTests();
      if (!next.id) next.id = 'cod-test-' + Date.now();
      const idx = store.findIndex((t) => String(t.id) === String(next.id));
      if (idx >= 0) store[idx] = { ...store[idx], ...next };
      else store.push(next);
      saveManagedTests(store);
      return next;
    },

    async deleteTest(id) {
      if (liveApi()) {
        const res = await api(`/coding/tests/${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (!res?.success) throw new Error(res?.message || 'Could not delete test.');
        return;
      }
      saveManagedTests(loadManagedTests().filter((t) => String(t.id) !== String(id)));
    },

    async listBank() {
      if (liveApi()) {
        const res = await api('/coding/problem-bank').catch(() => null);
        if (res?.success) return res.data.problems || res.data.questions || [];
      }
      return loadBankStore();
    },

    async saveBankProblem(payload) {
      if (liveApi()) {
        const id = payload.id;
        const res = id
          ? await api(`/coding/problem-bank/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(payload) })
          : await api('/coding/problem-bank', { method: 'POST', body: JSON.stringify(payload) });
        if (!res?.success) throw new Error(res?.message || 'Could not save problem.');
        return res.data;
      }
      const bank = loadBankStore();
      if (!payload.id) payload.id = 'bank-' + Date.now();
      const idx = bank.findIndex((q) => String(q.id) === String(payload.id));
      if (idx >= 0) bank[idx] = { ...bank[idx], ...payload };
      else bank.push(payload);
      saveBankStore(bank);
      return payload;
    },

    async deleteBankProblem(id) {
      if (liveApi()) {
        const res = await api(`/coding/problem-bank/${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (!res?.success) throw new Error(res?.message || 'Could not delete problem.');
        return;
      }
      saveBankStore(loadBankStore().filter((q) => String(q.id) !== String(id)));
    },

    async directory(query) {
      if (liveApi()) {
        const res = await api('/coding/progress?' + (query || new URLSearchParams()).toString()).catch(() => null);
        if (res?.success) return res.data;
      }
      return null;
    },

    async getProgress() {
      if (liveApi()) {
        const res = await api('/coding/me').catch(() => null);
        if (res?.success) return res.data;
      }
      return summarizeProgress(loadProgress());
    },

    async startAttempt(testId) {
      if (liveApi()) {
        const res = await api(`/coding/tests/${encodeURIComponent(testId)}/start`, { method: 'POST' }).catch(() => null);
        if (res?.success && res.data) {
          const started = res.data;
          attempts.set(started.attemptId, {
            id: started.attemptId,
            testId,
            test: started.test,
            answers: started.answers || {},
            startedAt: started.startedAt || Date.now(),
            endsAt: started.endsAt,
            submitted: false,
          });
          return started;
        }
      }
      const pub = getFullTestLocal(testId) ? publicFromFull(getFullTestLocal(testId)) : (typeof CodingData !== 'undefined' ? CodingData.getPublicTest(testId) : null);
      if (!pub) throw new Error('Test not found.');
      const attemptId = 'cod-' + Date.now();
      const answers = {};
      pub.items.forEach((item) => {
        const sample = (item.testCases || []).find((tc) => tc.sample);
        answers[item.id] = {
          language: 'Python',
          code: item.starterCode.Python,
          customInput: sample ? sample.input : '',
          lastRun: null,
          locked: false,
        };
      });
      const attempt = {
        id: attemptId,
        testId,
        test: pub,
        answers,
        startedAt: Date.now(),
        endsAt: Date.now() + Math.max(1, Number(pub.duration) || 20) * 60 * 1000,
        submitted: false,
      };
      attempts.set(attemptId, attempt);
      return {
        attemptId,
        test: pub,
        startedAt: attempt.startedAt,
        endsAt: attempt.endsAt,
      };
    },

    saveDraft(attemptId, questionId, fields) {
      const attempt = attempts.get(attemptId);
      if (!attempt || attempt.submitted) return;
      attempt.answers[questionId] = Object.assign(attempt.answers[questionId] || {}, fields);
    },

    async runCode({ attemptId, questionId, language, code, stdin }) {
      if (API_ENABLED && typeof api === 'function') {
        const res = await api('/coding/run', {
          method: 'POST',
          body: JSON.stringify({ attemptId, questionId, language, code, stdin }),
        });
        if (!res?.success) throw new Error(res?.message || 'Run failed.');
        return res.data;
      }
      const attempt = attempts.get(attemptId);
      if (!attempt || attempt.submitted) throw new Error('This test has already been submitted.');
      const question = questionFromAttempt(attempt, questionId);
      if (!question) throw new Error('Question not found.');
      this.saveDraft(attemptId, questionId, { language, code, customInput: stdin });

      const customStdin = String(stdin ?? '');
      const custom = await executeOnce(question, language, code, customStdin, false);

      const cases = [];
      for (const tc of (question.testCases || [])) {
        if (!tc.sample) {
          cases.push({
            id: tc.id,
            label: tc.label || 'Hidden Test Case',
            sample: false,
            hidden: true,
            input: '',
            expected: '',
            output: '',
            stderr: '',
            status: 'Not Run',
            passed: false,
          });
          continue;
        }
        const ran = await executeOnce(question, language, code, tc.input, true);
        cases.push({
          id: tc.id,
          label: tc.label || 'Sample Test Case',
          sample: true,
          hidden: false,
          input: tc.input,
          expected: ran.expected,
          output: ran.stdout,
          stderr: ran.stderr,
          status: ran.status,
          passed: ran.passed,
        });
      }

      const visible = cases.filter((c) => !c.hidden);
      const passedVisible = visible.filter((c) => c.passed).length;
      const lastRun = {
        overall: custom.status,
        custom: {
          input: customStdin,
          output: custom.stdout,
          expected: custom.expected,
          stderr: custom.stderr,
          status: custom.status,
          passed: custom.passed,
        },
        results: cases.map((c, i) => ({ ...c, index: i + 1, label: `Test Case ${i + 1}` })),
        passedCount: passedVisible,
        totalCount: cases.length,
        visibleCount: visible.length,
        at: Date.now(),
      };
      if (attempt.answers[questionId]) attempt.answers[questionId].lastRun = lastRun;
      return lastRun;
    },

    async submitAttempt(attemptId, { timeTakenSeconds } = {}) {
      const localResult = async () => {
      const attempt = attempts.get(attemptId);
      if (!attempt) throw new Error('Attempt not found.');
      if (attempt.submitted) throw new Error('This test has already been submitted.');
      attempt.submitted = true;
      const full = getFullTestLocal(attempt.testId)
        || attempt.test
        || (typeof CodingData !== 'undefined' ? CodingData.getTest(attempt.testId) : null);
      if (!full) throw new Error('Test not found.');
      const questionResults = [];
      let score = 0;
      let correct = 0;
      let incorrect = 0;
      let skipped = 0;
      let testsPassed = 0;
      let testsTotal = 0;
      const solved = [];

      for (let index = 0; index < (full.items || []).length; index += 1) {
        const item = full.items[index];
        const ans = attempt.answers[item.id] || {};
        const language = ans.language || 'Python';
        const code = ans.code || '';
        const skippedQ = isStarter(item, language, code);
        const caseResults = [];
        if (!skippedQ) {
          for (const tc of (item.testCases || [])) {
            const ran = await executeOnce(item, language, code, tc.input, true);
            caseResults.push({
              id: tc.id,
              label: tc.sample ? 'Sample' : 'Hidden',
              sample: !!tc.sample,
              status: ran.status,
              passed: ran.passed,
            });
          }
        } else {
          (item.testCases || []).forEach((tc) => {
            caseResults.push({
              id: tc.id,
              label: tc.sample ? 'Sample' : 'Hidden',
              sample: !!tc.sample,
              status: 'Not Run',
              passed: false,
            });
          });
        }
        const total = caseResults.length;
        const passed = caseResults.filter((c) => c.passed).length;
        testsTotal += total;
        testsPassed += passed;
        let status = 'Skipped';
        let marksObtained = 0;
        if (skippedQ) {
          skipped += 1;
        } else if (total && passed === total) {
          status = 'Correct';
          marksObtained = item.marks;
          score += item.marks;
          correct += 1;
          solved.push(item.id);
        } else {
          status = 'Incorrect';
          marksObtained = total ? Math.round((item.marks * passed) / total) : 0;
          score += marksObtained;
          incorrect += 1;
        }
        if (attempt.answers[item.id]) attempt.answers[item.id].locked = true;
        questionResults.push({
          index: index + 1,
          id: item.id,
          title: item.title,
          status,
          marks: item.marks,
          marksObtained,
          language,
          testsPassed: passed,
          testsTotal: total,
          caseResults,
        });
      }

      const totalMarks = Number(full.marks || full.totalMarks) || (full.items || []).reduce((s, q) => s + Number(q.marks || 0), 0);
      const percentage = totalMarks ? Math.round((score / totalMarks) * 1000) / 10 : 0;
      const passed = percentage >= PASS_PERCENT;
      const taken = Number.isFinite(timeTakenSeconds)
        ? timeTakenSeconds
        : Math.max(0, Math.round((Date.now() - attempt.startedAt) / 1000));
      const result = {
        attemptId,
        testId: full.id,
        testTitle: full.title,
        score,
        totalMarks,
        percentage,
        passed,
        status: passed ? 'Passed' : 'Failed',
        correct,
        incorrect,
        skipped,
        questions: full.questions,
        testsPassed,
        testsTotal,
        timeTakenSeconds: taken,
        timeTakenLabel: formatTimer(taken),
        questionResults,
        submittedAt: new Date().toISOString(),
      };
      const progress = loadProgress();
      progress.history.unshift({
        id: attemptId,
        testId: result.testId,
        testTitle: result.testTitle,
        submittedAt: result.submittedAt,
        score: result.score,
        totalMarks: result.totalMarks,
        percentage: result.percentage,
        status: result.status,
        correct: result.correct,
        incorrect: result.incorrect,
        skipped: result.skipped,
        timeTakenSeconds: result.timeTakenSeconds,
      });
      progress.history = progress.history.slice(0, 20);
      const set = new Set(progress.solvedQuestionIds);
      solved.forEach((id) => set.add(id));
      progress.solvedQuestionIds = [...set];
      saveProgress(progress);
      result.dateLabel = formatDate(result.submittedAt);
      return result;
      };
      const result = await localResult();
      if (liveApi()) {
        await api(`/coding/attempts/${encodeURIComponent(attemptId)}/submit`, {
          method: 'POST',
          body: JSON.stringify(result),
        }).catch(() => null);
      }
      return result;
    },
  };

  global.CodingService = CodingService;
})(window);
