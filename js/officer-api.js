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
    return {
      id: OfficerApi.id(d),
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

  mapApplication(a) {
    return {
      id: OfficerApi.id(a),
      studentName: a.studentName || '',
      registerNumber: a.registerNumber || '',
      department: a.department || '',
      company: a.company || '',
      role: a.role || '',
      stage: a.stage || a.status || '',
      status: a.status || 'pending',
      appliedAt: a.appliedAt || a.createdAt || '',
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

  async fetchApplications() {
    const res = await api('/officer/applications');
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
    return res.data;
  },

  async fetchResults() {
    const res = await api('/officer/results');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => ({
      id: OfficerApi.id(r),
      studentName: r.studentName || '',
      registerNumber: r.registerNumber || '',
      company: r.company || '',
      role: r.role || '',
      package: r.package || '',
      status: r.status || '',
      joiningDate: r.joiningDate || '',
    }));
  },

  async fetchAnalytics() {
    const res = await api('/officer/analytics');
    return res.success ? res.data : null;
  },
};
