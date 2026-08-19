/**
 * Resume Builder dashboard (Phase 2) — UI only.
 * Isolated from Profile, Resumes, APIs, and persistence.
 */
(function () {
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

  function sectionCard(section) {
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

  root.innerHTML = `
    <div class="card-surface p-3 p-md-4 mb-3">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div class="flex-grow-1">
          <h5 class="fw-bold mb-1">Resume Completion</h5>
          <div class="rb-completion-pct mb-1">0%</div>
          <p class="small text-muted-2 mb-2">Sections Completed: 0/8</p>
          <div class="rb-completion-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Resume completion">
            <div class="rb-completion-bar-fill"></div>
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

  if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
      bootstrap.Tooltip.getOrCreateInstance(el);
    });
  }
})();
