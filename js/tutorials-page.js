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
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preview="${esc(row.id)}">Preview</button>
            ${publish}
            ${remove}
          </div>
        </td>
      </tr>`;
    }).join('');
    body.querySelectorAll('[data-edit-tutorial]').forEach((btn) => btn.addEventListener('click', () => openTutorial(btn.getAttribute('data-edit-tutorial'))));
    body.querySelectorAll('[data-modules]').forEach((btn) => btn.addEventListener('click', () => openModules(btn.getAttribute('data-modules'))));
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
    document.getElementById('moduleView').classList.toggle('d-none', name !== 'builder');
    document.getElementById('articleView').classList.toggle('d-none', name !== 'article');
    document.getElementById('previewView').classList.toggle('d-none', name !== 'preview');
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
    const exercises = module.exercises || [];
    document.getElementById('previewExercises').innerHTML = exercises.length ? exercises.map((exercise) => {
      const samples = (exercise.testCases || []).filter((item) => item.sample);
      return `<div class="border rounded p-3 mb-2"><div class="fw-semibold">Challenge: ${esc(exercise.title)}</div><pre class="mt-2 mb-2">${esc(exercise.boilerplate || '')}</pre>${samples.map((item) => `<div class="small"><div>Input</div><pre>${esc(item.stdin)}</pre><div>Expected output</div><pre>${esc(item.expectedOutput)}</pre></div>`).join('') || '<p class="small text-muted-2 mb-0">No public sample tests.</p>'}</div>`;
    }).join('') : '<p class="text-muted-2 mb-0">This module has no exercises.</p>';
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
      const exercises = module.exercises || [];
      const active = !state.creatingModule && state.selectedModuleId === module.id;
      const excerpt = plainExcerpt(module.content) || 'No lesson text yet';
      return `<div class="border rounded p-2 ${active ? 'border-primary' : ''}">
        <button type="button" class="btn btn-sm p-0 fw-semibold" data-select-module="${esc(module.id)}">${active ? '●' : '○'} ${esc(index + 1)}. ${esc(module.title)}</button>
        <div class="small text-muted-2">${esc(excerpt)}</div>
        <div class="small mb-2">${esc(exercises.length)} exercise${exercises.length === 1 ? '' : 's'}</div>
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
    const list = document.getElementById('moduleExerciseList');
    const module = (state.active && state.active.modules || []).find((row) => row.id === state.selectedModuleId);
    if (!module || state.creatingModule) {
      pane.classList.add('d-none');
      return;
    }
    pane.classList.remove('d-none');
    const exercises = module.exercises || [];
    list.innerHTML = exercises.length ? exercises.map((exercise, exerciseIndex) => {
      const cases = exercise.testCases || [];
      const sampleCount = cases.filter((item) => item.sample).length;
      return `<div class="border rounded p-2">
        <div class="d-flex flex-wrap justify-content-between gap-2">
          <div><div class="fw-semibold">${exerciseIndex + 1}. ${esc(exercise.title)}</div><div class="small text-muted-2">${esc(languageLabel(exercise.language))} · ${sampleCount} public sample${sampleCount === 1 ? '' : 's'}</div></div>
          <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-exercise-up="${esc(module.id)}" data-exercise="${esc(exercise.id)}" ${exerciseIndex === 0 ? 'disabled' : ''}>Up</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-exercise-down="${esc(module.id)}" data-exercise="${esc(exercise.id)}" ${exerciseIndex === exercises.length - 1 ? 'disabled' : ''}>Down</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-preview-exercise="${esc(module.id)}" data-exercise="${esc(exercise.id)}">Preview</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-exercise="${esc(module.id)}" data-exercise="${esc(exercise.id)}">Edit</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-exercise="${esc(module.id)}" data-exercise="${esc(exercise.id)}">Delete</button>
          </div>
        </div>
        <div class="border rounded p-2 mt-2 d-none" data-exercise-preview="${esc(exercise.id)}"></div>
      </div>`;
    }).join('') : '<p class="small text-muted-2 mb-0">No exercises yet.</p>';
    list.querySelectorAll('[data-edit-exercise]').forEach((btn) => btn.addEventListener('click', () => openExercise(btn.getAttribute('data-edit-exercise'), btn.getAttribute('data-exercise'))));
    list.querySelectorAll('[data-delete-exercise]').forEach((btn) => btn.addEventListener('click', () => deleteExercise(btn.getAttribute('data-delete-exercise'), btn.getAttribute('data-exercise'))));
    list.querySelectorAll('[data-exercise-up]').forEach((btn) => btn.addEventListener('click', () => moveExercise(btn.getAttribute('data-exercise-up'), btn.getAttribute('data-exercise'), -1)));
    list.querySelectorAll('[data-exercise-down]').forEach((btn) => btn.addEventListener('click', () => moveExercise(btn.getAttribute('data-exercise-down'), btn.getAttribute('data-exercise'), 1)));
    list.querySelectorAll('[data-preview-exercise]').forEach((btn) => btn.addEventListener('click', () => previewExercise(btn.getAttribute('data-preview-exercise'), btn.getAttribute('data-exercise'))));
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

  async function loadModuleActivities() {
    const pane = document.getElementById('moduleActivityPane');
    if (!state.active || !state.selectedModuleId) {
      pane.classList.add('d-none');
      return;
    }
    pane.classList.remove('d-none');
    document.getElementById('activityForm').classList.add('d-none');
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
      } else {
        await call(`/tutorials/manage/${encodeURIComponent(state.active.id)}/modules/${encodeURIComponent(state.selectedModuleId)}/activities`, {
          method: 'POST',
          body,
        });
      }
      toast(publish ? 'Activity published.' : 'Activity saved as draft.', 'success');
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

  function bind() {
    document.getElementById('createTutorialBtn').addEventListener('click', () => { blankTutorialForm(); modal('tutorialModal').show(); });
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
    document.getElementById('activityAddBtn').addEventListener('click', () => {
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
    drafts: {},
    progressById: {},
    continueIds: {},
    progress: null,
    assessment: null,
    assessmentAttemptId: null,
    assessmentAnswers: {},
    assessmentQuestionIndex: 0,
    assessmentResult: null,
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
        <div class="small mb-2">${esc(count)} modules · ${esc(row.exerciseCount || 0)} exercises</div>
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
      document.getElementById('overviewExercises').textContent = `${detail.exerciseCount || 0} exercises`;
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

  function renderStudentModuleNav() {
    const modules = (learn.detail && learn.detail.modules) || [];
    const doneIds = new Set((learn.progress && learn.progress.completedModuleIds) || []);
    const select = document.getElementById('studentModuleSelect');
    if (select) {
      select.innerHTML = modules.map((module, index) => `<option value="${index}">${esc(module.title)}</option>`).join('');
      select.value = String(learn.moduleIndex);
    }
    document.getElementById('studentModuleNav').innerHTML = modules.map((module, index) => {
      const mark = doneIds.has(module.id) ? '✓' : (index === learn.moduleIndex ? '●' : '○');
      return `<button type="button" class="btn btn-sm text-start ${index === learn.moduleIndex ? 'btn-primary' : 'btn-outline-secondary'}" data-student-module="${index}"><span class="me-1" aria-hidden="true">${mark}</span>${esc(module.title)}</button>`;
    }).join('') || '<p class="text-muted-2 mb-0">This tutorial has no modules yet.</p>';
    document.querySelectorAll('[data-student-module]').forEach((btn) => {
      btn.addEventListener('click', () => {
        learn.moduleIndex = Number(btn.getAttribute('data-student-module'));
        showStudentModule().catch(fail);
      });
    });
  }

  async function showStudentModule() {
    const modules = (learn.detail && learn.detail.modules) || [];
    renderStudentModuleNav();
    document.getElementById('studentExercisePanel').classList.add('d-none');
    document.getElementById('studentAssessmentSection').classList.add('d-none');
    document.getElementById('studentAssessmentAttempt').classList.add('d-none');
    document.getElementById('studentAssessmentResults').classList.add('d-none');
    document.getElementById('studentPrevModule').disabled = learn.moduleIndex <= 0;
    document.getElementById('studentNextModule').disabled = learn.moduleIndex >= modules.length - 1;
    if (!modules.length) {
      document.getElementById('studentModulePosition').textContent = '';
      document.getElementById('studentModuleHeading').textContent = 'No modules yet';
      document.getElementById('studentModuleContent').textContent = 'This tutorial does not have any lessons yet.';
      document.getElementById('studentExerciseList').innerHTML = '<p class="text-muted-2 mb-0">There are no exercises until a module is added.</p>';
      document.getElementById('studentMarkModule').disabled = true;
      return;
    }
    const summary = modules[learn.moduleIndex];
    document.getElementById('studentModuleHeading').textContent = 'Loading…';
    document.getElementById('studentModuleContent').textContent = 'Loading lesson…';
    document.getElementById('studentExerciseList').innerHTML = '<p class="text-muted-2 mb-0">Loading exercises…</p>';
    learn.module = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(summary.id)}`);
    try {
      learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/progress`);
      paintProgress();
    } catch { /* module content still shows */ }
    document.getElementById('studentModulePosition').textContent = `Module ${learn.moduleIndex + 1} of ${modules.length}`;
    document.getElementById('studentModuleHeading').textContent = learn.module.title || '';
    const subtitle = document.getElementById('studentModuleSubtitle');
    if (subtitle) subtitle.textContent = learn.module.subtitle || '';
    if (learn.module.content) setLessonHtml(document.getElementById('studentModuleContent'), learn.module.content);
    else document.getElementById('studentModuleContent').textContent = 'This module does not have lesson content yet.';
    const exercises = learn.module.exercises || [];
    document.getElementById('studentExerciseList').innerHTML = exercises.length
      ? exercises.map((row) => `<button type="button" class="btn btn-outline-primary text-start" data-student-exercise="${esc(row.id)}">${esc(row.title)}</button>`).join('')
      : '<p class="text-muted-2 mb-0">This module has no exercises.</p>';
    document.querySelectorAll('[data-student-exercise]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentExercise(btn.getAttribute('data-student-exercise')));
    });
    await loadStudentAssessment();
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

  async function openStudentExercise(id) {
    try {
      const exercise = await call(`/tutorials/exercises/${encodeURIComponent(id)}`);
      document.getElementById('studentExercisePanel').classList.remove('d-none');
      document.getElementById('studentExerciseTitle').textContent = exercise.title || '';
      document.getElementById('studentExerciseTitle').dataset.exerciseId = exercise.id || '';
      document.getElementById('studentExerciseTitle').dataset.language = exercise.language || '';
      setLessonHtml(document.getElementById('studentExerciseInstructions'), exercise.instructions || '');
      const code = document.getElementById('studentCode');
      const examples = (exercise.testCases || []).filter((item) => item.sample !== false);
      document.getElementById('studentExamples').innerHTML = examples.length
        ? `<div class="fw-semibold mb-2">Sample test cases</div>${examples.map((item, index) => (
          `<div class="border rounded p-2 mb-2"><div class="small fw-semibold">Test case ${index + 1}</div><div class="small text-muted-2">Input</div><pre class="mb-2">${esc(item.stdin) || '-'}</pre><div class="small text-muted-2">Expected output</div><pre class="mb-0">${esc(item.expectedOutput)}</pre></div>`
        )).join('')}`
        : '<p class="small text-muted-2 mb-0">No public sample tests.</p>';
      let history = [];
      try {
        history = await call(`/tutorials/exercises/${encodeURIComponent(exercise.id)}/attempts`) || [];
      } catch { history = []; }
      learn.attemptHistory = history;
      if (learn.drafts[exercise.id] == null) {
        learn.drafts[exercise.id] = history[0] && history[0].sourceCode ? history[0].sourceCode : (exercise.boilerplate || '');
      }
      code.value = learn.drafts[exercise.id];
      code.oninput = () => { learn.drafts[exercise.id] = code.value; };
      const note = document.getElementById('studentAttemptNote');
      if (note) note.textContent = history.length ? '' : 'No saved attempts yet.';
      const historyRoot = document.getElementById('studentAttemptHistory');
      historyRoot.innerHTML = history.length ? history.map((item, index) => (
        `<div class="border rounded p-2 d-flex justify-content-between gap-2"><div><div class="fw-semibold">Attempt #${history.length - index}</div><div class="small text-muted-2">${esc(item.submittedAt || '')} · ${esc(languageLabel(item.language))} · Attempted</div></div><button type="button" class="btn btn-sm btn-outline-secondary" data-open-attempt="${index}">Open</button></div>`
      )).join('') : '<p class="text-muted-2 mb-0">No saved attempts yet.</p>';
      historyRoot.querySelectorAll('[data-open-attempt]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const item = learn.attemptHistory[Number(btn.getAttribute('data-open-attempt'))];
          if (!item) return;
          code.value = item.sourceCode || '';
          learn.drafts[exercise.id] = code.value;
          toast('Opened in the editor. Save Attempt stores a new copy.', 'success');
        });
      });
    } catch (err) {
      fail(err);
    }
  }

  function paintProgress() {
    const progress = learn.progress || { status: 'NOT_STARTED', progressPercent: 0, completedModules: 0, totalModules: 0, completed: false, completedModuleIds: [] };
    const label = document.getElementById('studentProgressLabel');
    const bar = document.getElementById('studentProgressBar');
    const completeBtn = document.getElementById('studentCompleteTutorial');
    const markBtn = document.getElementById('studentMarkModule');
      if (label) {
        const resume = progress.lastVisitedModuleId ? ' Continuing from your last module.' : '';
        label.textContent = progress.completed
          ? `Tutorial complete. ${progress.completedModules || 0} of ${progress.totalModules || 0} modules.`
          : `${progress.completedModules || 0} of ${progress.totalModules || 0} modules complete · ${progress.progressPercent || 0}%.${resume}`;
      }
    if (bar) bar.style.width = `${progress.progressPercent || 0}%`;
    if (completeBtn) {
      const ready = (progress.totalModules || 0) > 0 && (progress.completedModules || 0) === progress.totalModules && !progress.completed;
      completeBtn.classList.toggle('d-none', !ready && !progress.completed);
      completeBtn.disabled = !!progress.completed;
      completeBtn.textContent = progress.completed ? 'Tutorial complete' : 'Mark tutorial complete';
    }
    const current = ((learn.detail && learn.detail.modules) || [])[learn.moduleIndex];
    if (markBtn && current) {
      const done = (progress.completedModuleIds || []).includes(current.id);
      markBtn.disabled = done;
      markBtn.textContent = done ? 'Module complete' : 'Mark module complete';
    }
    renderStudentModuleNav();
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
    const moduleSelect = document.getElementById('studentModuleSelect');
    if (moduleSelect) {
      moduleSelect.addEventListener('change', () => {
        learn.moduleIndex = Number(moduleSelect.value);
        showStudentModule().catch(fail);
      });
    }
    document.getElementById('studentPrevModule').addEventListener('click', () => {
      if (learn.moduleIndex <= 0) return;
      learn.moduleIndex -= 1;
      showStudentModule().catch(fail);
    });
    document.getElementById('studentNextModule').addEventListener('click', () => {
      const total = ((learn.detail && learn.detail.modules) || []).length;
      if (learn.moduleIndex >= total - 1) return;
      learn.moduleIndex += 1;
      showStudentModule().catch(fail);
    });
    document.getElementById('studentMarkModule').addEventListener('click', async () => {
      const current = ((learn.detail && learn.detail.modules) || [])[learn.moduleIndex];
      if (!current || !learn.detail) return;
      try {
        learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(current.id)}/progress`, { method: 'POST', body: {} });
        paintProgress();
        toast('Module marked complete.', 'success');
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
    document.getElementById('studentSaveAttemptBtn').addEventListener('click', async () => {
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
