/**
 * Staff API helpers — department-scoped faculty endpoints.
 */
const StaffApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapRecommendation(r) {
    if (typeof AdminApi !== 'undefined') return AdminApi.mapRecommendation(r);
    const contact = r.contact || {};
    return {
      id: StaffApi.id(r),
      companyName: r.companyName || '',
      companyWebsite: r.companyWebsite || '',
      hrName: r.hrName || contact.name || '',
      hrEmail: r.hrEmail || contact.email || '',
      contactNumber: r.contactNumber || contact.phone || '',
      staffName: r.staffName || '',
      staffEmail: r.staffEmail || '',
      submittedAt: r.submittedAt || r.createdAt || '',
      status: r.status || 'pending',
    };
  },

  async fetchRecommendations() {
    const res = await api('/staff/recommendations');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => StaffApi.mapRecommendation(r));
  },

  async fetchDashboard() {
    const res = await api('/staff/dashboard');
    return res.success ? res.data : null;
  },

  async fetchStudents() {
    const res = await api('/staff/students');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  async fetchStudentPipeline(studentId) {
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/pipeline`);
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  async fetchHiringOverview() {
    const res = await api('/staff/hiring-overview');
    return res.success ? res.data : null;
  },
};
