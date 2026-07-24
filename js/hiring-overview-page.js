/**
 * Shared Campus Hiring Overview UI (hiring-overview page + PO dashboard).
 * Call HiringOverviewPage.init({ root?: HTMLElement|string }) after Auth/shell ready.
 */
(function (global) {
  'use strict';

  const IDS = [
    'viewFilterCard', 'deptSelect', 'branchSelect', 'batchSelect', 'viewHint', 'liveDataBadge',
    'pageTitleText', 'pageSub', 'companiesCard', 'companiesSection', 'activeCount',
    'companyRows', 'candidateRows', 'pipelineRows', 'statusFilter', 'trendChart',
    'statCompanies', 'statApplicants', 'statShortlisted', 'statHired',
    'trendYearSelect', 'placementListCard', 'placementListTitle', 'placementListSub',
    'placementListCount', 'placementListRows',
  ];

  function HiringOverviewPage() {
    this.root = document;
    this.apiHiringData = null;
    this.campusRecruitingData = null;
    this.hiringTrendData = null;
    this.hiringTrendThisYear = null;
    this.hiringTrendLastYear = null;
    this.placementRows = [];
    this.trendYearMode = 'this';
    this.trendChart = null;
    this.staffLive = false;
    this.adminLive = false;
    this.officerLive = false;
    this.campusLive = false;
    this.viewDeptGroups = [];
    this.viewExtraProgrammes = [];
    this.activeParentKey = '';
    this.activeDeptFilter = '';
    this.activeBatchFilter = '';
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

  /** Campus-wide filters/data: admin only. Staff always use the department hiring dashboard. */
  HiringOverviewPage.prototype.isCampusWideViewer = function () {
    return this.currentRole() === 'admin';
  };

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
    this.campusLive = this.adminLive || this.officerLive || this.staffLive;
  };

  HiringOverviewPage.prototype.updateLiveBadge = function () {
    const show = this.staffLive || this.campusLive || this.adminLive;
    const pageBadge = this.$('liveDataBadge');
    if (pageBadge) pageBadge.classList.toggle('d-none', !show);
    const dashBadge = document.getElementById('dashLiveBadge');
    if (dashBadge && (this.staffLive || this.campusLive)) {
      dashBadge.classList.toggle('d-none', !show);
    }
  };

  HiringOverviewPage.prototype.liveHiringView = function (dept) {
    if (this.campusLive && this.campusRecruitingData) {
      return this.recruitingViewForDept(this.campusRecruitingData, dept || '', this.selectedBatch());
    }
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
    const sub = canvas.closest('.card-surface')?.querySelector('small.text-muted-2');
    if (sub) {
      sub.textContent = '';
      sub.hidden = true;
    }
  };

  HiringOverviewPage.prototype.selectedTrendYear = function () {
    const now = new Date().getFullYear();
    return this.trendYearMode === 'last' ? now - 1 : now;
  };

  HiringOverviewPage.prototype.offerDateFromApplicant = function (row) {
    const timeline = Array.isArray(row?.timeline) ? row.timeline : [];
    for (const entry of timeline) {
      if ((entry?.status || '') === 'selected' && entry?.at) {
        return String(entry.at);
      }
    }
    return String(row?.updatedAt || row?.createdAt || row?.appliedAt || '');
  };

  HiringOverviewPage.prototype.filterRowsByDeptAndBatch = function (rows, dept, batch) {
    let filtered = Array.isArray(rows) ? rows : [];
    if (dept) {
      filtered = filtered.filter((row) => {
        const classBatch = row.classBatch || row.student?.classBatch || '';
        const applicantDept = row.dept || row.student?.department || row.department || '';
        if (this.batchMatchesBranch(classBatch, dept)) return true;
        return this.deptMatchesFilter(applicantDept, dept);
      });
    }
    if (batch) {
      filtered = filtered.filter((row) => {
        const classBatch = row.classBatch || row.student?.classBatch || '';
        return this.batchMatchesFilter(classBatch, batch);
      });
    }
    return filtered;
  };

  HiringOverviewPage.prototype.buildHiringTrendFromFilters = function () {
    const year = this.selectedTrendYear();
    const labels = [];
    const counts = Array(12).fill(0);
    for (let m = 0; m < 12; m++) {
      labels.push(new Date(year, m, 1).toLocaleString('en-US', { month: 'short' }));
    }

    const dept = this.selectedDept();
    const batch = this.selectedBatch();
    const applicants = Array.isArray(this.campusRecruitingData?.applicants)
      ? this.campusRecruitingData.applicants
      : [];

    if (applicants.length) {
      const offers = this.filterRowsByDeptAndBatch(
        applicants.filter((row) => {
          const rawStatus = String(row.status || '').toLowerCase();
          const uiStatus = String(row.uiStatus || '').toLowerCase();
          return rawStatus === 'selected' || uiStatus === 'offered' || uiStatus === 'selected';
        }),
        dept,
        batch
      );
      offers.forEach((row) => {
        const ts = Date.parse(this.offerDateFromApplicant(row));
        if (Number.isNaN(ts)) return;
        const d = new Date(ts);
        if (d.getFullYear() !== year) return;
        counts[d.getMonth()]++;
      });
      return { labels, series: [{ label: 'Offers', data: counts }], year };
    }

    let placements = this.placementSourceRows().filter((row) => Number(row.year) === year);
    placements = this.filterRowsByDeptAndBatch(placements, dept, batch);
    placements.forEach((row) => {
      const ts = Date.parse(String(row.placedAt || row.selectedAt || ''));
      if (Number.isNaN(ts)) return;
      const d = new Date(ts);
      if (d.getFullYear() !== year) return;
      counts[d.getMonth()]++;
    });

    return { labels, series: [{ label: 'Offers', data: counts }], year };
  };

  HiringOverviewPage.prototype.resolvedHiringTrend = function () {
    if (this.campusRecruitingData) {
      return this.buildHiringTrendFromFilters();
    }
    return this.trendYearMode === 'last'
      ? (this.hiringTrendLastYear || this.hiringTrendThisYear || this.hiringTrendData)
      : (this.hiringTrendThisYear || this.hiringTrendData);
  };

  HiringOverviewPage.prototype.applyTrendYearMode = function () {
    const select = this.$('trendYearSelect');
    const raw = String(select?.value || this.trendYearMode || 'this').toLowerCase();
    this.trendYearMode = raw.includes('last') ? 'last' : 'this';
    if (select && select.value !== this.trendYearMode) {
      const match = [...(select.options || [])].find(o => String(o.value || o.textContent).toLowerCase().includes(this.trendYearMode === 'last' ? 'last' : 'this'));
      if (match) select.value = match.value || match.textContent;
    }
    this.hiringTrendData = this.resolvedHiringTrend();
    // Re-render stats so Hired / placed matches the selected year + placement list.
    this.renderForDept(this.selectedDept());
  };

  HiringOverviewPage.prototype.placementSourceRows = function () {
    if (Array.isArray(this.campusRecruitingData?.placements)) {
      return this.campusRecruitingData.placements;
    }
    return this.placementRows || [];
  };

  /** Live placements only: real placedAt/join date in the target year (no historical batch guesses). */
  HiringOverviewPage.prototype.isLivePlacement = function (row, year) {
    if (Number(row?.year) !== year) return false;
    const co = String(row?.company || '').trim();
    if (!co || co === '—') return false;
    const at = String(row?.placedAt || row?.joinDate || '').trim();
    if (!at) return false;
    const ts = Date.parse(at);
    if (Number.isNaN(ts)) return false;
    return new Date(ts).getFullYear() === year;
  };

  HiringOverviewPage.prototype.livePlacementsForFilters = function (deptCode, batchCode, year, sourceRows) {
    let placements = Array.isArray(sourceRows)
      ? sourceRows.slice()
      : (Array.isArray(this.campusRecruitingData?.placements)
        ? this.campusRecruitingData.placements.slice()
        : this.placementSourceRows().slice());
    placements = placements.filter((p) => this.isLivePlacement(p, year));
    if (deptCode) {
      placements = placements.filter(p => this.deptMatchesFilter(p.dept, deptCode));
    }
    if (batchCode) {
      placements = placements.filter(p => this.batchMatchesFilter(p.classBatch, batchCode));
    }
    return this.uniquePlacementsByPerson(placements);
  };

  HiringOverviewPage.prototype.renderPlacementList = function () {
    const rowsEl = this.$('placementListRows');
    const countEl = this.$('placementListCount');
    const titleEl = this.$('placementListTitle');
    const subEl = this.$('placementListSub');
    if (!rowsEl) return;

    // Placement list / Hired always use the current year for live counts (no past years).
    const year = new Date().getFullYear();
    const dept = this.selectedDept();
    const batch = this.selectedBatch();
    const rows = this.livePlacementsForFilters(dept, batch, year);

    if (titleEl) {
      titleEl.textContent = `Live placement list (${year})`;
    }
    if (subEl) {
      subEl.textContent = '';
      subEl.hidden = true;
    }
    if (countEl) countEl.textContent = String(rows.length);

    rowsEl.innerHTML = rows.length
      ? rows.map((s) => {
        const batchLabel = [s.dept, s.classBatch].filter(Boolean).join(' · ') || '—';
        return `<tr>
          <td><strong>${this.escHtml(s.name)}</strong></td>
          <td>${this.escHtml(s.roll || '—')}</td>
          <td>${this.escHtml(batchLabel)}</td>
          <td>${this.escHtml(s.company || '—')}</td>
          <td>${this.escHtml(s.role || '—')}</td>
          <td>${this.escHtml(s.package || '—')}</td>
        </tr>`;
      }).join('')
      : `<tr><td colspan="6" class="text-muted-2 p-4">No live placements for ${year}.</td></tr>`;
  };

  HiringOverviewPage.prototype.renderPipelineBreakdown = function (pipeline) {
    const rows = pipeline.length ? pipeline : EMPTY_PIPELINE.slice();
    const el = this.$('pipelineRows');
    if (!el) return;
    el.innerHTML = rows.map(p => {
      return `<tr><td><strong>${this.escHtml(p.label)}</strong></td><td>${p.value.toLocaleString('en-IN')}</td></tr>`;
    }).join('');
  };

  HiringOverviewPage.prototype.placementPersonKey = function (row) {
    const roll = String(row?.roll || row?.registerNumber || '').trim().toUpperCase();
    if (roll) return 'roll:' + roll;
    const name = String(row?.name || '').trim().toLowerCase().replace(/\s+/g, ' ');
    const dept = String(row?.dept || '').trim().toLowerCase();
    if (name) return 'name:' + name + (dept ? '|' + dept : '');
    return '';
  };

  /** One row per person — a placed student counts as 1 even with multiple offers/companies. */
  HiringOverviewPage.prototype.uniquePlacementsByPerson = function (rows) {
    const seen = new Map();
    (rows || []).forEach((row) => {
      const key = this.placementPersonKey(row);
      if (!key || key === 'roll:' || key === 'name:|') return;
      if (!seen.has(key)) seen.set(key, row);
    });
    return [...seen.values()];
  };

  /** Unique people among applicants (1 student with 3 company offers still counts as 1). */
  HiringOverviewPage.prototype.uniqueApplicantsByPerson = function (rows) {
    const seen = new Map();
    (rows || []).forEach((row) => {
      const key = this.placementPersonKey({
        roll: row.roll || row.registerNumber || row.student?.registerNumber,
        name: row.name || row.student?.name,
        dept: row.dept || row.student?.department,
      });
      if (!key || key === 'roll:' || key === 'name:|') return;
      if (!seen.has(key)) seen.set(key, row);
    });
    return [...seen.values()];
  };

  HiringOverviewPage.prototype.recruitingViewForDept = function (data, deptCode, batchCode) {
    if (!data) return null;

    // Lite payload: headline stats + SQL status counts — no per-row applicants yet.
    if (data.lite) {
      const stats = data.stats || {};
      const sc = data.statusCounts || {};
      const shortlisted = (Number(sc.shortlisted) || 0) + (Number(sc.under_review) || 0);
      const offers = (Number(sc.offered) || 0) + (Number(sc.selected) || 0);
      const applicants = Number(stats.applicants) || Object.values(sc).reduce((n, v) => n + (Number(v) || 0), 0);
      const hired = Number(stats.placedStudents) || 0;
      const companies = (data.activeCompanies || []).map(c => ({
        company: c.company,
        roles: c.openRoles ? [`${c.openRoles} open role${c.openRoles === 1 ? '' : 's'}`] : [],
        applicants: Number(c.applicants) || 0,
        shortlisted: 0,
        selected: 0,
        status: c.status || 'Active',
        statusCls: { scheduled: 'info', open: 'success', ongoing: 'info', reviewing: 'warning' }[String(c.status || '').toLowerCase()] || 'success',
      }));
      return {
        totals: {
          companiesHiring: companies.length || Number(stats.activeCompanies) || 0,
          applicants,
          shortlisted,
          offers,
          hired,
        },
        pipeline: [
          { label: 'Applicants', value: applicants },
          { label: 'Shortlisted', value: shortlisted },
          { label: 'Offers', value: offers },
          { label: 'Hired', value: hired },
        ],
        companies,
        candidates: [],
        lite: true,
      };
    }

    let applicants = (data.applicants || []).map(RecruitingStore.mapApplicant);
    if (deptCode) {
      applicants = applicants.filter(a => this.deptMatchesFilter(a.dept, deptCode));
    }
    if (batchCode) {
      applicants = applicants.filter(a => this.batchMatchesFilter(a.classBatch, batchCode));
    }

    // People counts: one student selected at 3 companies still counts once for Offers.
    const people = this.uniqueApplicantsByPerson(applicants);
    const shortlisted = this.uniqueApplicantsByPerson(
      applicants.filter(a => a.status === 'shortlisted' || a.status === 'under_review')
    ).length;
    const offers = this.uniqueApplicantsByPerson(
      applicants.filter(a => a.status === 'offered' || a.status === 'selected')
    ).length;

    // Hired = unique people with a current live placement this year (not past companies).
    const year = new Date().getFullYear();
    const placements = this.livePlacementsForFilters(deptCode, batchCode, year, data.placements);
    const hired = placements.length;

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
        applicants: people.length,
        shortlisted,
        offers,
        hired,
      },
      pipeline: [
        { label: 'Applicants', value: people.length },
        { label: 'Shortlisted', value: shortlisted },
        { label: 'Offers', value: offers },
        { label: 'Hired', value: hired },
      ],
      companies,
      candidates: applicants.map(a => ({
        name: a.name,
        roll: a.roll,
        dept: a.dept,
        company: a.company || '-',
        role: a.role,
        status: a.status,
        classBatch: a.classBatch || '',
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

  HiringOverviewPage.prototype.selectedBatch = function () {
    return String(this.activeBatchFilter || '').trim();
  };

  HiringOverviewPage.prototype.batchMatchesFilter = function (studentBatch, batchCode) {
    if (!batchCode) return true;
    const raw = String(studentBatch || '').trim();
    if (!raw) return false;
    return raw.toUpperCase() === String(batchCode).trim().toUpperCase();
  };

  HiringOverviewPage.prototype.isYearOnlyBatchLabel = function (batchLabel) {
    return /^\s*\d{4}\s*[-–/]\s*\d{2,4}/.test(String(batchLabel || '').trim());
  };

  HiringOverviewPage.prototype.batchProgrammeFromLabel = function (batchLabel) {
    const trimmed = String(batchLabel || '').trim();
    if (!trimmed) return '';
    if (this.isYearOnlyBatchLabel(trimmed)) {
      return '';
    }
    const raw = normalizeProgrammeCode(batchLabel);
    if (!raw) return '';
    const programmes = [];
    if (typeof DEPARTMENT_PROGRAMME_GROUPS !== 'undefined') {
      DEPARTMENT_PROGRAMME_GROUPS.forEach((g) => {
        g.programmes.forEach((p) => programmes.push(p));
      });
    }
    programmes.sort((a, b) => normalizeProgrammeCode(b.code).length - normalizeProgrammeCode(a.code).length);
    for (const p of programmes) {
      const codes = [p.code, ...(p.aliases || [])]
        .map((c) => normalizeProgrammeCode(c))
        .filter(Boolean)
        .sort((a, b) => b.length - a.length);
      for (const code of codes) {
        if (raw === code || raw.startsWith(code)) {
          return normalizeProgrammeCode(p.code);
        }
      }
    }
    return raw;
  };

  HiringOverviewPage.prototype.batchMatchesBranch = function (batchLabel, branchCode) {
    if (!branchCode) return true;
    const batchProg = this.batchProgrammeFromLabel(batchLabel);
    const targets = (typeof splitDepartmentFilterValue === 'function'
      ? splitDepartmentFilterValue(branchCode)
      : [branchCode]
    ).map((c) => {
      const resolved = typeof resolveCollegeProgrammeCode === 'function'
        ? resolveCollegeProgrammeCode(c)
        : c;
      return normalizeProgrammeCode(resolved || c);
    }).filter(Boolean);
    if (!targets.length) return true;
    if (!batchProg) {
      return this.isYearOnlyBatchLabel(batchLabel) && targets.length === 1;
    }
    return targets.some((target) => batchProg === target);
  };

  HiringOverviewPage.prototype.selectedBranchForApi = function () {
    const filter = String(this.activeDeptFilter || '').trim();
    if (!filter) return '';
    const group = this.findGroupByParent(this.activeParentKey);
    if (group && filter === group.allValue) {
      return '';
    }
    return filter;
  };

  HiringOverviewPage.prototype.selectedBranchLabel = function () {
    const branchSelect = this.$('branchSelect');
    if (!branchSelect || branchSelect.disabled) return '';
    const value = String(branchSelect.value || '').trim();
    if (!value) return '';
    const group = this.findGroupByParent(this.activeParentKey);
    if (group && value === group.allValue) return '';
    const opt = [...branchSelect.options].find(o => o.value === value);
    return opt ? opt.textContent.trim() : value;
  };

  HiringOverviewPage.prototype.allBatchOptions = function () {
    if (Array.isArray(this.campusRecruitingData?.batchOptions) && this.campusRecruitingData.batchOptions.length) {
      return [...this.campusRecruitingData.batchOptions];
    }
    const u = Auth.user() || {};
    if (Array.isArray(u.assignedClassBatches) && u.assignedClassBatches.length) {
      return [...u.assignedClassBatches];
    }
    if (this.campusRecruitingData) {
      return this.deriveBatchOptionsFromCampus('');
    }
    return [];
  };

  HiringOverviewPage.prototype.batchOptions = function () {
    let batches = this.allBatchOptions();
    const branch = this.selectedBranchForApi();
    if (branch && batches.length) {
      batches = batches.filter((b) => this.batchMatchesBranch(b, branch));
    }
    return batches;
  };

  HiringOverviewPage.prototype.batchYearLabel = function (batchLabel) {
    const raw = String(batchLabel || '');
    const m = raw.match(/(\d{4})\s*[-–]\s*(\d{2,4})/);
    if (m) {
      const end = m[2].length === 2 ? m[1].slice(0, 2) + m[2] : m[2];
      return `${m[1]}-${end}`;
    }
    const y = raw.match(/(20\d{2})/);
    return y ? y[1] : '';
  };

  /** Match backend OfficerDataService::looksLikeFinalYearClassBatch year-window rule. */
  HiringOverviewPage.prototype.batchEndYear = function (batchLabel) {
    const raw = String(batchLabel || '');
    const m = raw.match(/20\d{2}\s*[-–]\s*(\d{2,4})/);
    if (!m) return 0;
    const endRaw = String(m[1] || '').trim();
    if (!endRaw) return 0;
    return endRaw.length === 2 ? (2000 + Number(endRaw)) : Number(endRaw);
  };

  HiringOverviewPage.prototype.isFinalYearBatchLabel = function (batchLabel, programmeCode) {
    const batch = String(batchLabel || '').trim().toUpperCase();
    if (!batch) return false;
    if (/\b(FINAL|OUTGOING|PASS.?OUT|PLACEMENT)\b/.test(batch)) return true;

    const hint = String(programmeCode || '').toUpperCase();
    const blob = `${batch} ${hint}`;
    const isPg = /(?:^|[^A-Z])(?:IN)?MCA(?:REG)?(?=\d|[^A-Z]|$)|(?:^|[^A-Z])(?:MBA|M\.?TECH|MTECH|MCAR|PG)(?=\d|[^A-Z]|$)/.test(blob);
    let programme = '';
    if (/MCAINT|INMCA|INTMCA|IMCA|DDMCA/.test(blob.replace(/[^A-Z0-9]/g, ''))) programme = 'INMCA';
    else if (/BCA/.test(blob.replace(/[^A-Z0-9]/g, ''))) programme = 'BCA';
    else if (/MCA/.test(blob.replace(/[^A-Z0-9]/g, ''))) programme = 'MCA';

    const finalSemesterStart = programme === 'BCA' ? 5 : programme === 'MCA' ? 3 : programme === 'INMCA' ? 9 : (isPg ? 3 : 7);
    const semMatch = batch.match(/(?:^|[^A-Z0-9])S(10|[1-9])(?:[^A-Z0-9]|$)/) || batch.match(/\bS(10|[1-9])\b/) || batch.match(/\bSEM(?:ESTER)?[\s\-]*(10|[1-9])\b/);
    if (semMatch) {
      return Number(semMatch[1]) >= finalSemesterStart;
    }

    const endYear = this.batchEndYear(batch);
    if (endYear > 0) {
      const nowYear = new Date().getFullYear();
      return endYear >= nowYear && endYear <= (nowYear + 1);
    }

    return false;
  };

  HiringOverviewPage.prototype.branchYearsForProgramme = function (programmeCode) {
    const years = [];
    const seen = new Set();
    this.allBatchOptions().forEach((batch) => {
      if (!this.batchMatchesBranch(batch, programmeCode)) return;
      if (!this.isFinalYearBatchLabel(batch, programmeCode)) return;
      const year = this.batchYearLabel(batch);
      if (!year) return;
      if (seen.has(year)) return;
      seen.add(year);
      years.push(year);
    });
    years.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    return years;
  };

  HiringOverviewPage.prototype.deriveBatchOptionsFromCampus = function (dept) {
    if (!this.campusRecruitingData) return [];
    const batches = new Set();
    (this.campusRecruitingData.applicants || []).forEach((row) => {
      const st = row.student || {};
      const deptCode = st.department || row.department || '';
      const batch = String(st.classBatch || row.classBatch || '').trim();
      if (!batch) return;
      if (dept && !this.deptMatchesFilter(deptCode, dept)) return;
      batches.add(batch);
    });
    return [...batches].sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
  };

  HiringOverviewPage.prototype.populateBatchSelect = function () {
    const batchSelect = this.$('batchSelect');
    if (!batchSelect) return;
    // Officer / staff dashboards: department/branch filters only — hide AES batch selector.
    const show = false;
    const batches = show ? this.batchOptions() : [];
    batchSelect.classList.toggle('d-none', !show || !batches.length);
    if (!show || !batches.length) {
      batchSelect.innerHTML = '<option value="">All batches</option>';
      batchSelect.value = '';
      this.activeBatchFilter = '';
      return;
    }

    const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    let html = '<option value="">All batches</option>';
    batches.forEach((batch) => {
      html += `<option value="${esc(batch)}">${esc(batch)}</option>`;
    });
    batchSelect.innerHTML = html;
    if (this.activeBatchFilter && [...batchSelect.options].some(o => o.value === this.activeBatchFilter)) {
      batchSelect.value = this.activeBatchFilter;
    } else {
      batchSelect.value = '';
      this.activeBatchFilter = '';
    }
  };

  HiringOverviewPage.prototype.onBatchDropdownChange = async function () {
    const batchSelect = this.$('batchSelect');
    this.activeBatchFilter = batchSelect?.value || '';
    this.renderForDept(this.selectedDept());
  };

  HiringOverviewPage.prototype.selectedDept = function () {
    const role = this.currentRole();
    if (this.isCampusWideViewer()) {
      return this.activeDeptFilter || '';
    }
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

    // Placement officers and normal staff only see their own department.
    // Senior staff (rank < 6) get campus-wide "All departments" like admin.
    if ((role === 'placement_officer' || role === 'staff') && !this.isCampusWideViewer()) {
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
    const batchSelect = this.$('batchSelect');
    if (card) card.classList.toggle('d-none', false);
    if (deptSelect) deptSelect.classList.toggle('d-none', false);
    if (branchSelect) branchSelect.classList.toggle('d-none', false);
    this.syncBranchSelect();
    this.populateBatchSelect();

    if ((role === 'staff' || role === 'placement_officer') && !this.isCampusWideViewer()) {
      if (hintEl) {
        hintEl.textContent = '';
        hintEl.hidden = true;
      }
      return;
    }

    if (hintEl) {
      hintEl.textContent = '';
      hintEl.hidden = true;
    }
  };

  HiringOverviewPage.prototype.configurePageForRole = function (role) {
    const title = this.$('pageTitleText') || document.getElementById('dashTitle');
    const sub = this.$('pageSub') || document.getElementById('dashSub') || this.root.querySelector('.page-sub');
    if (role === 'staff') {
      // Staff dashboard: username in topbar; no Department Hiring Overview banner.
      if (title) title.textContent = '';
      if (sub) {
        sub.textContent = '';
        sub.hidden = true;
      }
      document.getElementById('dashLiveBadge')?.classList.add('d-none');
      document.querySelector('.page-header')?.classList.add('d-none');
      return;
    }
    if (role === 'placement_officer') {
      if (title) title.textContent = 'Dashboard';
      if (sub) {
        sub.textContent = '';
        sub.hidden = true;
      }
      document.getElementById('dashLiveBadge')?.classList.add('d-none');
    } else if (role === 'admin') {
      if (title) title.textContent = 'Campus Hiring Overview';
      if (sub) {
        sub.textContent = '';
        sub.hidden = true;
      }
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
      const years = this.branchYearsForProgramme(p.code);
      const yearSuffix = years.length ? ` (${years.join(', ')})` : '';
      html += `<option value="${esc(p.code)}">${esc(p.label)}${esc(yearSuffix)}</option>`;
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
    this.populateBatchSelect();
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

  HiringOverviewPage.prototype.onBranchDropdownChange = async function () {
    const branchSelect = this.$('branchSelect');
    if (!branchSelect || branchSelect.disabled) return;
    this.activeParentKey = this.activeParentKey || '';
    this.activeDeptFilter = String(branchSelect.value || '').trim();
    this.activeBatchFilter = '';
    const batchSelect = this.$('batchSelect');
    if (batchSelect) batchSelect.value = '';
    this.populateBatchSelect();
    this.setDeptUI(this.selectedDept());
    this.renderForDept(this.selectedDept());
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
    this.setStat('statHired', totals.hired ?? 0);
    animateCounters(this.root === document ? document : this.root);

    const companiesEmpty = this.staffLive || this.officerLive
      ? 'No companies are actively hiring in your department right now.'
      : (dept ? 'No active hiring data for this department yet.' : 'No companies are actively hiring right now.');
    this.renderCompanyRows(companies, companiesEmpty);

    const statusFilter = this.$('statusFilter');
    const status = statusFilter ? statusFilter.value : '';
    const batchFilter = this.selectedBatch();
    const list = candidates.filter(s =>
      this.candidateMatchesFilter(s.status, status)
      && this.batchMatchesFilter(s.classBatch, batchFilter)
    );
    const emptyCandidatesMsg = view?.lite
      ? 'Candidate list loads when you filter by status or batch.'
      : (this.staffLive || this.officerLive
      ? 'No students from your department are in the hiring pipeline yet.'
      : (this.campusLive ? 'No candidates match this filter.' : 'Sign in to view live hiring data.'));
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

    this.renderHiringTrend(this.resolvedHiringTrend());
    this.renderPipelineBreakdown(pipeline);
    this.renderPlacementList();
  };

  HiringOverviewPage.prototype.ensureFullRecruiting = function () {
    if (!this.campusLive) return Promise.resolve(null);
    if (this.campusRecruitingData && !this.campusRecruitingData.lite) {
      return Promise.resolve(this.campusRecruitingData);
    }
    if (this._fullRecruitingPromise) return this._fullRecruitingPromise;
    this._fullRecruitingPromise = RecruitingStore.fetch()
      .then((data) => {
        if (data) this.applyRecruitingData(data, null);
        return data;
      })
      .catch(() => null);
    return this._fullRecruitingPromise;
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
    self.$('batchSelect')?.addEventListener('change', () => {
      self.ensureFullRecruiting().finally(() => self.onBatchDropdownChange());
    });
    self.$('trendYearSelect')?.addEventListener('change', () => { self.applyTrendYearMode(); });
    statusFilter?.addEventListener('change', () => {
      self.ensureFullRecruiting().finally(() => self.renderForDept(self.selectedDept()));
    });

    const goToCompanies = () => {
      self.$('companiesSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    companiesCard?.addEventListener('click', goToCompanies);
    companiesCard?.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goToCompanies(); }
    });

    document.addEventListener('ph-user-updated', () => {
      self.populateBatchSelect();
      self.setDeptUI(self.selectedDept());
      self.renderForDept(self.selectedDept());
    });
  };

  HiringOverviewPage.prototype.applyRecruitingData = function (data, stats) {
    if (data) {
      this.campusRecruitingData = data;
      if (Array.isArray(data.placements)) this.placementRows = data.placements;
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
      this.populateBatchSelect();
    }
    if (stats) {
      this.hiringTrendThisYear = stats?.hiringTrend || this.hiringTrendThisYear;
      this.hiringTrendLastYear = stats?.hiringTrendLastYear || this.hiringTrendLastYear;
    }
    this.applyTrendYearMode();
    this.updateLiveBadge();
    this.configurePageForRole(this.currentRole());
    this.setDeptUI(this.selectedDept());
    this.renderForDept(this.selectedDept());
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
      if (!Auth.isDemo() && !Auth.hasLiveSession()) {
        await Auth.ensureSession();
      }
      this.refreshLiveFlags();
      const role = this.currentRole();

      // Use cached departments immediately; refresh in background (do not block first paint).
      this.populateDeptSelect();
      DepartmentStore.fetch().then(() => this.populateDeptSelect()).catch(() => {});

      // Instant seed from session/prefetch (sub-1s KPI paint).
      const seed = window.__phCachedHiringSeed || window.__phReadDashKpi?.(role);
      if (seed?.recruiting) {
        const seedStats = seed.stats || null;
        this.applyRecruitingData(seed.recruiting, seedStats);
      }

      // First paint / revalidate: ultra-lite recruiting + lite stats only.
      if (this.campusLive) {
        const [liteData, liteStats] = await Promise.all([
          RecruitingStore.fetch({ lite: true }).catch(() => null),
          dashboardStats({ lite: true }).catch(() => null),
        ]);
        if (liteData) {
          this.applyRecruitingData(liteData, liteStats);
          try {
            window.__phWriteDashKpi?.(role, { recruiting: liteData, stats: liteStats });
          } catch (_) { /* ignore */ }
        } else if (!seed?.recruiting) {
          this.updateLiveBadge();
          this.configurePageForRole(role);
          this.setDeptUI(this.selectedDept());
          this.renderForDept(this.selectedDept());
        }
      } else {
        this.updateLiveBadge();
        this.configurePageForRole(role);
        this.setDeptUI(this.selectedDept());
        this.renderForDept(this.selectedDept());
      }

      // Background: trends always; full applicant enrich only off the dashboard critical path.
      if (this.campusLive) {
        const isDashboard = (document.body?.dataset?.page || '') === 'dashboard.html';
        const hydrateTrends = () => {
          dashboardStats().then((stats) => {
            if (stats) this.applyRecruitingData(this.campusRecruitingData, stats);
          }).catch(() => {});
        };
        const hydrateFull = () => {
          Promise.all([
            RecruitingStore.fetch().catch(() => null),
            dashboardStats().catch(() => null),
          ]).then(([data, stats]) => {
            if (!data && !stats) {
              if (!this.campusRecruitingData) {
                toast('Could not load live recruiting data. Refresh or sign in again.', 'warn');
                document.getElementById('dashLiveBadge')?.classList.add('d-none');
              }
              return;
            }
            this.applyRecruitingData(data || this.campusRecruitingData, stats);
          }).catch(() => {});
        };
        const schedule = (fn, delay) => {
          if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(fn, { timeout: delay });
          } else {
            setTimeout(fn, Math.min(delay, 1500));
          }
        };
        if (isDashboard) {
          // Dashboard: lite KPIs are enough; warm trends without 5k-row enrich.
          schedule(hydrateTrends, 2500);
        } else {
          schedule(hydrateFull, 2500);
        }
      }
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
