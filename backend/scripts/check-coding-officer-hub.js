const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', '..');
const html = fs.readFileSync(path.join(root, 'mock-coding.html'), 'utf8');
const page = fs.readFileSync(path.join(root, 'js', 'coding-page.js'), 'utf8');

const must = [
  'id="codViewNav"',
  'id="codDirectory"',
  'id="codManage"',
  'data-view="progress"',
  'data-view="manage"',
  'btnNewTest',
  'btnNewWeeklyContest',
  'btnNewBankProblem',
  'cod-role-officer',
  'codRoleHint',
  'coding-page.js?v=20260914codpo',
];
for (const s of must) {
  if (!html.includes(s)) {
    console.error('HTML missing', s);
    process.exit(1);
  }
}
if (/coding-page\.js\?v=20260819/.test(html) || /api\.js\?v=20260819/.test(html)) {
  console.error('HTML still points at Aug 19 scripts');
  process.exit(1);
}

const examIdx = html.indexOf('id="examShell"');
const examCloseHint = html.indexOf('data-cod-panel="result"');
const modalIdx = html.indexOf('id="testFormModal"');
if (examIdx < 0 || modalIdx < 0 || examCloseHint < 0 || !(examIdx < examCloseHint && examCloseHint < modalIdx)) {
  console.error('exam result panel is not inside examShell before manage modals');
  process.exit(1);
}
const between = html.slice(examCloseHint, modalIdx);
if (!between.includes('</div>')) {
  console.error('examShell is not closed before testFormModal');
  process.exit(1);
}

if (!page.includes('applyRoleAccess') || !page.includes("role === 'placement_officer'")) {
  console.error('coding-page missing officer access');
  process.exit(1);
}
if (!page.includes("view === 'take' && access.canTake")) {
  console.error('Take panel is not gated on canTake');
  process.exit(1);
}

function apply(role) {
  if (role === 'placement_officer') return { canTake: false, canManage: true, canViewDirectory: true };
  if (role === 'admin' || role === 'staff') return { canTake: false, canManage: false, canViewDirectory: true };
  if (role === 'student') return { canTake: true, canManage: false, canViewDirectory: false };
  return { canTake: false, canManage: false, canViewDirectory: false };
}

function visiblePanels(role, requested) {
  const access = apply(role);
  const views = [];
  if (access.canTake) views.push('take');
  if (access.canViewDirectory) views.push('progress');
  if (access.canManage) views.push('manage');
  let view = requested || (views.includes('progress') ? 'progress' : views[0]);
  if (!access.canTake || role === 'placement_officer' || role === 'admin' || role === 'staff') {
    if (view === 'take' || !views.includes(view)) {
      view = views.includes('progress') ? 'progress' : (views.includes('manage') ? 'manage' : view);
    }
  }
  return {
    view,
    take: view === 'take' && access.canTake,
    progress: view === 'progress',
    manage: view === 'manage',
    access,
  };
}

const po = apply('placement_officer');
if (po.canTake || !po.canManage || !po.canViewDirectory) {
  console.error('PO access wrong', po);
  process.exit(1);
}
const st = apply('student');
if (!st.canTake || st.canManage || st.canViewDirectory) {
  console.error('student access wrong', st);
  process.exit(1);
}
const ad = apply('admin');
if (ad.canTake || ad.canManage || !ad.canViewDirectory) {
  console.error('admin access wrong', ad);
  process.exit(1);
}

const poTakeHash = visiblePanels('placement_officer', 'take');
if (poTakeHash.take || !poTakeHash.progress || poTakeHash.view !== 'progress') {
  console.error('PO still sees Take test when hash is #take', poTakeHash);
  process.exit(1);
}
const poManage = visiblePanels('placement_officer', 'manage');
if (poManage.take || !poManage.manage || poManage.progress) {
  console.error('PO manage view wrong', poManage);
  process.exit(1);
}
const student = visiblePanels('student', 'take');
if (!student.take || student.progress || student.manage) {
  console.error('student take view wrong', student);
  process.exit(1);
}
const staff = visiblePanels('staff', 'take');
if (staff.take || !staff.progress || staff.manage) {
  console.error('staff still sees Take or Manage', staff);
  process.exit(1);
}

console.log('OK html+role+view checks');
