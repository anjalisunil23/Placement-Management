(function () {
  if (!document.getElementById('internalPosts')) return;
const STEPS = ['Job type', 'Details', 'Department', 'Preview'];
let access = { kind: '', canManage: false, departmentLocked: false, departmentId: '', departmentCode: '', departmentName: '', departments: [] };
let chromeKey = '';
let posts = [];
let editingId = '';
let editingStatus = 'draft';
let step = 1;
let jobType = '';

const $ = id => document.getElementById(id);

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function deptLabel(code, name) {
  const c = String(code || '').trim();
  const n = String(name || '').trim();
  if (c && n && c.toLowerCase() !== n.toLowerCase()) return `${c} â€” ${n}`;
  return n || c || 'â€”';
}

function formatMoney(amount, per) {
  const raw = String(amount || '').trim();
  if (!raw) return '';
  const numeric = raw.replace(/[â‚¹,\s]/g, '');
  if (/^\d+(\.\d+)?$/.test(numeric)) {
    const n = Number(numeric);
    const formatted = 'â‚¹' + n.toLocaleString('en-IN', { maximumFractionDigits: n % 1 ? 2 : 0 });
    return per ? `${formatted}/${per}` : formatted;
  }
  if (per && !/\/\s*(month|week|one-?time)/i.test(raw)) return `${raw}/${per}`;
  return raw;
}

function compensationLabel(d) {
  if (d.jobType === 'part_time') {
    const money = formatMoney(d.stipend, 'month');
    return money ? `Stipend: ${money}` : '';
  }
  if (d.compensationType === 'unpaid') return 'Free / Unpaid';
  if (d.compensationType === 'fee') {
    const money = formatMoney(d.compensationAmount, '');
    return money ? `Fee: ${money}` : '';
  }
  if (d.compensationType === 'stipend') {
    if (d.paymentFrequency === 'one_time') {
      const money = formatMoney(d.compensationAmount, '');
      return money ? `Stipend: ${money} (one-time)` : '';
    }
    const per = d.paymentFrequency === 'weekly' ? 'week' : 'month';
    const money = formatMoney(d.compensationAmount, per);
    return money ? `Stipend: ${money}` : '';
  }
  return '';
}

function workModeLabel(mode) {
  return { on_site: 'On-site', remote: 'Remote', hybrid: 'Hybrid' }[mode] || '';
}

function jobTypeLabel(type) {
  return type === 'internship' ? 'Internship' : (type === 'part_time' ? 'Part-Time Job' : '');
}

function statusBadge(status) {
  const map = { draft: ['muted', 'Draft'], published: ['success', 'Published'], closed: ['warning', 'Closed'] };
  const pair = map[status] || ['muted', status || 'â€”'];
  return `<span class="badge-soft ${pair[0]}">${pair[1]}</span>`;
}

function typeBadge(type) {
  const label = jobTypeLabel(type);
  const cls = type === 'internship' ? 'info' : 'primary';
  return label ? `<span class="badge-soft ${cls}">${esc(label)}</span>` : '';
}

function readForm() {
  return {
    jobType,
    title: $('fieldTitle').value.trim(),
    company: $('fieldCompany').value.trim(),
    description: $('fieldDescription').value.trim(),
    requiredSkills: $('fieldSkills').value.trim(),
    eligibilityCriteria: $('fieldEligibility').value.trim(),
    minCgpa: $('fieldMinCgpa').value.trim(),
    vacancies: $('fieldVacancies').value.trim(),
    workLocation: $('fieldLocation').value.trim(),
    workMode: $('fieldWorkMode').value,
    startDate: $('fieldStart').value,
    endDate: $('fieldEnd').value,
    duration: $('fieldDuration').value.trim(),
    workingHours: $('fieldHours').value.trim(),
    stipend: $('fieldStipend').value.trim(),
    compensationType: $('fieldCompType').value,
    compensationAmount: $('fieldAmount').value.trim(),
    paymentFrequency: $('fieldFrequency').value,
    applicationDeadline: $('fieldDeadline').value,
    contactInformation: $('fieldContact').value.trim(),
    additionalRequirements: $('fieldExtra').value.trim(),
    departmentId: access.departmentLocked ? access.departmentId : $('fieldDepartment').value,
  };
}

function clientErrors(forPublish) {
  const d = readForm();
  const errors = [];
  if (!d.jobType) errors.push('Select a job type.');
  if (!d.title) errors.push(d.jobType === 'internship' ? 'Internship title is required.' : 'Job title is required.');
  if (!forPublish) return errors;
  if (!d.company) errors.push('Company / organization is required.');
  if (!d.departmentId) errors.push('Select a department.');
  if (!d.description) errors.push(d.jobType === 'internship' ? 'Internship description is required.' : 'Job description is required.');
  if (!d.requiredSkills) errors.push('Required skills are required.');
  if (!d.eligibilityCriteria) errors.push('Eligibility criteria are required.');
  if (!d.vacancies || Number(d.vacancies) < 1) errors.push('Number of vacancies is required.');
  if (!d.workLocation) errors.push('Work location is required.');
  if (!d.workMode) errors.push('Select a work mode.');
  if (!d.startDate) errors.push('Start date is required.');
  if (!d.endDate && !d.duration) errors.push('Enter an end date or a duration.');
  if (!d.workingHours) errors.push('Working hours are required.');
  if (!d.applicationDeadline) errors.push('Application deadline is required.');
  if (!d.contactInformation) errors.push('Contact information is required.');
  if (d.jobType === 'part_time' && !d.stipend) errors.push('Stipend is required for a part-time job.');
  if (d.jobType === 'internship') {
    if (!d.compensationType) errors.push('Select a compensation type.');
    else if (d.compensationType === 'fee' && !d.compensationAmount) errors.push('Fee amount is required for a fee-based internship.');
    else if (d.compensationType === 'stipend') {
      if (!d.compensationAmount) errors.push('Stipend amount is required.');
      if (!d.paymentFrequency) errors.push('Select a payment frequency.');
    }
  }
  return errors;
}

function syncTypeUi() {
  const internship = jobType === 'internship';
  $('titleLabel').textContent = internship ? 'Internship title' : 'Job title';
  $('descriptionLabel').textContent = internship ? 'Internship description' : 'Job description';
  $('stipendWrap').classList.toggle('d-none', internship || !jobType);
  $('compTypeWrap').classList.toggle('d-none', !internship);
  document.querySelectorAll('.job-type-choice').forEach(btn => {
    btn.classList.toggle('border-primary', btn.dataset.jobType === jobType);
    btn.style.borderWidth = btn.dataset.jobType === jobType ? '2px' : '';
  });
  syncCompensationUi();
}

function syncCompensationUi() {
  const type = $('fieldCompType').value;
  const internship = jobType === 'internship';
  const showAmount = internship && (type === 'fee' || type === 'stipend');
  const showFreq = internship && type === 'stipend';
  $('amountWrap').classList.toggle('d-none', !showAmount);
  $('frequencyWrap').classList.toggle('d-none', !showFreq);
  $('unpaidNote').classList.toggle('d-none', !(internship && type === 'unpaid'));
  $('amountLabel').textContent = type === 'fee' ? 'Internship fee' : 'Stipend amount';
  $('amountHelp').textContent = type === 'fee'
    ? 'Shown as Fee: â‚¹2,500.'
    : '8000 with Monthly is shown as Stipend: â‚¹8,000/month.';
}

function showStep(next) {
  if (next === 2 && !jobType) {
    toast('Select Part-Time Job or Internship.', 'warn');
    return;
  }
  if (next === 3) {
    const errors = clientErrors(false);
    if (errors.length) { toast(errors[0], 'warn'); return; }
  }
  if (next === 4) {
    const errors = clientErrors(false);
    if (!readForm().departmentId) errors.push('Select a department.');
    if (errors.length) { toast(errors[0], 'warn'); return; }
    renderPreview();
  }
  step = next;
  document.querySelectorAll('[data-step-panel]').forEach(panel => {
    panel.classList.toggle('d-none', Number(panel.dataset.stepPanel) !== step);
  });
  $('stepBar').innerHTML = STEPS.map((label, i) => {
    const n = i + 1;
    const cls = n === step ? 'primary' : (n < step ? 'success' : 'muted');
    return `<span class="badge-soft ${cls}">${n}. ${label}</span>`;
  }).join('');
  $('btnBack').classList.toggle('d-none', step === 1);
  const draftVisible = step > 1 && editingStatus !== 'published';
  $('btnSaveDraft').classList.toggle('d-none', !draftVisible);
  $('btnSaveDraft').textContent = editingId && editingStatus === 'closed' ? 'Save changes' : 'Save draft';
  $('btnNext').classList.toggle('d-none', step === 4);
  $('btnPublish').classList.toggle('d-none', step !== 4);
  $('btnPublish').textContent = editingStatus === 'published' ? 'Save changes' : 'Publish';
}

function renderPreview() {
  const d = readForm();
  const dept = access.departmentLocked
    ? deptLabel(access.departmentCode, access.departmentName)
    : (access.departments.find(x => x.id === d.departmentId)
      ? deptLabel(access.departments.find(x => x.id === d.departmentId).code, access.departments.find(x => x.id === d.departmentId).name)
      : 'â€”');
  const duration = d.duration || (d.startDate && d.endDate ? `${d.startDate} to ${d.endDate}` : (d.endDate || d.startDate || 'â€”'));
  $('previewCard').innerHTML = postCardHtml({
    ...d,
    jobTypeLabel: jobTypeLabel(d.jobType),
    departmentLabel: dept,
    workModeLabel: workModeLabel(d.workMode),
    durationLabel: duration,
    compensationLabel: compensationLabel(d),
    status: editingStatus || 'draft',
  }, true);
}

function fillForm(post) {
  jobType = post.jobType || '';
  $('fieldTitle').value = post.title || '';
  $('fieldCompany').value = post.company || '';
  $('fieldDescription').value = post.description || '';
  $('fieldSkills').value = post.requiredSkills || '';
  $('fieldEligibility').value = post.eligibilityCriteria || '';
  $('fieldMinCgpa').value = post.minCgpa ?? '';
  $('fieldVacancies').value = post.vacancies || '';
  $('fieldLocation').value = post.workLocation || '';
  $('fieldWorkMode').value = post.workMode || '';
  $('fieldStart').value = post.startDate || '';
  $('fieldEnd').value = post.endDate || '';
  $('fieldDuration').value = post.duration || '';
  $('fieldHours').value = post.workingHours || '';
  $('fieldStipend').value = post.stipend || '';
  $('fieldCompType').value = post.compensationType || '';
  $('fieldAmount').value = post.compensationAmount || '';
  $('fieldFrequency').value = post.paymentFrequency || '';
  $('fieldDeadline').value = post.applicationDeadline || '';
  $('fieldContact').value = post.contactInformation || '';
  $('fieldExtra').value = post.additionalRequirements || '';
  $('fieldAttachment').value = '';
  if (!access.departmentLocked && post.departmentId) $('fieldDepartment').value = post.departmentId;
  const link = $('currentAttachment');
  if (post.attachmentUrl) {
    link.href = post.attachmentUrl;
    link.textContent = post.attachmentName || 'Current attachment';
    link.classList.remove('d-none');
  } else {
    link.classList.add('d-none');
    link.removeAttribute('href');
  }
  syncTypeUi();
}

function resetForm() {
  editingId = '';
  editingStatus = 'draft';
  jobType = '';
  ['fieldTitle','fieldCompany','fieldDescription','fieldSkills','fieldEligibility','fieldMinCgpa','fieldVacancies','fieldLocation','fieldHours','fieldStipend','fieldAmount','fieldDuration','fieldContact','fieldExtra'].forEach(id => { $(id).value = ''; });
  ['fieldWorkMode','fieldCompType','fieldFrequency','fieldStart','fieldEnd','fieldDeadline'].forEach(id => { $(id).value = ''; });
  $('fieldAttachment').value = '';
  $('currentAttachment').classList.add('d-none');
  if (!access.departmentLocked) $('fieldDepartment').value = '';
  syncTypeUi();
}

function openWizard(post) {
  if (post) {
    editingId = post.id;
    editingStatus = post.status || 'draft';
    fillForm(post);
    $('wizardTitle').textContent = 'Edit internal job post';
  } else {
    resetForm();
    $('wizardTitle').textContent = 'Create Internal Job Post';
  }
  $('browser').classList.add('d-none');
  $('listHeader').classList.add('d-none');
  $('wizard').classList.remove('d-none');
  showStep(post ? 2 : 1);
}

function closeWizard() {
  $('wizard').classList.add('d-none');
  $('browser').classList.remove('d-none');
  $('listHeader').classList.remove('d-none');
}

function postCardHtml(p, preview) {
  const comp = p.compensationLabel || 'â€”';
  return `<article class="card-surface h-100 p-3 d-flex flex-column">
    <div class="d-flex flex-wrap gap-2 mb-2">${typeBadge(p.jobType)}${statusBadge(p.status)}${p.applied ? '<span class="badge-soft success">Applied</span>' : ''}</div>
    <h3 class="h6 fw-bold mb-1">${esc(p.title || 'Untitled')}</h3>
    <div class="small text-muted-2 mb-2">${esc(p.company || 'â€”')}</div>
    <div class="small d-grid gap-1">
      <div><i class="bi bi-diagram-3 me-2"></i>${esc(p.departmentLabel || 'â€”')}</div>
      <div><i class="bi bi-geo-alt me-2"></i>${esc(p.workLocation || 'â€”')}</div>
      <div><i class="bi bi-laptop me-2"></i>${esc(p.workModeLabel || 'â€”')}</div>
      <div><i class="bi bi-calendar-range me-2"></i>${esc(p.durationLabel || 'â€”')}</div>
      <div><i class="bi bi-cash-coin me-2"></i>${esc(comp)}</div>
    </div>
    ${preview ? '' : '<div class="mt-3" data-card-actions></div>'}
  </article>`;
}

function detailHtml(p) {
  const rows = [
    ['Job type', p.jobTypeLabel],
    ['Company', p.company],
    ['Department', p.departmentLabel],
    ['Description', p.description],
    ['Required skills', p.requiredSkills],
    ['Eligibility', p.eligibilityCriteria],
    ['Minimum CGPA', p.minCgpa ? String(p.minCgpa) : 'â€”'],
    ['Vacancies', p.vacancies ? String(p.vacancies) : 'â€”'],
    ['Location', p.workLocation],
    ['Work mode', p.workModeLabel],
    ['Start date', p.startDate],
    ['End date', p.endDate],
    ['Duration', p.durationLabel],
    ['Working hours', p.workingHours],
    ['Compensation', p.compensationLabel],
    ['Deadline', p.applicationDeadline],
    ['Contact', p.contactInformation],
    ['Additional requirements', p.additionalRequirements],
  ];
  return rows.map(([label, value]) => `<div class="mb-2"><div class="small text-muted-2">${esc(label)}</div><div>${esc(value || 'â€”')}</div></div>`).join('')
    + (p.attachmentUrl ? `<a class="btn btn-sm btn-outline-primary" href="${esc(p.attachmentUrl)}" target="_blank" rel="noopener">Open attachment</a>` : '');
}

function renderList() {
  const canManage = !!access.canManage;
  $('postList').innerHTML = posts.length ? `<div class="row g-3">${posts.map(p => `
    <div class="col-md-6 col-xl-4">
      ${postCardHtml(p, false).replace('<div class="mt-3" data-card-actions></div>', `<div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-detail="${esc(p.id)}">Details</button>
        ${canManage ? `<button class="btn btn-sm btn-outline-primary" type="button" data-edit="${esc(p.id)}">Edit</button>` : ''}
        ${canManage && p.status !== 'published' ? `<button class="btn btn-sm btn-success" type="button" data-publish="${esc(p.id)}">Publish</button>` : ''}
        ${canManage && p.status === 'published' ? `<button class="btn btn-sm btn-outline-warning" type="button" data-close="${esc(p.id)}">Close</button>` : ''}
        ${canManage ? `<button class="btn btn-sm btn-outline-secondary" type="button" data-applicants="${esc(p.id)}">Applicants (${p.applicantCount || 0})</button>` : ''}
        ${canManage ? `<button class="btn btn-sm btn-outline-danger" type="button" data-delete="${esc(p.id)}">Delete</button>` : ''}
        ${!canManage && p.canApply ? `<button class="btn btn-sm btn-primary" type="button" data-apply="${esc(p.id)}">Apply</button>` : ''}
        ${!canManage && p.applyBlockReason && p.status === 'published' && !p.applied ? `<div class="small text-muted-2 w-100">${esc(p.applyBlockReason)}</div>` : ''}
      </div>`)}
    </div>`).join('')}</div>`
    : '<div class="card-surface text-muted-2 text-center p-4">No internal job posts match these filters.</div>';
}

function fillDepartmentControls() {
  const filter = $('filterDepartment');
  const picker = $('fieldDepartment');
  const locked = !!access.departmentLocked || access.kind === 'student';
  const own = deptLabel(access.departmentCode, access.departmentName);
  if (locked) {
    filter.innerHTML = `<option value="${esc(access.departmentId)}">${esc(own)}</option>`;
    filter.disabled = true;
    $('deptPicker').classList.add('d-none');
    $('deptLocked').classList.remove('d-none');
    $('deptLockedLabel').textContent = own;
  } else if (access.kind === 'admin') {
    const options = (access.departments || []).map(d => `<option value="${esc(d.id)}">${esc(deptLabel(d.code, d.name))}</option>`).join('');
    filter.innerHTML = `<option value="all">All</option>${options}`;
    filter.disabled = false;
    picker.innerHTML = `<option value="">Select department</option>${options}`;
    $('deptPicker').classList.remove('d-none');
    $('deptLocked').classList.add('d-none');
  } else {
    filter.innerHTML = '<option value="all">All</option>';
    filter.disabled = true;
  }
  if (access.kind === 'student') {
    $('filterStatusLabel').textContent = 'Application status';
    $('filterStatus').innerHTML = '<option value="all">All</option><option value="applied">Applied</option><option value="not_applied">Not applied</option>';
  } else {
    $('filterStatusLabel').textContent = 'Status';
    $('filterStatus').innerHTML = '<option value="all">All</option><option value="draft">Draft</option><option value="published">Published</option><option value="closed">Closed</option>';
  }
}

function applyAccessChrome() {
  const student = access.kind === 'student';
  $('pageTitle').textContent = student ? 'Internal Jobs' : 'Internal Job Posts';
  if (student) {
    $('pageSub').textContent = `Part-time jobs and internships for ${deptLabel(access.departmentCode, access.departmentName)}.`;
  } else if (access.departmentLocked) {
    $('pageSub').textContent = `You can create and manage posts for ${deptLabel(access.departmentCode, access.departmentName)} only.`;
  } else if (access.kind === 'admin') {
    $('pageSub').textContent = 'Choose a department for each post. Students in that department can view and apply.';
  } else {
    $('pageSub').textContent = 'Internal job posts are created by an admin or a placement officer assigned to a department.';
  }
  $('btnCreate').classList.toggle('d-none', !access.canManage);
  const key = `${access.kind}:${access.departmentId}:${access.canManage}`;
  if (chromeKey !== key) {
    fillDepartmentControls();
    chromeKey = key;
  }
  const notice = $('lockedNotice');
  if (!student && !access.canManage) {
    notice.textContent = 'Your account is not assigned to a department, so you cannot create or manage internal job posts.';
    notice.classList.remove('d-none');
  } else {
    notice.classList.add('d-none');
  }
}

function filterQuery() {
  const q = new URLSearchParams();
  q.set('jobType', $('filterJobType').value || 'all');
  q.set('compensation', $('filterCompensation').value || 'all');
  q.set('workMode', $('filterWorkMode').value || 'all');
  const location = $('filterLocation').value.trim();
  if (location) q.set('location', location);
  if (access.kind === 'student') q.set('applicationStatus', $('filterStatus').value || 'all');
  else q.set('status', $('filterStatus').value || 'all');
  if (access.kind === 'admin') q.set('departmentId', $('filterDepartment').value || 'all');
  return q.toString();
}

async function loadPosts() {
  const res = await api('/internal-jobs?' + filterQuery(), { noRedirectOn401: true });
  if (!res?.success) {
    posts = [];
    renderList();
    toast(res?.message || 'Could not load internal job posts.', 'error');
    return;
  }
  access = res.data?.access || access;
  posts = Array.isArray(res.data?.posts) ? res.data.posts : [];
  applyAccessChrome();
  renderList();
}

async function submitJob(action) {
  const publish = action === 'publish' && editingStatus !== 'published';
  const errors = clientErrors(publish || editingStatus === 'published');
  if (errors.length) { toast(errors[0], 'warn'); return; }
  const file = $('fieldAttachment').files?.[0];
  if (file && (!/\.(pdf|docx?|jpe?g|png|webp)$/i.test(file.name) || file.size > 10 * 1024 * 1024)) {
    toast('Choose a PDF, DOC, JPG, or PNG up to 10 MB.', 'warn');
    return;
  }
  const d = readForm();
  const verb = publish ? 'Publish' : (editingId ? 'Save' : 'Save draft');
  if (!(await confirmAction({ title: verb, message: `${verb} "${d.title}"?`, confirmText: verb, variant: 'primary' }))) return;
  const body = new FormData();
  Object.entries(d).forEach(([key, value]) => body.append(key, value ?? ''));
  body.set('action', publish ? 'publish' : 'draft');
  if (file) body.append('attachment', file, file.name);
  const path = editingId ? `/internal-jobs/${encodeURIComponent(editingId)}/save` : '/internal-jobs';
  const res = await api(path, { method: 'POST', body, noRedirectOn401: true });
  if (!res?.success) {
    toast(res?.message || 'Could not save the post.', 'error');
    return;
  }
  toast(res.message || 'Saved.', 'success');
  closeWizard();
  await loadPosts();
}

$('btnCreate').addEventListener('click', () => openWizard(null));
$('btnCancelWizard').addEventListener('click', closeWizard);
$('btnBack').addEventListener('click', () => showStep(Math.max(1, step - 1)));
$('btnNext').addEventListener('click', () => showStep(Math.min(4, step + 1)));
$('btnSaveDraft').addEventListener('click', () => submitJob('draft'));
$('btnPublish').addEventListener('click', () => {
  if (editingStatus === 'published') submitJob('save');
  else submitJob('publish');
});
document.querySelectorAll('.job-type-choice').forEach(btn => {
  btn.addEventListener('click', () => {
    jobType = btn.dataset.jobType || '';
    syncTypeUi();
  });
});
$('fieldCompType').addEventListener('change', syncCompensationUi);
['filterJobType','filterCompensation','filterDepartment','filterWorkMode','filterStatus'].forEach(id => {
  $(id).addEventListener('change', () => loadPosts());
});
$('filterLocation').addEventListener('change', () => loadPosts());

$('postList').addEventListener('click', async e => {
  const btn = e.target.closest('button');
  if (!btn) return;
  const id = btn.dataset.detail || btn.dataset.edit || btn.dataset.publish || btn.dataset.close || btn.dataset.delete || btn.dataset.applicants || btn.dataset.apply;
  const post = posts.find(p => p.id === id);
  if (!post) return;
  if (btn.dataset.detail) {
    $('detailTitle').textContent = post.title || 'Post';
    $('detailBody').innerHTML = detailHtml(post);
    bootstrap.Modal.getOrCreateInstance($('detailModal')).show();
    return;
  }
  if (btn.dataset.edit) { openWizard(post); return; }
  if (btn.dataset.publish) {
    if (!(await confirmAction({ title: 'Publish', message: `Publish "${post.title}"?`, confirmText: 'Publish', variant: 'primary' }))) return;
    const res = await api(`/internal-jobs/${encodeURIComponent(post.id)}/publish`, { method: 'POST', body: {}, noRedirectOn401: true });
    if (!res?.success) { toast(res?.message || 'Could not publish.', 'error'); return; }
    toast(res.message || 'Published.', 'success');
    await loadPosts();
    return;
  }
  if (btn.dataset.close) {
    if (!(await confirmAction({ title: 'Close post', message: `Close "${post.title}"? Students will no longer be able to apply.`, confirmText: 'Close', variant: 'warning' }))) return;
    const res = await api(`/internal-jobs/${encodeURIComponent(post.id)}/close`, { method: 'POST', body: {}, noRedirectOn401: true });
    if (!res?.success) { toast(res?.message || 'Could not close the post.', 'error'); return; }
    toast('Post closed.', 'success');
    await loadPosts();
    return;
  }
  if (btn.dataset.delete) {
    if (!(await confirmAction({ title: 'Delete post', message: `Delete "${post.title}"?`, confirmText: 'Delete', variant: 'danger' }))) return;
    const res = await api(`/internal-jobs/${encodeURIComponent(post.id)}`, { method: 'DELETE', noRedirectOn401: true });
    if (!res?.success) { toast(res?.message || 'Could not delete the post.', 'error'); return; }
    toast('Post deleted.', 'success');
    await loadPosts();
    return;
  }
  if (btn.dataset.applicants) {
    const res = await api(`/internal-jobs/${encodeURIComponent(post.id)}/applications`, { noRedirectOn401: true });
    const rows = res?.success && Array.isArray(res.data) ? res.data : [];
    $('applicantsBody').innerHTML = rows.length
      ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Name</th><th>Register no.</th><th>Applied</th></tr></thead><tbody>${rows.map(r => `<tr><td>${esc(r.studentName)}</td><td>${esc(r.registerNumber)}</td><td>${esc(formatDate(r.appliedAt))}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="text-muted-2">No applications yet.</div>';
    bootstrap.Modal.getOrCreateInstance($('applicantsModal')).show();
    return;
  }
  if (btn.dataset.apply) {
    if (!(await confirmAction({ title: 'Apply', message: `Apply for "${post.title}"?`, confirmText: 'Apply', variant: 'primary' }))) return;
    const res = await api(`/internal-jobs/${encodeURIComponent(post.id)}/apply`, { method: 'POST', body: {}, noRedirectOn401: true });
    if (!res?.success) { toast(res?.message || 'Could not apply.', 'error'); return; }
    toast('Application submitted.', 'success');
    await loadPosts();
  }
});

onAppReady(loadPosts);
})();