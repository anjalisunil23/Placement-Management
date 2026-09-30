(function () {
  const LANGUAGES = [
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'java', label: 'Java' },
    { value: 'python', label: 'Python' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'php', label: 'PHP' },
  ];
  const state = {
    categories: [],
    departments: [],
    tutorials: [],
    active: null,
    years: [],
    quill: null,
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
    if (!state.tutorials.length) {
      body.innerHTML = '<tr><td colspan="9" class="text-muted-2 p-4">No courses yet.</td></tr>';
      return;
    }
    body.innerHTML = state.tutorials.map((row) => {
      const status = row.status || 'draft';
      const publish = status === 'published'
        ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-unpublish="${esc(row.id)}">Unpublish</button>`
        : `<button type="button" class="btn btn-sm btn-outline-primary" data-publish="${esc(row.id)}">Publish</button>`;
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
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-tutorial="${esc(row.id)}">Edit</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-modules="${esc(row.id)}">Manage Modules</button>
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
    } catch (err) {
      fail(err);
    }
  }

  async function publishTutorial(id, publish) {
    try {
      await call(`/tutorials/manage/${encodeURIComponent(id)}/${publish ? 'publish' : 'unpublish'}`, { method: 'POST', body: {} });
      toast(publish ? 'Tutorial published.' : 'Tutorial unpublished.', 'success');
      await refreshList();
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

  function showModules(show) {
    document.getElementById('tutorialListView').classList.toggle('d-none', show);
    document.getElementById('moduleView').classList.toggle('d-none', !show);
    document.getElementById('previewView').classList.add('d-none');
  }

  async function openPreview(id) {
    try {
      const course = await call(`/tutorials/manage/${encodeURIComponent(id)}`);
      state.preview = course;
      document.getElementById('tutorialListView').classList.add('d-none');
      document.getElementById('moduleView').classList.add('d-none');
      document.getElementById('previewView').classList.remove('d-none');
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

  async function openModules(id) {
    try {
      state.active = await call(`/tutorials/manage/${encodeURIComponent(id)}`);
      document.getElementById('moduleTutorialTitle').textContent = state.active.title || 'Modules';
      renderModules();
      showModules(true);
    } catch (err) {
      fail(err);
    }
  }

  function renderModules() {
    const root = document.getElementById('moduleList');
    const modules = (state.active && state.active.modules) || [];
    if (!modules.length) {
      root.innerHTML = '<div class="card-surface p-4 text-muted-2">No modules yet.</div>';
      return;
    }
    root.innerHTML = modules.map((module, index) => {
      const exercises = module.exercises || [];
      const exerciseHtml = exercises.map((exercise) => {
        const cases = exercise.testCases || [];
        const badges = cases.map((item) => (
          `<span class="badge ${item.sample ? 'text-bg-primary' : 'text-bg-dark'}">${item.sample ? 'PUBLIC' : 'HIDDEN'}</span>`
        )).join(' ');
        return `<div class="d-flex flex-wrap justify-content-between gap-2 border-top pt-2 mt-2">
          <div><div class="fw-semibold">${esc(exercise.title)}</div><div class="small text-muted-2">${esc(languageLabel(exercise.language))} ${badges}</div></div>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-exercise="${esc(module.id)}" data-exercise="${esc(exercise.id)}">Edit</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-exercise="${esc(module.id)}" data-exercise="${esc(exercise.id)}">Delete</button>
          </div>
        </div>`;
      }).join('');
      return `<div class="card-surface p-3">
        <div class="d-flex flex-wrap justify-content-between gap-2">
          <div><div class="fw-semibold">${esc(index + 1)}. ${esc(module.title)}</div></div>
          <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-up="${esc(module.id)}" ${index === 0 ? 'disabled' : ''}>Up</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-down="${esc(module.id)}" ${index === modules.length - 1 ? 'disabled' : ''}>Down</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-module="${esc(module.id)}">Edit</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-module="${esc(module.id)}">Delete</button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-add-exercise="${esc(module.id)}">Add Exercise</button>
          </div>
        </div>
        ${exerciseHtml || '<p class="small text-muted-2 mb-0 mt-2">No exercises yet.</p>'}
      </div>`;
    }).join('');
    root.querySelectorAll('[data-edit-module]').forEach((btn) => btn.addEventListener('click', () => openModule(btn.getAttribute('data-edit-module'))));
    root.querySelectorAll('[data-delete-module]').forEach((btn) => btn.addEventListener('click', () => deleteModule(btn.getAttribute('data-delete-module'))));
    root.querySelectorAll('[data-up]').forEach((btn) => btn.addEventListener('click', () => moveModule(btn.getAttribute('data-up'), -1)));
    root.querySelectorAll('[data-down]').forEach((btn) => btn.addEventListener('click', () => moveModule(btn.getAttribute('data-down'), 1)));
    root.querySelectorAll('[data-add-exercise]').forEach((btn) => btn.addEventListener('click', () => openExercise(btn.getAttribute('data-add-exercise'), '')));
    root.querySelectorAll('[data-edit-exercise]').forEach((btn) => btn.addEventListener('click', () => openExercise(btn.getAttribute('data-edit-exercise'), btn.getAttribute('data-exercise'))));
    root.querySelectorAll('[data-delete-exercise]').forEach((btn) => btn.addEventListener('click', () => deleteExercise(btn.getAttribute('data-edit-exercise'), btn.getAttribute('data-exercise'))));
  }

  function languageLabel(value) {
    const hit = LANGUAGES.find((row) => row.value === value);
    return hit ? hit.label : (value || '');
  }

  async function ensureQuill() {
    if (window.Quill) return;
    if (!document.getElementById('tutorial-quill-css')) {
      const link = document.createElement('link');
      link.id = 'tutorial-quill-css';
      link.rel = 'stylesheet';
      link.href = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css';
      document.head.appendChild(link);
    }
    await new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';
      script.onload = resolve;
      script.onerror = () => reject(new Error('The lesson editor could not be loaded.'));
      document.head.appendChild(script);
    });
  }

  async function openModule(id) {
    try {
      await ensureQuill();
      const host = document.getElementById('moduleEditor');
      host.innerHTML = '';
      const existing = id && state.active ? (state.active.modules || []).find((row) => row.id === id) : null;
      document.getElementById('moduleModalTitle').textContent = existing ? 'Edit Module' : 'Add Module';
      document.getElementById('moduleId').value = existing ? existing.id : '';
      document.getElementById('moduleTitle').value = existing ? existing.title : '';
      state.quill = new Quill(host, {
        theme: 'snow',
        modules: {
          toolbar: {
            container: [
              [{ header: [2, 3, false] }],
              ['bold', 'italic'],
              [{ list: 'ordered' }, { list: 'bullet' }],
              ['link', 'image', 'code-block'],
              ['clean'],
            ],
            handlers: {
              image() {
                const url = window.prompt('Image address (https://...)');
                if (!url || !/^https?:\/\//i.test(url)) return;
                const range = state.quill.getSelection(true);
                state.quill.insertEmbed(range ? range.index : 0, 'image', url, 'user');
              },
            },
          },
        },
      });
      const html = existing ? (existing.content || '') : '';
      if (html) state.quill.clipboard.dangerouslyPasteHTML(html);
      modal('moduleModal').show();
    } catch (err) {
      fail(err);
    }
  }

  async function saveModule(event) {
    event.preventDefault();
    if (!state.active) return;
    const id = document.getElementById('moduleId').value;
    const html = state.quill ? state.quill.root.innerHTML : '';
    const body = {
      title: document.getElementById('moduleTitle').value.trim(),
      content: html === '<p><br></p>' ? '' : html,
    };
    const tutorialId = state.active.id;
    try {
      if (id) await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules/${encodeURIComponent(id)}`, { method: 'PUT', body });
      else await call(`/tutorials/manage/${encodeURIComponent(tutorialId)}/modules`, { method: 'POST', body });
      modal('moduleModal').hide();
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
    list.innerHTML = cases.map((item) => (
      `<div class="border rounded p-2">
        <div class="d-flex justify-content-between gap-2 mb-1">
          <span class="badge ${item.sample ? 'text-bg-primary' : 'text-bg-dark'}">${item.sample ? 'PUBLIC' : 'HIDDEN'}</span>
          <div class="d-flex gap-1">
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
    modal('exerciseModal').show();
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
      await openModules(state.active.id);
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
      await openModules(state.active.id);
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
      await openModules(state.active.id);
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
    document.getElementById('addModuleBtn').addEventListener('click', () => openModule(''));
    document.getElementById('addTestCaseBtn').addEventListener('click', addTestCase);
    document.getElementById('backFromPreview').addEventListener('click', () => {
      document.getElementById('previewView').classList.add('d-none');
      document.getElementById('tutorialListView').classList.remove('d-none');
    });
    document.getElementById('backToTutorials').addEventListener('click', async () => {
      showModules(false);
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
    categoryId: '',
    query: '',
    detail: null,
    moduleIndex: 0,
    module: null,
    drafts: {},
    progressById: {},
    continueIds: {},
    progress: null,
  };

  function setLessonHtml(el, html) {
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
    doc.querySelectorAll('script,iframe,object,embed,link,meta').forEach((node) => node.remove());
    doc.body.querySelectorAll('*').forEach((node) => {
      [...node.attributes].forEach((attr) => {
        if (attr.name.toLowerCase().startsWith('on') || /javascript:/i.test(attr.value)) node.removeAttribute(attr.name);
      });
    });
    el.replaceChildren(...doc.body.childNodes);
  }

  function filteredTutorials() {
    const q = learn.query.trim().toLowerCase();
    return learn.tutorials.filter((row) => {
      if (learn.categoryId && (row.category && row.category.id) !== learn.categoryId) return false;
      if (!q) return true;
      const hay = `${row.title || ''} ${row.topic || ''} ${row.description || ''} ${row.category ? row.category.name : ''}`.toLowerCase();
      return hay.includes(q);
    });
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
        renderStudentCards();
      });
    });
  }

  function renderStudentCards() {
    const root = document.getElementById('studentTutorialCards');
    const rows = filteredTutorials();
    if (!learn.tutorials.length) {
      root.innerHTML = '<div class="col-12"><div class="card-surface p-4 text-muted-2">No tutorials are available for you yet.</div></div>';
      return;
    }
    if (!rows.length) {
      root.innerHTML = '<div class="col-12"><div class="card-surface p-4 text-muted-2">No tutorials match this search.</div></div>';
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
        <div class="small mb-3">${esc(learn.progressById[row.id] ? `${learn.progressById[row.id].progressPercent || 0}% complete` : 'Not started')}</div>
        <button type="button" class="btn btn-primary" data-start-tutorial="${esc(row.id)}">${esc(learn.continueIds[row.id] ? 'Continue Learning' : 'Start Learning')}</button>
      </div></div>`;
    }).join('');
    root.querySelectorAll('[data-start-tutorial]').forEach((btn) => {
      btn.addEventListener('click', () => openOverview(btn.getAttribute('data-start-tutorial')));
    });
  }

  async function loadStudentList() {
    const root = document.getElementById('studentTutorialCards');
    root.innerHTML = '<div class="col-12"><div class="card-surface p-4 text-muted-2">Loading tutorials…</div></div>';
    try {
      const [tutorials, categories] = await Promise.all([
        call('/tutorials'),
        call('/tutorial-categories'),
      ]);
      learn.tutorials = tutorials || [];
      learn.categories = categories || [];
      learn.continueIds = {};
      learn.progressById = {};
      await Promise.all(learn.tutorials.map(async (row) => {
        try {
          const progress = await call(`/tutorials/${encodeURIComponent(row.id)}/progress`);
          learn.progressById[row.id] = progress;
          if (progress && progress.lastVisitedModuleId && progress.status !== 'NOT_STARTED') learn.continueIds[row.id] = true;
        } catch { /* listing still works if one progress read fails */ }
      }));
      renderStudentFilters();
      renderStudentCards();
    } catch (err) {
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
        : `Progress: ${progress.progressPercent || 0}% · ${progress.completedModules || 0} / ${progress.totalModules || 0} modules`;
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
    document.getElementById('studentModuleNav').innerHTML = modules.map((module, index) => (
      `<button type="button" class="btn btn-sm text-start ${index === learn.moduleIndex ? 'btn-primary' : 'btn-outline-secondary'}" data-student-module="${index}">${doneIds.has(module.id) ? '<i class="bi bi-check-lg me-1"></i>' : ''}${esc(module.title)}</button>`
    )).join('') || '<p class="text-muted-2 mb-0">This tutorial has no modules yet.</p>';
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
    learn.module = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/modules/${encodeURIComponent(summary.id)}`);
    try {
      learn.progress = await call(`/tutorials/${encodeURIComponent(learn.detail.id)}/progress`);
      paintProgress();
    } catch { /* module content still shows */ }
    document.getElementById('studentModulePosition').textContent = `Module ${learn.moduleIndex + 1} of ${modules.length}`;
    document.getElementById('studentModuleHeading').textContent = learn.module.title || '';
    if (learn.module.content) setLessonHtml(document.getElementById('studentModuleContent'), learn.module.content);
    else document.getElementById('studentModuleContent').textContent = 'This module does not have lesson content yet.';
    const exercises = learn.module.exercises || [];
    document.getElementById('studentExerciseList').innerHTML = exercises.length
      ? exercises.map((row) => `<button type="button" class="btn btn-outline-primary text-start" data-student-exercise="${esc(row.id)}">${esc(row.title)}</button>`).join('')
      : '<p class="text-muted-2 mb-0">This module has no exercises.</p>';
    document.querySelectorAll('[data-student-exercise]').forEach((btn) => {
      btn.addEventListener('click', () => openStudentExercise(btn.getAttribute('data-student-exercise')));
    });
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
      if (learn.drafts[exercise.id] == null) {
        let source = exercise.boilerplate || '';
        try {
          const history = await call(`/tutorials/exercises/${encodeURIComponent(exercise.id)}/attempts`);
          if (history && history[0] && history[0].sourceCode) source = history[0].sourceCode;
        const note = document.getElementById('studentAttemptNote');
        if (note) note.textContent = history && history.length ? '' : 'No saved attempts yet.';
        } catch { /* keep the starter code */ }
        learn.drafts[exercise.id] = source;
      }
      code.value = learn.drafts[exercise.id];
      code.oninput = () => { learn.drafts[exercise.id] = code.value; };
      const examples = exercise.testCases || [];
      document.getElementById('studentExamples').innerHTML = examples.length
        ? `<div class="fw-semibold mb-2">Examples</div>${examples.map((item, index) => (
          `<div class="border rounded p-2 mb-2"><div class="small fw-semibold">Example ${index + 1}</div><div class="small text-muted-2">Input</div><pre class="mb-2">${esc(item.stdin)}</pre><div class="small text-muted-2">Expected output</div><pre class="mb-0">${esc(item.expectedOutput)}</pre></div>`
        )).join('')}`
        : '';
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
    document.getElementById('studentSearch').addEventListener('input', (event) => {
      learn.query = event.target.value;
      renderStudentCards();
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
        toast(saved && saved.status === 'ATTEMPTED' ? 'Attempt saved. It was not graded.' : 'Attempt saved.', 'success');
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
