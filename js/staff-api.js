/**
 * Staff API helpers — department-scoped faculty endpoints.
 */
const StaffApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapRecommendation(row) {
    if (typeof AdminApi !== 'undefined') return AdminApi.mapRecommendation(row);
    const contact = row.contact || {};
    return {
      id: StaffApi.id(row),
      companyName: row.companyName || '',
      companyWebsite: row.companyWebsite || '',
      hrName: row.hrName || contact.name || '',
      hrEmail: row.hrEmail || contact.email || '',
      contactNumber: row.contactNumber || contact.phone || '',
      staffName: row.staffName || '',
      staffEmail: row.staffEmail || '',
      submittedAt: row.submittedAt || row.createdAt || '',
      status: row.status || 'pending',
      category: row.category || '',
    };
  },

  mapStudentRow(row) {
    return {
      id: row.id || StaffApi.id(row),
      studentId: row.id || StaffApi.id(row),
      name: row.name || '',
      email: row.email || '',
      registerNumber: row.registerNumber || '',
      department: row.department || '',
      classBatch: row.classBatch || '',
      cgpa: row.cgpa ?? null,
      placementStatus: row.placementStatus || 'seeking',
      status: row.status || 'active',
      blacklisted: !!row.blacklisted,
      blocked: !!row.blocked,
    };
  },

  mapDrive(d) {
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    return {
      id: StaffApi.id(d),
      company: d.companyName || d.company || '',
      role: d.title || d.role || '',
      type: d.type || 'pooled',
      date: d.date || '',
      branches: Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || ''),
      tier: d.tier || 'Tier 2',
      status,
      statusCls: { Open: 'success', Ongoing: 'info', Completed: 'primary', Closed: 'muted' }[status] || 'muted',
    };
  },

  async fetchProfile() {
    const res = await api('/staff/profile');
    return res.success ? res.data : null;
  },

  async fetchDashboard() {
    const res = await api('/staff/dashboard');
    return res.success ? res.data : null;
  },

  async fetchRecommendations() {
    const res = await api('/staff/recommendations');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => StaffApi.mapRecommendation(r));
  },

  async createRecommendation(payload) {
    return api('/staff/recommendations', {
      method: 'POST',
      body: {
        companyName: payload.companyName,
        companyWebsite: payload.companyWebsite || '',
        category: payload.category || 'Software',
        reason: payload.reason || payload.hrName || 'Staff recommendation',
        contact: {
          name: payload.hrName,
          email: payload.hrEmail,
          phone: payload.contactNumber,
        },
      },
    });
  },

  async fetchStudents() {
    const res = await api('/staff/students');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(s => StaffApi.mapStudentRow(s));
  },

  async fetchStudentPipeline(studentId) {
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/pipeline`);
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  async fetchDrives() {
    const res = await api('/staff/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(d => StaffApi.mapDrive(d));
  },

  async fetchHiringOverview() {
    const res = await api('/staff/hiring-overview');
    return res.success ? res.data : null;
  },
};
