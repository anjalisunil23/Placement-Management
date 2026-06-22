/* PlaceHub — API client, auth state, role permissions, mock fallback */
const APP_SCRIPT_VERSION = '20260619b';
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
  'drives.html':        ['admin','placement_officer','student','alumni','staff'],
  'create-drive.html':  ['admin','placement_officer'],
  'tracking.html':      ['admin','placement_officer'],
  'students.html':      ['admin','placement_officer','staff'],
  'eligibility.html':   ['company'],
  'company.html':       ['company'],
  'applicants.html':    ['company'],
  'reports.html':       ['admin','placement_officer'],
  'notifications.html': ['admin','placement_officer','student','staff','alumni','company'],
  'public-stats.html':  ROLES,
  'settings.html':      ['admin','placement_officer','student','staff','alumni','company'],
  'alumni-jobs.html':       ['alumni'],
  'alumni-referrals.html':  ['alumni'],
  'alumni-success-stories.html': ['alumni'],
  'staff-recommend.html':   ['staff'],
  'admin-companies.html':   ['admin','placement_officer'],
  'placement-console.html': ['admin','placement_officer'],
  'recruiting.html':        ['admin','placement_officer','company'],
  'student-overview.html':  ['admin','placement_officer','staff'],
  'hiring-overview.html':   ['admin','placement_officer','staff'],
  'users.html':             ['admin'],
  'rules.html':             ['admin'],
  'applications.html':      ['admin','placement_officer'],
  'resumes.html':           ['admin','placement_officer'],
  'blacklist.html':         ['admin'],
  'results.html':           ['admin','placement_officer'],
  'admin-settings.html':    ['admin'],
};

const ALUMNI_EMPLOYED_PAGES = ['dashboard.html', 'alumni-jobs.html', 'alumni-referrals.html', 'alumni-success-stories.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const ALUMNI_SEEKING_PAGES = ['dashboard.html', 'drives.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const COMPANY_PAGES = ['dashboard.html', 'eligibility.html', 'company.html', 'applicants.html', 'recruiting.html', 'notifications.html', 'settings.html'];
const STAFF_PAGES = ['dashboard.html', 'staff-recommend.html', 'drives.html', 'students.html', 'hiring-overview.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const STUDENT_PAGES = ['dashboard.html', 'drives.html', 'notifications.html', 'settings.html'];

/** Default landing page per role after sign-in */
const ROLE_HOME = {
  admin: 'dashboard.html',
  placement_officer: 'placement-console.html',
  staff: 'staff-recommend.html',
  student: 'drives.html',
  company: 'company.html',
  alumni: 'dashboard.html',
};

const ADMIN_ONLY_PAGES = [
  'users.html', 'rules.html', 'blacklist.html', 'admin-settings.html',
];

const RESUME_PROFILES = ['General', 'SDE / Full Stack', 'Data / ML', 'Product / Business', 'Core Engineering'];
const RESUME_BUCKET = 'placehub-resumes';

const DEPARTMENT_PLACEMENT = [
  { dept: 'CSE', students: 520, applicants: 1840, shortlisted: 520, selected: 186, placed: 412, pct: 79.2, avgPkg: 10.2 },
  { dept: 'IT', students: 420, applicants: 1520, shortlisted: 410, selected: 168, placed: 380, pct: 90.5, avgPkg: 9.8 },
  { dept: 'ECE', students: 380, applicants: 1280, shortlisted: 340, selected: 142, placed: 298, pct: 78.4, avgPkg: 8.6 },
  { dept: 'ME', students: 310, applicants: 890, shortlisted: 210, selected: 88, placed: 210, pct: 67.7, avgPkg: 6.4 },
  { dept: 'EE', students: 260, applicants: 760, shortlisted: 188, selected: 76, placed: 188, pct: 72.3, avgPkg: 7.1 },
  { dept: 'CE', students: 180, applicants: 520, shortlisted: 124, selected: 52, placed: 124, pct: 68.9, avgPkg: 5.8 },
  { dept: 'MCA', students: 240, applicants: 980, shortlisted: 280, selected: 118, placed: 222, pct: 92.5, avgPkg: 11.4 },
];

function placementDeptTotals() {
  return DEPARTMENT_PLACEMENT.reduce((t, d) => ({
    students: t.students + d.students,
    applicants: t.applicants + d.applicants,
    shortlisted: t.shortlisted + d.shortlisted,
    selected: t.selected + d.selected,
    placed: t.placed + d.placed,
  }), { students: 0, applicants: 0, shortlisted: 0, selected: 0, placed: 0 });
}

function alumniIsWorking() {
  const u = Auth.user();
  if (!u || u.role !== 'alumni') return false;
  if (typeof u.isWorking === 'boolean') return u.isWorking;
  return !!(u.company && String(u.company).trim());
}

function alumniPageAllowed(page) {
  return alumniIsWorking()
    ? ALUMNI_EMPLOYED_PAGES.includes(page)
    : ALUMNI_SEEKING_PAGES.includes(page);
}

const API_BASE =
  (typeof window !== 'undefined' && window.API_BASE_URL) ||
  localStorage.getItem('ph-api-base') ||
  '/backend/api';

/** Ensure a live server session before admin writes; redirects to login when needed. */
async function requireWriteSession() {
  Auth._sessionReady = false;
  if (await Auth.ensureSession()) return true;
  const page = document.body?.dataset?.page || 'dashboard.html';
  window.location.href = `login.html?next=${encodeURIComponent(page)}`;
  return false;
}

/** Real API login — returns { success, message?, redirect? } */
async function performServerLogin(email, password, next = '') {
  Auth.clear();
  const res = await api('/auth/login', {
    method: 'POST',
    body: { email, password },
    skipAuthRedirect: true,
    skipAuthRetry: true,
  });
  if (!res.success) {
    if (res._offline) {
      return { success: false, message: 'Cannot reach the server. Start it with: php -S 0.0.0.0:8080 router.php' };
    }
    return { success: false, message: res.message || 'Sign-in failed' };
  }
  const user = res.data?.user || res.data;
  if (!user || !user.role) {
    return { success: false, message: 'Sign-in response was invalid.' };
  }
  localStorage.setItem('ph-token', 'session');
  Auth.applySessionUser(user);
  Auth._sessionReady = false;
  const verified = await Auth.bootstrap();
  if (!verified) {
    Auth._sessionReady = true;
  }
  const redirect = Auth.resolveRedirect(next);
  if (user.role === 'company' && !Auth.isAllowed(redirect.split('#')[0])) {
    return { success: true, redirect: absAppPath(Auth.homePage('company')) };
  }
  if (user.role === 'alumni' && !Auth.isAllowed(redirect.split('#')[0])) {
    return { success: true, redirect: absAppPath(Auth.homePage('alumni')) };
  }
  return { success: true, redirect: absAppPath(redirect) };
}

/** Root-relative app path for reliable redirects from extensionless URLs. */
function absAppPath(path) {
  const raw = String(path || '').trim();
  if (!raw) return '/' + (ROLE_HOME[Auth.role()] || 'dashboard.html');
  return raw.startsWith('/') ? raw : '/' + raw;
}

const QUICK_LOGIN_ACCOUNTS = {
  admin: { email: 'admin@college.edu', password: 'Admin@123456' },
  placement_officer: { email: 'riya@college.edu', password: 'Officer@123456' },
  staff: { email: 'ravi.iyer@college.edu', password: 'Staff@123456' },
  student: { email: 'rahul.v@college.edu', password: 'Student@123456' },
  company: { email: 'neha@acme.io', password: 'Company@123456' },
  alumni: { email: 'rohan.v@alumni.edu', password: 'Alumni@123456' },
  'alumni-seeking': { email: 'priya.v@alumni.edu', password: 'Alumni@123456' },
};

const Auth = {
  user() { try { return JSON.parse(localStorage.getItem('ph-user') || 'null'); } catch { return null; } },
  token() { return localStorage.getItem('ph-token') || ''; },
  role() {
    const u = this.user();
    return (u && u.role) ? u.role : '';
  },
  homePage(role) {
    const u = this.user();
    const r = role || this.role();
    if (r === 'alumni' && u && typeof u.isWorking === 'boolean' && !u.isWorking) {
      return 'drives.html';
    }
    if (u?.dashboard) {
      const page = String(u.dashboard).replace(/^\//, '').split('#')[0];
      if (page && this.isAllowed(page)) return page;
    }
    return ROLE_HOME[r] || 'dashboard.html';
  },
  resolveRedirect(next) {
    const raw = (next || '').trim();
    if (!raw) return this.homePage();
    const page = raw.split('#')[0].split('?')[0].replace(/^\//, '');
    const hash = raw.includes('#') ? raw.slice(raw.indexOf('#')) : '';
    if (page && page !== 'login.html' && this.isAllowed(page)) {
      return page + hash;
    }
    return this.homePage();
  },
  applySessionUser(u) {
    if (!u) return;
    const prev = this.user() || {};
    this.set(
      {
        ...prev,
        id: u.id || u._id || prev.id || '',
        name: u.name || prev.name || '',
        email: u.email || prev.email || '',
        role: u.role || prev.role || '',
        department: u.department || prev.department || '',
        departmentId: u.departmentId || prev.departmentId || '',
        departmentName: u.departmentName || prev.departmentName || '',
        designation: u.designation || prev.designation || '',
        company: u.company ?? prev.company ?? '',
        companyName: u.companyName ?? prev.companyName ?? '',
        companyId: u.companyId || prev.companyId || '',
        registerNumber: u.registerNumber || prev.registerNumber || '',
        studentId: u.studentId || prev.studentId || '',
        classBatch: u.classBatch || prev.classBatch || '',
        cgpa: u.cgpa ?? prev.cgpa,
        backlogs: u.backlogs ?? prev.backlogs,
        placed: u.placed ?? prev.placed,
        title: u.title ?? prev.title ?? '',
        experience: u.experience ?? prev.experience,
        isWorking: u.isWorking ?? prev.isWorking,
        skills: u.skills || prev.skills || [],
        category: u.category || prev.category || '',
        tier: u.tier || prev.tier || '',
        website: u.website || prev.website || '',
        dashboard: u.dashboard || prev.dashboard || '',
      },
      'session'
    );
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
    this._sessionReady = false;
    localStorage.removeItem('ph-user');
    localStorage.removeItem('ph-token');
    localStorage.removeItem('ph-role');
  },
  logout() {
    apiFetch('/auth/logout', { method: 'POST', skipAuthRedirect: true, skipAuthRetry: true }).catch(() => {});
    this.clear();
    window.location.href = 'login.html';
  },
  isDemo() {
    const t = this.token();
    return !!t && t.startsWith('demo-token');
  },
  hasSession() { return this.token() === 'session'; },
  needsApiSession(page) {
    return [
      'reports.html', 'applications.html', 'resumes.html', 'results.html',
      'students.html', 'users.html', 'rules.html',
      'blacklist.html', 'admin-companies.html', 'admin-settings.html',
      'drives.html', 'create-drive.html',
    ].includes(page);
  },
  async bootstrap() {
    if (this._sessionReady === true) return true;
    const res = await apiFetch('/auth/me', { skipAuthRedirect: true, skipAuthRetry: true });
    if (!res.success || !res.data || !res.data.role) {
      this._sessionReady = false;
      return false;
    }
    this.applySessionUser(res.data);
    this._sessionReady = true;
    return true;
  },
  async ensureSession() {
    if (this._sessionReady === true) return true;
    return this.bootstrap();
  },
  hasLiveSession() { return this._sessionReady === true; },
  isAllowed(page) {
    const role = this.role();
    if (!role) return false;
    const base = (page || '').split('#')[0].split('?')[0];
    if (!(PAGE_PERMS[base] || ROLES).includes(role)) return false;
    if (role === 'placement_officer' && ADMIN_ONLY_PAGES.includes(base)) return false;
    if (role === 'staff' && ADMIN_ONLY_PAGES.includes(base)) return false;
    if (role === 'alumni') return alumniPageAllowed(base);
    if (role === 'company') return COMPANY_PAGES.includes(base);
    if (role === 'staff') return STAFF_PAGES.includes(base);
    if (role === 'student') return STUDENT_PAGES.includes(base);
    return true;
  },
  isAuthed() { return !!this.user(); },
  hasRealAuth() {
    const t = this.token();
    return !!t && !String(t).startsWith('demo-token');
  },
};

const UserPrefs = {
  storageKey: 'ph-user-prefs',
  read() {
    try { return JSON.parse(localStorage.getItem(this.storageKey) || '{}'); } catch { return {}; }
  },
  write(prefs) {
    localStorage.setItem(this.storageKey, JSON.stringify(prefs));
    return prefs;
  },
  theme() {
    return localStorage.getItem('ph-theme') || this.read().theme || 'light';
  },
  setTheme(theme) {
    const t = theme === 'dark' ? 'dark' : 'light';
    localStorage.setItem('ph-theme', t);
    document.documentElement.setAttribute('data-theme', t);
    const prefs = this.read();
    prefs.theme = t;
    prefs.darkMode = t === 'dark';
    this.write(prefs);
    document.dispatchEvent(new CustomEvent('themechange', { detail: t }));
    return t;
  },
  density() {
    return localStorage.getItem('ph-density') || this.read().density || 'comfortable';
  },
  setDensity(density) {
    const d = density === 'compact' ? 'compact' : 'comfortable';
    localStorage.setItem('ph-density', d);
    document.documentElement.setAttribute('data-density', d);
    const prefs = this.read();
    prefs.density = d;
    prefs.compactDensity = d === 'compact';
    this.write(prefs);
    return d;
  },
  notificationPrefs() {
    return this.read().notifications || {};
  },
  setNotificationPrefs(notifications) {
    const prefs = this.read();
    prefs.notifications = notifications;
    this.write(prefs);
  },
  integrationUserKey() {
    return (Auth.user()?.email || 'anonymous').toLowerCase();
  },
  defaultIntegrations() {
    return {
      google_workspace: { connected: true },
      slack: { connected: true },
      zoom: { connected: false },
      outlook: { connected: false },
    };
  },
  integrationPrefs() {
    const byUser = this.read().integrationsByUser || {};
    const saved = byUser[this.integrationUserKey()];
    if (saved) return { ...this.defaultIntegrations(), ...saved };
    return this.defaultIntegrations();
  },
  setIntegrationPrefs(integrations) {
    const prefs = this.read();
    prefs.integrationsByUser = prefs.integrationsByUser || {};
    prefs.integrationsByUser[this.integrationUserKey()] = integrations;
    this.write(prefs);
    return integrations;
  },
  setIntegrationConnected(key, connected) {
    const state = this.integrationPrefs();
    state[key] = {
      connected: !!connected,
      connectedAt: connected ? new Date().toISOString() : null,
    };
    return this.setIntegrationPrefs(state);
  },
  isIntegrationConnected(key) {
    return !!this.integrationPrefs()[key]?.connected;
  },
  apply() {
    document.documentElement.setAttribute('data-theme', this.theme());
    document.documentElement.setAttribute('data-density', this.density());
  },
  isDark() { return this.theme() === 'dark'; },
  isCompact() { return this.density() === 'compact'; },
};

if (typeof document !== 'undefined') {
  UserPrefs.apply();
}

const INTEGRATION_CATALOG = [
  { key: 'google_workspace', name: 'Google Workspace', icon: 'bi-google', desc: 'Sync calendar invites and drive announcements' },
  { key: 'slack', name: 'Slack', icon: 'bi-slack', desc: 'Post placement alerts to your team channel' },
  { key: 'zoom', name: 'Zoom', icon: 'bi-camera-video-fill', desc: 'Schedule interviews and virtual drives' },
  { key: 'outlook', name: 'Outlook', icon: 'bi-microsoft', desc: 'Send offer letters and updates via Microsoft 365' },
];

function demoUserFor(role) {
  const map = {
    admin:             { name:'Dr. Anjali Mehra',   email:'admin@placehub.app',     role:'admin' },
    placement_officer: { name:'Riya Ahuja',         email:'riya@college.edu',       role:'placement_officer' },
    student:           { name:'Karthik Subramanian',email:'karthik.s@college.edu',  role:'student',  registerNumber:'22MCA047', department:'MCA', cgpa:8.7, backlogs:0 },
    staff:             { name:'Prof. Ravi Iyer',    email:'ravi.iyer@college.edu',  role:'staff',    department:'CSE', designation:'Associate Professor' },
    company:           { name:'Neha Sharma',        email:'neha@acme.io',           role:'company',  companyName:'Acme Cloud', category:'Product', tier:'Tier 1' },
    alumni:            { name:'Rohan Verma',        email:'rohan.v@alumni.edu',     role:'alumni',   company:'Google', title:'SWE II', experience:3, isWorking:true },
    'alumni-seeking':  { name:'Priya Nair',         email:'priya.v@alumni.edu',     role:'alumni',   company:'', title:'', experience:2, isWorking:false },
  };
  return map[role] || map.placement_officer;
}

const STAFF_REC_KEY = 'ph-staff-recommendations';
const REG_COMPANIES_KEY = 'ph-registered-companies';

function seedStaffRecommendations() {
  if (localStorage.getItem(STAFF_REC_KEY)) return;
  const seed = [
    { id:'rec-demo-1', companyName:'Brillio', hrName:'Anita Desai', hrEmail:'anita.desai@brillio.com', contactNumber:'+91 98765 43210', staffName:'Prof. Ravi Iyer', staffEmail:'ravi.iyer@college.edu', submittedAt:'2025-11-14T10:00:00.000Z', status:'registered' },
    { id:'rec-demo-2', companyName:'Postman', hrName:'Kunal Shah', hrEmail:'kunal@postman.com', contactNumber:'+91 91234 56780', staffName:'Prof. Ravi Iyer', staffEmail:'ravi.iyer@college.edu', submittedAt:'2025-11-02T10:00:00.000Z', status:'contacted' },
    { id:'rec-demo-3', companyName:'Hasura', hrName:'Meera Nambiar', hrEmail:'meera@hasura.io', contactNumber:'+91 99887 76655', staffName:'Dr. Sunita Rao', staffEmail:'sunita.rao@college.edu', submittedAt:'2025-10-28T10:00:00.000Z', status:'pending' },
  ];
  localStorage.setItem(STAFF_REC_KEY, JSON.stringify(seed));
}

const StaffRecs = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedStaffRecommendations();
    try { return JSON.parse(localStorage.getItem(STAFF_REC_KEY) || '[]'); } catch { return []; }
  },
  save(list) { this._cache = list; localStorage.setItem(STAFF_REC_KEY, JSON.stringify(list)); },
  async fetch() {
    if (Auth.role() === 'staff' && Auth.hasRealAuth() && typeof StaffApi !== 'undefined') {
      const list = await StaffApi.fetchRecommendations();
      if (list) {
        this._cache = list;
        localStorage.setItem(STAFF_REC_KEY, JSON.stringify(list));
        return list;
      }
    }
    if (Auth.role() === 'admin' || Auth.role() === 'placement_officer') {
      const list = await AdminApi.fetchRecommendations();
      if (list) {
        this._cache = list;
        localStorage.setItem(STAFF_REC_KEY, JSON.stringify(list));
        return list;
      }
    }
    return this.all();
  },
  mine() {
    const email = Auth.user()?.email;
    return this.all().filter(r => r.staffEmail === email);
  },
  async add(payload) {
    const body = {
      companyName: payload.companyName,
      companyWebsite: payload.companyWebsite || '',
      category: payload.category || 'Software',
      reason: payload.reason || 'Referred by faculty for campus recruitment.',
      hrName: payload.hrName,
      hrEmail: payload.hrEmail,
      contactNumber: payload.contactNumber,
      contact: {
        name: payload.hrName,
        email: payload.hrEmail,
        phone: payload.contactNumber,
      },
    };
    const res = await api('/staff/recommendations', { method: 'POST', body });
    if (res.success) {
      if (Auth.role() === 'staff' && Auth.hasRealAuth()) {
        await this.fetch();
      } else {
        const u = Auth.user();
        const rec = {
          id: res.data?.id || ('rec-' + Date.now()),
          companyName: payload.companyName?.trim(),
          companyWebsite: payload.companyWebsite?.trim() || '',
          hrName: payload.hrName?.trim(),
          hrEmail: payload.hrEmail?.trim(),
          contactNumber: payload.contactNumber?.trim(),
          staffName: u?.name || 'Staff',
          staffEmail: u?.email || '',
          submittedAt: new Date().toISOString(),
          status: 'pending',
        };
        this.save([rec, ...this.all()]);
      }
      return res.data;
    }
    if (Auth.role() === 'staff' && Auth.hasRealAuth()) {
      return null;
    }
    const u = Auth.user();
    const rec = {
      id: 'rec-' + Date.now(),
      companyName: payload.companyName?.trim(),
      companyWebsite: payload.companyWebsite?.trim() || '',
      hrName: payload.hrName?.trim(),
      hrEmail: payload.hrEmail?.trim(),
      contactNumber: payload.contactNumber?.trim(),
      staffName: u?.name || 'Staff',
      staffEmail: u?.email || '',
      submittedAt: new Date().toISOString(),
      status: 'pending',
    };
    this.save([rec, ...this.all()]);
    return rec;
  },
  async updateStatus(id, status) {
    const res = await api(`/admin/recommendations/${encodeURIComponent(id)}/status`, { method: 'PUT', body: { status } });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().map(r => r.id === id ? { ...r, status } : r));
    return false;
  },
};

const RegisteredCompanies = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    try { return JSON.parse(localStorage.getItem(REG_COMPANIES_KEY) || '[]'); } catch { return []; }
  },
  save(list) { this._cache = list; localStorage.setItem(REG_COMPANIES_KEY, JSON.stringify(list)); },
  async fetch() {
    const list = await AdminApi.fetchCompanies();
    if (list) { this._cache = list; localStorage.setItem(REG_COMPANIES_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async register(payload) {
    if (!(await requireWriteSession())) return null;
    const res = await api('/admin/companies/register', { method: 'POST', body: payload });
    if (res.success) {
      await Promise.all([this.fetch(), StaffRecs.fetch()]);
      return res.data;
    }
    toast(res.message || 'Could not register company.', 'error');
    return null;
  },
  async addSimple(payload) {
    if (!(await requireWriteSession())) return null;
    const res = await api('/admin/companies', {
      method: 'POST',
      body: {
        companyName: payload.companyName,
        category: payload.category || 'Product',
        tier: payload.tier || 'Tier 2',
        website: payload.website || payload.companyWebsite || '',
      },
    });
    if (res.success) {
      await this.fetch();
      return res.data;
    }
    toast(res.message || 'Could not add company.', 'error');
    return null;
  },
  async update(companyId, payload) {
    if (!(await requireWriteSession())) return null;
    const body = {
      companyName: String(payload.companyName || '').trim(),
      website: String(payload.website || payload.companyWebsite || '').trim(),
      category: payload.category || 'Product',
      tier: payload.tier || 'Tier 2',
    };
    const hrName = String(payload.hrName || '').trim();
    const hrEmail = String(payload.hrEmail || '').trim();
    const contactNumber = String(payload.contactNumber || '').trim();
    if (hrName || hrEmail || contactNumber) {
      body.contacts = [{ name: hrName, email: hrEmail, phone: contactNumber }];
    }
    const res = await api(`/admin/companies/${encodeURIComponent(companyId)}`, { method: 'PUT', body });
    if (res.success) {
      await this.fetch();
      return true;
    }
    toast(res.message || 'Could not update company.', 'error');
    return null;
  },
  async remove(companyId) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/companies/${encodeURIComponent(companyId)}`, { method: 'DELETE' });
    if (res.success) {
      await this.fetch();
      return true;
    }
    toast(res.message || 'Could not delete company.', 'error');
    return false;
  },
};

const ALUMNI_JOBS_KEY = 'ph-alumni-job-posts';

function seedAlumniJobPosts() {
  if (localStorage.getItem(ALUMNI_JOBS_KEY)) return;
  localStorage.setItem(ALUMNI_JOBS_KEY, JSON.stringify([
    { id:'aj-1', title:'Senior SDE', company:'Google', type:'Full-time', package:'₹38 LPA', location:'Bengaluru', description:'Backend role in Ads infra.', status:'open', statusLabel:'Open', statusCls:'success', views:120, createdAt:'2025-12-12T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
    { id:'aj-2', title:'Product Manager', company:'Google', type:'Full-time', package:'₹32 LPA', location:'Hyderabad', description:'PM role for consumer products.', status:'reviewing', statusLabel:'Reviewing', statusCls:'info', views:86, createdAt:'2025-11-28T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
    { id:'aj-3', title:'Data Engineer', company:'Google', type:'Full-time', package:'₹30 LPA', location:'Bengaluru', description:'Data platform engineering.', status:'closed', statusLabel:'Closed', statusCls:'muted', views:42, createdAt:'2025-11-10T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
  ]));
}

const AlumniJobPosts = {
  all() { seedAlumniJobPosts(); try { return JSON.parse(localStorage.getItem(ALUMNI_JOBS_KEY) || '[]'); } catch { return []; } },
  save(list) { localStorage.setItem(ALUMNI_JOBS_KEY, JSON.stringify(list)); },
  mine() {
    const email = Auth.user()?.email || '';
    return this.all().filter(j => j.alumniEmail === email);
  },
  add(payload) {
    const u = Auth.user();
    const status = String(payload.status || 'open').toLowerCase();
    const st = mapApiJobPostStatus(status);
    const row = {
      id: 'aj-' + Date.now(),
      title: payload.title?.trim(),
      company: payload.company?.trim(),
      type: payload.type || 'Full-time',
      package: payload.package?.trim() || '',
      location: payload.location?.trim() || '',
      description: payload.description?.trim() || '',
      status,
      statusLabel: st.statusLabel,
      statusCls: st.statusCls,
      views: 0,
      createdAt: new Date().toISOString(),
      alumniEmail: u?.email || '',
    };
    const list = this.all();
    list.unshift(row);
    this.save(list);
    return row;
  },
  stats() {
    const mine = this.mine();
    return {
      activePosts: mine.filter(j => j.status === 'open' || j.status === 'reviewing').length,
      viewsThisMonth: mine.reduce((n, j) => n + (j.views || 0), 0),
      referralsCount: AlumniReferrals.mine().length,
    };
  },
};

function mapApiReferralStatus(status) {
  const map = {
    submitted: ['Submitted', 'success'],
    in_review: ['In review', 'info'],
    accepted: ['Accepted', 'success'],
  };
  const [label, cls] = map[(status || '').toLowerCase()] || ['Submitted', 'success'];
  return { status: label, statusCls: cls };
}

function mapApiJobPostStatus(status) {
  const map = {
    open: ['Open', 'success'],
    reviewing: ['Reviewing', 'info'],
    closed: ['Closed', 'muted'],
  };
  const [label, cls] = map[(status || '').toLowerCase()] || ['Open', 'success'];
  return { statusLabel: label, statusCls: cls };
}


const ALUMNI_REF_KEY = 'ph-alumni-referrals';

function seedAlumniReferrals() {
  if (localStorage.getItem(ALUMNI_REF_KEY)) return;
  localStorage.setItem(ALUMNI_REF_KEY, JSON.stringify([
    { id:'ar-1', companyName:'Google', companyWebsite:'https://careers.google.com', hrName:'Priya Menon', hrEmail:'priya.menon@google.com', contactNumber:'+91 98765 43210', status:'pending', submittedAt:'2025-12-14T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu', alumniName:'Rohan Verma' },
    { id:'ar-2', companyName:'Razorpay', companyWebsite:'https://razorpay.com/careers', hrName:'Arjun Nair', hrEmail:'arjun@razorpay.com', contactNumber:'+91 91234 56789', status:'contacted', submittedAt:'2025-11-30T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu', alumniName:'Rohan Verma' },
    { id:'ar-3', companyName:'Flipkart', companyWebsite:'', hrName:'Meera K', hrEmail:'meera@flipkart.com', contactNumber:'+91 99887 76655', status:'registered', submittedAt:'2025-11-18T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu', alumniName:'Rohan Verma' },
  ]));
}

const AlumniReferrals = {
  _cache: null,
  all() { seedAlumniReferrals(); if (this._cache) return this._cache; try { return JSON.parse(localStorage.getItem(ALUMNI_REF_KEY) || '[]'); } catch { return []; } },
  save(list) { this._cache = list; localStorage.setItem(ALUMNI_REF_KEY, JSON.stringify(list)); },
  mine() {
    const email = (Auth.user()?.email || '').toLowerCase();
    if (!email) return this.all();
    return this.all().filter(r => (r.alumniEmail || '').toLowerCase() === email);
  },
  async fetch() {
    const role = Auth.role();
    if ((role === 'admin' || role === 'placement_officer') && Auth.hasRealAuth() && typeof AdminApi !== 'undefined') {
      const list = await AdminApi.fetchAlumniReferrals();
      if (list) {
        this._cache = list;
        localStorage.setItem(ALUMNI_REF_KEY, JSON.stringify(list));
        return list;
      }
    }
    if (role === 'alumni') {
      const res = await api('/alumni/referrals');
      if (res.success && Array.isArray(res.data)) {
        this._cache = res.data.map(r => {
          const raw = String(r.status || 'pending').toLowerCase();
          const status = raw === 'submitted' ? 'pending' : raw === 'in_review' ? 'contacted' : raw === 'accepted' ? 'registered' : raw;
          return {
            id: r.id || r._id,
            companyName: r.companyName || r.jobTitle || '',
            companyWebsite: r.companyWebsite || r.link || '',
            hrName: r.hrName || r.contact?.name || '',
            hrEmail: r.hrEmail || r.contact?.email || '',
            contactNumber: r.contactNumber || r.contact?.phone || '',
            status,
            submittedAt: r.submittedAt || r.createdAt || '',
            alumniEmail: Auth.user()?.email || r.alumniEmail || '',
            alumniName: Auth.user()?.name || r.alumniName || '',
          };
        });
        localStorage.setItem(ALUMNI_REF_KEY, JSON.stringify(this._cache));
        return this._cache;
      }
    }
    return this.all();
  },
  async add(payload) {
    const body = {
      companyName: payload.companyName,
      companyWebsite: payload.companyWebsite || '',
      hrName: payload.hrName,
      hrEmail: payload.hrEmail,
      contactNumber: payload.contactNumber,
      contact: {
        name: payload.hrName,
        email: payload.hrEmail,
        phone: payload.contactNumber,
      },
    };
    const res = await api('/alumni/jobs/refer', { method: 'POST', body });
    if (res.success) { await this.fetch(); return res.data; }
    const u = Auth.user();
    const row = {
      id: 'ar-' + Date.now(),
      companyName: payload.companyName?.trim(),
      companyWebsite: payload.companyWebsite?.trim() || '',
      hrName: payload.hrName?.trim(),
      hrEmail: payload.hrEmail?.trim(),
      contactNumber: payload.contactNumber?.trim(),
      status: 'pending',
      submittedAt: new Date().toISOString(),
      alumniEmail: u?.email || '',
      alumniName: u?.name || 'Alumni',
    };
    this.save([row, ...this.all()]);
    return row;
  },
  async updateStatus(id, status) {
    const res = await api(`/admin/alumni-referrals/${encodeURIComponent(id)}/status`, { method: 'PUT', body: { status } });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().map(r => r.id === id ? { ...r, status } : r));
    return false;
  },
};

const ALUMNI_STORIES_KEY = 'ph-alumni-success-stories';

function seedAlumniSuccessStories() {
  if (localStorage.getItem(ALUMNI_STORIES_KEY)) return;
  localStorage.setItem(ALUMNI_STORIES_KEY, JSON.stringify([
    { id:'as-1', name:'Rohan Verma', company:'Google', role:'SWE II', package:'₹38 LPA', quote:'PlaceHub connected me with mentors and mock interviews that made the Google process feel achievable.', status:'published', createdAt:'2025-12-01T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
  ]));
}

const AlumniSuccessStories = {
  _cache: null,
  normalizeItem(s) {
    return {
      id: s.id || s._id,
      name: s.name || '',
      company: s.company || '',
      role: s.role || '',
      package: s.package || '',
      quote: s.quote || '',
      status: s.status || 'published',
      createdAt: s.createdAt || '',
      alumniEmail: s.alumniEmail || '',
    };
  },
  all() {
    seedAlumniSuccessStories();
    if (this._cache) return this._cache;
    try { return JSON.parse(localStorage.getItem(ALUMNI_STORIES_KEY) || '[]'); } catch { return []; }
  },
  save(list) { this._cache = list; localStorage.setItem(ALUMNI_STORIES_KEY, JSON.stringify(list)); },
  mine() {
    const email = (Auth.user()?.email || '').toLowerCase();
    if (!email) return this.all().map(s => this.normalizeItem(s));
    return this.all().filter(s => (s.alumniEmail || '').toLowerCase() === email).map(s => this.normalizeItem(s));
  },
  async fetch() {
    if (Auth.role() !== 'alumni') return this.all();
    const res = await api('/alumni/success-stories');
    if (res.success && Array.isArray(res.data)) {
      const email = Auth.user()?.email || '';
      this._cache = res.data.map(s => this.normalizeItem({ ...s, alumniEmail: email }));
      localStorage.setItem(ALUMNI_STORIES_KEY, JSON.stringify(this._cache));
      return this._cache;
    }
    return this.all();
  },
  async add(payload) {
    const res = await api('/alumni/success-stories', { method: 'POST', body: payload });
    if (res.success) { await this.fetch(); return true; }
    const u = Auth.user();
    const row = {
      id: 'as-' + Date.now(),
      ...payload,
      status: 'published',
      createdAt: new Date().toISOString(),
      alumniEmail: u?.email || '',
    };
    this.save([row, ...this.all()]);
    return true;
  },
  async update(id, payload) {
    const res = await api(`/alumni/success-stories/${encodeURIComponent(id)}`, { method: 'PUT', body: payload });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().map(s => s.id === id ? { ...s, ...payload } : s));
    return true;
  },
  async remove(id) {
    const res = await api(`/alumni/success-stories/${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().filter(s => s.id !== id));
    return true;
  },
};

const STAFF_REGISTRY_KEY = 'ph-staff-registry';

function seedStaffRegistry() {
  if (localStorage.getItem(STAFF_REGISTRY_KEY)) return;
  const seed = [
    { id:'st-1', name:'Prof. Ravi Iyer', email:'ravi.iyer@college.edu', department:'CSE', designation:'Associate Professor', phone:'+91 98765 11101', addedAt:'2025-08-01T10:00:00.000Z' },
    { id:'st-2', name:'Dr. Sunita Rao', email:'sunita.rao@college.edu', department:'ECE', designation:'Professor', phone:'+91 98765 11102', addedAt:'2025-08-01T10:00:00.000Z' },
    { id:'st-3', name:'Prof. Meena Krishnan', email:'meena.k@college.edu', department:'MCA', designation:'Assistant Professor', phone:'+91 98765 11103', addedAt:'2025-09-12T10:00:00.000Z' },
  ];
  localStorage.setItem(STAFF_REGISTRY_KEY, JSON.stringify(seed));
}

const StaffRegistry = {
  all() {
    seedStaffRegistry();
    try { return JSON.parse(localStorage.getItem(STAFF_REGISTRY_KEY) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(STAFF_REGISTRY_KEY, JSON.stringify(list)); },
  add(payload) {
    const staff = {
      id: 'st-' + Date.now(),
      name: payload.name?.trim(),
      email: payload.email?.trim(),
      department: payload.department?.trim(),
      designation: payload.designation?.trim() || 'Faculty',
      phone: payload.phone?.trim() || '',
      addedAt: new Date().toISOString(),
    };
    const list = this.all();
    list.unshift(staff);
    this.save(list);
    return staff;
  },
  remove(id) { this.save(this.all().filter(s => s.id !== id)); },
};

const PLACEMENT_SETTINGS_KEY = 'ph-placement-settings';

const PlacementSettings = {
  get() {
    try { return JSON.parse(localStorage.getItem(PLACEMENT_SETTINGS_KEY) || '{"resumeVerificationEnabled":true}'); }
    catch { return { resumeVerificationEnabled: true }; }
  },
  set(partial) {
    const next = { ...this.get(), ...partial };
    localStorage.setItem(PLACEMENT_SETTINGS_KEY, JSON.stringify(next));
    return next;
  },
  isResumeVerificationOn() { return this.get().resumeVerificationEnabled !== false; },
};

const PLACEMENT_STUDENTS = [
  { id:'ps-1', name:'Karthik Subramanian', roll:'22MCA047', dept:'MCA', cgpa:8.7, company:'Google', role:'SDE-1', status:'selected', resumePath:'s3://placehub-resumes/22MCA047/sde-full-stack/res-demo-1-Karthik_SDE.pdf' },
  { id:'ps-2', name:'Ananya Reddy', roll:'21CSE018', dept:'CSE', cgpa:8.9, company:'Amazon', role:'SDE Intern', status:'shortlisted', resumePath:'s3://placehub-resumes/21CSE018/sde-full-stack/Ananya_SDE.pdf' },
  { id:'ps-3', name:'Rahul Verma', roll:'21IT012', dept:'IT', cgpa:9.1, company:'Microsoft', role:'SWE', status:'placed', resumePath:'s3://placehub-resumes/21IT012/sde-full-stack/Rahul_Resume.pdf' },
  { id:'ps-4', name:'Sneha Iyer', roll:'21ECE044', dept:'ECE', cgpa:8.4, company:'Deloitte', role:'Analyst', status:'applied', resumePath:'s3://placehub-resumes/21ECE044/general/Sneha_CV.pdf' },
  { id:'ps-5', name:'Priya Nair', roll:'21CSE077', dept:'CSE', cgpa:8.2, company:'Flipkart', role:'SDE', status:'shortlisted', resumePath:'s3://placehub-resumes/21CSE077/sde-full-stack/Priya_Nair.pdf' },
  { id:'ps-6', name:'Kabir Singh', roll:'21IT025', dept:'IT', cgpa:8.55, company:'Adobe', role:'Product Intern', status:'placed', resumePath:'s3://placehub-resumes/21IT025/product-business/Kabir.pdf' },
  { id:'ps-7', name:'Vikram Joshi', roll:'21CSE092', dept:'CSE', cgpa:9.32, company:'Goldman Sachs', role:'Quant Intern', status:'selected', resumePath:'s3://placehub-resumes/21CSE092/data-ml/Vikram_ML.pdf' },
  { id:'ps-8', name:'Meera Iyer', roll:'22MCA031', dept:'MCA', cgpa:8.6, company:'TCS', role:'System Engineer', status:'applied', resumePath:'s3://placehub-resumes/22MCA031/general/Meera.pdf' },
  { id:'ps-9', name:'Aarav Mehta', roll:'21CSE001', dept:'CSE', cgpa:8.92, company:'Infosys', role:'SE', status:'placed', resumePath:'s3://placehub-resumes/21CSE001/general/Aarav.pdf' },
  { id:'ps-10', name:'Ananya Rao', roll:'21ECE022', dept:'ECE', cgpa:7.95, company:'Wipro', role:'Project Engineer', status:'applied', resumePath:'s3://placehub-resumes/21ECE022/core-engineering/Ananya_Rao.pdf' },
];

function pipelineStatusBadge(status) {
  const map = {
    applied: ['muted','Applied'],
    shortlisted: ['info','Shortlisted'],
    selected: ['warning','Selected'],
    placed: ['success','Placed'],
  };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

function formatDate(iso) {
  try { return new Date(iso).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' }); } catch { return '—'; }
}

function formatRelativeTime(iso) {
  if (!iso) return '—';
  try {
    const ms = Date.now() - new Date(iso).getTime();
    if (Number.isNaN(ms)) return '—';
    if (ms < 60000) return 'just now';
    const mins = Math.floor(ms / 60000);
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.floor(hrs / 24);
    if (days < 30) return `${days}d ago`;
    return formatDate(iso);
  } catch { return '—'; }
}

function destroyChart(canvas) {
  if (!canvas || typeof Chart === 'undefined') return;
  const existing = Chart.getChart(canvas);
  if (existing) existing.destroy();
}

function showPageAlert(id, type, message) {
  const el = document.getElementById(id);
  if (!el) return;
  if (type === 'hide') {
    el.classList.add('d-none');
    el.textContent = '';
    return;
  }
  el.classList.remove('d-none');
  if (type === 'info') {
    el.className = 'alert alert-info d-flex align-items-center gap-2';
    el.innerHTML = `<span class="spinner-border spinner-border-sm"></span><span>${message}</span>`;
  } else if (type === 'danger') {
    el.className = 'alert alert-danger';
    el.textContent = message;
  }
}

function stripUrlProtocol(url) {
  return String(url || '').replace(/^https?:\/\//i, '');
}

function recStatusBadge(status) {
  const map = { pending: ['warning','Pending'], contacted: ['info','Contacted'], registered: ['success','Registered'] };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

function studentKey(suffix) {
  const u = Auth.user();
  const id = u?.id || u?._id || u?.email || 'anonymous';
  return `ph-student-${suffix}-${id}`;
}

function resumeUploadFileName(file, user) {
  const safeName = (user?.name || 'Student').replace(/[^a-zA-Z0-9]/g, '') || 'Student';
  const reg = user?.registerNumber || 'student';
  const ext = (String(file?.name || '').match(/\.[^.]+$/) || ['.pdf'])[0];
  return `${safeName}_${reg}_Resume${ext}`;
}

function normalizeProfileType(value) {
  return String(value || 'General').trim().toLowerCase();
}

function profileTypesMatch(a, b) {
  const x = normalizeProfileType(a);
  const y = normalizeProfileType(b);
  if (!x || !y || x === 'general' || y === 'general') return true;
  return x === y;
}

const ResumeBucket = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('resumes')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('resumes'), JSON.stringify(list)); },
  seed() {
    if ((Auth.hasSession() && !Auth.isDemo()) || this.all().length) return;
    const u = Auth.user() || demoUserFor('student');
    const reg = u.registerNumber || 'student';
    const now = new Date().toISOString();
    const demo = [
      { id:'res-demo-1', label:'SDE Resume', profileType:'SDE / Full Stack', fileName:'Karthik_SDE.pdf', fileSize:245760, bucketPath:`s3://${RESUME_BUCKET}/${reg}/sde-/-full-stack/res-demo-1-Karthik_SDE.pdf`, uploadedAt: now },
      { id:'res-demo-2', label:'General Resume', profileType:'General', fileName:'Karthik_General.pdf', fileSize:198400, bucketPath:`s3://${RESUME_BUCKET}/${reg}/general/res-demo-2-Karthik_General.pdf`, uploadedAt: now },
      { id:'res-demo-3', label:'Data Science Resume', profileType:'Data / ML', fileName:'Karthik_ML.pdf', fileSize:312000, bucketPath:`s3://${RESUME_BUCKET}/${reg}/data-/-ml/res-demo-3-Karthik_ML.pdf`, uploadedAt: now },
    ];
    this.save(demo);
  },
  profileToEntry(profile) {
    const resume = profile?.resume;
    if (!resume || (!resume.filename && !resume.path)) return null;
    const reg = profile.registerNumber || Auth.user()?.registerNumber || 'student';
    return {
      id: `res-profile-${reg}`,
      label: 'Uploaded resume',
      profileType: 'General',
      fileName: resume.filename || String(resume.path).split(/[/\\]/).pop(),
      fileSize: resume.size || 0,
      bucketPath: resume.path
        ? (String(resume.path).startsWith('s3://') ? resume.path : `uploads://${resume.path}`)
        : '',
      uploadedAt: resume.uploadedAt || new Date().toISOString(),
      verified: !!resume.verified,
      fromProfile: true,
    };
  },
  mergeProfileResume(list, profile) {
    const entry = this.profileToEntry(profile);
    if (!entry) return list;
    const rest = list.filter(r => r.id !== entry.id);
    const existing = list.find(r => r.id === entry.id);
    return [{ ...existing, ...entry }, ...rest];
  },
  async fetch() {
    if (Auth.role() !== 'student' || Auth.isDemo()) return this.all();
    const res = await apiFetch('/student/profile', { skipAuthRedirect: true });
    if (!res.success || !res.data) return this.all();
    const profile = res.data;
    if (profile.registerNumber) {
      const u = Auth.user();
      if (u && u.registerNumber !== profile.registerNumber) {
        Auth.set({ ...u, registerNumber: profile.registerNumber }, Auth.token());
      }
    }
    const merged = this.mergeProfileResume(this.all(), profile);
    this.save(merged);
    return merged;
  },
  async upload(file, profileType, label) {
    const u = Auth.user() || {};
    const type = profileType || 'General';
    if (Auth.role() === 'student' && Auth.hasSession() && !Auth.isDemo() && file instanceof File) {
      const uploadName = resumeUploadFileName(file, u);
      const fd = new FormData();
      fd.append('resume', file, uploadName);
      const res = await apiFetch('/student/resume', { method: 'POST', body: fd, skipAuthRedirect: true });
      if (res.success) {
        await this.fetch();
        const fromProfile = this.all().find(r => r.fromProfile);
        if (fromProfile) {
          const bucketPath = `s3://${RESUME_BUCKET}/${u.registerNumber || u.email || 'student'}/${normalizeProfileType(type).replace(/\s+/g, '-')}/${fromProfile.fileName}`;
          const tagged = {
            ...fromProfile,
            label: label || type,
            profileType: type,
            bucketPath,
          };
          const list = this.all().map(r => (r.id === tagged.id ? tagged : r));
          if (!list.some(r => r.id === tagged.id)) list.unshift(tagged);
          this.save(list);
          return tagged;
        }
      } else if (res.message) {
        toast(res.message, 'warn');
      }
    }
    const id = 'res-' + Date.now();
    const safeName = (file.name || 'resume.pdf').replace(/[^\w.\-]/g, '_');
    const bucketPath = `s3://${RESUME_BUCKET}/${u.registerNumber || u.email || 'student'}/${normalizeProfileType(type).replace(/\s+/g, '-')}/${id}-${safeName}`;
    const entry = {
      id,
      label: label || type,
      profileType: type,
      fileName: file.name,
      fileSize: file.size,
      bucketPath,
      uploadedAt: new Date().toISOString(),
    };
    const list = this.all();
    list.unshift(entry);
    this.save(list);
    return entry;
  },
  remove(id) { this.save(this.all().filter(r => r.id !== id)); },
  forProfile(profileType) {
    const all = this.all();
    if (!all.length) return [];
    const wanted = profileType || 'General';
    const matched = all.filter(r => profileTypesMatch(r.profileType, wanted));
    const list = matched.length ? matched : all;
    return [...list].sort((a, b) => {
      const score = (r) => {
        if (r.profileType === wanted) return 0;
        if (normalizeProfileType(r.profileType) === normalizeProfileType(wanted)) return 1;
        if (r.profileType === 'General' || r.fromProfile) return 2;
        return 3;
      };
      return score(a) - score(b);
    });
  },
};

const StudentApps = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('applications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('applications'), JSON.stringify(list)); },
  hasApplied(driveId) { return this.all().some(a => a.driveId === driveId); },
  get(driveId) { return this.all().find(a => a.driveId === driveId); },
  resumePathForApply(resume) {
    if (!resume) return '';
    const bp = String(resume.bucketPath || '');
    if (bp.startsWith('uploads://')) return bp.slice('uploads://'.length);
    if (bp && !bp.startsWith('s3://')) return bp;
    return '';
  },
  async fetch() {
    if (Auth.role() !== 'student' || Auth.isDemo()) return this.all();
    const res = await api('/student/applications');
    if (!res.success || !Array.isArray(res.data)) return this.all();
    const mapped = res.data.map(a => ({
      id: a._id || a.id,
      driveId: a.driveId || '',
      status: a.status || 'applied',
      appliedAt: a.createdAt || a.appliedAt || '',
    }));
    this.save(mapped);
    return mapped;
  },
  async apply(drive, resumeId) {
    if (this.hasApplied(drive.id)) return null;
    const resume = ResumeBucket.all().find(r => r.id === resumeId);
    const resumePath = this.resumePathForApply(resume);

    if (Auth.role() === 'student' && Auth.hasSession() && !Auth.isDemo()) {
      const res = await api('/student/apply', {
        method: 'POST',
        body: {
          driveId: drive.id,
          resumeId: resumeId || '',
          resumeLabel: resume?.label || '',
          resumeFileName: resume?.fileName || '',
          resumePath,
        },
      });
      if (!res.success) {
        toast(res.message || 'Application failed.', 'error');
        return null;
      }
      const app = {
        id: res.data?.applicationId || res.data?._id || ('app-' + Date.now()),
        driveId: drive.id,
        company: drive.company,
        role: drive.role,
        package: drive.package,
        resumeId,
        resumeLabel: resume?.label || '—',
        status: 'applied',
        appliedAt: new Date().toISOString(),
      };
      await this.fetch();
      StudentNotifs.add({
        type: 'application_update',
        title: 'Application submitted',
        body: `Your application for ${drive.company} · ${drive.role} was submitted successfully.`,
        driveId: drive.id,
      });
      return app;
    }

    const app = {
      id: 'app-' + Date.now(),
      driveId: drive.id,
      company: drive.company,
      role: drive.role,
      package: drive.package,
      resumeId,
      resumeLabel: resume?.label || '—',
      status: 'applied',
      appliedAt: new Date().toISOString(),
    };
    const list = this.all();
    list.unshift(app);
    this.save(list);
    StudentNotifs.add({
      type: 'application_update',
      title: 'Application submitted',
      body: `Your application for ${drive.company} · ${drive.role} was submitted successfully.`,
      driveId: drive.id,
    });
    return app;
  },
  updateStatus(driveId, status, message) {
    const list = this.all().map(a => a.driveId === driveId ? { ...a, status } : a);
    this.save(list);
    const app = list.find(a => a.driveId === driveId);
    if (app) {
      StudentNotifs.add({
        type: 'application_update',
        title: 'Application update',
        body: message || `${app.company} · ${app.role} — status: ${status.replace(/_/g, ' ')}`,
        driveId,
      });
    }
  },
};

const StudentNotifs = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('notifications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('notifications'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    const seed = [
      { id:'n1', type:'job_poster', title:'New drive: Google SDE-1', body:'Registration is open. Package ₹42 LPA. Deadline Dec 28.', driveId:'google-sde-1', read:false, createdAt: new Date(Date.now()-120000).toISOString() },
      { id:'n2', type:'job_poster', title:'New drive: Amazon SDE Intern', body:'Internship drive posted. Package ₹18 LPA.', driveId:'amazon-intern', read:false, createdAt: new Date(Date.now()-3600000).toISOString() },
      { id:'n3', type:'application_update', title:'Microsoft SWE — Under review', body:'Your application is being reviewed by the placement cell.', driveId:'ms-swe', read:true, createdAt: new Date(Date.now()-86400000).toISOString() },
    ];
    this.save(seed);
  },
  add(n) {
    const item = {
      id: 'n-' + Date.now(),
      read: false,
      createdAt: new Date().toISOString(),
      ...n,
    };
    const list = this.all();
    list.unshift(item);
    this.save(list);
    return item;
  },
  markRead(id) { const sid = String(id); this.save(this.all().map(n => String(n.id) === sid ? { ...n, read: true } : n)); },
  markAllRead() { this.save(this.all().map(n => ({ ...n, read: true }))); },
  unreadCount() { return this.all().filter(n => !n.read).length; },
};

function userKey(suffix) {
  const email = Auth.user()?.email || 'anonymous';
  return `ph-user-${suffix}-${email}`;
}

const AlumniNotifs = {
  all() {
    try { return JSON.parse(localStorage.getItem(userKey('notifications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(userKey('notifications'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    this.save([
      { id:'an1', type:'referral', title:'Referral received', body:'Your SDE-2 referral at Google was submitted successfully.', read:false, createdAt: new Date(Date.now()-1800000).toISOString() },
      { id:'an2', type:'job_post', title:'Job post live', body:'Your Senior SDE posting is now visible to the alumni network.', read:false, createdAt: new Date(Date.now()-7200000).toISOString() },
      { id:'an3', type:'application_update', title:'Application update', body:'Your drive application status was updated.', read:true, createdAt: new Date(Date.now()-86400000).toISOString() },
    ]);
  },
  markRead(id) { const sid = String(id); this.save(this.all().map(n => String(n.id) === sid ? { ...n, read: true } : n)); },
  markAllRead() { this.save(this.all().map(n => ({ ...n, read: true }))); },
};

const StaffNotifs = {
  all() {
    try { return JSON.parse(localStorage.getItem(userKey('staff-notifications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(userKey('staff-notifications'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    this.save([
      { id:'sn1', type:'recommendation_update', title:'Recommendation under review', body:'Your Brillio referral is being reviewed by the placement cell.', read:false, createdAt: new Date(Date.now()-120000).toISOString() },
      { id:'sn2', type:'drive_announcement', title:'New drive: Google SDE-1', body:'CSE students can register for the Google SDE-1 drive.', read:false, createdAt: new Date(Date.now()-3600000).toISOString() },
      { id:'sn3', type:'application_update', title:'Postman referral contacted', body:'The placement team has contacted Postman HR.', read:true, createdAt: new Date(Date.now()-86400000).toISOString() },
    ]);
  },
  markRead(id) { const sid = String(id); this.save(this.all().map(n => String(n.id) === sid ? { ...n, read: true } : n)); },
  markAllRead() { this.save(this.all().map(n => ({ ...n, read: true }))); },
};

const AdminNotifs = {
  all() {
    try { return JSON.parse(localStorage.getItem(userKey('admin-notifications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(userKey('admin-notifications'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    this.save([
      { id:'adm1', type:'drive_announcement', title:'New drive published', body:'Google SDE-1 is now open for registrations.', read:false, createdAt: new Date(Date.now()-120000).toISOString() },
      { id:'adm2', type:'offer', title:'Offer accepted', body:'Kabir Singh accepted Amazon SDE Intern offer.', read:false, createdAt: new Date(Date.now()-720000).toISOString() },
      { id:'adm3', type:'resume_review', title:'Resume needs review', body:'18 new resumes pending verification.', read:false, createdAt: new Date(Date.now()-3600000).toISOString() },
      { id:'adm4', type:'application_update', title:'Broadcast delivered', body:'Placement drive announcement email reached 1,240 students.', read:true, createdAt: new Date(Date.now()-86400000).toISOString() },
    ]);
  },
  markRead(id) { const sid = String(id); this.save(this.all().map(n => String(n.id) === sid ? { ...n, read: true } : n)); },
  markAllRead() { this.save(this.all().map(n => ({ ...n, read: true }))); },
};

const CompanyNotifs = {
  all() {
    try { return JSON.parse(localStorage.getItem(userKey('company-notifications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(userKey('company-notifications'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    this.save([
      { id:'cn1', type:'application_update', title:'New application received', body:'A student applied to your SDE drive.', read:false, createdAt: new Date(Date.now()-1800000).toISOString() },
      { id:'cn2', type:'application_update', title:'Resume verified', body:'Placement cell verified a candidate resume for review.', read:false, createdAt: new Date(Date.now()-7200000).toISOString() },
    ]);
  },
  markRead(id) { const sid = String(id); this.save(this.all().map(n => String(n.id) === sid ? { ...n, read: true } : n)); },
  markAllRead() { this.save(this.all().map(n => ({ ...n, read: true }))); },
};

const BroadcastStore = {
  _cache: null,
  normalize(row) {
    return {
      id: row.id || row._id,
      title: row.title || '',
      message: row.message || '',
      audience: row.audience || '',
      audienceLabel: row.audienceLabel || row.audience || '',
      recipientCount: row.recipientCount ?? 0,
      emailSentCount: row.emailSentCount ?? 0,
      sendEmail: row.sendEmail !== false,
      status: row.status || 'delivered',
      sentByName: row.sentByName || '',
      createdAt: row.createdAt || '',
    };
  },
  all() {
    if (this._cache) return this._cache;
    try { return JSON.parse(localStorage.getItem('ph-broadcast-logs') || '[]'); } catch { return []; }
  },
  save(list) {
    this._cache = list;
    localStorage.setItem('ph-broadcast-logs', JSON.stringify(list));
  },
  async fetch() {
    const res = await api('/admin/broadcasts', { skipAuthRedirect: true });
    if (res?.success && Array.isArray(res.data)) {
      this._cache = res.data.map(r => this.normalize(r));
      this.save(this._cache);
      return this._cache;
    }
    return this.all();
  },
  async send(payload) {
    if (!(await requireWriteSession())) return null;
    const res = await api('/admin/broadcast', { method: 'POST', body: payload });
    if (res.success) {
      await this.fetch();
      return res.data;
    }
    toast(res.message || 'Broadcast failed.', 'error');
    return null;
  },
};

const NotificationInbox = {
  apiBase(role) {
    const map = {
      student: '/student/notifications',
      alumni: '/alumni/notifications',
      staff: '/staff/notifications',
      admin: '/admin/notifications',
      placement_officer: '/admin/notifications',
      company: '/company/notifications',
    };
    return map[role] || null;
  },
  store(role) {
    const map = {
      student: StudentNotifs,
      alumni: AlumniNotifs,
      staff: StaffNotifs,
      admin: AdminNotifs,
      placement_officer: AdminNotifs,
      company: CompanyNotifs,
    };
    return map[role] || null;
  },
  async unreadCount(role) {
    const base = this.apiBase(role);
    if (Auth.hasRealAuth() && base) {
      const res = await api(base, { skipAuthRedirect: true });
      if (res?.success) return (res.data || []).filter(n => !n.read).length;
    }
    const store = this.store(role);
    store?.seed?.();
    return (store?.all() || []).filter(n => !n.read).length;
  },
  async refreshBadge() {
    const role = Auth.role();
    const count = await this.unreadCount(role);
    document.querySelectorAll('a.icon-btn[href="notifications.html"] .dot').forEach(dot => {
      dot.style.display = count > 0 ? '' : 'none';
    });
    document.querySelectorAll('#sidebar a[href="notifications.html"] .nav-badge').forEach(badge => {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.style.display = count > 0 ? '' : 'none';
    });
    return count;
  },
};

function appStatusBadge(status) {
  const map = {
    applied: ['info','Applied'],
    under_review: ['warning','Under review'],
    shortlisted: ['success','Shortlisted'],
    rejected: ['danger','Not selected'],
    offered: ['success','Offered'],
    interview: ['info','Interview'],
  };
  const [cls, label] = map[status] || ['muted', String(status || '').replace(/_/g, ' ')];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

function mapCompanyJobStatus(status) {
  const map = {
    open: ['Open', 'success'],
    reviewing: ['Reviewing', 'warning'],
    closed: ['Closed', 'muted'],
    ongoing: ['Ongoing', 'info'],
  };
  const [label, cls] = map[String(status || 'open').toLowerCase()] || ['Open', 'success'];
  return { statusLabel: label, statusCls: cls };
}

const DRIVE_CATALOG = [
  { id:'google-sde-1', company:'Google', role:'SDE-1', package:'₹42 LPA', branches:'CSE,IT', applied:412, status:'Open', statusCls:'success', deadline:'Dec 28', profile:'SDE / Full Stack' },
  { id:'amazon-intern', company:'Amazon', role:'SDE Intern', package:'₹18 LPA', branches:'CSE,ECE', applied:680, status:'Ongoing', statusCls:'info', deadline:'Jan 04', profile:'SDE / Full Stack' },
  { id:'ms-swe', company:'Microsoft', role:'SWE', package:'₹52 LPA', branches:'CSE', applied:148, status:'Open', statusCls:'success', deadline:'Jan 10', profile:'SDE / Full Stack' },
  { id:'deloitte-analyst', company:'Deloitte', role:'Analyst', package:'₹9 LPA', branches:'All', applied:1240, status:'Ongoing', statusCls:'info', deadline:'Jan 06', profile:'Product / Business' },
  { id:'tcs-se', company:'TCS', role:'System Engineer', package:'₹4.5 LPA', branches:'All', applied:2160, status:'Open', statusCls:'warning', deadline:'Jan 15', profile:'General' },
  { id:'goldman-quant', company:'Goldman Sachs', role:'Quant Intern', package:'₹28 LPA', branches:'CSE,Math', applied:94, status:'Open', statusCls:'success', deadline:'Jan 18', profile:'Data / ML' },
  { id:'adobe-intern', company:'Adobe', role:'Product Intern', package:'₹22 LPA', branches:'CSE,ECE', applied:312, status:'Ongoing', statusCls:'info', deadline:'Jan 09', profile:'Product / Business' },
  { id:'flipkart-sde', company:'Flipkart', role:'SDE', package:'₹26 LPA', branches:'CSE,IT', applied:380, status:'Open', statusCls:'success', deadline:'Jan 12', profile:'SDE / Full Stack' },
  { id:'acme-sde', company:'Acme Cloud', role:'SDE-1', package:'₹18 LPA', branches:'CSE,IT,MCA', applied:86, status:'Ongoing', statusCls:'info', deadline:'Jan 20', profile:'SDE / Full Stack' },
  { id:'acme-intern', company:'Acme Cloud', role:'Product Intern', package:'₹12 LPA', branches:'CSE,ECE', applied:54, status:'Open', statusCls:'success', deadline:'Jan 22', profile:'Product / Business' },
];

function activeRecruitingCompanies() {
  const map = new Map();
  DriveStore.allWithCatalog().filter(d => d.status !== 'Closed').forEach(d => {
    if (!map.has(d.company)) {
      map.set(d.company, { company: d.company, roles: [d.role], applicants: d.applied || 0, status: d.status, statusCls: d.statusCls, package: d.package });
    } else {
      const c = map.get(d.company);
      if (!c.roles.includes(d.role)) c.roles.push(d.role);
      c.applicants += d.applied || 0;
    }
  });
  return [...map.values()];
}

function campusRecruitmentStats() {
  const totals = placementDeptTotals();
  const companies = activeRecruitingCompanies();
  const offeredInPool = COMPANY_APPLICANT_POOL.filter(a => a.status === 'offered').length;
  const selectedInPipeline = PLACEMENT_STUDENTS.filter(s => s.status === 'selected').length;
  return {
    companiesHiring: companies.length,
    applicants: totals.applicants,
    shortlisted: totals.shortlisted,
    offers: totals.selected + selectedInPipeline + offeredInPool,
    hired: totals.placed,
    companies,
    pipeline: [
      { label:'Applicants', value: totals.applicants },
      { label:'Shortlisted', value: totals.shortlisted },
      { label:'Offers', value: totals.selected + selectedInPipeline + offeredInPool },
      { label:'Hired', value: totals.placed },
    ],
  };
}

function canViewCampusHiring() {
  const role = Auth.role();
  return role === 'admin' || role === 'placement_officer' || role === 'staff';
}

function companyHiringCounts(companyName) {
  const company = companyName || '';
  const drives = DriveStore.allWithCatalog().filter(d => (d.company || '') === company && d.status !== 'Closed');
  const driveApplicants = drives.reduce((t, d) => t + (parseInt(d.applied, 10) || 0), 0);

  const inPipeline = PLACEMENT_STUDENTS.filter(s => (s.company || '') === company);
  const inPool = COMPANY_APPLICANT_POOL.filter(a => (a.company || '') === company);

  const shortlisted = inPipeline.filter(s => s.status === 'shortlisted').length
    + inPool.filter(a => a.status === 'shortlisted').length;
  const selected = inPipeline.filter(s => s.status === 'selected').length;
  const hired = inPipeline.filter(s => s.status === 'placed').length;

  return {
    applicants: driveApplicants,
    shortlisted,
    selected,
    hired,
  };
}

function viewerDepartment() {
  const role = Auth.role();
  const u = Auth.user();
  if (role === 'staff') return u?.department || '';
  if (role === 'placement_officer') return u?.department || '';
  return '';
}

function deptHiringCompanies(deptCode) {
  const dept = deptCode || '';
  if (!dept) return [];
  const people = PLACEMENT_STUDENTS.filter(s => (s.dept || '') === dept);
  const companies = [...new Set(people.map(s => s.company).filter(Boolean))];
  const map = companies.map(company => ({
    company,
    applicants: people.filter(s => s.company === company).length,
    shortlisted: people.filter(s => s.company === company && s.status === 'shortlisted').length,
    selected: people.filter(s => s.company === company && s.status === 'selected').length,
    hired: people.filter(s => s.company === company && s.status === 'placed').length,
  }));
  return map.sort((a, b) => b.applicants - a.applicants);
}

function departmentHiringOverview(deptCode) {
  const dept = deptCode || '';
  const row = DEPARTMENT_PLACEMENT.find(d => d.dept === dept);
  const companies = dept ? deptHiringCompanies(dept) : [];
  return {
    dept,
    officer: dept ? DeptPlacementOfficers.officerForDept(dept) : null,
    applicants: row?.applicants ?? 0,
    shortlisted: row?.shortlisted ?? 0,
    offers: row?.selected ?? 0,
    hired: row?.placed ?? 0,
    companies,
    candidates: dept ? PLACEMENT_STUDENTS.filter(s => (s.dept || '') === dept) : [],
  };
}

const COMPANY_APPLICANT_POOL = [
  { name:'Karthik Subramanian', roll:'22MCA047', dept:'MCA', cgpa:8.7, company:'Acme Cloud', role:'SDE-1', status:'under_review', appliedAt:'2026-01-14T09:00:00.000Z' },
  { name:'Ananya Reddy', roll:'21CSE018', dept:'CSE', cgpa:8.9, company:'Acme Cloud', role:'SDE-1', status:'shortlisted', appliedAt:'2026-01-13T11:30:00.000Z' },
  { name:'Rahul Verma', roll:'21IT012', dept:'IT', cgpa:9.1, company:'Acme Cloud', role:'SDE-1', status:'shortlisted', appliedAt:'2026-01-12T14:00:00.000Z' },
  { name:'Sneha Iyer', roll:'21ECE044', dept:'ECE', cgpa:8.4, company:'Acme Cloud', role:'Product Intern', status:'applied', appliedAt:'2026-01-15T08:00:00.000Z' },
  { name:'Priya Nair', roll:'21CSE077', dept:'CSE', cgpa:8.2, company:'Acme Cloud', role:'Product Intern', status:'under_review', appliedAt:'2026-01-14T16:00:00.000Z' },
  { name:'Kabir Singh', roll:'21IT025', dept:'IT', cgpa:8.55, company:'Acme Cloud', role:'SDE-1', status:'offered', appliedAt:'2026-01-10T10:00:00.000Z' },
  { name:'Vikram Joshi', roll:'21CSE092', dept:'CSE', cgpa:9.32, company:'Acme Cloud', role:'SDE-1', status:'under_review', appliedAt:'2026-01-11T12:00:00.000Z' },
  { name:'Meera Iyer', roll:'22MCA031', dept:'MCA', cgpa:8.6, company:'Acme Cloud', role:'Product Intern', status:'applied', appliedAt:'2026-01-15T07:30:00.000Z' },
  { name:'Aarav Mehta', roll:'21CSE001', dept:'CSE', cgpa:8.92, company:'Acme Cloud', role:'SDE-1', status:'rejected', appliedAt:'2026-01-09T09:00:00.000Z' },
  { name:'Ananya Rao', roll:'21ECE022', dept:'ECE', cgpa:7.95, company:'Acme Cloud', role:'Product Intern', status:'under_review', appliedAt:'2026-01-13T15:00:00.000Z' },
];

function companyApplicants(companyName) {
  const co = companyName || Auth.user()?.companyName || '';
  return COMPANY_APPLICANT_POOL.filter(a => !co || a.company === co);
}

function applicantsByDepartment(companyName) {
  const counts = {};
  companyApplicants(companyName).forEach(a => { counts[a.dept] = (counts[a.dept] || 0) + 1; });
  return Object.entries(counts).map(([dept, count]) => ({ dept, count })).sort((a, b) => b.count - a.count);
}

function companyEligibilityKey() {
  const u = Auth.user();
  return `ph-company-eligibility-${(u?.companyName || u?.email || 'default').replace(/\s+/g, '-')}`;
}

const ELIGIBILITY_BRANCHES = ['CSE', 'IT', 'ECE', 'ME', 'EE', 'CE', 'MCA'];

function departmentList() {
  return DepartmentStore.all();
}

function departmentCodes() {
  const codes = departmentList().map(d => String(d.code || '').trim().toUpperCase()).filter(Boolean);
  return codes.length ? [...new Set(codes)] : ELIGIBILITY_BRANCHES;
}

function fillDepartmentIdSelect(selectEl, selectedId = '') {
  if (!selectEl) return;
  const depts = departmentList();
  selectEl.innerHTML = '<option value="">Select department…</option>' +
    depts.map(d => `<option value="${d.id}"${d.id === selectedId ? ' selected' : ''}>${d.name} (${d.code})</option>`).join('');
}

function fillDepartmentCodeSelect(selectEl, { includeAll = false, selected = '' } = {}) {
  if (!selectEl) return;
  const codes = departmentCodes();
  let html = includeAll ? '<option value="">All branches</option>' : '<option value="">Select department…</option>';
  html += codes.map(c => `<option value="${c}"${c === selected ? ' selected' : ''}>${c}</option>`).join('');
  selectEl.innerHTML = html;
}

function renderDepartmentBranchCheckboxes(container, { name = 'branches', checkedAll = true, selected = null } = {}) {
  if (!container) return;
  const codes = departmentCodes();
  const selectedSet = selected instanceof Set
    ? selected
    : (Array.isArray(selected) ? new Set(selected.map(s => String(s).trim().toUpperCase())) : null);
  container.innerHTML = codes.length
    ? codes.map(code => {
        const checked = selectedSet ? selectedSet.has(String(code).toUpperCase()) : checkedAll;
        const id = `br-${code}-${Math.random().toString(36).slice(2, 7)}`;
        return `<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="${name}" value="${code}" id="${id}"${checked ? ' checked' : ''}><label class="form-check-label small" for="${id}">${code}</label></div>`;
      }).join('')
    : '<span class="small text-muted-2">No departments configured.</span>';
}

function readCheckedBranchCodes(container) {
  if (!container) return [];
  return [...container.querySelectorAll('input[name="branches"]:checked')].map(cb => cb.value);
}

async function ensureDepartmentsLoaded() {
  await DepartmentStore.fetch();
  return departmentList();
}

const CompanyEligibility = {
  all() {
    try { return JSON.parse(localStorage.getItem(companyEligibilityKey()) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(companyEligibilityKey(), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
    const co = Auth.user()?.companyName || 'Acme Cloud';
    if (co !== 'Acme Cloud') return;
    this.save([
      { id:'el-acme-sde', driveId:'acme-sde', role:'SDE-1', minCgpa:7.5, maxBacklogs:0, min10th:70, min12th:70, branches:['CSE','IT','MCA'], notes:'Strong DSA and systems fundamentals required.', updatedAt:'2026-01-10T10:00:00.000Z' },
      { id:'el-acme-intern', driveId:'acme-intern', role:'Product Intern', minCgpa:7.0, maxBacklogs:1, min10th:65, min12th:65, branches:['CSE','ECE'], notes:'Product sense and communication skills preferred.', updatedAt:'2026-01-12T10:00:00.000Z' },
    ]);
  },
  companyDrives() {
    const co = Auth.user()?.companyName || '';
    return DRIVE_CATALOG.filter(d => d.company === co);
  },
  forDrive(driveId) { return this.all().find(r => r.driveId === driveId); },
  upsert(payload) {
    const list = this.all();
    const idx = list.findIndex(r => r.driveId === payload.driveId);
    const rule = {
      id: payload.id || 'el-' + Date.now(),
      driveId: payload.driveId,
      role: payload.role,
      minCgpa: parseFloat(payload.minCgpa) || 0,
      maxBacklogs: parseInt(payload.maxBacklogs, 10) || 0,
      min10th: parseFloat(payload.min10th) || 0,
      min12th: parseFloat(payload.min12th) || 0,
      branches: payload.branches || [],
      notes: payload.notes?.trim() || '',
      updatedAt: new Date().toISOString(),
    };
    if (idx >= 0) list[idx] = { ...list[idx], ...rule };
    else list.unshift(rule);
    this.save(list);
    return rule;
  },
  remove(driveId) { this.save(this.all().filter(r => r.driveId !== driveId)); },
};

function estimateEligibleCount(rule) {
  if (!rule) return 0;
  const pool = [
    { dept:'CSE', cgpa:8.5, backlogs:0 }, { dept:'IT', cgpa:8.8, backlogs:0 },
    { dept:'ECE', cgpa:7.8, backlogs:1 }, { dept:'MCA', cgpa:8.2, backlogs:0 },
    { dept:'ME', cgpa:7.2, backlogs:2 }, { dept:'EE', cgpa:7.6, backlogs:0 },
  ];
  const mult = rule.branches?.length ? rule.branches.length * 48 : 120;
  const base = pool.filter(s =>
    rule.branches?.includes(s.dept) &&
    s.cgpa >= rule.minCgpa &&
    s.backlogs <= rule.maxBacklogs
  ).length;
  return Math.max(base * 38, mult);
}

/* ─── Admin data stores (localStorage demo) ─── */
const DEPTS_KEY = 'ph-departments';
const USERS_KEY = 'ph-users-registry';
const RULES_KEY = 'ph-placement-rules';
const APPS_KEY = 'ph-application-pipeline';
const BLACKLIST_KEY = 'ph-blacklist';
const RESULTS_KEY = 'ph-recruitment-results';
const PUBLIC_PAGE_KEY = 'ph-public-page';
const PLACEMENT_NEWS_KEY = 'ph-placement-news';
const SYS_SETTINGS_KEY = 'ph-system-settings';
const RESUME_QUEUE_KEY = 'ph-resume-queue';
const DRIVES_STORE_KEY = 'ph-drives-store';
const DRIVE_HIDDEN_KEY = 'ph-drives-hidden';
const DRIVE_OVERRIDES_KEY = 'ph-drives-overrides';
const DEPT_OFFICER_KEY = 'ph-dept-placement-officers';

const ROLE_SCOPED_CACHE_KEYS = [
  USERS_KEY, APPS_KEY, DRIVES_STORE_KEY, DRIVE_HIDDEN_KEY, DRIVE_OVERRIDES_KEY,
  STAFF_REC_KEY, REG_COMPANIES_KEY, RESUME_QUEUE_KEY, BLACKLIST_KEY, RESULTS_KEY,
  RULES_KEY, DEPTS_KEY, PLACEMENT_NEWS_KEY, STAFF_REGISTRY_KEY, ALUMNI_JOBS_KEY, ALUMNI_REF_KEY, ALUMNI_STORIES_KEY,
];

const COMPANY_CATEGORIES = ['Software', 'Chemical', 'Food', 'Production', 'Mechanical', 'Consulting', 'Product'];
const COMPANY_TIERS = ['Tier 1', 'Tier 2', 'Tier 3'];
const DRIVE_TYPES = ['Exclusive', 'Pooled', 'Company Direct'];
const RESUME_NAME_PATTERN = /^[A-Za-z]+_\d{2}[A-Z]{2,4}\d{2,3}_[A-Za-z0-9_]+\.pdf$/i;

function seedDepartments() {
  if (localStorage.getItem(DEPTS_KEY)) return;
  localStorage.setItem(DEPTS_KEY, JSON.stringify([
    { id:'d1', name:'MCA', code:'MCA' }, { id:'d2', name:'Computer Science', code:'CSE' },
    { id:'d3', name:'Information Technology', code:'IT' }, { id:'d4', name:'Mechanical', code:'ME' },
    { id:'d5', name:'Food Technology', code:'FT' }, { id:'d6', name:'Electronics', code:'ECE' },
  ]));
}

const DepartmentStore = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedDepartments();
    try { return JSON.parse(localStorage.getItem(DEPTS_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(DEPTS_KEY, JSON.stringify(l)); },
  async fetch() {
    let list = null;
    if (Auth.role() === 'admin') {
      list = await AdminApi.fetchDepartments();
    }
    if (!list) {
      const res = await apiFetch('/public/departments', { skipAuthRedirect: true });
      if (res.success && Array.isArray(res.data)) {
        list = res.data.map(d => ({
          id: d.id || d._id,
          name: d.name || '',
          code: d.code || '',
          hasOfficer: !!d.hasOfficer,
        }));
      }
    }
    if (list) { this._cache = list; localStorage.setItem(DEPTS_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async add(p) {
    if (!(await requireWriteSession())) return null;
    const res = await api('/admin/departments', { method: 'POST', body: { name: p.name, code: p.code } });
    if (res.success) { await this.fetch(); return res.data; }
    toast(res.message || 'Could not add department.', 'error');
    return null;
  },
  async update(id, p) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/departments/${encodeURIComponent(id)}`, { method: 'PUT', body: p });
    if (res.success) { await this.fetch(); return true; }
    toast(res.message || 'Could not update department.', 'error');
    return false;
  },
  async remove(id) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/departments/${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    toast(res.message || 'Could not delete department.', 'error');
    return false;
  },
};

function seedDeptOfficers() {
  if (localStorage.getItem(DEPT_OFFICER_KEY)) return;
  // Map dept code -> placement officer email (demo defaults)
  localStorage.setItem(DEPT_OFFICER_KEY, JSON.stringify({
    MCA: 'po.mca@college.edu',
    CSE: 'po.cse@college.edu',
    IT:  'po.it@college.edu',
    ECE: 'po.ece@college.edu',
    ME:  'po.me@college.edu',
    EE:  'po.ee@college.edu',
    CE:  'po.ce@college.edu',
  }));
}

const DeptPlacementOfficers = {
  all() { seedDeptOfficers(); try { return JSON.parse(localStorage.getItem(DEPT_OFFICER_KEY) || '{}'); } catch { return {}; } },
  getEmail(deptCode) { return this.all()[deptCode] || ''; },
  setEmail(deptCode, email) {
    const map = { ...this.all(), [deptCode]: String(email || '').trim() };
    localStorage.setItem(DEPT_OFFICER_KEY, JSON.stringify(map));
    return map;
  },
  officerForDept(deptCode) {
    const email = this.getEmail(deptCode);
    const user = UserRegistry.byRole('placement_officer').find(u => (u.email || '') === email);
    return user || (email ? { name: email.split('@')[0].replace(/[._-]+/g,' '), email } : null);
  },
};

function seedUsers() {
  if (localStorage.getItem(USERS_KEY)) return;
  localStorage.setItem(USERS_KEY, JSON.stringify([
    { id:'u-s1', role:'student', name:'Karthik Subramanian', email:'karthik.s@college.edu', registerNumber:'22MCA047', department:'MCA', classBatch:'MCA2025-2027', cgpa:8.7, ugMarks:78, mcaMarks:82, certifications:'AWS Cloud', status:'approved', blocked:false, blacklisted:false, placementStatus:'applied', chancesUsed:2, chancesMax:5, resumeStatus:'pending' },
    { id:'u-s2', role:'student', name:'Ananya Reddy', email:'ananya@college.edu', registerNumber:'21CSE018', department:'CSE', classBatch:'CSE2024-2028', cgpa:8.9, ugMarks:85, mcaMarks:null, certifications:'', status:'pending', blocked:false, blacklisted:false, placementStatus:'registered', chancesUsed:0, chancesMax:5, resumeStatus:'pending' },
    { id:'u-s3', role:'student', name:'Rahul Verma', email:'rahul@college.edu', registerNumber:'21IT012', department:'IT', classBatch:'INMCA2022-2027', cgpa:9.1, ugMarks:88, mcaMarks:null, certifications:'GCP', status:'approved', blocked:false, blacklisted:false, placementStatus:'placed', chancesUsed:3, chancesMax:5, resumeStatus:'approved' },
    { id:'u-st1', role:'staff', name:'Prof. Ravi Iyer', email:'ravi.iyer@college.edu', department:'CSE', designation:'Associate Professor', status:'approved', blocked:false, permissions:['recommend_company'] },
    { id:'u-po1', role:'placement_officer', name:'PO · MCA', email:'po.mca@college.edu', department:'MCA', status:'approved', blocked:false },
    { id:'u-po2', role:'placement_officer', name:'PO · CSE', email:'po.cse@college.edu', department:'CSE', status:'approved', blocked:false },
    { id:'u-po3', role:'placement_officer', name:'PO · IT',  email:'po.it@college.edu',  department:'IT',  status:'approved', blocked:false },
    { id:'u-c1', role:'company', name:'Neha Sharma', email:'neha@acme.io', companyName:'Acme Cloud', category:'Software', tier:'Tier 1', location:'Bengaluru', website:'https://acme.io', contactPerson:'Neha Sharma', phone:'+91 98765 00001', status:'approved', blocked:false, associationStatus:'Active', comments:'Tier 1 product company' },
    { id:'u-c2', role:'company', name:'Raj Patel', email:'raj@foodco.com', companyName:'FoodCo Industries', category:'Food', tier:'Tier 2', location:'Chennai', website:'', contactPerson:'Raj Patel', phone:'', status:'pending', blocked:false, associationStatus:'Pending', comments:'' },
    { id:'u-a1', role:'alumni', name:'Rohan Verma', email:'rohan.v@alumni.edu', company:'Google', status:'approved', blocked:false },
    { id:'u-a2', role:'alumni', name:'Priya Nair', email:'priya.v@alumni.edu', company:'', status:'pending', blocked:false },
  ]));
}

const UserRegistry = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedUsers();
    try { return JSON.parse(localStorage.getItem(USERS_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(USERS_KEY, JSON.stringify(l)); },
  byRole(r) { return this.all().filter(u => u.role === r); },
  get(id) { return this.all().find(u => u.id === id); },
  update(id, patch) { this.save(this.all().map(u => u.id === id ? { ...u, ...patch } : u)); },
  remove(id) { this.save(this.all().filter(u => u.id !== id)); },
  async fetch() {
    if (Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined') {
      const students = await OfficerApi.fetchStudents();
      if (!students) return this.all();
      const kept = this.all().filter(u => u.role !== 'student');
      const list = [...students, ...kept];
      this._cache = list;
      localStorage.setItem(USERS_KEY, JSON.stringify(list));
      return list;
    }
    const [users, students, companies] = await Promise.all([
      AdminApi.fetchUsers(),
      AdminApi.fetchStudents(),
      AdminApi.fetchCompanies(),
    ]);
    if (!users && !students && !companies) return this.all();
    const list = [];
    if (students) list.push(...students);
    const studentIds = new Set(students?.map(s => s.id) || []);
    const companyByUserId = new Map();
    const companyRows = companies || [];
    companyRows.forEach(c => {
      if (c.userId) companyByUserId.set(c.userId, c);
    });
    const seenCompanyIds = new Set();
    users?.forEach(u => {
      if (u.role === 'student' && studentIds.has(u.id)) return;
      if (u.role === 'company') {
        const company = companyByUserId.get(u.id);
        const row = typeof AdminApi !== 'undefined' && AdminApi.mergeCompanyUser
          ? AdminApi.mergeCompanyUser(u, company)
          : { ...u, role: 'company', hasLogin: true };
        list.push(row);
        if (company?.companyId) seenCompanyIds.add(company.companyId);
        return;
      }
      list.push(u);
    });
    companyRows.forEach(c => {
      const cid = c.companyId || c.id;
      if (!seenCompanyIds.has(cid)) list.push(c);
    });
    this._cache = list;
    localStorage.setItem(USERS_KEY, JSON.stringify(list));
    return list;
  },
  async add(p) {
    if (!(await requireWriteSession())) return null;
    const body = {
      name: p.name,
      email: p.email,
      password: p.password || ({ staff: 'Staff@123456', placement_officer: 'Officer@123456', alumni: 'Alumni@123456', company: 'Company@123456' }[p.role] || 'Staff@123456'),
      role: p.role || 'staff',
      approved: p.approved !== false,
    };
    if (p.departmentId) body.departmentId = p.departmentId;
    if (p.designation) body.designation = p.designation;
    if (p.company) body.company = p.company;
    if (p.alumniRole || p.jobRole) body.alumniRole = p.alumniRole || p.jobRole;
    if (p.experience != null) body.experience = p.experience;
    if (p.companyName) body.companyName = p.companyName;
    if (p.category) body.category = p.category;
    if (p.tier) body.tier = p.tier;
    if (p.phone || p.contactNumber) body.phone = p.phone || p.contactNumber;
    if (p.website || p.companyWebsite) body.website = p.website || p.companyWebsite;

    const res = await api('/admin/users', { method: 'POST', body });
    if (res.success) { await this.fetch(); return res.data; }
    toast(res.message || 'Could not create user.', 'error');
    return null;
  },
  async approve(id) {
    if (Auth.role() === 'placement_officer') {
      const res = await api(`/officer/users/${encodeURIComponent(id)}/approve`, { method: 'POST' });
      if (res.success) { await this.fetch(); return true; }
    }
    const res = await api(`/admin/users/${encodeURIComponent(id)}/approve`, { method: 'POST' });
    if (res.success) { await this.fetch(); return true; }
    this.update(id, { status:'approved' });
    return false;
  },
  async block(id, blocked = true) {
    const path = blocked ? 'block' : 'unblock';
    const res = await api(`/admin/users/${encodeURIComponent(id)}/${path}`, { method: 'POST' });
    if (res.success) { await this.fetch(); return true; }
    this.update(id, { blocked });
    return false;
  },
  async removeUser(id) {
    const row = this.get(id) || this.all().find(u =>
      u.id === id || u.userId === id || u.companyId === id
    );
    if (row?.role === 'company' && row.companyId && !row.hasLogin) {
      const res = await api(`/admin/companies/${encodeURIComponent(row.companyId)}`, { method: 'DELETE' });
      if (res.success) { await this.fetch(); return true; }
      return false;
    }
    const userId = row?.userId || row?.id || id;
    const res = await api(`/admin/users/${encodeURIComponent(userId)}`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    this.remove(id);
    return false;
  },
};

function seedRules() {
  if (localStorage.getItem(RULES_KEY)) return;
  localStorage.setItem(RULES_KEY, JSON.stringify({
    minCgpa:7.5, maxBacklog:0, maxPlacementChances:5, blockPlacedStudents:true,
    allowPlacedForSelectedDrives:false, placementPolicy:'Students with active backlogs are ineligible for Tier 1 drives.',
    policyVersion:'v3.2', updatedAt:new Date().toISOString(),
  }));
}

const PlacementRules = {
  _cache: null,
  get() {
    if (this._cache) return this._cache;
    seedRules();
    try { return JSON.parse(localStorage.getItem(RULES_KEY) || '{}'); } catch { return {}; }
  },
  set(p) {
    const n = { ...this.get(), ...p, updatedAt:new Date().toISOString() };
    this._cache = n;
    localStorage.setItem(RULES_KEY, JSON.stringify(n));
    return n;
  },
  async fetch() {
    const rule = await AdminApi.fetchActiveRule();
    if (rule) { this._cache = rule; localStorage.setItem(RULES_KEY, JSON.stringify(rule)); return rule; }
    return this.get();
  },
  async save(p) {
    if (!(await requireWriteSession())) return { ok: false, data: this.get() };
    const res = await api('/admin/rules/active', { method: 'PUT', body: p });
    if (res.success && res.data) {
      const mapped = AdminApi.mapRule(res.data);
      this._cache = mapped;
      localStorage.setItem(RULES_KEY, JSON.stringify(mapped));
      return { ok: true, data: mapped };
    }
    toast(res.message || 'Could not save rules.', 'error');
    return { ok: false, data: this.get() };
  },
};

function seedApplications() {
  if (localStorage.getItem(APPS_KEY)) return;
  localStorage.setItem(APPS_KEY, JSON.stringify([
    { id:'app-1', studentName:'Karthik Subramanian', registerNumber:'22MCA047', department:'MCA', company:'Google', role:'SDE-1', stage:'resume_verification', status:'pending', appliedAt:'2026-01-14T09:00:00.000Z' },
    { id:'app-2', studentName:'Ananya Reddy', registerNumber:'21CSE018', department:'CSE', company:'Amazon', role:'SDE Intern', stage:'approval', status:'pending', appliedAt:'2026-01-13T11:00:00.000Z' },
    { id:'app-3', studentName:'Rahul Verma', registerNumber:'21IT012', department:'IT', company:'Microsoft', role:'SWE', stage:'company_selection', status:'shortlisted', appliedAt:'2026-01-10T10:00:00.000Z' },
    { id:'app-4', studentName:'Sneha Iyer', registerNumber:'21ECE044', department:'ECE', company:'Deloitte', role:'Analyst', stage:'applied', status:'applied', appliedAt:'2026-01-15T08:00:00.000Z' },
  ]));
}

const ApplicationPipeline = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedApplications();
    try { return JSON.parse(localStorage.getItem(APPS_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(APPS_KEY, JSON.stringify(l)); },
  async fetch() {
    const list = Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined'
      ? await OfficerApi.fetchApplications()
      : await AdminApi.fetchApplications();
    if (list) { this._cache = list; localStorage.setItem(APPS_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async fetchByDrive(driveId) {
    const list = Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined'
      ? await OfficerApi.fetchApplications({ driveId })
      : await AdminApi.fetchApplications({ driveId });
    return list || [];
  },
  async transition(id, status, remarks = '') {
    const res = await api(`/admin/applications/${encodeURIComponent(id)}/transition`, { method: 'POST', body: { status, remarks } });
    if (res.success) { await this.fetch(); return true; }
    return false;
  },
  async approve(id) {
    if (Auth.role() === 'placement_officer') {
      const res = await api(`/officer/applications/${encodeURIComponent(id)}/approve`, { method: 'POST' });
      if (res.success) { await this.fetch(); return true; }
      return false;
    }
    if (await this.transition(id, 'officer_approved')) return true;
    this.save(this.all().map(a => a.id === id ? { ...a, stage:'company_selection', status:'approved' } : a));
    return false;
  },
  async reject(id) {
    if (Auth.role() === 'placement_officer') {
      const res = await api(`/officer/applications/${encodeURIComponent(id)}/reject`, { method: 'POST', body: {} });
      if (res.success) { await this.fetch(); return true; }
      return false;
    }
    if (await this.transition(id, 'rejected')) return true;
    this.save(this.all().map(a => a.id === id ? { ...a, stage:'rejected', status:'rejected' } : a));
    return false;
  },
};

function seedBlacklist() {
  if (localStorage.getItem(BLACKLIST_KEY)) return;
  localStorage.setItem(BLACKLIST_KEY, JSON.stringify([
    { id:'bl-1', studentName:'Vikram Das', registerNumber:'21ME055', reason:'Unauthorized absence from Google drive', addedAt:'2025-11-20T10:00:00.000Z', active:true },
  ]));
}

const BlacklistStore = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedBlacklist();
    try { return JSON.parse(localStorage.getItem(BLACKLIST_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(BLACKLIST_KEY, JSON.stringify(l)); },
  async fetch() {
    const list = await AdminApi.fetchBlacklist();
    if (list) { this._cache = list; localStorage.setItem(BLACKLIST_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async add(p) {
    if (!(await requireWriteSession())) return false;
    const res = await api('/admin/blacklist', { method: 'POST', body: { registerNumber: p.registerNumber, reason: p.reason } });
    if (res.success) { await this.fetch(); return true; }
    toast(res.message || 'Could not add to blacklist.', 'error');
    return false;
  },
  async remove(id) {
    if (!(await requireWriteSession())) return false;
    const row = this.all().find(b => b.id === id);
    const studentId = row?.studentId || id;
    const res = await api(`/admin/blacklist/${encodeURIComponent(studentId)}`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    toast(res.message || 'Could not remove from blacklist.', 'error');
    return false;
  },
};

function seedResults() {
  if (localStorage.getItem(RESULTS_KEY)) return;
  localStorage.setItem(RESULTS_KEY, JSON.stringify([
    { id:'res-1', studentName:'Rahul Verma', registerNumber:'21IT012', company:'Microsoft', role:'SWE', package:'₹52 LPA', status:'selected', joiningDate:'2026-07-15' },
    { id:'res-2', studentName:'Kabir Singh', registerNumber:'21IT025', company:'Adobe', role:'Product Intern', package:'₹22 LPA', status:'selected', joiningDate:'2026-06-01' },
    { id:'res-3', studentName:'Aarav Mehta', registerNumber:'21CSE001', company:'Infosys', role:'SE', package:'₹9 LPA', status:'rejected', joiningDate:'' },
  ]));
}

function driveResultMeta(d) {
  if (!d) return { company: '', role: '' };
  let company = String(d.company || d.companyName || '').trim();
  let role = String(d.role || '').trim();
  const title = String(d.title || '').trim();
  if (!role && title && !title.includes('—') && !title.includes(' - ')) role = title;
  if (title.includes('—') || title.includes(' - ')) {
    const sep = title.includes('—') ? '—' : ' - ';
    const parts = title.split(sep).map(s => s.trim()).filter(Boolean);
    if (parts.length >= 2) {
      const knownCompany = String(d.companyName || d.company || '').trim();
      if (knownCompany && parts[0] === knownCompany) {
        if (!company) company = parts[0];
        if (!role) role = parts[1];
      } else if (knownCompany && parts[1] === knownCompany) {
        if (!company) company = parts[1];
        if (!role) role = parts[0];
      } else {
        if (!company) company = parts[0];
        if (!role) role = parts[1];
      }
    }
  }
  return { company, role };
}

const RecruitmentResults = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedResults();
    try { return JSON.parse(localStorage.getItem(RESULTS_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(RESULTS_KEY, JSON.stringify(l)); },
  async fetch() {
    const list = Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined'
      ? await OfficerApi.fetchResults()
      : await AdminApi.fetchResults();
    if (list) { this._cache = list; localStorage.setItem(RESULTS_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async fetchByDrive(driveId, meta = {}) {
    const company = meta.company || '';
    const role = meta.role || '';
    const list = Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined'
      ? await OfficerApi.fetchResults({ driveId, company, role })
      : await AdminApi.fetchResults({ driveId, company, role });
    const fromApi = list || [];
    const matchesDrive = r => {
      if (r.driveId && r.driveId === driveId) return true;
      if (!company || !role) return false;
      return r.company === company && r.role === role;
    };
    const fromLocal = this.all().filter(matchesDrive);
    const merged = new Map();
    [...fromApi, ...fromLocal].forEach(r => { if (r?.id) merged.set(r.id, r); });
    return [...merged.values()];
  },
  async upsert(p) {
    if (!(await requireWriteSession())) return null;
    const path = Auth.role() === 'placement_officer' ? '/officer/results' : '/admin/results';
    const res = await api(path, { method: 'POST', body: p });
    if (res.success) { await this.fetch(); return res.data; }
    const list = this.all();
    const idx = list.findIndex(r => p.driveId
      ? r.driveId === p.driveId && r.registerNumber === p.registerNumber
      : r.registerNumber === p.registerNumber && r.company === p.company);
    const row = { id: p.id || 'res-'+Date.now(), ...p };
    if (idx >= 0) list[idx] = { ...list[idx], ...row }; else list.unshift(row);
    this.save(list);
    return row;
  },
  async remove(id) {
    const res = await api(`/admin/results/${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().filter(r => r.id !== id));
    return false;
  },
};

function seedResumeQueue() {
  if (localStorage.getItem(RESUME_QUEUE_KEY)) return;
  localStorage.setItem(RESUME_QUEUE_KEY, JSON.stringify([
    { id:'rq-1', studentName:'Ananya Reddy', registerNumber:'21CSE018', department:'CSE', fileName:'Ananya_21CSE018_Developer.pdf', validFormat:true, status:'pending', submittedAt:'2026-01-15T08:00:00.000Z' },
    { id:'rq-2', studentName:'Sneha Iyer', registerNumber:'21ECE044', department:'ECE', fileName:'resume.pdf', validFormat:false, status:'pending', submittedAt:'2026-01-14T12:00:00.000Z' },
    { id:'rq-3', studentName:'Karthik Subramanian', registerNumber:'22MCA047', department:'MCA', fileName:'Karthik_22MCA047_Developer.pdf', validFormat:true, status:'approved', submittedAt:'2026-01-10T09:00:00.000Z' },
  ]));
}

const ResumeQueue = {
  _cache: null,
  all() {
    if (this._cache) return this._cache;
    seedResumeQueue();
    try { return JSON.parse(localStorage.getItem(RESUME_QUEUE_KEY) || '[]'); } catch { return []; }
  },
  save(l) { this._cache = l; localStorage.setItem(RESUME_QUEUE_KEY, JSON.stringify(l)); },
  async fetch() {
    const list = Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined'
      ? await OfficerApi.fetchResumeQueue()
      : await AdminApi.fetchResumeQueue();
    if (list) { this._cache = list; localStorage.setItem(RESUME_QUEUE_KEY, JSON.stringify(list)); return list; }
    return this.all();
  },
  async approve(id) {
    const item = this.all().find(x => x.id === id);
    const studentId = item?.studentId || id;
    const path = Auth.role() === 'placement_officer'
      ? `/officer/students/${encodeURIComponent(studentId)}/verify-resume`
      : `/admin/students/${encodeURIComponent(studentId)}/verify-resume`;
    const res = await api(path, { method: 'POST' });
    if (res.success) { await this.fetch(); return true; }
    this.save(this.all().map(r => r.id === id ? { ...r, status:'approved' } : r));
    return false;
  },
  async reject(id) {
    const item = this.all().find(x => x.id === id);
    if (!item) return false;
    if (item.applicationId) {
      if (Auth.role() === 'placement_officer') {
        const res = await api(`/officer/applications/${encodeURIComponent(item.applicationId)}/reject`, { method: 'POST', body: {} });
        if (res.success) { await this.fetch(); return true; }
        return false;
      }
      const res = await api(`/admin/applications/${encodeURIComponent(item.applicationId)}/transition`, {
        method: 'POST',
        body: { status: 'rejected', remarks: 'Resume rejected during verification' },
      });
      if (res.success) { await this.fetch(); return true; }
      return false;
    }
    this.save(this.all().map(r => r.id === id ? { ...r, status:'rejected' } : r));
    return true;
  },
};

function checkResumeFileName(fileName) {
  return RESUME_NAME_PATTERN.test(fileName || '');
}

const SystemSettings = {
  _cache: null,
  defaults() {
    return { placementYear:'2025-26', emailFrom:'placement@college.edu', maxUploadMb:10, smtpEnabled:true, notifyOnApproval:true };
  },
  get() {
    if (this._cache) return { ...this.defaults(), ...this._cache };
    try {
      return JSON.parse(localStorage.getItem(SYS_SETTINGS_KEY) || JSON.stringify(this.defaults()));
    } catch { return this.defaults(); }
  },
  set(p) {
    const n = { ...this.get(), ...p };
    this._cache = n;
    localStorage.setItem(SYS_SETTINGS_KEY, JSON.stringify(n));
    return n;
  },
  async fetch() {
    const res = await api('/admin/settings/system');
    if (res.success && res.data) {
      this._cache = res.data;
      localStorage.setItem(SYS_SETTINGS_KEY, JSON.stringify(res.data));
      return res.data;
    }
    return this.get();
  },
  async save(payload) {
    if (!(await requireWriteSession())) return { ok: false, data: this.get() };
    const res = await api('/admin/settings/system', { method: 'PUT', body: payload });
    if (res.success && res.data) {
      this._cache = res.data;
      localStorage.setItem(SYS_SETTINGS_KEY, JSON.stringify(res.data));
      return { ok: true, data: res.data };
    }
    const merged = this.set(payload);
    return { ok: false, data: merged, message: res.message };
  },
};

const PublicPageContent = {
  _cache: null,
  _liveStats: null,
  defaults() {
    return {
      season:'2025-26', placed:0, highestPkg:0, avgPkg:0, medianPkg:0, lowestPkg:0,
      companies:0, placementRate:0, headline:'Where ambition meets opportunity',
      achievements:'Placement statistics are computed live from campus data.',
    };
  },
  get() {
    if (this._cache) return { ...this.defaults(), ...this._cache };
    try {
      return JSON.parse(localStorage.getItem(PUBLIC_PAGE_KEY) || JSON.stringify(this.defaults()));
    } catch { return this.defaults(); }
  },
  liveStats() {
    return this._liveStats;
  },
  set(p) {
    const n = { ...this.get(), ...p };
    this._cache = n;
    localStorage.setItem(PUBLIC_PAGE_KEY, JSON.stringify(n));
    return n;
  },
  async fetch() {
    const res = await api('/admin/settings/public');
    if (res.success && res.data) {
      this._cache = res.data;
      localStorage.setItem(PUBLIC_PAGE_KEY, JSON.stringify(res.data));
      return res.data;
    }
    return this.get();
  },
  async save(payload) {
    if (!(await requireWriteSession())) return { ok: false, data: this.get() };
    const res = await api('/admin/settings/public', { method: 'PUT', body: payload });
    if (res.success && res.data) {
      this._cache = res.data;
      localStorage.setItem(PUBLIC_PAGE_KEY, JSON.stringify(res.data));
      return { ok: true, data: res.data };
    }
    const merged = this.set(payload);
    return { ok: false, data: merged, message: res.message };
  },
};

function seedPlacementNews() {
  if (localStorage.getItem(PLACEMENT_NEWS_KEY)) return;
  localStorage.setItem(PLACEMENT_NEWS_KEY, JSON.stringify([
    { id:'news-1', title:'Record-breaking season kicks off', summary:'Over 142 companies have already confirmed campus visits for 2025–26.', date:'2025-11-12', link:'' },
    { id:'news-2', title:'Google announces 28 SDE offers', summary:'One of the largest cohorts hired from a single drive this year.', date:'2025-10-30', link:'' },
    { id:'news-3', title:'New mentorship program launched', summary:'Alumni from 60+ companies join the placement readiness program.', date:'2025-10-18', link:'' },
  ]));
}

function formatNewsDate(value) {
  if (!value) return '';
  const d = new Date(value.includes('T') ? value : value + 'T12:00:00');
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-IN', { month:'short', day:'numeric', year:'numeric' });
}

const PlacementNewsStore = {
  _cache: null,
  normalizeItem(n) {
    return {
      id: n.id || n._id,
      title: n.title,
      summary: n.summary,
      date: n.date,
      link: n.link || '',
      createdAt: n.createdAt,
    };
  },
  all() {
    if (this._cache) return this._cache.map(n => this.normalizeItem(n));
    seedPlacementNews();
    try { return JSON.parse(localStorage.getItem(PLACEMENT_NEWS_KEY) || '[]'); } catch { return []; }
  },
  save(list) {
    this._cache = list;
    localStorage.setItem(PLACEMENT_NEWS_KEY, JSON.stringify(list));
  },
  async fetch() {
    const res = await api('/admin/placement-news');
    if (res.success && Array.isArray(res.data)) {
      this._cache = res.data.map(n => this.normalizeItem(n));
      localStorage.setItem(PLACEMENT_NEWS_KEY, JSON.stringify(this._cache));
      return this._cache;
    }
    return this.all();
  },
  async fetchPublic() {
    const res = await api('/public/site-content');
    if (res.success && res.data) {
      if (res.data.system) {
        SystemSettings._cache = res.data.system;
        localStorage.setItem(SYS_SETTINGS_KEY, JSON.stringify(res.data.system));
      }
      if (res.data.publicPage) {
        PublicPageContent._cache = res.data.publicPage;
        PublicPageContent._liveStats = res.data.liveStats || null;
        localStorage.setItem(PUBLIC_PAGE_KEY, JSON.stringify(res.data.publicPage));
      }
      this._cache = (res.data.news || []).map(n => this.normalizeItem(n));
      localStorage.setItem(PLACEMENT_NEWS_KEY, JSON.stringify(this._cache));
      return res.data;
    }
    return null;
  },
  async add(payload) {
    if (!(await requireWriteSession())) return null;
    const res = await api('/admin/placement-news', { method: 'POST', body: payload });
    if (res.success) {
      await this.fetch();
      return res.data;
    }
    toast(res.message || 'Could not add news item.', 'error');
    return null;
  },
  async update(id, payload) {
    if (!(await requireWriteSession())) return false;
    const res = await api('/admin/placement-news/' + encodeURIComponent(id), { method: 'PUT', body: payload });
    if (res.success) {
      await this.fetch();
      return true;
    }
    toast(res.message || 'Could not update news item.', 'error');
    return false;
  },
  async remove(id) {
    if (!(await requireWriteSession())) return false;
    const res = await api('/admin/placement-news/' + encodeURIComponent(id), { method: 'DELETE' });
    if (res.success) {
      await this.fetch();
      return true;
    }
    toast(res.message || 'Could not delete news item.', 'error');
    return false;
  },
  published() {
    return this.all().slice().sort((a, b) => new Date(b.date || 0) - new Date(a.date || 0));
  },
};

function canManageDrives() {
  const role = Auth.role();
  return role === 'admin' || role === 'placement_officer';
}

function driveStatusCls(status) {
  const map = { Open:'success', Ongoing:'info', Completed:'primary', Closed:'muted' };
  return map[status] || 'muted';
}

const DriveStore = {
  all() {
    try { return JSON.parse(localStorage.getItem(DRIVES_STORE_KEY) || '[]'); } catch { return []; }
  },
  save(l) { localStorage.setItem(DRIVES_STORE_KEY, JSON.stringify(l)); },
  hiddenIds() {
    try { return JSON.parse(localStorage.getItem(DRIVE_HIDDEN_KEY) || '[]'); } catch { return []; }
  },
  saveHidden(ids) { localStorage.setItem(DRIVE_HIDDEN_KEY, JSON.stringify(ids)); },
  overrides() {
    try { return JSON.parse(localStorage.getItem(DRIVE_OVERRIDES_KEY) || '{}'); } catch { return {}; }
  },
  saveOverrides(map) { localStorage.setItem(DRIVE_OVERRIDES_KEY, JSON.stringify(map)); },
  isCatalog(id) { return DRIVE_CATALOG.some(d => d.id === id); },
  isCustom(id) {
    const d = this.all().find(x => x.id === id);
    return !!d && !d._fromApi;
  },
  isApiDrive(id) {
    if (this.isCatalog(id)) return false;
    const fromCache = this._apiCache?.find(d => d.id === id);
    if (fromCache?._fromApi) return true;
    const stored = this.all().find(d => d.id === id);
    if (stored?._fromApi) return true;
    return Auth.hasRealAuth() && canManageDrives() && /^[a-f0-9]{24}$/i.test(String(id));
  },
  catalogEntry(id) {
    const base = DRIVE_CATALOG.find(d => d.id === id);
    if (!base || this.hiddenIds().includes(id)) return null;
    const patch = this.overrides()[id] || {};
    const merged = { ...base, ...patch };
    if (patch.status) merged.statusCls = driveStatusCls(patch.status);
    return merged;
  },
  get(id) {
    if (this._studentCache) {
      const fromStudent = this._studentCache.find(d => d.id === id);
      if (fromStudent) return fromStudent;
    }
    if (this._apiCache) {
      const fromApi = this._apiCache.find(d => d.id === id);
      if (fromApi) return fromApi;
    }
    const stored = this.all().find(d => d.id === id);
    if (stored) return stored;
    const merged = this.allWithCatalog().find(d => d.id === id);
    if (merged) return merged;
    return this.catalogEntry(id);
  },
  _driveStatusToApi(status) {
    const map = { Open: 'scheduled', Ongoing: 'ongoing', Completed: 'completed', Closed: 'closed' };
    return map[status] || String(status || '').toLowerCase();
  },
  _normalizeBranches(branches) {
    if (!branches) return [];
    return Array.isArray(branches)
      ? branches.map(s => String(s).trim()).filter(Boolean)
      : String(branches).split(',').map(s => s.trim()).filter(Boolean);
  },
  async _resolveCompanyId(companyName, existingId) {
    if (existingId) return existingId;
    const name = String(companyName || '').trim().toLowerCase();
    if (!name || typeof RegisteredCompanies === 'undefined') return null;
    const list = RegisteredCompanies._cache || await RegisteredCompanies.fetch().catch(() => RegisteredCompanies.all());
    const match = (list || []).find(c =>
      String(c.companyName || c.company || '').trim().toLowerCase() === name
    );
    return match?.companyId || match?.id || null;
  },
  async _buildUpdateBody(p, existing) {
    const company = String(p.company ?? existing?.company ?? '').trim();
    const role = String(p.role ?? existing?.role ?? '').trim();
    const title = company && role ? `${company} — ${role}` : String(existing?.title || role || company).trim();
    const branches = this._normalizeBranches(p.branches ?? existing?.branches);
    const companyId = await this._resolveCompanyId(company, existing?.companyId || null);
    const prevElig = existing?.eligibility || {};
    const packageVal = String(p.package ?? existing?.package ?? '').trim();
    const deadlineVal = String(p.deadline ?? existing?.deadline ?? '').trim();
    const eligibility = {
      ...prevElig,
      package: packageVal === '—' ? '' : packageVal,
      deadline: !deadlineVal || deadlineVal === '—' ? '' : deadlineVal,
      description: String(p.description ?? existing?.description ?? '').trim(),
    };
    const body = {
      title,
      branches,
      eligibility,
      type: existing?.type || 'pooled',
      time: existing?.time || '10:00',
    };
    if (companyId) body.companyId = companyId;
    const recruitmentDate = String(p.date ?? p.recruitmentDate ?? existing?.date ?? '').trim();
    if (recruitmentDate && recruitmentDate !== '—' && recruitmentDate !== 'TBD') body.date = recruitmentDate;
    if (p.status) body.status = this._driveStatusToApi(p.status);
    return body;
  },
  mapStudentDrive(d) {
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const rawStatus = (d.status || '').toLowerCase();
    const status = statusMap[rawStatus] || d.status || 'Open';
    let company = d.companyName || d.company || '';
    if (!company && d.title && String(d.title).includes('—')) {
      company = String(d.title).split('—').pop().trim();
    }
    const branches = Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || '');
    return {
      id: d._id || d.id,
      company: company || '—',
      role: d.title || d.role || '—',
      package: d.package || d.eligibility?.package || '—',
      branches,
      status,
      statusCls: driveStatusCls(status),
      deadline: d.date || d.deadline || '—',
      profile: d.profile || 'General',
      applied: d.applied ? 1 : 0,
      eligible: d.eligibility?.eligible !== false,
      _fromApi: true,
    };
  },
  async fetchStudentDrives() {
    if (Auth.role() !== 'student' || Auth.isDemo()) return null;
    const res = await api('/student/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    this._studentCache = res.data.map(d => this.mapStudentDrive(d));
    return this._studentCache;
  },
  allWithCatalog() {
    if (this._apiCache) return this._apiCache;
    const hidden = new Set(this.hiddenIds());
    const overrides = this.overrides();
    const catalog = DRIVE_CATALOG
      .filter(d => !hidden.has(d.id))
      .map(d => {
        const patch = overrides[d.id] || {};
        const merged = { ...d, ...patch };
        if (patch.status) merged.statusCls = driveStatusCls(patch.status);
        return merged;
      });
    return [...this.all(), ...catalog];
  },
  async fetch() {
    if (!canManageDrives()) return this.allWithCatalog();
    if (Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined' && Auth.hasRealAuth()) {
      const list = await OfficerApi.fetchDrives();
      if (list) {
        this._apiCache = list;
        this.save(list);
        return list;
      }
    }
    if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
      const res = await api('/admin/drives');
      if (res.success && Array.isArray(res.data) && typeof OfficerApi !== 'undefined') {
        const list = res.data.map(d => OfficerApi.mapDrive(d));
        this._apiCache = list;
        this.save(list);
        return list;
      }
      if (typeof AdminApi !== 'undefined') {
        const list = await AdminApi.fetchDrives();
        if (list) {
          this._apiCache = list;
          this.save(list);
          return list;
        }
      }
    }
    return this.allWithCatalog();
  },
  async add(p) {
    if (!canManageDrives()) return null;
    if (!(await requireWriteSession())) return null;

    let companyId = p.companyId || null;
    if (!companyId && p.company && typeof RegisteredCompanies !== 'undefined') {
      const list = RegisteredCompanies._cache || await RegisteredCompanies.fetch().catch(() => RegisteredCompanies.all());
      const name = String(p.company).trim().toLowerCase();
      const match = (list || []).find(c =>
        String(c.companyName || c.company || '').trim().toLowerCase() === name
      );
      companyId = match?.companyId || match?.id || null;
    }

    const role = String(p.role || p.title || '').trim();
    const company = String(p.company || '').trim();
    const title = String(p.title || (company && role ? `${company} — ${role}` : role || company)).trim();
    const date = String(p.date || p.recruitmentDate || p.deadline || '').trim();
    const time = String(p.time || '10:00').trim();

    if (!companyId) {
      toast('Select a registered company. Add it under Admin → Companies first.', 'error');
      return null;
    }
    if (!title) {
      toast('Job role is required.', 'error');
      return null;
    }
    if (!date) {
      toast('Recruitment date is required.', 'error');
      return null;
    }

    const branches = p.branches
      ? (Array.isArray(p.branches) ? p.branches : String(p.branches).split(',').map(s => s.trim()).filter(Boolean))
      : [];

    const driveBody = {
      title,
      companyId,
      type: p.type || 'pooled',
      date,
      time,
      branches,
      tier: p.tier || 'Tier 2',
      eligibility: {
        minCgpa: parseFloat(p.minCgpa) || 0,
        maxBacklogs: parseInt(p.maxBacklogs, 10) || 0,
        package: p.package || '',
        location: p.location || '',
        deadline: p.deadline || '',
        description: p.description || '',
      },
    };

    const formatErrors = (res) => {
      if (res?.errors && typeof res.errors === 'object') {
        return Object.values(res.errors).join(' ');
      }
      return res?.message || 'Could not create drive.';
    };

    if (Auth.role() === 'placement_officer' && Auth.hasRealAuth()) {
      const res = await api('/officer/drives', { method: 'POST', body: driveBody });
      if (res.success) { await this.fetch(); return res.data; }
      toast(formatErrors(res), 'error');
      return null;
    }
    if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
      const res = await api('/admin/drives', { method: 'POST', body: driveBody });
      if (res.success) { await this.fetch(); return res.data; }
      toast(formatErrors(res), 'error');
      return null;
    }
    return null;
  },
  async update(id, p, existingHint = null) {
    if (!canManageDrives()) return null;
    if (!(await requireWriteSession())) return null;

    const formatErrors = (res) => {
      if (res?.errors && typeof res.errors === 'object') {
        return Object.values(res.errors).join(' ');
      }
      return res?.message || 'Could not update drive.';
    };

    if (this.isApiDrive(id)) {
      const existing = existingHint || this.get(id) || {};
      const body = await this._buildUpdateBody(p, existing);
      if (Auth.role() === 'placement_officer' && Auth.hasRealAuth()) {
        const res = await api(`/officer/drives/${encodeURIComponent(id)}`, { method: 'PUT', body });
        if (res.success) { await this.fetch(); return this.get(id); }
        toast(formatErrors(res), 'error');
        return null;
      }
      if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
        const res = await api(`/admin/drives/${encodeURIComponent(id)}`, { method: 'PUT', body });
        if (res.success) { await this.fetch(); return this.get(id); }
        toast(formatErrors(res), 'error');
        return null;
      }
      toast('Sign in with an admin or officer account to update drives.', 'error');
      return null;
    }

    const next = { ...p };
    if (p.status) next.statusCls = driveStatusCls(p.status);
    if (this.isCustom(id)) {
      this.save(this.all().map(d => d.id === id ? { ...d, ...next } : d));
      return this.get(id);
    }
    if (this.isCatalog(id)) {
      const map = { ...this.overrides(), [id]: { ...(this.overrides()[id] || {}), ...next } };
      this.saveOverrides(map);
      return this.get(id);
    }
    toast('Could not update drive.', 'error');
    return null;
  },
  async remove(id) {
    if (!canManageDrives()) return false;
    if (!(await requireWriteSession())) return false;

    const formatErrors = (res) => res?.message || 'Could not delete drive.';

    if (this.isApiDrive(id)) {
      if (Auth.role() === 'placement_officer' && Auth.hasRealAuth()) {
        const res = await api(`/officer/drives/${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (res.success) { await this.fetch(); return true; }
        toast(formatErrors(res), 'error');
        return false;
      }
      if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
        const res = await api(`/admin/drives/${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (res.success) { await this.fetch(); return true; }
        toast(formatErrors(res), 'error');
        return false;
      }
      return false;
    }

    if (this.isCustom(id)) {
      this.save(this.all().filter(d => d.id !== id));
      return true;
    }
    if (this.isCatalog(id)) {
      const hidden = [...new Set([...this.hiddenIds(), id])];
      this.saveHidden(hidden);
      const map = { ...this.overrides() };
      delete map[id];
      this.saveOverrides(map);
      return true;
    }
    return false;
  },
};

function clearRoleScopedCaches() {
  ROLE_SCOPED_CACHE_KEYS.forEach(k => localStorage.removeItem(k));
  UserRegistry._cache = null;
  StaffRecs._cache = null;
  RegisteredCompanies._cache = null;
  AlumniReferrals._cache = null;
  AlumniSuccessStories._cache = null;
  DepartmentStore._cache = null;
  PlacementRules._cache = null;
  ApplicationPipeline._cache = null;
  BlacklistStore._cache = null;
  RecruitmentResults._cache = null;
  ResumeQueue._cache = null;
  SystemSettings._cache = null;
  PublicPageContent._cache = null;
  PublicPageContent._liveStats = null;
  PlacementNewsStore._cache = null;
  DriveStore._apiCache = null;
}

async function dashboardStats() {
  if (Auth.role() === 'placement_officer' && typeof OfficerApi !== 'undefined' && Auth.hasRealAuth()) {
    const stats = await OfficerApi.fetchDashboard();
    if (stats) {
      return {
        totalStudents: stats.totalStudents ?? 0,
        totalCompanies: RegisteredCompanies.all().length,
        totalStaff: StaffRegistry.all().length,
        totalAlumni: Math.max(UserRegistry.byRole('alumni').length, 0),
        totalDrives: stats.activeDrives ?? 0,
        placedStudents: stats.placedStudents ?? 0,
        pendingApprovals: stats.pendingApprovals ?? 0,
        placementPct: stats.placementPercentage ?? 0,
        salary: { highest:68, lowest:3.5, average:9.4, median:8.2 },
        branchStats: DEPARTMENT_PLACEMENT,
        companyStats: activeRecruitingCompanies().slice(0, 8),
        department: stats.department || null,
      };
    }
  }
  if (Auth.role() === 'admin' && typeof AdminApi !== 'undefined' && Auth.hasRealAuth()) {
    const [stats, drives] = await Promise.all([
      AdminApi.fetchDashboard(),
      AdminApi.fetchDrives(),
    ]);
    if (stats) {
      const total = stats.totalStudents ?? 0;
      const placed = stats.placedStudents ?? 0;
      const activeDrives = drives
        ? drives.filter(d => String(d.status || '').toLowerCase() !== 'closed').length
        : 0;
      return {
        totalStudents: total,
        totalCompanies: stats.totalCompanies ?? 0,
        totalStaff: StaffRegistry.all().length,
        totalAlumni: Math.max(UserRegistry.byRole('alumni').length, 0),
        totalDrives: activeDrives,
        placedStudents: placed,
        pendingApprovals: stats.pendingApprovals ?? 0,
        placementPct: total ? ((placed / total) * 100).toFixed(1) : 0,
        salary: { highest:68, lowest:3.5, average:9.4, median:8.2 },
        branchStats: DEPARTMENT_PLACEMENT,
        companyStats: activeRecruitingCompanies().slice(0, 8),
      };
    }
  }
  const students = UserRegistry.byRole('student');
  const placed = students.filter(s => s.placementStatus === 'placed').length;
  const total = 3284;
  const salaries = [68, 52, 42, 28, 18, 12, 9.4, 7.5, 4.5, 3.5];
  const sorted = [...salaries].sort((a,b) => a-b);
  const mid = Math.floor(sorted.length / 2);
  return {
    totalStudents: total,
    totalCompanies: UserRegistry.byRole('company').length + RegisteredCompanies.all().length,
    totalStaff: StaffRegistry.all().length,
    totalAlumni: Math.max(UserRegistry.byRole('alumni').length, 840),
    totalDrives: DriveStore.allWithCatalog().filter(d => d.status !== 'Closed').length,
    placedStudents: placed || placementDeptTotals().placed,
    pendingApprovals: UserRegistry.all().filter(u => u.status === 'pending').length + ResumeQueue.all().filter(r => r.status === 'pending').length,
    placementPct: total ? ((placed || placementDeptTotals().placed) / total * 100).toFixed(1) : 0,
    salary: { highest:68, lowest:3.5, average:9.4, median: sorted[mid] || 8.2 },
    branchStats: DEPARTMENT_PLACEMENT,
    companyStats: activeRecruitingCompanies().slice(0, 8),
  };
}

function stageBadge(stage) {
  const map = { applied:['muted','Applied'], resume_verification:['warning','Resume verify'], approval:['info','Approval'], company_selection:['primary','Company'], rejected:['danger','Rejected'], shortlisted:['success','Shortlisted'] };
  const [cls, label] = map[stage] || ['muted', stage];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

const TrackingStore = {
  async fetch(limit = 100) {
    if (!Auth.hasRealAuth()) return null;
    const role = Auth.role();
    if (role !== 'admin' && role !== 'placement_officer') return null;
    const path = role === 'admin'
      ? `/admin/tracking?limit=${encodeURIComponent(limit)}`
      : `/officer/tracking?limit=${encodeURIComponent(limit)}`;
    const res = await api(path);
    return res.success ? res.data : null;
  },
};

const RecruitingStore = {
  async fetch() {
    if (!Auth.hasRealAuth()) return null;
    const paths = {
      company: '/company/recruiting',
      admin: '/admin/recruiting',
      placement_officer: '/officer/recruiting',
    };
    const path = paths[Auth.role()];
    if (!path) return null;
    const res = await api(path);
    return res.success ? res.data : null;
  },

  mapActiveCompany(row, mineName = '') {
    const openRoles = row.openRoles ?? 0;
    return {
      company: row.company || '',
      companyId: row.companyId || '',
      roles: openRoles > 0 ? [`${openRoles} open role${openRoles === 1 ? '' : 's'}`] : ['—'],
      openRoles,
      package: row.package || '—',
      applicants: row.applicants ?? 0,
      status: row.status || 'open',
      statusCls: { scheduled: 'info', open: 'success', ongoing: 'info', reviewing: 'warning' }[String(row.status || '').toLowerCase()] || 'success',
      mine: !!(mineName && row.company === mineName),
    };
  },

  mapApplicant(row) {
    const st = row.student || {};
    const job = row.job || {};
    const drive = row.drive || {};
    return {
      id: row.id || row._id || '',
      name: st.name || row.studentName || 'Student',
      roll: st.registerNumber || row.registerNumber || '',
      dept: st.department || row.department || '',
      cgpa: parseFloat(st.cgpa ?? row.cgpa ?? 0) || 0,
      role: job.title || drive?.title || row.role || '—',
      status: row.uiStatus || row.status || 'applied',
      appliedAt: row.createdAt || row.appliedAt || '',
    };
  },

  mapDeptRow(row) {
    return {
      dept: row.department || '',
      count: row.applicants ?? 0,
      share: row.share ?? 0,
    };
  },
};

const AnalyticsStore = {
  async fetchExtended() {
    if (!Auth.hasRealAuth()) return null;
    const role = Auth.role();
    if (role !== 'admin' && role !== 'placement_officer') return null;
    const res = await api('/analytics/extended');
    return res.success ? res.data : null;
  },
};

const PlacementConsoleStore = {
  async fetch() {
    if (!Auth.hasRealAuth()) return null;
    const role = Auth.role();
    if (role === 'admin' && typeof AdminApi !== 'undefined') {
      return AdminApi.fetchPlacementConsole();
    }
    if (role === 'placement_officer' && typeof OfficerApi !== 'undefined') {
      return OfficerApi.fetchPlacementConsole();
    }
    const res = await api('/analytics/placement-console');
    return res.success ? res.data : null;
  },

  mapDepartment(row) {
    return {
      dept: row.code || row.department || '',
      students: row.students ?? 0,
      applicants: row.applicants ?? 0,
      shortlisted: row.shortlisted ?? 0,
      selected: row.selected ?? 0,
      placed: row.placed ?? 0,
      pct: row.placementPct ?? 0,
      avgPkg: row.avgPackage ?? 0,
    };
  },
};

function userStatusBadge(status, blocked) {
  if (blocked) return '<span class="badge-soft danger">Blocked</span>';
  const map = { approved:['success','Approved'], pending:['warning','Pending'], rejected:['danger','Rejected'] };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

/* Generic fetch with session cookies; optional 401 redirect for expired sessions */
async function apiFetch(path, opts = {}) {
  if (opts.noRedirectOn401 && opts.skipAuthRedirect === undefined) {
    opts = { ...opts, skipAuthRedirect: true };
  }
  const token = Auth.token();
  const headers = { ...(opts.headers || {}) };
  if (token && token !== 'session' && !token.startsWith('demo-token')) {
    headers.Authorization = `Bearer ${token}`;
  }
  let body = opts.body;
  if (body instanceof FormData) {
    // Let the browser set multipart boundary.
  } else if (body != null && typeof body !== 'string') {
    body = JSON.stringify(body);
    headers['Content-Type'] = headers['Content-Type'] || 'application/json';
  } else if (body != null && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  try {
    const res = await fetch(API_BASE + path, {
      method: opts.method || 'GET',
      headers,
      body,
      credentials: 'include',
    });
    if (res.status === 401) {
      if (!opts.skipAuthRetry && !opts._authRetry) {
        const restored = await Auth.bootstrap();
        if (restored) {
          return apiFetch(path, { ...opts, _authRetry: true });
        }
      }
      if (!opts.skipAuthRedirect && !Auth.isDemo()) {
        const page = document.body?.dataset?.page;
        const next = page && page !== 'login.html' ? `?next=${encodeURIComponent(page)}` : '';
        Auth.clear();
        window.location.href = `login.html${next}`;
        return { success: false, message: 'Session expired', data: null, status: 401 };
      }
      return {
        success: false,
        message: Auth.isDemo()
          ? 'Sign in with your account on the login page to save changes. Preview mode is read-only.'
          : 'Session expired. Please sign in again.',
        data: null,
        status: 401,
      };
    }
    const json = await res.json().catch(() => ({ success: false, message: 'Bad response', data: null }));
    json.status = res.status;
    return json;
  } catch (e) {
    return { success: false, message: e.message || 'Network error', data: null, _offline: true };
  }
}

async function api(path, opts = {}) {
  return apiFetch(path, opts);
}

function onAppReady(fn) {
  if (document.body?.dataset?.page && document.body.dataset.page !== 'login.html') {
    document.addEventListener('ph-ready', fn, { once: true });
  } else {
    fn();
  }
}

function mockAuthRoleFromEmail(email = '') {
  const e = String(email).toLowerCase();
  if (e.includes('admin@')) return 'admin';
  if (e.includes('riya@') || e.includes('officer@')) return 'placement_officer';
  if (e.includes('iyer@') || e.includes('staff@') || e.includes('prof.')) return 'staff';
  if (e.includes('student') || e.includes('karthik')) return 'student';
  if (e.includes('alumni')) return 'alumni';
  if (e.includes('company') || e.includes('acme')) return 'company';
  return 'student';
}

/* Mock login/register for the preview when no backend is reachable */
async function mockAuth(kind, payload) {
  await new Promise(r => setTimeout(r, 300));
  const role = payload.role || mockAuthRoleFromEmail(payload.email);
  const user = { ...demoUserFor(role), ...payload, role };
  return { success:true, message: kind==='register' ? 'Registered. Pending approval.' : 'Logged in', data: { user, token: 'demo-token-' + Date.now() }, _offline: true };
}

/* Toasts */
function toast(msg, kind='info') {
  let host = document.getElementById('ph-toasts');
  if (!host) {
    host = document.createElement('div');
    host.id = 'ph-toasts';
    host.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;max-width:340px';
    if (window.matchMedia('(max-width:575px)').matches) {
      host.style.cssText = 'position:fixed;left:1rem;right:1rem;bottom:calc(1rem + env(safe-area-inset-bottom,0px));top:auto;z-index:9999;display:flex;flex-direction:column;gap:.5rem;max-width:none';
    }
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
  const employed = alumniIsWorking();
  root.querySelectorAll('[data-roles]').forEach(el => {
    const ok = el.dataset.roles.split(',').map(s=>s.trim()).includes(role);
    el.style.display = ok ? '' : 'none';
  });
  root.querySelectorAll('[data-not-roles]').forEach(el => {
    const blocked = el.dataset.notRoles.split(',').map(s=>s.trim()).includes(role);
    el.style.display = blocked ? 'none' : '';
  });
  root.querySelectorAll('[data-alumni-employed]').forEach(el => {
    el.style.display = (role === 'alumni' && employed) ? '' : 'none';
  });
  root.querySelectorAll('[data-alumni-seeking]').forEach(el => {
    el.style.display = (role === 'alumni' && !employed) ? '' : 'none';
  });
}

(function patchAuthCacheIsolation() {
  const origClear = Auth.clear.bind(Auth);
  const origSetRole = Auth.setRole.bind(Auth);
  Auth.clear = function () {
    clearRoleScopedCaches();
    return origClear();
  };
  Auth.setRole = function (role) {
    clearRoleScopedCaches();
    return origSetRole(role);
  };
})();
