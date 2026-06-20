/**
 * Placement Officer API helpers — department-scoped endpoints.
 */
const OfficerApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapStudentRow(row) {
    const u = row.user || {};
    const dept = row.department || {};
    const chances = row.placementChances || {};
    return {
      id: OfficerApi.id(u) || OfficerApi.id(row),
      studentId: OfficerApi.id(row),
      role: 'student',
      name: u.name || '',
      email: u.email || '',
      registerNumber: row.registerNumber || '',
      department: dept.code || dept.name || '',
      classBatch: row.classBatch || '',
      cgpa: row.academic?.cgpa ?? null,
      status: u.approved ? 'approved' : 'pending',
      blocked: u.status === 'blocked',
      placementStatus: row.placed ? 'placed' : 'registered',
      chancesUsed: chances.used ?? 0,
      chancesMax: (chances.used ?? 0) + (chances.remaining ?? 0),
      resumeStatus: row.resume?.verified ? 'approved' : (row.resume?.path ? 'pending' : 'pending'),
    };
  },

  mapDrive(d) {
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    const meta = typeof driveResultMeta === 'function' ? driveResultMeta(d) : { company: d.companyName || d.company || '', role: d.title || d.role || '' };
    return {
      id: OfficerApi.id(d),
      company: meta.company || d.companyName || d.company || '',
      companyId: d.companyId || '',
      role: meta.role || d.title || '',
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

  mapResumeRow(r) {
    const id = OfficerApi.id(r);
    const applicationId = r.applicationId ?? null;
    const studentId = r.studentId || '';
    let resumeUrl = '';
    if (r.hasResume !== false && (applicationId || studentId)) {
      resumeUrl = applicationId
        ? `${API_BASE}/officer/applications/${encodeURIComponent(applicationId)}/resume`
        : `${API_BASE}/officer/students/${encodeURIComponent(studentId)}/resume`;
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
    const id = OfficerApi.id(a);
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
      resumeUrl: hasResume ? `${API_BASE}/officer/applications/${encodeURIComponent(id)}/resume` : '',
    };
  },

  async fetchProfile() {
    const res = await api('/officer/profile');
    return res.success ? res.data : null;
  },

  async fetchDashboard() {
    const res = await api('/officer/dashboard');
    return res.success ? res.data : null;
  },

  async fetchStudents() {
    const res = await api('/officer/students');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(s => OfficerApi.mapStudentRow(s));
  },

  async fetchPendingStudents() {
    const res = await api('/officer/students/pending');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(u => AdminApi.mapUser(u));
  },

  async fetchApplications(params = {}) {
    const qs = new URLSearchParams();
    if (params.driveId) qs.set('driveId', params.driveId);
    if (params.status) qs.set('status', params.status);
    const q = qs.toString();
    const res = await api('/officer/applications' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(a => OfficerApi.mapApplication(a));
  },

  async fetchDrives() {
    const res = await api('/officer/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(d => OfficerApi.mapDrive(d));
  },

  async fetchPendingResumes() {
    const res = await api('/officer/resumes/pending');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => OfficerApi.mapResumeRow(r));
  },

  async fetchResumeQueue() {
    const res = await api('/officer/resumes');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => OfficerApi.mapResumeRow(r));
  },

  mapResult(r) {
    return {
      id: OfficerApi.id(r),
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
    const res = await api('/officer/results' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => OfficerApi.mapResult(r));
  },

  async fetchAnalytics() {
    const res = await api('/officer/analytics');
    return res.success ? res.data : null;
  },

  async fetchTracking(limit = 100) {
    const res = await api(`/officer/tracking?limit=${encodeURIComponent(limit)}`);
    return res.success ? res.data : null;
  },

  async fetchRecruiting() {
    const res = await api('/officer/recruiting');
    return res.success ? res.data : null;
  },

  async fetchPlacementConsole() {
    const res = await api('/analytics/placement-console');
    return res.success ? res.data : null;
  },

  async fetchExtendedAnalytics() {
    const res = await api('/analytics/extended');
    return res.success ? res.data : null;
  },
};
