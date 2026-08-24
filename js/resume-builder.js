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
      title: 'Experience',
      description: 'Internships, training, research, volunteering, and other relevant work experience.',
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
      title: 'Achievements, Leadership & Activities',
      description: 'Leadership roles, volunteer work, clubs, competitions, sports, and notable accomplishments.',
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
  const SKILL_MIN = 2;
  const SKILL_MAX = 50;
  const SKILL_COMPLETE_MIN = 3;
  const SKILL_CATEGORIES = ['Technical', 'Tools', 'Soft Skills', 'Domain Skills', 'Languages'];
  const PROJECT_TITLE_MIN = 3;
  const PROJECT_TITLE_MAX = 150;
  const PROJECT_DESC_MIN = 50;
  const PROJECT_DESC_MAX = 1000;
  const PROJECT_COMPLETE_MIN = 1;
  const PROJECT_RECOMMENDED = 2;
  const PROJECT_TYPES = ['Academic', 'Personal', 'Internship', 'Research', 'Freelance', 'Other'];
  const EXP_ORG_MIN = 3;
  const EXP_ORG_MAX = 150;
  const EXP_POSITION_MIN = 3;
  const EXP_POSITION_MAX = 150;
  const EXP_DESC_MIN = 50;
  const EXP_DESC_MAX = 1000;
  const EXP_COMPLETE_MIN = 1;
  const EXP_RECOMMENDED = 2;
  const EXP_TYPES = ['Internship', 'Industrial Training', 'Research', 'Freelance', 'Volunteer', 'Part Time', 'Apprenticeship', 'Other'];
  const CERT_NAME_MIN = 3;
  const CERT_NAME_MAX = 200;
  const CERT_ORG_MIN = 2;
  const CERT_ORG_MAX = 150;
  const CERT_DESC_MAX = 1000;
  const CERT_COMPLETE_MIN = 1;
  const CERT_RECOMMENDED = 2;
  const ACT_TITLE_MIN = 3;
  const ACT_TITLE_MAX = 150;
  const ACT_DESC_MIN = 20;
  const ACT_DESC_MAX = 1000;
  const ACT_COMPLETE_MIN = 1;
  const ACT_RECOMMENDED = 2;
  const ACT_TYPES = [
    'Achievement',
    'Leadership',
    'Club Membership',
    'Professional Membership',
    'Volunteer Work',
    'Sports',
    'Arts & Culture',
    'Event Coordination',
    'Competition',
    'Community Service',
    'Other',
  ];

  const state = {
    personal: {},
    personalComplete: false,
    education: [],
    educationComplete: false,
    objectiveComplete: false,
    objectiveText: '',
    objectiveEditing: false,
    skills: [],
    skillsEditingId: '',
    skillsMessage: '',
    skillsFormOpen: false,
    projects: [],
    projectsEditingId: '',
    projectsMessage: '',
    projectsFormOpen: false,
    experiences: [],
    experiencesEditingId: '',
    experiencesMessage: '',
    experiencesFormOpen: false,
    certifications: [],
    certificationsEditingId: '',
    certificationsMessage: '',
    certificationsFormOpen: false,
    activities: [],
    activitiesEditingId: '',
    activitiesMessage: '',
    activitiesFormOpen: false,
    previewLoading: false,
    previewRefreshing: false,
    previewMode: false,
    contactLinks: { linkedinUrl: '', githubUrl: '', websiteUrl: '' },
    contactLinksMessage: '',
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

  function renderPersonalCard() {
    const card = root.querySelector('[data-rb-card="personal"]');
    if (!card) return;
    const status = personalStatus(state.personal);
    card.innerHTML = personalCardBody(state.personal, status, false);
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
      + (state.objectiveComplete ? 1 : 0)
      + (state.skills.length >= SKILL_COMPLETE_MIN ? 1 : 0)
      + (state.projects.length >= PROJECT_COMPLETE_MIN ? 1 : 0)
      + (state.experiences.length >= EXP_COMPLETE_MIN ? 1 : 0)
      + (state.certifications.length >= CERT_COMPLETE_MIN ? 1 : 0)
      + (state.activities.length >= ACT_COMPLETE_MIN ? 1 : 0);
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
      <div class="rb-links-form mt-2 pt-3 border-top">
        <h6 class="fw-semibold mb-2">Professional Links</h6>
        <p class="small text-muted-2 mb-3">Add LinkedIn, GitHub, or personal website links for your resume header.</p>
        ${state.contactLinksMessage ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.contactLinksMessage)}</div>` : ''}
        <div class="row g-2 mb-3">
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbLinkLinkedin">LinkedIn URL</label>
            <input type="url" class="form-control" id="rbLinkLinkedin" maxlength="500" placeholder="https://linkedin.com/in/username" value="${esc(state.contactLinks.linkedinUrl)}" data-rb-link-linkedin />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbLinkGithub">GitHub URL</label>
            <input type="url" class="form-control" id="rbLinkGithub" maxlength="500" placeholder="https://github.com/username" value="${esc(state.contactLinks.githubUrl)}" data-rb-link-github />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbLinkWebsite">Personal Website / Portfolio</label>
            <input type="url" class="form-control" id="rbLinkWebsite" maxlength="500" placeholder="https://yourwebsite.com" value="${esc(state.contactLinks.websiteUrl)}" data-rb-link-website />
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-primary mb-3" data-rb-link-save>Save Professional Links</button>
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
        - Motivated and dedicated student seeking opportunities to apply academic knowledge, develop professional skills, and contribute effectively to organizational goals.<br>
        - Enthusiastic learner with strong problem-solving and teamwork abilities, looking to gain practical experience and grow in a challenging professional environment.
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

  function skillNameValid(name) {
    const len = String(name || '').trim().length;
    return len >= SKILL_MIN && len <= SKILL_MAX;
  }

  function skillsCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading skills…</p>`;
    }

    const count = state.skills.length;
    const complete = count >= SKILL_COMPLETE_MIN;
    const badge = complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const editing = !!state.skillsEditingId;
    const formOpen = state.skillsFormOpen || editing;
    const editingSkill = state.skills.find((s) => s.id === state.skillsEditingId) || null;
    const msg = state.skillsMessage
      ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.skillsMessage)}</div>`
      : '';
    const recTarget = 8;
    const recPct = Math.min(100, Math.round((count / recTarget) * 100));

    const grouped = SKILL_CATEGORIES.map((cat) => {
      const items = state.skills.filter((s) => s.skillCategory === cat);
      if (!items.length) return '';
      const heading = cat === 'Technical' ? 'Technical Skills' : cat;
      const chips = items.map((skill) => `
        <span class="rb-skill-chip">
          <span class="rb-skill-name">${esc(skill.skillName)}</span>
          <span class="rb-skill-actions">
            <button type="button" class="rb-skill-action" data-rb-skill-edit="${esc(skill.id)}" title="Edit" aria-label="Edit ${esc(skill.skillName)}"><i class="bi bi-pencil"></i></button>
            <button type="button" class="rb-skill-action" data-rb-skill-delete="${esc(skill.id)}" title="Remove" aria-label="Remove ${esc(skill.skillName)}"><i class="bi bi-x-lg"></i></button>
          </span>
        </span>`).join('');
      return `
        <div class="rb-skill-group">
          <div class="rb-skill-group-title">${esc(heading)}</div>
          <div class="rb-skill-list">${chips}</div>
        </div>`;
    }).join('');

    const categoryOpts = SKILL_CATEGORIES.map((cat) => {
      const selected = editingSkill && editingSkill.skillCategory === cat ? ' selected' : '';
      return `<option value="${esc(cat)}"${selected}>${esc(cat)}</option>`;
    }).join('');

    const formHtml = `
      ${msg}
      <div class="rb-skill-form">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-5">
            <label class="form-label small fw-semibold" for="rbSkillName">Skill name</label>
            <input class="form-control" id="rbSkillName" maxlength="${SKILL_MAX}" placeholder="e.g. MS Excel" value="${esc(editingSkill ? editingSkill.skillName : '')}" data-rb-skill-name />
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small fw-semibold" for="rbSkillCategory">Category</label>
            <select class="form-select" id="rbSkillCategory" data-rb-skill-category>
              <option value="">Select category</option>
              ${categoryOpts}
            </select>
          </div>
          <div class="col-12 col-md-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" data-rb-skill-cancel>Cancel</button>
            <button type="button" class="btn btn-primary flex-grow-1" data-rb-skill-save>${editing ? 'Save' : 'Add Skill'}</button>
          </div>
        </div>
      </div>`;

    const emptyHtml = `
      <div class="rb-skill-empty">
        <p class="mb-3">No skills added yet</p>
        <button type="button" class="btn btn-primary" data-rb-skill-add><i class="bi bi-plus-lg me-1"></i>Add Skill</button>
      </div>`;

    const listHtml = count
      ? `<div class="rb-skill-groups">${grouped}</div>
         ${formOpen ? '' : '<button type="button" class="btn btn-sm btn-outline-primary mt-3" data-rb-skill-add><i class="bi bi-plus-lg me-1"></i>Add Skill</button>'}`
      : (formOpen ? '' : emptyHtml);

    return `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-bold mb-0">Skills</h6>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Include technical, domain, software, communication and professional skills relevant to your career goals.
      </div>
      <div class="rb-skill-summary mb-3">
        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
          <div class="fw-semibold">Skills Added: ${count}</div>
          <div class="small text-muted-2">Recommended: 8+ Skills</div>
        </div>
        <div class="rb-skill-rec-bar" role="progressbar" aria-valuemin="0" aria-valuemax="8" aria-valuenow="${count}" aria-label="Recommended skills">
          <div class="rb-skill-rec-fill" style="width:${recPct}%"></div>
        </div>
      </div>
      ${listHtml}
      ${formOpen ? formHtml : ''}`;
  }

  function formatProjectDates(startDate, endDate) {
    const start = String(startDate || '').trim();
    const end = String(endDate || '').trim();
    if (start && end) return esc(start) + ' – ' + esc(end);
    if (start) return 'From ' + esc(start);
    if (end) return 'Until ' + esc(end);
    return '';
  }

  function projectTypeBadgeClass(type) {
    const map = {
      Academic: 'info',
      Personal: 'success',
      Internship: 'warning',
      Research: 'info',
      Freelance: 'success',
      Other: 'muted',
    };
    return map[type] || 'muted';
  }

  function projectTechChips(tech) {
    const items = String(tech || '')
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean);
    if (!items.length) return '';
    return `
      <div class="rb-project-tech-list" aria-label="Technologies used">
        ${items.map((item) => `<span class="rb-project-tech-chip">${esc(item)}</span>`).join('')}
      </div>`;
  }

  function projectCardItem(project) {
    const tech = String(project.technologiesUsed || '').trim();
    const link = String(project.projectLink || '').trim();
    const dates = formatProjectDates(project.startDate, project.endDate);
    const typeClass = projectTypeBadgeClass(project.projectType);
    return `
      <article class="rb-project-card card-surface">
        <header class="rb-project-card-header">
          <div class="rb-project-card-heading">
            <h3 class="rb-project-title">${esc(project.projectTitle)}</h3>
            <span class="badge-soft ${typeClass} rb-project-type-badge">${esc(project.projectType)}</span>
          </div>
          <div class="rb-project-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary rb-project-action" data-rb-project-edit="${esc(project.id)}" title="Edit project" aria-label="Edit ${esc(project.projectTitle)}">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger rb-project-action" data-rb-project-delete="${esc(project.id)}" title="Delete project" aria-label="Delete ${esc(project.projectTitle)}">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </header>
        ${projectTechChips(tech)}
        <p class="rb-project-desc">${esc(project.projectDescription)}</p>
        <footer class="rb-project-card-footer">
          ${dates ? `<span class="rb-project-dates"><i class="bi bi-calendar3" aria-hidden="true"></i>${dates}</span>` : ''}
          ${link ? `<a class="btn btn-sm btn-outline-primary rb-project-link-btn" href="${esc(link)}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>View Project</a>` : ''}
        </footer>
      </article>`;
  }

  function projectsCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading projects…</p>`;
    }

    const count = state.projects.length;
    const complete = count >= PROJECT_COMPLETE_MIN;
    const badge = complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const editing = !!state.projectsEditingId;
    const formOpen = state.projectsFormOpen || editing;
    const editingProject = state.projects.find((p) => p.id === state.projectsEditingId) || null;
    const msg = state.projectsMessage
      ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.projectsMessage)}</div>`
      : '';
    const recPct = Math.min(100, Math.round((count / PROJECT_RECOMMENDED) * 100));

    const typeOpts = PROJECT_TYPES.map((type) => {
      const selected = editingProject && editingProject.projectType === type ? ' selected' : '';
      return `<option value="${esc(type)}"${selected}>${esc(type)}</option>`;
    }).join('');

    const formHtml = `
      ${msg}
      <div class="rb-project-form">
        <div class="row g-2">
          <div class="col-md-8">
            <label class="form-label small fw-semibold" for="rbProjectTitle">Project Title *</label>
            <input class="form-control" id="rbProjectTitle" maxlength="${PROJECT_TITLE_MAX}" value="${esc(editingProject ? editingProject.projectTitle : '')}" data-rb-project-title />
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="rbProjectType">Project Type *</label>
            <select class="form-select" id="rbProjectType" data-rb-project-type>
              <option value="">Select type</option>
              ${typeOpts}
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbProjectTech">Technologies / Tools Used</label>
            <input class="form-control" id="rbProjectTech" maxlength="500" placeholder="e.g. Python, MySQL, React" value="${esc(editingProject ? editingProject.technologiesUsed : '')}" data-rb-project-tech />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbProjectDesc">Description *</label>
            <textarea class="form-control" id="rbProjectDesc" rows="4" maxlength="${PROJECT_DESC_MAX}" data-rb-project-desc>${esc(editingProject ? editingProject.projectDescription : '')}</textarea>
            <div class="form-text">Minimum ${PROJECT_DESC_MIN} characters.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbProjectLink">Project Link (Optional)</label>
            <input type="url" class="form-control" id="rbProjectLink" maxlength="500" placeholder="https://..." value="${esc(editingProject && editingProject.projectLink ? editingProject.projectLink : '')}" data-rb-project-link />
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="rbProjectStart">Start Date</label>
            <input type="date" class="form-control" id="rbProjectStart" value="${esc(editingProject && editingProject.startDate ? editingProject.startDate : '')}" data-rb-project-start />
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="rbProjectEnd">End Date</label>
            <input type="date" class="form-control" id="rbProjectEnd" value="${esc(editingProject && editingProject.endDate ? editingProject.endDate : '')}" data-rb-project-end />
          </div>
          <div class="col-12 d-flex gap-2 justify-content-end mt-1">
            <button type="button" class="btn btn-outline-secondary" data-rb-project-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-rb-project-save>${editing ? 'Save Project' : 'Add Project'}</button>
          </div>
        </div>
      </div>`;

    const emptyHtml = `
      <div class="rb-project-empty">
        <div class="rb-project-empty-icon" aria-hidden="true"><i class="bi bi-kanban"></i></div>
        <p class="rb-project-empty-title mb-3">No projects added yet</p>
        <button type="button" class="btn btn-primary rb-project-add-btn" data-rb-project-add><i class="bi bi-plus-lg me-1"></i>Add Project</button>
      </div>`;

    const addBtnHtml = formOpen
      ? ''
      : `<div class="rb-project-add-wrap">
           <button type="button" class="btn btn-primary rb-project-add-btn" data-rb-project-add><i class="bi bi-plus-lg me-1"></i>Add Project</button>
         </div>`;

    const listHtml = count
      ? `<div class="rb-project-list">${state.projects.map(projectCardItem).join('')}</div>${addBtnHtml}`
      : (formOpen ? '' : emptyHtml);

    return `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-bold mb-0">Projects</h6>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Include academic, personal, internship, research, or freelance projects that demonstrate your skills and experience.
      </div>
      <div class="rb-project-summary mb-3">
        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
          <div class="fw-semibold">Projects Added: ${count}</div>
          <div class="small text-muted-2">Recommended: ${PROJECT_RECOMMENDED}+ Projects</div>
        </div>
        <div class="rb-skill-rec-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${PROJECT_RECOMMENDED}" aria-valuenow="${count}" aria-label="Recommended projects">
          <div class="rb-skill-rec-fill" style="width:${recPct}%"></div>
        </div>
      </div>
      ${listHtml}
      ${formOpen ? formHtml : ''}`;
  }

  function experienceTypeBadgeClass(type) {
    const map = {
      Internship: 'info',
      'Industrial Training': 'warning',
      Research: 'info',
      Freelance: 'success',
      Volunteer: 'success',
      'Part Time': 'warning',
      Apprenticeship: 'warning',
      Other: 'muted',
    };
    return map[type] || 'muted';
  }

  function formatExperienceMonth(dateStr) {
    const raw = String(dateStr || '').trim();
    if (!raw) return '';
    const parts = raw.split('-');
    if (parts.length < 2) return raw;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = parseInt(parts[1], 10);
    const year = parts[0];
    if (month >= 1 && month <= 12) return months[month - 1] + ' ' + year;
    return raw;
  }

  function formatExperienceDateRange(exp) {
    const start = formatExperienceMonth(exp.startDate);
    if (exp.currentlyWorking) return start ? start + ' – Present' : 'Present';
    const end = formatExperienceMonth(exp.endDate);
    if (start && end) return start + ' – ' + end;
    return start || end || '';
  }

  function experienceTimelineItem(exp) {
    const typeClass = experienceTypeBadgeClass(exp.experienceType);
    const dates = formatExperienceDateRange(exp);
    const location = String(exp.location || '').trim();
    return `
      <article class="rb-exp-item">
        <div class="rb-exp-card card-surface">
          <header class="rb-exp-card-header">
            <div class="rb-exp-card-heading">
              <h3 class="rb-exp-org">${esc(exp.organizationName)}</h3>
              <p class="rb-exp-role">${esc(exp.positionTitle)}</p>
              <div class="rb-exp-meta">
                <span class="badge-soft ${typeClass} rb-exp-type-badge">${esc(exp.experienceType)}</span>
                ${dates ? `<span class="rb-exp-dates"><i class="bi bi-calendar3" aria-hidden="true"></i>${esc(dates)}</span>` : ''}
                ${location ? `<span class="rb-exp-location"><i class="bi bi-geo-alt" aria-hidden="true"></i>${esc(location)}</span>` : ''}
              </div>
            </div>
            <div class="rb-exp-card-actions">
              <button type="button" class="btn btn-sm btn-outline-secondary rb-exp-action" data-rb-exp-edit="${esc(exp.id)}" title="Edit experience" aria-label="Edit ${esc(exp.organizationName)}">
                <i class="bi bi-pencil"></i>
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger rb-exp-action" data-rb-exp-delete="${esc(exp.id)}" title="Delete experience" aria-label="Delete ${esc(exp.organizationName)}">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </header>
          <details class="rb-exp-desc-details">
            <summary class="rb-exp-desc-summary">View description</summary>
            <p class="rb-exp-desc">${esc(exp.description)}</p>
          </details>
        </div>
      </article>`;
  }

  function experienceCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading experience…</p>`;
    }

    const count = state.experiences.length;
    const complete = count >= EXP_COMPLETE_MIN;
    const badge = complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const editing = !!state.experiencesEditingId;
    const formOpen = state.experiencesFormOpen || editing;
    const editingExp = state.experiences.find((e) => e.id === state.experiencesEditingId) || null;
    const msg = state.experiencesMessage
      ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.experiencesMessage)}</div>`
      : '';
    const recPct = Math.min(100, Math.round((count / EXP_RECOMMENDED) * 100));
    const currentlyChecked = editingExp ? !!editingExp.currentlyWorking : false;

    const typeOpts = EXP_TYPES.map((type) => {
      const selected = editingExp && editingExp.experienceType === type ? ' selected' : '';
      return `<option value="${esc(type)}"${selected}>${esc(type)}</option>`;
    }).join('');

    const formHtml = `
      ${msg}
      <div class="rb-exp-form">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbExpOrg">Organization Name *</label>
            <input class="form-control" id="rbExpOrg" maxlength="${EXP_ORG_MAX}" value="${esc(editingExp ? editingExp.organizationName : '')}" data-rb-exp-org />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbExpPosition">Position / Role *</label>
            <input class="form-control" id="rbExpPosition" maxlength="${EXP_POSITION_MAX}" value="${esc(editingExp ? editingExp.positionTitle : '')}" data-rb-exp-position />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbExpType">Experience Type *</label>
            <select class="form-select" id="rbExpType" data-rb-exp-type>
              <option value="">Select type</option>
              ${typeOpts}
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbExpLocation">Location</label>
            <input class="form-control" id="rbExpLocation" maxlength="150" placeholder="City, State" value="${esc(editingExp && editingExp.location ? editingExp.location : '')}" data-rb-exp-location />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbExpDesc">Description *</label>
            <textarea class="form-control" id="rbExpDesc" rows="4" maxlength="${EXP_DESC_MAX}" data-rb-exp-desc>${esc(editingExp ? editingExp.description : '')}</textarea>
            <div class="form-text">Minimum ${EXP_DESC_MIN} characters.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="rbExpStart">Start Date *</label>
            <input type="date" class="form-control" id="rbExpStart" value="${esc(editingExp && editingExp.startDate ? editingExp.startDate : '')}" data-rb-exp-start required />
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="rbExpEnd">End Date</label>
            <input type="date" class="form-control" id="rbExpEnd" value="${esc(editingExp && editingExp.endDate ? editingExp.endDate : '')}" data-rb-exp-end ${currentlyChecked ? 'disabled' : ''} />
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="rbExpCurrent" data-rb-exp-current ${currentlyChecked ? 'checked' : ''} />
              <label class="form-check-label small" for="rbExpCurrent">Currently Working</label>
            </div>
          </div>
          <div class="col-12 d-flex gap-2 justify-content-end mt-1">
            <button type="button" class="btn btn-outline-secondary" data-rb-exp-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-rb-exp-save>${editing ? 'Save Experience' : 'Add Experience'}</button>
          </div>
        </div>
      </div>`;

    const emptyHtml = `
      <div class="rb-exp-empty">
        <div class="rb-exp-empty-icon" aria-hidden="true"><i class="bi bi-briefcase"></i></div>
        <p class="rb-exp-empty-title mb-3">No experience added yet</p>
        <button type="button" class="btn btn-primary rb-exp-add-btn" data-rb-exp-add><i class="bi bi-plus-lg me-1"></i>Add Experience</button>
      </div>`;

    const addBtnHtml = formOpen
      ? ''
      : `<div class="rb-exp-add-wrap">
           <button type="button" class="btn btn-primary rb-exp-add-btn" data-rb-exp-add><i class="bi bi-plus-lg me-1"></i>Add Experience</button>
         </div>`;

    const listHtml = count
      ? `<div class="rb-exp-timeline">${state.experiences.map(experienceTimelineItem).join('')}</div>${addBtnHtml}`
      : (formOpen ? '' : emptyHtml);

    return `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-bold mb-0">Experience</h6>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Include internships, industrial training, research work, volunteering, freelance work, apprenticeships, and other relevant experiences.
      </div>
      <div class="rb-exp-summary mb-3">
        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
          <div class="fw-semibold">Experience Added: ${count}</div>
          <div class="small text-muted-2">Recommended: ${EXP_RECOMMENDED}+ Entries</div>
        </div>
        <div class="rb-skill-rec-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${EXP_RECOMMENDED}" aria-valuenow="${count}" aria-label="Recommended experience entries">
          <div class="rb-skill-rec-fill" style="width:${recPct}%"></div>
        </div>
      </div>
      ${listHtml}
      ${formOpen ? formHtml : ''}`;
  }

  function formatCertDate(dateStr) {
    const raw = String(dateStr || '').trim();
    if (!raw) return '';
    const parts = raw.split('-');
    if (parts.length < 2) return raw;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = parseInt(parts[1], 10);
    const year = parts[0];
    if (month >= 1 && month <= 12) return months[month - 1] + ' ' + year;
    return raw;
  }

  function formatCertDateRange(cert) {
    const issued = formatCertDate(cert.issueDate);
    const expiry = formatCertDate(cert.expiryDate);
    if (issued && expiry) return 'Issued ' + issued + ' · Expires ' + expiry;
    if (issued) return 'Issued ' + issued + ' · No expiry';
    return '';
  }

  function certificationCardItem(cert) {
    const dates = formatCertDateRange(cert);
    const credentialId = String(cert.credentialId || '').trim();
    const credentialUrl = String(cert.credentialUrl || '').trim();
    const description = String(cert.description || '').trim();
    return `
      <article class="rb-cert-card card-surface">
        <header class="rb-cert-card-header">
          <div class="rb-cert-card-heading">
            <h3 class="rb-cert-name">${esc(cert.certificationName)}</h3>
            <p class="rb-cert-org">${esc(cert.issuingOrganization)}</p>
          </div>
          <div class="rb-cert-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary rb-cert-action" data-rb-cert-edit="${esc(cert.id)}" title="Edit certification" aria-label="Edit ${esc(cert.certificationName)}">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger rb-cert-action" data-rb-cert-delete="${esc(cert.id)}" title="Delete certification" aria-label="Delete ${esc(cert.certificationName)}">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </header>
        ${dates ? `<div class="rb-cert-dates"><i class="bi bi-calendar3" aria-hidden="true"></i>${esc(dates)}</div>` : ''}
        ${credentialId ? `<div class="rb-cert-id"><i class="bi bi-shield-check" aria-hidden="true"></i><span>ID:</span> <code>${esc(credentialId)}</code></div>` : ''}
        ${description ? `<p class="rb-cert-desc">${esc(description)}</p>` : ''}
        ${credentialUrl ? `<footer class="rb-cert-card-footer"><a class="btn btn-sm btn-outline-primary rb-cert-link-btn" href="${esc(credentialUrl)}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>View Credential</a></footer>` : ''}
      </article>`;
  }

  function certificationsCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading certifications…</p>`;
    }

    const count = state.certifications.length;
    const complete = count >= CERT_COMPLETE_MIN;
    const badge = complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const editing = !!state.certificationsEditingId;
    const formOpen = state.certificationsFormOpen || editing;
    const editingCert = state.certifications.find((c) => c.id === state.certificationsEditingId) || null;
    const msg = state.certificationsMessage
      ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.certificationsMessage)}</div>`
      : '';
    const recPct = Math.min(100, Math.round((count / CERT_RECOMMENDED) * 100));

    const formHtml = `
      ${msg}
      <div class="rb-cert-form">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertName">Certification Name *</label>
            <input class="form-control" id="rbCertName" maxlength="${CERT_NAME_MAX}" value="${esc(editingCert ? editingCert.certificationName : '')}" data-rb-cert-name />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertOrg">Issuing Organization *</label>
            <input class="form-control" id="rbCertOrg" maxlength="${CERT_ORG_MAX}" value="${esc(editingCert ? editingCert.issuingOrganization : '')}" data-rb-cert-org />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertIssue">Issue Date *</label>
            <input type="date" class="form-control" id="rbCertIssue" value="${esc(editingCert && editingCert.issueDate ? editingCert.issueDate : '')}" data-rb-cert-issue required />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertExpiry">Expiry Date</label>
            <input type="date" class="form-control" id="rbCertExpiry" value="${esc(editingCert && editingCert.expiryDate ? editingCert.expiryDate : '')}" data-rb-cert-expiry />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertId">Credential ID</label>
            <input class="form-control" id="rbCertId" maxlength="100" placeholder="Optional" value="${esc(editingCert && editingCert.credentialId ? editingCert.credentialId : '')}" data-rb-cert-id />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbCertUrl">Credential URL</label>
            <input type="url" class="form-control" id="rbCertUrl" maxlength="500" placeholder="https://..." value="${esc(editingCert && editingCert.credentialUrl ? editingCert.credentialUrl : '')}" data-rb-cert-url />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbCertDesc">Description</label>
            <textarea class="form-control" id="rbCertDesc" rows="3" maxlength="${CERT_DESC_MAX}" placeholder="Optional notes about this certification" data-rb-cert-desc>${esc(editingCert && editingCert.description ? editingCert.description : '')}</textarea>
          </div>
          <div class="col-12 d-flex gap-2 justify-content-end mt-1">
            <button type="button" class="btn btn-outline-secondary" data-rb-cert-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-rb-cert-save>${editing ? 'Save Certification' : 'Add Certification'}</button>
          </div>
        </div>
      </div>`;

    const emptyHtml = `
      <div class="rb-cert-empty">
        <div class="rb-cert-empty-icon" aria-hidden="true"><i class="bi bi-award"></i></div>
        <p class="rb-cert-empty-title mb-3">No certifications added yet</p>
        <button type="button" class="btn btn-primary rb-cert-add-btn" data-rb-cert-add><i class="bi bi-plus-lg me-1"></i>Add Certification</button>
      </div>`;

    const addBtnHtml = formOpen
      ? ''
      : `<div class="rb-cert-add-wrap">
           <button type="button" class="btn btn-primary rb-cert-add-btn" data-rb-cert-add><i class="bi bi-plus-lg me-1"></i>Add Certification</button>
         </div>`;

    const listHtml = count
      ? `<div class="rb-cert-list">${state.certifications.map(certificationCardItem).join('')}</div>${addBtnHtml}`
      : (formOpen ? '' : emptyHtml);

    return `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-bold mb-0">Certifications</h6>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Include courses, certifications, workshops, trainings, MOOCs, and professional credentials relevant to your academic and career goals.
      </div>
      <div class="rb-cert-summary mb-3">
        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
          <div class="fw-semibold">Certifications Added: ${count}</div>
          <div class="small text-muted-2">Recommended: ${CERT_RECOMMENDED}+ Certifications</div>
        </div>
        <div class="rb-skill-rec-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${CERT_RECOMMENDED}" aria-valuenow="${count}" aria-label="Recommended certifications">
          <div class="rb-skill-rec-fill" style="width:${recPct}%"></div>
        </div>
      </div>
      ${listHtml}
      ${formOpen ? formHtml : ''}`;
  }

  function activityTypeBadgeClass(type) {
    const map = {
      Achievement: 'success',
      Leadership: 'info',
      'Club Membership': 'info',
      'Professional Membership': 'warning',
      'Volunteer Work': 'success',
      Sports: 'warning',
      'Arts & Culture': 'info',
      'Event Coordination': 'warning',
      Competition: 'success',
      'Community Service': 'success',
      Other: 'muted',
    };
    return map[type] || 'muted';
  }

  function formatActivityDate(dateStr) {
    const raw = String(dateStr || '').trim();
    if (!raw) return '';
    const parts = raw.split('-');
    if (parts.length < 2) return raw;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = parseInt(parts[1], 10);
    const year = parts[0];
    if (month >= 1 && month <= 12) return months[month - 1] + ' ' + year;
    return raw;
  }

  function activityCardItem(activity) {
    const typeClass = activityTypeBadgeClass(activity.activityType);
    const org = String(activity.organization || '').trim();
    const date = formatActivityDate(activity.activityDate);
    return `
      <article class="rb-act-card card-surface">
        <header class="rb-act-card-header">
          <div class="rb-act-card-heading">
            <h3 class="rb-act-title">${esc(activity.title)}</h3>
            <div class="rb-act-meta">
              <span class="badge-soft ${typeClass} rb-act-type-badge">${esc(activity.activityType)}</span>
              ${org ? `<span class="rb-act-org"><i class="bi bi-building" aria-hidden="true"></i>${esc(org)}</span>` : ''}
              ${date ? `<span class="rb-act-date"><i class="bi bi-calendar3" aria-hidden="true"></i>${esc(date)}</span>` : ''}
            </div>
          </div>
          <div class="rb-act-card-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary rb-act-action" data-rb-act-edit="${esc(activity.id)}" title="Edit entry" aria-label="Edit ${esc(activity.title)}">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger rb-act-action" data-rb-act-delete="${esc(activity.id)}" title="Delete entry" aria-label="Delete ${esc(activity.title)}">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </header>
        <p class="rb-act-desc">${esc(activity.description)}</p>
      </article>`;
  }

  function activitiesCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Loading activities…</p>`;
    }

    const count = state.activities.length;
    const complete = count >= ACT_COMPLETE_MIN;
    const badge = complete
      ? '<span class="badge-soft success">Completed</span>'
      : '<span class="badge-soft warning">Incomplete</span>';
    const editing = !!state.activitiesEditingId;
    const formOpen = state.activitiesFormOpen || editing;
    const editingAct = state.activities.find((a) => a.id === state.activitiesEditingId) || null;
    const msg = state.activitiesMessage
      ? `<div class="alert alert-warning py-2 small mb-3" role="alert">${esc(state.activitiesMessage)}</div>`
      : '';
    const recPct = Math.min(100, Math.round((count / ACT_RECOMMENDED) * 100));

    const typeOpts = ACT_TYPES.map((type) => {
      const selected = editingAct && editingAct.activityType === type ? ' selected' : '';
      return `<option value="${esc(type)}"${selected}>${esc(type)}</option>`;
    }).join('');

    const formHtml = `
      ${msg}
      <div class="rb-act-form">
        <div class="row g-2">
          <div class="col-md-8">
            <label class="form-label small fw-semibold" for="rbActTitle">Title *</label>
            <input class="form-control" id="rbActTitle" maxlength="${ACT_TITLE_MAX}" value="${esc(editingAct ? editingAct.title : '')}" data-rb-act-title />
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="rbActType">Activity Type *</label>
            <select class="form-select" id="rbActType" data-rb-act-type>
              <option value="">Select type</option>
              ${typeOpts}
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbActOrg">Organization</label>
            <input class="form-control" id="rbActOrg" maxlength="150" placeholder="Optional" value="${esc(editingAct && editingAct.organization ? editingAct.organization : '')}" data-rb-act-org />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="rbActDate">Activity Date</label>
            <input type="date" class="form-control" id="rbActDate" value="${esc(editingAct && editingAct.activityDate ? editingAct.activityDate : '')}" data-rb-act-date />
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rbActDesc">Description *</label>
            <textarea class="form-control" id="rbActDesc" rows="4" maxlength="${ACT_DESC_MAX}" data-rb-act-desc>${esc(editingAct ? editingAct.description : '')}</textarea>
            <div class="form-text">Minimum ${ACT_DESC_MIN} characters.</div>
          </div>
          <div class="col-12 d-flex gap-2 justify-content-end mt-1">
            <button type="button" class="btn btn-outline-secondary" data-rb-act-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-rb-act-save>${editing ? 'Save Entry' : 'Add Entry'}</button>
          </div>
        </div>
      </div>`;

    const emptyHtml = `
      <div class="rb-act-empty">
        <div class="rb-act-empty-icon" aria-hidden="true"><i class="bi bi-trophy"></i></div>
        <p class="rb-act-empty-title mb-3">No achievements or activities added yet</p>
        <button type="button" class="btn btn-primary rb-act-add-btn" data-rb-act-add><i class="bi bi-plus-lg me-1"></i>Add Entry</button>
      </div>`;

    const addBtnHtml = formOpen
      ? ''
      : `<div class="rb-act-add-wrap">
           <button type="button" class="btn btn-primary rb-act-add-btn" data-rb-act-add><i class="bi bi-plus-lg me-1"></i>Add Entry</button>
         </div>`;

    const listHtml = count
      ? `<div class="rb-act-list">${state.activities.map(activityCardItem).join('')}</div>${addBtnHtml}`
      : (formOpen ? '' : emptyHtml);

    return `
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-bold mb-0">Achievements, Leadership & Activities</h6>
        ${badge}
      </div>
      <div class="alert alert-info py-2 small mb-3" role="note">
        Include leadership roles, volunteer work, club memberships, competitions, sports, cultural activities, event coordination, and notable achievements.
      </div>
      <div class="rb-act-summary mb-3">
        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
          <div class="fw-semibold">Activities Added: ${count}</div>
          <div class="small text-muted-2">Recommended: ${ACT_RECOMMENDED}+ Entries</div>
        </div>
        <div class="rb-skill-rec-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${ACT_RECOMMENDED}" aria-valuenow="${count}" aria-label="Recommended activities">
          <div class="rb-skill-rec-fill" style="width:${recPct}%"></div>
        </div>
      </div>
      ${listHtml}
      ${formOpen ? formHtml : ''}`;
  }

  function resumeSection(title, bodyHtml) {
    if (!bodyHtml) return '';
    return `
      <section class="rb-resume-section">
        <h2 class="rb-resume-h2">${esc(title)}</h2>
        <hr class="rb-resume-rule" aria-hidden="true" />
        ${bodyHtml}
      </section>`;
  }

  function contactLinkText(raw, kind) {
    const value = String(raw || '').trim();
    if (!value) return '';
    let label = value.replace(/^https?:\/\//i, '').replace(/\/$/, '');
    if (kind === 'linkedin' && !/linkedin\.com/i.test(label)) {
      label = 'linkedin.com/in/' + value.replace(/^@/, '').replace(/^\/+/, '');
    } else if (kind === 'github' && !/github\.com/i.test(label)) {
      label = 'github.com/' + value.replace(/^@/, '').replace(/^\/+/, '');
    }
    return esc(label);
  }

  function previewContactLine() {
    const personal = state.personal || {};
    const links = state.contactLinks || {};
    const parts = [];
    const mobile = String(personal.mobile || '').trim();
    const email = firstText(personal.personalEmail, personal.collegeEmail);
    const linkedin = contactLinkText(links.linkedinUrl, 'linkedin');
    const github = contactLinkText(links.githubUrl, 'github');
    const website = contactLinkText(links.websiteUrl, 'website');
    if (mobile) parts.push(esc(mobile));
    if (email) parts.push(esc(email));
    if (linkedin) parts.push(linkedin);
    if (github) parts.push(github);
    if (website) parts.push(website);
    return parts.join(' | ');
  }

  function previewBulletsHtml(text) {
    const raw = String(text || '').trim();
    if (!raw) return '';
    const lines = raw
      .split(/\n+/)
      .map((line) => line.replace(/^[•\-\*\u2022]\s*/, '').trim())
      .filter(Boolean);
    const items = lines.length ? lines : [raw];
    return `<ul class="rb-resume-bullets">${items.map((line) => `<li>${esc(line)}</li>`).join('')}</ul>`;
  }

  function previewEducationScore(row) {
    const score = String(row.score || '').trim();
    if (!score) return '';
    if (/CGPA/i.test(score)) {
      const num = score.replace(/\s*CGPA\s*/i, '').trim();
      return num ? 'CGPA: ' + num : score;
    }
    if (/%\s*$/.test(score)) return score;
    return score;
  }

  /**
   * Format education dates for Resume Preview from THIS record only.
   * Source field: qualifications[].monthYear (mapped to row.year).
   * Never invents start/end years from course name or other rows.
   */
  function previewEducationDuration(row) {
    const raw = String(row.year || '').trim();
    if (!raw) return '';

    // Explicit full range already on this record: "2022 - 2025" / "2022–2025"
    const fullRange = raw.match(/((?:19|20)\d{2})\s*[-–—−]\s*((?:19|20)\d{2})/);
    if (fullRange) return fullRange[1] + ' - ' + fullRange[2];

    // Batch-style range on this record only: e.g. "MCA2025-27-S3" → 2025 - 2027
    const shortRange = raw.match(/((?:19|20)\d{2})\s*[-–—−]\s*(\d{2})(?!\d)/);
    if (shortRange) {
      const start = Number(shortRange[1]);
      const endTwo = Number(shortRange[2]);
      const end = Math.floor(start / 100) * 100 + endTwo;
      if (end >= start) return start + ' - ' + end;
    }

    // Single year (or first year found) — display only what exists; do not invent a range
    const yearOnly = passingYear(raw);
    if (yearOnly > 0) return String(yearOnly);

    return raw;
  }

  function previewTechnicalSkillsHtml() {
    const techCats = [
      { key: 'Technical', label: 'Programming Languages' },
      { key: 'Tools', label: 'Tools & Platforms' },
      { key: 'Domain Skills', label: 'Domain Skills' },
      { key: 'Languages', label: 'Languages' },
    ];
    const lines = techCats.map(({ key, label }) => {
      const items = state.skills.filter((s) => s.skillCategory === key);
      if (!items.length) return '';
      const names = items.map((s) => esc(s.skillName)).join(', ');
      return `<div class="rb-resume-skill-line"><span class="rb-resume-skill-label">${esc(label)}:</span> ${names}</div>`;
    }).filter(Boolean).join('');
    if (!lines) return '';
    return resumeSection('Technical Skills', `<div class="rb-resume-skills">${lines}</div>`);
  }

  function previewSoftSkillsHtml() {
    const items = state.skills.filter((s) => s.skillCategory === 'Soft Skills');
    if (!items.length) return '';
    const names = items.map((s) => esc(s.skillName)).join(', ');
    return resumeSection('Soft Skills', `<div class="rb-resume-skill-line">${names}</div>`);
  }

  function previewEducationHtml() {
    if (!state.education.length) return '';
    const items = state.education.map((row) => {
      const score = previewEducationScore(row);
      const duration = previewEducationDuration(row);
      const institution = [row.institution, row.university].filter(Boolean).join(', ');
      return `
        <div class="rb-resume-edu">
          <div class="rb-resume-edu-row">
            <div class="rb-resume-edu-degree">${esc(row.qualification || 'Qualification')}</div>
            <div class="rb-resume-edu-year rb-resume-right-bold">${duration ? esc(duration) : ''}</div>
          </div>
          <div class="rb-resume-edu-row">
            <div class="rb-resume-edu-inst">${institution ? esc(institution) : ''}</div>
            <div class="rb-resume-edu-score rb-resume-right-bold">${score ? esc(score) : ''}</div>
          </div>
        </div>`;
    }).join('');
    return resumeSection('Education', items);
  }

  function previewProjectsHtml() {
    if (!state.projects.length) return '';
    const items = state.projects.map((p) => {
      const tech = String(p.technologiesUsed || '').trim();
      return `
        <div class="rb-resume-entry">
          <div class="rb-resume-entry-title">${esc(p.projectTitle)}</div>
          ${tech ? `<div class="rb-resume-tech">${esc(tech)}</div>` : ''}
          ${previewBulletsHtml(p.projectDescription)}
        </div>`;
    }).join('');
    return resumeSection('Projects', items);
  }

  function previewExperienceHtml() {
    if (!state.experiences.length) return '';
    const sorted = state.experiences.slice().sort((a, b) => {
      const aKey = String(a.startDate || '');
      const bKey = String(b.startDate || '');
      return bKey.localeCompare(aKey);
    });
    const items = sorted.map((exp) => {
      const dates = formatExperienceDateRange(exp);
      return `
        <div class="rb-resume-entry">
          <div class="rb-resume-entry-top">
            <div class="rb-resume-entry-title">${esc(exp.positionTitle)}</div>
            ${dates ? `<div class="rb-resume-entry-right rb-resume-right-bold">${esc(dates)}</div>` : ''}
          </div>
          <div class="rb-resume-entry-org">${esc(exp.organizationName)}</div>
          ${previewBulletsHtml(exp.description)}
        </div>`;
    }).join('');
    return resumeSection('Professional Experience', items);
  }

  function previewCertificationsHtml() {
    if (!state.certifications.length) return '';
    const items = state.certifications.map((c) => {
      const issued = formatCertDate(c.issueDate);
      const parts = [c.issuingOrganization, issued].filter(Boolean).join(', ');
      const line = parts
        ? `${esc(c.certificationName)} (${esc(parts)})`
        : esc(c.certificationName);
      return `<li>${line}</li>`;
    }).join('');
    return resumeSection('Certifications', `<ul class="rb-resume-list">${items}</ul>`);
  }

  function previewAchievementsHtml() {
    const rows = state.activities.filter((a) => a.activityType === 'Achievement');
    if (!rows.length) return '';
    const items = rows.map((a) => {
      const org = String(a.organization || '').trim();
      const line = org ? `${esc(a.title)} — ${esc(org)}` : esc(a.title);
      return `<li>${line}</li>`;
    }).join('');
    return resumeSection('Achievements', `<ul class="rb-resume-list">${items}</ul>`);
  }

  function previewActivitiesHtml() {
    const rows = state.activities.filter((a) => a.activityType !== 'Achievement');
    if (!rows.length) return '';
    const items = rows.map((a) => {
      const org = String(a.organization || '').trim();
      const date = formatActivityDate(a.activityDate);
      const meta = [a.activityType, org, date].filter(Boolean).map((v) => esc(v)).join(' · ');
      return `<li><span class="rb-resume-strong">${esc(a.title)}</span>${meta ? ` — ${meta}` : ''}</li>`;
    }).join('');
    return resumeSection('Activities / Leadership', `<ul class="rb-resume-list">${items}</ul>`);
  }

  function buildResumeDocumentHtml() {
    const personal = state.personal || {};
    const name = String(personal.fullName || '').trim();
    const contact = previewContactLine();
    const objective = String(state.objectiveText || '').trim();

    const sections = [
      (name || contact) ? `
        <header class="rb-resume-header">
          ${name ? `<h1 class="rb-resume-name">${esc(name)}</h1>` : ''}
          ${contact ? `<p class="rb-resume-contact">${contact}</p>` : ''}
        </header>` : '',
      objective ? resumeSection('Career Objective', `<p class="rb-resume-para">${esc(objective)}</p>`) : '',
      previewEducationHtml(),
      previewTechnicalSkillsHtml(),
      previewExperienceHtml(),
      previewProjectsHtml(),
      previewAchievementsHtml(),
      previewCertificationsHtml(),
      previewActivitiesHtml(),
      previewSoftSkillsHtml(),
    ].filter(Boolean);

    if (!sections.length) {
      return `
        <div class="rb-resume-empty-doc">
          <p>Complete sections in Resume Builder to see your resume preview.</p>
        </div>`;
    }

    return `<article class="rb-resume-doc" id="rbResumePrintRoot">${sections.join('')}</article>`;
  }

  function previewPageHtml(loading) {
    const body = loading
      ? '<p class="small text-muted-2 mb-0 p-4">Preparing resume preview…</p>'
      : `
        <p class="rb-preview-notice">This preview represents how your resume will appear when exported.</p>
        <div class="rb-preview-stage">
          <div class="rb-preview-paper">
            ${buildResumeDocumentHtml()}
          </div>
        </div>`;
    return `
      <div class="rb-preview-page" data-rb-preview-page>
        <div class="rb-preview-page-bar">
          <button type="button" class="btn btn-outline-secondary" data-rb-preview-back>
            <i class="bi bi-arrow-left me-1"></i>Back to Resume Builder
          </button>
          <button type="button" class="btn btn-primary" data-rb-preview-print>
            <i class="bi bi-printer me-1"></i>Print Preview
          </button>
        </div>
        ${body}
      </div>`;
  }

  function previewCardBody(loading) {
    if (loading) {
      return `<p class="small text-muted-2 mb-0">Preparing resume preview…</p>`;
    }
    return `
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
          <h6 class="fw-bold mb-1">Resume Preview</h6>
          <p class="small text-muted-2 mb-0">Open a full-page ATS preview of your resume.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 rb-preview-toolbar">
          <button type="button" class="btn btn-sm btn-primary" data-rb-live-preview>
            <i class="bi bi-eye me-1"></i>Live Preview
          </button>
        </div>
      </div>
      <div class="alert alert-info py-2 small mb-0" role="note">
        This preview represents how your resume will appear when exported.
      </div>`;
  }

  function renderPreviewCard() {
    if (state.previewRefreshing) return;
    if (state.previewMode) {
      const page = root.querySelector('[data-rb-preview-page]');
      if (page) page.outerHTML = previewPageHtml(false);
      return;
    }
    const card = root.querySelector('[data-rb-card="preview"]');
    if (!card) return;
    card.innerHTML = previewCardBody(false);
  }

  function openLivePreview() {
    state.previewMode = true;
    const dash = root.querySelector('[data-rb-dashboard]');
    let page = root.querySelector('[data-rb-preview-page]');
    if (dash) dash.classList.add('d-none');
    if (!page) {
      root.insertAdjacentHTML('beforeend', previewPageHtml(false));
      page = root.querySelector('[data-rb-preview-page]');
    } else {
      page.outerHTML = previewPageHtml(false);
    }
    root.querySelector('[data-rb-preview-page]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function closeLivePreview() {
    state.previewMode = false;
    const page = root.querySelector('[data-rb-preview-page]');
    if (page) page.remove();
    const dash = root.querySelector('[data-rb-dashboard]');
    if (dash) dash.classList.remove('d-none');
  }

  function scrollToPreview() {
    openLivePreview();
  }

  async function refreshPreviewData() {
    state.previewRefreshing = true;
    if (state.previewMode) {
      const page = root.querySelector('[data-rb-preview-page]');
      if (page) page.outerHTML = previewPageHtml(true);
    }
    try {
      await Promise.all([
        loadPersonalFromProfile(),
        loadContactLinks(),
        loadCareerObjective(),
        loadSkills(),
        loadProjects(),
        loadExperience(),
        loadCertifications(),
        loadActivities(),
      ]);
    } finally {
      state.previewRefreshing = false;
      if (state.previewMode) {
        const page = root.querySelector('[data-rb-preview-page]');
        if (page) page.outerHTML = previewPageHtml(false);
        else openLivePreview();
      } else {
        renderPreviewCard();
      }
    }
  }

  function printResumePreview() {
    if (!state.previewMode) openLivePreview();
    document.body.classList.add('rb-printing-resume');
    window.setTimeout(() => {
      window.print();
      window.setTimeout(() => document.body.classList.remove('rb-printing-resume'), 300);
    }, 50);
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

    if (section.id === 'skills') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="skills">
            ${skillsCardBody(true)}
          </div>
        </div>`;
    }

    if (section.id === 'projects') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="projects">
            ${projectsCardBody(true)}
          </div>
        </div>`;
    }

    if (section.id === 'internships') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="experience">
            ${experienceCardBody(true)}
          </div>
        </div>`;
    }

    if (section.id === 'certifications') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="certifications">
            ${certificationsCardBody(true)}
          </div>
        </div>`;
    }

    if (section.id === 'achievements') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="activities">
            ${activitiesCardBody(true)}
          </div>
        </div>`;
    }

    if (section.id === 'preview') {
      return `
        <div class="col-12">
          <div class="card-surface p-3 p-md-4 rb-section-card" data-rb-card="preview">
            ${previewCardBody(true)}
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
      <div data-rb-dashboard>
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
          <div class="rb-completion-actions d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary" data-rb-live-preview>
              <i class="bi bi-eye me-1"></i>Live Preview
            </button>
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
      </div>
    `;

    if (root.dataset.rbEventsBound === '1') return;
    root.dataset.rbEventsBound = '1';

    root.addEventListener('click', (event) => {
      if (event.target.closest('[data-rb-preview-back]')) {
        closeLivePreview();
        return;
      }
      if (event.target.closest('[data-rb-live-preview]') || event.target.closest('[data-rb-section="preview"]')) {
        openLivePreview();
        return;
      }
      if (event.target.closest('[data-rb-preview-refresh]')) {
        refreshPreviewData();
        return;
      }
      if (event.target.closest('[data-rb-preview-print]')) {
        printResumePreview();
        return;
      }
      if (event.target.closest('[data-rb-edit-profile]')) {
        goToExistingProfile();
        return;
      }
      if (event.target.closest('[data-rb-link-save]')) {
        saveContactLinks();
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
        return;
      }
      if (event.target.closest('[data-rb-skill-save]')) {
        saveSkill();
        return;
      }
      if (event.target.closest('[data-rb-skill-add]')) {
        state.skillsFormOpen = true;
        state.skillsEditingId = '';
        state.skillsMessage = '';
        renderSkillsCard();
        return;
      }
      if (event.target.closest('[data-rb-skill-cancel]')) {
        state.skillsEditingId = '';
        state.skillsFormOpen = false;
        state.skillsMessage = '';
        renderSkillsCard();
        return;
      }
      const editSkill = event.target.closest('[data-rb-skill-edit]');
      if (editSkill) {
        state.skillsEditingId = editSkill.getAttribute('data-rb-skill-edit') || '';
        state.skillsFormOpen = true;
        state.skillsMessage = '';
        renderSkillsCard();
        return;
      }
      const delSkill = event.target.closest('[data-rb-skill-delete]');
      if (delSkill) {
        deleteSkill(delSkill.getAttribute('data-rb-skill-delete') || '');
        return;
      }
      if (event.target.closest('[data-rb-project-save]')) {
        saveProject();
        return;
      }
      if (event.target.closest('[data-rb-project-add]')) {
        state.projectsFormOpen = true;
        state.projectsEditingId = '';
        state.projectsMessage = '';
        renderProjectsCard();
        return;
      }
      if (event.target.closest('[data-rb-project-cancel]')) {
        state.projectsEditingId = '';
        state.projectsFormOpen = false;
        state.projectsMessage = '';
        renderProjectsCard();
        return;
      }
      const editProject = event.target.closest('[data-rb-project-edit]');
      if (editProject) {
        state.projectsEditingId = editProject.getAttribute('data-rb-project-edit') || '';
        state.projectsFormOpen = true;
        state.projectsMessage = '';
        renderProjectsCard();
        return;
      }
      const delProject = event.target.closest('[data-rb-project-delete]');
      if (delProject) {
        deleteProject(delProject.getAttribute('data-rb-project-delete') || '');
        return;
      }
      if (event.target.closest('[data-rb-exp-save]')) {
        saveExperience();
        return;
      }
      if (event.target.closest('[data-rb-exp-add]')) {
        state.experiencesFormOpen = true;
        state.experiencesEditingId = '';
        state.experiencesMessage = '';
        renderExperienceCard();
        return;
      }
      if (event.target.closest('[data-rb-exp-cancel]')) {
        state.experiencesEditingId = '';
        state.experiencesFormOpen = false;
        state.experiencesMessage = '';
        renderExperienceCard();
        return;
      }
      const editExp = event.target.closest('[data-rb-exp-edit]');
      if (editExp) {
        state.experiencesEditingId = editExp.getAttribute('data-rb-exp-edit') || '';
        state.experiencesFormOpen = true;
        state.experiencesMessage = '';
        renderExperienceCard();
        return;
      }
      const delExp = event.target.closest('[data-rb-exp-delete]');
      if (delExp) {
        deleteExperience(delExp.getAttribute('data-rb-exp-delete') || '');
        return;
      }
      if (event.target.closest('[data-rb-cert-save]')) {
        saveCertification();
        return;
      }
      if (event.target.closest('[data-rb-cert-add]')) {
        state.certificationsFormOpen = true;
        state.certificationsEditingId = '';
        state.certificationsMessage = '';
        renderCertificationsCard();
        return;
      }
      if (event.target.closest('[data-rb-cert-cancel]')) {
        state.certificationsEditingId = '';
        state.certificationsFormOpen = false;
        state.certificationsMessage = '';
        renderCertificationsCard();
        return;
      }
      const editCert = event.target.closest('[data-rb-cert-edit]');
      if (editCert) {
        state.certificationsEditingId = editCert.getAttribute('data-rb-cert-edit') || '';
        state.certificationsFormOpen = true;
        state.certificationsMessage = '';
        renderCertificationsCard();
        return;
      }
      const delCert = event.target.closest('[data-rb-cert-delete]');
      if (delCert) {
        deleteCertification(delCert.getAttribute('data-rb-cert-delete') || '');
        return;
      }
      if (event.target.closest('[data-rb-act-save]')) {
        saveActivity();
        return;
      }
      if (event.target.closest('[data-rb-act-add]')) {
        state.activitiesFormOpen = true;
        state.activitiesEditingId = '';
        state.activitiesMessage = '';
        renderActivitiesCard();
        return;
      }
      if (event.target.closest('[data-rb-act-cancel]')) {
        state.activitiesEditingId = '';
        state.activitiesFormOpen = false;
        state.activitiesMessage = '';
        renderActivitiesCard();
        return;
      }
      const editAct = event.target.closest('[data-rb-act-edit]');
      if (editAct) {
        state.activitiesEditingId = editAct.getAttribute('data-rb-act-edit') || '';
        state.activitiesFormOpen = true;
        state.activitiesMessage = '';
        renderActivitiesCard();
        return;
      }
      const delAct = event.target.closest('[data-rb-act-delete]');
      if (delAct) {
        deleteActivity(delAct.getAttribute('data-rb-act-delete') || '');
      }
    });

    root.addEventListener('change', (event) => {
      if (event.target.matches('[data-rb-exp-current]')) {
        syncExperienceEndDateField();
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
    state.personal = fields && typeof fields === 'object' ? fields : {};
    state.personalComplete = status.complete;
    renderPersonalCard();
    updateCompletionUi();
    renderPreviewCard();
  }

  function applyEducation(rows) {
    const status = educationStatus(rows);
    state.education = Array.isArray(rows) ? rows : [];
    state.educationComplete = status.complete;
    const card = root.querySelector('[data-rb-card="education"]');
    if (card) card.innerHTML = educationCardBody(rows, status, false);
    updateCompletionUi();
    renderPreviewCard();
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
    renderPreviewCard();
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

  function readContactLinkForm() {
    const linkedin = root.querySelector('[data-rb-link-linkedin]');
    const github = root.querySelector('[data-rb-link-github]');
    const website = root.querySelector('[data-rb-link-website]');
    return {
      linkedinUrl: linkedin ? String(linkedin.value || '').trim() : '',
      githubUrl: github ? String(github.value || '').trim() : '',
      websiteUrl: website ? String(website.value || '').trim() : '',
    };
  }

  function applyContactLinks(links) {
    state.contactLinks = {
      linkedinUrl: String(links?.linkedinUrl || '').trim(),
      githubUrl: String(links?.githubUrl || '').trim(),
      websiteUrl: String(links?.websiteUrl || '').trim(),
    };
    renderPersonalCard();
    renderPreviewCard();
  }

  async function loadContactLinks() {
    if (!canUseResumeBuilderApi()) {
      applyContactLinks({});
      return;
    }
    try {
      const res = await api('/student/resume-builder/contact-links', { skipAuthRedirect: true });
      if (res?.success) {
        applyContactLinks(res.data || {});
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applyContactLinks({});
  }

  async function saveContactLinks() {
    const form = readContactLinkForm();
    if (!canUseResumeBuilderApi()) {
      state.contactLinksMessage = 'Sign in to save professional links.';
      renderPersonalCard();
      return;
    }
    try {
      const res = await api('/student/resume-builder/contact-links', {
        method: 'PUT',
        body: form,
        skipAuthRedirect: true,
      });
      if (res?.success) {
        state.contactLinksMessage = '';
        applyContactLinks(res.data || form);
        return;
      }
      state.contactLinksMessage = res?.message || 'Could not save professional links.';
    } catch (_err) {
      state.contactLinksMessage = 'Could not save professional links.';
    }
    renderPersonalCard();
  }

  function renderSkillsCard() {
    const card = root.querySelector('[data-rb-card="skills"]');
    if (card) card.innerHTML = skillsCardBody(false);
    updateCompletionUi();
  }

  function applySkills(skills) {
    state.skills = Array.isArray(skills) ? skills.filter((s) => s && s.skillName) : [];
    renderSkillsCard();
    renderPreviewCard();
  }

  function canUseResumeBuilderApi() {
    return typeof api === 'function'
      && typeof Auth !== 'undefined'
      && typeof Auth.hasRealAuth === 'function'
      && Auth.hasRealAuth();
  }

  async function loadSkills() {
    if (!canUseResumeBuilderApi()) {
      applySkills([]);
      return;
    }
    try {
      const res = await api('/student/resume-builder/skills', { skipAuthRedirect: true });
      if (res?.success) {
        applySkills(res.data?.skills || []);
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applySkills([]);
  }

  function readSkillForm() {
    const nameEl = root.querySelector('[data-rb-skill-name]');
    const catEl = root.querySelector('[data-rb-skill-category]');
    return {
      skillName: nameEl ? String(nameEl.value || '').trim() : '',
      skillCategory: catEl ? String(catEl.value || '').trim() : '',
    };
  }

  async function saveSkill() {
    const form = readSkillForm();
    if (!skillNameValid(form.skillName)) {
      state.skillsMessage = 'Skill name must be between ' + SKILL_MIN + ' and ' + SKILL_MAX + ' characters.';
      renderSkillsCard();
      const nameEl = root.querySelector('[data-rb-skill-name]');
      if (nameEl) nameEl.value = form.skillName;
      const catEl = root.querySelector('[data-rb-skill-category]');
      if (catEl) catEl.value = form.skillCategory;
      return;
    }
    if (!SKILL_CATEGORIES.includes(form.skillCategory)) {
      state.skillsMessage = 'Select a skill category.';
      renderSkillsCard();
      const nameEl = root.querySelector('[data-rb-skill-name]');
      if (nameEl) nameEl.value = form.skillName;
      return;
    }
    const dup = state.skills.some((s) => s.id !== state.skillsEditingId
      && String(s.skillName || '').toLowerCase() === form.skillName.toLowerCase());
    if (dup) {
      state.skillsMessage = 'This skill is already added.';
      renderSkillsCard();
      const nameEl = root.querySelector('[data-rb-skill-name]');
      if (nameEl) nameEl.value = form.skillName;
      const catEl = root.querySelector('[data-rb-skill-category]');
      if (catEl) catEl.value = form.skillCategory;
      return;
    }

    if (!canUseResumeBuilderApi()) {
      state.skillsMessage = 'Sign in to save skills.';
      renderSkillsCard();
      return;
    }

    const editingId = state.skillsEditingId;
    try {
      const res = editingId
        ? await api('/student/resume-builder/skills/' + encodeURIComponent(editingId), {
            method: 'PUT',
            body: form,
            skipAuthRedirect: true,
          })
        : await api('/student/resume-builder/skills', {
            method: 'POST',
            body: form,
            skipAuthRedirect: true,
          });
      if (res?.success) {
        state.skillsEditingId = '';
        state.skillsFormOpen = false;
        state.skillsMessage = '';
        applySkills(res.data?.skills || []);
        return;
      }
      state.skillsMessage = res?.message || 'Could not save skill.';
      renderSkillsCard();
      const nameEl = root.querySelector('[data-rb-skill-name]');
      if (nameEl) nameEl.value = form.skillName;
      const catEl = root.querySelector('[data-rb-skill-category]');
      if (catEl) catEl.value = form.skillCategory;
    } catch (_err) {
      state.skillsMessage = 'Could not save skill.';
      renderSkillsCard();
    }
  }

  async function deleteSkill(id) {
    if (!id || !canUseResumeBuilderApi()) return;
    try {
      const res = await api('/student/resume-builder/skills/' + encodeURIComponent(id) + '/delete', {
        method: 'POST',
        skipAuthRedirect: true,
      });
      if (res?.success) {
        if (state.skillsEditingId === id) state.skillsEditingId = '';
        state.skillsMessage = '';
        applySkills(res.data?.skills || []);
        return;
      }
      state.skillsMessage = res?.message || 'Could not remove skill.';
      renderSkillsCard();
    } catch (_err) {
      state.skillsMessage = 'Could not remove skill.';
      renderSkillsCard();
    }
  }

  function renderProjectsCard() {
    const card = root.querySelector('[data-rb-card="projects"]');
    if (card) card.innerHTML = projectsCardBody(false);
    updateCompletionUi();
  }

  function applyProjects(projects) {
    state.projects = Array.isArray(projects) ? projects.filter((p) => p && p.projectTitle) : [];
    renderProjectsCard();
    renderPreviewCard();
  }

  async function loadProjects() {
    if (!canUseResumeBuilderApi()) {
      applyProjects([]);
      return;
    }
    try {
      const res = await api('/student/resume-builder/projects', { skipAuthRedirect: true });
      if (res?.success) {
        applyProjects(res.data?.projects || []);
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applyProjects([]);
  }

  function readProjectForm() {
    const titleEl = root.querySelector('[data-rb-project-title]');
    const typeEl = root.querySelector('[data-rb-project-type]');
    const techEl = root.querySelector('[data-rb-project-tech]');
    const descEl = root.querySelector('[data-rb-project-desc]');
    const linkEl = root.querySelector('[data-rb-project-link]');
    const startEl = root.querySelector('[data-rb-project-start]');
    const endEl = root.querySelector('[data-rb-project-end]');
    return {
      projectTitle: titleEl ? String(titleEl.value || '').trim() : '',
      projectType: typeEl ? String(typeEl.value || '').trim() : '',
      technologiesUsed: techEl ? String(techEl.value || '').trim() : '',
      projectDescription: descEl ? String(descEl.value || '').trim() : '',
      projectLink: linkEl ? String(linkEl.value || '').trim() : '',
      startDate: startEl ? String(startEl.value || '').trim() : '',
      endDate: endEl ? String(endEl.value || '').trim() : '',
    };
  }

  function restoreProjectForm(form) {
    const titleEl = root.querySelector('[data-rb-project-title]');
    const typeEl = root.querySelector('[data-rb-project-type]');
    const techEl = root.querySelector('[data-rb-project-tech]');
    const descEl = root.querySelector('[data-rb-project-desc]');
    const linkEl = root.querySelector('[data-rb-project-link]');
    const startEl = root.querySelector('[data-rb-project-start]');
    const endEl = root.querySelector('[data-rb-project-end]');
    if (titleEl) titleEl.value = form.projectTitle;
    if (typeEl) typeEl.value = form.projectType;
    if (techEl) techEl.value = form.technologiesUsed;
    if (descEl) descEl.value = form.projectDescription;
    if (linkEl) linkEl.value = form.projectLink;
    if (startEl) startEl.value = form.startDate;
    if (endEl) endEl.value = form.endDate;
  }

  function validateProjectForm(form) {
    const titleLen = form.projectTitle.length;
    if (titleLen < PROJECT_TITLE_MIN || titleLen > PROJECT_TITLE_MAX) {
      return 'Project title must be between ' + PROJECT_TITLE_MIN + ' and ' + PROJECT_TITLE_MAX + ' characters.';
    }
    if (!PROJECT_TYPES.includes(form.projectType)) {
      return 'Select a project type.';
    }
    const descLen = form.projectDescription.length;
    if (descLen < PROJECT_DESC_MIN || descLen > PROJECT_DESC_MAX) {
      return 'Description must be between ' + PROJECT_DESC_MIN + ' and ' + PROJECT_DESC_MAX + ' characters.';
    }
    if (form.projectLink) {
      try {
        const parsed = new URL(form.projectLink);
        if (!/^https?:$/i.test(parsed.protocol)) return 'Enter a valid project URL (including https://).';
      } catch (_err) {
        return 'Enter a valid project URL (including https://).';
      }
    }
    if (form.startDate && form.endDate && form.endDate < form.startDate) {
      return 'End date cannot be earlier than start date.';
    }
    return '';
  }

  async function saveProject() {
    const form = readProjectForm();
    const error = validateProjectForm(form);
    if (error) {
      state.projectsMessage = error;
      renderProjectsCard();
      restoreProjectForm(form);
      return;
    }
    if (!canUseResumeBuilderApi()) {
      state.projectsMessage = 'Sign in to save projects.';
      renderProjectsCard();
      return;
    }

    const editingId = state.projectsEditingId;
    try {
      const res = editingId
        ? await api('/student/resume-builder/projects/' + encodeURIComponent(editingId), {
            method: 'PUT',
            body: form,
            skipAuthRedirect: true,
          })
        : await api('/student/resume-builder/projects', {
            method: 'POST',
            body: form,
            skipAuthRedirect: true,
          });
      if (res?.success) {
        state.projectsEditingId = '';
        state.projectsFormOpen = false;
        state.projectsMessage = '';
        applyProjects(res.data?.projects || []);
        return;
      }
      state.projectsMessage = res?.message || 'Could not save project.';
      renderProjectsCard();
      restoreProjectForm(form);
    } catch (_err) {
      state.projectsMessage = 'Could not save project.';
      renderProjectsCard();
      restoreProjectForm(form);
    }
  }

  async function deleteProject(id) {
    if (!id || !canUseResumeBuilderApi()) return;
    try {
      const res = await api('/student/resume-builder/projects/' + encodeURIComponent(id) + '/delete', {
        method: 'POST',
        skipAuthRedirect: true,
      });
      if (res?.success) {
        if (state.projectsEditingId === id) state.projectsEditingId = '';
        state.projectsMessage = '';
        applyProjects(res.data?.projects || []);
        return;
      }
      state.projectsMessage = res?.message || 'Could not remove project.';
      renderProjectsCard();
    } catch (_err) {
      state.projectsMessage = 'Could not remove project.';
      renderProjectsCard();
    }
  }

  function renderExperienceCard() {
    const card = root.querySelector('[data-rb-card="experience"]');
    if (card) card.innerHTML = experienceCardBody(false);
    updateCompletionUi();
  }

  function syncExperienceEndDateField() {
    const current = root.querySelector('[data-rb-exp-current]');
    const end = root.querySelector('[data-rb-exp-end]');
    if (!current || !end) return;
    if (current.checked) {
      end.value = '';
      end.disabled = true;
    } else {
      end.disabled = false;
    }
  }

  function applyExperiences(experiences) {
    state.experiences = Array.isArray(experiences)
      ? experiences.filter((e) => e && e.organizationName)
      : [];
    renderExperienceCard();
    renderPreviewCard();
  }

  async function loadExperience() {
    if (!canUseResumeBuilderApi()) {
      applyExperiences([]);
      return;
    }
    try {
      const res = await api('/student/resume-builder/experience', { skipAuthRedirect: true });
      if (res?.success) {
        applyExperiences(res.data?.experiences || []);
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applyExperiences([]);
  }

  function readExperienceForm() {
    const orgEl = root.querySelector('[data-rb-exp-org]');
    const posEl = root.querySelector('[data-rb-exp-position]');
    const typeEl = root.querySelector('[data-rb-exp-type]');
    const locEl = root.querySelector('[data-rb-exp-location]');
    const descEl = root.querySelector('[data-rb-exp-desc]');
    const startEl = root.querySelector('[data-rb-exp-start]');
    const endEl = root.querySelector('[data-rb-exp-end]');
    const currentEl = root.querySelector('[data-rb-exp-current]');
    return {
      organizationName: orgEl ? String(orgEl.value || '').trim() : '',
      positionTitle: posEl ? String(posEl.value || '').trim() : '',
      experienceType: typeEl ? String(typeEl.value || '').trim() : '',
      location: locEl ? String(locEl.value || '').trim() : '',
      description: descEl ? String(descEl.value || '').trim() : '',
      startDate: startEl ? String(startEl.value || '').trim() : '',
      endDate: endEl ? String(endEl.value || '').trim() : '',
      currentlyWorking: !!(currentEl && currentEl.checked),
    };
  }

  function restoreExperienceForm(form) {
    const orgEl = root.querySelector('[data-rb-exp-org]');
    const posEl = root.querySelector('[data-rb-exp-position]');
    const typeEl = root.querySelector('[data-rb-exp-type]');
    const locEl = root.querySelector('[data-rb-exp-location]');
    const descEl = root.querySelector('[data-rb-exp-desc]');
    const startEl = root.querySelector('[data-rb-exp-start]');
    const endEl = root.querySelector('[data-rb-exp-end]');
    const currentEl = root.querySelector('[data-rb-exp-current]');
    if (orgEl) orgEl.value = form.organizationName;
    if (posEl) posEl.value = form.positionTitle;
    if (typeEl) typeEl.value = form.experienceType;
    if (locEl) locEl.value = form.location;
    if (descEl) descEl.value = form.description;
    if (startEl) startEl.value = form.startDate;
    if (endEl) endEl.value = form.endDate;
    if (currentEl) currentEl.checked = form.currentlyWorking;
    syncExperienceEndDateField();
  }

  function validateExperienceForm(form) {
    const orgLen = form.organizationName.length;
    if (orgLen < EXP_ORG_MIN || orgLen > EXP_ORG_MAX) {
      return 'Organization name must be between ' + EXP_ORG_MIN + ' and ' + EXP_ORG_MAX + ' characters.';
    }
    const posLen = form.positionTitle.length;
    if (posLen < EXP_POSITION_MIN || posLen > EXP_POSITION_MAX) {
      return 'Position must be between ' + EXP_POSITION_MIN + ' and ' + EXP_POSITION_MAX + ' characters.';
    }
    if (!EXP_TYPES.includes(form.experienceType)) {
      return 'Select an experience type.';
    }
    const descLen = form.description.length;
    if (descLen < EXP_DESC_MIN || descLen > EXP_DESC_MAX) {
      return 'Description must be between ' + EXP_DESC_MIN + ' and ' + EXP_DESC_MAX + ' characters.';
    }
    if (!form.startDate) {
      return 'Start date is required.';
    }
    if (form.currentlyWorking && form.endDate) {
      return 'Clear the end date when currently working here.';
    }
    if (!form.currentlyWorking && form.endDate && form.endDate < form.startDate) {
      return 'End date cannot be earlier than start date.';
    }
    return '';
  }

  async function saveExperience() {
    const form = readExperienceForm();
    const error = validateExperienceForm(form);
    if (error) {
      state.experiencesMessage = error;
      renderExperienceCard();
      restoreExperienceForm(form);
      return;
    }
    if (!canUseResumeBuilderApi()) {
      state.experiencesMessage = 'Sign in to save experience.';
      renderExperienceCard();
      return;
    }

    const payload = {
      organizationName: form.organizationName,
      positionTitle: form.positionTitle,
      experienceType: form.experienceType,
      location: form.location,
      description: form.description,
      startDate: form.startDate,
      endDate: form.currentlyWorking ? '' : form.endDate,
      currentlyWorking: form.currentlyWorking,
    };

    const editingId = state.experiencesEditingId;
    try {
      const res = editingId
        ? await api('/student/resume-builder/experience/' + encodeURIComponent(editingId), {
            method: 'PUT',
            body: payload,
            skipAuthRedirect: true,
          })
        : await api('/student/resume-builder/experience', {
            method: 'POST',
            body: payload,
            skipAuthRedirect: true,
          });
      if (res?.success) {
        state.experiencesEditingId = '';
        state.experiencesFormOpen = false;
        state.experiencesMessage = '';
        applyExperiences(res.data?.experiences || []);
        return;
      }
      state.experiencesMessage = res?.message || 'Could not save experience.';
      renderExperienceCard();
      restoreExperienceForm(form);
    } catch (_err) {
      state.experiencesMessage = 'Could not save experience.';
      renderExperienceCard();
      restoreExperienceForm(form);
    }
  }

  async function deleteExperience(id) {
    if (!id || !canUseResumeBuilderApi()) return;
    try {
      const res = await api('/student/resume-builder/experience/' + encodeURIComponent(id) + '/delete', {
        method: 'POST',
        skipAuthRedirect: true,
      });
      if (res?.success) {
        if (state.experiencesEditingId === id) state.experiencesEditingId = '';
        state.experiencesMessage = '';
        applyExperiences(res.data?.experiences || []);
        return;
      }
      state.experiencesMessage = res?.message || 'Could not remove experience.';
      renderExperienceCard();
    } catch (_err) {
      state.experiencesMessage = 'Could not remove experience.';
      renderExperienceCard();
    }
  }

  function renderCertificationsCard() {
    const card = root.querySelector('[data-rb-card="certifications"]');
    if (card) card.innerHTML = certificationsCardBody(false);
    updateCompletionUi();
  }

  function applyCertifications(certifications) {
    state.certifications = Array.isArray(certifications)
      ? certifications.filter((c) => c && c.certificationName)
      : [];
    renderCertificationsCard();
    renderPreviewCard();
  }

  async function loadCertifications() {
    if (!canUseResumeBuilderApi()) {
      applyCertifications([]);
      return;
    }
    try {
      const res = await api('/student/resume-builder/certifications', { skipAuthRedirect: true });
      if (res?.success) {
        applyCertifications(res.data?.certifications || []);
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applyCertifications([]);
  }

  function readCertificationForm() {
    const nameEl = root.querySelector('[data-rb-cert-name]');
    const orgEl = root.querySelector('[data-rb-cert-org]');
    const issueEl = root.querySelector('[data-rb-cert-issue]');
    const expiryEl = root.querySelector('[data-rb-cert-expiry]');
    const idEl = root.querySelector('[data-rb-cert-id]');
    const urlEl = root.querySelector('[data-rb-cert-url]');
    const descEl = root.querySelector('[data-rb-cert-desc]');
    return {
      certificationName: nameEl ? String(nameEl.value || '').trim() : '',
      issuingOrganization: orgEl ? String(orgEl.value || '').trim() : '',
      issueDate: issueEl ? String(issueEl.value || '').trim() : '',
      expiryDate: expiryEl ? String(expiryEl.value || '').trim() : '',
      credentialId: idEl ? String(idEl.value || '').trim() : '',
      credentialUrl: urlEl ? String(urlEl.value || '').trim() : '',
      description: descEl ? String(descEl.value || '').trim() : '',
    };
  }

  function restoreCertificationForm(form) {
    const nameEl = root.querySelector('[data-rb-cert-name]');
    const orgEl = root.querySelector('[data-rb-cert-org]');
    const issueEl = root.querySelector('[data-rb-cert-issue]');
    const expiryEl = root.querySelector('[data-rb-cert-expiry]');
    const idEl = root.querySelector('[data-rb-cert-id]');
    const urlEl = root.querySelector('[data-rb-cert-url]');
    const descEl = root.querySelector('[data-rb-cert-desc]');
    if (nameEl) nameEl.value = form.certificationName;
    if (orgEl) orgEl.value = form.issuingOrganization;
    if (issueEl) issueEl.value = form.issueDate;
    if (expiryEl) expiryEl.value = form.expiryDate;
    if (idEl) idEl.value = form.credentialId;
    if (urlEl) urlEl.value = form.credentialUrl;
    if (descEl) descEl.value = form.description;
  }

  function validateCertificationForm(form) {
    const nameLen = form.certificationName.length;
    if (nameLen < CERT_NAME_MIN || nameLen > CERT_NAME_MAX) {
      return 'Certification name must be between ' + CERT_NAME_MIN + ' and ' + CERT_NAME_MAX + ' characters.';
    }
    const orgLen = form.issuingOrganization.length;
    if (orgLen < CERT_ORG_MIN || orgLen > CERT_ORG_MAX) {
      return 'Issuing organization must be between ' + CERT_ORG_MIN + ' and ' + CERT_ORG_MAX + ' characters.';
    }
    if (!form.issueDate) {
      return 'Issue date is required.';
    }
    if (form.expiryDate && form.expiryDate < form.issueDate) {
      return 'Expiry date cannot be earlier than issue date.';
    }
    if (form.credentialUrl) {
      try {
        const parsed = new URL(form.credentialUrl);
        if (!/^https?:$/i.test(parsed.protocol)) return 'Enter a valid credential URL (including https://).';
      } catch (_err) {
        return 'Enter a valid credential URL (including https://).';
      }
    }
    if (form.description.length > CERT_DESC_MAX) {
      return 'Description must be at most ' + CERT_DESC_MAX + ' characters.';
    }
    return '';
  }

  async function saveCertification() {
    const form = readCertificationForm();
    const error = validateCertificationForm(form);
    if (error) {
      state.certificationsMessage = error;
      renderCertificationsCard();
      restoreCertificationForm(form);
      return;
    }
    if (!canUseResumeBuilderApi()) {
      state.certificationsMessage = 'Sign in to save certifications.';
      renderCertificationsCard();
      return;
    }

    const editingId = state.certificationsEditingId;
    try {
      const res = editingId
        ? await api('/student/resume-builder/certifications/' + encodeURIComponent(editingId), {
            method: 'PUT',
            body: form,
            skipAuthRedirect: true,
          })
        : await api('/student/resume-builder/certifications', {
            method: 'POST',
            body: form,
            skipAuthRedirect: true,
          });
      if (res?.success) {
        state.certificationsEditingId = '';
        state.certificationsFormOpen = false;
        state.certificationsMessage = '';
        applyCertifications(res.data?.certifications || []);
        return;
      }
      state.certificationsMessage = res?.message || 'Could not save certification.';
      renderCertificationsCard();
      restoreCertificationForm(form);
    } catch (_err) {
      state.certificationsMessage = 'Could not save certification.';
      renderCertificationsCard();
      restoreCertificationForm(form);
    }
  }

  async function deleteCertification(id) {
    if (!id || !canUseResumeBuilderApi()) return;
    try {
      const res = await api('/student/resume-builder/certifications/' + encodeURIComponent(id) + '/delete', {
        method: 'POST',
        skipAuthRedirect: true,
      });
      if (res?.success) {
        if (state.certificationsEditingId === id) state.certificationsEditingId = '';
        state.certificationsMessage = '';
        applyCertifications(res.data?.certifications || []);
        return;
      }
      state.certificationsMessage = res?.message || 'Could not remove certification.';
      renderCertificationsCard();
    } catch (_err) {
      state.certificationsMessage = 'Could not remove certification.';
      renderCertificationsCard();
    }
  }

  function renderActivitiesCard() {
    const card = root.querySelector('[data-rb-card="activities"]');
    if (card) card.innerHTML = activitiesCardBody(false);
    updateCompletionUi();
  }

  function applyActivities(activities) {
    state.activities = Array.isArray(activities)
      ? activities.filter((a) => a && a.title)
      : [];
    renderActivitiesCard();
    renderPreviewCard();
  }

  async function loadActivities() {
    if (!canUseResumeBuilderApi()) {
      applyActivities([]);
      return;
    }
    try {
      const res = await api('/student/resume-builder/activities', { skipAuthRedirect: true });
      if (res?.success) {
        applyActivities(res.data?.activities || []);
        return;
      }
    } catch (_err) {
      // Fall through.
    }
    applyActivities([]);
  }

  function readActivityForm() {
    const titleEl = root.querySelector('[data-rb-act-title]');
    const typeEl = root.querySelector('[data-rb-act-type]');
    const orgEl = root.querySelector('[data-rb-act-org]');
    const descEl = root.querySelector('[data-rb-act-desc]');
    const dateEl = root.querySelector('[data-rb-act-date]');
    return {
      title: titleEl ? String(titleEl.value || '').trim() : '',
      activityType: typeEl ? String(typeEl.value || '').trim() : '',
      organization: orgEl ? String(orgEl.value || '').trim() : '',
      description: descEl ? String(descEl.value || '').trim() : '',
      activityDate: dateEl ? String(dateEl.value || '').trim() : '',
    };
  }

  function restoreActivityForm(form) {
    const titleEl = root.querySelector('[data-rb-act-title]');
    const typeEl = root.querySelector('[data-rb-act-type]');
    const orgEl = root.querySelector('[data-rb-act-org]');
    const descEl = root.querySelector('[data-rb-act-desc]');
    const dateEl = root.querySelector('[data-rb-act-date]');
    if (titleEl) titleEl.value = form.title;
    if (typeEl) typeEl.value = form.activityType;
    if (orgEl) orgEl.value = form.organization;
    if (descEl) descEl.value = form.description;
    if (dateEl) dateEl.value = form.activityDate;
  }

  function validateActivityForm(form) {
    const titleLen = form.title.length;
    if (titleLen < ACT_TITLE_MIN || titleLen > ACT_TITLE_MAX) {
      return 'Title must be between ' + ACT_TITLE_MIN + ' and ' + ACT_TITLE_MAX + ' characters.';
    }
    if (!ACT_TYPES.includes(form.activityType)) {
      return 'Select an activity type.';
    }
    const descLen = form.description.length;
    if (descLen < ACT_DESC_MIN || descLen > ACT_DESC_MAX) {
      return 'Description must be between ' + ACT_DESC_MIN + ' and ' + ACT_DESC_MAX + ' characters.';
    }
    return '';
  }

  async function saveActivity() {
    const form = readActivityForm();
    const error = validateActivityForm(form);
    if (error) {
      state.activitiesMessage = error;
      renderActivitiesCard();
      restoreActivityForm(form);
      return;
    }
    if (!canUseResumeBuilderApi()) {
      state.activitiesMessage = 'Sign in to save activities.';
      renderActivitiesCard();
      return;
    }

    const editingId = state.activitiesEditingId;
    try {
      const res = editingId
        ? await api('/student/resume-builder/activities/' + encodeURIComponent(editingId), {
            method: 'PUT',
            body: form,
            skipAuthRedirect: true,
          })
        : await api('/student/resume-builder/activities', {
            method: 'POST',
            body: form,
            skipAuthRedirect: true,
          });
      if (res?.success) {
        state.activitiesEditingId = '';
        state.activitiesFormOpen = false;
        state.activitiesMessage = '';
        applyActivities(res.data?.activities || []);
        return;
      }
      state.activitiesMessage = res?.message || 'Could not save activity.';
      renderActivitiesCard();
      restoreActivityForm(form);
    } catch (_err) {
      state.activitiesMessage = 'Could not save activity.';
      renderActivitiesCard();
      restoreActivityForm(form);
    }
  }

  async function deleteActivity(id) {
    if (!id || !canUseResumeBuilderApi()) return;
    try {
      const res = await api('/student/resume-builder/activities/' + encodeURIComponent(id) + '/delete', {
        method: 'POST',
        skipAuthRedirect: true,
      });
      if (res?.success) {
        if (state.activitiesEditingId === id) state.activitiesEditingId = '';
        state.activitiesMessage = '';
        applyActivities(res.data?.activities || []);
        return;
      }
      state.activitiesMessage = res?.message || 'Could not remove activity.';
      renderActivitiesCard();
    } catch (_err) {
      state.activitiesMessage = 'Could not remove activity.';
      renderActivitiesCard();
    }
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
    loadContactLinks();
    loadCareerObjective();
    loadSkills();
    loadProjects();
    loadExperience();
    loadCertifications();
    loadActivities();
  }

  if (typeof onAppReady === 'function') onAppReady(init);
  else init();
})();
