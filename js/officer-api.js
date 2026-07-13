/**
 * Placement Officer API helpers — department-scoped endpoints.
 */
const OfficerApi = {
  id(doc) { return doc?.id || doc?._id || ''; },

  mapStudentRow(row) {
    const u = row.user || {};
    const dept = row.department || {};
    const chances = row.placementChances || {};
    const selfPlacement = row.selfPlacement && typeof row.selfPlacement === 'object' ? row.selfPlacement : null;
    const isPlaced = !!row.placed;
    const photoUrl = row.photoUrl || u.photoUrl || row.photo?.url || u.photo?.url || '';
    let placementStatus = 'registered';
    if (isPlaced) placementStatus = 'placed';
    else if (selfPlacement?.status === 'pending') placementStatus = 'pending_placement';
    const academic = row.academic || {};
    const personal = row.personal || {};
    const email = u.email || '';
    const isCollege = /@(students\.)?amaljyothi\.ac\.in$/i.test(email) || /\.ajce\.in$/i.test(email);
    return {
      id: OfficerApi.id(u) || OfficerApi.id(row),
      studentId: OfficerApi.id(row),
      role: 'student',
      name: row.displayName || u.name || row.name || personal.name || personal.fullName || '',
      email: row.collegeEmail || (isCollege ? email : (row.personalEmail || personal.personalEmail || email)),
      collegeEmail: row.collegeEmail || (isCollege ? email : (personal.collegeEmail || '')),
      personalEmail: row.personalEmail || personal.personalEmail || personal.email || (!isCollege ? email : ''),
      phone: row.phone || personal.phone || '',
      registerNumber: row.registerNumber || '',
      department: row.departmentCode || dept.code || dept.name || '',
      departmentName: row.departmentName || dept.name || dept.code || '',
      classBatch: row.classBatch || '',
      cgpa: academic.cgpa ?? row.academic?.cgpa ?? null,
      marks10th: academic.marks10th ?? row.academic?.marks10th ?? null,
      marks12th: academic.marks12th ?? row.academic?.marks12th ?? row.academic?.ugMarks ?? null,
      ugMarks: academic.ugMarks ?? row.academic?.ugMarks ?? row.academic?.marks12th ?? null,
      backlogs: academic.backlogs ?? row.academic?.backlogs ?? 0,
      photoUrl: row.photo?.url || row.photoUrl || '',
      status: u.approved ? 'approved' : 'pending',
      blocked: u.status === 'blocked',
      placed: isPlaced,
      selfPlacement,
      placementStatus,
      photo: row.photo || u.photo || null,
      chancesUsed: chances.used ?? 0,
      chancesMax: (chances.used ?? 0) + (chances.remaining ?? 0),
      resumeStatus: row.resume?.verified ? 'approved' : (row.resume?.path ? 'pending' : 'none'),
      policyAccepted: !!(row.policyAccepted),
      policyAcceptedAt: row.policyAcceptedAt || '',
      policyVersion: row.policyVersion || '',
    };
  },

  mapDrive(d) {
    const statusMap = { scheduled: 'Open', ongoing: 'Ongoing', completed: 'Completed', closed: 'Closed' };
    const status = statusMap[(d.status || '').toLowerCase()] || d.status || 'Open';
    const meta = typeof driveResultMeta === 'function' ? driveResultMeta(d) : { company: d.companyName || d.company || '', role: d.title || d.role || '' };
    const elig = (d.eligibility && typeof d.eligibility === 'object') ? d.eligibility : {};
    const pkg = String(elig.package || d.package || '').trim();
    const deadline = String(elig.deadline || d.deadline || '').trim();
    const jobType = String(elig.jobType || d.jobType || '').trim();
    return {
      id: OfficerApi.id(d),
      company: meta.company || d.companyName || d.company || '',
      companyId: d.companyId || '',
      role: meta.role || (() => {
      const title = String(d.title || '').trim();
      if (title.includes('—')) return title.split('—').pop().trim();
      if (title.includes(' - ')) return title.split(' - ').pop().trim();
      return '';
    })() || d.role || '',
      title: d.title || '',
      type: d.type || 'pooled',
      jobType: jobType || '—',
      date: d.date || '',
      time: d.time || '10:00',
      package: pkg || '—',
      deadline: (deadline && deadline !== 'TBD') ? deadline : '—',
      description: String(elig.description || d.description || '').trim(),
      branches: typeof formatDriveBranches === 'function'
        ? formatDriveBranches(d.branches ?? elig.branches ?? '')
        : (Array.isArray(d.branches) ? d.branches.join(', ') : (d.branches || '')),
      tier: d.tier || 'Tier 2',
      eligibility: { ...elig, package: pkg, deadline, jobType },
      status,
      statusCls: { Open: 'success', Ongoing: 'info', Completed: 'primary', Closed: 'muted' }[status] || 'muted',
      applied: d.applied ?? 0,
      profile: d.profile || 'General',
      hasShortlistDocument: !!(d.hasShortlistDocument || d.shortlistDocumentName),
      shortlistDocumentName: d.shortlistDocumentName || '',
      shortlistUploadedAt: d.shortlistUploadedAt || '',
      shortlistDocumentUrl: d.shortlistDocumentUrl || '',
      _fromApi: true,
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

  async fetchStudents(params = {}) {
    const qs = new URLSearchParams();
    if (params.q) qs.set('q', params.q);
    const q = qs.toString();
    const res = await api('/officer/students' + (q ? `?${q}` : ''));
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data.map(s => OfficerApi.mapStudentRow(s));
  },

  async fetchStudentProfile(studentId, registerNumber = '') {
    const qs = registerNumber ? `?registerNumber=${encodeURIComponent(registerNumber)}` : '';
    const res = await api(`/officer/students/${encodeURIComponent(studentId)}/profile${qs}`);
    return res.success && res.data ? res.data : null;
  },

  async fetchStudentPipeline(studentId) {
    const res = await api(`/officer/students/${encodeURIComponent(studentId)}/pipeline`);
    if (!res.success || !Array.isArray(res.data)) return null;
    return res.data;
  },

  selfPlacementBase(studentId) {
    return `/officer/students/${encodeURIComponent(studentId)}/self-placement`;
  },

  selfPlacementOfferLetterUrl(studentId) {
    return `${API_BASE}/officer/students/${encodeURIComponent(studentId)}/self-placement/offer-letter`;
  },

  selfPlacementCompanyIdUrl(studentId) {
    return `${API_BASE}/officer/students/${encodeURIComponent(studentId)}/self-placement/company-id`;
  },

  selfPlacementSalarySlipUrl(studentId) {
    return `${API_BASE}/officer/students/${encodeURIComponent(studentId)}/self-placement/salary-slip`;
  },

  async fetchSelfPlacement(studentId) {
    const res = await api(OfficerApi.selfPlacementBase(studentId));
    return res.success ? res.data : null;
  },

  async createSelfPlacement(studentId, formData) {
    const res = await api(OfficerApi.selfPlacementBase(studentId), { method: 'POST', body: formData });
    if (!res.success) {
      toast(res.message || 'Could not record self-placement.', 'error');
      return null;
    }
    return res.data;
  },

  async approveSelfPlacement(studentId) {
    const res = await api(`${OfficerApi.selfPlacementBase(studentId)}/approve`, { method: 'POST' });
    return res.success ? res.data : null;
  },

  async rejectSelfPlacement(studentId, reason = '') {
    const res = await api(`${OfficerApi.selfPlacementBase(studentId)}/reject`, { method: 'POST', body: { reason } });
    return res.success ? res.data : null;
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

  async fetchNonApplicants(driveId, params = {}) {
    const id = encodeURIComponent(String(driveId || '').trim());
    if (!id) return null;
    const qs = new URLSearchParams();
    if (params.limit) qs.set('limit', String(params.limit));
    const q = qs.toString();
    const res = await api(`/officer/drives/${id}/non-applicants` + (q ? `?${q}` : ''));
    if (!res.success || !res.data || typeof res.data !== 'object') return null;
    return res.data;
  },

  shortlistUploadPath(driveId) {
    const id = encodeURIComponent(String(driveId || '').trim());
    const role = typeof Auth !== 'undefined' ? Auth.role() : '';
    return role === 'admin'
      ? `/admin/drives/${id}/shortlist-upload`
      : `/officer/drives/${id}/shortlist-upload`;
  },

  shortlistDocumentPath(driveId) {
    const id = encodeURIComponent(String(driveId || '').trim());
    const role = typeof Auth !== 'undefined' ? Auth.role() : '';
    return role === 'admin'
      ? `/admin/drives/${id}/shortlist-document`
      : `/officer/drives/${id}/shortlist-document`;
  },

  /**
   * Upload company shortlist document and/or import register numbers.
   * @param {string} driveId
   * @param {{ document?: File|null, csv?: File|null, csvText?: string, registerNumbers?: string }} payload
   */
  async uploadDriveShortlist(driveId, payload = {}) {
    const path = OfficerApi.shortlistUploadPath(driveId);
    if (!path.includes('/drives/') || path.endsWith('/drives/')) return null;
    const fd = new FormData();
    if (payload.document instanceof File) fd.append('document', payload.document, payload.document.name);
    if (payload.csv instanceof File) fd.append('csv', payload.csv, payload.csv.name);
    if (payload.csvText) fd.append('csvText', String(payload.csvText));
    if (payload.registerNumbers) fd.append('registerNumbers', String(payload.registerNumbers));
    const res = await api(path, { method: 'POST', body: fd });
    if (!res.success) {
      toast(res.message || 'Could not upload shortlist.', 'error');
      return null;
    }
    return { ...(res.data || {}), message: res.message || '' };
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
    const res = await api('/officer/placement-console');
    return res.success ? res.data : null;
  },

  async fetchExtendedAnalytics() {
    const res = await api('/analytics/extended');
    return res.success ? res.data : null;
  },
};
