/**
 * Resume Builder dashboard — UI + Personal Information (read-only from existing profile).
 * Isolated from Profile editing, Resumes upload, APIs, and persistence.
 */
(function () {
  const TOTAL_SECTIONS = 8;
  const root = document.getElementById('resumeBuilderDashboard');
  if (!root) return;

  const role = (typeof Auth !== 'undefined' && typeof Auth.role === 'function') ? Auth.role() : '';
  if (role && role !== 'student') return;

  const SECTIONS = [
    {
      id: 'personal',
      icon: 'bi-person-vcard',
      title: 'Personal Information',
      description: 'Name, contact details, and identity fields used in the resume header.',
    },
    {
      id: 'objective',
      icon: 'bi-bullseye',
      title: 'Career Objective',
      description: 'A short professional summary of your goals and strengths.',
    },
    {
      id: 'education',
      icon: 'bi-mortarboard',
      title: 'Education',
      description: 'Academic qualifications, institutions, and scores.',
    },
    {
      id: 'skills',
      icon: 'bi-stars',
      title: 'Skills',
      description: 'Technical and professional skills to highlight for recruiters.',
    },
    {
      id: 'projects',
      icon: 'bi-code-slash',
      title: 'Projects',
      description: 'Academic or personal projects with your role and outcomes.',
    },
    {
      id: 'internships',
      icon: 'bi-briefcase',
      title: 'Internships',
      description: 'Internship experience, organisation, and duration.',
    },
    {
      id: 'certifications',
      icon: 'bi-award',
      title: 'Certifications',
      description: 'Courses and certificates that support your profile.',
    },
    {
      id: 'achievements',
      icon: 'bi-trophy',
      title: 'Achievements',
      description: 'Awards, rankings, and other notable accomplishments.',
    },
    {
      id: 'preview',
      icon: 'bi-eye',
      title: 'Resume Preview',
      description: 'Preview how your resume will look before generating a PDF.',
    },
  ];

  const PERSONAL_MANDATORY = [
    { key: 'fullName', label: 'Full Name' },
    { key: 'registerNumber', label: 'Register Number' },
    { key: 'studentId', hidden: true },
    { key: 'collegeEmail', label: 'College Email' },
    { key: 'mobile', label: 'Mobile Number' },
    { key: 'department', label: 'Department' },
  ];

  const state = {
    personalComplete: false,
  };

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  }

  function firstText(...values) {
    for (const value of values) {
      if (value && typeof value === 'object') continue;
      const text = String(value ?? '').trim();
      if (text) return text;
    }
    return '';
  }

  function displayValue(value) {
    const text = String(value ?? '').trim();
    return text ? esc(text) : '—';
  }

  function departmentName(profile) {
    const dept = profile?.department;
    if (dept && typeof dept === 'object') {
      return firstText(dept.name, dept.code);
    }
    return firstText(
      profile?.departmentName,
      typeof dept === 'string' ? dept : '',
      profile?.departmentCode,
      profile?.branch,
      profile?.programme
    );
  }

  function extractPersonal(profile, session) {
    const p = profile && typeof profile === 'object' ? profile : {};
    const sessionUser = session && typeof session === 'object' ? session : {};
    const user = p.user && typeof p.user === 'object' ? p.user : {};
    const personal = p.personal && typeof p.personal === 'object' ? p.personal : {};
    const dept = departmentName(p) || departmentName(sessionUser);

    return {
      fullName: firstText(user.stud_name, user.name, p.displayName, p.stud_name, sessionUser.stud_name, sessionUser.name),
      registerNumber: firstText(p.registerNumber, sessionUser.registerNumber, sessionUser.admission_no),
      studentId: firstText(p.studentId, p._id, p.id, sessionUser.studentId, sessionUser.id),
      collegeEmail: firstText(user.collegeEmail, p.collegeEmail, sessionUser.collegeEmail, user.email, sessionUser.email),
      personalEmail: firstText(personal.personalEmail, user.personalEmail, sessionUser.personalEmail),
      mobile: firstText(personal.phone, user.phone, p.phone, sessionUser.phone),
      department: dept,
      program: firstText(personal.course, p.programme, p.program, p.branch, sessionUser.course, dept),
      batch: firstText(p.classBatch, p.stud_class, personal.year, p.batch, sessionUser.classBatch),
      gender: firstText(p.gender, personal.gender, user.gender, sessionUser.gender),
    };
  }

  function personalStatus(fields) {
    const missingItems = PERSONAL_MANDATORY.filter((item) => !String(fields[item.key] || '').trim());
    return {
      complete: missingItems.length === 0,
      missing: missingItems.filter((item) => !item.hidden).map((item) => item.label),
    };
  }

  function completedCount() {
    return state.personalComplete ? 1 : 0;
  }

  function completionPercent() {
    return Math.round((completedCount() / TOTAL_SECTIONS) * 100);
  }

  function updateCompletionUi() {
    const done = completedCount();
    const pct = completionPercent();
    const pctEl = root.querySelector('[data-rb-completion-pct]');
    const countEl = root.querySelector('[data-rb-completion-count]');
    const fillEl = root.querySelector('[data-rb-completion-fill]');
    const barEl = root.querySelector('[data-rb-completion-bar]');
    if (pctEl) pctEl.textContent = pct + '%';
    if (countEl) countEl.textContent = 'Sections Completed: ' + done + '/' + TOTAL_SECTIONS;
    if (fillEl) fillEl.style.width = pct + '%';
    if (barEl) barEl.setAttribute('aria-valuenow', String(pct));
  }

  function goToExistingProfile() {
    document.querySelector('#settingsNav a[href="#prof"]')?.click();
  }

  function fieldRow(label, value) {
    return `
      <div class="col-12 col-md-6">
        <div class="rb-field">
          <div class="form-label small fw-semibold mb-1">${esc(label)}</div>
          <div class="rb-field-value">${displayValue(value)}</div>
        </div>
      </div>`;
  }

  function personalCardBody(fields, status, loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading profile information…</p>`;
    }

    const badge = status.complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const missingHtml = status.missing.length
      ? `<div class="alert alert-warning py-2 small mb-3" role="status">Missing: ${esc(status.missing.join(', '))}</div>`
      : '';

    return `
      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
          <div class="rb-section-icon" aria-hidden="true"><i class="bi bi-person-vcard"></i></div>
          <h6 class="fw-bold mb-0">Personal Information</h6>
        </div>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Profile information is automatically synced from your student profile.
      </div>
      ${missingHtml}
      <div class="row g-3 mb-3">
        ${fieldRow('Full Name', fields.fullName)}
        ${fieldRow('Register Number', fields.registerNumber)}
        ${fieldRow('College Email', fields.collegeEmail)}
        ${fieldRow('Personal Email', fields.personalEmail)}
        ${fieldRow('Mobile Number', fields.mobile)}
        ${fieldRow('Department', fields.department)}
        ${fieldRow('Program/Course', fields.program)}
        ${fieldRow('Batch / Passout Year', fields.batch)}
        ${fieldRow('Gender', fields.gender)}
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary" data-rb-edit-profile>
        <i class="bi bi-pencil-square me-1"></i>Edit Profile
      </button>`;
  }

  function sectionCard(section) {
    if (section.id === 'personal') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="personal">
            ${personalCardBody({}, { complete: false, missing: [] }, true)}
          </div>
        </div>`;
    }

    return `
      <div class="col-12 col-md-6">
        <div class="card-surface p-3 p-md-4 rb-section-card">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
              <div class="rb-section-icon" aria-hidden="true"><i class="bi ${section.icon}"></i></div>
              <h6 class="fw-bold mb-0">${section.title}</h6>
            </div>
            <span class="badge-soft muted">Not Started</span>
          </div>
          <p class="small text-muted-2 mb-3 rb-section-desc">${section.description}</p>
          <button type="button" class="btn btn-sm btn-outline-primary align-self-start" data-rb-section="${section.id}">Configure</button>
        </div>
      </div>`;
  }

  function renderShell() {
    root.innerHTML = `
      <div class="card-surface p-3 p-md-4 mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
          <div class="flex-grow-1">
            <h5 class="fw-bold mb-1">Resume Completion</h5>
            <div class="rb-completion-pct mb-1" data-rb-completion-pct>0%</div>
            <p class="small text-muted-2 mb-2" data-rb-completion-count>Sections Completed: 0/${TOTAL_SECTIONS}</p>
            <div class="rb-completion-bar" data-rb-completion-bar role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Resume completion">
              <div class="rb-completion-bar-fill" data-rb-completion-fill></div>
            </div>
          </div>
          <div class="rb-completion-actions">
            <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top" title="Complete required sections first">
              <button type="button" class="btn btn-primary" disabled aria-disabled="true">
                <i class="bi bi-file-earmark-pdf me-1"></i>Generate Resume PDF
              </button>
            </span>
          </div>
        </div>
      </div>
      <div class="row g-3">
        ${SECTIONS.map(sectionCard).join('')}
      </div>
    `;

    root.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-rb-edit-profile]');
      if (!btn || !root.contains(btn)) return;
      goToExistingProfile();
    });

    if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
      root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        bootstrap.Tooltip.getOrCreateInstance(el);
      });
    }
  }

  function applyPersonal(fields) {
    const status = personalStatus(fields);
    state.personalComplete = status.complete;
    const card = root.querySelector('[data-rb-card="personal"]');
    if (card) card.innerHTML = personalCardBody(fields, status, false);
    updateCompletionUi();
  }

  async function loadPersonalFromProfile() {
    const session = (typeof Auth !== 'undefined' && typeof Auth.user === 'function') ? (Auth.user() || {}) : {};
    let profile = null;

    const canFetch = typeof api === 'function'
      && typeof Auth !== 'undefined'
      && typeof Auth.hasRealAuth === 'function'
      && Auth.hasRealAuth();

    if (canFetch) {
      try {
        const res = await api('/student/profile', { skipAuthRedirect: true });
        if (res?.success && res.data) profile = res.data;
      } catch (_err) {
        profile = null;
      }
    }

    applyPersonal(extractPersonal(profile, session));
  }

  function init() {
    renderShell();
    loadPersonalFromProfile();
  }

  if (typeof onAppReady === 'function') onAppReady(init);
  else init();
})();
