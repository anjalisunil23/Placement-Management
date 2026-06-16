/* Shared app shell: sidebar, topbar, theme toggle, counters */
const NAV = [
  { section: "Overview" },
  { href: "dashboard.html", icon: "bi-grid-1x2-fill", label: "Dashboard" },
  { href: "analytics.html", icon: "bi-bar-chart-fill", label: "Analytics" },
  { section: "Placement" },
  { href: "drives.html", icon: "bi-briefcase-fill", label: "Placement Drives" },
  { href: "create-drive.html", icon: "bi-plus-square", label: "Create Drive" },
  { href: "tracking.html", icon: "bi-graph-up-arrow", label: "Placement Tracking" },
  { section: "Students" },
  { href: "students.html", icon: "bi-people-fill", label: "Student Registration" },
  { href: "eligibility.html", icon: "bi-check2-square", label: "Eligibility" },
  { section: "Company" },
  { href: "company.html", icon: "bi-building", label: "Company Portal" },
  { href: "applicants.html", icon: "bi-person-lines-fill", label: "Applicants" },
  { section: "Insights" },
  { href: "reports.html", icon: "bi-file-earmark-bar-graph", label: "Reports" },
  { href: "notifications.html", icon: "bi-bell-fill", label: "Notifications" },
  { section: "Other" },
  { href: "public-stats.html", icon: "bi-globe2", label: "Public Portal" },
  { href: "settings.html", icon: "bi-gear-fill", label: "Settings" },
];

function renderShell(active) {
  const sidebar = document.getElementById("sidebar");
  const topbar = document.getElementById("topbar");
  if (sidebar) {
    sidebar.innerHTML = `
      <div class="brand">
        <div class="brand-mark">P</div>
        <div>PlaceHub<div style="font-size:.7rem;color:var(--muted);font-weight:500">Placement Suite</div></div>
      </div>
      <div class="nav-section">
        ${NAV.map(n => n.section
          ? `<div class="nav-label">${n.section}</div>`
          : `<a class="nav-item ${n.href === active ? "active" : ""}" href="${n.href}">
               <i class="bi ${n.icon}"></i><span>${n.label}</span>
             </a>`
        ).join("")}
      </div>
      <div style="padding:1rem;border-top:1px solid var(--border)">
        <div class="d-flex align-items-center gap-2">
          <div class="avatar" style="width:36px;height:36px;font-size:.85rem">RA</div>
          <div style="min-width:0">
            <div style="font-size:.85rem;font-weight:600" class="text-truncate">Riya Ahuja</div>
            <div style="font-size:.72rem;color:var(--muted)">TPO Admin</div>
          </div>
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
        <button class="icon-btn" id="themeBtn" title="Theme"><i class="bi bi-moon-stars"></i></button>
        <a href="notifications.html" class="icon-btn" title="Notifications"><i class="bi bi-bell"></i><span class="dot"></span></a>
        <a href="settings.html" class="icon-btn d-none d-sm-grid" title="Settings"><i class="bi bi-gear"></i></a>
        <div class="avatar">RA</div>
      </div>`;
  }
  // Mobile
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
}

/* Animated counters */
function animateCounters(root = document) {
  root.querySelectorAll(".counter").forEach(el => {
    const target = parseFloat(el.dataset.target || "0");
    const decimals = parseInt(el.dataset.decimals || "0");
    const prefix = el.dataset.prefix || "";
    const suffix = el.dataset.suffix || "";
    let cur = 0;
    const step = target / 60;
    const tick = () => {
      cur += step;
      if (cur >= target) { cur = target; el.textContent = prefix + cur.toFixed(decimals) + suffix; return; }
      el.textContent = prefix + cur.toFixed(decimals) + suffix;
      requestAnimationFrame(tick);
    };
    tick();
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const active = document.body.dataset.page;
  renderShell(active);
  animateCounters();
});
