/* PlaceHub — coding practice service (local mock; swap in API later) */
(function (global) {
  const PASS_PERCENT = 60;
  const STORAGE_PREFIX = 'ph-coding-progress-';
  const API_ENABLED = false;

  function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
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
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\s+$/g, '').trim();
  }

  function isStarter(question, language, code) {
    const starter = String(question?.starterCode?.[language] || '').trim();
    return !String(code || '').trim() || String(code).trim() === starter;
  }

  function unbalanced(code) {
    const pairs = { '(': ')', '[': ']', '{': '}' };
    const stack = [];
    let inStr = null;
    for (let i = 0; i < code.length; i += 1) {
      const ch = code[i];
      if (inStr) {
        if (ch === '\\') {
          i += 1;
          continue;
        }
        if (ch === inStr) inStr = null;
        continue;
      }
      if (ch === '"' || ch === "'" || ch === '`') {
        inStr = ch;
        continue;
      }
      if (pairs[ch]) stack.push(pairs[ch]);
      else if (ch === ')' || ch === ']' || ch === '}') {
        if (stack.pop() !== ch) return true;
      }
    }
    return stack.length > 0;
  }

  function keywordHits(question, language, code) {
    const tokens = question?.keywords?.[language] || question?.keywords?.Python || [];
    const hay = String(code || '');
    return tokens.filter((token) => hay.toLowerCase().includes(String(token).toLowerCase()));
  }

  function evaluateCode(question, language, code) {
    const trimmed = String(code || '').trim();
    if (!trimmed) {
      return { kind: 'compile', reason: 'empty', pass: false };
    }
    if (isStarter(question, language, trimmed)) {
      return { kind: 'skipped', pass: false };
    }
    if (unbalanced(trimmed)) {
      return { kind: 'compile', reason: 'syntax', pass: false };
    }
    if (/\b(abort\s*\(|segfault|throw new|raise RuntimeError)\b/i.test(trimmed)) {
      return { kind: 'runtime', pass: false };
    }
    const tokens = question?.keywords?.[language] || question?.keywords?.Python || [];
    const hits = keywordHits(question, language, trimmed);
    const needed = tokens.length ? Math.max(1, Math.ceil(tokens.length * 0.4)) : 1;
    if (hits.length >= needed) {
      return { kind: 'correct', pass: true, hits };
    }
    return { kind: 'incorrect', pass: false, hits };
  }

  function runCases(question, language, code, cases) {
    const verdict = evaluateCode(question, language, code);
    return cases.map((tc) => {
      const expected = question.solver ? normalizeOut(question.solver(tc.input)) : normalizeOut(tc.expected);
      if (verdict.kind === 'compile') {
        return {
          id: tc.id,
          label: tc.label || (tc.sample ? 'Sample Test Case' : 'Hidden Test Case'),
          sample: !!tc.sample,
          input: tc.input,
          expected,
          output: '',
          status: 'Compilation Error',
        };
      }
      if (verdict.kind === 'runtime') {
        return {
          id: tc.id,
          label: tc.label || (tc.sample ? 'Sample Test Case' : 'Hidden Test Case'),
          sample: !!tc.sample,
          input: tc.input,
          expected,
          output: '',
          status: 'Runtime Error',
        };
      }
      if (verdict.kind === 'skipped') {
        return {
          id: tc.id,
          label: tc.label || (tc.sample ? 'Sample Test Case' : 'Hidden Test Case'),
          sample: !!tc.sample,
          input: tc.input,
          expected,
          output: '',
          status: 'Wrong Answer',
        };
      }
      const passed = verdict.pass;
      return {
        id: tc.id,
        label: tc.label || (tc.sample ? 'Sample Test Case' : 'Hidden Test Case'),
        sample: !!tc.sample,
        input: tc.input,
        expected,
        output: passed ? expected : (expected ? expected.slice(0, Math.max(0, expected.length - 1)) : ''),
        status: passed ? 'Passed' : 'Wrong Answer',
      };
    });
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

  const attempts = new Map();

  const CodingService = {
    formatTimer,
    passPercent: PASS_PERCENT,

    async listTests() {
      if (API_ENABLED && typeof api === 'function') {
        const res = await api('/coding/tests').catch(() => null);
        if (res?.success) return res.data.tests || [];
      }
      return CodingData.listTests();
    },

    async getProgress() {
      if (API_ENABLED && typeof api === 'function') {
        const res = await api('/coding/progress').catch(() => null);
        if (res?.success) return res.data;
      }
      return summarizeProgress(loadProgress());
    },

    async startAttempt(testId) {
      if (API_ENABLED && typeof api === 'function') {
        const res = await api(`/coding/tests/${encodeURIComponent(testId)}/start`, { method: 'POST' });
        if (!res?.success) throw new Error(res?.message || 'Could not start test.');
        return res.data;
      }
      const pub = CodingData.getPublicTest(testId);
      if (!pub) throw new Error('Test not found.');
      const attemptId = 'cod-' + Date.now();
      const answers = {};
      pub.items.forEach((item) => {
        answers[item.id] = { language: 'Python', code: item.starterCode.Python, lastRun: null };
      });
      const attempt = {
        id: attemptId,
        testId,
        test: pub,
        answers,
        startedAt: Date.now(),
        endsAt: Date.now() + Math.max(1, Number(pub.duration) || 20) * 60 * 1000,
      };
      attempts.set(attemptId, attempt);
      return {
        attemptId,
        test: pub,
        startedAt: attempt.startedAt,
        endsAt: attempt.endsAt,
      };
    },

    saveDraft(attemptId, questionId, language, code) {
      const attempt = attempts.get(attemptId);
      if (!attempt) return;
      attempt.answers[questionId] = attempt.answers[questionId] || {};
      attempt.answers[questionId].language = language;
      attempt.answers[questionId].code = code;
    },

    async runCode({ attemptId, questionId, language, code }) {
      await delay(550 + Math.floor(Math.random() * 450));
      if (API_ENABLED && typeof api === 'function') {
        const res = await api('/coding/run', {
          method: 'POST',
          body: JSON.stringify({ attemptId, questionId, language, code }),
        });
        if (!res?.success) throw new Error(res?.message || 'Run failed.');
        return res.data;
      }
      const attempt = attempts.get(attemptId);
      const question = CodingData.getQuestion(attempt?.testId, questionId);
      if (!question) throw new Error('Question not found.');
      this.saveDraft(attemptId, questionId, language, code);
      const samples = (question.testCases || []).filter((tc) => tc.sample);
      const results = runCases(question, language, code, samples);
      const overall = results.every((r) => r.status === 'Passed')
        ? 'Passed'
        : (results[0]?.status || 'Wrong Answer');
      const lastRun = { overall, results, at: Date.now() };
      if (attempt?.answers?.[questionId]) attempt.answers[questionId].lastRun = lastRun;
      return lastRun;
    },

    async submitAttempt(attemptId, { timeTakenSeconds } = {}) {
      if (API_ENABLED && typeof api === 'function') {
        const res = await api(`/coding/attempts/${encodeURIComponent(attemptId)}/submit`, {
          method: 'POST',
          body: JSON.stringify({ timeTakenSeconds }),
        });
        if (!res?.success) throw new Error(res?.message || 'Submit failed.');
        return res.data;
      }
      const attempt = attempts.get(attemptId);
      if (!attempt) throw new Error('Attempt not found.');
      const full = CodingData.getTest(attempt.testId);
      const questionResults = [];
      let score = 0;
      let correct = 0;
      let incorrect = 0;
      let skipped = 0;
      const solved = [];
      (full.items || []).forEach((item, index) => {
        const ans = attempt.answers[item.id] || {};
        const verdict = evaluateCode(item, ans.language || 'Python', ans.code || '');
        let status = 'Skipped';
        let marksObtained = 0;
        if (verdict.kind === 'skipped' || verdict.kind === 'compile') {
          status = verdict.kind === 'compile' ? 'Incorrect' : 'Skipped';
          if (status === 'Skipped') skipped += 1;
          else incorrect += 1;
        } else if (verdict.pass) {
          const hidden = (item.testCases || []).filter((tc) => !tc.sample);
          const hiddenResults = hidden.length
            ? runCases(item, ans.language || 'Python', ans.code || '', hidden)
            : [];
          const hiddenPass = !hiddenResults.length || hiddenResults.every((r) => r.status === 'Passed');
          if (hiddenPass) {
            status = 'Correct';
            marksObtained = item.marks;
            score += item.marks;
            correct += 1;
            solved.push(item.id);
          } else {
            status = 'Incorrect';
            incorrect += 1;
          }
        } else {
          status = 'Incorrect';
          incorrect += 1;
        }
        questionResults.push({
          index: index + 1,
          id: item.id,
          title: item.title,
          status,
          marks: item.marks,
          marksObtained,
          language: ans.language || 'Python',
        });
      });
      const totalMarks = full.marks;
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
      attempts.delete(attemptId);
      result.dateLabel = formatDate(result.submittedAt);
      return result;
    },
  };

  global.CodingService = CodingService;
})(window);
