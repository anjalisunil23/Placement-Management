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
      contactRole: row.contactRole || contact.role || '',
      staffName: row.staffName || '',
      staffEmail: row.staffEmail || '',
      submittedAt: row.submittedAt || row.createdAt || '',
      status: row.status || 'pending',
      category: row.category || '',
      reason: row.reason || '',
      adminComments: row.adminComments || '',
    };
  },

  mapStudentRow(row) {
    if (typeof OfficerApi !== 'undefined') return OfficerApi.mapStudentRow(row);
    const deptObj = row.department && typeof row.department === 'object' ? row.department : null;
    const deptCode = row.departmentCode
      || deptObj?.code
      || (typeof row.department === 'string' ? row.department : '');
    const deptName = row.departmentName || deptObj?.name
      || (typeof resolveCollegeProgrammeLabel === 'function' ? (resolveCollegeProgrammeLabel(deptCode) || '') : '')
      || deptCode;
    return {
      id: row.id || row._id || StaffApi.id(row),
      studentId: row.id || row._id || row.studentId || StaffApi.id(row),
      name: row.displayName || row.name || '',
      email: row.collegeEmail || row.email || '',
      collegeEmail: row.collegeEmail || row.email || '',
      personalEmail: row.personalEmail || '',
      phone: row.phone || row.personal?.phone || '',
      registerNumber: row.registerNumber || '',
      department: deptCode,
      departmentName: deptName,
      classBatch: row.classBatch || '',
      cgpa: row.cgpa ?? row.academic?.cgpa ?? null,
      marks10th: row.marks10th ?? row.academic?.marks10th ?? null,
      marks12th: row.marks12th ?? row.academic?.marks12th ?? row.ugMarks ?? row.academic?.ugMarks ?? null,
      ugMarks: row.ugMarks ?? row.academic?.ugMarks ?? row.marks12th ?? row.academic?.marks12th ?? null,
      backlogs: row.academic?.backlogs ?? 0,
      photoUrl: row.photoUrl || row.photo?.url || '',
      placementStatus: row.placementStatus || 'seeking',
      photo: row.photo || null,
      status: row.status || row.user?.status || 'active',
      blacklisted: !!row.blacklisted,
      blocked: !!row.blocked,
      aesOnly: !!row.aesOnly,
      isNew: !!(row.isNew || row.aesOnly),
      policyAccepted: !!row.policyAccepted,
      policyAcceptedAt: row.policyAcceptedAt || '',
      policyVersion: row.policyVersion || '',
    };
  },

  mapDrive(d) {
    if (typeof OfficerApi !== 'undefined') return OfficerApi.mapDrive(d);
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    const elig = (d.eligibility && typeof d.eligibility === 'object') ? d.eligibility : {};
    const pkg = String(elig.package || d.package || '').trim();
    const deadline = String(elig.deadline || d.deadline || '').trim();
    const jobType = String(elig.jobType || d.jobType || '').trim();
    const recruitmentDate = String(d.recruitmentDate || d.date || '').trim();
    const mode = String(d.mode || elig.mode || '').trim();
    const minCgpa = elig.minCgpa ?? d.minCgpa ?? '';
    const maxBacklogs = elig.maxBacklogs ?? d.maxBacklogs ?? '';
    const title = String(d.title || '').trim();
    let role = String(d.role || '').trim();
    if (!role && title) {
      if (title.includes('—')) role = title.split('—').pop().trim();
      else if (title.includes(' - ')) role = title.split(' - ').pop().trim();
      else role = title;
    }
    return {
      id: StaffApi.id(d),
      company: d.companyName || d.company || '',
      role,
      type: d.type || 'pooled',
      jobType: jobType || '—',
      date: recruitmentDate,
      recruitmentDate: recruitmentDate || '—',
      package: pkg || '—',
      deadline: (deadline && deadline !== 'TBD') ? deadline : '—',
      mode: mode || '—',
      minCgpa,
      maxBacklogs,
      branches: Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || ''),
      tier: d.tier || 'Tier 2',
      eligibility: { ...elig, package: pkg, deadline, jobType, mode, minCgpa, maxBacklogs },
      status,
      statusCls: { Open: 'success', Ongoing: 'info', Completed: 'primary', Closed: 'muted' }[status] || 'muted',
      _fromApi: true,
    };
  },

  mapDashboard(dash) {
    if (!dash) return null;
    const branchStats = (dash.branchStatistics || []).map(b => ({
      dept: b.code || b.department || '',
      students: b.total ?? 0,
      placed: b.placed ?? 0,
      pct: b.percentage ?? 0,
    }));
    const rec = dash.recommendations || {};
    return {
      recommendations: {
        total: rec.total ?? 0,
        pending: rec.pending ?? 0,
        registered: rec.registered ?? 0,
        recent: Array.isArray(rec.recent) ? rec.recent : [],
      },
      department: dash.department || {},
      hiring: dash.hiring || {},
      branchStats,
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

  async fetchDashboardStats() {
    const res = await api('/staff/dashboard-stats');
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
          role: payload.contactRole || '',
        },
      },
    });
  },

  async fetchStudents(params = {}) {
    const qs = new URLSearchParams();
    if (params.q) qs.set('q', params.q);
    const q = qs.toString();
    StaffApi._lastFetchError = '';
    const res = await api('/staff/students' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) {
      StaffApi._lastFetchError = res.message || 'Request failed';
      return null;
    }
    try {
      return res.data.map(s => StaffApi.mapStudentRow(s));
    } catch (e) {
      StaffApi._lastFetchError = e?.message || 'Could not parse student data';
      return null;
    }
  },

  async fetchFinalYearStudents(params = {}) {
    const qs = new URLSearchParams();
    if (params.q) qs.set('q', params.q);
    const q = qs.toString();
    StaffApi._lastFetchError = '';
    const res = await api('/staff/students/final-year' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) {
      StaffApi._lastFetchError = res.message || 'Request failed';
      return null;
    }
    try {
      return res.data.map(s => StaffApi.mapStudentRow(s));
    } catch (e) {
      StaffApi._lastFetchError = e?.message || 'Could not parse student data';
      return null;
    }
  },

  async fetchStudentPipeline(studentId) {
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/pipeline`);
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  async fetchStudentProfile(studentId, registerNumber = '') {
    const qs = registerNumber ? `?registerNumber=${encodeURIComponent(registerNumber)}` : '';
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/profile${qs}`);
    return res.success && res.data ? res.data : null;
  },

  async fetchDrives() {
    const res = await api('/staff/drives');
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(d => StaffApi.mapDrive(d));
  },

  async fetchHiringOverview(params = {}) {
    const qs = new URLSearchParams();
    if (params.batch) qs.set('batch', params.batch);
    if (params.branch) qs.set('branch', params.branch);
    const q = qs.toString();
    const res = await api('/staff/hiring-overview' + (q ? `?${q}` : ''));
    return res.success ? res.data : null;
  },

  async fetchPlacementFilters(params = {}) {
    const qs = new URLSearchParams();
    if (params.departmentId) qs.set('departmentId', params.departmentId);
    if (params.program) qs.set('program', params.program);
    if (params.branch) qs.set('branch', params.branch);
    const q = qs.toString();
    const res = await api('/staff/placement-filters' + (q ? `?${q}` : ''));
    return res.success && res.data ? res.data : null;
  },

  async fetchPlacementsHigherEducation(params = {}) {
    const qs = new URLSearchParams();
    ['departmentId', 'program', 'branch', 'batch', 'type', 'q'].forEach(k => {
      if (params[k]) qs.set(k, params[k]);
    });
    const q = qs.toString();
    const cacheKey = 'ph_staff_placements_' + q;
    try {
      const cached = sessionStorage.getItem(cacheKey);
      if (cached) {
        const parsed = JSON.parse(cached);
        if (parsed && parsed._at && (Date.now() - parsed._at) < 90000 && parsed.data) {
          return parsed.data;
        }
      }
    } catch (_) { /* ignore */ }

    const res = await api('/staff/placements-higher-education' + (q ? `?${q}` : ''));
    if (res.success && res.data) {
      try {
        sessionStorage.setItem(cacheKey, JSON.stringify({ _at: Date.now(), data: res.data }));
      } catch (_) { /* ignore quota */ }
      return res.data;
    }
    return null;
  },

  clearPlacementsCache() {
    try {
      const keys = [];
      for (let i = 0; i < sessionStorage.length; i++) {
        const k = sessionStorage.key(i);
        if (k && k.startsWith('ph_staff_placements_')) keys.push(k);
      }
      keys.forEach(k => sessionStorage.removeItem(k));
    } catch (_) { /* ignore */ }
  },

  async updateStudentPlacement(studentId, body) {
    if (typeof StaffApi.clearPlacementsCache === 'function') StaffApi.clearPlacementsCache();
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/placement`, {
      method: 'PUT',
      body,
    });
    return res;
  },

  async updateStudentProfile(studentId, body) {
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/profile`, {
      method: 'PUT',
      body,
    });
    return res;
  },

  async uploadStudentPlacementDocuments(studentId, formData) {
    const res = await api(`/staff/students/${encodeURIComponent(studentId)}/placement/documents`, {
      method: 'POST',
      body: formData,
    });
    return res;
  },
};
