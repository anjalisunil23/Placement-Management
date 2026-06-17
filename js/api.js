/* PlaceHub — API client, auth state, role permissions, mock fallback */
const ROLES = ['admin','placement_officer','student','staff','company','alumni'];

const ROLE_LABELS = {
  admin: 'Administrator',
  placement_officer: 'Placement Officer',
  student: 'Student',
  staff: 'Faculty / Staff',
  company: 'Company Recruiter',
  alumni: 'Alumni',
};

const ROLE_BADGES = {
  admin: 'danger',
  placement_officer: 'primary',
  student: 'info',
  staff: 'warning',
  company: 'success',
  alumni: 'muted',
};

/* Which roles can visit which page. Page = filename. */
const PAGE_PERMS = {
  'dashboard.html':     ROLES,
  'analytics.html':     ['admin','placement_officer'],
  'drives.html':        ['admin','placement_officer','student','alumni','company','staff'],
  'create-drive.html':  ['admin','placement_officer'],
  'tracking.html':      ['admin','placement_officer','company'],
  'students.html':      ['admin','placement_officer'],
  'eligibility.html':   ROLES,
  'company.html':       ['admin','placement_officer','company','staff'],
  'applicants.html':    ['admin','placement_officer','company'],
  'reports.html':       ['admin','placement_officer'],
  'notifications.html': ROLES,
  'public-stats.html':  ROLES,
  'settings.html':      ROLES,
};

const API_BASE =
  (typeof window !== 'undefined' && window.VITE_API_BASE_URL) ||
  localStorage.getItem('ph-api-base') ||
  'http://localhost:8000/backend/api';

const Auth = {
  user() { try { return JSON.parse(localStorage.getItem('ph-user') || 'null'); } catch { return null; } },
  token() { return localStorage.getItem('ph-token') || ''; },
  role() {
    const u = this.user();
    return (u && u.role) || localStorage.getItem('ph-role') || 'placement_officer';
  },
  set(user, token) {
    if (user) localStorage.setItem('ph-user', JSON.stringify(user));
    if (token) localStorage.setItem('ph-token', token);
    if (user?.role) localStorage.setItem('ph-role', user.role);
  },
  setRole(role) {
    const u = this.user() || demoUserFor(role);
    u.role = role;
    u.name = u.name || demoUserFor(role).name;
    this.set(u, this.token() || 'demo-token');
    localStorage.setItem('ph-role', role);
  },
  clear() {
    localStorage.removeItem('ph-user');
    localStorage.removeItem('ph-token');
    localStorage.removeItem('ph-role');
  },
  logout() { this.clear(); window.location.href = 'login.html'; },
  isAllowed(page) { return (PAGE_PERMS[page] || ROLES).includes(this.role()); },
  isAuthed() { return !!this.user(); },
};

function demoUserFor(role) {
  const map = {
    admin:             { name:'Dr. Anjali Mehra',   email:'admin@placehub.app',     role:'admin' },
    placement_officer: { name:'Riya Ahuja',         email:'riya@college.edu',       role:'placement_officer' },
    student:           { name:'Karthik Subramanian',email:'karthik.s@college.edu',  role:'student',  registerNumber:'22MCA047', department:'MCA', cgpa:8.7, backlogs:0 },
    staff:             { name:'Prof. Ravi Iyer',    email:'ravi.iyer@college.edu',  role:'staff',    department:'CSE', designation:'Associate Professor' },
    company:           { name:'Neha Sharma',        email:'neha@acme.io',           role:'company',  companyName:'Acme Cloud', category:'Product', tier:'Tier 1' },
    alumni:            { name:'Rohan Verma',        email:'rohan.v@alumni.edu',     role:'alumni',   company:'Google', title:'SWE II', experience:3 },
  };
  return map[role] || map.placement_officer;
}

/* Generic fetch with bearer, 401 redirect, and { success, message, data } shape */
async function api(path, opts = {}) {
  const token = Auth.token();
  const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const body = opts.body && typeof opts.body !== 'string' ? JSON.stringify(opts.body) : opts.body;
  try {
    const res = await fetch(API_BASE + path, { method: opts.method || 'GET', headers, body });
    if (res.status === 401) { Auth.clear(); window.location.href = 'login.html'; return { success:false, message:'Session expired', data:null }; }
    const json = await res.json().catch(() => ({ success:false, message:'Bad response', data:null }));
    return json;
  } catch (e) {
    return { success:false, message:e.message || 'Network error', data:null, _offline:true };
  }
}

/* Mock login/register for the preview when no backend is reachable */
async function mockAuth(kind, payload) {
  await new Promise(r => setTimeout(r, 300));
  const role = payload.role || (payload.email?.includes('admin') ? 'admin' : 'placement_officer');
  const user = { ...demoUserFor(role), ...payload, role };
  return { success:true, message: kind==='register' ? 'Registered. Pending approval.' : 'Logged in', data: { user, token: 'demo-token-' + Date.now() } };
}

/* Toasts */
function toast(msg, kind='info') {
  let host = document.getElementById('ph-toasts');
  if (!host) {
    host = document.createElement('div');
    host.id = 'ph-toasts';
    host.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;max-width:340px';
    document.body.appendChild(host);
  }
  const el = document.createElement('div');
  el.className = `card-surface p-3 d-flex gap-2 align-items-start`;
  el.style.cssText = 'border-left:3px solid var(--' + ({success:'success',error:'danger',warn:'warning',info:'info'}[kind]||'primary') + ');animation:phSlide .25s ease';
  el.innerHTML = `<i class="bi bi-${kind==='success'?'check-circle-fill':kind==='error'?'exclamation-octagon-fill':'info-circle-fill'}" style="color:var(--${kind==='success'?'success':kind==='error'?'danger':'info'})"></i><div class="small flex-grow-1">${msg}</div>`;
  host.appendChild(el);
  setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3200);
}

/* Show/hide elements by role via data-roles="a,b" and data-not-roles="c" */
function applyRoleVisibility(root = document) {
  const role = Auth.role();
  root.querySelectorAll('[data-roles]').forEach(el => {
    const ok = el.dataset.roles.split(',').map(s=>s.trim()).includes(role);
    el.style.display = ok ? '' : 'none';
  });
  root.querySelectorAll('[data-not-roles]').forEach(el => {
    const blocked = el.dataset.notRoles.split(',').map(s=>s.trim()).includes(role);
    el.style.display = blocked ? 'none' : '';
  });
}
