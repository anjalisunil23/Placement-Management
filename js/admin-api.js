/**
 * Admin API helpers — maps backend responses to UI shapes used by admin pages.
 */
const AdminApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapUser(u) {
    if (!u) return null;
    return {
      id: this.id(u),
      name: u.name || '',
      email: u.email || '',
      role: u.role || '',
      status: u.approved ? 'approved' : (u.status === 'pending' ? 'pending' : (u.status || 'pending')),
      blocked: u.status === 'blocked',
      department: u.department || u.departmentName || '',
      departmentId: u.departmentId || '',
      designation: u.designation || '',
      company: u.company || u.companyName || '',
      alumniRole: u.alumniRole || '',
      companyName: u.companyName || '',
      category: u.category || '',
      tier: u.tier || '',
      associationStatus: u.associationStatus || '',
      contactPerson: u.contactPerson || u.name || '',
      phone: u.phone || '',
      registerNumber: u.registerNumber || '',
      cgpa: u.cgpa ?? u.academic?.cgpa ?? null,
      placementStatus: u.placementStatus || (u.placed ? 'placed' : 'registered'),
      chancesUsed: u.chancesUsed ?? u.placementChances?.used ?? 0,
      chancesMax: u.chancesMax ?? u.placementChances?.remaining ?? 5,
      resumeStatus: u.resumeStatus || (u.resume?.verified ? 'approved' : 'pending'),
      classBatch: u.classBatch || '',
    };
  },

  mapStudentRow(row) {
    const u = row.user || {};
    const dept = row.department || {};
    const chances = row.placementChances || {};
    const academic = row.academic || {};
    const personal = row.personal || {};
    const photo = row.photo || {};
    const resume = row.resume || {};
    const email = u.email || '';
    const isCollege = /@(students\.)?amaljyothi\.ac\.in$/i.test(email) || /\.ajce\.in$/i.test(email);
    const selfPlacement = row.selfPlacement && typeof row.selfPlacement === 'object' ? row.selfPlacement : null;
    const isPlaced = !!row.placed;
    const photoUrl = row.photoUrl || u.photoUrl || row.photo?.url || u.photo?.url || '';
    let placementStatus = 'registered';
    if (isPlaced) placementStatus = 'placed';
    else if (selfPlacement?.status === 'pending') placementStatus = 'pending_placement';
    return {
      id: this.id(u) || this.id(row),
      studentId: this.id(row),
      role: 'student',
      name: row.displayName || u.name || '',
      email,
      collegeEmail: isCollege ? email : (personal.collegeEmail || ''),
      personalEmail: personal.personalEmail || personal.email || (!isCollege ? email : ''),
      phone: personal.phone || '',
      registerNumber: row.registerNumber || '',
      department: dept.code || dept.name || '',
      departmentName: dept.name || dept.code || '',
      classBatch: row.classBatch || '',
      cgpa: academic.cgpa ?? null,
      marks10th: academic.marks10th ?? null,
      marks12th: academic.marks12th ?? academic.ugMarks ?? null,
      ugMarks: academic.ugMarks ?? academic.marks12th ?? null,
      backlogs: academic.backlogs ?? 0,
      photoUrl: row.photoUrl || photo.url || u.photoUrl || u.photo?.url || '',
      photo: row.photo || u.photo || null,
      status: u.approved ? 'approved' : 'pending',
      blocked: u.status === 'blocked',
      placed: isPlaced,
      selfPlacement,
      placementStatus,
      chancesUsed: chances.used ?? 0,
      chancesMax: (chances.used ?? 0) + (chances.remaining ?? 0),
      resumeStatus: resume.verified ? 'approved' : (resume.path ? 'pending' : 'none'),
    };
  },

  mapRecommendation(r) {
    return {
      id: this.id(r),
      companyName: r.companyName || '',
      companyWebsite: r.companyWebsite || '',
      hrName: r.hrName || r.contact?.name || '',
      hrEmail: r.hrEmail || r.contact?.email || '',
      contactNumber: r.contactNumber || r.contact?.phone || '',
      contactRole: r.contactRole || r.contact?.role || '',
      staffName: r.staffName || '',
      staffEmail: r.staffEmail || '',
      submittedAt: r.submittedAt || r.createdAt || '',
      status: r.status || 'pending',
      reason: r.reason || '',
      adminComments: r.adminComments || '',
    };
  },

  mapAlumniReferral(r) {
    const raw = String(r.status || 'pending').toLowerCase();
    const status = raw === 'submitted' ? 'pending' : raw === 'in_review' ? 'contacted' : raw === 'accepted' ? 'registered' : raw;
    return {
      id: this.id(r),
      companyName: r.companyName || r.jobTitle || '',
      companyWebsite: r.companyWebsite || r.link || '',
      hrName: r.hrName || r.contact?.name || '',
      hrEmail: r.hrEmail || r.contact?.email || '',
      contactNumber: r.contactNumber || r.contact?.phone || '',
      contactRole: r.contactRole || r.contact?.role || '',
      alumniName: r.alumniName || '',
      alumniEmail: r.alumniEmail || '',
      submittedAt: r.submittedAt || r.createdAt || '',
      status,
      adminComments: r.adminComments || '',
    };
  },

  mapCompany(c) {
    const contacts = Array.isArray(c.contacts)
      ? c.contacts
      : (c.contacts && typeof c.contacts === 'object' ? [c.contacts] : []);
    const contact = contacts[0] || {};
    const userId = String(c.userId || '');
    const companyId = this.id(c);
    const hasLogin = !!userId;
    const hrName = contact.name || c.hrName || c.contactPerson || c.name || '';
    const hrEmail = contact.email || c.hrEmail || (hasLogin ? c.email : '') || '';
    const contactNumber = contact.phone || c.contactNumber || c.phone || '';
    return {
      id: userId || companyId,
      userId: userId || null,
      companyId,
      hasLogin,
      companyName: c.companyName || '',
      companyWebsite: c.website || c.companyWebsite || '',
      hrName,
      hrEmail,
      contactNumber,
      category: c.category || '',
      tier: c.tier || '',
      registeredAt: c.createdAt || '',
      associationStatus: c.associationStatus || '',
      name: hrName || c.companyName || '',
      email: hrEmail,
      contactPerson: hrName,
      phone: contactNumber,
      status: c.associationStatus === 'active' ? 'approved' : 'pending',
      blocked: false,
      role: 'company',
    };
  },

  mergeCompanyUser(user, company) {
    if (!user) return company;
    if (!company) return { ...user, role: 'company', hasLogin: true, companyId: null };
    return {
      ...company,
      id: user.id,
      userId: user.id,
      companyId: company.companyId || company.id,
      hasLogin: true,
      name: user.name || company.name,
      email: user.email || company.email,
      contactPerson: user.name || company.contactPerson,
      status: user.status,
      blocked: user.blocked,
    };
  },

  mapBlacklistRow(row) {
    const student = row.student || {};
    const user = row.user || {};
    return {
      id: this.id(row),
      studentId: this.id(student),
      studentName: user.name || '',
      registerNumber: student.registerNumber || '',
      reason: row.reason || '',
      addedAt: row.createdAt || '',
      active: !row.removedAt,
    };
  },

  mapResumeRow(r) {
    const id = this.id(r);
    const applicationId = r.applicationId ?? null;
    const studentId = r.studentId || '';
    const resumeBase = (typeof Auth !== 'undefined' && Auth.role() === 'placement_officer')
      ? '/officer'
      : '/admin';
    let resumeUrl = '';
    if (r.hasResume !== false && (applicationId || studentId)) {
      resumeUrl = applicationId
        ? `${API_BASE}${resumeBase}/applications/${encodeURIComponent(applicationId)}/resume`
        : `${API_BASE}${resumeBase}/students/${encodeURIComponent(studentId)}/resume`;
    }
    return {
      id,
      applicationId,
      studentId,
      studentName: r.studentName || '',
      registerNumber: r.registerNumber || '',
      department: r.department || '',
      company: r.company || '—',
      role: r.role || '—',
      fileName: r.fileName || '',
      validFormat: r.validFormat !== false,
      status: r.status || 'pending',
      applicationStatus: r.applicationStatus || '',
      submittedAt: r.submittedAt || '',
      hasResume: r.hasResume !== false,
      resumeUrl,
    };
  },

  mapApplication(a) {
    const id = this.id(a);
    const resumeBase = (typeof Auth !== 'undefined' && Auth.role() === 'placement_officer')
      ? '/officer/applications/'
      : '/admin/applications/';
    const hasResume = !!(a.hasResume || a.resumePath || a.resumeFileName);
    return {
      id,
      driveId: a.driveId || '',
      studentName: a.studentName || '',
      registerNumber: a.registerNumber || '',
      department: a.department || '',
      company: a.company || '',
      role: a.role || '',
      stage: a.stage || a.status || '',
      status: a.status || 'pending',
      appliedAt: a.appliedAt || a.createdAt || '',
      resumeLabel: a.resumeLabel || '',
      resumeFileName: a.resumeFileName || '',
      hasResume,
      resumeUrl: hasResume ? `${API_BASE}${resumeBase}${encodeURIComponent(id)}/resume` : '',
    };
  },

  mapRule(r) {
    if (!r) return {};
    return {
      minCgpa: r.minCgpa ?? 7.5,
      maxBacklog: r.maxBacklogs ?? r.maxBacklog ?? 0,
      maxPlacementChances: r.placementChances ?? r.maxPlacementChances ?? 5,
      blockPlacedStudents: r.blockPlacedStudents !== false,
      allowPlacedForSelectedDrives: !!r.allowPlacedForSelectedDrives,
      placementPolicy: r.eligibilityCriteria || r.placementPolicy || '',
      policyVersion: r.policyVersion || 'v1.0',
      updatedAt: r.updatedAt || r.createdAt || '',
    };
  },

  mapDepartment(d) {
    return {
      id: this.id(d),
      name: d.name || '',
      code: d.code || '',
      placementOfficer: d.placementOfficer || null,
      hasOfficer: !!d.hasOfficer,
    };
  },

  async fetchUsers() {
    const res = await api('/admin/users');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(u => this.mapUser(u));
  },

  async fetchStudents(params = {}) {
    const qs = new URLSearchParams();
    if (params.q) qs.set('q', params.q);
    const q = qs.toString();
    const res = await api('/admin/students' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(s => this.mapStudentRow(s));
  },

  async fetchStudentProfile(studentId) {
    const res = await api(`/admin/students/${encodeURIComponent(studentId)}/profile`);
    return res.success && res.data ? res.data : null;
  },

  async fetchStudentPipeline(studentId) {
    const res = await api(`/admin/students/${encodeURIComponent(studentId)}/pipeline`);
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  selfPlacementBase(studentId) {
    return `/admin/students/${encodeURIComponent(studentId)}/self-placement`;
  },

  selfPlacementOfferLetterUrl(studentId) {
    return `${API_BASE}/admin/students/${encodeURIComponent(studentId)}/self-placement/offer-letter`;
  },

  async fetchSelfPlacement(studentId) {
    const res = await api(this.selfPlacementBase(studentId));
    return res.success ? res.data : null;
  },

  async approveSelfPlacement(studentId) {
    const res = await api(`${this.selfPlacementBase(studentId)}/approve`, { method: 'POST' });
    return res.success ? res.data : null;
  },

  async rejectSelfPlacement(studentId, reason = '') {
    const res = await api(`${this.selfPlacementBase(studentId)}/reject`, { method: 'POST', body: { reason } });
    return res.success ? res.data : null;
  },

  async fetchCompanies() {
    const res = await api('/admin/companies');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(c => this.mapCompany(c));
  },

  async fetchRecommendations() {
    const res = await api('/admin/recommendations');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapRecommendation(r));
  },

  async updateRecommendation(id, payload) {
    const res = await api(`/admin/recommendations/${encodeURIComponent(id)}`, { method: 'PUT', body: payload });
    return res.success;
  },

  async deleteRecommendation(id) {
    const res = await api(`/admin/recommendations/${encodeURIComponent(id)}`, { method: 'DELETE' });
    return res.success;
  },

  async fetchAlumniReferrals() {
    const res = await api('/admin/alumni-referrals');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapAlumniReferral(r));
  },

  async updateAlumniReferral(id, payload) {
    const res = await api(`/admin/alumni-referrals/${encodeURIComponent(id)}`, { method: 'PUT', body: payload });
    return res.success;
  },

  async deleteAlumniReferral(id) {
    const res = await api(`/admin/alumni-referrals/${encodeURIComponent(id)}`, { method: 'DELETE' });
    return res.success;
  },

  async fetchBlacklist() {
    const res = await api('/admin/blacklist');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapBlacklistRow(r));
  },

  async fetchApplications(params = {}) {
    const qs = new URLSearchParams();
    if (params.driveId) qs.set('driveId', params.driveId);
    if (params.status) qs.set('status', params.status);
    const q = qs.toString();
    const res = await api('/admin/applications' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(a => this.mapApplication(a));
  },

  mapResult(r) {
    return {
      id: this.id(r),
      driveId: r.driveId || '',
      studentName: r.studentName || '',
      registerNumber: r.registerNumber || '',
      company: r.company || '',
      role: r.role || '',
      package: r.package || '',
      status: r.status || '',
      joiningDate: r.joiningDate || '',
    };
  },

  async fetchResults(params = {}) {
    const qs = new URLSearchParams();
    if (params.driveId) qs.set('driveId', params.driveId);
    if (params.company) qs.set('company', params.company);
    if (params.role) qs.set('role', params.role);
    if (params.status) qs.set('status', params.status);
    const q = qs.toString();
    const res = await api('/admin/results' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapResult(r));
  },

  async fetchPendingResumes() {
    const res = await api('/admin/resumes/pending');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapResumeRow(r));
  },

  async fetchResumeQueue() {
    const res = await api('/admin/resumes');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapResumeRow(r));
  },

  async fetchDepartments() {
    const res = await api('/admin/departments');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(d => this.mapDepartment(d));
  },

  async fetchActiveRule() {
    const res = await api('/admin/rules/active');
    if (!res.success) return null;
    return this.mapRule(res.data);
  },

  mapDrive(d) {
    if (typeof OfficerApi !== 'undefined') return OfficerApi.mapDrive(d);
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    return {
      id: this.id(d),
      company: d.companyName || d.company || '',
      companyId: d.companyId || '',
      role: d.title || '',
      title: d.title || '',
      type: d.type || 'pooled',
      date: d.date || '',
      time: d.time || '',
      branches: Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || ''),
      tier: d.tier || 'Tier 2',
      status,
      statusCls: { Open: 'success', Ongoing: 'info', Completed: 'primary', Closed: 'muted' }[status] || 'muted',
      applied: d.applied ?? 0,
      profile: d.profile || 'General',
    };
  },

  async fetchDashboard() {
    const res = await api('/admin/dashboard');
    return res.success ? res.data : null;
  },

  async fetchDrives() {
    const res = await api('/admin/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(d => this.mapDrive(d));
  },

  async fetchTracking(limit = 100) {
    const res = await api(`/admin/tracking?limit=${encodeURIComponent(limit)}`);
    return res.success ? res.data : null;
  },

  async fetchRecruiting() {
    const res = await api('/admin/recruiting');
    return res.success ? res.data : null;
  },

  async fetchPlacementConsole() {
    const res = await api('/admin/placement-console');
    return res.success ? res.data : null;
  },

  async fetchExtendedAnalytics() {
    const res = await api('/admin/analytics/extended');
    return res.success ? res.data : null;
  },
};

const ReportCenter = {
  async list() {
    const res = await api('/admin/reports', { skipAuthRedirect: true });
    return res.success && Array.isArray(res.data) ? res.data : [];
  },

  async generate(type, opts = {}) {
    return api(`/admin/reports/${encodeURIComponent(type)}`, {
      method: 'POST',
      body: opts,
      skipAuthRedirect: true,
    });
  },

  downloadHref(downloadUrl) {
    if (!downloadUrl) return '#';
    if (downloadUrl.startsWith('http')) return downloadUrl;
    return downloadUrl.startsWith('/') ? downloadUrl : `/${downloadUrl}`;
  },

  formatLabel(fmt) {
    return (fmt || 'pdf').toUpperCase();
  },

  typeLabel(type) {
    const map = {
      student: 'Student Placement Status',
      department: 'Department Placement',
      company: 'Company Recruitment',
      monthly: 'Monthly Placement',
      annual: 'Annual Placement',
      selection: 'Selection Count',
    };
    return map[type] || type;
  },
};
