/* PlaceHub — API client, auth state, role permissions, mock fallback */
const APP_SCRIPT_VERSION = '20260625a';

const BRAND = {
  logoSrc: '/css/ajce-logo.png?v=20260624s',
  logoFallbackSrc: '/logo.php?v=20260624s',
  logoDataUri: 'data:image/png;base64,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',
  logoAlt: 'Amal Jyothi College of Engineering — AJCE Placements',
  fullName: 'Amal Jyothi College of Engineering — AJCE Placements',
  title: 'AJCE Placements',
  subtitle: 'Amal Jyothi College of Engineering (Autonomous)',
};

function brandLogoHtml(height = 34, className = 'brand-logo') {
  const src = BRAND.logoDataUri || BRAND.logoSrc;
  return `<img src="${src}" alt="${BRAND.logoAlt}" class="${className}" height="${height}" decoding="async"/>`;
}

function brandBlockHtml(opts = {}) {
  const showTitle = opts.showTitle === true;
  const showSubtitle = showTitle && opts.showSubtitle === true;
  const href = opts.href || '';
  const inner = `
    <div class="d-flex align-items-center gap-2 ${opts.className || ''}">
      ${brandLogoHtml(opts.logoHeight || 34)}
      ${showTitle ? `<div style="min-width:0">
        <div style="font-weight:700;line-height:1.2">${BRAND.title}</div>
        ${showSubtitle ? `<div style="font-size:.7rem;color:var(--muted);font-weight:500;line-height:1.2">${BRAND.subtitle}</div>` : ''}
      </div>` : ''}
    </div>`;
  return href
    ? `<a href="${href}" class="d-flex align-items-center gap-2 text-decoration-none text-reset">${inner}</a>`
    : inner;
}

function isSyntheticStudentEmail(email, registerNumber) {
  const e = String(email || '').trim().toLowerCase();
  if (!e.includes('@students.amaljyothi.ac.in')) return false;
  const reg = String(registerNumber || '').replace(/[^a-z0-9]/gi, '').toLowerCase();
  if (!reg) return true;
  const local = e.split('@')[0] || '';
  return local === reg;
}

function isCollegeEmail(email) {
  const e = String(email || '').trim().toLowerCase();
  if (!e.includes('@')) return false;
  const domain = e.split('@').pop() || '';
  if (domain.includes('amaljyothi.ac.in')) return true;
  return domain === 'ajce.in' || domain.endsWith('.ajce.in');
}

function isPersonalEmailDomain(email) {
  const e = String(email || '').trim().toLowerCase();
  const personalDomains = [
    '@gmail.com', '@googlemail.com', '@yahoo.com', '@yahoo.in', '@outlook.com', '@hotmail.com',
    '@live.com', '@icloud.com', '@protonmail.com', '@rediffmail.com',
  ];
  return personalDomains.some((d) => e.endsWith(d));
}

function normalizeSessionEmails(college, personal) {
  let c = String(college || '').trim().toLowerCase();
  let p = String(personal || '').trim().toLowerCase();
  if (p && isCollegeEmail(p)) {
    if (!c) c = p;
    p = '';
  }
  if (c && isPersonalEmailDomain(c)) {
    if (!p) p = c;
    c = '';
  }
  return { collegeEmail: c, personalEmail: p, email: c || p };
}

function inferNameFromEmail(email) {
  const e = String(email || '').trim().toLowerCase();
  if (!e.includes('@')) return '';
  let local = e.split('@')[0] || '';
  local = local.replace(/\d+$/, '').replace(/[._+-]+/g, ' ').trim();
  if (local.length < 3 || !/[a-zA-Z]/.test(local)) return '';
  return local.replace(/\b\w/g, (c) => c.toUpperCase());
}
const ROLES = ['admin','placement_officer','student','staff','company','alumni'];

function sanitizeDisplayName(name, registerNumber) {
  const n = String(name || '').trim();
  if (!n) return '';
  const reg = String(registerNumber || '').trim();
  if (reg && n.toUpperCase() === reg.toUpperCase()) return '';
  if (/^\d+$/.test(n)) return '';
  return n;
}

function resolveSessionName(merged, registerNumber) {
  const reg = registerNumber || merged.registerNumber || '';
  const sources = [merged, merged.aesProfile || {}];
  const candidates = [];
  for (const src of sources) {
    candidates.push(...collectAesNameCandidates(src));
  }
  const best = pickBestAesName(candidates, reg);
  if (best) return best;
  const inferred = inferNameFromEmail(merged.personalEmail || '');
  const fromEmail = sanitizeDisplayName(inferred, reg);
  if (fromEmail) return fromEmail;
  return sanitizeDisplayName(merged.name || '', reg);
}

function collectAesNameCandidates(src) {
  if (!src || typeof src !== 'object') return [];
  const candidates = [];
  const priorityKeys = [
    'full_name', 'fullname', 'student_full_name', 'studentfullname', 'stud_full_name', 'studfullname',
    'user_full_name', 'complete_name', 'name_in_full', 'applicant_name', 'candidate_name',
    'student_name', 'studentName', 'studname', 'stud_name', 'stu_name', 'stuname',
    'display_name', 'displayname', 'staff_name', 'emp_name', 'name', 'sname', 'nm', 'stu_nm', 'uname',
  ];
  for (const key of priorityKeys) {
    const val = pickInsensitiveFrom(src, [key]);
    if (val) candidates.push({ name: val, key });
  }
  const fname = pickInsensitiveFrom(src, ['fname', 'first_name', 'firstName', 'firstname', 'stu_fname']);
  const lname = pickInsensitiveFrom(src, ['lname', 'last_name', 'lastName', 'lastname', 'stu_lname', 'mname', 'middle_name']);
  const combined = `${fname} ${lname}`.trim();
  if (combined) candidates.push({ name: combined, key: 'first_last_combined' });
  for (const [key, raw] of Object.entries(src)) {
    if (!isNameFieldKey(key)) continue;
    const val = String(raw || '').trim();
    if (val) candidates.push({ name: val, key });
  }
  return candidates;
}

function pickBestAesName(candidates, registerNumber) {
  const reg = String(registerNumber || '').trim();
  let best = '';
  let bestScore = -1;
  const seen = new Set();
  for (const entry of candidates) {
    const name = String(entry.name || '').trim();
    const key = String(entry.key || '').toLowerCase();
    if (!name) continue;
    const normalized = name.toLowerCase().replace(/\s+/g, ' ');
    if (seen.has(normalized)) continue;
    seen.add(normalized);
    const clean = sanitizeDisplayName(name, reg);
    if (!clean) continue;
    let score = clean.length;
    if (key.includes('full')) score += 200;
    if (key.includes('first_last')) score += 150;
    if (key.includes('student') || key.includes('stud')) score += 80;
    if (clean.includes(' ')) score += 50;
    if (key === 'name' || key === 'nm' || key === 'uname') score -= 30;
    if (score > bestScore) {
      bestScore = score;
      best = clean;
    }
  }
  return best;
}

function pickInsensitiveFrom(obj, keys) {
  if (!obj || typeof obj !== 'object') return '';
  const lowerMap = {};
  for (const [key, value] of Object.entries(obj)) {
    if (value == null || value === '') continue;
    lowerMap[String(key).toLowerCase()] = String(value).trim();
  }
  for (const key of keys) {
    const val = lowerMap[String(key).toLowerCase()];
    if (val) return val;
  }
  return '';
}

function isNameFieldKey(key) {
  const k = String(key || '').toLowerCase();
  if (['name', 'fullname', 'full_name', 'displayname', 'display_name', 'nm', 'uname', 'sname', 'studname', 'stuname', 'studentname', 'studentfullname', 'student_full_name', 'studfullname'].includes(k)) {
    return true;
  }
  if (/parent|father|mother|guardian|dept|branch|company/.test(k)) return false;
  return k.endsWith('_name') || k.includes('studname') || k.includes('studentname');
}

function resolveSessionDepartment(merged) {
  const academic = (merged.academic && typeof merged.academic === 'object') ? merged.academic : {};
  const keys = [
    'department', 'departmentName', 'branch', 'dept', 'dept_name', 'branch_name',
    'department_name', 'department_code', 'dept_code', 'branch_code', 'programme', 'program',
  ];
  for (const key of keys) {
    const val = String(merged[key] || academic[key] || '').trim();
    if (val) return val.toUpperCase();
  }
  return '';
}

function resolveSessionCgpa(merged) {
  const academic = (merged.academic && typeof merged.academic === 'object') ? merged.academic : {};
  const keys = ['cgpa', 'CGPA', 'gpa', 'GPA', 'current_cgpa', 'currentCgpa', 'cumulative_cgpa', 'overall_cgpa'];
  for (const key of keys) {
    const val = merged[key] ?? academic[key];
    if (val != null && val !== '' && Number(val) > 0) return Number(val);
  }
  return undefined;
}

function resolveSessionEmails(merged, registerNumber) {
  const reg = registerNumber || merged.registerNumber || '';
  let college = String(merged.collegeEmail || '').trim().toLowerCase();
  let personal = String(merged.personalEmail || '').trim().toLowerCase();

  if (college && isSyntheticStudentEmail(college, reg)) college = '';

  const collegeKeys = [
    'college_email', 'collegeemail', 'college_mail', 'collegemail', 'college_mail_id',
    'official_email', 'student_email', 'studentemail', 'stu_email', 'institutional_email', 'ajce_email',
  ];
  const personalKeys = ['personal_email', 'personalemail', 'gmail', 'alternate_email', 'alt_email', 'private_email'];

  if (!college) {
    for (const key of collegeKeys) {
      const val = String(merged[key] || merged.aesProfile?.[key] || '').trim().toLowerCase();
      if (val && val.includes('@') && !isSyntheticStudentEmail(val, reg) && !isPersonalEmailDomain(val)) {
        college = val;
        break;
      }
    }
  }
  if (!personal) {
    for (const key of personalKeys) {
      const val = String(merged[key] || merged.aesProfile?.[key] || '').trim().toLowerCase();
      if (val && val.includes('@') && !isCollegeEmail(val)) {
        personal = val;
        break;
      }
    }
  }

  for (const key of Object.keys(merged)) {
    const lowerKey = key.toLowerCase();
    const val = String(merged[key] || '').trim().toLowerCase();
    if (!val.includes('@') || !val.includes('.')) continue;
    if (/college|official|institut|student_email|stu_email|ajce_email|inst_mail/.test(lowerKey)) {
      if (!college && !isSyntheticStudentEmail(val, reg) && !isPersonalEmailDomain(val)) college = val;
      continue;
    }
    if (/personal|gmail|alternate|private/.test(lowerKey)) {
      if (!personal && isPersonalEmailDomain(val)) personal = val;
      continue;
    }
    if (isCollegeEmail(val)) {
      if (!college && !isSyntheticStudentEmail(val, reg)) college = val;
    } else if (!personal && isPersonalEmailDomain(val)) {
      personal = val;
    }
  }

  return normalizeSessionEmails(college, personal);
}

function resolveSessionEmail(merged, registerNumber) {
  return resolveSessionEmails(merged, registerNumber).email;
}

function resolveSessionPhone(merged) {
  const keys = [
    'phone', 'mobile', 'phone_no', 'phoneNo', 'mob', 'contact', 'contact_no', 'mobile_no', 'mobileno',
    'cell', 'student_mobile', 'studentMobile', 'stu_mobile', 'stuMobile',
    'parent_mobile', 'parentMobile', 'father_mobile', 'mother_mobile',
    'stu_phone', 'stuPhone', 'personal_mobile', 'personalMobile', 'whatsapp',
  ];
  for (const key of keys) {
    const val = String(merged[key] || '').trim();
    const digits = val.replace(/\D/g, '');
    if (val && digits !== '919876543210' && digits !== '9876543210') return val;
  }
  return String(merged.phone || '').trim();
}

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
    Auth.clear();
    return { success: false, message: 'Sign-in succeeded but the session could not be verified. Try again.' };
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
  admin: { email: 'placements@amaljyothi.ac.in', password: 'Placements@2026' },
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
    if (r === 'admin') return 'dashboard.html';
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
    if (this.role() === 'admin') return 'dashboard.html';
    if (this.role() === 'placement_officer') return 'placement-console.html';
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
    const aes = (u.aesProfile && typeof u.aesProfile === 'object') ? u.aesProfile : {};
    const merged = { ...prev, ...u, ...aes };
    const reg = merged.registerNumber || prev.registerNumber || '';
    const emails = resolveSessionEmails(merged, reg);
    const role = u.role || prev.role || '';
    const dashboard = u.dashboard || prev.dashboard || '';
    this.set(
      {
        ...prev,
        id: merged.id || merged._id || prev.id || '',
        name: resolveSessionName(merged, reg),
        email: emails.email,
        collegeEmail: emails.collegeEmail || prev.collegeEmail || '',
        personalEmail: emails.personalEmail || prev.personalEmail || '',
        role,
        department: resolveSessionDepartment(merged) || merged.department || prev.department || '',
        departmentId: merged.departmentId || prev.departmentId || '',
        departmentName: resolveSessionDepartment(merged) || merged.departmentName || prev.departmentName || '',
        designation: merged.designation || prev.designation || '',
        company: merged.company ?? prev.company ?? '',
        companyName: merged.companyName ?? prev.companyName ?? '',
        companyId: merged.companyId || prev.companyId || '',
        registerNumber: merged.registerNumber || prev.registerNumber || '',
        studentId: merged.studentId || prev.studentId || '',
        classBatch: merged.classBatch || prev.classBatch || '',
        cgpa: resolveSessionCgpa(merged) ?? merged.cgpa ?? prev.cgpa,
        backlogs: merged.backlogs ?? prev.backlogs,
        placed: merged.placed ?? prev.placed,
        title: merged.title ?? prev.title ?? '',
        experience: merged.experience ?? prev.experience,
        isWorking: merged.isWorking ?? prev.isWorking,
        skills: merged.skills || prev.skills || [],
        category: merged.category || prev.category || '',
        tier: merged.tier || prev.tier || '',
        website: merged.website || prev.website || '',
        dashboard,
        phone: resolveSessionPhone(merged) || prev.phone || '',
        course: merged.course || prev.course || '',
        year: merged.year || prev.year || '',
        semester: merged.semester || prev.semester || '',
        gender: merged.gender || prev.gender || '',
        bloodGroup: merged.bloodGroup || prev.bloodGroup || '',
        address: merged.address || prev.address || '',
        parentName: merged.parentName || prev.parentName || '',
        dob: merged.dob || prev.dob || '',
        aadhar: merged.aadhar || prev.aadhar || '',
        aesProfile: u.aesProfile || prev.aesProfile || null,
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
    window.location.href = 'public-stats.html';
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
  async fetchForApplicant() {
    const role = Auth.role();
    if (role === 'student') return this.fetch();
    if (role !== 'alumni' || Auth.isDemo()) return this.all();

    const libraryRes = await apiFetch('/alumni/resumes', { skipAuthRedirect: true });
    if (libraryRes.success && Array.isArray(libraryRes.data) && libraryRes.data.length) {
      const fromLibrary = libraryRes.data.map(r => ({
        id: r._id || r.id,
        label: r.label || 'Resume',
        profileType: r.profileType || 'General',
        fileName: r.fileName || '',
        fileSize: r.fileSize || 0,
        bucketPath: r.viewUrl || '',
        uploadedAt: r.uploadedAt || new Date().toISOString(),
        verified: !!r.verified,
        fromApi: true,
      }));
      this.save(fromLibrary);
      return fromLibrary;
    }

    return this.all();
  },
  async fetch() {
    if (Auth.role() !== 'student' || Auth.isDemo()) return this.all();

    const libraryRes = await apiFetch('/student/resumes', { skipAuthRedirect: true });
    if (libraryRes.success && Array.isArray(libraryRes.data) && libraryRes.data.length) {
      const fromLibrary = libraryRes.data.map(r => ({
        id: r._id || r.id,
        label: r.label || 'Resume',
        profileType: r.profileType || 'General',
        fileName: r.fileName || '',
        fileSize: r.fileSize || 0,
        bucketPath: r.viewUrl || '',
        uploadedAt: r.uploadedAt || new Date().toISOString(),
        verified: !!r.verified,
        fromApi: true,
      }));
      this.save(fromLibrary);
      return fromLibrary;
    }

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
      id: a.id || a._id,
      driveId: a.driveId || '',
      company: a.company || '',
      role: a.role || '',
      package: a.resultPackage || a.package || '',
      resumeLabel: a.resumeLabel || '—',
      status: a.status || 'applied',
      resultStatus: a.resultStatus || '',
      resultJoiningDate: a.resultJoiningDate || '',
      resultId: a.resultId || '',
      appliedAt: a.appliedAt || a.createdAt || '',
    }));
    this.save(mapped);
    return mapped;
  },
  async apply(drive, resumeId, certificateFiles = []) {
    if (this.hasApplied(drive.id)) return null;
    const resume = ResumeBucket.all().find(r => r.id === resumeId);
    const resumePath = this.resumePathForApply(resume);
    const certs = Array.isArray(certificateFiles) ? certificateFiles.filter(f => f?.name) : [];

    if (Auth.role() === 'student' && Auth.hasSession() && !Auth.isDemo()) {
      let res;
      if (certs.length) {
        const form = new FormData();
        form.append('driveId', drive.id);
        form.append('resumeId', resumeId || '');
        form.append('resumeLabel', resume?.label || '');
        form.append('resumeFileName', resume?.fileName || '');
        form.append('resumePath', resumePath || '');
        certs.forEach(file => form.append('certificates[]', file));
        res = await apiFetch('/student/apply', { method: 'POST', body: form });
      } else {
        res = await api('/student/apply', {
          method: 'POST',
          body: {
            driveId: drive.id,
            resumeId: resumeId || '',
            resumeLabel: resume?.label || '',
            resumeFileName: resume?.fileName || '',
            resumePath,
          },
        });
      }
      if (!res.success) {
        const msg = res.message || 'Application failed.';
        if (/not eligible/i.test(msg) && /resume/i.test(msg)) {
          toast(`${msg} Upload a resume in Settings → Resumes, then try again.`, 'error');
        } else {
          toast(msg, 'error');
        }
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

const AlumniApps = {
  all() {
    try { return JSON.parse(localStorage.getItem(studentKey('alumni-applications')) || '[]'); } catch { return []; }
  },
  save(list) { localStorage.setItem(studentKey('alumni-applications'), JSON.stringify(list)); },
  hasApplied(driveId) { return this.all().some(a => a.driveId === driveId); },
  get(driveId) { return this.all().find(a => a.driveId === driveId); },
  async fetch() {
    if (Auth.role() !== 'alumni' || Auth.isDemo()) return this.all();
    const res = await api('/alumni/applications');
    if (!res.success || !Array.isArray(res.data)) return this.all();
    const mapped = res.data.map(a => ({
      id: a.id || a._id,
      driveId: a.driveId || '',
      company: a.company || '',
      role: a.role || '',
      package: a.resultPackage || a.package || '',
      status: a.status || 'applied',
      appliedAt: a.appliedAt || a.createdAt || '',
    }));
    this.save(mapped);
    return mapped;
  },
  async apply(drive, resumeId, certificateFiles = []) {
    if (this.hasApplied(drive.id)) return null;
    if (Auth.role() !== 'alumni' || !Auth.hasRealAuth() || Auth.isDemo()) return null;
    const resume = ResumeBucket.all().find(r => r.id === resumeId);
    const resumePath = StudentApps.resumePathForApply(resume);
    const certs = Array.isArray(certificateFiles) ? certificateFiles.filter(f => f?.name) : [];

    let res;
    if (certs.length) {
      const form = new FormData();
      form.append('driveId', drive.id);
      form.append('resumeId', resumeId || '');
      form.append('resumeLabel', resume?.label || '');
      form.append('resumeFileName', resume?.fileName || '');
      form.append('resumePath', resumePath || '');
      certs.forEach(file => form.append('certificates[]', file));
      res = await apiFetch('/alumni/apply', { method: 'POST', body: form });
    } else {
      res = await api('/alumni/apply', {
        method: 'POST',
        body: {
          driveId: drive.id,
          resumeId: resumeId || '',
          resumeLabel: resume?.label || '',
          resumeFileName: resume?.fileName || '',
          resumePath,
        },
      });
    }
    if (!res.success) {
      const msg = res.message || 'Application failed.';
      if (/not eligible/i.test(msg) && /resume/i.test(msg)) {
        toast(`${msg} Upload a resume in Settings → Resumes, then try again.`, 'error');
      } else {
        toast(msg, 'error');
      }
      return null;
    }
    await this.fetch();
    return res.data;
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
    resume_pending: ['warning','Resume pending'],
    resume_verified: ['info','Resume verified'],
    officer_approved: ['info','Officer approved'],
    company_review: ['warning','Under review'],
    under_review: ['warning','Under review'],
    shortlisted: ['success','Shortlisted'],
    selected: ['success','Selected'],
    rejected: ['danger','Not selected'],
    withdrawn: ['muted','Withdrawn'],
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
    if (Auth.role() === 'admin') {
      this._cache = [];
      localStorage.setItem(DEPTS_KEY, JSON.stringify([]));
      return [];
    }
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
  async unassignOfficer(deptId) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/departments/${encodeURIComponent(deptId)}/placement-officer`, { method: 'DELETE' });
    if (res.success) { await this.fetch(); return true; }
    toast(res.message || 'Could not remove placement officer.', 'error');
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
    const password = String(p.password || '').trim();
    if (password.length < 8) {
      toast('Password must be at least 8 characters.', 'error');
      return null;
    }
    const body = {
      name: (p.name || '').trim(),
      email: String(p.email || '').trim().toLowerCase(),
      password,
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
  async promoteToOfficer(userId) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/users/${encodeURIComponent(userId)}/promote-to-officer`, { method: 'POST' });
    if (res.success) {
      await this.fetch();
      if (typeof DepartmentStore !== 'undefined') await DepartmentStore.fetch();
      return true;
    }
    toast(res.message || 'Could not assign placement officer.', 'error');
    return false;
  },
  async demoteFromOfficer(userId) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/users/${encodeURIComponent(userId)}/demote-from-officer`, { method: 'POST' });
    if (res.success) {
      await this.fetch();
      if (typeof DepartmentStore !== 'undefined') await DepartmentStore.fetch();
      return true;
    }
    toast(res.message || 'Could not remove placement officer role.', 'error');
    return false;
  },
  async changeDepartmentOfficer(deptId, userId) {
    if (!(await requireWriteSession())) return false;
    const res = await api(`/admin/departments/${encodeURIComponent(deptId)}/placement-officer`, {
      method: 'PUT',
      body: { userId },
    });
    if (res.success) {
      await this.fetch();
      if (typeof DepartmentStore !== 'undefined') await DepartmentStore.fetch();
      return true;
    }
    toast(res.message || 'Could not change placement officer.', 'error');
    return false;
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
    const path = Auth.role() === 'placement_officer' ? '/officer/results/' : '/admin/results/';
    const res = await api(`${path}${encodeURIComponent(id)}`, { method: 'DELETE' });
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
    const res = await api('/public/site-content', { skipAuthRedirect: true });
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

function driveUiStatusToApi(status) {
  const map = { Open: 'scheduled', Ongoing: 'ongoing', Completed: 'completed', Closed: 'closed' };
  return map[status] || String(status || '').toLowerCase();
}

function buildDriveApiBody(patch, existing) {
  const body = {};
  if (patch.role || patch.title) body.title = patch.role || patch.title;
  if (patch.status) body.status = driveUiStatusToApi(patch.status);
  if (patch.date || patch.deadline) body.date = patch.date || patch.deadline;
  if (patch.time) body.time = patch.time;
  if (patch.type) body.type = patch.type;
  if (patch.branches) {
    body.branches = Array.isArray(patch.branches)
      ? patch.branches
      : String(patch.branches).split(',').map(s => s.trim()).filter(Boolean);
  }
  if (patch.companyId || existing?.companyId) body.companyId = patch.companyId || existing?.companyId;
  if (patch.tier) body.tier = patch.tier;
  return body;
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
    if (this._alumniCache) {
      const fromAlumni = this._alumniCache.find(d => d.id === id);
      if (fromAlumni) return fromAlumni;
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
    const prevElig = existing?.eligibility && typeof existing.eligibility === 'object'
      ? existing.eligibility
      : {};
    const packageVal = String(p.package ?? prevElig.package ?? existing?.package ?? '').trim();
    const deadlineVal = String(p.deadline ?? prevElig.deadline ?? existing?.deadline ?? '').trim();
    const jobTypeVal = String(p.jobType ?? prevElig.jobType ?? existing?.jobType ?? '').trim();
    const eligibility = {
      ...prevElig,
      package: !packageVal || packageVal === '—' ? '' : packageVal,
      deadline: !deadlineVal || deadlineVal === '—' || deadlineVal === 'TBD' ? '' : deadlineVal,
      jobType: jobTypeVal === '—' ? '' : jobTypeVal,
      description: String(p.description ?? prevElig.description ?? existing?.description ?? '').trim(),
      location: String(p.location ?? prevElig.location ?? '').trim(),
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
    const title = String(d.title || d.role || '').trim();
    let company = String(d.companyName || d.company || '').trim();
    let role = title || '—';
    if (title.includes('—')) {
      const parts = title.split('—').map(s => s.trim()).filter(Boolean);
      if (parts.length >= 2) {
        company = company || parts[0];
        role = parts.slice(1).join(' — ');
      }
    }
    const branches = Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || '');
    const elig = (d.eligibility && typeof d.eligibility === 'object' && d.eligibility.eligible === undefined)
      ? d.eligibility
      : {};
    const check = d.eligibilityCheck || (d.eligibility?.eligible !== undefined ? d.eligibility : null);
    const pkg = String(d.package || elig.package || '').trim();
    const deadline = String(d.deadline || elig.deadline || '').trim();
    const jobType = String(d.jobType || elig.jobType || '').trim();
    return {
      id: d._id || d.id,
      company: company || '—',
      role,
      package: pkg || '—',
      jobType: jobType || '—',
      branches,
      status,
      statusCls: driveStatusCls(status),
      deadline: (deadline && deadline !== 'TBD') ? deadline : (d.date || '—'),
      profile: d.profile || 'General',
      applied: d.applied ? 1 : 0,
      applicationStatus: d.applicationStatus || null,
      eligible: check ? check.eligible !== false : true,
      eligibilityReasons: check?.reasons || [],
      _fromApi: true,
    };
  },
  async fetchStudentDrives() {
    if (Auth.role() !== 'student' || Auth.isDemo()) return null;
    this._studentCache = null;
    const res = await api('/student/drives', { skipAuthRedirect: true });
    if (!res.success || !Array.isArray(res.data)) return null;
    this._studentCache = res.data.map(d => this.mapStudentDrive(d));
    return this._studentCache;
  },
  async fetchAlumniDrives() {
    if (Auth.role() !== 'alumni' || Auth.isDemo()) return null;
    const res = await api('/alumni/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    this._alumniCache = res.data.map(d => this.mapStudentDrive(d));
    return this._alumniCache;
  },
  allWithCatalog() {
    if (this._studentCache?.length) return this._studentCache;
    if (this._alumniCache?.length) return this._alumniCache;
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
        jobType: p.jobType || '',
        location: p.location || '',
        deadline: (p.deadline && p.deadline !== 'TBD') ? p.deadline : '',
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
      if (res.success) { await this.fetch(); DriveStore._studentCache = null; return res.data; }
      toast(formatErrors(res), 'error');
      return null;
    }
    if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
      const res = await api('/admin/drives', { method: 'POST', body: driveBody });
      if (res.success) { await this.fetch(); DriveStore._studentCache = null; return res.data; }
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
        if (res.success) {
          this._apiCache = null;
          await this.fetch();
          return this.get(id);
        }
        toast(formatErrors(res), 'error');
        return null;
      }
      if (Auth.role() === 'admin' && Auth.hasRealAuth()) {
        const res = await api(`/admin/drives/${encodeURIComponent(id)}`, { method: 'PUT', body });
        if (res.success) {
          this._apiCache = null;
          await this.fetch();
          return this.get(id);
        }
        toast(formatErrors(res), 'error');
        return null;
      }
      toast('Sign in with an admin or officer account to update drives.', 'error');
      return null;
    }

    const next = { ...p };
    if (p.status) next.statusCls = driveStatusCls(p.status);
    const existing = this.get(id);

    if (Auth.hasRealAuth() && existing?._fromApi !== false && !this.isCatalog(id)) {
      const body = buildDriveApiBody(next, existing);
      if (Object.keys(body).length) {
        const path = Auth.role() === 'placement_officer' ? `/officer/drives/${encodeURIComponent(id)}` : `/admin/drives/${encodeURIComponent(id)}`;
        const res = await api(path, { method: 'PUT', body });
        if (res.success) {
          await this.fetch();
          return this.get(id);
        }
        toast(res.message || 'Could not update drive.', 'error');
        return null;
      }
    }

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
  DriveStore._studentCache = null;
  DriveStore._alumniCache = null;
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
      company: row.companyName || row.company?.companyName || '',
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
    // Let the browser set multipart boundary; a preset Content-Type breaks uploads (415).
    delete headers['Content-Type'];
    delete headers['content-type'];
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
    const text = await res.text();
    let json;
    try {
      json = text ? JSON.parse(text) : {};
    } catch {
      const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      const detail = plain ? plain.slice(0, 160) : 'Empty server response';
      return {
        success: false,
        message: res.status >= 500
          ? `Server error (${res.status}). API may be down — redeploy on cPanel and confirm PHP 8.2+ and composer install. ${detail}`
          : `Bad response (${res.status}): ${detail}`,
        data: null,
        status: res.status,
      };
    }
    if (typeof json !== 'object' || json === null) {
      return { success: false, message: 'Bad response', data: null, status: res.status };
    }
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

/* Confirmation modal — replaces window.confirm for destructive / write actions */
function ensureConfirmModal() {
  if (document.getElementById('phConfirmModal')) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `
<div class="modal fade" id="phConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="phConfirmTitle">Confirm action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><p class="mb-0" id="phConfirmMessage"></p></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="phConfirmCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="phConfirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>`;
  document.body.appendChild(wrap.firstElementChild);
}

/**
 * @param {string|{ title?: string, message: string, confirmText?: string, cancelText?: string, variant?: string }} opts
 * @returns {Promise<boolean>}
 */
function confirmAction(opts) {
  const options = typeof opts === 'string' ? { message: opts } : (opts || {});
  ensureConfirmModal();
  return new Promise((resolve) => {
    const modalEl = document.getElementById('phConfirmModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const titleEl = document.getElementById('phConfirmTitle');
    const msgEl = document.getElementById('phConfirmMessage');
    const okBtn = document.getElementById('phConfirmOk');
    const cancelBtn = document.getElementById('phConfirmCancel');

    titleEl.textContent = options.title || 'Confirm action';
    msgEl.textContent = options.message || 'Are you sure you want to continue?';
    okBtn.textContent = options.confirmText || 'Confirm';
    cancelBtn.textContent = options.cancelText || 'Cancel';
    okBtn.className = `btn btn-${options.variant || 'primary'}`;

    let settled = false;
    const finish = (value) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };

    const onOk = () => {
      modal.hide();
      finish(true);
    };
    const onHidden = () => {
      okBtn.removeEventListener('click', onOk);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      if (!settled) finish(false);
    };

    okBtn.addEventListener('click', onOk);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    modal.show();
  });
}

window.confirmAction = confirmAction;
window.BRAND = BRAND;
window.brandLogoHtml = brandLogoHtml;
window.brandBlockHtml = brandBlockHtml;

/**
 * Confirm before running a form submit or other write handler.
 * @param {Event} e
 * @param {Parameters<typeof confirmAction>[0]} opts
 * @param {() => (void|Promise<void>)} handler
 */
async function confirmThen(e, opts, handler) {
  if (e?.preventDefault) e.preventDefault();
  if (!(await confirmAction(opts))) return;
  await handler();
}
window.confirmThen = confirmThen;

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
