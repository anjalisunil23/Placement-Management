/* PlaceHub — coding practice hub */
(function () {
  const exam = CodingExam.createExamController({
    root: document.getElementById('examShell'),
    onExit() {
      closeExam();
    },
  });

  function esc(s) {
    return CodingExam.esc(s);
  }

  function difficultyClass(diff) {
    return CodingExam.difficultyClass(diff);
  }

  function renderStats(p) {
    document.getElementById('myStatsRow').innerHTML = [
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
    const hist = p.history || [];
    const root = document.getElementById('myHistory');
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
      : '<p class="text-muted-2 mb-0">No coding attempts yet. Select a test on the left to begin.</p>';
  }

  function renderTestList(tests) {
    const root = document.getElementById('testList');
    if (!tests.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">No coding tests are available yet.</p>';
      return;
    }
    root.innerHTML = tests.map((t) => `
      <div class="border rounded-3 p-3 d-flex flex-wrap justify-content-between align-items-start gap-2 cod-test-card">
        <div class="flex-grow-1">
          <div class="fw-semibold">${esc(t.title)}</div>
          <div class="small text-muted-2 d-flex flex-wrap align-items-center gap-1 mt-1">
            <span>${esc(t.category)}</span>
            <span>·</span>
            <span class="badge-soft ${difficultyClass(t.difficulty)}">${esc(t.difficulty)}</span>
            <span>·</span>
            <span>${esc(t.questions)} Questions</span>
            <span>·</span>
            <span>${esc(t.duration)} min</span>
            <span>·</span>
            <span>${esc(t.marks)} marks</span>
          </div>
          <div class="small mt-1">${esc(t.description || '')}</div>
        </div>
        <button type="button" class="btn btn-sm btn-primary" data-open-test="${esc(t.id)}">Select</button>
      </div>`).join('');
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

  async function closeExam() {
    exam.hide();
    document.getElementById('hubView').classList.remove('d-none');
    await loadHub();
  }

  async function loadHub() {
    const [tests, progress] = await Promise.all([
      CodingService.listTests(),
      CodingService.getProgress(),
    ]);
    renderStats(progress);
    renderHistory(progress);
    renderTestList(tests);
  }

  loadHub().catch((err) => {
    toast(err?.message || 'Could not load coding practice.', 'error');
  });
})();
