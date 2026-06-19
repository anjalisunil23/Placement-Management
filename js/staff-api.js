/**
 * Staff API helpers — department-scoped read access and recommendations.
 */
const StaffApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapRecommendation(row) {
    return {
      id: StaffApi.id(row),
      companyName: row.companyName || '',
      companyWebsite: row.companyWebsite || '',
      hrName: row.hrName || row.contact?.name || '',
      hrEmail: row.hrEmail || row.contact?.email || '',
      contactNumber: row.contactNumber || row.contact?.phone || '',
      staffName: row.staffName || '',
      staffEmail: row.staffEmail || '',
      submittedAt: row.submittedAt || row.createdAt || '',
      status: row.status || 'pending',
      category: row.category || '',
    };
  },

  mapStudentRow(row) {
    const u = row.user || {};
    const dept = row.department || {};
    return {
      id: StaffApi.id(u) || StaffApi.id(row),
      studentId: StaffApi.id(row),
      name: u.name || '',
      email: u.email || '',
      registerNumber: row.registerNumber || '',
      department: dept.code || dept.name || '',
      cgpa: row.academic?.cgpa ?? null,
      placementStatus: row.placed ? 'placed' : 'registered',
    };
  },

  mapDrive(d) {
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    return {
      id: StaffApi.id(d),
      company: d.companyName || d.company || '',
      role: d.title || '',
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
