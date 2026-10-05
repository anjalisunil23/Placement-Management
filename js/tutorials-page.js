(function () {
  const LANGUAGES = [
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'java', label: 'Java' },
    { value: 'python', label: 'Python' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'php', label: 'PHP' },
    { value: 'sql', label: 'SQL' },
  ];
  const CODE_DEMO_LANGUAGES = [
    { value: 'auto', label: 'Auto' },
    { value: 'text', label: 'Plain Text' },
    { value: 'python', label: 'Python' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'java', label: 'Java' },
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'csharp', label: 'C#' },
    { value: 'php', label: 'PHP' },
    { value: 'sql', label: 'SQL' },
    { value: 'html', label: 'HTML' },
    { value: 'css', label: 'CSS' },
    { value: 'json', label: 'JSON' },
    { value: 'bash', label: 'Bash' },
    { value: 'go', label: 'Go' },
  ];
  const state = {
    categories: [],
    departments: [],
    tutorials: [],
    active: null,
    years: [],
    lesson: null,
    activeBlockId: '',
    insertAfterId: '',
    statusFilter: '',
    publishId: '',
    creatingModule: false,
    selectedModuleId: '',
    assessment: null,
    assessmentQuestions: [],
    assessmentPreview: null,
    activities: [],
    activityBusy: false,
    activityAiPreview: null,
    aiCourse: null,
    aiModule: null,
    aiSavedTutorialId: '',
    reviewTutorialId: '',
    reviewCourse: null,
    reviewQueue: [],
    reviewDetail: null,
    reviewBusy: false,
    reviewActivityOptions: [],
  };

  function role() {
    return typeof Auth !== 'undefined' && Auth.role ? Auth.role() : '';
  }

  function isAuthor() {
    return ['admin', 'placement_officer', 'staff'].includes(role());
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  }

  async function call(path, opts) {
    const res = await api(path, opts || {});
    if (!res || !res.success) {
      const err = new Error((res && res.message) || 'Request failed.');
      err.status = res && res.status;
      throw err;
    }
    return res.data;
  }

  function fail(err) {
    toast(err && err.message ? err.message : 'Network error', 'error');
  }

  function modal(id) {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
  }

  function categoryName(id) {
    const hit = state.categories.find((row) => row.id === id);
    return hit ? hit.name : '—';
  }

  function departmentName(id) {
    const hit = state.departments.find((row) => String(row.id) === String(id));
    return hit ? (hit.code || hit.name) : id;
  }

  function statusBadge(status) {
    const map = {
      draft: ['warning', 'Draft'],
      published: ['success', 'Published'],
      unpublished: ['secondary', 'Unpublished'],
    };
    const pair = map[status] || ['secondary', status || '—'];
    return `<span class="badge text-bg-${pair[0]}">${esc(pair[1])}</span>`;
  }

  function visibilityText(row) {
    if (row.visibility !== 'scoped') return 'All Students';
    const departments = (row.departmentIds || []).map(departmentName);
    const years = row.passingYears || [];
    if (departments.length && years.length) return `${departments.join(', ')} AND ${years.join(', ')}`;
    if (departments.length) return departments.join(', ');
    if (years.length) return years.join(', ');
    return 'Selected audience';
  }

  async function loadCategories() {
    state.categories = await call('/tutorial-categories') || [];
    const select = document.getElementById('tutorialCategory');
    const current = select.value;
    select.innerHTML = state.categories.map((row) => (
      `<option value="${esc(row.id)}">${esc(row.name)}</option>`
    )).join('');
    if (current) select.value = current;
  }

  async function loadDepartments() {
    if (typeof DepartmentStore !== 'undefined') {
      await DepartmentStore.fetch({ force: true });
      state.departments = DepartmentStore.all() || [];
    }
    document.getElementById('departmentChecks').innerHTML = state.departments.map((row) => (
      `<label class="form-check"><input class="form-check-input" type="checkbox" value="${esc(row.id)}" data-dept/> <span class="form-check-label">${esc(row.name || row.code)} <span class="text-muted-2">${esc(row.code || '')}</span></span></label>`
    )).join('') || '<p class="text-muted-2 mb-0">No departments are available.</p>';
  }

  function selectedDepartments() {
    return [...document.querySelectorAll('[data-dept]:checked')].map((input) => input.value);
  }

  function setDepartments(ids) {
    const wanted = new Set((ids || []).map(String));
    document.querySelectorAll('[data-dept]').forEach((input) => {
      input.checked = wanted.has(input.value);
    });
  }

  function renderYears() {
    document.getElementById('yearChips').innerHTML = state.years.map((year) => (
      `<button type="button" class="btn btn-sm btn-outline-secondary" data-remove-year="${esc(year)}">${esc(year)} <i class="bi bi-x"></i></button>`
    )).join('');
    document.querySelectorAll('[data-remove-year]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.years = state.years.filter((year) => year !== btn.getAttribute('data-remove-year'));
        renderYears();
      });
    });
  }

  function visibilityMode() {
    const picked = document.querySelector('input[name="tutorialVisibility"]:checked');
    return picked ? picked.value : 'all';
  }

  function syncAudience() {
    const mode = visibilityMode();
    document.getElementById('departmentAudience').classList.toggle('d-none', mode !== 'departments' && mode !== 'both');
    document.getElementById('yearAudience').classList.toggle('d-none', mode !== 'years' && mode !== 'both');
  }

  function renderTutorials() {
    const body = document.getElementById('tutorialRows');
    const rows = state.tutorials.filter((row) => !state.statusFilter || row.status === state.statusFilter);
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="9" class="text-muted-2 p-4">No courses in this list. Create a course to begin.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => {
      const status = row.status || 'draft';
      const publish = status === 'published'
        ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-unpublish="${esc(row.id)}">Unpublish</button>`
        : `<button type="button" class="btn btn-sm btn-outline-primary" data-publish="${esc(row.id)}">Publish</button>`;
      const edit = status === 'published' ? '' : `<button type="button" class="btn btn-sm btn-outline-secondary" data-edit-tutorial="${esc(row.id)}">Edit</button>`;
      const manage = `<button type="button" class="btn btn-sm btn-outline-secondary" data-modules="${esc(row.id)}">${status === 'published' ? 'Manage' : 'Manage Modules'}</button>`;
      const reviews = `<button type="button" class="btn btn-sm btn-outline-secondary" data-activity-reviews="${esc(row.id)}">Reviews</button>`;
      const remove = status === 'published'
        ? ''
        : `<button type="button" class="btn btn-sm btn-outline-danger" data-delete-tutorial="${esc(row.id)}">Delete</button>`;
      const updated = row.updatedAt && typeof formatDate === 'function' ? formatDate(row.updatedAt) : '—';
      return `<tr>
        <td class="fw-semibold">${esc(row.title)}</td>
        <td>${esc(categoryName(row.categoryId))}</td>
        <td>${esc(row.topic)}</td>
        <td>${statusBadge(status)}</td>
        <td>${esc(visibilityText(row))}</td>
        <td>${esc(row.moduleCount ?? 0)}</td>
        <td>${esc(row.exerciseCount ?? 0)}</td>
        <td>${esc(updated)}</td>
        <td class="text-nowrap">
          <div class="d-flex flex-wrap gap-1 justify-content-end">
            ${edit}
            ${manage}
            ${reviews}
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preview="${esc(row.id)}">Preview</button>
            ${publish}
            ${remove}
          </div>
        </td>
      </tr>`;
    }).join('');
    body.querySelectorAll('[data-edit-tutorial]').forEach((btn) => btn.addEventListener('click', () => openTutorial(btn.getAttribute('data-edit-tutorial'))));
    body.querySelectorAll('[data-modules]').forEach((btn) => btn.addEventListener('click', () => openModules(btn.getAttribute('data-modules'))));
    body.querySelectorAll('[data-activity-reviews]').forEach((btn) => btn.addEventListener('click', () => openActivityReviews(btn.getAttribute('data-activity-reviews')).catch(fail)));
    body.querySelectorAll('[data-publish]').forEach((btn) => btn.addEventListener('click', () => publishTutorial(btn.getAttribute('data-publish'), true)));
    body.querySelectorAll('[data-unpublish]').forEach((btn) => btn.addEventListener('click', () => publishTutorial(btn.getAttribute('data-unpublish'), false)));
    body.querySelectorAll('[data-preview]').forEach((btn) => btn.addEventListener('click', () => openPreview(btn.getAttribute('data-preview'))));
    body.querySelectorAll('[data-delete-tutorial]').forEach((btn) => btn.addEventListener('click', () => deleteTutorial(btn.getAttribute('data-delete-tutorial'))));
  }

  async function refreshList() {
    state.tutorials = await call('/tutorials/manage') || [];
    renderTutorials();
  }

  function blankTutorialForm() {
    document.getElementById('tutorialModalTitle').textContent = 'Create Course';
    document.getElementById('tutorialForm').reset();
    document.getElementById('tutorialId').value = '';
    document.getElementById('visibilityAll').checked = true;
    state.years = [];
    setDepartments([]);
    renderYears();
    syncAudience();
  }

  async function openTutorial(id) {
    try {
      const row = id ? await call(`/tutorials/manage/${encodeURIComponent(id)}`) : null;
      blankTutorialForm();
      if (row) {
        document.getElementById('tutorialModalTitle').textContent = 'Edit Course';
        document.getElementById('tutorialId').value = row.id;
        document.getElementById('tutorialTitle').value = row.title || '';
        document.getElementById('tutorialTopic').value = row.topic || '';
        document.getElementById('tutorialDescription').value = row.description || '';
        document.getElementById('tutorialCategory').value = row.categoryId || '';
        const hasDepartments = (row.departmentIds || []).length > 0;
        const hasYears = (row.passingYears || []).length > 0;
        const mode = row.visibility !== 'scoped' ? 'visibilityAll' : (hasDepartments && hasYears ? 'visibilityBoth' : (hasDepartments ? 'visibilityDepartments' : 'visibilityYears'));
        document.getElementById(mode).checked = true;
        setDepartments(row.departmentIds || []);
        state.years = [...(row.passingYears || [])];
        renderYears();
        syncAudience();
      }
      modal('tutorialModal').show();
    } catch (err) {
      fail(err);
    }
  }

  async function saveTutorial(event) {
    event.preventDefault();
    const id = document.getElementById('tutorialId').value;
    const mode = visibilityMode();
    if ((mode === 'departments' || mode === 'both') && !selectedDepartments().length) {
      toast('Choose at least one department.', 'error');
      return;
    }
    if ((mode === 'years' || mode === 'both') && !state.years.length) {
      toast('Add at least one passing year.', 'error');
      return;
    }
    const body = {
      title: document.getElementById('tutorialTitle').value.trim(),
      categoryId: document.getElementById('tutorialCategory').value,
      topic: document.getElementById('tutorialTopic').value.trim(),
      description: document.getElementById('tutorialDescription').value.trim(),
      visibility: mode === 'all' ? 'all' : 'scoped',
      departmentIds: mode === 'departments' || mode === 'both' ? selectedDepartments() : [],
      passingYears: mode === 'years' || mode === 'both' ? state.years : [],
    };
    try {
      if (id) await call(`/tutorials/manage/${encodeURIComponent(id)}`, { method: 'PUT', body });
      else await call('/tutorials/manage', { method: 'POST', body });
      modal('tutorialModal').hide();
      toast(id ? 'Course updated.' : 'Course created.', 'success');
      await refreshList();
      if (id && state.active && state.active.id === id && !document.getElementById('moduleView').classList.contains('d-none')) {
        await openModules(id);
      }
    } catch (err) {
      fail(err);
    }
  }

  async function publishTutorial(id, publish) {
    if (!publish) {
      try {
        await call(`/tutorials/manage/${encodeURIComponent(id)}/unpublish`, { method: 'POST', body: {} });
        toast('Tutorial unpublished.', 'success');
        await refreshList();
        if (state.active && state.active.id === id && !document.getElementById('moduleView').classList.contains('d-none')) {
          await openModules(id);
        }
      } catch (err) {
        fail(err);
      }
      return;
    }
    try {
      const check = await call(`/tutorials/manage/${encodeURIComponent(id)}/checklist`);
      state.publishId = id;
      document.getElementById('publishChecks').innerHTML = (check.checks || []).map((item) => (
        `<div>${item.ok ? '✓' : '✕'} ${esc(item.label)}</div>`
      )).join('');
      document.getElementById('publishWarnings').innerHTML = (check.warnings || []).map((item) => `<div>⚠ ${esc(item)}</div>`).join('');
      document.getElementById('confirmPublishBtn').disabled = !check.canPublish;
      modal('publishModal').show();
    } catch (err) {
      fail(err);
    }
  }

  async function deleteTutorial(id) {
    const ok = await confirmAction({ title: 'Delete tutorial', message: 'Delete this tutorial and its modules?', confirmText: 'Delete', variant: 'danger' });
    if (!ok) return;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(id)}`, { method: 'DELETE' });
      toast('Tutorial deleted.', 'success');
      await refreshList();
    } catch (err) {
      fail(err);
    }
  }

  async function saveCategory(event) {
    event.preventDefault();
    try {
      await call('/tutorial-categories', {
        method: 'POST',
        body: {
          name: document.getElementById('categoryName').value.trim(),
          description: document.getElementById('categoryDescription').value.trim(),
        },
      });
      modal('categoryModal').hide();
      document.getElementById('categoryForm').reset();
      toast('Category saved.', 'success');
      await loadCategories();
    } catch (err) {
      fail(err);
    }
  }

  function showStaffScreen(name) {
    document.getElementById('tutorialListView').classList.toggle('d-none', name !== 'list');
    const aiView = document.getElementById('aiCourseView');
    if (aiView) aiView.classList.toggle('d-none', name !== 'aiCourse');
    document.getElementById('moduleView').classList.toggle('d-none', name !== 'builder');
    document.getElementById('articleView').classList.toggle('d-none', name !== 'article');
    document.getElementById('previewView').classList.toggle('d-none', name !== 'preview');
    const reviewView = document.getElementById('activityReviewView');
    if (reviewView) reviewView.classList.toggle('d-none', name !== 'reviews');
  }

  function lessonBlocksText(documentJson) {
    const blocks = (documentJson && documentJson.blocks) || [];
    return blocks.map((block) => {
      const type = block.type || '';
      if (type === 'heading') return `${block.level === 3 ? '###' : '##'} ${block.text || ''}`;
      if (type === 'code') return `[${block.language || 'code'}]\n${block.source || ''}`;
      if (type === 'divider') return '---';
      return block.text || '';
    }).filter(Boolean).join('\n\n');
  }

  function setAiError(id, message) {
    const box = document.getElementById(id);
    if (!box) return;
    if (!message) {
      box.classList.add('d-none');
      box.textContent = '';
      return;
    }
    box.textContent = message;
    box.classList.remove('d-none');
  }

  function fillAiCategorySelect(preferredSlug) {
    const select = document.getElementById('aiPreviewCategory');
    if (!select) return;
    select.innerHTML = (state.categories || []).map((row) => (
      `<option value="${esc(row.id)}" data-slug="${esc(row.slug || '')}">${esc(row.name)}</option>`
    )).join('');
    const match = [...select.options].find((option) => option.getAttribute('data-slug') === preferredSlug);
    if (match) select.value = match.value;
  }

  function openAiCourseScreen() {
    state.aiCourse = null;
    state.aiSavedTutorialId = '';
    setAiError('aiCourseError', '');
    document.getElementById('aiCoursePreview').classList.add('d-none');
    document.getElementById('aiCourseSaved').classList.add('d-none');
    document.getElementById('aiCourseLoading').classList.add('d-none');
    showStaffScreen('aiCourse');
  }

  const AI_EXERCISE_LANGUAGES = ['python', 'javascript', 'java', 'c', 'cpp', 'php', 'sql'];

  function lessonSections(documentJson) {
    const blocks = (documentJson && documentJson.blocks) || [];
    const sections = [];
    blocks.forEach((block) => {
      if (block && block.type === 'heading' && Number(block.level || 2) === 2) {
        sections.push({
          id: String(block.id || ''),
          title: block.text || 'Lesson',
          blocks: [block],
        });
        return;
      }
      if (!sections.length) return;
      sections[sections.length - 1].blocks.push(block);
    });
    return sections;
  }

  function syncAiPractice(root, module) {
    if (!root || !module) return;
    const questions = module.lessonMcqs || [];
    root.querySelectorAll('[data-ai-mcq]').forEach((card) => {
      const question = questions[Number(card.getAttribute('data-ai-mcq'))];
      if (!question) return;
      question.question = card.querySelector('[data-ai-mcq-question]').value.trim();
      question.explanation = card.querySelector('[data-ai-mcq-explanation]').value.trim();
      question.options = [0, 1, 2, 3].map((index) => card.querySelector(`[data-ai-mcq-option="${index}"]`).value.trim());
      const selected = card.querySelector('input[data-ai-mcq-correct]:checked');
      const correct = selected ? Number(selected.value) : 0;
      question.correctIndex = correct;
      question.correctAnswer = correct;
    });
  }

  function renderAiMcqEditor(question, questionIndex) {
    const options = (question.options || ['', '', '', '']).slice(0, 4);
    while (options.length < 4) options.push('');
    const correct = Number(question.correctIndex != null ? question.correctIndex : question.correctAnswer || 0);
    const group = String(question.tempId || `${question.lessonBlockId || 'q'}-${questionIndex}`).replace(/[^A-Za-z0-9_-]/g, '');
    const optionFields = options.map((option, index) => (
      `<label class="d-flex gap-2 align-items-start mb-1">
        <input class="mt-2" type="radio" name="ai-mcq-${group}" data-ai-mcq-correct value="${index}" ${index === correct ? 'checked' : ''}/>
        <input class="form-control form-control-sm" data-ai-mcq-option="${index}" value="${esc(option)}" maxlength="500"/>
      </label>`
    )).join('');
    return `<div class="border rounded p-2 mb-2 bg-white" data-ai-mcq="${questionIndex}">
      <div class="d-flex justify-content-between gap-2 mb-2">
        <div class="small fw-semibold">Question</div>
        <button type="button" class="btn btn-sm btn-outline-danger" data-ai-mcq-remove="${questionIndex}">Remove</button>
      </div>
      <textarea class="form-control form-control-sm mb-2" data-ai-mcq-question rows="2">${esc(question.question || '')}</textarea>
      <div class="small text-muted-2 mb-1">Mark the correct option.</div>
      ${optionFields}
      <label class="form-label small mt-2">Explanation</label>
      <textarea class="form-control form-control-sm" data-ai-mcq-explanation rows="2">${esc(question.explanation || '')}</textarea>
    </div>`;
  }

  function renderAiExerciseEditor(exercise, exerciseIndex) {
    const cases = (exercise.testCases || []).map((row, caseIndex) => (
      `<div class="border rounded p-2 mb-2" data-ai-case="${caseIndex}">
        <div class="d-flex justify-content-between gap-2 mb-1">
          <label class="small mb-0"><input type="checkbox" data-ai-case-sample ${row.sample ? 'checked' : ''}/> Public sample</label>
          <button type="button" class="btn btn-sm btn-link text-danger p-0" data-ai-case-remove="${exerciseIndex}:${caseIndex}">Remove case</button>
        </div>
        <label class="form-label small mb-1">Input</label>
        <textarea class="form-control form-control-sm mb-1" data-ai-case-stdin rows="2">${esc(row.stdin || '')}</textarea>
        <label class="form-label small mb-1">Expected output</label>
        <textarea class="form-control form-control-sm" data-ai-case-output rows="2">${esc(row.expectedOutput || '')}</textarea>
      </div>`
    )).join('');
    const languages = AI_EXERCISE_LANGUAGES.map((lang) => (
      `<option value="${lang}" ${exercise.language === lang ? 'selected' : ''}>${esc(lang)}</option>`
    )).join('');
    return `<div class="border rounded p-2 mb-2 bg-white" data-ai-exercise="${exerciseIndex}">
      <div class="d-flex justify-content-between gap-2 mb-2">
        <div class="small fw-semibold">Exercise</div>
        <button type="button" class="btn btn-sm btn-outline-danger" data-ai-exercise-remove="${exerciseIndex}">Remove</button>
      </div>
      <label class="form-label small">Title</label>
      <input class="form-control form-control-sm mb-2" data-ai-ex-title value="${esc(exercise.title || '')}" maxlength="160"/>
      <label class="form-label small">Problem and instructions</label>
      <textarea class="form-control form-control-sm mb-2" data-ai-ex-instructions rows="3">${esc(exercise.instructions || '')}</textarea>
      <label class="form-label small">Language</label>
      <select class="form-select form-select-sm mb-2" data-ai-ex-language>${languages}</select>
      <label class="form-label small">Starter code</label>
      <textarea class="form-control form-control-sm font-monospace mb-2" data-ai-ex-boilerplate rows="4">${esc(exercise.boilerplate || '')}</textarea>
      <div class="small fw-semibold mb-1">Test cases</div>
      ${cases || '<p class="small text-muted-2">No test cases.</p>'}
      <button type="button" class="btn btn-sm btn-outline-secondary" data-ai-case-add="${exerciseIndex}">Add test case</button>
    </div>`;
  }

  function renderAiLessonPractice(module) {
    const sections = lessonSections(module.lessonDocument);
    const questions = module.lessonMcqs || [];
    if (!sections.length) {
      return '<p class="small text-muted-2 mb-0">No lessons were returned for this module.</p>';
    }
    return sections.map((section) => {
      const cards = questions.map((question, questionIndex) => ({ question, questionIndex }))
        .filter((row) => String(row.question.lessonBlockId || '') === section.id)
        .map((row) => renderAiMcqEditor(row.question, row.questionIndex))
        .join('') || '<p class="small text-muted-2">No questions for this lesson yet.</p>';
      return `<div class="border rounded p-2 mt-2" data-ai-lesson="${esc(section.id)}">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-2">
          <div class="small fw-semibold">${esc(section.title)}</div>
          <button type="button" class="btn btn-sm btn-outline-primary" data-ai-regen="${esc(section.id)}" ${section.id ? '' : 'disabled'}>Regenerate questions</button>
        </div>
        <details class="mb-2"><summary class="small">Lesson content</summary><pre class="small mt-2 mb-0" style="white-space:pre-wrap">${esc(lessonBlocksText({ blocks: section.blocks }))}</pre></details>
        ${cards}
      </div>`;
    }).join('');
  }

  function bindAiPractice(root, scope) {
    if (!root) return;
    root.querySelectorAll('[data-ai-mcq-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const module = aiPracticeModule(scope, root);
        if (!module) return;
        syncAiPractice(root, module);
        module.lessonMcqs.splice(Number(btn.getAttribute('data-ai-mcq-remove')), 1);
        refreshAiPractice(scope);
      });
    });
    root.querySelectorAll('[data-ai-regen]').forEach((btn) => {
      btn.addEventListener('click', () => {
        regenerateLessonExercises(scope, root, btn.getAttribute('data-ai-regen') || '').catch((err) => {
          const errorId = scope === 'module' ? 'aiModuleError' : 'aiCourseError';
          setAiError(errorId, err && err.message ? err.message : 'Questions could not be regenerated.');
        });
      });
    });
  }

  function aiPracticeModule(scope, root) {
    if (scope === 'module') return state.aiModule && state.aiModule.module;
    const card = root.closest ? root.closest('[data-ai-module]') : null;
    const index = card ? Number(card.getAttribute('data-ai-module')) : Number(root.getAttribute('data-ai-module'));
    return state.aiCourse && state.aiCourse.modules ? state.aiCourse.modules[index] : null;
  }

  function refreshAiPractice(scope) {
    if (scope === 'module') renderAiModulePreview();
    else renderAiCoursePreview();
  }

  function syncAiCourseFromForm() {
    if (!state.aiCourse) return;
    state.aiCourse.course.title = document.getElementById('aiPreviewTitle').value.trim();
    state.aiCourse.course.description = document.getElementById('aiPreviewDescription').value.trim();
    document.querySelectorAll('#aiPreviewModules [data-ai-module]').forEach((card) => {
      const index = Number(card.getAttribute('data-ai-module'));
      const module = state.aiCourse.modules[index];
      if (!module) return;
      module.title = card.querySelector('[data-ai-title]').value.trim();
      module.description = card.querySelector('[data-ai-description]').value.trim();
      module.subtitle = module.description.slice(0, 240);
      syncAiPractice(card, module);
    });
  }

  function renderAiCoursePreview() {
    const preview = state.aiCourse;
    const box = document.getElementById('aiCoursePreview');
    if (!preview || !preview.course) {
      box.classList.add('d-none');
      return;
    }
    box.classList.remove('d-none');
    document.getElementById('aiPreviewTitle').value = preview.course.title || '';
    document.getElementById('aiPreviewDescription').value = preview.course.description || '';
    fillAiCategorySelect(preview.suggestedCategorySlug || '');
    document.getElementById('aiPreviewMeta').textContent = [
      preview.course.topic ? `Topic: ${preview.course.topic}` : '',
      preview.course.difficulty || '',
      preview.course.academicField ? preview.course.academicField.replace(/_/g, ' ') : '',
      `${(preview.modules || []).length} module${(preview.modules || []).length === 1 ? '' : 's'}`,
    ].filter(Boolean).join(' · ');
    document.getElementById('aiPreviewModules').innerHTML = (preview.modules || []).map((module, index) => (
      `<div class="card-surface p-3" data-ai-module="${index}">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
          <div class="fw-semibold">Module ${index + 1}</div>
          <button type="button" class="btn btn-sm btn-outline-danger" data-ai-remove="${index}">Remove</button>
        </div>
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Title</label><input class="form-control form-control-sm" data-ai-title value="${esc(module.title || '')}" maxlength="160"/></div>
          <div class="col-12"><label class="form-label">Description</label><textarea class="form-control form-control-sm" data-ai-description rows="2">${esc(module.description || module.subtitle || '')}</textarea></div>
          <div class="col-12"><div class="small fw-semibold mb-1">Lessons and practice</div>${renderAiLessonPractice(module)}</div>
        </div>
      </div>`
    )).join('') || '<p class="text-muted-2 mb-0">No modules left in this preview.</p>';
    document.querySelectorAll('[data-ai-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        syncAiCourseFromForm();
        const index = Number(btn.getAttribute('data-ai-remove'));
        state.aiCourse.modules.splice(index, 1);
        renderAiCoursePreview();
      });
    });
    document.querySelectorAll('#aiPreviewModules [data-ai-module]').forEach((card) => bindAiPractice(card, 'course'));
  }

  async function regenerateLessonExercises(scope, root, lessonId) {
    const module = aiPracticeModule(scope, root);
    if (!module) return;
    if (scope === 'course') syncAiCourseFromForm();
    else syncAiPractice(document.getElementById('aiModulePreview'), module);
    const section = lessonSections(module.lessonDocument).find((row) => row.id === lessonId);
    if (!section || !section.id) {
      throw new Error('This lesson cannot be linked to questions.');
    }
    const errorId = scope === 'module' ? 'aiModuleError' : 'aiCourseError';
    setAiError(errorId, '');
    root.querySelectorAll('[data-ai-regen]').forEach((button) => {
      button.disabled = true;
    });
    const topicInput = document.getElementById(scope === 'module' ? 'aiModuleTopic' : 'aiCourseTopic');
    const fieldInput = document.getElementById(scope === 'module' ? 'aiModuleField' : 'aiCourseField');
    const difficultyInput = document.getElementById(scope === 'module' ? 'aiModuleDifficulty' : 'aiCourseDifficulty');
    const course = scope === 'course' && state.aiCourse ? state.aiCourse.course : null;
    try {
      const data = await call('/tutorials/manage/ai/generate-lesson-mcqs', {
        method: 'POST',
        body: {
          lessonBlockId: section.id,
          lessonTitle: section.title,
          lessonText: lessonBlocksText({ blocks: section.blocks }),
          topic: (course && course.topic) || (topicInput ? topicInput.value.trim() : '') || section.title,
          academicField: (course && course.academicField) || (fieldInput ? fieldInput.value : 'computer_applications'),
          difficulty: (course && course.difficulty) || (difficultyInput ? difficultyInput.value : 'beginner'),
          count: 3,
        },
      });
      const next = Array.isArray(data && data.lessonMcqs) ? data.lessonMcqs : [];
      module.lessonMcqs = (module.lessonMcqs || []).filter((row) => String(row.lessonBlockId || '') !== section.id).concat(next);
      refreshAiPractice(scope);
      toast(next.length ? 'Questions regenerated for this lesson.' : 'No questions were returned for this lesson.', next.length ? 'success' : 'error');
    } finally {
      if (root.isConnected) {
        root.querySelectorAll('[data-ai-regen]').forEach((button) => {
          button.disabled = false;
        });
      }
    }
  }

  async function generateAiCourse() {
    const topic = document.getElementById('aiCourseTopic').value.trim();
    const moduleCount = Number(document.getElementById('aiCourseModuleCount').value);
    setAiError('aiCourseError', '');
    document.getElementById('aiCourseSaved').classList.add('d-none');
    if (!topic) {
      setAiError('aiCourseError', 'Topic is required.');
      return;
    }
    if (!Number.isInteger(moduleCount) || moduleCount < 1 || moduleCount > 12) {
      setAiError('aiCourseError', 'Number of modules must be between 1 and 12.');
      return;
    }
    const btn = document.getElementById('aiCourseGenerateBtn');
    btn.disabled = true;
    document.getElementById('aiCourseLoading').classList.remove('d-none');
    try {
      const data = await call('/tutorials/manage/ai/generate-course', {
        method: 'POST',
        body: {
          topic,
          academicField: document.getElementById('aiCourseField').value,
          difficulty: document.getElementById('aiCourseDifficulty').value,
          moduleCount,
          additionalInstructions: document.getElementById('aiCourseInstructions').value.trim(),
          mcqsPerModule: 0,
          practicalPreference: 'none',
        },
      });
      if (!data || !data.course || !Array.isArray(data.modules) || !data.modules.length) {
        throw new Error('AI did not return a course preview.');
      }
      state.aiCourse = data;
      state.aiSavedTutorialId = '';
      renderAiCoursePreview();
      toast('Review the generated course, then save it as a draft.', 'success');
    } catch (err) {
      state.aiCourse = null;
      document.getElementById('aiCoursePreview').classList.add('d-none');
      setAiError('aiCourseError', err && err.message ? err.message : 'Course generation failed.');
    } finally {
      btn.disabled = false;
      document.getElementById('aiCourseLoading').classList.add('d-none');
    }
  }

  async function saveAiCourseDraft() {
    if (!state.aiCourse) {
      setAiError('aiCourseError', 'Generate a course before saving.');
      return;
    }
    syncAiCourseFromForm();
    if (!state.aiCourse.course.title) {
      setAiError('aiCourseError', 'Course title is required.');
      return;
    }
    if (!(state.aiCourse.modules || []).length) {
      setAiError('aiCourseError', 'Keep at least one module before saving.');
      return;
    }
    if ((state.aiCourse.modules || []).some((module) => !String(module.title || '').trim())) {
      setAiError('aiCourseError', 'Every module needs a title.');
      return;
    }
    const categoryId = document.getElementById('aiPreviewCategory').value;
    if (!categoryId) {
      setAiError('aiCourseError', 'Choose a category before saving the generated course.');
      return;
    }
    const btn = document.getElementById('aiCourseSaveBtn');
    const regen = document.getElementById('aiCourseRegenerateBtn');
    const saveState = document.getElementById('aiCourseSaveState');
    btn.disabled = true;
    if (regen) regen.disabled = true;
    if (saveState) saveState.textContent = 'Saving…';
    try {
      const saved = await call('/tutorials/manage/ai/save-course', {
        method: 'POST',
        body: {
          categoryId,
          visibility: 'all',
          topic: state.aiCourse.course.topic || document.getElementById('aiCourseTopic').value.trim(),
          academicField: state.aiCourse.course.academicField || document.getElementById('aiCourseField').value,
          difficulty: state.aiCourse.course.difficulty || document.getElementById('aiCourseDifficulty').value,
          course: state.aiCourse.course,
          modules: state.aiCourse.modules,
          preferences: state.aiCourse.preferences || { mcqsPerModule: 0, practicalPreference: 'none' },
        },
      });
      state.aiSavedTutorialId = (saved && saved.tutorial && saved.tutorial.id) || '';
      document.getElementById('aiCourseSaved').classList.remove('d-none');
      if (saveState) saveState.textContent = 'Saved as draft.';
      toast('Course saved as draft.', 'success');
      await refreshList();
    } catch (err) {
      if (saveState) saveState.textContent = 'Save failed.';
      setAiError('aiCourseError', err && err.message ? err.message : 'The course could not be saved.');
      toast(err && err.message ? err.message : 'The course could not be saved.', 'error');
    } finally {
      btn.disabled = false;
      if (regen) regen.disabled = false;
    }
  }

  async function regenerateAiCourse() {
    if (state.aiCourse) {
      const ok = await confirmAction({
        title: 'Regenerate course?',
        message: 'This replaces the current preview. Edits that have not been saved will be lost. Other saved courses are not changed.',
        confirmText: 'Regenerate',
        variant: 'danger',
      });
      if (!ok) return;
    }
    await generateAiCourse();
  }

  async function leaveAiCourse() {
    if (state.aiCourse && !state.aiSavedTutorialId) {
      const ok = await confirmAction({
        title: 'Leave this preview?',
        message: 'This generated course has not been saved. Leave without saving?',
        confirmText: 'Leave',
        variant: 'danger',
      });
      if (!ok) return;
    }
    showStaffScreen('list');
  }

  function toggleAiModulePane() {
    const pane = document.getElementById('aiModulePane');
    pane.classList.toggle('d-none');
    if (!pane.classList.contains('d-none')) {
      setAiError('aiModuleError', '');
    }
  }

  function renderAiModulePreview() {
    const box = document.getElementById('aiModulePreview');
    const module = state.aiModule && state.aiModule.module;
    if (!module) {
      box.classList.add('d-none');
      return;
    }
    box.classList.remove('d-none');
    document.getElementById('aiModulePreviewTitle').value = module.title || '';
    document.getElementById('aiModulePreviewSubtitle').value = module.subtitle || '';
    document.getElementById('aiModulePreviewDescription').value = module.description || '';
    const practice = document.getElementById('aiModuleExercises');
    if (practice) {
      practice.innerHTML = renderAiLessonPractice(module);
      bindAiPractice(document.getElementById('aiModulePreview'), 'module');
    }
  }

  async function generateAiModule() {
    if (!state.active || !state.active.id) {
      toast('Open a course before generating a module.', 'error');
      return;
    }
    const topic = document.getElementById('aiModuleTopic').value.trim();
    setAiError('aiModuleError', '');
    if (!topic) {
      setAiError('aiModuleError', 'Module topic is required.');
      return;
    }
    const btn = document.getElementById('aiModuleGenerateBtn');
    btn.disabled = true;
    document.getElementById('aiModuleLoading').classList.remove('d-none');
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/ai/generate-module`, {
        method: 'POST',
        body: {
          topic,
          academicField: document.getElementById('aiModuleField').value,
          difficulty: document.getElementById('aiModuleDifficulty').value,
          additionalInstructions: document.getElementById('aiModuleInstructions').value.trim(),
          mcqsPerModule: 0,
          practicalPreference: 'none',
        },
      });
      if (!data || !data.module || !data.module.title) {
        throw new Error('AI did not return a module preview.');
      }
      state.aiModule = data;
      renderAiModulePreview();
      toast('Review the module, then save it into this course.', 'success');
    } catch (err) {
      state.aiModule = null;
      document.getElementById('aiModulePreview').classList.add('d-none');
      setAiError('aiModuleError', err && err.message ? err.message : 'Module generation failed.');
    } finally {
      btn.disabled = false;
      document.getElementById('aiModuleLoading').classList.add('d-none');
    }
  }

  async function saveAiModuleDraft() {
    if (!state.active || !state.aiModule || !state.aiModule.module) {
      setAiError('aiModuleError', 'Generate a module before saving.');
      return;
    }
    const module = state.aiModule.module;
    module.title = document.getElementById('aiModulePreviewTitle').value.trim();
    module.subtitle = document.getElementById('aiModulePreviewSubtitle').value.trim();
    module.description = document.getElementById('aiModulePreviewDescription').value.trim();
    syncAiPractice(document.getElementById('aiModulePreview'), module);
    if (!module.title) {
      setAiError('aiModuleError', 'Module title is required.');
      return;
    }
    const btn = document.getElementById('aiModuleSaveBtn');
    btn.disabled = true;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/ai/save-module`, {
        method: 'POST',
        body: {
          difficulty: document.getElementById('aiModuleDifficulty').value,
          academicField: document.getElementById('aiModuleField').value,
          module,
        },
      });
      state.aiModule = null;
      document.getElementById('aiModulePreview').classList.add('d-none');
      toast('Module saved. Course status was not changed.', 'success');
      await openModules(state.active.id);
    } catch (err) {
      setAiError('aiModuleError', err && err.message ? err.message : 'The module could not be saved.');
    } finally {
      btn.disabled = false;
    }
  }

  async function openPreview(id) {
    try {
      state.previewReturn = document.getElementById('moduleView').classList.contains('d-none') ? 'list' : 'builder';
      const course = await call(`/tutorials/manage/${encodeURIComponent(id)}`);
      state.preview = course;
      showStaffScreen('preview');
      document.getElementById('previewTitle').textContent = course.title || 'Preview';
      renderPreview(0);
    } catch (err) {
      fail(err);
    }
  }

  function renderPreview(index) {
    const course = state.preview;
    const modules = (course && course.modules) || [];
    document.getElementById('previewNav').innerHTML = modules.map((module, i) => (
      `<button type="button" class="btn btn-sm text-start ${i === index ? 'btn-primary' : 'btn-outline-secondary'}" data-preview-module="${i}">${esc(module.title)}</button>`
    )).join('') || '<p class="text-muted-2 mb-0">This course has no modules yet.</p>';
    document.querySelectorAll('[data-preview-module]').forEach((btn) => {
      btn.addEventListener('click', () => renderPreview(Number(btn.getAttribute('data-preview-module'))));
    });
    const module = modules[index];
    if (!module) {
      document.getElementById('previewModuleTitle').textContent = 'No modules yet';
      document.getElementById('previewContent').textContent = 'Add a module before students can learn this course.';
      document.getElementById('previewExercises').innerHTML = '';
      return;
    }
    document.getElementById('previewModuleTitle').textContent = module.title || '';
    if (module.content) setLessonHtml(document.getElementById('previewContent'), module.content);
    else document.getElementById('previewContent').textContent = 'This module has no lesson content yet.';
    const previewPractice = document.getElementById('previewExercises');
    if (previewPractice) previewPractice.innerHTML = '';
  }

  function paintCourseHeader() {
    const course = state.active || {};
    document.getElementById('moduleTutorialTitle').textContent = course.title || 'Course';
    document.getElementById('moduleTutorialMeta').textContent = [
      categoryName(course.categoryId),
      course.topic || '',
      visibilityText(course),
      course.status || 'draft',
    ].filter(Boolean).join(' · ');
    const publish = document.getElementById('builderPublish');
    if (publish) {
      publish.textContent = course.status === 'published' ? 'Unpublish' : 'Publish';
    }
  }

  async function openModules(id, stayOnArticle) {
    try {
      const selected = state.selectedModuleId;
      state.active = await call(`/tutorials/manage/${encodeURIComponent(id)}`);
      paintCourseHeader();
      state.creatingModule = false;
      if (!(state.active.modules || []).some((row) => row.id === selected)) {
        state.selectedModuleId = '';
      }
      showStaffScreen(stayOnArticle ? 'article' : 'builder');
      if (stayOnArticle) renderModuleExercises();
      renderModuleNav();
    } catch (err) {
      fail(err);
    }
  }

  function plainExcerpt(content) {
    const doc = parseLessonDocument(content);
    let text = '';
    if (doc) {
      text = doc.blocks.map((block) => {
        if (block.type === 'code') return `${block.source || ''} ${block.exampleOutput || ''}`;
        if (block.type === 'image') return block.alt || '';
        if (block.type === 'divider') return '';
        return block.text || '';
      }).join(' ');
    } else {
      text = String(content || '').replace(/<[^>]+>/g, ' ');
    }
    text = text.replace(/\s+/g, ' ').trim();
    return text.length > 90 ? `${text.slice(0, 87)}…` : text;
  }

  function renderModuleNav() {
    const root = document.getElementById('moduleNav');
    const modules = (state.active && state.active.modules) || [];
    if (!modules.length && !state.creatingModule) {
      root.innerHTML = '<p class="small text-muted-2 mb-0">No modules yet.</p>';
      return;
    }
    const cards = modules.map((module, index) => {
      const active = !state.creatingModule && state.selectedModuleId === module.id;
      const excerpt = plainExcerpt(module.content) || 'No lesson text yet';
      return `<div class="border rounded p-2 ${active ? 'border-primary' : ''}">
        <button type="button" class="btn btn-sm p-0 fw-semibold" data-select-module="${esc(module.id)}">${active ? '●' : '○'} ${esc(index + 1)}. ${esc(module.title)}</button>
        <div class="small text-muted-2 mb-2">${esc(excerpt)}</div>
        <div class="d-flex flex-wrap gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-up="${esc(module.id)}" ${index === 0 ? 'disabled' : ''} aria-label="Move module up">Up</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-down="${esc(module.id)}" ${index === modules.length - 1 ? 'disabled' : ''} aria-label="Move module down">Down</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-select-module="${esc(module.id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-delete-module="${esc(module.id)}">Delete</button>
        </div>
      </div>`;
    }).join('');
    const draft = state.creatingModule
      ? '<div class="border border-primary rounded p-2"><div class="fw-semibold">● New module</div><div class="small text-muted-2">Write the title and lesson on the right, then save.</div></div>'
      : '';
    root.innerHTML = cards + draft;
    root.querySelectorAll('[data-select-module]').forEach((btn) => btn.addEventListener('click', () => selectModule(btn.getAttribute('data-select-module'))));
    root.querySelectorAll('[data-delete-module]').forEach((btn) => btn.addEventListener('click', () => deleteModule(btn.getAttribute('data-delete-module'))));
    root.querySelectorAll('[data-up]').forEach((btn) => btn.addEventListener('click', () => moveModule(btn.getAttribute('data-up'), -1)));
    root.querySelectorAll('[data-down]').forEach((btn) => btn.addEventListener('click', () => moveModule(btn.getAttribute('data-down'), 1)));
  }

  function renderModuleExercises() {
    const pane = document.getElementById('moduleExercisePane');
    if (pane) pane.classList.add('d-none');
  }

  async function moveExercise(moduleId, exerciseId, direction) {
    if (!state.active) return;
    const module = (state.active.modules || []).find((row) => row.id === moduleId);
    if (!module) return;
    const exercises = [...(module.exercises || [])];
    const index = exercises.findIndex((row) => row.id === exerciseId);
    const next = index + direction;
    if (index < 0 || next < 0 || next >= exercises.length) return;
    const swap = exercises[index];
    exercises[index] = exercises[next];
    exercises[next] = swap;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(moduleId)}/exercises/reorder`, {
        method: 'POST',
        body: { exerciseIds: exercises.map((row) => row.id) },
      });
      toast('Exercise order saved.', 'success');
      await openModules(state.active.id, true);
    } catch (err) {
      fail(err);
    }
  }

  function previewExercise(moduleId, exerciseId) {
    const exercise = findExercise(moduleId, exerciseId);
    const host = document.querySelector(`[data-exercise-preview="${exerciseId}"]`);
    if (!exercise || !host) return;
    const samples = (exercise.testCases || []).filter((item) => item.sample);
    host.classList.remove('d-none');
    host.innerHTML = `<div class="small text-warning fw-semibold mb-2">STAFF PREVIEW</div>
      <div class="fw-semibold">${esc(exercise.title)}</div>
      <div class="small text-muted-2 mb-2">Language: ${esc(languageLabel(exercise.language))}</div>
      <pre class="mb-2">${esc(exercise.boilerplate || '')}</pre>
      ${samples.length ? samples.map((item, index) => `<div class="small mb-2"><div class="fw-semibold">Sample ${index + 1}</div><div>Input</div><pre>${esc(item.stdin) || '-'}</pre><div>Expected output</div><pre class="mb-0">${esc(item.expectedOutput)}</pre></div>`).join('') : '<p class="small text-muted-2 mb-0">No public sample tests.</p>'}
      <p class="small text-muted-2 mb-0">Preview does not save a student attempt.</p>`;
  }

  function languageLabel(value) {
    const hit = LANGUAGES.find((row) => row.value === value);
    return hit ? hit.label : (value || '');
  }

  function newBlockId() {
    return Math.random().toString(36).slice(2, 10);
  }

  function emptyLessonDocument() {
    return { version: 1, blocks: [{ id: newBlockId(), type: 'paragraph', text: '' }] };
  }

  function codeLanguageOptions(selected) {
    return CODE_DEMO_LANGUAGES.map((row) => (
      `<option value="${esc(row.value)}"${row.value === selected ? ' selected' : ''}>${esc(row.label)}</option>`
    )).join('');
  }

  function detectCodeLanguage(source) {
    const text = String(source || '');
    if (/^\s*</.test(text) && /<\/?[a-z]/i.test(text)) return 'html';
    if (/^\s*{[\s\S]*}\s*$/.test(text) || /^\s*\[[\s\S]*\]\s*$/.test(text)) return 'json';
    if (/\bdef\s+\w+\s*\(|\bprint\s*\(/.test(text)) return 'python';
    if (/\b(interface|type)\s+[A-Z]|\bimport\s+type\b|:\s*(string|number|boolean)\b/.test(text)) return 'typescript';
    if (/\bfunction\b|\bconst\b|\blet\b|=>/.test(text)) return 'javascript';
    if (/\bpublic\s+class\b|\bSystem\.out\.println/.test(text)) return 'java';
    if (/#include\s*</.test(text) && /\bstd::/.test(text)) return 'cpp';
    if (/#include\s*</.test(text)) return 'c';
    if (/\bSELECT\b|\bFROM\b|\bWHERE\b/i.test(text)) return 'sql';
    if (/^\s*\$|^\s*#!/.test(text)) return 'bash';
    return 'auto';
  }

  function parseLessonDocument(raw) {
    const trim = String(raw || '').trim();
    if (!trim) return null;
    if (trim.charAt(0) === '{') {
      try {
        const decoded = JSON.parse(trim);
        if (decoded && Number(decoded.version) === 1 && Array.isArray(decoded.blocks)) {
          return normalizeLessonDocument(decoded);
        }
      } catch { /* fall through to HTML migrate */ }
    }
    return null;
  }

  function normalizeLessonDocument(doc) {
    const blocks = (doc.blocks || []).map((block) => {
      const id = String(block.id || newBlockId()).replace(/[^a-zA-Z0-9_-]/g, '') || newBlockId();
      const type = String(block.type || '');
      if (type === 'paragraph' || type === 'quote') {
        return { id, type, text: String(block.text || '') };
      }
      if (type === 'heading') {
        return { id, type: 'heading', level: Number(block.level) === 3 ? 3 : 2, text: String(block.text || '') };
      }
      if (type === 'code') {
        const language = CODE_DEMO_LANGUAGES.some((row) => row.value === block.language) ? block.language : 'auto';
        return {
          id,
          type: 'code',
          language,
          source: String(block.source || ''),
          exampleOutput: String(block.exampleOutput || ''),
        };
      }
      if (type === 'image') {
        return { id, type: 'image', url: String(block.url || ''), alt: String(block.alt || '') };
      }
      if (type === 'divider') {
        return { id, type: 'divider' };
      }
      return null;
    }).filter(Boolean);
    return { version: 1, blocks: blocks.length ? blocks : emptyLessonDocument().blocks };
  }

  function htmlToLessonDocument(html) {
    const empty = emptyLessonDocument();
    const trim = String(html || '').trim();
    if (!trim) return empty;
    const doc = new DOMParser().parseFromString(trim, 'text/html');
    const blocks = [];
    const pushText = (type, text, level) => {
      const value = String(text || '').replace(/\u00a0/g, ' ').trim();
      if (!value && type !== 'paragraph') return;
      if (type === 'heading') blocks.push({ id: newBlockId(), type: 'heading', level: level === 3 ? 3 : 2, text: value });
      else blocks.push({ id: newBlockId(), type, text: value });
    };
    [...doc.body.childNodes].forEach((node) => {
      if (node.nodeType === 3) {
        const text = node.textContent.replace(/\s+/g, ' ').trim();
        if (text) pushText('paragraph', text);
        return;
      }
      if (node.nodeType !== 1) return;
      const tag = node.tagName.toLowerCase();
      if (tag === 'h2') pushText('heading', node.textContent, 2);
      else if (tag === 'h3') pushText('heading', node.textContent, 3);
      else if (tag === 'blockquote') pushText('quote', node.textContent);
      else if (tag === 'hr') blocks.push({ id: newBlockId(), type: 'divider' });
      else if (tag === 'img') {
        const url = node.getAttribute('src') || '';
        if (/^(\/|https?:\/\/)/i.test(url)) blocks.push({ id: newBlockId(), type: 'image', url, alt: node.getAttribute('alt') || '' });
      } else if (tag === 'pre') {
        const code = node.querySelector('code');
        const source = code ? code.textContent : node.textContent;
        const language = node.getAttribute('data-language') || 'auto';
        const role = node.getAttribute('data-role') === 'output' ? 'output' : 'code';
        if (role === 'output' && blocks.length && blocks[blocks.length - 1].type === 'code') {
          blocks[blocks.length - 1].exampleOutput = source;
        } else {
          blocks.push({
            id: newBlockId(),
            type: 'code',
            language: CODE_DEMO_LANGUAGES.some((row) => row.value === language) ? language : 'auto',
            source: role === 'output' ? '' : source,
            exampleOutput: role === 'output' ? source : '',
          });
        }
      }       else if (tag === 'figure') {
        const img = node.querySelector('img');
        if (img && /^(\/|https?:\/\/)/i.test(img.getAttribute('src') || '')) {
          blocks.push({ id: newBlockId(), type: 'image', url: img.getAttribute('src'), alt: img.getAttribute('alt') || node.textContent });
        }
      } else if (tag === 'p' || tag === 'div') {
        const img = node.querySelector('img');
        if (img && /^(\/|https?:\/\/)/i.test(img.getAttribute('src') || '')) {
          blocks.push({ id: newBlockId(), type: 'image', url: img.getAttribute('src'), alt: img.getAttribute('alt') || '' });
        } else {
          pushText('paragraph', node.textContent);
        }
      } else {
        pushText('paragraph', node.textContent);
      }
    });
    return { version: 1, blocks: blocks.length ? blocks : empty.blocks };
  }

  function lessonFromContent(raw) {
    return parseLessonDocument(raw) || htmlToLessonDocument(raw);
  }

  function serializeLessonDocument() {
    syncLessonFromDom();
    const doc = normalizeLessonDocument(state.lesson || emptyLessonDocument());
    state.lesson = doc;
    return JSON.stringify(doc);
  }

  function blockIndex(id) {
    return (state.lesson.blocks || []).findIndex((block) => block.id === id);
  }

  function syncLessonFromDom() {
    if (!state.lesson) return;
    const root = document.getElementById('lessonBlocks');
    if (!root) return;
    state.lesson.blocks.forEach((block) => {
      const row = root.querySelector(`[data-block-id="${CSS.escape(block.id)}"]`);
      if (!row) return;
      if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'heading') {
        const editable = row.querySelector('[data-editable]');
        if (editable) block.text = editable.innerText.replace(/\u00a0/g, ' ').replace(/\n+$/, '');
      } else if (block.type === 'code') {
        const source = row.querySelector('[data-code-source]');
        const output = row.querySelector('[data-code-output]');
        const select = row.querySelector('[data-code-language]');
        if (source) block.source = source.value;
        if (output) block.exampleOutput = output.value;
        if (select) block.language = select.value;
      } else if (block.type === 'image') {
        const alt = row.querySelector('[data-image-alt]');
        if (alt) block.alt = alt.value;
      }
    });
  }

  function ensureTrailingParagraph(afterId) {
    const index = blockIndex(afterId);
    if (index < 0) return null;
    const next = state.lesson.blocks[index + 1];
    if (next && next.type === 'paragraph' && !(next.text || '').trim()) return next.id;
    const paragraph = { id: newBlockId(), type: 'paragraph', text: '' };
    state.lesson.blocks.splice(index + 1, 0, paragraph);
    return paragraph.id;
  }

  function continueFocus(block, options) {
    const opts = options || {};
    let focusId = block.id;
    let focusField = opts.focusField || (block.type === 'code' ? 'code' : 'text');
    if (opts.withParagraph !== false && block.type !== 'paragraph') {
      const paragraphId = ensureTrailingParagraph(block.id);
      if (paragraphId && opts.focusInserted !== true) {
        focusId = paragraphId;
        focusField = 'text';
      }
    }
    state.activeBlockId = focusId;
    state.insertAfterId = '';
    renderLessonEditor(focusId, focusField);
  }

  function insertBlockAfter(afterId, block, options) {
    const index = afterId ? blockIndex(afterId) : state.lesson.blocks.length - 1;
    const at = index < 0 ? state.lesson.blocks.length : index + 1;
    state.lesson.blocks.splice(at, 0, block);
    continueFocus(block, options);
    return block.id;
  }

  function replaceBlock(id, nextBlock, options) {
    const index = blockIndex(id);
    if (index < 0) return;
    state.lesson.blocks[index] = nextBlock;
    continueFocus(nextBlock, options);
  }

  function removeBlock(id) {
    if (!state.lesson || state.lesson.blocks.length <= 1) {
      state.lesson = emptyLessonDocument();
      renderLessonEditor(state.lesson.blocks[0].id);
      return;
    }
    const index = blockIndex(id);
    if (index < 0) return;
    state.lesson.blocks.splice(index, 1);
    const focus = state.lesson.blocks[Math.max(0, index - 1)] || state.lesson.blocks[0];
    state.activeBlockId = focus.id;
    renderLessonEditor(focus.id);
  }

  function focusBlock(id, field) {
    const root = document.getElementById('lessonBlocks');
    if (!root) return;
    const row = root.querySelector(`[data-block-id="${CSS.escape(id)}"]`);
    if (!row) return;
    root.querySelectorAll('.lesson-row').forEach((node) => node.classList.toggle('is-active', node === row));
    state.activeBlockId = id;
    let target = null;
    if (field === 'code') target = row.querySelector('[data-code-source]');
    else if (field === 'output') target = row.querySelector('[data-code-output]');
    else target = row.querySelector('[data-editable], [data-code-source], [data-image-alt]');
    if (!target) return;
    target.focus();
    if (target.isContentEditable) {
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(target);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    } else if (typeof target.setSelectionRange === 'function') {
      const end = target.value.length;
      target.setSelectionRange(end, end);
    }
  }

  function mountEditor(content) {
    state.lesson = lessonFromContent(content || '');
    state.activeBlockId = state.lesson.blocks[0] ? state.lesson.blocks[0].id : '';
    state.insertAfterId = '';
    toggleInsertMenu(false);
    renderLessonEditor(state.activeBlockId);
  }

  function openInsertMenuForRow(row, blockId) {
    const menu = document.getElementById('articleInsertMenu');
    const root = document.getElementById('lessonBlocks');
    if (!menu || !root || !row) return;
    state.insertAfterId = blockId;
    state.activeBlockId = blockId;
    root.querySelectorAll('.lesson-row').forEach((node) => node.classList.toggle('is-active', node === row));
    row.after(menu);
    toggleInsertMenu(true);
  }

  function renderLessonEditor(focusId, focusField) {
    const root = document.getElementById('lessonBlocks');
    const menu = document.getElementById('articleInsertMenu');
    if (!root || !state.lesson) return;
    if (menu) {
      menu.classList.add('d-none');
      root.parentElement.appendChild(menu);
    }
    root.innerHTML = '';
    state.lesson.blocks.forEach((block) => {
      const row = document.createElement('div');
      row.className = `lesson-row${block.id === state.activeBlockId ? ' is-active' : ''}`;
      row.dataset.blockId = block.id;
      row.dataset.blockType = block.type;

      const plus = document.createElement('button');
      plus.type = 'button';
      plus.className = 'row-plus';
      plus.setAttribute('aria-label', 'Insert block');
      plus.textContent = '+';
      plus.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        syncLessonFromDom();
        openInsertMenuForRow(row, block.id);
      });

      const main = document.createElement('div');
      main.className = 'lesson-main';
      main.appendChild(buildEditableBlock(block));
      row.append(plus, main);
      row.addEventListener('focusin', () => {
        state.activeBlockId = block.id;
        root.querySelectorAll('.lesson-row').forEach((node) => node.classList.toggle('is-active', node === row));
      });
      root.appendChild(row);
    });
    if (focusId) focusBlock(focusId, focusField);
  }

  function buildEditableBlock(block) {
    if (block.type === 'heading') {
      const el = document.createElement('div');
      el.className = block.level === 3 ? 'lesson-heading lesson-subhead' : 'lesson-heading';
      el.dataset.editable = '1';
      el.contentEditable = 'true';
      el.dataset.placeholder = block.level === 3 ? 'Subheading' : 'Heading';
      el.textContent = block.text || '';
      wireTextBlock(el, block);
      return el;
    }
    if (block.type === 'quote') {
      const el = document.createElement('div');
      el.className = 'lesson-quote';
      el.dataset.editable = '1';
      el.contentEditable = 'true';
      el.dataset.placeholder = 'Quote';
      el.textContent = block.text || '';
      wireTextBlock(el, block);
      return el;
    }
    if (block.type === 'code') {
      const wrap = document.createElement('div');
      wrap.className = 'tutorial-code-block';
      wrap.innerHTML = `<div class="tutorial-code-bar"><label class="tutorial-code-lang-label"><span>Language</span><select data-code-language aria-label="Code language for this block">${codeLanguageOptions(block.language || 'auto')}</select></label><span data-code-detected class="tutorial-code-detected"></span><span class="d-flex gap-1"><button type="button" data-code-copy>Copy</button><button type="button" data-code-delete>Delete</button></span></div><textarea data-code-source spellcheck="false" aria-label="Code" placeholder="Write or paste code"></textarea><div class="tutorial-code-note">Example output is optional and is typed by the tutor. Code is never executed here.</div><textarea data-code-output spellcheck="false" aria-label="Example output" placeholder="Example output (optional)"></textarea>`;
      const source = wrap.querySelector('[data-code-source]');
      const output = wrap.querySelector('[data-code-output]');
      const select = wrap.querySelector('[data-code-language]');
      const detected = wrap.querySelector('[data-code-detected]');
      source.value = block.source || '';
      output.value = block.exampleOutput || '';
      const refreshDetected = () => {
        if (!detected) return;
        if (select.value !== 'auto') {
          detected.textContent = '';
          return;
        }
        const guessed = detectCodeLanguage(source.value);
        const label = (CODE_DEMO_LANGUAGES.find((row) => row.value === guessed) || {}).label;
        detected.textContent = guessed !== 'auto' && label ? `Detected: ${label}` : '';
      };
      refreshDetected();
      source.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
          event.preventDefault();
          const start = source.selectionStart;
          const end = source.selectionEnd;
          source.value = `${source.value.slice(0, start)}  ${source.value.slice(end)}`;
          source.selectionStart = source.selectionEnd = start + 2;
        }
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          syncLessonFromDom();
          const focusId = ensureTrailingParagraph(block.id);
          state.activeBlockId = focusId;
          renderLessonEditor(focusId, 'text');
        }
      });
      source.addEventListener('input', () => {
        block.source = source.value;
        refreshDetected();
      });
      output.addEventListener('input', () => { block.exampleOutput = output.value; });
      select.addEventListener('change', () => {
        block.language = select.value;
        refreshDetected();
      });
      wrap.querySelector('[data-code-copy]').addEventListener('click', () => {
        if (navigator.clipboard) navigator.clipboard.writeText(source.value).then(() => toast('Copied.', 'success')).catch(() => {});
      });
      wrap.querySelector('[data-code-delete]').addEventListener('click', () => {
        syncLessonFromDom();
        removeBlock(block.id);
      });
      return wrap;
    }
    if (block.type === 'image') {
      const wrap = document.createElement('div');
      wrap.className = 'lesson-image';
      wrap.innerHTML = `<img src="${esc(block.url)}" alt="${esc(block.alt || '')}"/><input type="text" class="form-control form-control-sm mt-2" data-image-alt maxlength="180" placeholder="Image description" value="${esc(block.alt || '')}"/><div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-outline-danger" data-image-delete>Delete image</button></div>`;
      wrap.querySelector('[data-image-alt]').addEventListener('input', (event) => { block.alt = event.target.value; });
      wrap.querySelector('[data-image-delete]').addEventListener('click', () => {
        syncLessonFromDom();
        removeBlock(block.id);
      });
      return wrap;
    }
    if (block.type === 'divider') {
      const wrap = document.createElement('div');
      wrap.className = 'lesson-divider d-flex align-items-center gap-2';
      wrap.innerHTML = '<hr class="flex-grow-1"/><button type="button" class="btn btn-sm btn-outline-danger" data-divider-delete>Delete</button>';
      wrap.querySelector('[data-divider-delete]').addEventListener('click', () => {
        syncLessonFromDom();
        removeBlock(block.id);
      });
      return wrap;
    }
    const el = document.createElement('div');
    el.className = 'lesson-paragraph';
    el.dataset.editable = '1';
    el.contentEditable = 'true';
    el.dataset.placeholder = 'Tell your story…';
    el.textContent = block.text || '';
    wireTextBlock(el, block);
    return el;
  }

  function wireTextBlock(el, block) {
    el.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        syncLessonFromDom();
        const paragraph = { id: newBlockId(), type: 'paragraph', text: '' };
        insertBlockAfter(block.id, paragraph, { withParagraph: false, focusField: 'text' });
        return;
      }
      if (event.key === 'Backspace' && !(el.innerText || '').replace(/\n/g, '').trim()) {
        const index = blockIndex(block.id);
        if (index > 0) {
          event.preventDefault();
          syncLessonFromDom();
          removeBlock(block.id);
        }
      }
    });
    el.addEventListener('input', () => {
      const text = el.innerText.replace(/\u00a0/g, ' ');
      block.text = text.replace(/\n+$/, '');
      if (block.type !== 'paragraph') return;
      if (text.trim() === '/') {
        el.textContent = '';
        block.text = '';
        openInsertMenuForRow(el.closest('.lesson-row'), block.id);
        return;
      }
      const fence = text.trim().match(/^```([A-Za-z0-9+#.]*)?$/);
      if (fence) {
        const aliases = { js: 'javascript', ts: 'typescript', py: 'python', 'c++': 'cpp', 'c#': 'csharp', cs: 'csharp' };
        const token = String(fence[1] || '').toLowerCase();
        const mapped = aliases[token] || token;
        const language = CODE_DEMO_LANGUAGES.some((row) => row.value === mapped) ? mapped : 'auto';
        el.textContent = '';
        block.text = '';
        replaceBlock(block.id, {
          id: block.id,
          type: 'code',
          language,
          source: '',
          exampleOutput: '',
        });
      }
    });
  }

  function openArticleShell() {
    document.getElementById('articleCourseName').textContent = state.active ? (state.active.title || '') : '';
    document.getElementById('articleStatus').textContent = state.creatingModule ? 'Draft' : (state.active && state.active.status === 'published' ? 'Published' : 'Draft');
    toggleInsertMenu(false);
    showStaffScreen('article');
  }

  function beginNewModule() {
    if (!state.active) return;
    state.creatingModule = true;
    state.selectedModuleId = '';
    document.getElementById('moduleId').value = '';
    document.getElementById('moduleTitle').value = '';
    document.getElementById('moduleSubtitle').value = '';
    document.getElementById('moduleExercisePane').classList.add('d-none');
    document.getElementById('moduleAssessmentPane').classList.add('d-none');
    document.getElementById('moduleActivityPane').classList.add('d-none');
    document.getElementById('exerciseForm').classList.add('d-none');
    resetActivityForm();
    openArticleShell();
    mountEditor('');
    document.getElementById('moduleTitle').focus();
  }

  function selectModule(id) {
    if (!state.active || !id) return;
    const module = (state.active.modules || []).find((row) => row.id === id);
    if (!module) return;
    state.creatingModule = false;
    state.selectedModuleId = id;
    document.getElementById('moduleId').value = module.id;
    document.getElementById('moduleTitle').value = module.title || '';
    document.getElementById('moduleSubtitle').value = module.subtitle || '';
    document.getElementById('exerciseForm').classList.add('d-none');
    openArticleShell();
    renderModuleExercises();
    mountEditor(module.content || '');
    loadModuleAssessment().catch(fail);
    loadModuleActivities().catch(fail);
    if (!(module.title || '').trim()) document.getElementById('moduleTitle').focus();
  }

  function blankQuestion() {
    return {
      id: '',
      question: '',
      options: ['', '', '', ''],
      correctIndex: 0,
      explanation: '',
      difficulty: 'beginner',
      marks: 1,
    };
  }

  function assessmentSettingsFromForm() {
    return {
      title: document.getElementById('assessmentTitle').value.trim() || 'Module quiz',
      passPercent: Number(document.getElementById('assessmentPass').value) || 60,
      maxAttempts: Number(document.getElementById('assessmentMaxAttempts').value) || 3,
      status: document.getElementById('assessmentStatus').value === 'published' ? 'published' : 'draft',
      showExplanations: document.getElementById('assessmentShowExplanations').checked,
      allowReview: document.getElementById('assessmentAllowReview').checked,
    };
  }

  function fillAssessmentSettings(assessment) {
    document.getElementById('assessmentTitle').value = (assessment && assessment.title) || 'Module quiz';
    document.getElementById('assessmentPass').value = assessment ? (assessment.passPercent ?? 60) : 60;
    document.getElementById('assessmentMaxAttempts').value = assessment ? (assessment.maxAttempts ?? 3) : 3;
    document.getElementById('assessmentStatus').value = assessment && assessment.status === 'published' ? 'published' : 'draft';
    document.getElementById('assessmentShowExplanations').checked = !assessment || assessment.showExplanations !== false;
    document.getElementById('assessmentAllowReview').checked = !assessment || assessment.allowReview !== false;
  }

  function renderAssessmentQuestions() {
    const root = document.getElementById('assessmentQuestionList');
    const questions = state.assessmentQuestions || [];
    const total = questions.reduce((sum, row) => sum + (Number(row.marks) || 1), 0);
    document.getElementById('assessmentMeta').textContent = questions.length
      ? `${questions.length} question${questions.length === 1 ? '' : 's'} · ${total} marks · ${(state.assessment && state.assessment.status) || 'draft'}`
      : 'No questions yet';
    if (!questions.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">Add questions manually or generate with AI.</p>';
      return;
    }
    root.innerHTML = questions.map((row, index) => {
      const options = (row.options || ['', '', '', '']).slice(0, 4);
      while (options.length < 4) options.push('');
      return `<div class="border rounded p-3" data-q-index="${index}">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
          <div class="fw-semibold">Question ${index + 1}</div>
          <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-q-up ${index === 0 ? 'disabled' : ''}>↑</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-q-down ${index === questions.length - 1 ? 'disabled' : ''}>↓</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-q-delete>Delete</button>
          </div>
        </div>
        <label class="form-label">Question</label>
        <textarea class="form-control form-control-sm mb-2" data-q-field="question" rows="2">${esc(row.question || '')}</textarea>
        ${options.map((opt, oi) => (
          `<div class="input-group input-group-sm mb-1">
            <div class="input-group-text"><input class="form-check-input mt-0" type="radio" name="qCorrect${index}" data-q-correct value="${oi}" ${Number(row.correctIndex) === oi ? 'checked' : ''}/></div>
            <input class="form-control" data-q-option="${oi}" value="${esc(opt)}" placeholder="Option ${String.fromCharCode(65 + oi)}"/>
          </div>`
        )).join('')}
        <div class="row g-2 mt-1">
          <div class="col-md-4"><label class="form-label">Difficulty</label>
            <select class="form-select form-select-sm" data-q-field="difficulty">
              <option value="beginner" ${row.difficulty === 'beginner' ? 'selected' : ''}>Beginner</option>
              <option value="intermediate" ${row.difficulty === 'intermediate' ? 'selected' : ''}>Intermediate</option>
              <option value="advanced" ${row.difficulty === 'advanced' ? 'selected' : ''}>Advanced</option>
            </select>
          </div>
          <div class="col-md-2"><label class="form-label">Marks</label><input class="form-control form-control-sm" type="number" min="1" max="20" data-q-field="marks" value="${esc(row.marks || 1)}"/></div>
          <div class="col-md-6"><label class="form-label">Explanation</label><textarea class="form-control form-control-sm" data-q-field="explanation" rows="2">${esc(row.explanation || '')}</textarea></div>
        </div>
      </div>`;
    }).join('');
    root.querySelectorAll('[data-q-index]').forEach((card) => {
      const index = Number(card.getAttribute('data-q-index'));
      card.querySelectorAll('[data-q-field]').forEach((input) => {
        input.addEventListener('change', () => {
          const field = input.getAttribute('data-q-field');
          state.assessmentQuestions[index][field] = field === 'marks' ? Number(input.value) || 1 : input.value;
          if (field === 'marks') document.getElementById('assessmentMeta').textContent = `${state.assessmentQuestions.length} questions`;
        });
        input.addEventListener('input', () => {
          const field = input.getAttribute('data-q-field');
          state.assessmentQuestions[index][field] = field === 'marks' ? Number(input.value) || 1 : input.value;
        });
      });
      card.querySelectorAll('[data-q-option]').forEach((input) => {
        input.addEventListener('input', () => {
          const oi = Number(input.getAttribute('data-q-option'));
          if (!Array.isArray(state.assessmentQuestions[index].options)) state.assessmentQuestions[index].options = ['', '', '', ''];
          state.assessmentQuestions[index].options[oi] = input.value;
        });
      });
      card.querySelectorAll('[data-q-correct]').forEach((input) => {
        input.addEventListener('change', () => {
          if (input.checked) state.assessmentQuestions[index].correctIndex = Number(input.value);
        });
      });
      const up = card.querySelector('[data-q-up]');
      const down = card.querySelector('[data-q-down]');
      const del = card.querySelector('[data-q-delete]');
      if (up) up.addEventListener('click', () => {
        if (index <= 0) return;
        const list = state.assessmentQuestions;
        [list[index - 1], list[index]] = [list[index], list[index - 1]];
        renderAssessmentQuestions();
      });
      if (down) down.addEventListener('click', () => {
        const list = state.assessmentQuestions;
        if (index >= list.length - 1) return;
        [list[index + 1], list[index]] = [list[index], list[index + 1]];
        renderAssessmentQuestions();
      });
      if (del) del.addEventListener('click', () => {
        state.assessmentQuestions.splice(index, 1);
        renderAssessmentQuestions();
      });
    });
  }

  function renderAssessmentPreviewList() {
    const box = document.getElementById('assessmentPreviewBox');
    const list = document.getElementById('assessmentPreviewList');
    const preview = state.assessmentPreview;
    if (!preview || !(preview.questions || []).length) {
      box.classList.add('d-none');
      list.innerHTML = '';
      return;
    }
    box.classList.remove('d-none');
    list.innerHTML = preview.questions.map((row, index) => (
      `<label class="border rounded p-2 d-flex gap-2 align-items-start">
        <input type="checkbox" class="form-check-input mt-1" data-preview-select="${index}" ${row.selected === false ? '' : 'checked'}/>
        <div class="flex-grow-1">
          <div class="fw-semibold">${esc(row.question || '')}</div>
          <ol class="mb-1 small">${(row.options || []).map((opt) => `<li>${esc(opt)}</li>`).join('')}</ol>
          <div class="small text-muted-2">Answer: ${String.fromCharCode(65 + (Number(row.correctIndex) || 0))} · ${esc(row.difficulty || 'beginner')} · ${esc(row.marks || 1)} mark(s)</div>
          <div class="small">${esc(row.explanation || '')}</div>
        </div>
      </label>`
    )).join('');
    list.querySelectorAll('[data-preview-select]').forEach((input) => {
      input.addEventListener('change', () => {
        const index = Number(input.getAttribute('data-preview-select'));
        if (state.assessmentPreview.questions[index]) {
          state.assessmentPreview.questions[index].selected = input.checked;
        }
      });
    });
  }

  async function loadModuleAssessment() {
    const pane = document.getElementById('moduleAssessmentPane');
    if (!state.active || !state.selectedModuleId) {
      pane.classList.add('d-none');
      return;
    }
    pane.classList.remove('d-none');
    document.getElementById('assessmentPreviewBox').classList.add('d-none');
    document.getElementById('assessmentStudentPreview').classList.add('d-none');
    state.assessmentPreview = null;
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/assessment`);
      state.assessment = data.assessment || null;
      state.assessmentQuestions = (data.questions || []).map((row) => ({
        id: row.id || '',
        question: row.question || '',
        options: (row.options || ['', '', '', '']).slice(0, 4),
        correctIndex: Number(row.correctIndex ?? row.correctAnswer ?? 0),
        explanation: row.explanation || '',
        difficulty: row.difficulty || 'beginner',
        marks: Number(row.marks) || 1,
      }));
      fillAssessmentSettings(state.assessment);
      renderAssessmentQuestions();
    } catch (err) {
      state.assessment = null;
      state.assessmentQuestions = [];
      fillAssessmentSettings(null);
      renderAssessmentQuestions();
      if (err && err.status && err.status !== 404) fail(err);
    }
  }

  async function generateAssessmentQuestions() {
    if (!state.active || !state.selectedModuleId) {
      toast('Save the module before generating MCQs.', 'error');
      return;
    }
    const btn = document.getElementById('assessmentGenerateBtn');
    btn.disabled = true;
    btn.textContent = 'Generating…';
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/assessment/generate`, {
        method: 'POST',
        body: {
          questionCount: Number(document.getElementById('assessmentGenCount').value) || 5,
          difficulty: document.getElementById('assessmentGenDifficulty').value,
          additionalInstructions: document.getElementById('assessmentGenInstructions').value.trim(),
        },
      });
      state.assessmentPreview = data;
      (state.assessmentPreview.questions || []).forEach((row) => { row.selected = true; });
      renderAssessmentPreviewList();
      toast('Review the generated questions, then approve to add them.', 'success');
    } catch (err) {
      fail(err);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Generate';
    }
  }

  async function approveGeneratedQuestions() {
    if (!state.assessmentPreview || !state.active || !state.selectedModuleId) return;
    const selected = (state.assessmentPreview.questions || []).filter((row) => row.selected !== false);
    if (!selected.length) {
      toast('Select at least one question to approve.', 'error');
      return;
    }
    const replace = !(state.assessmentQuestions || []).length;
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/assessment/save-generated`, {
        method: 'POST',
        body: {
          ...assessmentSettingsFromForm(),
          status: 'draft',
          replaceExisting: replace,
          questions: selected,
        },
      });
      state.assessment = data.assessment || null;
      state.assessmentQuestions = (data.questions || []).map((row) => ({
        id: row.id || '',
        question: row.question || '',
        options: (row.options || ['', '', '', '']).slice(0, 4),
        correctIndex: Number(row.correctIndex ?? 0),
        explanation: row.explanation || '',
        difficulty: row.difficulty || 'beginner',
        marks: Number(row.marks) || 1,
      }));
      state.assessmentPreview = null;
      renderAssessmentPreviewList();
      fillAssessmentSettings(state.assessment);
      renderAssessmentQuestions();
      toast(replace ? 'Generated questions saved as draft.' : 'Approved questions appended as draft.', 'success');
    } catch (err) {
      fail(err);
    }
  }

  async function saveAssessmentEditor() {
    if (!state.active || !state.selectedModuleId) {
      toast('Save the module before saving an assessment.', 'error');
      return;
    }
    if (!(state.assessmentQuestions || []).length) {
      toast('Add at least one question before saving.', 'error');
      return;
    }
    const settings = assessmentSettingsFromForm();
    if (settings.status === 'published' && state.active.status !== 'published') {
      toast('Publish the course before publishing the assessment, or save as draft.', 'error');
      return;
    }
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/assessment/save`, {
        method: 'POST',
        body: {
          ...settings,
          questions: state.assessmentQuestions.map((row) => ({
            question: row.question,
            options: row.options,
            correctIndex: Number(row.correctIndex) || 0,
            explanation: row.explanation,
            difficulty: row.difficulty || 'beginner',
            marks: Number(row.marks) || 1,
          })),
        },
      });
      state.assessment = data.assessment || null;
      state.assessmentQuestions = (data.questions || []).map((row) => ({
        id: row.id || '',
        question: row.question || '',
        options: (row.options || ['', '', '', '']).slice(0, 4),
        correctIndex: Number(row.correctIndex ?? 0),
        explanation: row.explanation || '',
        difficulty: row.difficulty || 'beginner',
        marks: Number(row.marks) || 1,
      }));
      fillAssessmentSettings(state.assessment);
      renderAssessmentQuestions();
      toast('Assessment saved.', 'success');
    } catch (err) {
      fail(err);
    }
  }

  function previewAssessmentStudent() {
    const box = document.getElementById('assessmentStudentPreview');
    const body = document.getElementById('assessmentStudentPreviewBody');
    const questions = state.assessmentQuestions || [];
    if (!questions.length) {
      toast('Add questions before previewing.', 'error');
      return;
    }
    const settings = assessmentSettingsFromForm();
    const total = questions.reduce((sum, row) => sum + (Number(row.marks) || 1), 0);
    body.innerHTML = `<div class="mb-2"><strong>${esc(settings.title)}</strong> · ${questions.length} questions · ${total} marks · pass ${settings.passPercent}%</div>`
      + questions.map((row, index) => (
        `<div class="mb-3"><div class="fw-semibold">Q${index + 1}. ${esc(row.question || '')}</div>
        ${(row.options || []).map((opt, oi) => `<div class="form-check"><input class="form-check-input" type="radio" disabled/><label class="form-check-label">${String.fromCharCode(65 + oi)}. ${esc(opt)}</label></div>`).join('')}</div>`
      )).join('');
    box.classList.remove('d-none');
  }

  const ACTIVITY_TYPE_LABELS = {
    programming_task: 'Programming',
    sql_query: 'SQL',
    numerical: 'Numerical',
    short_answer: 'Short answer',
    case_study: 'Case study',
    analytical_design: 'Analytical / design',
  };

  function activityEvalModesForType(type) {
    if (type === 'numerical') return ['none', 'tutor_review', 'auto_compare'];
    if (type === 'short_answer') return ['none', 'tutor_review', 'self_check'];
    return ['none', 'tutor_review'];
  }

  function syncActivityEvalModeOptions(selected) {
    const type = document.getElementById('activityType').value;
    const select = document.getElementById('activityEvalMode');
    const modes = activityEvalModesForType(type);
    const current = selected || select.value;
    select.innerHTML = modes.map((mode) => (
      `<option value="${mode}">${esc(mode.replace(/_/g, ' '))}</option>`
    )).join('');
    select.value = modes.includes(current) ? current : modes[modes.length - 1];
  }

  function renderActivityTypeFields(activity) {
    const root = document.getElementById('activityTypeFields');
    const type = document.getElementById('activityType').value;
    const config = (activity && activity.config) || {};
    const key = (activity && activity.answerKey) || {};
    if (type === 'programming_task') {
      root.innerHTML = `
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label" for="actLang">Language</label>
            <select class="form-select form-select-sm" id="actLang">
              ${LANGUAGES.map((row) => `<option value="${esc(row.value)}" ${((config.language || 'python') === row.value) ? 'selected' : ''}>${esc(row.label)}</option>`).join('')}
              <option value="text" ${(config.language || '') === 'text' ? 'selected' : ''}>Plain text</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label" for="actBoilerplate">Starter / boilerplate code</label><textarea class="form-control form-control-sm font-monospace" id="actBoilerplate" rows="6" spellcheck="false">${esc(config.boilerplate || '')}</textarea><div class="form-text">Stored as text only. Not executed.</div></div>
          <div class="col-12"><label class="form-label" for="actModelAnswer">Optional model solution (staff only)</label><textarea class="form-control form-control-sm font-monospace" id="actModelAnswer" rows="3">${esc(key.modelAnswer || '')}</textarea></div>
        </div>`;
      return;
    }
    if (type === 'sql_query') {
      root.innerHTML = `
        <div class="row g-2">
          <div class="col-12"><label class="form-label" for="actSchema">Database schema description</label><textarea class="form-control form-control-sm font-monospace" id="actSchema" rows="4">${esc(config.schemaDescription || '')}</textarea></div>
          <div class="col-12"><label class="form-label" for="actModelAnswer">Optional expected result / model query (staff only)</label><textarea class="form-control form-control-sm font-monospace" id="actModelAnswer" rows="3">${esc(key.modelAnswer || '')}</textarea><div class="form-text">SQL is never executed.</div></div>
        </div>`;
      return;
    }
    if (type === 'numerical') {
      root.innerHTML = `
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label" for="actExpected">Expected numeric answer</label><input class="form-control form-control-sm" id="actExpected" type="number" step="any" value="${esc(key.expectedValue != null ? key.expectedValue : '')}"/></div>
          <div class="col-md-4"><label class="form-label" for="actUnit">Units</label><input class="form-control form-control-sm" id="actUnit" maxlength="40" value="${esc(config.unit || '')}"/></div>
          <div class="col-md-4"><label class="form-label" for="actTolerance">Tolerance</label><input class="form-control form-control-sm" id="actTolerance" type="number" min="0" step="any" value="${esc(config.tolerance != null ? config.tolerance : 0)}"/></div>
          <div class="col-12"><div class="form-text">Expected answer is staff-only and will not appear in student views.</div></div>
        </div>`;
      return;
    }
    if (type === 'short_answer') {
      root.innerHTML = `
        <div class="row g-2">
          <div class="col-12"><label class="form-label" for="actModelAnswer">Model answer / evaluation guidance (staff only)</label><textarea class="form-control form-control-sm" id="actModelAnswer" rows="3">${esc(key.modelAnswer || '')}</textarea></div>
          <div class="col-12"><label class="form-label" for="actKeywords">Keywords (comma-separated, for self-check)</label><input class="form-control form-control-sm" id="actKeywords" value="${esc((key.keywords || []).join(', '))}"/></div>
          <div class="col-12"><label class="form-label" for="actRubric">Self-check rubric (shown after submit when mode is self-check)</label><textarea class="form-control form-control-sm" id="actRubric" rows="2">${esc(config.selfCheckRubric || '')}</textarea></div>
        </div>`;
      return;
    }
    if (type === 'case_study') {
      const parts = (config.parts && config.parts.length) ? config.parts : [{ id: 'part-1', prompt: '' }, { id: 'part-2', prompt: '' }];
      root.innerHTML = `
        <div class="fw-semibold mb-2">Response sections</div>
        <div id="actPartsList" class="d-flex flex-column gap-2 mb-2">
          ${parts.map((part, index) => `
            <div class="border rounded p-2" data-part-index="${index}" data-part-id="${esc(part.id || (`part-${index + 1}`))}">
              <label class="form-label">Part ${index + 1} prompt</label>
              <textarea class="form-control form-control-sm" data-part-prompt rows="2">${esc(part.prompt || '')}</textarea>
            </div>`).join('')}
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="actAddPartBtn">+ Add part</button>
        <label class="form-label" for="actModelAnswer">Optional evaluation rubric (staff only)</label>
        <textarea class="form-control form-control-sm" id="actModelAnswer" rows="2">${esc(key.modelAnswer || '')}</textarea>`;
      const addBtn = document.getElementById('actAddPartBtn');
      if (addBtn) {
        addBtn.addEventListener('click', () => {
          const list = document.getElementById('actPartsList');
          const index = list.children.length;
          if (index >= 12) {
            toast('A case study can have at most 12 parts.', 'error');
            return;
          }
          const wrap = document.createElement('div');
          wrap.className = 'border rounded p-2';
          wrap.setAttribute('data-part-index', String(index));
          wrap.setAttribute('data-part-id', `part-${index + 1}`);
          wrap.innerHTML = `<label class="form-label">Part ${index + 1} prompt</label><textarea class="form-control form-control-sm" data-part-prompt rows="2"></textarea>`;
          list.appendChild(wrap);
        });
      }
      return;
    }
    root.innerHTML = `
      <div class="row g-2">
        <div class="col-12"><label class="form-label" for="actDeliverable">Expected deliverable description</label><textarea class="form-control form-control-sm" id="actDeliverable" rows="2">${esc(config.deliverableHint || '')}</textarea></div>
        <div class="col-12"><label class="form-label" for="actModelAnswer">Optional evaluation rubric (staff only)</label><textarea class="form-control form-control-sm" id="actModelAnswer" rows="3">${esc(key.modelAnswer || '')}</textarea></div>
      </div>`;
  }

  function collectActivityPayload(publish) {
    const type = document.getElementById('activityType').value;
    const title = document.getElementById('activityTitle').value.trim();
    const instructions = document.getElementById('activityInstructions').value.trim();
    if (!title) throw new Error('Activity title is required.');
    if (!instructions) throw new Error('Activity instructions are required.');
    const evaluationMode = document.getElementById('activityEvalMode').value;
    const allowed = activityEvalModesForType(type);
    if (!allowed.includes(evaluationMode)) throw new Error('Choose a valid evaluation mode for this activity type.');
    const payload = {
      title,
      instructions,
      activityType: type,
      academicField: document.getElementById('activityField').value,
      difficulty: document.getElementById('activityDifficulty').value,
      evaluationMode,
      status: publish ? 'published' : 'draft',
      config: {},
      answerKey: {},
    };
    if (type === 'programming_task') {
      payload.config = {
        language: document.getElementById('actLang').value,
        boilerplate: document.getElementById('actBoilerplate').value,
      };
      const model = document.getElementById('actModelAnswer').value.trim();
      if (model) payload.answerKey = { modelAnswer: model };
    } else if (type === 'sql_query') {
      payload.config = { schemaDescription: document.getElementById('actSchema').value };
      const model = document.getElementById('actModelAnswer').value.trim();
      if (model) payload.answerKey = { modelAnswer: model };
    } else if (type === 'numerical') {
      const expectedRaw = document.getElementById('actExpected').value.trim();
      const tolerance = Number(document.getElementById('actTolerance').value);
      if (evaluationMode === 'auto_compare' && expectedRaw === '') {
        throw new Error('Expected numeric answer is required for auto compare.');
      }
      if (expectedRaw !== '' && Number.isNaN(Number(expectedRaw))) {
        throw new Error('Expected answer must be a number.');
      }
      if (Number.isNaN(tolerance) || tolerance < 0) throw new Error('Tolerance must be zero or a positive number.');
      payload.config = {
        unit: document.getElementById('actUnit').value.trim(),
        tolerance: Number.isNaN(tolerance) ? 0 : tolerance,
      };
      payload.answerKey = expectedRaw === '' ? {} : { expectedValue: Number(expectedRaw) };
    } else if (type === 'short_answer') {
      const model = document.getElementById('actModelAnswer').value.trim();
      const keywords = document.getElementById('actKeywords').value.split(',').map((item) => item.trim()).filter(Boolean);
      payload.config = { selfCheckRubric: document.getElementById('actRubric').value.trim() };
      payload.answerKey = {};
      if (model) payload.answerKey.modelAnswer = model;
      if (keywords.length) payload.answerKey.keywords = keywords;
      if (evaluationMode === 'self_check' && !model && !keywords.length) {
        throw new Error('Self-check short answers need a model answer or keywords.');
      }
    } else if (type === 'case_study') {
      const parts = [];
      document.querySelectorAll('#actPartsList [data-part-prompt]').forEach((input, index) => {
        const prompt = input.value.trim();
        if (!prompt) return;
        const wrap = input.closest('[data-part-id]');
        const id = (wrap && wrap.getAttribute('data-part-id')) || `part-${index + 1}`;
        parts.push({ id, prompt });
      });
      if (!parts.length) throw new Error('Add at least one case-study part prompt.');
      payload.config = { parts };
      const model = document.getElementById('actModelAnswer').value.trim();
      if (model) payload.answerKey = { modelAnswer: model };
    } else {
      payload.config = { deliverableHint: document.getElementById('actDeliverable').value.trim() };
      const model = document.getElementById('actModelAnswer').value.trim();
      if (model) payload.answerKey = { modelAnswer: model };
    }
    return payload;
  }

  function renderActivityList() {
    const root = document.getElementById('activityList');
    const rows = state.activities || [];
    document.getElementById('activityMeta').textContent = rows.length
      ? `${rows.length} activit${rows.length === 1 ? 'y' : 'ies'}`
      : 'No activities yet';
    if (!rows.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">Add practical activities for this module. Students will use them after you publish.</p>';
      return;
    }
    root.innerHTML = rows.map((row, index) => {
      const status = row.status === 'published' ? ['success', 'Published'] : ['warning', 'Draft'];
      return `<div class="border rounded p-2" data-activity-id="${esc(row.id)}">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
          <div>
            <div class="fw-semibold">${esc(row.title || 'Untitled')}</div>
            <div class="small text-muted-2">${esc(ACTIVITY_TYPE_LABELS[row.activityType] || row.activityType)} · ${esc(row.difficulty || 'beginner')} · ${esc((row.evaluationMode || '').replace(/_/g, ' '))}</div>
          </div>
          <span class="badge text-bg-${status[0]}">${status[1]}</span>
        </div>
        <div class="d-flex flex-wrap gap-1 mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-act-up ${index === 0 ? 'disabled' : ''}>↑</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-act-down ${index === rows.length - 1 ? 'disabled' : ''}>↓</button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-act-edit>Edit</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-act-toggle-publish>${row.status === 'published' ? 'Unpublish' : 'Publish'}</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-act-archive>Archive</button>
        </div>
      </div>`;
    }).join('');
    root.querySelectorAll('[data-activity-id]').forEach((card) => {
      const id = card.getAttribute('data-activity-id');
      const index = state.activities.findIndex((row) => row.id === id);
      const up = card.querySelector('[data-act-up]');
      const down = card.querySelector('[data-act-down]');
      const edit = card.querySelector('[data-act-edit]');
      const pub = card.querySelector('[data-act-toggle-publish]');
      const arch = card.querySelector('[data-act-archive]');
      if (up) up.addEventListener('click', () => moveActivity(index, -1).catch(fail));
      if (down) down.addEventListener('click', () => moveActivity(index, 1).catch(fail));
      if (edit) edit.addEventListener('click', () => { openActivityForm(state.activities[index]).catch(fail); });
      if (pub) pub.addEventListener('click', () => toggleActivityPublish(id).catch(fail));
      if (arch) arch.addEventListener('click', () => archiveActivity(id).catch(fail));
    });
  }

  function setActivityAiBanner(show) {
    const banner = document.getElementById('activityAiBanner');
    if (banner) banner.classList.toggle('d-none', !show);
  }

  async function generateActivityDraft() {
    if (!state.active || !state.selectedModuleId) {
      toast('Save the module before generating an activity.', 'error');
      return;
    }
    const topic = document.getElementById('activityAiTopic').value.trim();
    if (!topic) {
      toast('Enter a topic or problem area for AI generation.', 'error');
      return;
    }
    const btn = document.getElementById('activityAiGenerateBtn');
    btn.disabled = true;
    btn.textContent = 'Generating…';
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/generate`, {
        method: 'POST',
        body: {
          activityType: document.getElementById('activityAiType').value,
          topic,
          difficulty: document.getElementById('activityAiDifficulty').value,
          language: document.getElementById('activityAiLanguage').value,
          additionalInstructions: document.getElementById('activityAiInstructions').value.trim(),
        },
      });
      const activity = data.activity || null;
      if (!activity) throw new Error('AI did not return an activity draft.');
      state.activityAiPreview = data;
      await openActivityForm(activity);
      document.getElementById('activityId').value = '';
      document.getElementById('activityFormTitle').textContent = 'AI draft (unsaved)';
      document.getElementById('activityFormStatus').textContent = 'Draft';
      document.getElementById('activityFormStatus').className = 'badge text-bg-secondary';
      document.getElementById('activityType').disabled = true;
      setActivityAiBanner(true);
      toast('Review the AI draft, edit if needed, then save as draft.', 'success');
    } catch (err) {
      fail(err);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Generate';
    }
  }

  async function loadModuleActivities() {
    const pane = document.getElementById('moduleActivityPane');
    if (!state.active || !state.selectedModuleId) {
      pane.classList.add('d-none');
      return;
    }
    pane.classList.remove('d-none');
    document.getElementById('activityForm').classList.add('d-none');
    state.activityAiPreview = null;
    setActivityAiBanner(false);
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities`);
      state.activities = data.activities || [];
      renderActivityList();
    } catch (err) {
      state.activities = [];
      renderActivityList();
      fail(err);
    }
  }

  function activityFormIsOpen() {
    return !document.getElementById('activityForm').classList.contains('d-none');
  }

  async function openActivityForm(activity) {
    if (activityFormIsOpen()) {
      const currentId = document.getElementById('activityId').value;
      const nextId = activity && activity.id ? activity.id : '';
      if (currentId !== nextId || (!currentId && !nextId && document.getElementById('activityTitle').value.trim())) {
        const ok = await confirmAction({
          title: 'Discard activity edits?',
          message: 'You have an activity form open. Discard those changes and continue?',
          confirmText: 'Discard',
          variant: 'danger',
        });
        if (!ok) return;
      }
    }
    const form = document.getElementById('activityForm');
    form.classList.remove('d-none');
    document.getElementById('activityId').value = activity && activity.id ? activity.id : '';
    document.getElementById('activityFormTitle').textContent = activity && activity.id ? 'Edit activity' : 'New activity';
    document.getElementById('activityFormStatus').textContent = activity && activity.status === 'published' ? 'Published' : 'Draft';
    document.getElementById('activityFormStatus').className = `badge text-bg-${activity && activity.status === 'published' ? 'success' : 'secondary'}`;
    document.getElementById('activityTitle').value = (activity && activity.title) || '';
    document.getElementById('activityInstructions').value = (activity && activity.instructions) || '';
    document.getElementById('activityType').value = (activity && activity.activityType) || 'short_answer';
    document.getElementById('activityField').value = (activity && activity.academicField) || 'other';
    document.getElementById('activityDifficulty').value = (activity && activity.difficulty) || 'beginner';
    syncActivityEvalModeOptions((activity && activity.evaluationMode) || '');
    renderActivityTypeFields(activity || null);
    document.getElementById('activityType').disabled = !!(activity && activity.id);
    document.querySelectorAll('#activityList [data-activity-id]').forEach((card) => {
      card.classList.toggle('border-primary', !!(activity && activity.id && card.getAttribute('data-activity-id') === activity.id));
    });
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('activityTitle').focus();
  }

  function resetActivityForm() {
    document.getElementById('activityForm').classList.add('d-none');
    document.getElementById('activityId').value = '';
    document.getElementById('activityType').disabled = false;
    state.activityAiPreview = null;
    setActivityAiBanner(false);
    document.querySelectorAll('#activityList [data-activity-id]').forEach((card) => {
      card.classList.remove('border-primary');
    });
  }

  async function saveActivity(publish) {
    if (!state.active || !state.selectedModuleId) {
      toast('Save the module before adding activities.', 'error');
      return;
    }
    if (state.activityBusy) return;
    if (publish && state.active.status !== 'published') {
      toast('Publish the course before publishing an activity, or save as draft.', 'error');
      return;
    }
    let body;
    try {
      body = collectActivityPayload(publish);
    } catch (err) {
      fail(err);
      return;
    }
    const id = document.getElementById('activityId').value;
    const fromAi = !id && !!state.activityAiPreview && !publish;
    const draftBtn = document.getElementById('activitySaveDraftBtn');
    const pubBtn = document.getElementById('activityPublishBtn');
    state.activityBusy = true;
    draftBtn.disabled = true;
    pubBtn.disabled = true;
    try {
      if (id) {
        await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/${encodeURIComponent(id)}`, {
          method: 'PUT',
          body,
        });
      } else if (fromAi) {
        await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/save-generated`, {
          method: 'POST',
          body: { activity: body },
        });
      } else {
        await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities`, {
          method: 'POST',
          body,
        });
      }
      toast(publish ? 'Activity published.' : 'Activity saved as draft.', 'success');
      state.activityAiPreview = null;
      setActivityAiBanner(false);
      resetActivityForm();
      await loadModuleActivities();
    } catch (err) {
      fail(err);
    } finally {
      state.activityBusy = false;
      draftBtn.disabled = false;
      pubBtn.disabled = false;
    }
  }

  async function moveActivity(index, direction) {
    if (state.activityBusy) return;
    const list = state.activities.slice();
    const target = index + direction;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
    state.activityBusy = true;
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/reorder`, {
        method: 'POST',
        body: { activityIds: list.map((row) => row.id) },
      });
      state.activities = data.activities || list;
      renderActivityList();
      toast('Order updated.', 'success');
    } catch (err) {
      fail(err);
      await loadModuleActivities();
    } finally {
      state.activityBusy = false;
    }
  }

  async function toggleActivityPublish(id) {
    if (state.activityBusy) return;
    const row = state.activities.find((item) => item.id === id);
    if (!row) return;
    if (row.status !== 'published' && state.active.status !== 'published') {
      toast('Publish the course before publishing an activity.', 'error');
      return;
    }
    state.activityBusy = true;
    try {
      const path = row.status === 'published' ? 'unpublish' : 'publish';
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/${encodeURIComponent(id)}/${path}`, {
        method: 'POST',
        body: {},
      });
      toast(row.status === 'published' ? 'Activity unpublished.' : 'Activity published.', 'success');
      await loadModuleActivities();
    } catch (err) {
      fail(err);
    } finally {
      state.activityBusy = false;
    }
  }

  async function archiveActivity(id) {
    if (state.activityBusy) return;
    const ok = await confirmAction({
      title: 'Archive activity',
      message: 'Archive this activity? Students will no longer see it. Historical submissions are kept.',
      confirmText: 'Archive',
      variant: 'danger',
    });
    if (!ok) return;
    state.activityBusy = true;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities/${encodeURIComponent(id)}`, {
        method: 'DELETE',
      });
      toast('Activity archived.', 'success');
      if (document.getElementById('activityId').value === id) resetActivityForm();
      await loadModuleActivities();
    } catch (err) {
      fail(err);
    } finally {
      state.activityBusy = false;
    }
  }

  function cancelModuleEdit() {
    state.creatingModule = false;
    state.lesson = null;
    state.assessment = null;
    state.assessmentQuestions = [];
    state.assessmentPreview = null;
    state.activities = [];
    state.activityAiPreview = null;
    showStaffScreen('builder');
    renderModuleNav();
  }

  function toggleInsertMenu(show) {
    const menu = document.getElementById('articleInsertMenu');
    if (!menu) return;
    const open = typeof show === 'boolean' ? show : menu.classList.contains('d-none');
    menu.classList.toggle('d-none', !open);
  }

  function insertArticleBlock(kind) {
    if (!state.lesson) return;
    syncLessonFromDom();
    const afterId = state.insertAfterId || state.activeBlockId || (state.lesson.blocks[state.lesson.blocks.length - 1] || {}).id;
    toggleInsertMenu(false);
    if (kind === 'text') {
      insertBlockAfter(afterId, { id: newBlockId(), type: 'paragraph', text: '' }, { withParagraph: false });
      return;
    }
    if (kind === 'heading') {
      insertBlockAfter(afterId, { id: newBlockId(), type: 'heading', level: 2, text: '' }, { focusInserted: true });
      return;
    }
    if (kind === 'subheading') {
      insertBlockAfter(afterId, { id: newBlockId(), type: 'heading', level: 3, text: '' }, { focusInserted: true });
      return;
    }
    if (kind === 'quote') {
      insertBlockAfter(afterId, { id: newBlockId(), type: 'quote', text: '' }, { focusInserted: true });
      return;
    }
    if (kind === 'code' || kind === 'example') {
      insertBlockAfter(afterId, {
        id: newBlockId(),
        type: 'code',
        language: 'auto',
        source: '',
        exampleOutput: '',
      });
      return;
    }
    if (kind === 'divider') {
      insertBlockAfter(afterId, { id: newBlockId(), type: 'divider' });
      return;
    }
    if (kind === 'exercise') {
      if (!state.selectedModuleId) {
        toast('Save the article before adding an exercise.', 'error');
        return;
      }
      const focusId = ensureTrailingParagraph(afterId);
      state.activeBlockId = focusId;
      renderLessonEditor(focusId, 'text');
      document.getElementById('moduleExercisePane').scrollIntoView({ behavior: 'smooth', block: 'start' });
      openExercise(state.selectedModuleId, '');
      return;
    }
    if (kind === 'image') document.getElementById('articleImageInput').click();
  }

  async function insertUploadedImage(file) {
    if (!file || !state.lesson) return;
    const body = new FormData();
    body.append('image', file);
    const saved = await call('/tutorials/manage/media', { method: 'POST', body });
    const url = saved && saved.url ? saved.url : '';
    if (!/^(\/|https?:\/\/)/i.test(url)) throw new Error('The image could not be saved.');
    const afterId = state.insertAfterId || state.activeBlockId || (state.lesson.blocks[state.lesson.blocks.length - 1] || {}).id;
    insertBlockAfter(afterId, { id: newBlockId(), type: 'image', url, alt: '' });
  }

  async function saveModule(event) {
    event.preventDefault();
    if (!state.active) return;
    const id = document.getElementById('moduleId').value;
    document.getElementById('articleStatus').textContent = 'Saving…';
    const body = {
      title: document.getElementById('moduleTitle').value.trim(),
      subtitle: document.getElementById('moduleSubtitle').value.trim(),
      content: serializeLessonDocument(),
    };
    const tutorialId = state.active.id;
    try {
      const saved = id
        ? await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules/${encodeURIComponent(id)}`, { method: 'PUT', body })
        : await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules`, { method: 'POST', body });
      state.creatingModule = false;
      state.selectedModuleId = saved && saved.id ? saved.id : id;
      toast('Module saved.', 'success');
      await openModules(tutorialId);
    } catch (err) {
      fail(err);
    }
  }

  async function deleteModule(id) {
    if (!state.active) return;
    const ok = await confirmAction({ title: 'Delete module', message: 'Delete this module and its exercises?', confirmText: 'Delete', variant: 'danger' });
    if (!ok) return;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(id)}`, { method: 'DELETE' });
      toast('Module deleted.', 'success');
      state.selectedModuleId = state.selectedModuleId === id ? '' : state.selectedModuleId;
      await openModules(state.active.id);
    } catch (err) {
      fail(err);
    }
  }

  async function moveModule(id, direction) {
    if (!state.active) return;
    const modules = [...(state.active.modules || [])];
    const index = modules.findIndex((row) => row.id === id);
    const next = index + direction;
    if (index < 0 || next < 0 || next >= modules.length) return;
    const swap = modules[index];
    modules[index] = modules[next];
    modules[next] = swap;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/reorder`, {
        method: 'POST',
        body: { moduleIds: modules.map((row) => row.id) },
      });
      toast('Module order saved.', 'success');
      await openModules(state.active.id);
    } catch (err) {
      fail(err);
    }
  }

  function findExercise(moduleId, exerciseId) {
    const module = (state.active && state.active.modules || []).find((row) => row.id === moduleId);
    if (!module) return null;
    return (module.exercises || []).find((row) => row.id === exerciseId) || null;
  }

  function renderTestCases(exercise) {
    const list = document.getElementById('testCaseList');
    const cases = (exercise && exercise.testCases) || [];
    list.innerHTML = cases.map((item, index) => (
      `<div class="border rounded p-2">
        <div class="d-flex justify-content-between gap-2 mb-1">
          <span class="badge ${item.sample ? 'text-bg-primary' : 'text-bg-dark'}">${item.sample ? 'PUBLIC' : 'HIDDEN'}</span>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-case-up="${esc(item.id)}" ${index === 0 ? 'disabled' : ''}>Up</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-case-down="${esc(item.id)}" ${index === cases.length - 1 ? 'disabled' : ''}>Down</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-case="${esc(item.id)}">Edit</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-case="${esc(item.id)}">Delete</button>
          </div>
        </div>
        <div class="small"><span class="text-muted-2">Input</span><pre class="mb-1">${esc(item.stdin)}</pre></div>
        <div class="small"><span class="text-muted-2">Expected</span><pre class="mb-0">${esc(item.expectedOutput)}</pre></div>
      </div>`
    )).join('') || '<p class="text-muted-2 mb-0">No test cases yet.</p>';
    list.querySelectorAll('[data-edit-case]').forEach((btn) => {
      btn.addEventListener('click', () => editTestCase(btn.getAttribute('data-edit-case'), cases));
    });
    list.querySelectorAll('[data-delete-case]').forEach((btn) => {
      btn.addEventListener('click', () => deleteTestCase(btn.getAttribute('data-delete-case')));
    });
    list.querySelectorAll('[data-case-up]').forEach((btn) => btn.addEventListener('click', () => moveTestCase(btn.getAttribute('data-case-up'), -1, cases)));
    list.querySelectorAll('[data-case-down]').forEach((btn) => btn.addEventListener('click', () => moveTestCase(btn.getAttribute('data-case-down'), 1, cases)));
  }

  async function moveTestCase(id, direction, cases) {
    const exerciseId = document.getElementById('exerciseId').value;
    const index = cases.findIndex((row) => row.id === id);
    const next = index + direction;
    if (!exerciseId || index < 0 || next < 0 || next >= cases.length) return;
    const order = cases.map((row) => row.id);
    const swap = order[index];
    order[index] = order[next];
    order[next] = swap;
    try {
      await call(`/tutorials/manage/exercises/${encodeURIComponent(exerciseId)}/test-cases/reorder`, {
        method: 'POST',
        body: { testCaseIds: order },
      });
      await openModules(state.active.id, true);
      renderTestCases(findExercise(document.getElementById('exerciseModuleId').value, exerciseId));
    } catch (err) {
      fail(err);
    }
  }

  function openExercise(moduleId, exerciseId) {
    const exercise = exerciseId ? findExercise(moduleId, exerciseId) : null;
    document.getElementById('exerciseModalTitle').textContent = exercise ? 'Edit Exercise' : 'Add Exercise';
    document.getElementById('exerciseId').value = exercise ? exercise.id : '';
    document.getElementById('exerciseModuleId').value = moduleId;
    document.getElementById('exerciseTitle').value = exercise ? exercise.title : '';
    document.getElementById('exerciseInstructions').value = exercise ? (exercise.instructions || '').replace(/<[^>]+>/g, '') : '';
    document.getElementById('exerciseLanguage').value = exercise ? exercise.language : 'c';
    document.getElementById('exerciseBoilerplate').value = exercise ? exercise.boilerplate || '' : '';
    document.getElementById('exerciseTime').value = exercise ? exercise.timeLimitMs : 5000;
    document.getElementById('exerciseMemory').value = exercise ? exercise.memoryLimitKb : 128000;
    document.getElementById('testCaseSection').classList.toggle('d-none', !exercise);
    document.getElementById('caseStdin').value = '';
    document.getElementById('caseExpected').value = '';
    document.getElementById('caseSample').value = 'true';
    document.getElementById('addTestCaseBtn').textContent = 'Add test case';
    delete document.getElementById('addTestCaseBtn').dataset.caseId;
    renderTestCases(exercise);
    document.getElementById('exerciseForm').classList.remove('d-none');
    document.getElementById('exerciseTitle').focus();
  }

  async function saveExercise(event) {
    event.preventDefault();
    if (!state.active) return;
    const moduleId = document.getElementById('exerciseModuleId').value;
    const id = document.getElementById('exerciseId').value;
    const body = {
      title: document.getElementById('exerciseTitle').value.trim(),
      instructions: document.getElementById('exerciseInstructions').value.trim(),
      language: document.getElementById('exerciseLanguage').value,
      boilerplate: document.getElementById('exerciseBoilerplate').value,
      timeLimitMs: Number(document.getElementById('exerciseTime').value),
      memoryLimitKb: Number(document.getElementById('exerciseMemory').value),
    };
    const tutorialId = state.active.id;
    try {
      if (id) {
        await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules/${encodeURIComponent(moduleId)}/exercises/${encodeURIComponent(id)}`, { method: 'PUT', body });
      } else {
        const created = await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules/${encodeURIComponent(moduleId)}/exercises`, { method: 'POST', body });
        document.getElementById('exerciseId').value = created.id || '';
        document.getElementById('testCaseSection').classList.remove('d-none');
      }
      toast('Exercise saved.', 'success');
      await openModules(tutorialId);
      const savedId = document.getElementById('exerciseId').value;
      if (savedId) {
        state.active = await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}`);
        renderTestCases(findExercise(moduleId, savedId));
      }
    } catch (err) {
      fail(err);
    }
  }

  async function deleteExercise(moduleId, exerciseId) {
    if (!state.active) return;
    const ok = await confirmAction({ title: 'Delete exercise', message: 'Delete this exercise and its test cases?', confirmText: 'Delete', variant: 'danger' });
    if (!ok) return;
    try {
      await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(moduleId)}/exercises/${encodeURIComponent(exerciseId)}`, { method: 'DELETE' });
      toast('Exercise deleted.', 'success');
      await openModules(state.active.id, true);
    } catch (err) {
      fail(err);
    }
  }

  function editTestCase(id, cases) {
    const item = (cases || []).find((row) => row.id === id);
    if (!item) return;
    document.getElementById('caseStdin').value = item.stdin || '';
    document.getElementById('caseExpected').value = item.expectedOutput || '';
    document.getElementById('caseSample').value = item.sample ? 'true' : 'false';
    document.getElementById('addTestCaseBtn').textContent = 'Update test case';
    document.getElementById('addTestCaseBtn').dataset.caseId = item.id;
  }

  async function addTestCase() {
    const exerciseId = document.getElementById('exerciseId').value;
    if (!exerciseId) {
      toast('Save the exercise before adding test cases.', 'error');
      return;
    }
    const sample = document.getElementById('caseSample').value === 'true';
    const caseId = document.getElementById('addTestCaseBtn').dataset.caseId || '';
    const body = {
      stdin: document.getElementById('caseStdin').value,
      expectedOutput: document.getElementById('caseExpected').value,
      sample,
    };
    try {
      if (caseId) {
        await call(`/tutorials/manage/exercises/${encodeURIComponent(exerciseId)}/test-cases/${encodeURIComponent(caseId)}`, { method: 'PUT', body });
      } else {
        await call(`/tutorials/manage/exercises/${encodeURIComponent(exerciseId)}/test-cases`, { method: 'POST', body });
      }
      document.getElementById('caseStdin').value = '';
      document.getElementById('caseExpected').value = '';
      document.getElementById('addTestCaseBtn').textContent = 'Add test case';
      delete document.getElementById('addTestCaseBtn').dataset.caseId;
      toast(sample ? 'Public test case saved.' : 'Hidden test case saved.', 'success');
      await openModules(state.active.id, true);
      renderTestCases(findExercise(document.getElementById('exerciseModuleId').value, exerciseId));
    } catch (err) {
      fail(err);
    }
  }

  async function deleteTestCase(id) {
    const exerciseId = document.getElementById('exerciseId').value;
    const ok = await confirmAction({ title: 'Delete test case', message: 'Delete this test case?', confirmText: 'Delete', variant: 'danger' });
    if (!ok || !exerciseId) return;
    try {
      await call(`/tutorials/manage/exercises/${encodeURIComponent(exerciseId)}/test-cases/${encodeURIComponent(id)}`, { method: 'DELETE' });
      toast('Test case deleted.', 'success');
      await openModules(state.active.id, true);
      state.active = await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}`);
      renderTestCases(findExercise(document.getElementById('exerciseModuleId').value, exerciseId));
    } catch (err) {
      fail(err);
    }
  }

  async function openActivityReviews(tutorialId) {
    if (!tutorialId) return;
    state.reviewTutorialId = tutorialId;
    state.reviewDetail = null;
    document.getElementById('activityReviewDetail').classList.add('d-none');
    document.getElementById('activityReviewEmpty').classList.remove('d-none');
    showStaffScreen('reviews');
    document.getElementById('activityReviewQueue').innerHTML = '<p class="text-muted-2 mb-0">Loading…</p>';
    try {
      const course = await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}`);
      state.reviewCourse = course;
      state.active = course;
      document.getElementById('activityReviewCourseMeta').textContent = `${course.title || 'Course'} · student practical activity submissions`;
      const moduleSelect = document.getElementById('reviewFilterModule');
      moduleSelect.innerHTML = '<option value="">All modules</option>'
        + ((course.modules || []).map((row) => `<option value="${esc(row.id)}">${esc(row.title || 'Module')}</option>`).join(''));
      await populateReviewActivityFilter();
      document.getElementById('reviewFilterStatus').value = 'pending';
      await loadActivityReviewQueue();
    } catch (err) {
      fail(err);
      showStaffScreen('list');
    }
  }

  async function populateReviewActivityFilter() {
    const moduleId = document.getElementById('reviewFilterModule').value;
    const select = document.getElementById('reviewFilterActivity');
    select.innerHTML = '<option value="">All activities</option>';
    state.reviewActivityOptions = [];
    if (!state.reviewCourse) return;
    const modules = (state.reviewCourse.modules || []).filter((row) => !moduleId || row.id === moduleId);
    for (const module of modules) {
      try {
        const data = await call(`/tutorials/manage/${encodeURIComponent(state.reviewTutorialId)}/modules/${encodeURIComponent(module.id)}/activities`);
        (data.activities || []).forEach((activity) => {
          state.reviewActivityOptions.push({
            id: activity.id,
            title: activity.title || 'Activity',
            moduleId: module.id,
          });
          select.innerHTML += `<option value="${esc(activity.id)}">${esc(activity.title || 'Activity')}</option>`;
        });
      } catch { /* skip module without activities access */ }
    }
  }

  async function loadActivityReviewQueue() {
    if (!state.reviewTutorialId) return;
    const queue = document.getElementById('activityReviewQueue');
    queue.innerHTML = '<p class="text-muted-2 mb-0">Loading submissions…</p>';
    const params = new URLSearchParams();
    const moduleId = document.getElementById('reviewFilterModule').value;
    const activityId = document.getElementById('reviewFilterActivity').value;
    const status = document.getElementById('reviewFilterStatus').value || 'all';
    if (moduleId) params.set('moduleId', moduleId);
    if (activityId) params.set('activityId', activityId);
    params.set('status', status);
    params.set('limit', '100');
    try {
      const data = await call(`/tutorials/manage/${encodeURIComponent(state.reviewTutorialId)}/activity-submissions?${params}`);
      state.reviewQueue = data.submissions || [];
      renderActivityReviewQueue();
    } catch (err) {
      queue.innerHTML = `<p class="text-danger mb-0">${esc(err.message || 'Could not load submissions.')}</p>`;
    }
  }

  function renderActivityReviewQueue() {
    const queue = document.getElementById('activityReviewQueue');
    const rows = state.reviewQueue || [];
    if (!rows.length) {
      queue.innerHTML = '<p class="text-muted-2 mb-0">No submissions match these filters.</p>';
      return;
    }
    queue.innerHTML = rows.map((row) => {
      const student = row.student || {};
      const review = row.review || {};
      const selected = state.reviewDetail && state.reviewDetail.id === row.id;
      const status = review.status === 'reviewed' ? 'Reviewed' : (review.status === 'n/a' ? 'Auto' : 'Pending');
      const badge = review.status === 'reviewed' ? 'success' : (review.status === 'n/a' ? 'secondary' : 'warning');
      return `<button type="button" class="btn ${selected ? 'btn-primary' : 'btn-outline-secondary'} text-start" data-open-review="${esc(row.id)}">
        <div class="fw-semibold">${esc(row.activityTitle || 'Activity')}</div>
        <div class="small">${esc(student.registerNumber || student.name || 'Student')} · Attempt #${esc(row.attemptNumber || '')}</div>
        <div class="small">${esc(row.moduleTitle || '')} · ${esc(row.submittedAt || '')}</div>
        <span class="badge text-bg-${badge} mt-1">${status}</span>
      </button>`;
    }).join('');
    queue.querySelectorAll('[data-open-review]').forEach((btn) => {
      btn.addEventListener('click', () => openActivityReviewDetail(btn.getAttribute('data-open-review')).catch(fail));
    });
  }

  async function openActivityReviewDetail(submissionId) {
    if (!state.reviewTutorialId || !submissionId) return;
    try {
      const detail = await call(`/tutorials/manage/${encodeURIComponent(state.reviewTutorialId)}/activity-submissions/${encodeURIComponent(submissionId)}`);
      state.reviewDetail = detail;
      document.getElementById('activityReviewEmpty').classList.add('d-none');
      document.getElementById('activityReviewDetail').classList.remove('d-none');
      paintActivityReviewDetail();
      renderActivityReviewQueue();
    } catch (err) {
      fail(err);
    }
  }

  function paintActivityReviewDetail() {
    const detail = state.reviewDetail;
    if (!detail) return;
    const snap = detail.activitySnapshot || {};
    const review = detail.review || {};
    const student = detail.student || {};
    document.getElementById('reviewDetailTitle').textContent = detail.activityTitle || snap.title || 'Activity';
    document.getElementById('reviewDetailMeta').textContent = [
      STUDENT_ACTIVITY_TYPE_LABELS[detail.activityType || snap.activityType] || detail.activityType || snap.activityType,
      `Attempt #${detail.attemptNumber || ''}`,
      detail.submittedAt || '',
      STUDENT_EVAL_LABELS[detail.evaluationMode || snap.evaluationMode] || detail.evaluationMode || '',
    ].filter(Boolean).join(' · ');
    const status = review.status === 'reviewed' ? 'Reviewed' : (review.status === 'n/a' ? 'Automated' : 'Pending');
    document.getElementById('reviewDetailStatus').textContent = status;
    document.getElementById('reviewDetailStatus').className = `badge text-bg-${review.status === 'reviewed' ? 'success' : (review.status === 'n/a' ? 'secondary' : 'warning')}`;
    document.getElementById('reviewDetailStudent').textContent = [
      student.name,
      student.registerNumber,
      student.classBatch,
    ].filter(Boolean).join(' · ');
    setLessonHtml(document.getElementById('reviewDetailInstructions'), snap.instructions || '');
    document.getElementById('reviewDetailResponse').innerHTML = renderStaffActivityResponse(detail);
    const autoBox = document.getElementById('reviewAutoResult');
    const auto = detail.autoResult || {};
    if (auto.mode === 'auto_compare' || auto.mode === 'self_check') {
      autoBox.classList.remove('d-none');
      autoBox.innerHTML = auto.mode === 'auto_compare'
        ? `<div class="fw-semibold mb-1">Automated result</div><div>${auto.matched ? 'Matched' : 'Not matched'} (read-only)</div>`
        : `<div class="fw-semibold mb-1">Self-check result</div><div>Keywords ${esc(auto.keywordsMatched || 0)}/${esc(auto.keywordsTotal || 0)} (read-only)</div>`;
    } else {
      autoBox.classList.add('d-none');
      autoBox.innerHTML = '';
    }
    const form = document.getElementById('activityReviewForm');
    const reviewable = detail.reviewable === true;
    form.classList.toggle('d-none', !reviewable);
    document.getElementById('reviewFormNote').textContent = reviewable
      ? 'Score fields are optional. Finalize makes feedback visible to the student.'
      : 'This submission uses automated evaluation and cannot be manually overwritten.';
    document.getElementById('reviewScore').value = review.score != null ? review.score : '';
    document.getElementById('reviewMaxScore').value = review.maxScore != null ? review.maxScore : 10;
    document.getElementById('reviewPassed').checked = review.passed === true;
    document.getElementById('reviewFeedback').value = review.feedback || '';
    document.getElementById('reviewPrivateNotes').value = review.privateNotes || '';
    const disabled = !reviewable;
    ['reviewScore', 'reviewMaxScore', 'reviewPassed', 'reviewFeedback', 'reviewPrivateNotes', 'reviewSaveDraftBtn', 'reviewFinalizeBtn']
      .forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
      });
  }

  function renderStaffActivityResponse(detail) {
    const type = detail.activityType || ((detail.activitySnapshot || {}).activityType);
    const payload = detail.payload || {};
    if (type === 'programming_task') {
      return `<pre class="mb-0">${esc(payload.source || '')}</pre><div class="form-text">Source is shown as text only. Nothing is executed.</div>`;
    }
    if (type === 'sql_query') {
      return `<pre class="mb-0">${esc(payload.sql || '')}</pre><div class="form-text">SQL is never executed.</div>`;
    }
    if (type === 'numerical') {
      return `<div><strong>Value:</strong> ${esc(payload.value != null ? payload.value : '—')} ${esc(payload.unit || '')}</div>`;
    }
    if (type === 'case_study') {
      const parts = (((detail.activitySnapshot || {}).config || {}).parts) || [];
      const answers = payload.parts || {};
      return parts.map((part, index) => (
        `<div class="mb-2"><div class="fw-semibold">Part ${index + 1}: ${esc(part.prompt || '')}</div><div>${esc(answers[part.id] || '')}</div></div>`
      )).join('') || '<p class="mb-0 text-muted-2">No parts.</p>';
    }
    return `<div style="white-space:pre-wrap">${esc(payload.text || '')}</div>`;
  }

  function collectReviewFormPayload() {
    const scoreRaw = document.getElementById('reviewScore').value.trim();
    const maxRaw = document.getElementById('reviewMaxScore').value.trim();
    const payload = {
      feedback: document.getElementById('reviewFeedback').value,
      privateNotes: document.getElementById('reviewPrivateNotes').value,
      passed: document.getElementById('reviewPassed').checked ? true : null,
    };
    if (scoreRaw !== '') {
      if (Number.isNaN(Number(scoreRaw))) throw new Error('Score must be numeric.');
      payload.score = Number(scoreRaw);
    } else {
      payload.score = null;
    }
    if (maxRaw !== '') {
      if (Number.isNaN(Number(maxRaw))) throw new Error('Max score must be numeric.');
      payload.maxScore = Number(maxRaw);
    } else {
      payload.maxScore = null;
    }
    if (payload.score != null && payload.maxScore == null) {
      throw new Error('Max score is required when score is provided.');
    }
    return payload;
  }

  async function saveActivityReviewDraft() {
    if (!state.reviewDetail || state.reviewBusy) return;
    let body;
    try {
      body = collectReviewFormPayload();
    } catch (err) {
      fail(err);
      return;
    }
    state.reviewBusy = true;
    document.getElementById('reviewSaveDraftBtn').disabled = true;
    try {
      const saved = await call(`/tutorials/manage/${encodeURIComponent(state.reviewTutorialId)}/activity-submissions/${encodeURIComponent(state.reviewDetail.id)}/review`, {
        method: 'PUT',
        body,
      });
      state.reviewDetail = saved;
      paintActivityReviewDetail();
      await loadActivityReviewQueue();
      toast('Review draft saved.', 'success');
    } catch (err) {
      fail(err);
    } finally {
      state.reviewBusy = false;
      document.getElementById('reviewSaveDraftBtn').disabled = false;
    }
  }

  async function finalizeActivityReview() {
    if (!state.reviewDetail || state.reviewBusy) return;
    let body;
    try {
      body = collectReviewFormPayload();
    } catch (err) {
      fail(err);
      return;
    }
    const ok = await confirmAction({
      title: 'Finalize review',
      message: 'Finalize this review? Score and feedback become visible to the student.',
      confirmText: 'Finalize',
    });
    if (!ok) return;
    state.reviewBusy = true;
    document.getElementById('reviewFinalizeBtn').disabled = true;
    document.getElementById('reviewSaveDraftBtn').disabled = true;
    try {
      const saved = await call(`/tutorials/manage/${encodeURIComponent(state.reviewTutorialId)}/activity-submissions/${encodeURIComponent(state.reviewDetail.id)}/review/finalize`, {
        method: 'POST',
        body,
      });
      state.reviewDetail = saved;
      paintActivityReviewDetail();
      await loadActivityReviewQueue();
      toast('Review finalized.', 'success');
    } catch (err) {
      fail(err);
    } finally {
      state.reviewBusy = false;
      document.getElementById('reviewFinalizeBtn').disabled = false;
      document.getElementById('reviewSaveDraftBtn').disabled = false;
    }
  }

  function bind() {
    document.getElementById('createTutorialBtn').addEventListener('click', () => { blankTutorialForm(); modal('tutorialModal').show(); });
    document.getElementById('generateCourseAiBtn').addEventListener('click', openAiCourseScreen);
    document.getElementById('aiCourseBack').addEventListener('click', () => { leaveAiCourse().catch(fail); });
    document.getElementById('aiCourseCancelBtn').addEventListener('click', () => { leaveAiCourse().catch(fail); });
    document.getElementById('aiCourseGenerateBtn').addEventListener('click', () => {
      (state.aiCourse ? regenerateAiCourse() : generateAiCourse()).catch(fail);
    });
    document.getElementById('aiCourseRegenerateBtn').addEventListener('click', () => { regenerateAiCourse().catch(fail); });
    document.getElementById('aiCourseSaveBtn').addEventListener('click', () => { saveAiCourseDraft().catch(fail); });
    document.getElementById('aiCourseOpenSaved').addEventListener('click', () => {
      if (state.aiSavedTutorialId) openModules(state.aiSavedTutorialId).catch(fail);
    });
    document.getElementById('generateModuleAiBtn').addEventListener('click', toggleAiModulePane);
    document.getElementById('aiModuleGenerateBtn').addEventListener('click', () => { generateAiModule().catch(fail); });
    document.getElementById('aiModuleSaveBtn').addEventListener('click', () => { saveAiModuleDraft().catch(fail); });
    document.getElementById('tutorialForm').addEventListener('submit', saveTutorial);
    document.getElementById('categoryForm').addEventListener('submit', saveCategory);
    document.getElementById('moduleForm').addEventListener('submit', saveModule);
    document.getElementById('exerciseForm').addEventListener('submit', saveExercise);
    document.getElementById('addCategoryBtn').addEventListener('click', () => modal('categoryModal').show());
    document.getElementById('addModuleBtn').addEventListener('click', () => beginNewModule());
    document.getElementById('articleBack').addEventListener('click', cancelModuleEdit);
    document.getElementById('articleInsertMenu').addEventListener('click', (event) => {
      const button = event.target.closest('[data-insert]');
      if (!button) return;
      insertArticleBlock(button.getAttribute('data-insert'));
    });
    document.addEventListener('click', (event) => {
      const menu = document.getElementById('articleInsertMenu');
      if (!menu || menu.classList.contains('d-none')) return;
      if (event.target.closest('#articleInsertMenu') || event.target.closest('.row-plus')) return;
      toggleInsertMenu(false);
    });
    document.getElementById('articleImageInput').addEventListener('change', (event) => {
      const file = event.target.files && event.target.files[0];
      event.target.value = '';
      if (!file) return;
      insertUploadedImage(file).catch(fail);
    });
    document.getElementById('addExerciseInline').addEventListener('click', () => {
      if (!state.selectedModuleId) return;
      openExercise(state.selectedModuleId, '');
    });
    document.getElementById('cancelExerciseBtn').addEventListener('click', () => {
      document.getElementById('exerciseForm').classList.add('d-none');
    });
    document.getElementById('assessmentGenerateBtn').addEventListener('click', () => {
      generateAssessmentQuestions().catch(fail);
    });
    document.getElementById('assessmentPreviewDiscard').addEventListener('click', () => {
      state.assessmentPreview = null;
      renderAssessmentPreviewList();
    });
    document.getElementById('assessmentPreviewApprove').addEventListener('click', () => {
      approveGeneratedQuestions().catch(fail);
    });
    document.getElementById('assessmentAddQuestionBtn').addEventListener('click', () => {
      state.assessmentQuestions.push(blankQuestion());
      renderAssessmentQuestions();
    });
    document.getElementById('assessmentPreviewBtn').addEventListener('click', previewAssessmentStudent);
    document.getElementById('assessmentPreviewClose').addEventListener('click', () => {
      document.getElementById('assessmentStudentPreview').classList.add('d-none');
    });
    document.getElementById('assessmentSaveBtn').addEventListener('click', () => {
      saveAssessmentEditor().catch(fail);
    });
    document.getElementById('activityAiGenerateBtn').addEventListener('click', () => {
      generateActivityDraft().catch(fail);
    });
    document.getElementById('activityAddBtn').addEventListener('click', () => {
      state.activityAiPreview = null;
      setActivityAiBanner(false);
      openActivityForm(null).catch(fail);
    });
    document.getElementById('activityCancelBtn').addEventListener('click', resetActivityForm);
    document.getElementById('activityType').addEventListener('change', () => {
      syncActivityEvalModeOptions();
      renderActivityTypeFields(null);
    });
    document.getElementById('activityForm').addEventListener('submit', (event) => {
      event.preventDefault();
      saveActivity(false).catch(fail);
    });
    document.getElementById('activityPublishBtn').addEventListener('click', () => {
      saveActivity(true).catch(fail);
    });
    document.getElementById('builderEditCourse').addEventListener('click', () => {
      if (state.active) openTutorial(state.active.id);
    });
    document.getElementById('builderActivityReviews').addEventListener('click', () => {
      if (state.active) openActivityReviews(state.active.id).catch(fail);
    });
    document.getElementById('activityReviewBack').addEventListener('click', () => {
      if (state.reviewTutorialId) openModules(state.reviewTutorialId).catch(fail);
      else showStaffScreen('list');
    });
    document.getElementById('reviewFilterModule').addEventListener('change', () => {
      populateReviewActivityFilter().catch(fail);
    });
    document.getElementById('reviewFilterApply').addEventListener('click', () => {
      loadActivityReviewQueue().catch(fail);
    });
    document.getElementById('reviewSaveDraftBtn').addEventListener('click', () => {
      saveActivityReviewDraft().catch(fail);
    });
    document.getElementById('reviewFinalizeBtn').addEventListener('click', () => {
      finalizeActivityReview().catch(fail);
    });
    document.getElementById('builderPreview').addEventListener('click', () => {
      if (state.active) openPreview(state.active.id);
    });
    document.getElementById('builderPublish').addEventListener('click', () => {
      if (!state.active) return;
      publishTutorial(state.active.id, state.active.status !== 'published');
    });
    document.getElementById('addTestCaseBtn').addEventListener('click', addTestCase);
    document.getElementById('confirmPublishBtn').addEventListener('click', async () => {
      if (!state.publishId) return;
      try {
        await call(`/tutorials/manage/${encodeURIComponent(state.publishId)}/publish`, { method: 'POST', body: {} });
        modal('publishModal').hide();
        toast('Course published.', 'success');
        await refreshList();
        if (state.active && state.active.id === state.publishId && !document.getElementById('moduleView').classList.contains('d-none')) {
          await openModules(state.publishId);
        }
      } catch (err) {
        fail(err);
      }
    });
    document.querySelectorAll('[data-staff-status]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.statusFilter = btn.getAttribute('data-staff-status') || '';
        document.querySelectorAll('[data-staff-status]').forEach((item) => {
          item.classList.toggle('btn-primary', item === btn);
          item.classList.toggle('btn-outline-secondary', item !== btn);
        });
        renderTutorials();
      });
    });
    document.getElementById('backFromPreview').addEventListener('click', () => {
      document.getElementById('previewView').classList.add('d-none');
      showStaffScreen(state.previewReturn === 'builder' ? 'builder' : 'list');
    });
    document.getElementById('backToTutorials').addEventListener('click', async () => {
      showStaffScreen('list');
      try { await refreshList(); } catch (err) { fail(err); }
    });
    document.querySelectorAll('input[name="tutorialVisibility"]').forEach((input) => input.addEventListener('change', syncAudience));
    document.getElementById('addYearBtn').addEventListener('click', () => {
      const year = document.getElementById('yearInput').value.trim();
      if (!/^(19|20)\d{2}$/.test(year)) {
        toast('Enter a four-digit year such as 2027.', 'error');
        return;
      }
      if (!state.years.includes(year)) state.years.push(year);
      document.getElementById('yearInput').value = '';
      renderYears();
    });
  }

  const learn = {
    tutorials: [],
    categories: [],
    categoriesLoaded: false,
    listRequest: 0,
    categoryId: '',
    query: '',
    detail: null,
    moduleIndex: 0,
    module: null,
    lessons: [],
    lessonIndex: 0,
    drafts: {},
    draftSaved: {},
    expandedModules: {},
    progressById: {},
    continueIds: {},
    progress: null,
    practiceQuestions: [],
    practiceIndex: 0,
    practiceSelection: {},
    practiceFeedback: null,
    assessment: null,
    assessmentAttemptId: null,
    assessmentAnswers: {},
    assessmentQuestionIndex: 0,
    assessmentResult: null,
    activities: [],
    activityStatuses: {},
    activity: null,
    activityAttempts: [],
    activityAttempt: null,
    activityBusy: false,
    activityDirty: false,
    activityViewingSubmitted: false,
  };

  const STUDENT_ACTIVITY_TYPE_LABELS = {
    programming_task: 'Programming task',
    sql_query: 'SQL query',
    numerical: 'Numerical problem',
    short_answer: 'Short answer',
    case_study: 'Case study',
    analytical_design: 'Analytical / design',
  };

  const STUDENT_EVAL_LABELS = {
    none: 'No submission',
    self_check: 'Self-check after submit',
    tutor_review: 'Tutor review',
    auto_compare: 'Automatic check',
  };

  function highlightLessonCode(source, language) {
    if (window.hljs) {
      try {
        const aliases = { csharp: 'csharp', html: 'xml', text: 'plaintext' };
        const alias = aliases[language] || language;
        if (language && language !== 'auto' && language !== 'text' && window.hljs.getLanguage(alias)) {
          return window.hljs.highlight(source, { language: alias }).value;
        }
        if (language === 'text') return esc(source);
        return window.hljs.highlightAuto(source).value;
      } catch {
        /* fall through */
      }
    }
    return esc(source)
      .replace(/(&quot;(?:[^&]|&(?!quot;))*&quot;|&#39;(?:[^&]|&(?!#39;))*&#39;)/g, '<span style="color:#86efac">$1</span>')
      .replace(/(^|\n)(\/\/.*|#.*)/g, '$1<span style="color:#94a3b8">$2</span>');
  }

  function decorateCodeBlocks(root) {
    root.querySelectorAll('pre.tutorial-code-block').forEach((pre) => {
      if (pre.dataset.ready === '1') return;
      const source = pre.querySelector('code') ? pre.querySelector('code').textContent : pre.textContent;
      const language = pre.getAttribute('data-language') || 'text';
      const role = pre.getAttribute('data-role') === 'output' ? 'output' : 'code';
      const label = pre.getAttribute('data-label') || (CODE_DEMO_LANGUAGES.find((row) => row.value === language) || {}).label || language;
      const card = document.createElement('div');
      card.className = 'tutorial-code-card';
      const title = role === 'output' ? 'Example output' : label;
      card.innerHTML = `<header><span>${esc(title)}</span>${role === 'code' ? '<button type="button" data-student-copy>Copy</button>' : ''}</header>${role === 'output' ? '<div class="tutorial-code-note">Example written by the tutor. The code was not run.</div>' : ''}<pre></pre>`;
      card.querySelector('pre').innerHTML = role === 'code' ? highlightLessonCode(source, language) : esc(source);
      pre.replaceWith(card);
      card.dataset.ready = '1';
      const copy = card.querySelector('[data-student-copy]');
      if (copy) {
        copy.addEventListener('click', () => {
          if (navigator.clipboard) navigator.clipboard.writeText(source).then(() => toast('Copied.', 'success')).catch(() => {});
        });
      }
    });
  }

  function lessonDocumentToHtml(doc) {
    return (doc.blocks || []).map((block) => {
      if (block.type === 'heading') {
        const tag = block.level === 3 ? 'h3' : 'h2';
        return `<${tag}>${esc(block.text || '')}</${tag}>`;
      }
      if (block.type === 'quote') return `<blockquote>${esc(block.text || '')}</blockquote>`;
      if (block.type === 'divider') return '<hr/>';
      if (block.type === 'image') {
        return `<figure class="lesson-figure"><img src="${esc(block.url || '')}" alt="${esc(block.alt || '')}">${block.alt ? `<figcaption>${esc(block.alt)}</figcaption>` : ''}</figure>`;
      }
      if (block.type === 'code') {
        const language = block.language || 'auto';
        const label = CODE_DEMO_LANGUAGES.find((row) => row.value === language);
        const langAttr = language === 'auto' ? (detectCodeLanguage(block.source) || 'text') : language;
        let html = `<pre class="tutorial-code-block" data-language="${esc(langAttr)}" data-role="code" data-label="${esc(label ? label.label : langAttr)}"><code>${esc(block.source || '')}</code></pre>`;
        if (String(block.exampleOutput || '').trim() !== '') {
          html += `<pre class="tutorial-code-block" data-language="text" data-role="output"><code>${esc(block.exampleOutput)}</code></pre>`;
        }
        return html;
      }
      const text = String(block.text || '').trim();
      return text ? `<p>${esc(text).replace(/\n/g, '<br>')}</p>` : '';
    }).join('');
  }

  function setLessonHtml(el, raw) {
    const doc = parseLessonDocument(raw);
    if (doc) {
      el.innerHTML = lessonDocumentToHtml(doc);
      decorateCodeBlocks(el);
      return;
    }
    const parsed = new DOMParser().parseFromString(String(raw || ''), 'text/html');
    parsed.querySelectorAll('script,iframe,object,embed,link,meta').forEach((node) => node.remove());
    parsed.body.querySelectorAll('*').forEach((node) => {
      [...node.attributes].forEach((attr) => {
        const name = attr.name.toLowerCase();
        const allowed = name === 'href' || name === 'src' || name === 'alt' || name === 'class' || name === 'data-language' || name === 'data-role' || name === 'data-label';
        if (!allowed || name.startsWith('on') || /javascript:/i.test(attr.value)) node.removeAttribute(attr.name);
      });
    });
    el.replaceChildren(...parsed.body.childNodes);
    decorateCodeBlocks(el);
  }

  function filteredTutorials() {
    return learn.tutorials;
  }

  function renderStudentFilters() {
    const root = document.getElementById('studentCategoryFilters');
    const items = [{ id: '', name: 'All' }, ...learn.categories];
    root.innerHTML = items.map((row) => (
      `<button type="button" class="btn btn-sm ${learn.categoryId === row.id ? 'btn-primary' : 'btn-outline-secondary'}" data-student-category="${esc(row.id)}">${esc(row.name)}</button>`
    )).join('');
    root.querySelectorAll('[data-student-category]').forEach((btn) => {
      btn.addEventListener('click', () => {
        learn.categoryId = btn.getAttribute('data-student-category') || '';
        renderStudentFilters();
        loadStudentList().catch(fail);
      });
    });
  }

  function renderStudentCards() {
    const root = document.getElementById('studentTutorialCards');
    const rows = filteredTutorials();
    if (!learn.tutorials.length) {
      const message = learn.query || learn.categoryId
        ? 'No tutorials match this search or category.'
        : 'No tutorials are currently available. Published HTML/CSS and Git courses should appear here for every student.';
      root.innerHTML = `<div class="col-12"><div class="card-surface p-4 text-muted-2">${esc(message)}</div></div>`;
      return;
    }
    root.innerHTML = rows.map((row) => {
      const thumb = String(row.thumbnail || '');
      const image = /^(https?:\/\/|\/)/i.test(thumb)
        ? `<img src="${esc(thumb)}" alt="" class="rounded mb-3" style="max-height:8rem;object-fit:cover">`
        : '';
      const count = Number(row.moduleCount || (row.modules || []).length || 0);
      return `<div class="col-md-6 col-xl-4"><div class="card-surface p-3 h-100 d-flex flex-column">
        ${image}
        <div class="small text-muted-2">${esc(row.category ? row.category.name : '')}</div>
        <h2 class="h5 fw-bold mb-1">${esc(row.title)}</h2>
        <div class="small fw-semibold mb-2">${esc(row.topic || '')}</div>
        <p class="small text-muted-2 flex-grow-1">${esc(row.description || '')}</p>
        <div class="small mb-2">${esc(count)} module${count === 1 ? '' : 's'}</div>
        <div class="small mb-1">${esc(row.progress ? `${row.progress.completedModules || 0} / ${row.progress.totalModules || count} modules · ${row.progress.progressPercent || 0}%` : 'Not started')}</div>
        <div class="progress mb-3" style="height:.4rem" aria-hidden="true"><div class="progress-bar" style="width:${esc(row.progress ? row.progress.progressPercent || 0 : 0)}%"></div></div>
        <button type="button" class="btn btn-primary" data-start-tutorial="${esc(row.id)}">${esc(row.progress && row.progress.lastVisitedModuleId ? 'Continue Learning' : 'Start Course')}</button>
      </div></div>`;
    }).join('');
    root.querySelectorAll('[data-start-tutorial]').forEach((btn) => {
      btn.addEventListener('click', () => openOverview(btn.getAttribute('data-start-tutorial')));
    });
  }

  async function loadStudentList() {
    const request = ++learn.listRequest;
    const root = document.getElementById('studentTutorialCards');
    root.innerHTML = '<div class="col-12"><div class="card-surface p-4 text-muted-2">Loading tutorials…</div></div>';
    try {
      const params = new URLSearchParams();
      if (learn.query.trim()) params.set('search', learn.query.trim());
      if (learn.categoryId) params.set('category', learn.categoryId);
      const tutorials = await call('/tutorials' + (params.toString() ? `?${params}` : ''));
      if (request !== learn.listRequest) return;
      if (!learn.categoriesLoaded) {
        learn.categories = await call('/tutorial-categories') || [];
        learn.categoriesLoaded = true;
        if (request !== learn.listRequest) return;
        renderStudentFilters();
      }
      learn.tutorials = tutorials || [];
      renderStudentCards();
    } catch (err) {
      if (request !== learn.listRequest) return;
      root.innerHTML = `<div class="col-12"><div class="card-surface p-4 text-danger">${esc(err.message || 'Could not load tutorials.')}</div></div>`;
    }
  }

  function showStudentScreen(name) {
    document.getElementById('studentListView').classList.toggle('d-none', name !== 'list');
    document.getElementById('studentOverview').classList.toggle('d-none', name !== 'overview');
    document.getElementById('studentLearnView').classList.toggle('d-none', name !== 'learn');
  }

  async function openOverview(id) {
    try {
      const detail = await call(`/tutorials/${encodeURIComponent(id)}`);
      const progress = await call(`/tutorials/${encodeURIComponent(id)}/progress`);
      learn.detail = detail;
      learn.progress = progress;
      document.getElementById('overviewCategory').textContent = detail.category ? detail.category.name : '';
      document.getElementById('overviewTitle').textContent = detail.title || '';
      document.getElementById('overviewDescription').textContent = detail.description || '';
      document.getElementById('overviewModules').textContent = `${detail.moduleCount || (detail.modules || []).length} modules`;
      document.getElementById('overviewExercises').textContent = '';
      document.getElementById('overviewProgress').textContent = progress.completed
        ? 'Course complete'
        : `Progress: ${progress.progressPercent || 0}% · ${progress.completedModules || 0} of ${progress.totalModules || 0} modules completed`;
      const done = new Set(progress.completedModuleIds || []);
      document.getElementById('overviewOutline').innerHTML = (detail.modules || []).map((module) => {
        const mark = done.has(module.id) ? '✓' : (progress.lastVisitedModuleId === module.id ? '●' : '○');
        return `<div>${mark} ${esc(module.title)}</div>`;
      }).join('') || '<p class="text-muted-2 mb-0">This course has no modules yet.</p>';
      const button = document.getElementById('overviewContinue');
      button.textContent = progress.completed ? 'Review course' : (progress.lastVisitedModuleId ? 'Continue Learning' : 'Start Learning');
      showStudentScreen('overview');
    } catch (err) {
      fail(err);
    }
  }

  async function openStudentTutorial(id) {
    try {
      learn.detail = await call(`/tutorials/${encodeURIComponent(id)}`);
      learn.progress = await call(`/tutorials/${encodeURIComponent(id)}/progress/start`, { method: 'POST', body: {} });
      const resume = learn.progress && learn.progress.lastVisitedModuleId;
      const modules = learn.detail.modules || [];
      const resumeIndex = modules.findIndex((module) => module.id === resume);
      learn.moduleIndex = resumeIndex >= 0 ? resumeIndex : 0;
      learn.lessonIndex = 0;
      learn.lessons = [];
      paintProgress();
      document.getElementById('studentTutorialHeading').textContent = learn.detail.title || '';
      document.getElementById('studentTutorialMeta').textContent = learn.detail.category ? learn.detail.category.name : '';
      document.getElementById('studentTutorialSummary').textContent = learn.detail.description || '';
      showStudentScreen('learn');
      await showStudentModule();
    } catch (err) {
      fail(err);
    }
  }

  function lessonOutlineFor(module, index) {
    if (index === learn.moduleIndex && Array.isArray(learn.lessons) && learn.lessons.length) {
      return learn.lessons.map((lesson) => ({ id: lesson.id, title: lesson.title }));
    }
    if (Array.isArray(module.lessonOutline) && module.lessonOutline.length) return module.lessonOutline;
    if (Array.isArray(module.lessons) && module.lessons.length) {
      return module.lessons.map((lesson) => ({ id: lesson.id, title: lesson.title }));
    }
    return [{ id: 'module', title: module.title || 'Lesson' }];
  }

  function lessonIsComplete(moduleId, lessonId) {
    const flags = (learn.progress && learn.progress.workspace && learn.progress.workspace.lessons) || {};
    return flags[`${moduleId}:${lessonId}`] === true;
  }

  function renderStudentModuleNav() {
    const modules = (learn.detail && learn.detail.modules) || [];
    const root = document.getElementById('studentModuleNav');
    if (!root) return;
    if (!modules.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">This course has no modules yet.</p>';
      return;
    }
    root.innerHTML = modules.map((module, index) => {
      const expanded = learn.expandedModules[module.id] !== false && (learn.expandedModules[module.id] === true || index === learn.moduleIndex);
      const lessons = lessonOutlineFor(module, index);
      const moduleReady = ((learn.progress && learn.progress.workspace && learn.progress.workspace.readyModuleIds) || []).includes(module.id);
      const lessonButtons = expanded ? `<div class="student-lessons">${lessons.map((lesson, lessonIndex) => {
        const active = index === learn.moduleIndex && lessonIndex === learn.lessonIndex;
        const done = lessonIsComplete(module.id, lesson.id);
        return `<button type="button" class="btn btn-sm student-lesson ${active ? 'btn-primary is-active' : 'btn-outline-secondary'}" data-student-lesson-jump="${index}:${lessonIndex}"><span class="me-1" aria-hidden="true">${done ? '✓' : '○'}</span>${esc(lesson.title || ('Lesson ' + (lessonIndex + 1)))}</button>`;
      }).join('')}</div>` : '';
      return `<div class="student-module ${index === learn.moduleIndex ? 'is-active' : ''}">
        <button type="button" class="student-module-toggle" data-student-module="${index}" aria-expanded="${expanded ? 'true' : 'false'}">
          <span class="me-1" aria-hidden="true">${expanded ? '▾' : '▸'}</span>
          <span class="me-1" aria-hidden="true">${moduleReady ? '✓' : '○'}</span>
          Module ${index + 1}: ${esc(module.title || 'Module')}
        </button>
        ${lessonButtons}
      </div>`;
    }).join('');
    root.querySelectorAll('[data-student-module]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const index = Number(btn.getAttribute('data-student-module'));
        const module = modules[index];
        if (!module) return;
        const wasExpanded = learn.expandedModules[module.id] === true || (learn.expandedModules[module.id] !== false && index === learn.moduleIndex);
        learn.expandedModules[module.id] = !wasExpanded;
        if (index !== learn.moduleIndex) {
          learn.expandedModules[module.id] = true;
          showStudentModule(index).catch(fail);
          return;
        }
        renderStudentModuleNav();
      });
    });
    root.querySelectorAll('[data-student-lesson-jump]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const parts = String(btn.getAttribute('data-student-lesson-jump') || '').split(':');
        const moduleIndex = Number(parts[0]);
        const lessonIndex = Number(parts[1]);
        if (moduleIndex !== learn.moduleIndex) showStudentModule(moduleIndex, lessonIndex).catch(fail);
        else showStudentLesson(lessonIndex).catch(fail);
      });
    });
  }

  function currentLessonId() {
    const lesson = (learn.lessons || [])[learn.lessonIndex];
    return lesson ? String(lesson.id || '') : '';
  }

  function practiceMatchesLesson(item) {
    const linked = String((item && item.lessonBlockId) || '').trim();
    if (!linked) return true;
    return linked === currentLessonId();
  }

  function renderStudentLessonNav() {
    renderStudentModuleNav();
  }

  function updateLessonNavButtons() {
    const modules = (learn.detail && learn.detail.modules) || [];
    const lessons = learn.lessons || [];
    const prev = document.getElementById('studentPrevModule');
    const next = document.getElementById('studentNextModule');
    if (!prev || !next) return;
    const atFirst = learn.moduleIndex <= 0 && learn.lessonIndex <= 0;
    const lastLesson = learn.lessonIndex >= Math.max(lessons.length - 1, 0);
    const atLast = learn.moduleIndex >= modules.length - 1 && lastLesson;
    prev.disabled = atFirst || !modules.length;
    next.disabled = atLast || !modules.length;
    prev.textContent = 'Previous lesson';
    next.textContent = !atLast && lastLesson && learn.moduleIndex < modules.length - 1 ? 'Continue to next module' : 'Next lesson';
    const markLesson = document.getElementById('studentMarkLesson');
    if (markLesson) {
      const module = modules[learn.moduleIndex];
      const lesson = lessons[learn.lessonIndex];
      const hasPractice = (learn.practiceQuestions || []).length > 0;
      const done = module && lesson ? lessonIsComplete(module.id, lesson.id) : false;
      markLesson.disabled = !module || !lesson || hasPractice || done;
      markLesson.textContent = done ? 'Lesson complete' : (hasPractice ? 'Answer the practice questions' : 'Mark lesson complete');
    }
  }

  async function showStudentLesson(nextLessonIndex) {
    const courseNav = document.getElementById('studentCourseNav');
    const backdrop = document.getElementById('studentNavBackdrop');
    if (courseNav) courseNav.classList.remove('is-open');
    if (backdrop) backdrop.classList.remove('is-open');
    const lessons = learn.lessons || [];
    if (!lessons.length) {
      document.getElementById('studentLessonHeading').textContent = '';
      document.getElementById('studentModuleContent').textContent = 'This module does not have lesson content yet.';
      renderStudentLessonNav();
      await renderStudentPracticeForLesson();
      updateLessonNavButtons();
      return;
    }
    if (typeof nextLessonIndex === 'number') {
      learn.lessonIndex = Math.max(0, Math.min(lessons.length - 1, nextLessonIndex));
    }
    const lesson = lessons[learn.lessonIndex] || lessons[0];
    renderStudentLessonNav();
    document.getElementById('studentLessonHeading').textContent = lesson.title || '';
    const content = document.getElementById('studentModuleContent');
    if (lesson.html) setLessonHtml(content, lesson.html);
    else content.textContent = 'This lesson does not have content yet.';
    if (learn.activity && !practiceMatchesLesson(learn.activity)) {
      learn.activity = null;
      learn.activityAttempt = null;
      learn.activityAttempts = [];
      learn.activityDirty = false;
      learn.activityViewingSubmitted = false;
      const panel = document.getElementById('studentActivityPanel');
      if (panel) panel.classList.add('d-none');
    }
    await renderStudentPracticeForLesson();
    updateLessonNavButtons();
  }

  function syncPracticeEmpty(questionCount) {
    const activities = (learn.activities || []).filter(practiceMatchesLesson);
    const hasAssessment = !document.getElementById('studentAssessmentSection').classList.contains('d-none');
    const empty = document.getElementById('studentPracticeEmpty');
    if (empty) empty.classList.toggle('d-none', !!(questionCount || activities.length || hasAssessment));
    const hint = document.getElementById('studentPracticeHint');
    if (hint) {
      const lesson = (learn.lessons || [])[learn.lessonIndex];
      hint.textContent = lesson
        ? `Practice for “${lesson.title || 'this lesson'}”.`
        : 'Questions for the selected lesson.';
    }
  }

  async function renderStudentPracticeForLesson() {
    const host = document.getElementById('studentPracticeQuestions');
    if (!host) return;
    learn.practiceFeedback = null;
    const module = learn.module;
    const lesson = (learn.lessons || [])[learn.lessonIndex];
    renderStudentActivityList();
    if (!learn.detail || !module || !lesson) {
      learn.practiceQuestions = [];
      host.innerHTML = '<p class="text-muted-2 mb-0">Select a lesson to practise.</p>';
      syncPracticeEmpty(0);
      return;
    }
    const lessonId = String(lesson.id || '');
    host.innerHTML = '<p class="text-muted-2 mb-0">Loading questions…</p>';
    try {
      const data = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(module.id)}/lesson-practice/${encodeURIComponent(lessonId)}`);
      if (currentLessonId() !== lessonId) return;
      learn.practiceQuestions = Array.isArray(data && data.questions) ? data.questions : [];
      learn.practiceIndex = 0;
      learn.practiceSelection = {};
      paintPracticeQuestion();
    } catch (err) {
      if (currentLessonId() !== lessonId) return;
      learn.practiceQuestions = [];
      host.innerHTML = `<p class="text-muted-2 mb-0">${esc(err && err.message ? err.message : 'Questions could not be loaded.')}</p>`;
    }
    syncPracticeEmpty((learn.practiceQuestions || []).length);
  }

  function paintPracticeQuestion() {
    const host = document.getElementById('studentPracticeQuestions');
    const questions = learn.practiceQuestions || [];
    if (!host) return;
    if (!questions.length) {
      host.innerHTML = '<p class="text-muted-2 mb-0">No questions for this lesson yet.</p>';
      return;
    }
    const index = Math.max(0, Math.min(questions.length - 1, learn.practiceIndex || 0));
    learn.practiceIndex = index;
    const question = questions[index];
    const selected = learn.practiceSelection[question.id];
    const feedback = learn.practiceFeedback && learn.practiceFeedback.questionId === question.id ? learn.practiceFeedback : null;
    const options = (question.options || []).map((option, optionIndex) => (
      `<label class="d-flex gap-2 align-items-start border rounded p-2 mb-2">
        <input class="mt-1" type="radio" name="lesson-practice-option" value="${optionIndex}" ${selected === optionIndex ? 'checked' : ''}/>
        <span>${esc(option)}</span>
      </label>`
    )).join('');
    const result = feedback
      ? `<div class="alert ${feedback.correct ? 'alert-success' : 'alert-danger'} py-2 mt-2 mb-0"><div class="fw-semibold">${feedback.correct ? 'Correct' : 'Incorrect'}</div>${feedback.explanation ? `<div class="small mt-1">${esc(feedback.explanation)}</div>` : ''}</div>`
      : (question.answeredCorrectly ? '<div class="small text-success mt-2">Answered correctly.</div>' : '');
    host.innerHTML = `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <div class="small text-muted-2">Question ${index + 1} of ${questions.length}</div>
        <div class="d-flex gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-practice-prev ${index === 0 ? 'disabled' : ''}>Previous</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-practice-next ${index === questions.length - 1 ? 'disabled' : ''}>Next</button>
        </div>
      </div>
      <div class="fw-semibold mb-2">${esc(question.question || '')}</div>
      ${options}
      <button type="button" class="btn btn-sm btn-primary" data-practice-check>Check answer</button>
      ${result}
    `;
    host.querySelectorAll('input[name="lesson-practice-option"]').forEach((input) => {
      input.addEventListener('change', () => {
        learn.practiceSelection[question.id] = Number(input.value);
      });
    });
    const prev = host.querySelector('[data-practice-prev]');
    const next = host.querySelector('[data-practice-next]');
    if (prev) prev.addEventListener('click', () => { learn.practiceIndex -= 1; learn.practiceFeedback = null; paintPracticeQuestion(); });
    if (next) next.addEventListener('click', () => { learn.practiceIndex += 1; learn.practiceFeedback = null; paintPracticeQuestion(); });
    const check = host.querySelector('[data-practice-check]');
    if (check) check.addEventListener('click', () => { submitPracticeAnswer(question.id).catch(fail); });
  }

  async function submitPracticeAnswer(questionId) {
    const selected = learn.practiceSelection[questionId];
    if (selected == null || Number.isNaN(Number(selected))) {
      toast('Choose an answer first.', 'error');
      return;
    }
    const button = document.querySelector('#studentPracticeQuestions [data-practice-check]');
    if (button) button.disabled = true;
    try {
      const result = await call(`/tutorials/lesson-questions/${encodeURIComponent(questionId)}/check`, {
        method: 'POST',
        body: { selectedIndex: Number(selected) },
      });
      learn.practiceFeedback = result || null;
      const row = (learn.practiceQuestions || []).find((item) => item.id === questionId);
      if (row && result && result.correct) row.answeredCorrectly = true;
      paintPracticeQuestion();
      if (learn.detail) {
        learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/progress`);
        paintProgress();
      }
    } finally {
      const again = document.querySelector('#studentPracticeQuestions [data-practice-check]');
      if (again) again.disabled = false;
    }
  }

  async function goStudentLessonDelta(delta) {
    const modules = (learn.detail && learn.detail.modules) || [];
    const lessons = learn.lessons || [];
    if (!modules.length) return;
    let moduleIndex = learn.moduleIndex;
    let lessonIndex = learn.lessonIndex + delta;
    if (lessonIndex >= 0 && lessonIndex < lessons.length) {
      await showStudentLesson(lessonIndex);
      return;
    }
    if (delta > 0 && moduleIndex < modules.length - 1) {
      await showStudentModule(moduleIndex + 1);
      return;
    }
    if (delta < 0 && moduleIndex > 0) {
      await showStudentModule(moduleIndex - 1);
      if ((learn.lessons || []).length) {
        await showStudentLesson(learn.lessons.length - 1);
      }
    }
  }

  async function showStudentModule(nextIndex, lessonIndex) {
    if (typeof nextIndex === 'number' && nextIndex !== learn.moduleIndex) {
      if (learn.activityDirty) {
        const ok = await confirmAction({
          title: 'Leave unsaved activity?',
          message: 'You have unsaved activity responses. Leave this module without saving?',
          confirmText: 'Leave',
          variant: 'danger',
        });
        if (!ok) {
          renderStudentModuleNav();
          const select = document.getElementById('studentModuleSelect');
          if (select) select.value = String(learn.moduleIndex);
          return;
        }
        learn.activityDirty = false;
      }
      learn.moduleIndex = nextIndex;
      learn.lessonIndex = typeof lessonIndex === 'number' ? lessonIndex : 0;
      const opened = ((learn.detail && learn.detail.modules) || [])[nextIndex];
      if (opened) learn.expandedModules[opened.id] = true;
    } else if (learn.activityDirty && typeof nextIndex !== 'number') {
      const ok = await confirmAction({
        title: 'Leave unsaved activity?',
        message: 'You have unsaved activity responses. Reload this module without saving?',
        confirmText: 'Leave',
        variant: 'danger',
      });
      if (!ok) return;
      learn.activityDirty = false;
    }
    const modules = (learn.detail && learn.detail.modules) || [];
    renderStudentModuleNav();
    document.getElementById('studentAssessmentSection').classList.add('d-none');
    document.getElementById('studentAssessmentAttempt').classList.add('d-none');
    document.getElementById('studentAssessmentResults').classList.add('d-none');
    resetStudentActivityUi();
    if (!modules.length) {
      learn.lessons = [];
      learn.lessonIndex = 0;
      document.getElementById('studentModulePosition').textContent = '';
      document.getElementById('studentModuleHeading').textContent = 'No modules yet';
      document.getElementById('studentLessonHeading').textContent = '';
      document.getElementById('studentModuleContent').textContent = 'This tutorial does not have any lessons yet.';
      const practiceHost = document.getElementById('studentPracticeQuestions');
      if (practiceHost) practiceHost.innerHTML = '<p class="text-muted-2 mb-0">There are no questions until a module is added.</p>';
      const markLesson = document.getElementById('studentMarkLesson');
      if (markLesson) markLesson.disabled = true;
      renderStudentLessonNav();
      updateLessonNavButtons();
      return;
    }
    const summary = modules[learn.moduleIndex];
    document.getElementById('studentModuleHeading').textContent = 'Loading…';
    document.getElementById('studentLessonHeading').textContent = '';
    document.getElementById('studentModuleContent').textContent = 'Loading lesson…';
    const loadingPractice = document.getElementById('studentPracticeQuestions');
    if (loadingPractice) loadingPractice.innerHTML = '<p class="text-muted-2 mb-0">Loading practice…</p>';
    learn.module = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(summary.id)}`);
    try {
      learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/progress`);
      paintProgress();
    } catch { /* module content still shows */ }
    document.getElementById('studentModulePosition').textContent = `Module ${learn.moduleIndex + 1} of ${modules.length}`;
    document.getElementById('studentModuleHeading').textContent = learn.module.title || '';
    const subtitle = document.getElementById('studentModuleSubtitle');
    if (subtitle) subtitle.textContent = learn.module.subtitle || '';
    learn.lessons = Array.isArray(learn.module.lessons) && learn.module.lessons.length
      ? learn.module.lessons
      : [{
        id: 'module',
        title: learn.module.title || 'Lesson',
        html: learn.module.content || '',
      }];
    if (learn.lessonIndex >= learn.lessons.length) learn.lessonIndex = 0;
    await loadStudentAssessment();
    await loadStudentActivities();
    await showStudentLesson(learn.lessonIndex);
  }

  function resetStudentAssessmentUi() {
    learn.assessment = null;
    learn.assessmentAttemptId = null;
    learn.assessmentAnswers = {};
    learn.assessmentQuestionIndex = 0;
    learn.assessmentResult = null;
    document.getElementById('studentAssessmentSection').classList.add('d-none');
    document.getElementById('studentAssessmentAttempt').classList.add('d-none');
    document.getElementById('studentAssessmentResults').classList.add('d-none');
  }

  async function loadStudentAssessment() {
    resetStudentAssessmentUi();
    if (!learn.detail || !learn.module) return;
    try {
      const data = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/assessment`);
      learn.assessment = data;
      renderStudentAssessmentIntro();
    } catch (err) {
      if (err && err.status === 404) return;
      /* Missing assessment must not block lesson study. */
    }
  }

  function renderStudentAssessmentIntro() {
    const data = learn.assessment;
    if (!data || !data.assessment) return;
    const section = document.getElementById('studentAssessmentSection');
    const intro = document.getElementById('studentAssessmentIntro');
    const a = data.assessment;
    const summaries = (data.attemptSummaries || []).filter((row) => row.status === 'SUBMITTED');
    section.classList.remove('d-none');
    intro.innerHTML = `
      <div class="fw-semibold">${esc(a.title || 'Module quiz')}</div>
      <div class="small text-muted-2 mb-2">${a.questionCount || 0} questions · ${a.totalMarks || 0} marks · pass ${a.passPercent || 60}% · ${data.attemptsRemaining || 0} attempt(s) left</div>
      ${summaries.length ? `<div class="small mb-2">Best recent: ${summaries.map((row) => `#${row.attemptNumber} ${row.percent}% ${row.passed ? 'Pass' : 'Fail'}`).join(' · ')}</div>` : ''}
      <button type="button" class="btn btn-sm btn-primary" id="studentAssessmentStartBtn" ${(data.attemptsRemaining || 0) <= 0 && !data.inProgressAttemptId ? 'disabled' : ''}>${data.inProgressAttemptId ? 'Continue assessment' : 'Start assessment'}</button>
    `;
    const startBtn = document.getElementById('studentAssessmentStartBtn');
    if (startBtn) startBtn.addEventListener('click', () => startStudentAssessment().catch(fail));
  }

  async function startStudentAssessment() {
    if (!learn.detail || !learn.module || !learn.assessment) return;
    const started = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/assessment/start`, {
      method: 'POST',
      body: {},
    });
    learn.assessmentAttemptId = started.attempt && started.attempt.id;
    learn.assessmentAnswers = {};
    learn.assessmentQuestionIndex = 0;
    learn.assessmentResult = null;
    document.getElementById('studentAssessmentResults').classList.add('d-none');
    renderStudentAssessmentAttempt();
  }

  function renderStudentAssessmentAttempt() {
    const data = learn.assessment;
    const questions = (data && data.questions) || [];
    const root = document.getElementById('studentAssessmentAttempt');
    if (!questions.length) {
      root.classList.add('d-none');
      return;
    }
    const index = Math.max(0, Math.min(learn.assessmentQuestionIndex || 0, questions.length - 1));
    learn.assessmentQuestionIndex = index;
    const q = questions[index];
    const answered = Object.keys(learn.assessmentAnswers || {}).length;
    root.classList.remove('d-none');
    root.innerHTML = `
      <div class="d-flex justify-content-between gap-2 mb-2">
        <div class="fw-semibold">Question ${index + 1} of ${questions.length}</div>
        <div class="small text-muted-2">${answered}/${questions.length} answered · ${esc(q.marks || 1)} mark(s)</div>
      </div>
      <div class="progress mb-3" style="height:.4rem"><div class="progress-bar" style="width:${Math.round((answered / questions.length) * 100)}%"></div></div>
      <div class="mb-3">${esc(q.question || '')}</div>
      ${(q.options || []).map((opt, oi) => (
        `<div class="form-check mb-2">
          <input class="form-check-input" type="radio" name="studentMcq" id="studentMcq${oi}" value="${oi}" ${Number(learn.assessmentAnswers[q.id]) === oi ? 'checked' : ''}/>
          <label class="form-check-label" for="studentMcq${oi}">${String.fromCharCode(65 + oi)}. ${esc(opt)}</label>
        </div>`
      )).join('')}
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="studentMcqPrev" ${index === 0 ? 'disabled' : ''}>Previous</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="studentMcqNext" ${index >= questions.length - 1 ? 'disabled' : ''}>Next</button>
        <button type="button" class="btn btn-sm btn-primary ms-auto" id="studentMcqSubmit">Submit assessment</button>
      </div>
    `;
    root.querySelectorAll('input[name="studentMcq"]').forEach((input) => {
      input.addEventListener('change', () => {
        learn.assessmentAnswers[q.id] = Number(input.value);
        renderStudentAssessmentAttempt();
      });
    });
    const prev = document.getElementById('studentMcqPrev');
    const next = document.getElementById('studentMcqNext');
    const submit = document.getElementById('studentMcqSubmit');
    if (prev) prev.addEventListener('click', () => {
      learn.assessmentQuestionIndex = Math.max(0, index - 1);
      renderStudentAssessmentAttempt();
    });
    if (next) next.addEventListener('click', () => {
      learn.assessmentQuestionIndex = Math.min(questions.length - 1, index + 1);
      renderStudentAssessmentAttempt();
    });
    if (submit) submit.addEventListener('click', () => submitStudentAssessment().catch(fail));
  }

  async function submitStudentAssessment() {
    if (!learn.detail || !learn.module || !learn.assessment) return;
    const questions = learn.assessment.questions || [];
    if (Object.keys(learn.assessmentAnswers || {}).length !== questions.length) {
      toast('Answer every question before submitting.', 'error');
      return;
    }
    const ok = await confirmAction({
      title: 'Submit assessment',
      message: 'Submit your answers? You cannot change this attempt afterward.',
      confirmText: 'Submit',
    });
    if (!ok) return;
    const result = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/assessment/submit`, {
      method: 'POST',
      body: {
        attemptId: learn.assessmentAttemptId,
        answers: questions.map((q) => ({
          questionId: q.id,
          selectedIndex: Number(learn.assessmentAnswers[q.id]),
        })),
      },
    });
    learn.assessmentResult = result;
    document.getElementById('studentAssessmentAttempt').classList.add('d-none');
    renderStudentAssessmentResults(result);
    try {
      learn.assessment = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/assessment`);
      renderStudentAssessmentIntro();
    } catch { /* keep results visible */ }
  }

  function renderStudentAssessmentResults(result) {
    const root = document.getElementById('studentAssessmentResults');
    root.classList.remove('d-none');
    const review = result.review || [];
    root.innerHTML = `
      <div class="fw-semibold mb-1">${result.passed ? 'Passed' : 'Not passed'}</div>
      <div class="mb-3">${result.score}/${result.totalMarks} · ${result.percent}% (pass ${result.passPercent}%)</div>
      ${review.length ? review.map((row, index) => (
        `<div class="border rounded p-2 mb-2">
          <div class="fw-semibold">Q${index + 1}. ${esc(row.question || '')}</div>
          <div class="small ${row.isCorrect ? 'text-success' : 'text-danger'}">${row.isCorrect ? 'Correct' : 'Incorrect'} · ${row.marksAwarded}/${row.marks}</div>
          ${(row.options || []).map((opt, oi) => {
            const mark = oi === row.selectedIndex ? ' (your answer)' : '';
            const key = result.allowReview && oi === row.correctIndex ? ' ✓' : '';
            return `<div class="small">${String.fromCharCode(65 + oi)}. ${esc(opt)}${mark}${key}</div>`;
          }).join('')}
          ${result.showExplanations && row.explanation ? `<div class="small text-muted-2 mt-1">${esc(row.explanation)}</div>` : ''}
        </div>`
      )).join('') : '<p class="small text-muted-2 mb-0">Review is disabled for this assessment.</p>'}
    `;
  }

  function resetStudentActivityUi() {
    learn.activities = [];
    learn.activityStatuses = {};
    learn.activity = null;
    learn.activityAttempts = [];
    learn.activityAttempt = null;
    learn.activityBusy = false;
    learn.activityDirty = false;
    learn.activityViewingSubmitted = false;
    const section = document.getElementById('studentActivitySection');
    const panel = document.getElementById('studentActivityPanel');
    const list = document.getElementById('studentActivityList');
    if (section) section.classList.add('d-none');
    if (panel) panel.classList.add('d-none');
    if (list) list.innerHTML = '';
  }

  function studentActivityBasePath(activityId) {
    return `/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/activities/${encodeURIComponent(activityId)}`;
  }

  function studentAttemptStatusLabel(attempt) {
    if (!attempt) return 'Not started';
    const status = String(attempt.status || '');
    if (status === 'IN_PROGRESS') return 'In progress';
    if (status === 'SUBMITTED') return 'Submitted';
    if (status === 'RETURNED') return 'Returned';
    return status || 'Unknown';
  }

  function studentAttemptStatusBadge(attempt) {
    if (!attempt) return '<span class="badge text-bg-secondary">Not started</span>';
    const status = String(attempt.status || '');
    if (status === 'IN_PROGRESS') return '<span class="badge text-bg-warning">In progress</span>';
    if (status === 'SUBMITTED') return '<span class="badge text-bg-success">Submitted</span>';
    if (status === 'RETURNED') return '<span class="badge text-bg-info">Returned</span>';
    return `<span class="badge text-bg-secondary">${esc(status)}</span>`;
  }

  async function loadStudentActivities() {
    learn.activity = null;
    learn.activityAttempts = [];
    learn.activityAttempt = null;
    learn.activityBusy = false;
    learn.activityDirty = false;
    learn.activityViewingSubmitted = false;
    const panel = document.getElementById('studentActivityPanel');
    if (panel) panel.classList.add('d-none');
    if (!learn.detail || !learn.module) {
      learn.activities = [];
      learn.activityStatuses = {};
      return;
    }
    const section = document.getElementById('studentActivitySection');
    const list = document.getElementById('studentActivityList');
    list.innerHTML = '<p class="text-muted-2 mb-0">Loading activities…</p>';
    try {
      const data = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(learn.module.id)}/activities`);
      learn.activities = data.activities || [];
      if (!learn.activities.length) {
        if (section) section.classList.add('d-none');
        list.innerHTML = '';
        learn.activityStatuses = {};
        return;
      }
      if (section) section.classList.remove('d-none');
      const statuses = {};
      await Promise.all(learn.activities.map(async (row) => {
        try {
          const attemptsData = await call(studentActivityBasePath(row.id) + '/attempts');
          const attempts = attemptsData.attempts || [];
          statuses[row.id] = attempts[0] || null;
        } catch {
          statuses[row.id] = null;
        }
      }));
      learn.activityStatuses = statuses;
      renderStudentActivityList();
    } catch (err) {
      learn.activities = [];
      learn.activityStatuses = {};
      if (err && err.status === 404) {
        if (section) section.classList.add('d-none');
        return;
      }
      list.innerHTML = `<p class="text-danger mb-0">${esc(err.message || 'Could not load activities.')}</p>`;
    }
  }

  function renderStudentActivityList() {
    const list = document.getElementById('studentActivityList');
    const section = document.getElementById('studentActivitySection');
    const all = learn.activities || [];
    const rows = all.filter(practiceMatchesLesson);
    if (!all.length) {
      if (section) section.classList.add('d-none');
      list.innerHTML = '';
      return;
    }
    if (section) section.classList.remove('d-none');
    if (!rows.length) {
      list.innerHTML = '<p class="text-muted-2 mb-0">No practical activities for this lesson.</p>';
      return;
    }
    list.innerHTML = rows.map((row) => {
      const latest = learn.activityStatuses[row.id];
      const preview = String(row.instructions || '').replace(/<[^>]+>/g, ' ').trim();
      const short = preview.length > 140 ? `${preview.slice(0, 140)}…` : preview;
      const evalLabel = STUDENT_EVAL_LABELS[row.evaluationMode] || row.evaluationMode || '';
      const actionLabel = latest && latest.status === 'IN_PROGRESS'
        ? 'Continue'
        : (latest && latest.status === 'SUBMITTED' ? 'View' : 'Open');
      return `<div class="border rounded p-3" data-student-activity-card="${esc(row.id)}">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
          <div class="flex-grow-1">
            <div class="fw-semibold">${esc(row.title || 'Activity')}</div>
            <div class="small text-muted-2 mb-1">${esc(STUDENT_ACTIVITY_TYPE_LABELS[row.activityType] || row.activityType)} · ${esc(row.difficulty || 'beginner')} · ${esc(evalLabel)}</div>
            <div class="small mb-2">${esc(short || 'No instructions preview.')}</div>
            <div>${studentAttemptStatusBadge(latest)}</div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" data-open-student-activity="${esc(row.id)}">${actionLabel}</button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('[data-open-student-activity]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openStudentActivity(btn.getAttribute('data-open-student-activity')).catch(fail);
      });
    });
  }

  async function openStudentActivity(activityId) {
    if (!learn.detail || !learn.module || !activityId) return;
    if (learn.activityDirty) {
      const ok = await confirmAction({
        title: 'Discard unsaved response?',
        message: 'You have unsaved changes in the current activity. Discard them?',
        confirmText: 'Discard',
        variant: 'danger',
      });
      if (!ok) return;
    }
    const panel = document.getElementById('studentActivityPanel');
    panel.classList.remove('d-none');
    document.getElementById('studentActivityTitle').textContent = 'Loading…';
    document.getElementById('studentActivityMeta').textContent = '';
    document.getElementById('studentActivityInstructions').textContent = '';
    document.getElementById('studentActivityConfig').innerHTML = '';
    document.getElementById('studentActivityEditor').innerHTML = '';
    document.getElementById('studentActivityResult').classList.add('d-none');
    document.getElementById('studentActivityHistory').innerHTML = '<p class="text-muted-2 mb-0">Loading attempts…</p>';
    try {
      const [activity, attemptsData] = await Promise.all([
        call(studentActivityBasePath(activityId)),
        call(studentActivityBasePath(activityId) + '/attempts'),
      ]);
      learn.activity = activity;
      learn.activityAttempts = attemptsData.attempts || [];
      learn.activityDirty = false;
      learn.activityViewingSubmitted = false;
      const inProgress = learn.activityAttempts.find((row) => row.status === 'IN_PROGRESS') || null;
      learn.activityAttempt = inProgress;
      paintStudentActivityPanel();
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
      fail(err);
      panel.classList.add('d-none');
    }
  }

  function paintStudentActivityPanel() {
    const activity = learn.activity;
    if (!activity) return;
    const attempt = learn.activityAttempt;
    const locked = !!(attempt && attempt.status === 'SUBMITTED') || learn.activityViewingSubmitted;
    const display = (attempt && attempt.activitySnapshot)
      ? {
        ...activity,
        title: attempt.activitySnapshot.title || activity.title,
        instructions: attempt.activitySnapshot.instructions || activity.instructions,
        activityType: attempt.activitySnapshot.activityType || activity.activityType,
        difficulty: attempt.activitySnapshot.difficulty || activity.difficulty,
        evaluationMode: attempt.activitySnapshot.evaluationMode || activity.evaluationMode,
        config: attempt.activitySnapshot.config || activity.config,
      }
      : activity;
    document.getElementById('studentActivityTitle').textContent = display.title || 'Activity';
    document.getElementById('studentActivityMeta').textContent = [
      STUDENT_ACTIVITY_TYPE_LABELS[display.activityType] || display.activityType,
      display.difficulty || 'beginner',
      STUDENT_EVAL_LABELS[display.evaluationMode] || display.evaluationMode,
    ].filter(Boolean).join(' · ');
    setLessonHtml(document.getElementById('studentActivityInstructions'), display.instructions || '');
    document.getElementById('studentActivityConfig').innerHTML = renderStudentActivityConfig(display);
    document.getElementById('studentActivityStatus').innerHTML = attempt
      ? `${studentAttemptStatusBadge(attempt)} <span class="small text-muted-2 ms-1">Attempt #${esc(attempt.attemptNumber || '')}</span>`
      : '<span class="badge text-bg-secondary">Not started</span>';
    renderStudentActivityEditor(display, attempt, locked);
    renderStudentActivityResult(attempt);
    renderStudentActivityActions(activity, attempt, locked);
    renderStudentActivityHistory();
  }

  function renderStudentActivityConfig(activity) {
    const config = (activity && activity.config) || {};
    const type = activity.activityType;
    const bits = [];
    if (type === 'programming_task') {
      bits.push(`<div><strong>Language:</strong> ${esc(languageLabel(config.language || 'text'))}</div>`);
      if (config.boilerplate) {
        bits.push(`<div class="mt-2"><div class="fw-semibold mb-1">Starter code</div><pre class="mb-0 small">${esc(config.boilerplate)}</pre></div>`);
      }
    } else if (type === 'sql_query') {
      if (config.schemaDescription) {
        bits.push(`<div><div class="fw-semibold mb-1">Database schema</div><pre class="mb-0 small">${esc(config.schemaDescription)}</pre></div>`);
      }
    } else if (type === 'numerical') {
      if (config.unit) bits.push(`<div><strong>Unit:</strong> ${esc(config.unit)}</div>`);
      if (config.tolerance != null && config.tolerance !== '') {
        bits.push(`<div><strong>Tolerance:</strong> ${esc(config.tolerance)}</div>`);
      }
    } else if (type === 'short_answer' && config.maxLength) {
      bits.push(`<div><strong>Max length:</strong> ${esc(config.maxLength)} characters</div>`);
    } else if (type === 'analytical_design') {
      if (config.deliverableHint) {
        bits.push(`<div><strong>Expected deliverable:</strong> ${esc(config.deliverableHint)}</div>`);
      }
    } else if (type === 'case_study' && Array.isArray(config.parts) && config.parts.length) {
      bits.push(`<div><strong>Response sections:</strong> ${esc(config.parts.length)}</div>`);
    }
    if (config.promptHint) bits.push(`<div class="mt-1 text-muted-2">${esc(config.promptHint)}</div>`);
    return bits.length ? bits.join('') : '';
  }

  function renderStudentActivityEditor(activity, attempt, locked) {
    const root = document.getElementById('studentActivityEditor');
    const type = activity.activityType;
    const config = activity.config || {};
    const payload = (attempt && attempt.payload) || {};
    const disabled = locked ? 'disabled' : '';
    if ((activity.evaluationMode || '') === 'none') {
      root.innerHTML = '<p class="small text-muted-2 mb-0">This activity does not accept submissions.</p>';
      return;
    }
    if (!attempt) {
      root.innerHTML = '<p class="small text-muted-2 mb-0">Start an attempt to enter your response.</p>';
      return;
    }
    if (type === 'programming_task') {
      const source = Object.prototype.hasOwnProperty.call(payload, 'source')
        ? payload.source
        : (config.boilerplate || '');
      root.innerHTML = `
        <label class="form-label" for="studentActSource">Your code <span class="text-muted-2">(${esc(languageLabel(config.language || 'text'))})</span></label>
        <textarea class="form-control font-monospace" id="studentActSource" rows="12" spellcheck="false" ${disabled}>${esc(source)}</textarea>
        <div class="form-text">Stored as text only. There is no Run or Execute button.</div>`;
    } else if (type === 'sql_query') {
      root.innerHTML = `
        <label class="form-label" for="studentActSql">Your SQL</label>
        <textarea class="form-control font-monospace" id="studentActSql" rows="10" spellcheck="false" ${disabled}>${esc(payload.sql || '')}</textarea>
        <div class="form-text">SQL is never executed.</div>`;
    } else if (type === 'numerical') {
      root.innerHTML = `
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label" for="studentActValue">Numeric answer</label>
            <input class="form-control" id="studentActValue" type="number" step="any" value="${esc(payload.value != null ? payload.value : '')}" ${disabled}/>
          </div>
          <div class="col-md-6"><label class="form-label" for="studentActUnit">Unit</label>
            <input class="form-control" id="studentActUnit" maxlength="40" value="${esc(payload.unit != null ? payload.unit : (config.unit || ''))}" ${disabled}/>
          </div>
        </div>`;
    } else if (type === 'short_answer') {
      const max = Number(config.maxLength) || 1000;
      root.innerHTML = `
        <label class="form-label" for="studentActText">Your answer</label>
        <textarea class="form-control" id="studentActText" rows="6" maxlength="${esc(max)}" ${disabled}>${esc(payload.text || '')}</textarea>`;
    } else if (type === 'case_study') {
      const parts = Array.isArray(config.parts) ? config.parts : [];
      const answers = payload.parts || {};
      root.innerHTML = parts.length
        ? parts.map((part, index) => `
          <div class="mb-3">
            <label class="form-label" for="studentActPart${index}">Part ${index + 1}: ${esc(part.prompt || '')}</label>
            <textarea class="form-control" id="studentActPart${index}" data-part-id="${esc(part.id || '')}" rows="3" ${disabled}>${esc(answers[part.id] || '')}</textarea>
          </div>`).join('')
        : '<p class="text-danger mb-0">This case study has no response sections.</p>';
    } else {
      root.innerHTML = `
        <label class="form-label" for="studentActText">Your response</label>
        <textarea class="form-control" id="studentActText" rows="8" ${disabled}>${esc(payload.text || '')}</textarea>
        ${config.deliverableHint ? `<div class="form-text">${esc(config.deliverableHint)}</div>` : ''}`;
    }
    if (!locked) {
      root.querySelectorAll('textarea, input').forEach((input) => {
        input.addEventListener('input', () => {
          learn.activityDirty = true;
          document.getElementById('studentActivitySaveNote').textContent = 'Unsaved changes.';
        });
      });
    }
  }

  function renderStudentActivityResult(attempt) {
    const box = document.getElementById('studentActivityResult');
    if (!attempt || attempt.status !== 'SUBMITTED') {
      box.classList.add('d-none');
      box.innerHTML = '';
      return;
    }
    const result = attempt.autoResult || {};
    const review = attempt.review || null;
    const mode = result.mode || '';
    let body = '<div class="fw-semibold mb-1">Submission result</div>';
    if (mode === 'auto_compare') {
      body += result.matched
        ? '<div class="text-success">Matched within the allowed tolerance.</div>'
        : '<div class="text-danger">Did not match within the allowed tolerance.</div>';
    } else if (mode === 'self_check') {
      body += `<div class="small mb-1">Keywords matched: ${esc(result.keywordsMatched || 0)} / ${esc(result.keywordsTotal || 0)}</div>`;
      if (result.rubric) body += `<div class="small">${esc(result.rubric)}</div>`;
    } else if (mode === 'tutor_review') {
      if (review && review.status === 'reviewed') {
        const scoreLine = (review.score != null && review.maxScore != null)
          ? `${esc(review.score)} / ${esc(review.maxScore)}`
          : (review.score != null ? esc(review.score) : '');
        body += '<div class="text-success mb-1">Tutor review complete.</div>';
        if (scoreLine) body += `<div class="mb-1">Score: ${scoreLine}</div>`;
        if (review.passed === true) body += '<div class="small text-success mb-1">Passed</div>';
        if (review.passed === false) body += '<div class="small text-danger mb-1">Not passed</div>';
        if (review.feedback) body += `<div class="small" style="white-space:pre-wrap">${esc(review.feedback)}</div>`;
      } else {
        body += '<div class="text-muted-2">Pending tutor review.</div>';
      }
    } else {
      body += '<div class="text-muted-2">Submitted.</div>';
    }
    box.innerHTML = body;
    box.classList.remove('d-none');
  }

  function renderStudentActivityActions(activity, attempt, locked) {
    const startBtn = document.getElementById('studentActivityStartBtn');
    const saveBtn = document.getElementById('studentActivitySaveBtn');
    const submitBtn = document.getElementById('studentActivitySubmitBtn');
    const note = document.getElementById('studentActivitySaveNote');
    const noSubmit = (activity.evaluationMode || '') === 'none';
    const inProgress = (learn.activityAttempts || []).find((row) => row.status === 'IN_PROGRESS') || null;
    startBtn.classList.remove('d-none');
    startBtn.dataset.action = 'start';
    if (noSubmit) {
      startBtn.classList.add('d-none');
    } else if (learn.activityViewingSubmitted) {
      startBtn.textContent = inProgress ? 'Back to in-progress' : 'Start new attempt';
      startBtn.dataset.action = inProgress ? 'back' : 'start';
    } else if (attempt && attempt.status === 'IN_PROGRESS') {
      startBtn.classList.add('d-none');
    } else if (attempt && attempt.status === 'SUBMITTED') {
      startBtn.textContent = 'Start new attempt';
      startBtn.dataset.action = 'start';
    } else {
      startBtn.textContent = 'Start attempt';
      startBtn.dataset.action = 'start';
    }
    const editable = !!(attempt && attempt.status === 'IN_PROGRESS') && !locked && !learn.activityViewingSubmitted;
    saveBtn.classList.toggle('d-none', !editable);
    submitBtn.classList.toggle('d-none', !editable);
    if (locked || learn.activityViewingSubmitted) {
      note.textContent = 'This attempt is locked after submission.';
    } else if (learn.activityDirty) {
      note.textContent = 'Unsaved changes.';
    } else {
      note.textContent = '';
    }
  }

  function renderStudentActivityHistory() {
    const root = document.getElementById('studentActivityHistory');
    const rows = learn.activityAttempts || [];
    if (!rows.length) {
      root.innerHTML = '<p class="text-muted-2 mb-0">No attempts yet.</p>';
      return;
    }
    root.innerHTML = rows.map((row) => `
      <div class="border rounded p-2 d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
          <div class="fw-semibold">Attempt #${esc(row.attemptNumber || '')}</div>
          <div class="small text-muted-2">${esc(studentAttemptStatusLabel(row))}${row.submittedAt ? ` · ${esc(row.submittedAt)}` : ''}</div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-view-activity-attempt="${esc(row.id)}">Open</button>
      </div>`).join('');
    root.querySelectorAll('[data-view-activity-attempt]').forEach((btn) => {
      btn.addEventListener('click', () => {
        viewStudentActivityAttempt(btn.getAttribute('data-view-activity-attempt')).catch(fail);
      });
    });
  }

  async function viewStudentActivityAttempt(attemptId) {
    if (learn.activityDirty) {
      const ok = await confirmAction({
        title: 'Discard unsaved response?',
        message: 'Open another attempt and discard unsaved changes?',
        confirmText: 'Discard',
        variant: 'danger',
      });
      if (!ok) return;
    }
    const attempt = (learn.activityAttempts || []).find((row) => row.id === attemptId);
    if (!attempt) return;
    learn.activityAttempt = attempt;
    learn.activityDirty = false;
    learn.activityViewingSubmitted = attempt.status === 'SUBMITTED';
    paintStudentActivityPanel();
  }

  function studentAttemptActivityView() {
    const activity = learn.activity;
    if (!activity) return null;
    const snapshot = learn.activityAttempt && learn.activityAttempt.activitySnapshot
      ? learn.activityAttempt.activitySnapshot
      : null;
    if (!snapshot) return activity;
    return {
      ...activity,
      title: snapshot.title || activity.title,
      instructions: snapshot.instructions || activity.instructions,
      activityType: snapshot.activityType || activity.activityType,
      difficulty: snapshot.difficulty || activity.difficulty,
      evaluationMode: snapshot.evaluationMode || activity.evaluationMode,
      config: snapshot.config || activity.config,
    };
  }

  function collectStudentActivityResponse() {
    const activity = studentAttemptActivityView();
    if (!activity) throw new Error('Open an activity first.');
    const type = activity.activityType;
    if (type === 'programming_task') {
      return { source: document.getElementById('studentActSource').value };
    }
    if (type === 'sql_query') {
      return { sql: document.getElementById('studentActSql').value };
    }
    if (type === 'numerical') {
      const raw = document.getElementById('studentActValue').value.trim();
      if (raw === '') throw new Error('Enter a numeric answer.');
      if (Number.isNaN(Number(raw))) throw new Error('Numeric answer must be a number.');
      return {
        value: Number(raw),
        unit: document.getElementById('studentActUnit').value.trim(),
      };
    }
    if (type === 'case_study') {
      const parts = {};
      document.querySelectorAll('#studentActivityEditor [data-part-id]').forEach((input) => {
        const id = input.getAttribute('data-part-id');
        if (id) parts[id] = input.value;
      });
      return { parts };
    }
    return { text: document.getElementById('studentActText').value };
  }

  async function startStudentActivityAttempt() {
    if (!learn.activity || learn.activityBusy) return;
    const startBtn = document.getElementById('studentActivityStartBtn');
    const action = startBtn.dataset.action || 'start';
    if (action === 'back') {
      const inProgress = (learn.activityAttempts || []).find((row) => row.status === 'IN_PROGRESS') || null;
      learn.activityAttempt = inProgress;
      learn.activityViewingSubmitted = false;
      learn.activityDirty = false;
      paintStudentActivityPanel();
      return;
    }
    if ((learn.activity.evaluationMode || '') === 'none') {
      toast('This activity does not accept submissions.', 'error');
      return;
    }
    learn.activityBusy = true;
    startBtn.disabled = true;
    try {
      const started = await call(studentActivityBasePath(learn.activity.id) + '/start', {
        method: 'POST',
        body: {},
      });
      learn.activityAttempt = started.attempt || null;
      learn.activityViewingSubmitted = false;
      learn.activityDirty = false;
      const attemptsData = await call(studentActivityBasePath(learn.activity.id) + '/attempts');
      learn.activityAttempts = attemptsData.attempts || [];
      learn.activityStatuses[learn.activity.id] = learn.activityAttempts[0] || learn.activityAttempt;
      paintStudentActivityPanel();
      renderStudentActivityList();
      toast(started.resumed ? 'Resumed your in-progress attempt.' : 'Attempt started.', 'success');
    } catch (err) {
      fail(err);
      try {
        await openStudentActivity(learn.activity.id);
      } catch { /* keep current panel */ }
    } finally {
      learn.activityBusy = false;
      startBtn.disabled = false;
    }
  }

  async function saveStudentActivityResponse() {
    if (!learn.activity || !learn.activityAttempt || learn.activityBusy) return;
    if (learn.activityAttempt.status !== 'IN_PROGRESS') {
      toast('Submitted attempts cannot be edited.', 'error');
      return;
    }
    let response;
    try {
      response = collectStudentActivityResponse();
    } catch (err) {
      fail(err);
      return;
    }
    learn.activityBusy = true;
    const saveBtn = document.getElementById('studentActivitySaveBtn');
    const note = document.getElementById('studentActivitySaveNote');
    saveBtn.disabled = true;
    note.textContent = 'Saving…';
    try {
      const saved = await call(studentActivityBasePath(learn.activity.id) + '/save', {
        method: 'PUT',
        body: {
          attemptId: learn.activityAttempt.id,
          response,
        },
      });
      learn.activityAttempt = saved.attempt || learn.activityAttempt;
      learn.activityDirty = false;
      note.textContent = 'Saved.';
      toast('Response saved.', 'success');
      const attemptsData = await call(studentActivityBasePath(learn.activity.id) + '/attempts');
      learn.activityAttempts = attemptsData.attempts || [];
      learn.activityStatuses[learn.activity.id] = learn.activityAttempts[0] || learn.activityAttempt;
      renderStudentActivityHistory();
      renderStudentActivityList();
    } catch (err) {
      note.textContent = 'Save failed. Your local edits are still in the editor.';
      fail(err);
    } finally {
      learn.activityBusy = false;
      saveBtn.disabled = false;
    }
  }

  async function submitStudentActivityResponse() {
    if (!learn.activity || !learn.activityAttempt || learn.activityBusy) return;
    if (learn.activityAttempt.status !== 'IN_PROGRESS') {
      toast('This attempt was already submitted.', 'error');
      return;
    }
    let response;
    try {
      response = collectStudentActivityResponse();
      const attemptActivity = studentAttemptActivityView() || learn.activity;
      const attemptType = attemptActivity.activityType;
      if (attemptType === 'case_study') {
        const parts = (attemptActivity.config && attemptActivity.config.parts) || [];
        const missing = parts.some((part) => !String((response.parts || {})[part.id] || '').trim());
        if (missing) throw new Error('Answer every case-study part before submitting.');
      }
      if (attemptType === 'programming_task' && !String(response.source || '').trim()) {
        throw new Error('Source code is required.');
      }
      if (attemptType === 'sql_query' && !String(response.sql || '').trim()) {
        throw new Error('SQL is required.');
      }
      if ((attemptType === 'short_answer' || attemptType === 'analytical_design')
        && !String(response.text || '').trim()) {
        throw new Error('A response is required.');
      }
    } catch (err) {
      fail(err);
      return;
    }
    const ok = await confirmAction({
      title: 'Submit activity',
      message: 'Submit this response? You cannot edit this attempt afterward.',
      confirmText: 'Submit',
    });
    if (!ok) return;
    learn.activityBusy = true;
    const submitBtn = document.getElementById('studentActivitySubmitBtn');
    const saveBtn = document.getElementById('studentActivitySaveBtn');
    submitBtn.disabled = true;
    saveBtn.disabled = true;
    try {
      const result = await call(studentActivityBasePath(learn.activity.id) + '/submit', {
        method: 'POST',
        body: {
          attemptId: learn.activityAttempt.id,
          response,
        },
      });
      learn.activityAttempt = result.attempt || learn.activityAttempt;
      learn.activityDirty = false;
      learn.activityViewingSubmitted = true;
      const attemptsData = await call(studentActivityBasePath(learn.activity.id) + '/attempts');
      learn.activityAttempts = attemptsData.attempts || [];
      learn.activityStatuses[learn.activity.id] = learn.activityAttempts[0] || learn.activityAttempt;
      paintStudentActivityPanel();
      renderStudentActivityList();
      toast(result.idempotent ? 'Already submitted.' : 'Activity submitted.', 'success');
    } catch (err) {
      fail(err);
    } finally {
      learn.activityBusy = false;
      submitBtn.disabled = false;
      saveBtn.disabled = false;
    }
  }

  function draftStorageKey(exerciseId) {
    return `pmsTutorialDraft:${exerciseId}`;
  }

  function readStoredDraft(exerciseId) {
    try {
      return localStorage.getItem(draftStorageKey(exerciseId));
    } catch {
      return null;
    }
  }

  function writeStoredDraft(exerciseId, value) {
    try {
      localStorage.setItem(draftStorageKey(exerciseId), value);
    } catch { /* private mode */ }
  }

  function setDraftState(saved) {
    const label = document.getElementById('studentDraftState');
    if (!label) return;
    label.textContent = saved ? 'Saved' : 'Unsaved';
  }

  function rememberExerciseDraft(exerciseId, value, saved) {
    learn.drafts[exerciseId] = value;
    if (saved) {
      writeStoredDraft(exerciseId, value);
      learn.draftSaved[exerciseId] = value;
      setDraftState(true);
    } else {
      setDraftState(value === learn.draftSaved[exerciseId]);
    }
  }

  function scheduleExerciseDraft(exerciseId) {
    clearTimeout(learn.draftTimer);
    learn.draftTimer = setTimeout(() => {
      if (learn.drafts[exerciseId] != null) rememberExerciseDraft(exerciseId, learn.drafts[exerciseId], true);
    }, 400);
  }

  function currentExerciseId() {
    const title = document.getElementById('studentExerciseTitle');
    return title ? (title.dataset.exerciseId || '') : '';
  }

  async function openStudentExercise(id) {
    try {
      const exercise = await call(`/tutorials/exercises/${encodeURIComponent(id)}`);
      const panel = document.getElementById('studentExercisePanel');
      panel.classList.remove('d-none');
      const title = document.getElementById('studentExerciseTitle');
      title.textContent = exercise.title || '';
      title.dataset.exerciseId = exercise.id || '';
      title.dataset.language = exercise.language || '';
      title.dataset.boilerplate = exercise.boilerplate || '';
      const meta = document.getElementById('studentExerciseMeta');
      if (meta) meta.textContent = `${languageLabel(exercise.language)} · Lesson practice`;
      setLessonHtml(document.getElementById('studentExerciseInstructions'), exercise.instructions || '');
      const code = document.getElementById('studentCode');
      const examples = (exercise.testCases || []).filter((item) => item.sample !== false);
      document.getElementById('studentExamples').innerHTML = examples.length
        ? `<div class="fw-semibold mb-2">Sample output</div><p class="small text-muted-2">This is the tutor’s sample. It is not the output of your program.</p>${examples.map((item, index) => (
          `<div class="border rounded p-2 mb-2"><div class="small fw-semibold">Sample ${index + 1}</div><div class="small text-muted-2">Input</div><pre class="mb-2">${esc(item.stdin) || '-'}</pre><div class="small text-muted-2">Sample output</div><pre class="mb-0">${esc(item.expectedOutput)}</pre></div>`
        )).join('')}`
        : '<p class="small text-muted-2 mb-0">No public sample output.</p>';
      document.getElementById('studentRunOutput').textContent = 'Run your code to see the actual output.';
      document.getElementById('studentSubmitResults').textContent = 'Submit to grade this solution against the test cases.';
      let history = [];
      try {
        history = await call(`/tutorials/exercises/${encodeURIComponent(exercise.id)}/attempts`) || [];
      } catch { history = []; }
      learn.attemptHistory = history;
      const stored = readStoredDraft(exercise.id);
      if (!Object.prototype.hasOwnProperty.call(learn.drafts, exercise.id)) {
        learn.drafts[exercise.id] = stored != null ? stored : ((history[0] && history[0].sourceCode) || exercise.boilerplate || '');
      }
      learn.draftSaved[exercise.id] = learn.drafts[exercise.id];
      code.value = learn.drafts[exercise.id];
      setDraftState(true);
      code.oninput = () => {
        learn.drafts[exercise.id] = code.value;
        setDraftState(false);
        scheduleExerciseDraft(exercise.id);
      };
      const note = document.getElementById('studentAttemptNote');
      if (note) note.textContent = '';
      const historyRoot = document.getElementById('studentAttemptHistory');
      historyRoot.innerHTML = history.length ? history.map((item, index) => {
        const verdict = item.status === 'PASSED' ? 'Passed' : (item.status === 'FAILED' ? 'Failed' : 'Saved');
        const counts = item.testsTotal != null ? ` · ${item.testsPassed || 0}/${item.testsTotal} tests` : '';
        return `<div class="border rounded p-2 d-flex justify-content-between gap-2"><div><div class="fw-semibold">Submission #${history.length - index}</div><div class="small text-muted-2">${esc(item.submittedAt || '')} · ${esc(languageLabel(item.language))} · ${esc(verdict)}${esc(counts)}</div></div><button type="button" class="btn btn-sm btn-outline-secondary" data-open-attempt="${index}">Open</button></div>`;
      }).join('') : '<p class="text-muted-2 mb-0">No submissions yet.</p>';
      historyRoot.querySelectorAll('[data-open-attempt]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const item = learn.attemptHistory[Number(btn.getAttribute('data-open-attempt'))];
          if (!item) return;
          code.value = item.sourceCode || '';
          rememberExerciseDraft(exercise.id, code.value, true);
          toast('Opened in the editor. Your draft was updated.', 'success');
        });
      });
    } catch (err) {
      fail(err);
    }
  }

  function renderRunOutput(result) {
    const box = document.getElementById('studentRunOutput');
    if (!box) return;
    if (!result) {
      box.textContent = 'Run your code to see the actual output.';
      return;
    }
    const parts = [`Status: ${result.status || (result.ok ? 'OK' : 'Error')}`];
    if (result.durationMs != null) parts.push(`Time: ${result.durationMs} ms`);
    parts.push('', 'stdout:', result.stdout || '(empty)');
    if (result.stderr) parts.push('', 'stderr:', result.stderr);
    if (result.timedOut) parts.push('', 'Execution exceeded the time limit.');
    box.textContent = parts.join('\n');
  }

  function renderSubmitResults(result) {
    const box = document.getElementById('studentSubmitResults');
    if (!box || !result) return;
    const rows = (result.results || []).map((item) => {
      if (item.sample) {
        return `<div class="border rounded p-2 mb-2"><div class="fw-semibold">${item.passed ? 'Passed' : 'Failed'} · Public test ${item.index}</div><div class="text-muted-2">Actual stdout</div><pre class="mb-1">${esc(item.stdout || '')}</pre>${item.stderr ? `<div class="text-muted-2">stderr</div><pre class="mb-0">${esc(item.stderr)}</pre>` : ''}</div>`;
      }
      return `<div class="border rounded p-2 mb-2"><div class="fw-semibold">${item.passed ? 'Passed' : 'Failed'} · Hidden test ${item.index}</div><div class="text-muted-2">${esc(item.status || 'Hidden test')}</div></div>`;
    }).join('');
    box.innerHTML = `<div class="mb-2">${result.passed ? 'Passed' : 'Not passed'} · ${result.testsPassed || 0} passed, ${result.testsFailed || 0} failed, ${result.testsTotal || 0} total${result.durationMs != null ? ` · ${result.durationMs} ms` : ''}</div>${rows}`;
  }

  function paintProgress() {
    const progress = learn.progress || { status: 'NOT_STARTED', progressPercent: 0, completedModules: 0, totalModules: 0, completed: false, completedModuleIds: [] };
    const workspace = progress.workspace || {};
    const percent = workspace.percent != null ? workspace.percent : (progress.progressPercent || 0);
    const label = document.getElementById('studentProgressLabel');
    const bar = document.getElementById('studentProgressBar');
    const stats = document.getElementById('studentProgressStats');
    const completeBtn = document.getElementById('studentCompleteTutorial');
    if (label) label.textContent = `${percent}% complete`;
    if (bar) {
      bar.style.width = `${percent}%`;
      bar.parentElement.setAttribute('aria-valuenow', String(percent));
    }
    if (stats) {
      stats.innerHTML = [
        `<span><strong>${workspace.completedModules || 0}</strong> / ${workspace.totalModules || progress.totalModules || 0} modules</span>`,
        `<span><strong>${workspace.completedLessons || 0}</strong> / ${workspace.totalLessons || 0} lessons</span>`,
        `<span><strong>${workspace.completedExercises || 0}</strong> / ${workspace.totalExercises || 0} practice questions</span>`,
      ].join('');
    }
    if (completeBtn) {
      const ready = (progress.totalModules || 0) > 0 && (progress.completedModules || 0) === progress.totalModules && !progress.completed;
      completeBtn.classList.toggle('d-none', !ready && !progress.completed);
      completeBtn.disabled = !!progress.completed;
      completeBtn.textContent = progress.completed ? 'Tutorial complete' : 'Mark tutorial complete';
    }
    renderStudentModuleNav();
    updateLessonNavButtons();
  }

  function bindStudent() {
    document.getElementById('studentModuleContent').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-student-copy]');
      if (!button) return;
      const card = button.closest('.tutorial-code-card');
      const text = card && card.querySelector('pre') ? card.querySelector('pre').textContent : '';
      if (navigator.clipboard) {
        try { await navigator.clipboard.writeText(text || ''); toast('Copied.', 'success'); } catch { /* ignore */ }
      }
    });
    document.getElementById('studentSearch').addEventListener('input', (event) => {
      learn.query = event.target.value;
      clearTimeout(learn.searchTimer);
      learn.searchTimer = setTimeout(() => loadStudentList().catch(fail), 250);
    });
    document.getElementById('studentBackFromOverview').addEventListener('click', () => showStudentScreen('list'));
    document.getElementById('overviewContinue').addEventListener('click', () => {
      if (learn.detail) openStudentTutorial(learn.detail.id);
    });
    document.getElementById('studentBackToList').addEventListener('click', () => showStudentScreen('overview'));
    const nav = document.getElementById('studentWorkspace');
    const courseNav = document.getElementById('studentCourseNav');
    const backdrop = document.getElementById('studentNavBackdrop');
    const mobileNav = () => window.matchMedia('(max-width: 991.98px)').matches;
    const closeNav = () => {
      if (courseNav) courseNav.classList.remove('is-open');
      if (backdrop) backdrop.classList.remove('is-open');
    };
    try {
      if (sessionStorage.getItem('pmsTutorialNavCollapsed') === '1' && nav && !mobileNav()) nav.classList.add('is-nav-collapsed');
    } catch { /* ignore */ }
    const collapseBtn = document.getElementById('studentNavCollapse');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', () => {
        if (mobileNav()) {
          closeNav();
          return;
        }
        if (nav) nav.classList.add('is-nav-collapsed');
        try { sessionStorage.setItem('pmsTutorialNavCollapsed', '1'); } catch { /* ignore */ }
      });
    }
    const reopenBtn = document.getElementById('studentNavReopen');
    if (reopenBtn) {
      reopenBtn.addEventListener('click', () => {
        if (mobileNav()) {
          if (courseNav) courseNav.classList.add('is-open');
          if (backdrop) backdrop.classList.add('is-open');
          return;
        }
        if (nav) nav.classList.remove('is-nav-collapsed');
        try { sessionStorage.setItem('pmsTutorialNavCollapsed', '0'); } catch { /* ignore */ }
      });
    }
    if (backdrop) backdrop.addEventListener('click', closeNav);
    document.getElementById('studentPrevModule').addEventListener('click', () => {
      goStudentLessonDelta(-1).catch(fail);
    });
    document.getElementById('studentNextModule').addEventListener('click', () => {
      goStudentLessonDelta(1).catch(fail);
    });
    const exerciseClose = document.getElementById('studentExerciseCloseBtn');
    if (exerciseClose) {
      exerciseClose.addEventListener('click', () => {
        const panel = document.getElementById('studentExercisePanel');
        if (panel) panel.classList.add('d-none');
      });
    }
    document.getElementById('studentMarkLesson').addEventListener('click', async () => {
      const current = ((learn.detail && learn.detail.modules) || [])[learn.moduleIndex];
      const lesson = (learn.lessons || [])[learn.lessonIndex];
      if (!current || !lesson || !learn.detail) return;
      try {
        learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(current.id)}/lessons/${encodeURIComponent(lesson.id)}/complete`, { method: 'POST', body: {} });
        paintProgress();
        toast('Lesson marked complete.', 'success');
      } catch (err) {
        fail(err);
      }
    });
    document.getElementById('studentCompleteTutorial').addEventListener('click', async () => {
      if (!learn.detail) return;
      try {
        learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/complete`, { method: 'POST', body: {} });
        paintProgress();
        toast('Tutorial marked complete.', 'success');
      } catch (err) {
        fail(err);
      }
    });
    const runBtn = document.getElementById('studentRunBtn');
    if (runBtn) runBtn.addEventListener('click', async () => {
      const exerciseId = currentExerciseId();
      if (!exerciseId) {
        toast('Open an exercise before running code.', 'error');
        return;
      }
      const button = document.getElementById('studentRunBtn');
      button.disabled = true;
      try {
        const result = await call(`/tutorials/exercises/${encodeURIComponent(exerciseId)}/run`, {
          method: 'POST',
          body: {
            sourceCode: document.getElementById('studentCode').value,
            language: document.getElementById('studentExerciseTitle').dataset.language,
          },
        });
        renderRunOutput(result);
      } catch (err) {
        renderRunOutput({ ok: false, status: 'Error', stdout: '', stderr: err && err.message ? err.message : 'The code could not be run.', timedOut: false });
      } finally {
        button.disabled = false;
      }
    });
    const submitBtn = document.getElementById('studentSubmitBtn');
    if (submitBtn) submitBtn.addEventListener('click', async () => {
      const exerciseId = currentExerciseId();
      if (!exerciseId) {
        toast('Open an exercise before submitting.', 'error');
        return;
      }
      const button = document.getElementById('studentSubmitBtn');
      button.disabled = true;
      try {
        const result = await call(`/tutorials/exercises/${encodeURIComponent(exerciseId)}/submit`, {
          method: 'POST',
          body: {
            sourceCode: document.getElementById('studentCode').value,
            language: document.getElementById('studentExerciseTitle').dataset.language,
          },
        });
        renderSubmitResults(result);
        if (learn.detail) {
          learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/progress`);
          paintProgress();
        }
        await openStudentExercise(exerciseId);
        toast(result && result.passed ? 'Submission passed.' : 'Submission did not pass every test.', result && result.passed ? 'success' : 'error');
      } catch (err) {
        const box = document.getElementById('studentSubmitResults');
        if (box) box.textContent = err && err.message ? err.message : 'The submission could not be graded.';
      } finally {
        button.disabled = false;
      }
    });
    const resetBtn = document.getElementById('studentResetCodeBtn');
    if (resetBtn) resetBtn.addEventListener('click', async () => {
      const exerciseId = currentExerciseId();
      if (!exerciseId) return;
      const ok = await confirmAction({
        title: 'Reset code?',
        message: 'Replace your draft with the starter code?',
        confirmText: 'Reset',
        variant: 'danger',
      });
      if (!ok) return;
      const starter = document.getElementById('studentExerciseTitle').dataset.boilerplate || '';
      document.getElementById('studentCode').value = starter;
      rememberExerciseDraft(exerciseId, starter, true);
    });
    const fullBtn = document.getElementById('studentEditorFullBtn');
    if (fullBtn) fullBtn.addEventListener('click', () => {
      const panel = document.getElementById('studentExercisePanel');
      panel.classList.toggle('is-fullscreen');
      document.getElementById('studentEditorFullBtn').textContent = panel.classList.contains('is-fullscreen') ? 'Exit full screen' : 'Full screen';
    });
    document.getElementById('studentSaveAttemptBtn') && document.getElementById('studentSaveAttemptBtn').addEventListener('click', async () => {
      const title = document.getElementById('studentExerciseTitle').dataset.exerciseId;
      if (!title) {
        toast('Open an exercise before saving an attempt.', 'error');
        return;
      }
      try {
        const saved = await call(`/tutorials/exercises/${encodeURIComponent(title)}/attempt`, {
          method: 'POST',
          body: {
            sourceCode: document.getElementById('studentCode').value,
            language: document.getElementById('studentExerciseTitle').dataset.language,
          },
        });
        toast(saved && saved.message ? saved.message : 'Your attempt has been saved.', 'success');
        await openStudentExercise(title);
      } catch (err) {
        fail(err);
      }
    });
    document.getElementById('studentActivityCloseBtn').addEventListener('click', async () => {
      if (learn.activityDirty) {
        const ok = await confirmAction({
          title: 'Close activity?',
          message: 'You have unsaved changes. Close without saving?',
          confirmText: 'Close',
          variant: 'danger',
        });
        if (!ok) return;
      }
      learn.activityDirty = false;
      learn.activity = null;
      learn.activityAttempt = null;
      learn.activityViewingSubmitted = false;
      document.getElementById('studentActivityPanel').classList.add('d-none');
    });
    document.getElementById('studentActivityStartBtn').addEventListener('click', () => {
      startStudentActivityAttempt().catch(fail);
    });
    document.getElementById('studentActivitySaveBtn').addEventListener('click', () => {
      saveStudentActivityResponse().catch(fail);
    });
    document.getElementById('studentActivitySubmitBtn').addEventListener('click', () => {
      submitStudentActivityResponse().catch(fail);
    });
    window.addEventListener('beforeunload', (event) => {
      if (!learn.activityDirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
  }

  async function boot() {
    if (typeof renderShell === 'function') renderShell('tutorials.html');
    const staff = document.getElementById('staffTutorialPage');
    const student = document.getElementById('studentTutorialPage');
    if (!isAuthor()) {
      student.classList.remove('d-none');
      staff.classList.add('d-none');
      bindStudent();
      await loadStudentList();
      return;
    }
    staff.classList.remove('d-none');
    student.classList.add('d-none');
    bind();
    try {
      await Promise.all([loadCategories(), loadDepartments()]);
      await refreshList();
    } catch (err) {
      fail(err);
    }
  }

  onAppReady(boot);
})();
