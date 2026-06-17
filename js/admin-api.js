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
      designation: u.designation || '',
      company: u.company || u.companyName || '',
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
    return {
      id: this.id(u) || this.id(row),
      studentId: this.id(row),
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

  mapRecommendation(r) {
    return {
      id: this.id(r),
      companyName: r.companyName || '',
      companyWebsite: r.companyWebsite || '',
      hrName: r.hrName || r.contact?.name || '',
      hrEmail: r.hrEmail || r.contact?.email || '',
      contactNumber: r.contactNumber || r.contact?.phone || '',
      staffName: r.staffName || '',
      staffEmail: r.staffEmail || '',
      submittedAt: r.submittedAt || r.createdAt || '',
      status: r.status || 'pending',
    };
  },

  mapCompany(c) {
    const contact = (c.contacts && c.contacts[0]) || {};
    return {
      id: this.id(c),
      companyName: c.companyName || '',
      companyWebsite: c.website || c.companyWebsite || '',
      hrName: contact.name || '',
      hrEmail: contact.email || '',
      contactNumber: contact.phone || '',
      category: c.category || '',
      tier: c.tier || '',
      registeredAt: c.createdAt || '',
      associationStatus: c.associationStatus || '',
      name: contact.name || c.companyName || '',
      email: contact.email || '',
      contactPerson: contact.name || '',
      phone: contact.phone || '',
      status: c.associationStatus === 'active' ? 'approved' : 'pending',
      blocked: false,
      role: 'company',
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

  mapApplication(a) {
    return {
      id: this.id(a),
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

  async fetchStudents() {
    const res = await api('/admin/students');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(s => this.mapStudentRow(s));
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

  async fetchBlacklist() {
    const res = await api('/admin/blacklist');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => this.mapBlacklistRow(r));
  },

  async fetchApplications() {
    const res = await api('/admin/applications');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(a => this.mapApplication(a));
  },

  async fetchResults() {
    const res = await api('/admin/results');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => ({
      id: this.id(r),
      studentName: r.studentName || '',
      registerNumber: r.registerNumber || '',
      company: r.company || '',
      role: r.role || '',
      package: r.package || '',
      status: r.status || '',
      joiningDate: r.joiningDate || '',
    }));
  },

  async fetchPendingResumes() {
    const res = await api('/admin/resumes/pending');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(r => ({
      id: this.id(r),
      studentId: r.studentId || this.id(r),
      studentName: r.studentName || '',
      registerNumber: r.registerNumber || '',
      department: r.department || '',
      fileName: r.fileName || '',
      validFormat: r.validFormat !== false,
      status: r.status || 'pending',
      submittedAt: r.submittedAt || '',
    }));
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
};
