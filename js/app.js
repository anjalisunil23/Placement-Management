/* Shared app shell: sidebar, topbar, theme toggle, role gate, counters */

const NAV = [
  { section: "Overview", roles: ROLES },
  { href: "dashboard.html", icon: "bi-grid-1x2-fill", label: "Dashboard", roles: ROLES },

  { section: "Placement", roles: ['admin','placement_officer','student','staff','alumni'] },
  { href: "drives.html", icon: "bi-briefcase-fill", label: "Placement Drives", roles: ['admin','placement_officer','staff'] },
  { href: "drives.html", icon: "bi-search", label: "Browse & Apply", roles: ['student'], studentOnly: true },
  { href: "drives.html", icon: "bi-search", label: "Apply for Jobs", roles: ['alumni'], alumniSeeking: true },
  { href: "placement-console.html", icon: "bi-sliders", label: "Placement Console", roles: ['admin','placement_officer'] },
  { href: "student-overview.html", icon: "bi-person-vcard", label: "Student Overview", roles: ['admin','placement_officer','staff'] },
  { href: "hiring-overview.html", icon: "bi-building-check", label: "Hiring Overview", roles: ['admin','placement_officer','staff'] },
  { href: "students.html", icon: "bi-people-fill", label: "Students", roles: ['admin','placement_officer'] },
  { href: "users.html", icon: "bi-person-gear", label: "User Management", roles: ['admin'] },
  { href: "resumes.html", icon: "bi-file-earmark-check", label: "Resume Verification", roles: ['admin','placement_officer'] },
  { href: "applications.html", icon: "bi-send-check", label: "Applications", roles: ['admin','placement_officer'] },
  { href: "create-drive.html", icon: "bi-plus-square", label: "Create Drive", roles: ['admin','placement_officer'] },
  { href: "rules.html", icon: "bi-check2-square", label: "Placement Rules", roles: ['admin'] },
  { href: "departments.html", icon: "bi-diagram-3", label: "Departments", roles: ['admin'] },
  { href: "blacklist.html", icon: "bi-slash-circle", label: "Blacklist", roles: ['admin'] },
  { href: "results.html", icon: "bi-trophy", label: "Recruitment Results", roles: ['admin','placement_officer'] },
  { href: "tracking.html", icon: "bi-graph-up-arrow", label: "Placement Tracking", roles: ['admin','placement_officer'] },
  { href: "admin-companies.html", icon: "bi-building-check", label: "Companies & Referrals", roles: ['admin','placement_officer'] },
  { href: "analytics.html", icon: "bi-bar-chart-fill", label: "Analytics", roles: ['admin','placement_officer'] },
  { href: "reports.html", icon: "bi-file-earmark-bar-graph", label: "Reports", roles: ['admin','placement_officer'] },
  { href: "admin-settings.html", icon: "bi-gear-wide-connected", label: "System Settings", roles: ['admin'] },

  { section: "Alumni", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-jobs.html", icon: "bi-megaphone-fill", label: "Job Posts", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-referrals.html", icon: "bi-share-fill", label: "Referrals", roles: ['alumni'], alumniEmployed: true },

  { section: "Staff", roles: ['staff'] },
  { href: "staff-recommend.html", icon: "bi-building-add", label: "Recommend Company", roles: ['staff'] },
  { href: "dashboard.html#dept-stats", icon: "bi-bar-chart-line-fill", label: "Dept. Statistics", roles: ['staff'] },

  { section: "Company", roles: ['admin','placement_officer','company'] },
  { href: "company.html", icon: "bi-building", label: "Company Portal", roles: ['company'] },
  { href: "applicants.html", icon: "bi-person-lines-fill", label: "Applicants", roles: ['company'] },
  { href: "eligibility.html", icon: "bi-check2-square", label: "Eligibility Criteria", roles: ['company'] },

  { section: "Insights", roles: ['staff'] },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['staff'] },

  { section: "Account", roles: ['admin','placement_officer','student','staff','alumni'] },
  { href: "notifications.html", icon: "bi-bell-fill", label: "Notifications", roles: ['admin','placement_officer','student','staff','alumni'] },
  { href: "settings.html", icon: "bi-gear-fill", label: "Settings", roles: ['admin','placement_officer','staff','alumni'] },
  { href: "settings.html", icon: "bi-person-badge", label: "Profile & Resumes", roles: ['student'], studentOnly: true },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['admin','placement_officer','alumni'] },
];

const PAGE_LABELS = {
  'dashboard.html': 'Dashboard',
  'analytics.html': 'Analytics',
  'drives.html': 'Placement Drives',
  'create-drive.html': 'Create Drive',
  'placement-console.html': 'Placement Console',
  'student-overview.html': 'Student Overview',
  'hiring-overview.html': 'Hiring Overview',
  'tracking.html': 'Placement Tracking',
  'students.html': 'Student Registration',
  'eligibility.html': 'Eligibility Criteria',
  'company.html': 'Company Portal',
  'admin-companies.html': 'Staff Recommendations',
  'applicants.html': 'Applicants',
  'reports.html': 'Reports',
  'notifications.html': 'Notifications',
  'settings.html': 'Settings',
  'settings-student': 'Profile & Resumes',
  'public-stats.html': 'Public Portal',
  'alumni-jobs.html': 'Job Posts',
  'alumni-referrals.html': 'Referrals',
  'staff-recommend.html': 'Recommend Company',
  'users.html': 'User Management',
  'departments.html': 'Departments',
  'rules.html': 'Placement Rules',
  'applications.html': 'Applications',
  'resumes.html': 'Resume Verification',
  'blacklist.html': 'Blacklist',
  'results.html': 'Recruitment Results',
  'admin-settings.html': 'System Settings',
};

const TOPBAR_SEARCH = {
  admin: 'Search students, companies, drives…',
  placement_officer: 'Search students, companies, drives…',
  student: 'Search drives, companies, roles…',
  staff: 'Search drives, departments, companies…',
  company: 'Search companies, departments, applicants…',
  alumni: 'Search jobs, drives, referrals…',
};

function initials(name='') {
  return name.trim().split(/\s+/).map(s=>s[0]).slice(0,2).join('').toUpperCase() || 'U';
}

function navItemVisible(n, role) {
  if (!n.roles.includes(role)) return false;
  if (role !== 'alumni') return true;
  if (n.alumniEmployed) return alumniIsWorking();
  if (n.alumniSeeking) return !alumniIsWorking();
  return true;
}

function filteredNav() {
  const role = Auth.role();
  const out = [];
  for (let i = 0; i < NAV.length; i++) {
    const n = NAV[i];
    if (!navItemVisible(n, role)) continue;
    if (n.section) {
      let hasChild = false;
      for (let j = i + 1; j < NAV.length && !NAV[j].section; j++) {
        if (navItemVisible(NAV[j], role)) { hasChild = true; break; }
      }
      if (hasChild) out.push(n);
    } else {
      out.push(n);
    }
  }
  return out;
}

function isNavActive(n, active) {
  if (!n.href) return false;
  const base = n.href.split('#')[0];
  const activeBase = (active || '').split('#')[0];
  return base === activeBase;
}

function renderShell(active) {
  const user = Auth.user() || demoUserFor(Auth.role());
  const role = Auth.role();
  const sidebar = document.getElementById("sidebar");
  const topbar = document.getElementById("topbar");
  const activeBase = (active || 'dashboard.html').split('#')[0];
  const pageLabel = role === 'student' && activeBase === 'settings.html'
    ? 'Profile & Resumes'
    : (PAGE_LABELS[activeBase] || 'PlaceHub');

  if (sidebar) {
    const navItems = filteredNav();
    sidebar.innerHTML = `
      <div class="brand">
        <a href="dashboard.html" class="d-flex align-items-center gap-2 text-decoration-none text-reset">
          <div class="brand-mark">P</div>
          <div>PlaceHub<div style="font-size:.7rem;color:var(--muted);font-weight:500">Placement Suite</div></div>
        </a>
      </div>
      <div class="nav-section">
        ${navItems.length ? navItems.map(n => n.section
          ? `<div class="nav-label">${n.section}</div>`
          : `<a class="nav-item ${isNavActive(n, active) ? "active" : ""}" href="${n.href}">
               <i class="bi ${n.icon}"></i><span>${n.label}</span>
             </a>`
        ).join("") : `<div class="p-3 small text-muted-2">No navigation items for this role.</div>`}
      </div>
      <div style="padding:1rem;border-top:1px solid var(--border)">
        <div class="d-flex align-items-center gap-2">
          <div class="avatar" style="width:36px;height:36px;font-size:.85rem">${initials(user.name)}</div>
          <div style="min-width:0;flex:1">
            <div style="font-size:.85rem;font-weight:600" class="text-truncate">${user.name}</div>
            <div style="font-size:.72rem;color:var(--muted)">${ROLE_LABELS[role]}</div>
          </div>
          <button class="icon-btn" id="logoutBtn" title="Log out" style="width:32px;height:32px"><i class="bi bi-box-arrow-right"></i></button>
        </div>
      </div>`;
  }

  if (topbar) {
    const quickLinks = {
      admin: '<a href="placement-console.html" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex">Console</a><a href="students.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Approvals</a>',
      placement_officer: '<a href="placement-console.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Placement Console</a>',
      student: '<a href="drives.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Browse Drives</a><a href="settings.html" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex">Upload Resume</a>',
      staff: '<a href="staff-recommend.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Recommend Co.</a>',
      company: '<a href="company.html" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex">Portal</a><a href="applicants.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Applicants</a>',
      alumni: alumniIsWorking()
        ? '<a href="alumni-jobs.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Post Job</a>'
        : '<a href="drives.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Apply</a>',
    }[role] || '';

    topbar.innerHTML = `
      <button class="icon-btn d-lg-none" id="menuBtn" aria-label="Menu"><i class="bi bi-list"></i></button>
      <div class="d-none d-md-block">
        <div class="fw-semibold" style="font-size:.95rem;line-height:1.2">${pageLabel}</div>
        <div class="small text-muted-2">${ROLE_LABELS[role]} workspace</div>
      </div>
      <div class="search ms-md-3">
        <i class="bi bi-search"></i>
        <input placeholder="${TOPBAR_SEARCH[role] || 'Search…'}" />
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        ${quickLinks}
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Preview as role">
            <i class="bi bi-person-badge me-1"></i><span class="d-none d-sm-inline">${ROLE_LABELS[role]}</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header">Preview as role</h6></li>
            ${ROLES.map(r => `<li><a class="dropdown-item ${r===role?'active':''}" href="#" data-role="${r}">${ROLE_LABELS[r]}</a></li>`).join('')}
          </ul>
        </div>
        <button class="icon-btn" id="themeBtn" title="Theme"><i class="bi bi-moon-stars"></i></button>
        ${role !== 'company' ? '<a href="notifications.html" class="icon-btn" title="Notifications"><i class="bi bi-bell"></i><span class="dot"></span></a>' : ''}
        ${role !== 'company' ? '<a href="settings.html" class="icon-btn d-none d-sm-grid" title="Settings"><i class="bi bi-gear"></i></a>' : ''}
        <div class="avatar">${initials(user.name)}</div>
      </div>`;
  }

  const menuBtn = document.getElementById("menuBtn");
  const back = document.querySelector('.backdrop') || document.createElement("div");
  if (!back.classList.contains('backdrop')) {
    back.className = "backdrop";
    document.body.appendChild(back);
  }
  menuBtn?.addEventListener("click", () => { sidebar?.classList.add("open"); back.classList.add("show"); });
  back.addEventListener("click", () => { sidebar?.classList.remove("open"); back.classList.remove("show"); });

  const saved = UserPrefs.theme();
  document.documentElement.setAttribute("data-theme", saved);
  document.documentElement.setAttribute("data-density", UserPrefs.density());
  document.getElementById("themeBtn")?.addEventListener("click", () => {
    const cur = UserPrefs.isDark() ? "light" : "dark";
    UserPrefs.setTheme(cur);
    const dk = document.getElementById("dk");
    if (dk) dk.checked = cur === "dark";
  });

  document.querySelectorAll('[data-role]').forEach(a => a.addEventListener('click', (e) => {
    e.preventDefault();
    const key = a.dataset.role;
    const user = demoUserFor(key === 'alumni-seeking' ? 'alumni-seeking' : key);
    Auth._sessionReady = false;
    Auth.setRole(user.role);
    Auth.set(user, 'demo-token');
    toast('Preview mode — sign in for live data and reports.', 'info');
    window.location.reload();
  }));

  document.getElementById('logoutBtn')?.addEventListener('click', () => Auth.logout());
}

/* Animated counters */
function animateCounters(root = document) {
  root.querySelectorAll(".counter").forEach(el => {
    if (el.dataset.animated === '1') return;
    el.dataset.animated = '1';
    const target = parseFloat(el.dataset.target || "0");
    const decimals = parseInt(el.dataset.decimals || "0");
    const prefix = el.dataset.prefix || "";
    const suffix = el.dataset.suffix || "";
    let cur = 0;
    const step = target / 60 || 1;
    const tick = () => {
      cur += step;
      if (cur >= target) { cur = target; el.textContent = prefix + cur.toFixed(decimals) + suffix; return; }
      el.textContent = prefix + cur.toFixed(decimals) + suffix;
      requestAnimationFrame(tick);
    };
    tick();
  });
}

function enforcePageRole(active) {
  if (!active || active === 'login.html' || active === 'public-stats.html' || active === 'index.html') return true;
  if (!Auth.isAllowed(active)) {
    toast(`This page isn't available for ${ROLE_LABELS[Auth.role()]}.`, 'warn');
    setTimeout(() => window.location.href = 'dashboard.html', 600);
    return false;
  }
  return true;
}

document.addEventListener("DOMContentLoaded", async () => {
  const active = document.body.dataset.page;
  const isPublic = !active || active === 'login.html' || active === 'public-stats.html' || active === 'index.html';

  if (!isPublic) {
    const hasSession = await Auth.bootstrap();
    if (!hasSession && !Auth.isAuthed()) {
      window.location.href = `login.html?next=${encodeURIComponent(active)}`;
      return;
    }
  }

  if (!enforcePageRole(active)) return;
  renderShell(active);
  applyRoleVisibility();
  animateCounters();
  document.dispatchEvent(new CustomEvent('ph-ready'));
});
