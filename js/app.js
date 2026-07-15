/* PlaceHub shell v2026.07.11o — navy sidebar/topbar theme */
const APP_SHELL_VERSION = '2026.07.11o';

(function applyShellThemeFallback() {
  if (typeof document === 'undefined' || document.getElementById('ph-shell-theme')) return;
  const style = document.createElement('style');
  style.id = 'ph-shell-theme';
  style.textContent = `
:root{
  --bg:#F8FAFC;--surface:#FFFFFF;--surface-2:#F1F5F9;--border:#E2E8F0;--text:#0F172A;--muted:#64748B;
  --primary:#2563EB;--primary-2:#3B82F6;--success:#22C55E;--warning:#F59E0B;--danger:#EF4444;
  --sidebar-bg:#0F172A;--topbar-bg:#16213E;--shell-text:#F8FAFC;--shell-muted:#94A3B8;
  --shell-border:rgba(226,232,240,.12);--shell-hover:rgba(248,250,252,.08);--shell-active:rgba(37,99,235,.22);
}
.sidebar{
  background:var(--sidebar-bg)!important;color:var(--shell-text)!important;
  border-right:1px solid var(--shell-border)!important;
}
.sidebar .brand,.sidebar .brand a,.sidebar .nav-item{color:var(--shell-text)!important}
.sidebar .brand .brand-logo{background:transparent!important;padding:0!important;border-radius:0!important}
.sidebar .nav-label,.sidebar .nav-item i,.sidebar .nav-chevron,.sidebar .text-muted-2{color:var(--shell-muted)!important}
.sidebar .nav-item:hover{background:var(--shell-hover)!important}
.sidebar .nav-item.active{
  color:#fff!important;background:var(--shell-active)!important;border-left-color:var(--primary)!important;
}
.sidebar .nav-item.active i{color:var(--primary-2)!important}
.sidebar .icon-btn{
  background:transparent!important;border-color:var(--shell-border)!important;color:var(--shell-text)!important;
}
#settingsNav.nav-pills .nav-link{color:var(--text)!important;border-left:0!important}
#settingsNav.nav-pills .nav-link:hover{color:var(--text)!important;background:var(--surface-2)!important}
#settingsNav.nav-pills .nav-link.active{color:#fff!important;background:var(--primary)!important}
.topbar{
  background:var(--topbar-bg)!important;color:var(--shell-text)!important;
  border-bottom:1px solid var(--shell-border)!important;
}
.topbar .fw-semibold,.topbar .small{color:var(--shell-text)!important}
.topbar .text-muted-2{color:var(--shell-muted)!important}
.topbar .search input{
  background:rgba(248,250,252,.08)!important;border-color:var(--shell-border)!important;color:var(--shell-text)!important;
  padding-left:2.55rem!important;
}
.topbar .icon-btn{
  background:transparent!important;border-color:var(--shell-border)!important;color:var(--shell-text)!important;
}
.topbar .btn-outline-primary{color:#fff!important;border-color:rgba(255,255,255,.28)!important}
.topbar .btn-outline-secondary{color:var(--shell-text)!important;border-color:rgba(255,255,255,.22)!important}
.btn-primary{background:var(--primary)!important;border-color:var(--primary)!important;color:#fff!important}
.btn-primary:hover,.btn-primary:focus,.btn-primary:active,.btn-primary:focus-visible{
  background:var(--primary-2)!important;border-color:var(--primary-2)!important;color:#fff!important;
}
.btn-outline-primary{color:var(--primary)!important;border-color:var(--primary)!important;background:transparent!important}
.btn-outline-primary:hover,.btn-outline-primary:focus,.btn-outline-primary:active,.btn-outline-primary:focus-visible{
  background:var(--primary)!important;border-color:var(--primary)!important;color:#fff!important;
}
`;
  const parent = document.head || document.documentElement;
  parent.appendChild(style);
})();

const NAV = [
  { section: "Overview", roles: ROLES },
  { href: "dashboard.html", icon: "bi-grid-1x2-fill", label: "Dashboard", roles: ROLES },

  { section: "Placement", roles: ['admin', 'placement_officer', 'student', 'staff', 'alumni'] },
  { href: "drives.html", icon: "bi-briefcase-fill", label: "Placement Drives", roles: ['admin', 'placement_officer', 'staff'] },
  { href: "drives.html", icon: "bi-search", label: "Browse & Apply", roles: ['student'], studentOnly: true },
  { href: "get-placed.html", icon: "bi-briefcase-fill", label: "Placement details", roles: ['student'], studentOnly: true },
  { href: "drives.html", icon: "bi-search", label: "Apply for Jobs", roles: ['alumni'], alumniSeeking: true },
  { href: "students.html", icon: "bi-people-fill", label: "Students", roles: ['admin', 'placement_officer', 'staff'] },
  { href: "users.html", icon: "bi-person-gear", label: "User Management", roles: ['admin'] },
  { href: "admin-companies.html", icon: "bi-building-check", label: "Companies & Referrals", roles: ['admin', 'placement_officer'] },
  { href: "reports.html", icon: "bi-file-earmark-bar-graph", label: "Reports", roles: ['admin', 'placement_officer'] },
  { href: "admin-settings.html", icon: "bi-gear-wide-connected", label: "System Settings", roles: ['admin'] },

  { section: "Alumni", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-jobs.html", icon: "bi-megaphone-fill", label: "Job Posts", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-referrals.html", icon: "bi-share-fill", label: "Referrals", roles: ['alumni'], alumniEmployed: true },
  { href: "alumni-success-stories.html", icon: "bi-star-fill", label: "Success Stories", roles: ['alumni'], alumniEmployed: true },

  { section: "Staff", roles: ['staff'] },
  { href: "staff-placements.html", icon: "bi-mortarboard-fill", label: "Placements & Higher Ed", roles: ['staff'] },
  { href: "staff-recommend.html", icon: "bi-building-add", label: "Recommend Company", roles: ['staff'] },

  { section: "Company", roles: ['admin', 'placement_officer', 'company'] },
  { href: "company.html", icon: "bi-building", label: "Company Portal", roles: ['company'] },
  { href: "applicants.html", icon: "bi-person-lines-fill", label: "Applicants", roles: ['company'] },
  { href: "recruiting.html", icon: "bi-diagram-3-fill", label: "Recruitment", roles: ['company'] },
  { href: "eligibility.html", icon: "bi-check2-square", label: "Eligibility Criteria", roles: ['company'] },

  { section: "Insights", roles: ['staff'] },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['staff'] },

  { section: "Account", roles: ['admin', 'placement_officer', 'student', 'staff', 'alumni', 'company'] },
  { href: "notifications.html", icon: "bi-bell-fill", label: "Notifications", roles: ['admin', 'placement_officer', 'student', 'staff', 'alumni', 'company'] },
  { href: "settings.html", icon: "bi-gear-fill", label: "Settings", roles: ['admin', 'placement_officer', 'staff', 'alumni', 'company'] },
  { href: "settings.html", icon: "bi-person-badge", label: "Profile & Resumes", roles: ['student'], studentOnly: true },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ['admin', 'placement_officer', 'alumni'] },
];

const PAGE_LABELS = {
  'dashboard.html': 'Dashboard',
  'analytics.html': 'Analytics',
  'drives.html': 'Placement Drives',
  'create-drive.html': 'Create Drive',
  'placement-console.html': 'Placement Drives · Console',
  'students.html': 'Students',
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
  'get-placed.html': 'Placement details',
  'public-stats.html': 'Public Portal',
  'alumni-jobs.html': 'Job Posts',
  'alumni-referrals.html': 'Referrals',
  'alumni-success-stories.html': 'Success Stories',
  'staff-recommend.html': 'Recommend Company',
  'staff-placements.html': 'Placements & Higher Education',
  'users.html': 'User Management',
  'rules.html': 'Placement Rules',
  'applications.html': 'Student · Management · Application',
  'blacklist.html': 'Student · Management · Blacklist',
  'results.html': 'Recruitment Results',
  'admin-settings.html': 'System Settings',
};

function initials(name = '') {
  return name.trim().split(/\s+/).map(s => s[0]).slice(0, 2).join('').toUpperCase() || 'U';
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

function shellProfileHref(role) {
  return 'settings.html';
}

function shellProfileLabel(role) {
  return 'Profile';
}

const SHELL_TOPBAR_BTN_STYLE = [
  'width:38px', 'height:38px', 'min-width:38px', 'min-height:38px', 'flex:0 0 38px',
  'padding:0', 'margin:0', 'border:0', 'border-radius:50%', 'overflow:hidden',
  'background:transparent', 'line-height:0', 'cursor:pointer',
  'display:inline-flex', 'align-items:center', 'justify-content:center',
  'box-shadow:none', 'outline:none', '-webkit-appearance:none', 'appearance:none',
].join(';');

const SHELL_SIDEBAR_WRAP_STYLE = [
  'width:36px', 'height:36px', 'min-width:36px', 'min-height:36px', 'flex:0 0 36px',
  'border-radius:50%', 'overflow:hidden', 'display:inline-block', 'line-height:0', 'vertical-align:middle',
].join(';');

function shellPhotoCircleHtml(user, size, fontSize = '.85rem') {
  const url = userPhotoUrl(user);
  const ini = initials(user?.name);
  const circle = [
    `display:block`, `width:${size}px`, `height:${size}px`,
    `min-width:${size}px`, `min-height:${size}px`,
    'border-radius:50%', 'overflow:hidden', 'border:0', 'box-shadow:none', 'margin:0', 'padding:0',
    'pointer-events:none',
  ].join(';');
  if (url) {
    const safe = encodeURI(url).replace(/'/g, '%27');
    return `<span class="shell-avatar-photo" data-initials="${escapeAttr(ini)}" style="${circle};background:url('${safe}') center/cover no-repeat" role="img" aria-label="${escapeAttr(user?.name || 'User')}" title="${escapeAttr(user?.name || '')}"></span>`;
  }
  return `<span style="${circle};background:linear-gradient(135deg,#2563EB,#3B82F6);color:#fff;display:grid;place-items:center;font-weight:700;font-size:${fontSize}">${ini}</span>`;
}

function topbarProfileMenuHtml(user, role) {
  const profileLabel = shellProfileLabel(role);
  return `
    <div class="topbar-profile-menu" id="topbarProfileMenu">
      <button type="button" class="topbar-avatar-btn" id="topbarProfileBtn" style="${SHELL_TOPBAR_BTN_STYLE}" aria-expanded="false" aria-haspopup="true" aria-controls="topbarProfileDropdown" aria-label="Account menu" title="${escapeAttr(user?.name || 'Account')}">
        ${shellPhotoCircleHtml(user, 38, '.8rem')}
      </button>
      <div class="topbar-profile-dropdown" id="topbarProfileDropdown" hidden>
        <div class="topbar-profile-dropdown-name">${escapeAttr(user?.name || 'Account')}</div>
        <a class="topbar-profile-dropdown-item" id="topbarProfileLink" href="${shellProfileHref(role)}"><i class="bi bi-person"></i><span>${profileLabel}</span></a>
        <button type="button" class="topbar-profile-dropdown-item is-danger" id="topbarLogoutBtn"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
      </div>
    </div>`;
}

function setTopbarProfileMenuOpen(open) {
  const menu = document.getElementById('topbarProfileMenu');
  const btn = document.getElementById('topbarProfileBtn');
  const panel = document.getElementById('topbarProfileDropdown');
  if (!menu || !btn || !panel) return;
  menu.classList.toggle('is-open', open);
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  panel.hidden = !open;
}

async function handleTopbarLogout(e) {
  e?.preventDefault?.();
  e?.stopPropagation?.();
  setTopbarProfileMenuOpen(false);
  let confirmed = true;
  if (typeof confirmAction === 'function') {
    confirmed = await confirmAction({
      title: 'Sign out',
      message: 'Sign out of your account?',
      confirmText: 'Sign out',
      variant: 'warning',
    });
  }
  if (!confirmed) return;
  if (typeof Auth !== 'undefined' && typeof Auth.logout === 'function') {
    Auth.logout();
    return;
  }
  window.location.href = 'public-stats.html';
}

function bindTopbarProfileMenu() {
  const menu = document.getElementById('topbarProfileMenu');
  const btn = document.getElementById('topbarProfileBtn');
  const logoutBtn = document.getElementById('topbarLogoutBtn');
  if (!menu || !btn || btn.dataset.bound === '1') {
    if (logoutBtn) bindLogoutButton(logoutBtn);
    return;
  }
  btn.dataset.bound = '1';

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const open = btn.getAttribute('aria-expanded') !== 'true';
    setTopbarProfileMenuOpen(open);
  });

  logoutBtn?.addEventListener('click', (e) => {
    handleTopbarLogout(e).catch(() => { });
  });

  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target)) setTopbarProfileMenuOpen(false);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') setTopbarProfileMenuOpen(false);
  });
}

function bindLogoutButton(btn) {
  if (!btn || btn.dataset.bound === '1') return;
  btn.dataset.bound = '1';
  btn.addEventListener('click', (e) => {
    handleTopbarLogout(e).catch(() => { });
  });
}

function hydrateShellAvatars() {
  const url = userPhotoUrl(Auth.user());
  if (!url) return;
  const safe = encodeURI(url).replace(/'/g, '%27');
  document.querySelectorAll('.shell-avatar-photo').forEach((el) => {
    el.style.backgroundImage = `url('${safe}')`;
    el.style.backgroundSize = 'cover';
    el.style.backgroundPosition = 'center';
  });
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
          <span class="sidebar-avatar-wrap" style="${SHELL_SIDEBAR_WRAP_STYLE}">${shellPhotoCircleHtml(user, 36, '.85rem')}</span>
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
      admin: '',
      placement_officer: '',
      student: '',
      staff: '',
      company: '<a href="company.html" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex">Portal</a><a href="applicants.html" class="btn btn-sm btn-outline-primary d-none d-lg-inline-flex">Applicants</a>',
      alumni: '',
    }[role] || '';

    const displayName = String(user?.name || '').trim();
    const useNameInTopbar = (
      (['student', 'alumni'].includes(role) && activeBase === 'dashboard.html')
      || (role === 'staff' && activeBase === 'dashboard.html')
    ) && displayName;
    const topbarTitle = useNameInTopbar ? displayName : pageLabel;
    const topbarSub = useNameInTopbar
      ? ''
      : (role === 'student' || role === 'staff' || role === 'alumni' || role === 'admin' || role === 'placement_officer' ? '' : `${ROLE_LABELS[role]} workspace`);
    topbar.innerHTML = `
      <button class="icon-btn d-lg-none" id="menuBtn" aria-label="Menu"><i class="bi bi-list"></i></button>
      ${showTopbarTitle ? `<div class="d-none d-md-block">
        <div class="fw-semibold" style="font-size:.95rem;line-height:1.2">${escapeAttr(topbarTitle)}</div>
        ${topbarSub ? `<div class="small text-muted-2">${escapeAttr(topbarSub)}</div>` : ''}
      </div>` : ''}
      <div class="flex-grow-1"></div>
      <div class="ms-auto topbar-actions d-flex align-items-center gap-2">
        ${quickLinks}
        ${Auth.isDemo() ? `<div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Preview as role">
            <i class="bi bi-person-badge me-1"></i><span class="d-none d-sm-inline">${ROLE_LABELS[role]}</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header">Preview as role</h6></li>
            ${ROLES.map(r => `<li><a class="dropdown-item ${r === role ? 'active' : ''}" href="#" data-role="${r}">${ROLE_LABELS[r]}</a></li>`).join('')}
          </ul>
        </div>` : `<span class="badge badge-soft ${ROLE_BADGES[role] || 'muted'} d-none d-sm-inline-flex align-items-center gap-1 px-2 py-1"><i class="bi bi-person-badge"></i>${ROLE_LABELS[role]}</span>`}
        ${'<a href="notifications.html" class="icon-btn" title="Notifications"><i class="bi bi-bell"></i><span class="dot"></span></a>'}
        ${topbarProfileMenuHtml(user, role)}
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

  document.documentElement.setAttribute("data-theme", "light");
  document.documentElement.setAttribute("data-density", UserPrefs.density());
  UserPrefs.setTheme("light");

  document.querySelectorAll('[data-role]').forEach(a => a.addEventListener('click', (e) => {
    e.preventDefault();
    const key = a.dataset.role;
    if (key === 'admin') {
      window.location.href = `public-stats.html?next=${encodeURIComponent('dashboard.html')}`;
      return;
    }
    const user = demoUserFor(key === 'alumni-seeking' ? 'alumni-seeking' : key);
    Auth.clear();
    Auth.set(user, 'demo-token');
    toast('Preview mode — sign in with your account for live data and saving changes.', 'info');
    window.location.reload();
  }));

  document.getElementById('logoutBtn') && bindLogoutButton(document.getElementById('logoutBtn'));
  bindTopbarProfileMenu();

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

  hydrateShellAvatars();
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
  if (!active || active === 'public-stats.html' || active === 'index.html' || active === 'aes-complete.html' || active === 'login.html' || active === 'placement-registration.html') return true;
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
  if (active && document.getElementById('sidebar')) {
    renderShell(active);
    hydrateShellAvatars();
  }
});

document.addEventListener('error', (event) => {
  const img = event.target;
  if (!(img instanceof HTMLImageElement)) return;
  const avatar = img.closest('.avatar.has-photo, .settings-profile-avatar.has-photo');
  if (!avatar) return;
  avatar.classList.remove('has-photo');
  avatar.style.backgroundImage = '';
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
  const isPublic = !active || active === 'public-stats.html' || active === 'index.html' || active === 'aes-complete.html' || active === 'login.html';

  if (isPublic) {
    document.documentElement.setAttribute('data-theme', UserPrefs.theme());
    document.documentElement.setAttribute('data-density', UserPrefs.density());
    if (active !== 'public-stats.html') {
      animateCounters();
    }
    document.dispatchEvent(new CustomEvent('ph-ready'));
    return;
  }

  const paintShell = () => {
    renderShell(active);
    applyRoleVisibility();
  };

  // Paint nav immediately from cached session so the sidebar is not blank during API calls.
  if (Auth.role() || Auth.user()) {
    paintShell();
  }

  let hasSession = await Auth.bootstrap();
  // Only retry once if we had a cached role but the first /auth/me failed (cookie race).
  if (!hasSession && (Auth.role() || Auth.user())) {
    hasSession = await Auth.bootstrap();
  }
  if (!hasSession && typeof ADMIN_ONLY_PAGES !== 'undefined' && ADMIN_ONLY_PAGES.includes(active)) {
    window.location.replace(`public-stats.html?next=${encodeURIComponent(active)}`);
    return;
  }
  if (!hasSession) {
    if (Auth.isDemo()) {
      // preview mode — read-only dashboards only
    } else {
      const roleHint = Auth.role() || Auth.user()?.role || '';
      Auth.clear();
      window.location.href = authReentryUrl(active, roleHint);
      return;
    }
  }

  // First-time students must complete Placement Cell registration before any other page.
  if (typeof studentNeedsPlacementRegistration === 'function' && studentNeedsPlacementRegistration()) {
    if (active !== 'placement-registration.html') {
      window.location.replace('placement-registration.html');
      return;
    }
  } else if (active === 'placement-registration.html' && Auth.role() === 'student') {
    window.location.replace(Auth.homePage() || 'drives.html');
    return;
  }

  if (!enforcePageRole(active)) return;
  paintShell();

  if (Auth.hasRealAuth() && active !== 'settings.html') {
    Auth.enrichFromProfile()
      .then(() => {
        const page = document.body?.dataset?.page;
        if (page) renderShell(page);
      })
      .catch(() => { });
  }

  animateCounters();
  // Defer badge refresh so it doesn't contend with the page's first data fetch.
  setTimeout(() => { NotificationInbox.refreshBadge?.(); }, 0);
  document.dispatchEvent(new CustomEvent('ph-ready'));
  ReferralModals.init();
});

/** Shared staff / alumni referral modals — open from any page */
const ReferralModals = {
  phoneFieldHtml() {
    const group = typeof phoneFieldGroupHtml === 'function'
      ? phoneFieldGroupHtml({ name: 'contactNumber', placeholder: 'Phone number' })
      : `<input class="form-control" type="tel" name="contactNumber" required data-contact-phone="1"/>`;
    return `<div class="col-md-6">
      <label class="form-label small fw-semibold">Contact number</label>
      ${group}
    </div>`;
  },

  validateReferralForm(form) {
    const phoneInput = form.querySelector('[name="contactNumber"]');
    phoneInput?.setCustomValidity('');
    if (!form.checkValidity()) {
      form.reportValidity();
      return null;
    }
    const data = Object.fromEntries(new FormData(form).entries());
    const phone = typeof readPhoneFieldGroup === 'function'
      ? readPhoneFieldGroup(form)
      : String(data.contactNumber || '').trim();
    if (typeof isValidContactPhone !== 'function' || !isValidContactPhone(phone)) {
      toast('Enter a valid phone number.', 'warn');
      phoneInput?.focus();
      phoneInput?.setCustomValidity('Enter a valid phone number.');
      phoneInput?.reportValidity();
      return null;
    }
    phoneInput?.setCustomValidity('');
    data.contactNumber = typeof normalizeContactPhone === 'function'
      ? (normalizeContactPhone(phone) || phone)
      : phone;
    delete data.phoneCountryCode;
    return data;
  },

  mountStaff() {
    const existing = document.getElementById('staffRecommendModal');
    if (existing?.querySelector('[name="phoneCountryCode"]')) return;
    existing?.remove();
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="staffRecommendModal" tabindex="-1" aria-labelledby="staffRecommendModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="staffRecommendModalForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="staffRecommendModalLabel">Recommend a Company</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="max-height:min(70vh,640px);overflow-y:auto">
          <p class="small text-muted-2 mb-3">Share recruiter details for the placement cell. Only the admin can view contact person information.</p>
          <div class="row g-3">
            <div class="col-12"><label class="form-label small fw-semibold">Company name</label><input class="form-control" name="companyName" required placeholder="e.g. Razorpay"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">Company website (optional)</label><input class="form-control" name="companyWebsite" placeholder="https://company.com/careers"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact person</label><input class="form-control" name="hrName" required placeholder="e.g. Priya Menon"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Role in company</label><input class="form-control" name="contactRole" placeholder="e.g. Talent Acquisition Manager"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact person's mail</label><input class="form-control" type="email" name="hrEmail" required placeholder="contact@company.com"/></div>
            ${this.phoneFieldHtml()}
          </div>
          <div class="alert alert-info small mt-3 mb-0">Only the admin can view contact details and follow up with the company.</div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Submit Recommendation</button>
        </div>
      </form>
    </div>
  </div>
</div>`);
    const form = document.getElementById('staffRecommendModalForm');
    const phoneInput = form.querySelector('[name="contactNumber"]');
    phoneInput?.addEventListener('input', () => phoneInput.setCustomValidity(''));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = this.validateReferralForm(e.target);
      if (!data) return;
      if (!(await confirmAction({ title: 'Submit recommendation', message: `Recommend ${data.companyName || 'this company'} to the placement cell?`, confirmText: 'Submit', variant: 'primary' }))) return;
      const saved = await StaffRecs.add(data);
      if (!saved && Auth.hasRealAuth() && !Auth.isDemo()) {
        toast('Could not submit recommendation. Check the contact number and try again.', 'error');
        return;
      }
      bootstrap.Modal.getInstance(document.getElementById('staffRecommendModal'))?.hide();
      toast('Company recommended. The admin will review and contact them.', 'success');
      e.target.reset();
      document.dispatchEvent(new CustomEvent('ph-staff-recommend-added'));
    });
  },

  mountAlumni() {
    const existing = document.getElementById('alumniReferralModal');
    if (existing?.querySelector('[name="phoneCountryCode"]')) return;
    existing?.remove();
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="alumniReferralModal" tabindex="-1" aria-labelledby="alumniReferralModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="alumniReferralModalForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="alumniReferralModalLabel">Recommend a Company</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="max-height:min(70vh,640px);overflow-y:auto">
          <p class="small text-muted-2 mb-3">Share recruiter details for the placement cell. Only the admin can view contact person information.</p>
          <div class="row g-3">
            <div class="col-12"><label class="form-label small fw-semibold">Company name</label><input class="form-control" name="companyName" required placeholder="e.g. Razorpay"/></div>
            <div class="col-12"><label class="form-label small fw-semibold">Company website (optional)</label><input class="form-control" name="companyWebsite" placeholder="https://company.com/careers"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact person</label><input class="form-control" name="hrName" required placeholder="e.g. Priya Menon"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Role in company</label><input class="form-control" name="contactRole" placeholder="e.g. Talent Acquisition Manager"/></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Contact person's mail</label><input class="form-control" type="email" name="hrEmail" required placeholder="contact@company.com"/></div>
            ${this.phoneFieldHtml()}
          </div>
          <div class="alert alert-info small mt-3 mb-0">Only the admin can view contact details and follow up with the company.</div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i>Submit Recommendation</button>
        </div>
      </form>
    </div>
  </div>
</div>`);
    const form = document.getElementById('alumniReferralModalForm');
    const phoneInput = form.querySelector('[name="contactNumber"]');
    phoneInput?.addEventListener('input', () => phoneInput.setCustomValidity(''));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = this.validateReferralForm(e.target);
      if (!data) return;
      if (!(await confirmAction({ title: 'Submit recommendation', message: `Recommend ${data.companyName || 'this company'} to the placement cell?`, confirmText: 'Submit', variant: 'primary' }))) return;
      const saved = await AlumniReferrals.add(data);
      if (!saved && Auth.hasRealAuth() && !Auth.isDemo()) {
        toast('Could not submit recommendation. Check the contact number and try again.', 'error');
        return;
      }
      bootstrap.Modal.getInstance(document.getElementById('alumniReferralModal'))?.hide();
      toast('Company recommended. The admin will review and contact them.', 'success');
      e.target.reset();
      document.dispatchEvent(new CustomEvent('ph-alumni-referral-added'));
    });
  },

  openStaff() {
    if (Auth.role() !== 'staff' && Auth.role() !== 'placement_officer') return;
    this.mountStaff();
    const form = document.getElementById('staffRecommendModalForm');
    form.reset();
    form.querySelector('[name="contactNumber"]')?.setCustomValidity('');
    const title = document.getElementById('staffRecommendModalLabel');
    if (title) title.textContent = 'Recommend a Company';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('staffRecommendModal')).show();
  },

  openOfficer() {
    if (Auth.role() !== 'placement_officer') return;
    this.openStaff();
  },

  openAlumni() {
    if (Auth.role() !== 'alumni') return;
    this.mountAlumni();
    const form = document.getElementById('alumniReferralModalForm');
    form.reset();
    form.querySelector('[name="contactNumber"]')?.setCustomValidity('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('alumniReferralModal')).show();
  },

  init() {
    if (location.hash !== '#create') return;
    const role = Auth.role();
    if (role === 'staff' || role === 'placement_officer') this.openStaff();
    else if (role === 'alumni' && alumniIsWorking()) this.openAlumni();
  },
};

window.openStaffRecommendModal = () => ReferralModals.openStaff();
window.openOfficerRecommendModal = () => ReferralModals.openOfficer();
window.openReferralModal = () => ReferralModals.openAlumni();

/** Hidden file input + Choose file button (avoids broken native PDF file chrome). */
window.wireFilePickers = function wireFilePickers(rootSelector = 'body') {
  document.querySelectorAll(`${rootSelector} [data-file-picker]`).forEach((btn) => {
    if (btn.dataset.pickerWired === '1') return;
    btn.dataset.pickerWired = '1';
    const id = btn.getAttribute('data-file-picker');
    const input = document.getElementById(id);
    const nameEl = document.querySelector(`[data-file-picker-name="${id}"]`);
    if (!input) return;
    btn.addEventListener('click', () => {
      if (input.disabled || btn.disabled) return;
      input.click();
    });
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      const files = input.files;
      if (!nameEl) return;
      if (input.multiple && files?.length > 1) {
        const names = [...files].map(f => f.name).filter(Boolean);
        nameEl.textContent = names.length > 2
          ? `${names.length} files selected`
          : names.join(', ');
      } else if (input.multiple && files?.length === 1) {
        nameEl.textContent = file?.name || 'No file chosen';
      } else {
        nameEl.textContent = file?.name || 'No file chosen';
      }
      nameEl.classList.toggle('has-file', !!(file || (files && files.length)));
    });
  });
};

window.resetFilePickers = function resetFilePickers(ids) {
  (ids || []).forEach((id) => {
    const input = document.getElementById(id);
    if (input) input.value = '';
    const nameEl = document.querySelector(`[data-file-picker-name="${id}"]`);
    if (nameEl) {
      nameEl.textContent = 'No files chosen';
      nameEl.classList.remove('has-file');
    }
  });
};