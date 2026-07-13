/**
 * Shared Campus Hiring Overview UI (hiring-overview page + PO dashboard).
 * Call HiringOverviewPage.init({ root?: HTMLElement|string }) after Auth/shell ready.
 */
(function (global) {
  'use strict';

  const IDS = [
    'viewFilterCard', 'deptSelect', 'branchSelect', 'viewHint', 'liveDataBadge',
    'pageTitleText', 'pageSub', 'companiesCard', 'companiesSection', 'activeCount',
    'companyRows', 'candidateRows', 'pipelineRows', 'statusFilter', 'trendChart',
    'statCompanies', 'statApplicants', 'statShortlisted', 'statOffers', 'statHired',
    'trendYearSelect',
  ];

  function HiringOverviewPage() {
    this.root = document;
    this.apiHiringData = null;
    this.campusRecruitingData = null;
    this.hiringTrendData = null;
    this.trendChart = null;
    this.staffLive = false;
    this.adminLive = false;
    this.officerLive = false;
    this.campusLive = false;
    this.viewDeptGroups = [];
    this.viewExtraProgrammes = [];
    this.activeParentKey = '';
    this.activeDeptFilter = '';
    this._els = {};
    this._bound = false;
  }

  HiringOverviewPage.prototype.$ = function (id) {
    if (this._els[id]) return this._els[id];
    const el = this.root === document
      ? document.getElementById(id)
      : (this.root.querySelector('#' + id) || (this.root.id === id ? this.root : null));
    this._els[id] = el;
    return el;
  };

  HiringOverviewPage.prototype.currentRole = function () { return Auth.role(); };

  HiringOverviewPage.prototype.escHtml = function (s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  };

  HiringOverviewPage.prototype.formatRoles = function (roles) {
    const list = (roles || []).map(r => String(r || '').trim()).filter(r => r && r !== '?');
    return list.length ? list.join(', ') : '-';
  };

  HiringOverviewPage.prototype.refreshLiveFlags = function () {
    const live = Auth.hasRealAuth() && !Auth.isDemo();
    const role = Auth.role();
    this.staffLive = live && role === 'staff';
    this.adminLive = live && role === 'admin';
    this.officerLive = live && role === 'placement_officer';
    this.campusLive = this.adminLive || this.officerLive;
  };

  HiringOverviewPage.prototype.updateLiveBadge = function () {
    const el = this.$('liveDataBadge');
    if (!el) return;
    el.classList.toggle('d-none', !(this.staffLive || this.campusLive));
  };

  HiringOverviewPage.prototype.liveHiringView = function (dept) {
    if (this.staffLive) return this.apiHiringData;
    if (this.campusLive && this.campusRecruitingData) return this.recruitingViewForDept(this.campusRecruitingData, dept || '');
    return null;
  };

  const EMPTY_PIPELINE = [
    { label: 'Applicants', value: 0 },
    { label: 'Shortlisted', value: 0 },
    { label: 'Offers', value: 0 },
    { label: 'Hired', value: 0 },
  ];

  HiringOverviewPage.prototype.pipelineFromView = function (view) {
    if (!view) return EMPTY_PIPELINE.slice();
    if (Array.isArray(view.pipeline) && view.pipeline.some(p => Number(p.value ?? 0) > 0)) {
      return view.pipeline.map(p => ({ label: p.label || 'Stage', value: Number(p.value ?? 0) }));
    }
    return this.staffPipelineFromData(view);
  };

  HiringOverviewPage.prototype.candidateMatchesFilter = function (status, filter) {
    if (!filter) return true;
    const s = String(status || '').toLowerCase();
    if (filter === 'applied') return s === 'applied' || s === 'under_review';
    if (filter === 'shortlisted') return s === 'shortlisted';
    if (filter === 'selected') return s === 'selected' || s === 'offered';
    if (filter === 'placed') return s === 'placed';
    return s === filter;
  };

  HiringOverviewPage.prototype.staffPipelineFromData = function (data) {
    if (!data) return [];
    const totals = data.totals || {};
    const pipeline = Array.isArray(data.pipeline) ? data.pipeline : [];
    if (pipeline.length && pipeline.some(p => Number(p.value ?? 0) > 0)) {
      return pipeline.map(p => ({ label: p.label || 'Stage', value: Number(p.value ?? 0) }));
    }
    return [
      { label: 'Applicants', value: Number(totals.applicants ?? 0) },
      { label: 'Shortlisted', value: Number(totals.shortlisted ?? 0) },
      { label: 'Offers', value: Number(totals.offers ?? 0) },
      { label: 'Hired', value: Number(totals.hired ?? 0) },
    ];
  };

  HiringOverviewPage.prototype.emptyHiringTrend = function () {
    const labels = [];
    const data = [];
    const now = new Date();
    for (let i = 11; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      labels.push(d.toLocaleString('en-US', { month: 'short' }));
      data.push(0);
    }
    return { labels, series: [{ label: 'Offers', data }] };
  };

  HiringOverviewPage.prototype.renderHiringTrend = function (trend) {
    const canvas = this.$('trendChart');
    if (!canvas || typeof Charts === 'undefined') return;
    const t = (trend?.labels?.length) ? trend : this.emptyHiringTrend();
    if (this.trendChart) {
      this.trendChart.destroy();
      this.trendChart = null;
    }
    this.trendChart = Charts.line(
      canvas,
      t.labels,
      t.series?.length ? t.series : [{ label: 'Offers', data: t.values || [] }]
    );
  };

  HiringOverviewPage.prototype.renderPipelineBreakdown = function (pipeline) {
    const rows = pipeline.length ? pipeline : EMPTY_PIPELINE.slice();
    const maxPipeline = Math.max(...rows.map(p => p.value), 1);
    const el = this.$('pipelineRows');
    if (!el) return;
    el.innerHTML = rows.map(p => {
      const share = ((p.value / maxPipeline) * 100).toFixed(1);
      return `<tr><td><strong>${this.escHtml(p.label)}</strong></td><td>${p.value.toLocaleString('en-IN')}</td><td>${share}%</td></tr>`;
    }).join('');
  };

  HiringOverviewPage.prototype.recruitingViewForDept = function (data, deptCode) {
    if (!data) return null;
    let applicants = (data.applicants || []).map(RecruitingStore.mapApplicant);
    if (deptCode) {
      applicants = applicants.filter(a => this.deptMatchesFilter(a.dept, deptCode));
    }

    const shortlisted = applicants.filter(a => a.status === 'shortlisted' || a.status === 'under_review').length;
    const offers = applicants.filter(a => a.status === 'offered' || a.status === 'selected').length;
    const hired = applicants.filter(a => a.status === 'placed').length;

    const companyMap = new Map();
    (data.activeCompanies || []).forEach(c => {
      companyMap.set(c.company, {
        company: c.company,
        roles: c.openRoles ? [`${c.openRoles} open role${c.openRoles === 1 ? '' : 's'}`] : [],
        applicants: 0,
        shortlisted: 0,
        selected: 0,
        status: c.status || 'Active',
        statusCls: { scheduled: 'info', open: 'success', ongoing: 'info', reviewing: 'warning' }[String(c.status || '').toLowerCase()] || 'success',
      });
    });
    applicants.forEach(a => {
      const key = a.company || 'Unknown';
      if (!companyMap.has(key)) {
        companyMap.set(key, { company: key, roles: [], applicants: 0, shortlisted: 0, selected: 0, status: 'Active', statusCls: 'info' });
      }
      const row = companyMap.get(key);
      row.applicants++;
      if (a.status === 'shortlisted' || a.status === 'under_review') row.shortlisted++;
      if (a.status === 'offered' || a.status === 'selected') row.selected++;
    });

    const companies = [...companyMap.values()].filter(c => !deptCode || c.applicants > 0);
    return {
      totals: {
        companiesHiring: companies.length,
        applicants: applicants.length,
        shortlisted,
        offers,
        hired: hired || offers,
      },
      pipeline: [
        { label: 'Applicants', value: applicants.length },
        { label: 'Shortlisted', value: shortlisted },
        { label: 'Offers', value: offers },
        { label: 'Hired', value: hired || offers },
      ],
      companies,
      candidates: applicants.map(a => ({
        name: a.name,
        roll: a.roll,
        dept: a.dept,
        company: a.company || '-',
        role: a.role,
        status: a.status,
      })),
    };
  };

  HiringOverviewPage.prototype.ownDepartmentMeta = function () {
    const u = Auth.user() || {};
    const hints = [];
    const push = (v) => {
      const s = String(v || '').trim();
      if (s && !hints.includes(s)) hints.push(s);
    };
    if (u.department && typeof u.department === 'object') {
      push(u.department.name);
      push(u.department.code);
      push(u.department.aesId);
      push(u.department.id);
    } else {
      push(u.department);
    }
    push(u.departmentName);
    push(u.departmentCode);
    push(u.branch);
    push(u.programme);
    push(u.departmentId);
    push(u.departmentAesId);
    if (typeof viewerDepartment === 'function') push(viewerDepartment());

    let record = null;
    for (const h of hints) {
      record = typeof resolveDepartmentRecord === 'function' ? resolveDepartmentRecord(h) : null;
      if (record) break;
    }

    const codeFromRecord = record && String(record.code || '').trim();
    const nameFromRecord = record && String(record.name || '').trim();
    const nonNumeric = hints.find(h => h && !/^\d+$/.test(h)) || '';
    const code = (codeFromRecord && !/^\d+$/.test(codeFromRecord)) ? codeFromRecord : nonNumeric;
    const name = nameFromRecord || String(u.departmentName || '').trim() || nonNumeric || code;

    return { code, name, record, hints };
  };

  HiringOverviewPage.prototype.ownDepartmentCode = function () {
    const meta = this.ownDepartmentMeta();
    return meta.code || meta.name || '';
  };

  HiringOverviewPage.prototype.findGroupForFilter = function (filter) {
    const raw = String(filter || '').trim();
    if (!raw) return null;
    if (raw.includes('|')) {
      const key = splitDepartmentFilterValue(raw).map(normalizeProgrammeCode).sort().join('|');
      return this.viewDeptGroups.find(g =>
        g.programmes.map(p => normalizeProgrammeCode(p.code)).sort().join('|') === key
      ) || null;
    }

    // Resolve numeric AES ids / store ids to a readable code or name first.
    let needle = raw;
    if (/^\d+$/.test(raw) && typeof resolveDepartmentRecord === 'function') {
      const rec = resolveDepartmentRecord(raw);
      if (rec) {
        const code = String(rec.code || '').trim();
        const name = String(rec.name || '').trim();
        if (code && !/^\d+$/.test(code)) needle = code;
        else if (name) needle = name;
      }
    }

    const code = normalizeProgrammeCode(
      typeof resolveCollegeProgrammeCode === 'function' ? (resolveCollegeProgrammeCode(needle) || needle) : needle
    );
    const name = String(needle || '').trim().toLowerCase();
    const nameCompact = name.replace(/[^a-z0-9]+/g, '');

    const byProgramme = this.viewDeptGroups.find(g =>
      g.programmes.some(p => normalizeProgrammeCode(p.code) === code)
      || (typeof programmeAliasSet === 'function' && g.programmes.some(p => programmeAliasSet(p).has(code)))
    );
    if (byProgramme) return byProgramme;

    const byParentExact = this.viewDeptGroups.find(g => g.parent.toLowerCase() === name);
    if (byParentExact) return byParentExact;

    const byParentFuzzy = this.viewDeptGroups.find(g => {
      const parent = g.parent.toLowerCase();
      const parentCompact = parent.replace(/[^a-z0-9]+/g, '');
      if (!nameCompact) return false;
      return parent.includes(name)
        || name.includes(parent)
        || parentCompact.includes(nameCompact)
        || nameCompact.includes(parentCompact);
    });
    if (byParentFuzzy) return byParentFuzzy;

    // Common AES short labels → catalogue parents.
    const keywordMap = [
      { re: /\b(cse|cs|computer\s*science)\b/i, parent: 'Computer Science & Engineering' },
      { re: /\b(ece|ec|electronics)\b/i, parent: 'Electronics & Communication' },
      { re: /\b(eee|ee|electrical)\b/i, parent: 'Electrical & Electronics' },
      { re: /\b(me|mech|mechanical)\b/i, parent: 'Mechanical Engineering' },
      { re: /\b(ce|civil)\b/i, parent: 'Civil Engineering' },
      { re: /\b(it|aids|artificial\s*intelligence)\b/i, parent: 'AI & Information Technology' },
      { re: /\b(mca|bca|computer\s*applications)\b/i, parent: 'Computer Applications' },
      { re: /\b(che|chem|chemical)\b/i, parent: 'Chemical Engineering' },
      { re: /\b(ft|food)\b/i, parent: 'Food Technology' },
      { re: /\b(met|mme|metallurg)/i, parent: 'Metallurgical & Materials' },
      { re: /\b(aue|auto|automobile)\b/i, parent: 'Mechanical Engineering' },
    ];
    for (const row of keywordMap) {
      if (row.re.test(needle) || row.re.test(name)) {
        const g = this.viewDeptGroups.find(x => x.parent === row.parent);
        if (g) return g;
      }
    }
    return null;
  };

  HiringOverviewPage.prototype.findOwnDepartmentGroup = function () {
    const meta = this.ownDepartmentMeta();
    const tried = [];
    for (const hint of [meta.code, meta.name, ...(meta.hints || [])]) {
      const h = String(hint || '').trim();
      if (!h || tried.includes(h)) continue;
      tried.push(h);
      const g = this.findGroupForFilter(h);
      if (g) return g;
    }
    return null;
  };

  HiringOverviewPage.prototype.selectedDept = function () {
    const role = this.currentRole();
    if (role === 'staff' || role === 'placement_officer') {
      if (this.activeDeptFilter) return this.activeDeptFilter;
      const meta = this.ownDepartmentMeta();
      return meta.code || meta.name || '';
    }
    return this.activeDeptFilter || '';
  };

  HiringOverviewPage.prototype.populateDeptSelect = function (extraDepts) {
    const deptSelect = this.$('deptSelect');
    if (!deptSelect) return;
    const extraCodes = [];
    const pushExtra = (code, name) => {
      const raw = String(code || '').trim();
      if (!raw || /^\d+$/.test(raw)) return;
      if (typeof isStudentAcademicDepartment === 'function' && !isStudentAcademicDepartment(raw, name || raw)) return;
      extraCodes.push({ code: raw, name: name || raw });
    };

    DepartmentStore.all().forEach(d => pushExtra(d.code, d.name || d.code));
    (Array.isArray(extraDepts) ? extraDepts : []).forEach(d => {
      if (typeof d === 'string') pushExtra(d, d);
      else pushExtra(d.code || d.dept || d.department, d.name || d.dept || d.department || d.code);
    });
    if (typeof departmentCodes === 'function') {
      departmentCodes().forEach(c => pushExtra(c, c));
    }

    const built = typeof buildDepartmentProgrammeOptions === 'function'
      ? buildDepartmentProgrammeOptions(extraCodes)
      : { groups: [], extras: extraCodes.map(e => ({ code: e.code, label: e.name || e.code })) };

    this.viewDeptGroups = built.groups || [];
    this.viewExtraProgrammes = built.extras || [];

    const role = this.currentRole();
    const escLabel = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

    // Placement officers and staff only see their own department (no campus-wide "All departments").
    if (role === 'placement_officer' || role === 'staff') {
      const meta = this.ownDepartmentMeta();
      const ownGroup = this.findOwnDepartmentGroup();
      this.viewDeptGroups = ownGroup ? [ownGroup] : [];
      this.viewExtraProgrammes = [];

      if (!ownGroup) {
        const label = (meta.name && !/^\d+$/.test(meta.name))
          ? meta.name
          : (meta.code && !/^\d+$/.test(meta.code) ? meta.code : (meta.name || meta.code || 'Your department'));
        const value = meta.code || meta.name || label;
        deptSelect.innerHTML = `<option value="${escLabel(value)}">${escLabel(label)}</option>`;
        deptSelect.value = value;
        deptSelect.disabled = true;
        this.activeParentKey = '';
        this.activeDeptFilter = value;
        this.syncBranchSelect();
        return;
      }

      this.activeParentKey = ownGroup.parent;
      const ownNeedle = normalizeProgrammeCode(meta.code || meta.name || '');
      const ownProg = ownGroup.programmes.find(p => {
        const code = normalizeProgrammeCode(p.code);
        const resolved = normalizeProgrammeCode(
          typeof resolveCollegeProgrammeCode === 'function'
            ? (resolveCollegeProgrammeCode(meta.code || meta.name) || meta.code || meta.name)
            : (meta.code || meta.name)
        );
        return code === resolved
          || code === ownNeedle
          || (typeof programmeAliasSet === 'function' && programmeAliasSet(p).has(ownNeedle));
      });
      // Default to all branches under their department; branch dropdown can narrow.
      this.activeDeptFilter = ownGroup.allValue || ownProg?.code || meta.code || meta.name;

      const parentLabel = ownGroup.parent || meta.name || meta.code || 'Your department';
      deptSelect.innerHTML = `<option value="${escLabel(ownGroup.parent)}">${escLabel(parentLabel)}</option>`;
      deptSelect.value = ownGroup.parent;
      deptSelect.disabled = true;
      this.syncBranchSelect();
      return;
    }

    let html = '<option value="">All departments</option>';
    this.viewDeptGroups.forEach(group => {
      html += `<option value="${escLabel(group.parent)}">${escLabel(group.parent)}</option>`;
    });
    this.viewExtraProgrammes.forEach(p => {
      html += `<option value="prog:${escLabel(p.code)}">${escLabel(p.label)}</option>`;
    });
    deptSelect.innerHTML = html;
    deptSelect.disabled = false;

    if (this.activeParentKey && [...deptSelect.options].some(o => o.value === this.activeParentKey)) {
      deptSelect.value = this.activeParentKey;
    } else if (this.activeDeptFilter && this.viewExtraProgrammes.some(p => p.code === this.activeDeptFilter)) {
      deptSelect.value = `prog:${this.activeDeptFilter}`;
    } else {
      deptSelect.value = '';
      this.activeParentKey = '';
      this.activeDeptFilter = '';
    }

    this.syncBranchSelect();
  };

  HiringOverviewPage.prototype.setDeptUI = function (dept) {
    const role = this.currentRole();
    const hintEl = this.$('viewHint');
    const card = this.$('viewFilterCard');
    const deptSelect = this.$('deptSelect');
    const branchSelect = this.$('branchSelect');
    if (card) card.classList.toggle('d-none', false);
    if (deptSelect) deptSelect.classList.toggle('d-none', false);
    if (branchSelect) branchSelect.classList.toggle('d-none', false);

    if (role === 'staff' || role === 'placement_officer') {
      const meta = this.ownDepartmentMeta();
      const label = meta.name || meta.code || 'your department';
      if (hintEl) hintEl.textContent = `Showing hiring data for ${label} only.`;
      return;
    }

    if (hintEl) hintEl.textContent = '';
  };

  HiringOverviewPage.prototype.configurePageForRole = function (role) {
    const title = this.$('pageTitleText') || document.getElementById('dashTitle');
    const sub = this.$('pageSub') || document.getElementById('dashSub') || this.root.querySelector('.page-sub');
    if (role === 'staff') {
      if (title) title.textContent = 'Department Hiring Overview';
      if (sub) sub.textContent = 'Live snapshot of companies hiring your department students, pipeline stages, and recent activity.';
    } else if (role === 'placement_officer') {
      const meta = this.ownDepartmentMeta();
      const label = meta.name || meta.code || 'your department';
      if (title) title.textContent = 'Department Hiring Overview';
      if (sub) sub.textContent = `Live snapshot for ${label}: companies hiring, pipeline stages, and recent activity.`;
    } else if (role === 'admin') {
      if (title) title.textContent = 'Campus Hiring Overview';
      if (sub) sub.textContent = 'Live snapshot of companies hiring, applicants in the pipeline, shortlists, offers, and hires across campus.';
    }
  };

  HiringOverviewPage.prototype.findGroupByParent = function (parentKey) {
    return this.viewDeptGroups.find(g => g.parent === parentKey) || null;
  };

  HiringOverviewPage.prototype.syncBranchSelect = function () {
    const branchSelect = this.$('branchSelect');
    if (!branchSelect) return;
    const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    const group = this.findGroupByParent(this.activeParentKey);

    if (!group || !group.programmes.length) {
      branchSelect.innerHTML = '<option value="">All branches</option>';
      branchSelect.value = '';
      branchSelect.disabled = true;
      return;
    }

    let html = `<option value="${esc(group.allValue)}">All branches</option>`;
    group.programmes.forEach(p => {
      html += `<option value="${esc(p.code)}">${esc(p.label)}</option>`;
    });
    branchSelect.innerHTML = html;
    branchSelect.disabled = false;

    if (this.activeDeptFilter && [...branchSelect.options].some(o => o.value === this.activeDeptFilter)) {
      branchSelect.value = this.activeDeptFilter;
    } else {
      branchSelect.value = group.allValue;
    }
  };

  HiringOverviewPage.prototype.applyViewFilter = function (filter, opts) {
    const parentKey = (opts && opts.parentKey !== undefined) ? opts.parentKey : this.activeParentKey;
    const deptSelect = this.$('deptSelect');
    this.activeParentKey = parentKey || '';
    this.activeDeptFilter = String(filter || '').trim();
    if (deptSelect) {
      if (this.activeParentKey && [...deptSelect.options].some(o => o.value === this.activeParentKey)) {
        deptSelect.value = this.activeParentKey;
      } else if (!this.activeParentKey && this.activeDeptFilter && this.viewExtraProgrammes.some(p => p.code === this.activeDeptFilter)) {
        deptSelect.value = `prog:${this.activeDeptFilter}`;
      } else if (!this.activeParentKey && !this.activeDeptFilter) {
        deptSelect.value = '';
      }
    }
    this.syncBranchSelect();
    this.setDeptUI(this.selectedDept());
    this.renderForDept(this.selectedDept());
  };

  HiringOverviewPage.prototype.onDepartmentDropdownChange = function () {
    const deptSelect = this.$('deptSelect');
    const value = (deptSelect && deptSelect.value) || '';

    if (!value) {
      this.applyViewFilter('', { parentKey: '' });
      return;
    }

    if (value.startsWith('prog:')) {
      this.applyViewFilter(value.slice(5), { parentKey: '' });
      return;
    }

    const group = this.findGroupByParent(value);
    if (!group) {
      this.applyViewFilter('', { parentKey: '' });
      return;
    }

    this.applyViewFilter(group.allValue || group.programmes[0]?.code || '', { parentKey: group.parent });
  };

  HiringOverviewPage.prototype.onBranchDropdownChange = function () {
    const branchSelect = this.$('branchSelect');
    if (!branchSelect || branchSelect.disabled) return;
    this.applyViewFilter(branchSelect.value || '', { parentKey: this.activeParentKey });
  };

  HiringOverviewPage.prototype.deptMatchesFilter = function (applicantDept, deptCode) {
    if (!deptCode) return true;
    const targets = (typeof splitDepartmentFilterValue === 'function'
      ? splitDepartmentFilterValue(deptCode)
      : [deptCode]
    ).map(c => {
      const resolved = typeof resolveCollegeProgrammeCode === 'function'
        ? resolveCollegeProgrammeCode(c)
        : c;
      return departmentCanonicalCode(resolved || c).toUpperCase();
    }).filter(Boolean);
    if (!targets.length) return true;
    const raw = String(applicantDept || '').trim().toUpperCase();
    const canon = departmentCanonicalCode(applicantDept || '').toUpperCase();
    const resolvedApplicant = typeof resolveCollegeProgrammeCode === 'function'
      ? resolveCollegeProgrammeCode(applicantDept || '')
      : '';
    return targets.some(target =>
      raw === target
      || canon === target
      || resolvedApplicant === target
      || raw.includes(target)
      || canon.includes(target)
    );
  };

  HiringOverviewPage.prototype.renderCompanyRows = function (companies, emptyMsg) {
    const activeCount = this.$('activeCount');
    const companyRows = this.$('companyRows');
    if (activeCount) activeCount.textContent = `${companies.length} active`;
    if (!companyRows) return;
    companyRows.innerHTML = companies.length
      ? companies.map(c => `<tr>
        <td><strong>${this.escHtml(c.company)}</strong></td>
        <td>${this.escHtml(this.formatRoles(c.roles))}</td>
        <td>${(c.applicants || 0).toLocaleString('en-IN')}</td>
        <td>${(c.shortlisted || 0).toLocaleString('en-IN')}</td>
        <td>${(c.selected || 0).toLocaleString('en-IN')}</td>
        <td><span class="badge-soft ${c.statusCls || 'info'}">${this.escHtml(c.status || 'Active')}</span></td>
      </tr>`).join('')
      : `<tr><td colspan="6" class="text-muted-2 p-4">${emptyMsg}</td></tr>`;
  };

  HiringOverviewPage.prototype.setStat = function (id, value) {
    const el = this.$id(id);
    if (!el) return;
    el.dataset.target = value;
    el.dataset.animated = '0';
  };

  HiringOverviewPage.prototype.$id = function (id) { return this.$(id); };

  HiringOverviewPage.prototype.renderForDept = function (dept) {
    const view = this.liveHiringView(dept);
    const totals = view?.totals || {};
    const companies = view?.companies || [];
    const candidates = view?.candidates || [];
    const pipeline = this.pipelineFromView(view);

    this.setStat('statCompanies', totals.companiesHiring ?? 0);
    this.setStat('statApplicants', totals.applicants ?? 0);
    this.setStat('statShortlisted', totals.shortlisted ?? 0);
    this.setStat('statOffers', totals.offers ?? 0);
    this.setStat('statHired', totals.hired ?? 0);
    animateCounters(this.root === document ? document : this.root);

    const companiesEmpty = this.staffLive || this.officerLive
      ? 'No companies are actively hiring in your department right now.'
      : (dept ? 'No active hiring data for this department yet.' : 'No companies are actively hiring right now.');
    this.renderCompanyRows(companies, companiesEmpty);

    const statusFilter = this.$('statusFilter');
    const status = statusFilter ? statusFilter.value : '';
    const list = candidates.filter(s => this.candidateMatchesFilter(s.status, status));
    const emptyCandidatesMsg = this.staffLive || this.officerLive
      ? 'No students from your department are in the hiring pipeline yet.'
      : (this.campusLive ? 'No candidates match this filter.' : 'Sign in to view live hiring data.');
    const candidateRows = this.$('candidateRows');
    if (candidateRows) {
      candidateRows.innerHTML = list.length
        ? list.map(s => `<tr>
        <td><strong>${this.escHtml(s.name)}</strong></td>
        <td>${this.escHtml(s.roll)}</td>
        <td>${this.escHtml(s.dept)}</td>
        <td>${this.escHtml(s.company)}</td>
        <td>${this.escHtml(s.role)}</td>
        <td>${pipelineStatusBadge(s.status)}</td>
      </tr>`).join('')
        : `<tr><td colspan="6" class="text-muted-2 p-4">${emptyCandidatesMsg}</td></tr>`;
    }

    this.renderHiringTrend(this.hiringTrendData);
    this.renderPipelineBreakdown(pipeline);
  };

  HiringOverviewPage.prototype.bindEvents = function () {
    if (this._bound) return;
    this._bound = true;
    const self = this;
    const deptSelect = this.$('deptSelect');
    const branchSelect = this.$('branchSelect');
    const statusFilter = this.$('statusFilter');
    const companiesCard = this.$('companiesCard');

    deptSelect?.addEventListener('change', () => self.onDepartmentDropdownChange());
    branchSelect?.addEventListener('change', () => self.onBranchDropdownChange());
    statusFilter?.addEventListener('change', () => self.renderForDept(self.selectedDept()));

    const goToCompanies = () => {
      self.$('companiesSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    companiesCard?.addEventListener('click', goToCompanies);
    companiesCard?.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goToCompanies(); }
    });

    document.addEventListener('ph-user-updated', () => {
      self.setDeptUI(self.selectedDept());
      self.renderForDept(self.selectedDept());
    });
  };

  HiringOverviewPage.prototype.init = async function (opts) {
    opts = opts || {};
    if (opts.root) {
      this.root = typeof opts.root === 'string' ? document.querySelector(opts.root) : opts.root;
    } else {
      this.root = document;
    }
    if (!this.root) return;
    this._els = {};
    this.bindEvents();

    try {
      if (!Auth.isDemo()) {
        await Auth.ensureSession();
      }
      this.refreshLiveFlags();
      const role = this.currentRole();
      if (this.staffLive || this.officerLive) {
        await Auth.enrichFromProfile();
      }

      await DepartmentStore.fetch();
      this.populateDeptSelect();

      if (this.staffLive) {
        const data = await StaffApi.fetchHiringOverview();
        if (data) this.apiHiringData = data;
        else toast('Could not load hiring overview. Refresh or sign in again.', 'warn');
      }
      if (this.campusLive) {
        const data = await RecruitingStore.fetch();
        if (data) {
          this.campusRecruitingData = data;
          const fromLive = [
            ...(Array.isArray(data.applicantsByDept) ? data.applicantsByDept.map(d => ({
              code: d.department || d.dept || d.code,
              name: d.department || d.dept || d.name || d.code,
            })) : []),
            ...(Array.isArray(data.applicants) ? data.applicants.map(a => ({
              code: a.dept || a.department || a.student?.department,
              name: a.dept || a.department || a.student?.department,
            })) : []),
          ];
          this.populateDeptSelect(fromLive);
        } else {
          toast('Could not load live recruiting data. Refresh or sign in again.', 'warn');
        }
        try {
          const stats = await dashboardStats();
          this.hiringTrendData = stats?.hiringTrend || null;
        } catch (_) {
          this.hiringTrendData = null;
        }
      }
      if (this.staffLive && this.apiHiringData?.hiringTrend) {
        this.hiringTrendData = this.apiHiringData.hiringTrend;
      }
      this.updateLiveBadge();
      this.configurePageForRole(role);
      this.setDeptUI(this.selectedDept());
      this.renderForDept(this.selectedDept());
    } catch (err) {
      console.error('Hiring overview init failed', err);
      this.populateDeptSelect();
      this.setDeptUI(this.selectedDept());
      this.renderForDept(this.selectedDept());
    }
  };

  global.HiringOverviewPage = {
    init(opts) {
      const page = new HiringOverviewPage();
      return page.init(opts);
    },
  };
})(window);
