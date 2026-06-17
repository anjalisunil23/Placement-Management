/* Shared app shell: sidebar, topbar, theme toggle, role gate, counters */

const NAV = [
  { section: "Overview", roles: ROLES },
  { href: "dashboard.html", icon: "bi-grid-1x2-fill", label: "Dashboard", roles: ROLES },
  { href: "analytics.html", icon: "bi-bar-chart-fill", label: "Analytics", roles: ['admin','placement_officer'] },

  { section: "Placement", roles: ['admin','placement_officer','student','alumni','company'] },
  { href: "drives.html", icon: "bi-briefcase-fill", label: "Placement Drives", roles: ['admin','placement_officer','student','alumni','company','staff'] },
  { href: "create-drive.html", icon: "bi-plus-square", label: "Create Drive", roles: ['admin','placement_officer'] },
  { href: "tracking.html", icon: "bi-graph-up-arrow", label: "Placement Tracking", roles: ['admin','placement_officer','company'] },

  { section: "Students", roles: ['admin','placement_officer'] },
  { href: "students.html", icon: "bi-people-fill", label: "Student Registration", roles: ['admin','placement_officer'] },

  { section: "Eligibility & Rules", roles: ROLES },
  { href: "eligibility.html", icon: "bi-check2-square", label: "Eligibility", roles: ROLES },

  { section: "Company", roles: ['admin','placement_officer','company','staff'] },
  { href: "company.html", icon: "bi-building", label: "Company Portal", roles: ['admin','placement_officer','company','staff'] },
  { href: "applicants.html", icon: "bi-person-lines-fill", label: "Applicants", roles: ['admin','placement_officer','company'] },

  { section: "Insights", roles: ['admin','placement_officer'] },
  { href: "reports.html", icon: "bi-file-earmark-bar-graph", label: "Reports", roles: ['admin','placement_officer'] },

  { section: "Other", roles: ROLES },
  { href: "notifications.html", icon: "bi-bell-fill", label: "Notifications", roles: ROLES },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal", roles: ROLES },
  { href: "settings.html", icon: "bi-gear-fill", label: "Settings", roles: ROLES },
];

function initials(name='') {
  return name.trim().split(/\s+/).map(s=>s[0]).slice(0,2).join('').toUpperCase() || 'U';
}

function filteredNav() {
  const role = Auth.role();
  const out = [];
  for (let i = 0; i < NAV.length; i++) {
    const n = NAV[i];
    if (!n.roles.includes(role)) continue;
    if (n.section) {
      // include section only if at least one following non-section entry is visible before next section
      let hasChild = false;
      for (let j = i + 1; j < NAV.length && !NAV[j].section; j++) {
        if (NAV[j].roles.includes(role)) { hasChild = true; break; }
      }
      if (hasChild) out.push(n);
    } else {
      out.push(n);
    }
  }
  return out;
}

function renderShell(active) {
  const user = Auth.user() || demoUserFor(Auth.role());
  const role = Auth.role();
  const sidebar = document.getElementById("sidebar");
  const topbar = document.getElementById("topbar");

  if (sidebar) {
    sidebar.innerHTML = `
      <div class="brand">
        <div class="brand-mark">P</div>
        <div>PlaceHub<div style="font-size:.7rem;color:var(--muted);font-weight:500">Placement Suite</div></div>
      </div>
      <div class="nav-section">
        ${filteredNav().map(n => n.section
          ? `<div class="nav-label">${n.section}</div>`
          : `<a class="nav-item ${n.href === active ? "active" : ""}" href="${n.href}">
               <i class="bi ${n.icon}"></i><span>${n.label}</span>
             </a>`
        ).join("")}
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
    topbar.innerHTML = `
      <button class="icon-btn d-lg-none" id="menuBtn" aria-label="Menu"><i class="bi bi-list"></i></button>
      <div class="search">
        <i class="bi bi-search"></i>
        <input placeholder="Search drives, students, companies…" />
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
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
        <a href="notifications.html" class="icon-btn" title="Notifications"><i class="bi bi-bell"></i><span class="dot"></span></a>
        <a href="settings.html" class="icon-btn d-none d-sm-grid" title="Settings"><i class="bi bi-gear"></i></a>
        <div class="avatar">${initials(user.name)}</div>
      </div>`;
  }

  // Mobile drawer
  const menuBtn = document.getElementById("menuBtn");
  const back = document.createElement("div");
  back.className = "backdrop";
  document.body.appendChild(back);
  menuBtn?.addEventListener("click", () => { sidebar.classList.add("open"); back.classList.add("show"); });
  back.addEventListener("click", () => { sidebar.classList.remove("open"); back.classList.remove("show"); });

  // Theme
  const saved = localStorage.getItem("ph-theme") || "light";
  document.documentElement.setAttribute("data-theme", saved);
  document.getElementById("themeBtn")?.addEventListener("click", () => {
    const cur = document.documentElement.getAttribute("data-theme") === "dark" ? "light" : "dark";
    document.documentElement.setAttribute("data-theme", cur);
    localStorage.setItem("ph-theme", cur);
    document.dispatchEvent(new CustomEvent("themechange", { detail: cur }));
  });

  // Role switcher (preview)
  document.querySelectorAll('[data-role]').forEach(a => a.addEventListener('click', (e) => {
    e.preventDefault();
    Auth.setRole(a.dataset.role);
    window.location.reload();
  }));

  // Logout
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

/* Page-level role gate. Redirects to dashboard when role can't view this page. */
function enforcePageRole(active) {
  // login & public-stats are public
  if (!active || active === 'login.html' || active === 'public-stats.html' || active === 'index.html') return true;
  if (!Auth.isAuthed()) {
    // Seed a demo user so the preview is interactive without forcing login
    Auth.setRole(Auth.role());
  }
  if (!Auth.isAllowed(active)) {
    toast(`This page isn't available for ${ROLE_LABELS[Auth.role()]}.`, 'warn');
    setTimeout(() => window.location.href = 'dashboard.html', 600);
    return false;
  }
  return true;
}

document.addEventListener("DOMContentLoaded", () => {
  const active = document.body.dataset.page;
  if (!enforcePageRole(active)) return;
  renderShell(active);
  applyRoleVisibility();
  animateCounters();
});
