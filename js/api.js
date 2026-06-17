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
  'drives.html':        ['admin','placement_officer','student','alumni','staff'],
  'create-drive.html':  ['admin','placement_officer'],
  'tracking.html':      ['admin','placement_officer'],
  'students.html':      ['admin','placement_officer'],
  'eligibility.html':   ['company'],
  'company.html':       ['company'],
  'applicants.html':    ['company'],
  'reports.html':       ['admin','placement_officer'],
  'notifications.html': ['admin','placement_officer','student','staff','alumni'],
  'public-stats.html':  ROLES,
  'settings.html':      ['admin','placement_officer','student','staff','alumni'],
  'alumni-jobs.html':       ['alumni'],
  'alumni-referrals.html':  ['alumni'],
  'staff-recommend.html':   ['staff'],
  'admin-companies.html':   ['admin','placement_officer'],
  'placement-console.html': ['admin','placement_officer'],
  'student-overview.html':  ['admin','placement_officer','staff'],
  'hiring-overview.html':   ['admin','placement_officer','staff'],
  'users.html':             ['admin'],
  'departments.html':       ['admin'],
  'rules.html':             ['admin'],
  'applications.html':      ['admin','placement_officer'],
  'resumes.html':           ['admin','placement_officer'],
  'blacklist.html':         ['admin'],
  'results.html':           ['admin','placement_officer'],
  'admin-settings.html':    ['admin'],
};

const ALUMNI_EMPLOYED_PAGES = ['dashboard.html', 'alumni-jobs.html', 'alumni-referrals.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const ALUMNI_SEEKING_PAGES = ['dashboard.html', 'drives.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const COMPANY_PAGES = ['dashboard.html', 'eligibility.html', 'company.html', 'applicants.html'];
const STAFF_PAGES = ['dashboard.html', 'staff-recommend.html', 'drives.html', 'student-overview.html', 'hiring-overview.html', 'settings.html', 'notifications.html', 'public-stats.html'];
const STUDENT_PAGES = ['dashboard.html', 'drives.html', 'notifications.html', 'settings.html'];

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
  isAllowed(page) {
    const role = this.role();
    if (!(PAGE_PERMS[page] || ROLES).includes(role)) return false;
    if (role === 'alumni') return alumniPageAllowed(page);
    if (role === 'company') return COMPANY_PAGES.includes(page);
    if (role === 'staff') return STAFF_PAGES.includes(page);
    if (role === 'student') return STUDENT_PAGES.includes(page);
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
  all() {
    seedStaffRecommendations();
    try { return JSON.parse(localStorage.getItem(STAFF_REC_KEY) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(STAFF_REC_KEY, JSON.stringify(list)); },
  mine() {
    const email = Auth.user()?.email;
    return this.all().filter(r => r.staffEmail === email);
  },
  add(payload) {
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
    const list = this.all();
    list.unshift(rec);
    this.save(list);
    return rec;
  },
  updateStatus(id, status) {
    this.save(this.all().map(r => r.id === id ? { ...r, status } : r));
  },
};

const RegisteredCompanies = {
  all() {
    try { return JSON.parse(localStorage.getItem(REG_COMPANIES_KEY) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(REG_COMPANIES_KEY, JSON.stringify(list)); },
  register(payload) {
    const company = {
      id: 'co-' + Date.now(),
      companyName: payload.companyName?.trim(),
      companyWebsite: payload.companyWebsite?.trim() || '',
      hrName: payload.hrName?.trim(),
      hrEmail: payload.hrEmail?.trim(),
      contactNumber: payload.contactNumber?.trim(),
      category: payload.category || 'Product',
      tier: payload.tier || 'Tier 2',
      registeredAt: new Date().toISOString(),
      sourceRecommendationId: payload.sourceRecommendationId || null,
    };
    const list = this.all();
    list.unshift(company);
    this.save(list);
    if (payload.sourceRecommendationId) StaffRecs.updateStatus(payload.sourceRecommendationId, 'registered');
    return company;
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
    { id:'ar-1', jobTitle:'SDE-2', companyName:'Google', companyWebsite:'https://careers.google.com', package:'₹38 LPA', type:'Either', description:'Backend role in Ads infra. Strong DSA + systems.', status:'Submitted', statusCls:'success', submittedAt:'2025-12-14T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
    { id:'ar-2', jobTitle:'Product Analyst', companyName:'Razorpay', companyWebsite:'https://razorpay.com/careers', package:'₹22 LPA', type:'Student', description:'Product analytics + SQL + stakeholder management.', status:'In review', statusCls:'info', submittedAt:'2025-11-30T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
    { id:'ar-3', jobTitle:'Backend Engineer', companyName:'Flipkart', companyWebsite:'', package:'₹28 LPA', type:'Either', description:'Microservices and distributed systems experience.', status:'Accepted', statusCls:'success', submittedAt:'2025-11-18T10:00:00.000Z', alumniEmail:'rohan.v@alumni.edu' },
  ]));
}

const AlumniReferrals = {
  all() { seedAlumniReferrals(); try { return JSON.parse(localStorage.getItem(ALUMNI_REF_KEY) || '[]'); } catch { return []; } },
  save(list) { localStorage.setItem(ALUMNI_REF_KEY, JSON.stringify(list)); },
  mine() {
    const email = (Auth.user()?.email || '').toLowerCase();
    if (!email) return this.all();
    return this.all().filter(r => (r.alumniEmail || '').toLowerCase() === email);
  },
  add(payload) {
    const u = Auth.user();
    const row = {
      id: 'ar-' + Date.now(),
      jobTitle: payload.jobTitle?.trim(),
      companyName: payload.companyName?.trim(),
      companyWebsite: payload.companyWebsite?.trim() || '',
      package: payload.package?.trim() || '',
      type: payload.type || 'Either',
      description: payload.description?.trim() || '',
      status: 'Submitted',
      statusCls: 'success',
      submittedAt: new Date().toISOString(),
      alumniEmail: u?.email || '',
      alumniName: u?.name || 'Alumni',
    };
    const list = this.all();
    list.unshift(row);
    this.save(list);
    return row;
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

function stripUrlProtocol(url) {
  return String(url || '').replace(/^https?:\/\//i, '');
}

function recStatusBadge(status) {
  const map = { pending: ['warning','Pending'], contacted: ['info','Contacted'], registered: ['success','Registered'] };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

function studentKey(suffix) {
  const email = Auth.user()?.email || 'anonymous';
  return `ph-student-${suffix}-${email}`;
}

const ResumeBucket = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('resumes')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('resumes'), JSON.stringify(list)); },
  seed() {
    if (this.all().length) return;
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
  upload(file, profileType, label) {
    const u = Auth.user();
    const id = 'res-' + Date.now();
    const safeName = (file.name || 'resume.pdf').replace(/[^\w.\-]/g, '_');
    const bucketPath = `s3://${RESUME_BUCKET}/${u?.registerNumber || u?.email || 'student'}/${profileType.replace(/\s+/g, '-').toLowerCase()}/${id}-${safeName}`;
    const entry = {
      id,
      label: label || profileType,
      profileType,
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
    return this.all().filter(r => r.profileType === profileType || r.profileType === 'General');
  },
};

const StudentApps = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('applications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('applications'), JSON.stringify(list)); },
  hasApplied(driveId) { return this.all().some(a => a.driveId === driveId); },
  get(driveId) { return this.all().find(a => a.driveId === driveId); },
  apply(drive, resumeId) {
    if (this.hasApplied(drive.id)) return null;
    const resume = ResumeBucket.all().find(r => r.id === resumeId);
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

function appStatusBadge(status) {
  const map = {
    applied: ['info','Applied'],
    under_review: ['warning','Under review'],
    shortlisted: ['success','Shortlisted'],
    rejected: ['danger','Not selected'],
    offered: ['success','Offered'],
  };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
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
  all() { seedDepartments(); try { return JSON.parse(localStorage.getItem(DEPTS_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(DEPTS_KEY, JSON.stringify(l)); },
  add(p) { const d = { id:'d-'+Date.now(), name:p.name?.trim(), code:p.code?.trim().toUpperCase() }; this.save([...this.all(), d]); return d; },
  update(id, p) { this.save(this.all().map(d => d.id === id ? { ...d, ...p } : d)); },
  remove(id) { this.save(this.all().filter(d => d.id !== id)); },
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
  all() { seedUsers(); try { return JSON.parse(localStorage.getItem(USERS_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(USERS_KEY, JSON.stringify(l)); },
  byRole(r) { return this.all().filter(u => u.role === r); },
  get(id) { return this.all().find(u => u.id === id); },
  update(id, patch) { this.save(this.all().map(u => u.id === id ? { ...u, ...patch } : u)); },
  remove(id) { this.save(this.all().filter(u => u.id !== id)); },
  add(p) { const u = { id:'u-'+Date.now(), status:'pending', blocked:false, blacklisted:false, ...p }; this.save([u, ...this.all()]); return u; },
  approve(id) { this.update(id, { status:'approved' }); },
  block(id, blocked=true) { this.update(id, { blocked }); },
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
  get() { seedRules(); try { return JSON.parse(localStorage.getItem(RULES_KEY) || '{}'); } catch { return {}; } },
  set(p) { const n = { ...this.get(), ...p, updatedAt:new Date().toISOString() }; localStorage.setItem(RULES_KEY, JSON.stringify(n)); return n; },
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
  all() { seedApplications(); try { return JSON.parse(localStorage.getItem(APPS_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(APPS_KEY, JSON.stringify(l)); },
  advance(id, stage, status) { this.save(this.all().map(a => a.id === id ? { ...a, stage, status } : a)); },
  approve(id) { this.advance(id, 'company_selection', 'approved'); },
  reject(id) { this.advance(id, 'rejected', 'rejected'); },
};

function seedBlacklist() {
  if (localStorage.getItem(BLACKLIST_KEY)) return;
  localStorage.setItem(BLACKLIST_KEY, JSON.stringify([
    { id:'bl-1', studentName:'Vikram Das', registerNumber:'21ME055', reason:'Unauthorized absence from Google drive', addedAt:'2025-11-20T10:00:00.000Z', active:true },
  ]));
}

const BlacklistStore = {
  all() { seedBlacklist(); try { return JSON.parse(localStorage.getItem(BLACKLIST_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(BLACKLIST_KEY, JSON.stringify(l)); },
  add(p) { const e = { id:'bl-'+Date.now(), active:true, addedAt:new Date().toISOString(), ...p }; this.save([e, ...this.all()]); return e; },
  remove(id) { this.save(this.all().map(b => b.id === id ? { ...b, active:false } : b)); },
};

function seedResults() {
  if (localStorage.getItem(RESULTS_KEY)) return;
  localStorage.setItem(RESULTS_KEY, JSON.stringify([
    { id:'res-1', studentName:'Rahul Verma', registerNumber:'21IT012', company:'Microsoft', role:'SWE', package:'₹52 LPA', status:'selected', joiningDate:'2026-07-15' },
    { id:'res-2', studentName:'Kabir Singh', registerNumber:'21IT025', company:'Adobe', role:'Product Intern', package:'₹22 LPA', status:'selected', joiningDate:'2026-06-01' },
    { id:'res-3', studentName:'Aarav Mehta', registerNumber:'21CSE001', company:'Infosys', role:'SE', package:'₹9 LPA', status:'rejected', joiningDate:'' },
  ]));
}

const RecruitmentResults = {
  all() { seedResults(); try { return JSON.parse(localStorage.getItem(RESULTS_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(RESULTS_KEY, JSON.stringify(l)); },
  upsert(p) {
    const list = this.all();
    const idx = list.findIndex(r => r.registerNumber === p.registerNumber && r.company === p.company);
    const row = { id: p.id || 'res-'+Date.now(), ...p };
    if (idx >= 0) list[idx] = { ...list[idx], ...row }; else list.unshift(row);
    this.save(list); return row;
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
  all() { seedResumeQueue(); try { return JSON.parse(localStorage.getItem(RESUME_QUEUE_KEY) || '[]'); } catch { return []; } },
  save(l) { localStorage.setItem(RESUME_QUEUE_KEY, JSON.stringify(l)); },
  approve(id) {
    const item = this.all().find(x => x.id === id);
    this.save(this.all().map(r => r.id === id ? { ...r, status:'approved' } : r));
    const u = UserRegistry.all().find(x => x.registerNumber === item?.registerNumber);
    if (u) UserRegistry.update(u.id, { resumeStatus:'approved' });
  },
  reject(id) { this.save(this.all().map(r => r.id === id ? { ...r, status:'rejected' } : r)); },
};

function checkResumeFileName(fileName) {
  return RESUME_NAME_PATTERN.test(fileName || '');
}

const SystemSettings = {
  get() {
    try {
      return JSON.parse(localStorage.getItem(SYS_SETTINGS_KEY) || JSON.stringify({
        placementYear:'2025-26', emailFrom:'placement@college.edu', maxUploadMb:10,
        smtpEnabled:true, notifyOnApproval:true,
      }));
    } catch { return { placementYear:'2025-26', maxUploadMb:10 }; }
  },
  set(p) { const n = { ...this.get(), ...p }; localStorage.setItem(SYS_SETTINGS_KEY, JSON.stringify(n)); return n; },
};

const PublicPageContent = {
  get() {
    try {
      return JSON.parse(localStorage.getItem(PUBLIC_PAGE_KEY) || JSON.stringify({
        season:'2025-26', placed:2154, highestPkg:68, avgPkg:9.4, medianPkg:8.2, lowestPkg:3.5,
        companies:142, headline:'Where ambition meets opportunity',
        achievements:'Record ₹68 LPA international offer · 92.5% MCA placement rate',
      }));
    } catch { return {}; }
  },
  set(p) { const n = { ...this.get(), ...p }; localStorage.setItem(PUBLIC_PAGE_KEY, JSON.stringify(n)); return n; },
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
  all() {
    seedPlacementNews();
    try { return JSON.parse(localStorage.getItem(PLACEMENT_NEWS_KEY) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(PLACEMENT_NEWS_KEY, JSON.stringify(list)); },
  add(payload) {
    const item = { id:'news-'+Date.now(), link:'', createdAt:new Date().toISOString(), ...payload };
    this.save([item, ...this.all()]);
    return item;
  },
  update(id, payload) {
    this.save(this.all().map(n => n.id === id ? { ...n, ...payload, updatedAt:new Date().toISOString() } : n));
  },
  remove(id) { this.save(this.all().filter(n => n.id !== id)); },
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
  isCustom(id) { return this.all().some(d => d.id === id); },
  catalogEntry(id) {
    const base = DRIVE_CATALOG.find(d => d.id === id);
    if (!base || this.hiddenIds().includes(id)) return null;
    const patch = this.overrides()[id] || {};
    const merged = { ...base, ...patch };
    if (patch.status) merged.statusCls = driveStatusCls(patch.status);
    return merged;
  },
  get(id) {
    const custom = this.all().find(d => d.id === id);
    if (custom) return custom;
    return this.catalogEntry(id);
  },
  add(p) {
    if (!canManageDrives()) return null;
    const d = { id:'drv-'+Date.now(), status:'Open', statusCls:'success', applied:0, profile:'General', ...p };
    this.save([d, ...this.all()]); return d;
  },
  update(id, p) {
    if (!canManageDrives()) return null;
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
    return null;
  },
  remove(id) {
    if (!canManageDrives()) return false;
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
  allWithCatalog() {
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
};

function dashboardStats() {
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

function userStatusBadge(status, blocked) {
  if (blocked) return '<span class="badge-soft danger">Blocked</span>';
  const map = { approved:['success','Approved'], pending:['warning','Pending'], rejected:['danger','Rejected'] };
  const [cls, label] = map[status] || ['muted', status];
  return `<span class="badge-soft ${cls}">${label}</span>`;
}

/* Generic fetch with bearer, 401 redirect, and { success, message, data } shape */
async function api(path, opts = {}) {
  const token = Auth.token();
  const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const body = opts.body && typeof opts.body !== 'string' ? JSON.stringify(opts.body) : opts.body;
  try {
    const res = await fetch(API_BASE + path, { method: opts.method || 'GET', headers, body });
    if (res.status === 401) {
      if (!opts.noRedirectOn401) { Auth.clear(); window.location.href = 'login.html'; }
      return { success:false, message:'Session expired', data:null };
    }
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
