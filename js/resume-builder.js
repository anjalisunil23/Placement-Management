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

  const OBJECTIVE_MIN = 50;
  const OBJECTIVE_MAX = 500;

  const state = {
    personalComplete: false,
    educationComplete: false,
    objectiveComplete: false,
    objectiveText: '',
    objectiveEditing: false,
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
    return (state.personalComplete ? 1 : 0)
      + (state.educationComplete ? 1 : 0)
      + (state.objectiveComplete ? 1 : 0);
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

  function goToAcademicProfile() {
    goToExistingProfile();
    window.setTimeout(() => {
      document.getElementById('profQualificationsWrap')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 250);
  }

  function educationRank(label) {
    const upper = String(label || '').toUpperCase();
    if (/\b(SSLC|SSC|10TH|10\s*STD|CLASS\s*X|TENTH)\b/.test(upper) && !/HIGHER/.test(upper)) return 4;
    if (/\b(HSC|12TH|PLUS\s*TWO|PLUS2|PUC|CLASS\s*XII|HIGHER\s*SECONDARY)\b/.test(upper)) return 3;
    if (/\b(BCA|BTECH|B\.TECH|B\.E\.?|BE|BSC|B\.SC|BACHELOR|UNDERGRAD|UG)\b/.test(upper)) return 2;
    if (/\b(MCA|M\.?\s*TECH|MTECH|M\.?\s*SC|MSC|MBA|MASTER|CURRENT|CGPA|PG)\b/.test(upper)) return 1;
    return 2;
  }

  function passingYear(monthYear) {
    const match = String(monthYear || '').match(/(19|20)\d{2}/);
    return match ? Number(match[0]) : 0;
  }

  function formatEducationScore(row) {
    const mark = Number(row.mark);
    const maxMark = Number(row.maxMark ?? row.maxmark);
    const pct = Number(row.percentage);
    if (mark > 0 && mark <= 10 && (!maxMark || maxMark <= 10)) {
      const shown = Number.isInteger(mark) ? String(mark) : String(Math.round(mark * 100) / 100);
      return shown + ' CGPA';
    }
    if (pct > 0 && pct <= 100) return String(Math.round(pct * 100) / 100) + '%';
    if (mark > 0 && maxMark > 0) return String(Math.round((mark / maxMark) * 10000) / 100) + '%';
    if (mark > 0) return String(mark);
    return '';
  }

  function mapQualificationRow(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const qualification = firstText(raw.qualification, raw.qual, raw.degree);
    const institution = firstText(raw.institution, raw.instname, raw.inst_name, raw.instName);
    const university = firstText(raw.university, raw.board, raw.universityName, raw.boardName, raw.univ, raw.boardname);
    const registerNumber = firstText(raw.registerNumber, raw.regno, raw.reg_no, raw.registerno);
    const year = firstText(raw.monthYear, raw.monthyear, raw.month_year, raw.passedYear, raw.year);
    const score = formatEducationScore(raw);
    if (!qualification && !institution && !university && !registerNumber && !year && !score) return null;
    return {
      qualification,
      institution,
      university,
      registerNumber,
      year,
      score,
      rank: educationRank(qualification),
      yearNum: passingYear(year),
    };
  }

  function extractEducation(profile, session) {
    const p = profile && typeof profile === 'object' ? profile : {};
    const sessionUser = session && typeof session === 'object' ? session : {};
    const academic = (p.academic && typeof p.academic === 'object')
      ? p.academic
      : ((sessionUser.academic && typeof sessionUser.academic === 'object') ? sessionUser.academic : {});
    const rawRows = Array.isArray(p.qualifications) && p.qualifications.length
      ? p.qualifications
      : (Array.isArray(academic.qualifications) ? academic.qualifications : []);
    let rows = rawRows.map(mapQualificationRow).filter(Boolean);

    if (rows.length === 0) {
      const program = firstText(p.programme, p.program, academic.course, departmentName(p), departmentName(sessionUser), 'Current Degree');
      const cgpa = Number(p.cgpa ?? academic.cgpa);
      const marks12 = Number(academic.marks12th ?? p.marks12th ?? academic.ugMarks);
      const marks10 = Number(academic.marks10th ?? p.marks10th);
      if (cgpa > 0 && cgpa <= 10) {
        rows.push(mapQualificationRow({ qualification: program, mark: cgpa, maxMark: 10 }));
      }
      if (marks12 > 0 && marks12 <= 100) {
        rows.push(mapQualificationRow({ qualification: 'Plus Two / Higher Secondary', percentage: marks12, mark: marks12, maxMark: 100 }));
      }
      if (marks10 > 0 && marks10 <= 100) {
        rows.push(mapQualificationRow({ qualification: 'SSLC / 10th', percentage: marks10, mark: marks10, maxMark: 100 }));
      }
      rows = rows.filter(Boolean);
    }

    rows.sort((a, b) => a.rank - b.rank || b.yearNum - a.yearNum);
    return rows;
  }

  function educationStatus(rows) {
    return { complete: Array.isArray(rows) && rows.length > 0 };
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

  function educationEntry(row) {
    const details = [];
    if (row.institution) details.push(`<div>${esc(row.institution)}</div>`);
    if (row.university) details.push(`<div>${esc(row.university)}</div>`);
    if (row.registerNumber) details.push(`<div>Reg. No. ${esc(row.registerNumber)}</div>`);
    const detailsHtml = details.length ? `<div class="rb-edu-meta mt-1">${details.join('')}</div>` : '';
    return `
      <div class="rb-edu-item">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <div class="rb-edu-title">${displayValue(row.qualification)}</div>
            ${detailsHtml}
          </div>
          <div class="text-end flex-shrink-0">
            ${row.year ? `<div class="small fw-semibold">${esc(row.year)}</div>` : ''}
            ${row.score ? `<div class="small text-muted-2">${esc(row.score)}</div>` : ''}
          </div>
        </div>
      </div>`;
  }

  function educationCardBody(rows, status, loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading education details…</p>`;
    }

    const badge = status.complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const listHtml = rows.length
      ? rows.map(educationEntry).join('')
      : '<p class="small text-muted-2 mb-3">No education records were found on your academic profile.</p>';

    return `
      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
          <div class="rb-section-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></div>
          <h6 class="fw-bold mb-0">Education</h6>
        </div>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Education details are automatically synced from your academic profile.
      </div>
      <div class="mb-3">${listHtml}</div>
      <button type="button" class="btn btn-sm btn-outline-primary" data-rb-view-academic>
        <i class="bi bi-box-arrow-up-right me-1"></i>View Academic Profile
      </button>`;
  }

  function objectiveLength(text) {
    return String(text || '').trim().length;
  }

  function objectiveValid(text) {
    const len = objectiveLength(text);
    return len >= OBJECTIVE_MIN && len <= OBJECTIVE_MAX;
  }

  function objectiveEditor(text, message) {
    const value = String(text || '');
    const len = objectiveLength(value);
    const ok = objectiveValid(value);
    const msg = message
      ? `<div class="alert alert-warning py-2 small mb-2" role="alert">${esc(message)}</div>`
      : '';
    return `
      ${msg}
      <label class="form-label small fw-semibold" for="rbObjectiveText">Career objective</label>
      <textarea class="form-control" id="rbObjectiveText" rows="4" maxlength="${OBJECTIVE_MAX}" data-rb-objective-input>${esc(value)}</textarea>
      <div class="d-flex justify-content-between align-items-center mt-1 mb-3">
        <div class="form-text mb-0" data-rb-objective-count>${len} / ${OBJECTIVE_MAX} characters (minimum ${OBJECTIVE_MIN})</div>
        <div class="d-flex gap-2">
          ${state.objectiveText ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-rb-objective-cancel>Cancel</button>' : ''}
          <button type="button" class="btn btn-sm btn-primary" data-rb-objective-save ${ok ? '' : 'disabled'}>Save</button>
        </div>
      </div>
      <p class="small text-muted-2 mb-0">
        Examples:<br>
        - MCA student passionate about software development and problem solving.<br>
        - Seeking opportunities to apply programming and analytical skills in real-world projects.
      </p>`;
  }

  function objectiveCardBody(loading, message) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading career objective…</p>`;
    }

    const hasSaved = objectiveValid(state.objectiveText);
    const editing = state.objectiveEditing || !hasSaved;
    const badge = hasSaved
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';

    let body;
    if (editing) {
      body = objectiveEditor(state.objectiveEditing ? state.objectiveText : (hasSaved ? state.objectiveText : ''), message);
    } else {
      body = `
        <p class="mb-3" style="white-space:pre-wrap">${esc(state.objectiveText)}</p>
        <button type="button" class="btn btn-sm btn-outline-primary" data-rb-objective-edit>Edit</button>`;
    }

    return `
      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
          <div class="rb-section-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></div>
          <h6 class="fw-bold mb-0">Career Objective</h6>
        </div>
        ${badge}
      </div>
      ${body}`;
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

    if (section.id === 'education') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="education">
            ${educationCardBody([], { complete: false }, true)}
          </div>
        </div>`;
    }

    if (section.id === 'objective') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="objective">
            ${objectiveCardBody(true)}
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
      if (event.target.closest('[data-rb-edit-profile]')) {
        goToExistingProfile();
        return;
      }
      if (event.target.closest('[data-rb-view-academic]')) {
        goToAcademicProfile();
        return;
      }
      if (event.target.closest('[data-rb-objective-edit]')) {
        state.objectiveEditing = true;
        renderObjectiveCard();
        return;
      }
      if (event.target.closest('[data-rb-objective-cancel]')) {
        state.objectiveEditing = false;
        renderObjectiveCard();
        return;
      }
      if (event.target.closest('[data-rb-objective-save]')) {
        saveCareerObjective();
      }
    });

    root.addEventListener('input', (event) => {
      const ta = event.target.closest('[data-rb-objective-input]');
      if (!ta || !root.contains(ta)) return;
      const len = objectiveLength(ta.value);
      const count = root.querySelector('[data-rb-objective-count]');
      if (count) {
        count.textContent = len + ' / ' + OBJECTIVE_MAX + ' characters (minimum ' + OBJECTIVE_MIN + ')';
      }
      const saveBtn = root.querySelector('[data-rb-objective-save]');
      if (saveBtn) saveBtn.disabled = !objectiveValid(ta.value);
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

  function applyEducation(rows) {
    const status = educationStatus(rows);
    state.educationComplete = status.complete;
    const card = root.querySelector('[data-rb-card="education"]');
    if (card) card.innerHTML = educationCardBody(rows, status, false);
    updateCompletionUi();
  }

  function renderObjectiveCard(message) {
    const card = root.querySelector('[data-rb-card="objective"]');
    if (card) card.innerHTML = objectiveCardBody(false, message);
    updateCompletionUi();
  }

  function applyObjective(text) {
    const saved = String(text || '').trim();
    state.objectiveText = saved;
    state.objectiveComplete = objectiveValid(saved);
    state.objectiveEditing = !state.objectiveComplete;
    renderObjectiveCard();
  }

  async function loadCareerObjective() {
    const canFetch = typeof api === 'function'
      && typeof Auth !== 'undefined'
      && typeof Auth.hasRealAuth === 'function'
      && Auth.hasRealAuth();
    if (!canFetch) {
      applyObjective('');
      return;
    }
    try {
      const res = await api('/student/resume-builder/career-objective', { skipAuthRedirect: true });
      if (res?.success) {
        applyObjective(res.data?.objectiveText || '');
        return;
      }
    } catch (_err) {
      // Fall through to empty state.
    }
    applyObjective('');
  }

  async function saveCareerObjective() {
    const ta = root.querySelector('[data-rb-objective-input]');
    const text = ta ? ta.value : '';
    if (!objectiveValid(text)) {
      renderObjectiveCard('Career objective must be between ' + OBJECTIVE_MIN + ' and ' + OBJECTIVE_MAX + ' characters.');
      const again = root.querySelector('[data-rb-objective-input]');
      if (again) again.value = text;
      return;
    }
    const saveBtn = root.querySelector('[data-rb-objective-save]');
    if (saveBtn) saveBtn.disabled = true;
    try {
      const res = await api('/student/resume-builder/career-objective', {
        method: 'PUT',
        body: { objectiveText: String(text).trim() },
        skipAuthRedirect: true,
      });
      if (res?.success) {
        applyObjective(res.data?.objectiveText || text);
        return;
      }
      renderObjectiveCard(res?.message || 'Could not save career objective.');
      const again = root.querySelector('[data-rb-objective-input]');
      if (again) again.value = text;
    } catch (_err) {
      renderObjectiveCard('Could not save career objective.');
      const again = root.querySelector('[data-rb-objective-input]');
      if (again) again.value = text;
    }
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
    applyEducation(extractEducation(profile, session));
  }

  function init() {
    renderShell();
    loadPersonalFromProfile();
    loadCareerObjective();
  }

  if (typeof onAppReady === 'function') onAppReady(init);
  else init();
})();
