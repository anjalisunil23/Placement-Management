/* PlaceHub shell v2026.06.22 — hide Departments from admin sidebar */
const APP_SHELL_VERSION = '2026.06.22';

const NAV = [
  { section: "Overview", roles: ROLES },
  { href: "dashboard.html", icon: "bi-grid-1x2-fill", label: "Dashboard", roles: ROLES },

  { section: "Placement", roles: ['admin','placement_officer','student','staff','alumni'] },
  {
    group: 'placement-drives',
    icon: 'bi-briefcase-fill',
    label: 'Placement Drives',
    href: 'drives.html',
    roles: ['admin','placement_officer','staff'],
    children: [
      { href: 'placement-console.html', label: 'Console', roles: ['admin','placement_officer'] },
    ],
  },
  { href: "drives.html", icon: "bi-search", label: "Browse & Apply", roles: ['student'], studentOnly: true },
  { href: "drives.html", icon: "bi-search", label: "Apply for Jobs", roles: ['alumni'], alumniSeeking: true },
  { href: "students.html", icon: "bi-people-fill", label: "Student", roles: ['admin','placement_officer','staff'] },
  { href: "hiring-overview.html", icon: "bi-building-check", label: "Hiring Overview", roles: ['admin','placement_officer','staff'] },
  { href: "users.html", icon: "bi-person-gear", label: "User Management", roles: ['admin'] },
  { href: "tracking.html", icon: "bi-graph-up-arrow", label: "Placement Tracking", roles: ['admin','placement_officer'] },
  { href: "admin-companies.html", icon: "bi-building-check", label: "Companies & Referrals", roles: ['admin','placement_officer'] },
  { href: "reports.html", icon: "bi-file-earmark-bar-graph", label: "Reports", roles: ['admin','placement_officer'] },
  { href: "admin-settings.html", icon: "bi-gear-wide-connected", label: "System Settings", roles: ['admin'] },

  { section: "Alumni", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-jobs.html", icon: "bi-megaphone-fill", label: "Job Posts", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-referrals.html", icon: "bi-share-fill", label: "Referrals", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-success-stories.html", icon: "bi-star-fill", label: "Success Stories", roles: ['alumni'], alumniEmployed: true },

  { section: "Staff", roles: ['staff'] },
  { href: "staff-recommend.html", icon: "bi-building-add", label: "Recommend Company", roles: ['staff'] },

  { section: "Company", roles: ['admin','placement_officer','company'] },
  { href: "company.html", icon: "bi-building", label: "Company Portal", roles: ['company'] },
  { href: "applicants.html", icon: "bi-person-lines-fill", label: "Applicants", roles: ['company'] },
  { href: "recruiting.html", icon: "bi-diagram-3-fill", label: "Recruitment", roles: ['company'] },
  { href: "eligibility.html", icon: "bi-check2-square", label: "Eligibility Criteria", roles: ['company'] },

  { section: "Insights", roles: ['staff'] },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['staff'] },

  { section: "Account", roles: ['admin','placement_officer','student','staff','alumni','company'] },
  { href: "notifications.html", icon: "bi-bell-fill", label: "Notifications", roles: ['admin','placement_officer','student','staff','alumni','company'] },
  { href: "settings.html", icon: "bi-gear-fill", label: "Settings", roles: ['admin','placement_officer','staff','alumni','company'] },
  { href: "settings.html", icon: "bi-person-badge", label: "Profile & Resumes", roles: ['student'], studentOnly: true },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['admin','placement_officer','alumni'] },
];

const PAGE_LABELS = {
  'dashboard.html': 'Dashboard',
  'analytics.html': 'Analytics',
  'drives.html': 'Placement Drives',
  'create-drive.html': 'Create Drive',
  'placement-console.html': 'Placement Drives · Console',
  'students.html': 'Student',
  'hiring-overview.html': 'Hiring Overview',
  'tracking.html': 'Placement Tracking',
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
  'alumni-success-stories.html': 'Success Stories',
  'staff-recommend.html': 'Recommend Company',
  'users.html': 'User Management',
  'rules.html': 'Placement Rules',
  'applications.html': 'Student · Management · Application',
  'blacklist.html': 'Student · Management · Blacklist',
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

function escapeAttr(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');
}

function userPhotoUrl(user) {
  if (!user || typeof user !== 'object') return '';
  if (typeof resolveSessionPhotoUrl === 'function') {
    const fromSession = resolveSessionPhotoUrl(user);
    if (fromSession) return fromSession;
  }
  const direct = String(user.photoUrl || user.stud_photo || user.photo?.url || '').trim();
  if (!direct) return '';
  if (/^https?:\/\//i.test(direct)) return direct;
  return direct.startsWith('/') ? direct : `/${direct}`;
}

function userAvatarHtml(user, size = 38, fontSize = '.85rem') {
  const url = userPhotoUrl(user);
  const style = `width:${size}px;height:${size}px;font-size:${fontSize}`;
  const ini = initials(user?.name);
  if (url) {
    return `<div class="avatar has-photo" style="${style}" data-initials="${escapeAttr(ini)}"><img src="${escapeAttr(url)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"/></div>`;
  }
  return `<div class="avatar" style="${style}">${ini}</div>`;
}

function navItemHidden(n) {
  const href = String(n?.href || '');
  const label = String(n?.label || '');
  return href === 'departments.html' || href.includes('departments') || label === 'Departments';
}

function navItemVisible(n, role) {
  if (navItemHidden(n)) return false;
  if (n.studentOnly && role !== 'student') return false;
  if (!n.roles.includes(role)) return false;
  if (role !== 'alumni') return true;
  if (n.alumniEmployed) return alumniIsWorking();
  if (n.alumniSeeking) return !alumniIsWorking();
  return true;
}

function visibleGroupChildren(group, role) {
  return (group.children || []).filter(c => {
    if (navItemHidden(c)) return false;
    if (c.group) {
      if (!navItemVisible(c, role)) return false;
      if (c.href) return true;
      return visibleGroupChildren(c, role).length > 0;
    }
    return navItemVisible(c, role);
  });
}

function isGroupActive(group, active) {
  const base = (active || '').split('#')[0];
  if (group.href && group.href.split('#')[0] === base) return true;
  return (group.children || []).some(c => {
    if (c.group) return isGroupActive(c, active);
    return (c.href || '').split('#')[0] === base;
  });
}

function renderNavChildren(children, active, role, depth = 1) {
  return visibleGroupChildren({ children }, role).map(c => {
    if (c.group) {
      const open = isGroupActive(c, active);
      const subKids = visibleGroupChildren(c, role);
      const headActive = c.href ? isNavActive(c, active) : open;
      return `
        <div class="nav-sub-group ${open ? 'open' : ''}" data-nav-group="${c.group}">
          <div class="nav-sub-group-row">
            ${c.href
              ? `<a class="nav-item nav-sub-item ${headActive ? 'active' : ''}" href="${c.href}"><span>${c.label}</span></a>`
              : `<button type="button" class="nav-item nav-sub-group-toggle ${headActive ? 'active' : ''}" aria-expanded="${open}"><span>${c.label}</span></button>`}
            ${subKids.length
              ? `<button type="button" class="nav-sub-chevron-btn" aria-label="Toggle ${c.label}"><i class="bi bi-chevron-down nav-chevron"></i></button>`
              : ''}
          </div>
          ${subKids.length
            ? `<div class="nav-sub-nested">${renderNavChildren(subKids, active, role, depth + 1)}</div>`
            : ''}
        </div>`;
    }
    const cls = depth > 1 ? 'nav-sub-item nav-sub-item-deep' : 'nav-sub-item';
    return `<a class="nav-item ${cls} ${isNavActive(c, active) ? 'active' : ''}" href="${c.href}"><span>${c.label}</span></a>`;
  }).join('');
}

function groupNavVisible(group, role) {
  if (!navItemVisible(group, role)) return false;
  return !!(group.href || visibleGroupChildren(group, role).length);
}

function filteredNav() {
  const role = Auth.role();
  const out = [];
  for (let i = 0; i < NAV.length; i++) {
    const n = NAV[i];
    if (n.section) {
      if (!navItemVisible(n, role)) continue;
      let hasChild = false;
      for (let j = i + 1; j < NAV.length && !NAV[j].section; j++) {
        const child = NAV[j];
        if (child.group) {
          if (groupNavVisible(child, role)) { hasChild = true; break; }
        } else if (navItemVisible(child, role)) { hasChild = true; break; }
      }
      if (hasChild) out.push(n);
    } else if (n.group) {
      if (groupNavVisible(n, role)) out.push(n);
    } else if (navItemVisible(n, role)) {
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

function renderNavEntry(n, active, role) {
  if (n.section) return `<div class="nav-label">${n.section}</div>`;
  if (n.group) {
    const kids = visibleGroupChildren(n, role);
    if (!kids.length && !n.href) return '';
    const open = isGroupActive(n, active);
    const headActive = n.href ? isNavActive(n, active) : open;

    if (!kids.length && n.href) {
      return `<a class="nav-item ${headActive ? 'active' : ''}" href="${n.href}">
        <i class="bi ${n.icon}"></i><span>${n.label}</span>
      </a>`;
    }

    if (!n.href) {
      return `
      <div class="nav-group ${open ? 'open' : ''}" data-nav-group="${n.group}">
        <button type="button" class="nav-item nav-group-toggle ${open ? 'active' : ''}" aria-expanded="${open}">
          <i class="bi ${n.icon}"></i><span>${n.label}</span>
          <i class="bi bi-chevron-down nav-chevron ms-auto"></i>
        </button>
        <div class="nav-sub">
          ${renderNavChildren(kids, active, role)}
        </div>
      </div>`;
    }

    return `
      <div class="nav-group ${open ? 'open' : ''}" data-nav-group="${n.group}">
        <div class="nav-group-row">
          ${n.href
            ? `<a class="nav-item nav-group-link ${headActive ? 'active' : ''}" href="${n.href}"><i class="bi ${n.icon}"></i><span>${n.label}</span></a>`
            : `<button type="button" class="nav-item nav-group-toggle ${open ? 'active' : ''}" aria-expanded="${open}"><i class="bi ${n.icon}"></i><span>${n.label}</span></button>`}
          <button type="button" class="nav-group-chevron-btn" aria-label="Toggle ${n.label}"><i class="bi bi-chevron-down nav-chevron"></i></button>
        </div>
        <div class="nav-sub">
          ${renderNavChildren(kids, active, role)}
        </div>
      </div>`;
  }
  return `<a class="nav-item ${isNavActive(n, active) ? 'active' : ''}" href="${n.href}">
    <i class="bi ${n.icon}"></i><span>${n.label}</span>${n.href === 'notifications.html' ? '<span class="badge-soft danger nav-badge ms-auto" style="display:none;font-size:.65rem;padding:.15rem .45rem">0</span>' : ''}
  </a>`;
}

function renderShell(active) {
  const user = Auth.user() || demoUserFor(Auth.role());
  const role = Auth.role();
  const sidebar = document.getElementById("sidebar");
  const topbar = document.getElementById("topbar");
  const activeBase = (active || 'dashboard.html').split('#')[0];
  const pageLabel = role === 'student' && activeBase === 'settings.html'
    ? 'Profile & Resumes'
    : (PAGE_LABELS[activeBase] || BRAND.title);
  const showTopbarTitle = activeBase !== 'staff-recommend.html';

  if (sidebar) {
    const navItems = filteredNav();
    sidebar.innerHTML = `
      <div class="brand">
        ${brandBlockHtml({ href: Auth.homePage(), logoHeight: 36 })}
      </div>
      <div class="nav-section">
        ${navItems.length ? navItems.map(n => renderNavEntry(n, active, role)).join('') : `<div class="p-3 small text-muted-2">No navigation items for this role.</div>`}
      </div>
      <div style="padding:1rem;border-top:1px solid var(--border)">
        <div class="d-flex align-items-center gap-2">
          ${userAvatarHtml(user, 36, '.85rem')}
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
      staff: '',
      company: '<a href="company.html" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex">Portal</a><a href="applicants.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Applicants</a>',
      alumni: alumniIsWorking()
        ? '<button type="button" onclick="ReferralModals.openAlumni()" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Recommend Co.</button><a href="alumni-jobs.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex ms-2">Post Job</a>'
        : '<a href="drives.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Apply</a>',
    }[role] || '';

    topbar.innerHTML = `
      <button class="icon-btn d-lg-none" id="menuBtn" aria-label="Menu"><i class="bi bi-list"></i></button>
      ${showTopbarTitle ? `<div class="d-none d-md-block">
        <div class="fw-semibold" style="font-size:.95rem;line-height:1.2">${pageLabel}</div>
        <div class="small text-muted-2">${ROLE_LABELS[role]} workspace</div>
      </div>` : ''}
      <div class="search ms-md-3">
        <i class="bi bi-search"></i>
        <input placeholder="${TOPBAR_SEARCH[role] || 'Search…'}" />
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        ${quickLinks}
        ${Auth.isDemo() ? `<div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Preview as role">
            <i class="bi bi-person-badge me-1"></i><span class="d-none d-sm-inline">${ROLE_LABELS[role]}</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header">Preview as role</h6></li>
            ${ROLES.map(r => `<li><a class="dropdown-item ${r===role?'active':''}" href="#" data-role="${r}">${ROLE_LABELS[r]}</a></li>`).join('')}
          </ul>
        </div>` : `<span class="badge badge-soft ${ROLE_BADGES[role] || 'muted'} d-none d-sm-inline-flex align-items-center gap-1 px-2 py-1"><i class="bi bi-person-badge"></i>${ROLE_LABELS[role]}</span>`}
        <button class="icon-btn" id="themeBtn" title="Theme"><i class="bi bi-moon-stars"></i></button>
        ${'<a href="notifications.html" class="icon-btn" title="Notifications"><i class="bi bi-bell"></i><span class="dot"></span></a>'}
        ${role !== 'student' ? '<a href="settings.html" class="icon-btn d-none d-sm-grid" title="Settings"><i class="bi bi-gear"></i></a>' : '<a href="settings.html" class="icon-btn d-none d-sm-grid" title="Profile & Resumes"><i class="bi bi-person-badge"></i></a>'}
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
    if (key === 'admin') {
      window.location.href = `login.html?next=${encodeURIComponent('dashboard.html')}`;
      return;
    }
    const user = demoUserFor(key === 'alumni-seeking' ? 'alumni-seeking' : key);
    Auth.clear();
    Auth.set(user, 'demo-token');
    toast('Preview mode — sign in with your account for live data and saving changes.', 'info');
    window.location.reload();
  }));

  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    if (await confirmAction({
      title: 'Sign out',
      message: 'Sign out of your account?',
      confirmText: 'Sign out',
      variant: 'warning',
    })) {
      Auth.logout();
    }
  });

  sidebar?.querySelectorAll('.nav-group-chevron-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const group = btn.closest('.nav-group');
      if (!group) return;
      const willOpen = !group.classList.contains('open');
      group.classList.toggle('open', willOpen);
      group.querySelector('.nav-group-toggle')?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  sidebar?.querySelectorAll('.nav-group-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.nav-group');
      if (!group) return;
      const willOpen = !group.classList.contains('open');
      sidebar.querySelectorAll('.nav-group.open').forEach(g => {
        if (g !== group) {
          g.classList.remove('open');
          g.querySelector('.nav-group-toggle')?.setAttribute('aria-expanded', 'false');
        }
      });
      group.classList.toggle('open', willOpen);
      btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  sidebar?.querySelectorAll('.nav-sub-chevron-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const group = btn.closest('.nav-sub-group');
      if (!group) return;
      group.classList.toggle('open');
    });
  });
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
  const page = active.split('#')[0];
  if (!Auth.isAllowed(page)) {
    toast(`This page isn't available for ${ROLE_LABELS[Auth.role()] || 'your role'}.`, 'warn');
    setTimeout(() => { window.location.href = Auth.homePage(); }, 600);
    return false;
  }
  return true;
}

document.addEventListener('ph-user-updated', () => {
  const active = document.body.dataset.page;
  if (active && document.getElementById('sidebar')) renderShell(active);
});

document.addEventListener('error', (event) => {
  const img = event.target;
  if (!(img instanceof HTMLImageElement)) return;
  const avatar = img.closest('.avatar.has-photo');
  if (!avatar) return;
  avatar.classList.remove('has-photo');
  avatar.textContent = avatar.dataset.initials || 'U';
  img.remove();
}, true);

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.querySelector('link[rel="icon"]')) {
    const link = document.createElement('link');
    link.rel = 'icon';
    link.type = 'image/svg+xml';
    link.href = '/favicon.svg';
    document.head.appendChild(link);
  }

  const active = document.body.dataset.page;
  const isPublic = !active || active === 'login.html' || active === 'public-stats.html' || active === 'index.html' || active === 'aes-complete.html';

  if (isPublic) {
    document.documentElement.setAttribute('data-theme', UserPrefs.theme());
    document.documentElement.setAttribute('data-density', UserPrefs.density());
    if (active !== 'public-stats.html') {
      animateCounters();
    }
    document.dispatchEvent(new CustomEvent('ph-ready'));
    return;
  }

  let hasSession = await Auth.bootstrap();
  if (!hasSession) {
    await new Promise((r) => setTimeout(r, 250));
    hasSession = await Auth.bootstrap();
  }
  if (!hasSession && typeof ADMIN_ONLY_PAGES !== 'undefined' && ADMIN_ONLY_PAGES.includes(active)) {
    window.location.replace(`login.html?next=${encodeURIComponent(active)}`);
    return;
  }
  if (!hasSession) {
    if (Auth.isDemo()) {
      // preview mode — read-only dashboards only
    } else if (Auth.hasSession() && Auth.user()?.role) {
      Auth._sessionReady = true;
    } else {
      Auth.clear();
      window.location.href = `login.html?next=${encodeURIComponent(active)}`;
      return;
    }
  }

  if (!enforcePageRole(active)) return;
  renderShell(active);
  applyRoleVisibility();
  animateCounters();
  NotificationInbox.refreshBadge?.();
  document.dispatchEvent(new CustomEvent('ph-ready'));
  ReferralModals.init();
});

/** Shared staff / alumni referral modals — open from any page */
const ReferralModals = {
  mountStaff() {
    if (document.getElementById('staffRecommendModal')) return;
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="staffRecommendModal" tabindex="-1" aria-labelledby="staffRecommendModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="staffRecommendModalForm">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="staffRecommendModalLabel">Recommend a Company</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="max-height:min(70vh,640px);overflow-y:auto">
          <p class="small text-muted-2 mb-3">Share recruiter details for the placement cell. Only the admin can view HR contact information.</p>
          <div class="row g-3">
            <div class="col-12"><label class="form-label small fw-semibold">Company name</label><input class="form-control" name="companyName" required placeholder="e.g. Razorpay"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">Company website (optional)</label><input class="form-control" name="companyWebsite" placeholder="https://company.com/careers"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">HR name</label><input class="form-control" name="hrName" required placeholder="e.g. Priya Menon"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">HR email</label><input class="form-control" type="email" name="hrEmail" required placeholder="hr@company.com"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact number</label><input class="form-control" name="contactNumber" required placeholder="+91 98765 43210"/></div>
          </div>
          <div class="alert alert-info small mt-3 mb-0">Only the admin can view HR contact details and follow up with the company.</div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Submit Recommendation</button>
        </div>
      </form>
    </div>
  </div>
</div>`);
    document.getElementById('staffRecommendModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target).entries());
      if (!(await confirmAction({ title: 'Submit recommendation', message: `Recommend ${data.companyName || 'this company'} to the placement cell?`, confirmText: 'Submit', variant: 'primary' }))) return;
      await StaffRecs.add(data);
      bootstrap.Modal.getInstance(document.getElementById('staffRecommendModal'))?.hide();
      toast('Company recommended. The admin will review and contact them.', 'success');
      e.target.reset();
      document.dispatchEvent(new CustomEvent('ph-staff-recommend-added'));
    });
  },

  mountAlumni() {
    if (document.getElementById('alumniReferralModal')) return;
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="alumniReferralModal" tabindex="-1" aria-labelledby="alumniReferralModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="alumniReferralModalForm">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="alumniReferralModalLabel">Recommend a Company</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="max-height:min(70vh,640px);overflow-y:auto">
          <p class="small text-muted-2 mb-3">Share recruiter details for the placement cell. Only the admin can view HR contact information.</p>
          <div class="row g-3">
            <div class="col-12"><label class="form-label small fw-semibold">Company name</label><input class="form-control" name="companyName" required placeholder="e.g. Razorpay"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">Company website (optional)</label><input class="form-control" name="companyWebsite" placeholder="https://company.com/careers"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">HR name</label><input class="form-control" name="hrName" required placeholder="e.g. Priya Menon"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">HR email</label><input class="form-control" type="email" name="hrEmail" required placeholder="hr@company.com"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact number</label><input class="form-control" name="contactNumber" required placeholder="+91 98765 43210"/></div>
          </div>
          <div class="alert alert-info small mt-3 mb-0">Only the admin can view HR contact details and follow up with the company.</div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Submit Recommendation</button>
        </div>
      </form>
    </div>
  </div>
</div>`);
    document.getElementById('alumniReferralModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target).entries());
      if (!(await confirmAction({ title: 'Submit recommendation', message: `Recommend ${data.companyName || 'this company'} to the placement cell?`, confirmText: 'Submit', variant: 'primary' }))) return;
      await AlumniReferrals.add(data);
      bootstrap.Modal.getInstance(document.getElementById('alumniReferralModal'))?.hide();
      toast('Company recommended. The admin will review and contact them.', 'success');
      e.target.reset();
      document.dispatchEvent(new CustomEvent('ph-alumni-referral-added'));
    });
  },

  openStaff() {
    if (Auth.role() !== 'staff') return;
    this.mountStaff();
    const form = document.getElementById('staffRecommendModalForm');
    form.reset();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('staffRecommendModal')).show();
  },

  openAlumni() {
    if (Auth.role() !== 'alumni') return;
    this.mountAlumni();
    const form = document.getElementById('alumniReferralModalForm');
    form.reset();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('alumniReferralModal')).show();
  },

  init() {
    if (location.hash !== '#create') return;
    const role = Auth.role();
    if (role === 'staff') this.openStaff();
    else if (role === 'alumni' && alumniIsWorking()) this.openAlumni();
  },
};

window.openStaffRecommendModal = () => ReferralModals.openStaff();
window.openReferralModal = () => ReferralModals.openAlumni();
