'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '../..');
const src = fs.readFileSync(path.join(root, 'js/coding-error-format.js'), 'utf8');
const ctx = { window: {}, console };
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(src, ctx);
const fmt = ctx.window.CodingErrorFormat;
if (!fmt) {
  console.error('[FAIL] CodingErrorFormat missing');
  process.exit(1);
}

let fail = 0;
function check(name, ok, detail) {
  console.log((ok ? '[PASS] ' : '[FAIL] ') + name + (detail ? ' — ' + detail : ''));
  if (!ok) fail += 1;
}

check('custom input not remapped', fmt.resolveRunStatus({ status: 'Custom Input Error', passed: false, execution: { exitCode: 0, engineStatus: 'not_run', succeeded: false } }) === 'Custom Input Error', '');
const reconciled = fmt.reconcilePracticeRunRow({
  status: 'Custom Input Error',
  passed: false,
  expected: '',
  output: '',
  execution: { exitCode: 0, engineStatus: 'not_run', succeeded: false },
});
check('reconcile keeps custom input error', reconciled.status === 'Custom Input Error', reconciled.status);
check('passed true is Passed', fmt.resolveRunStatus({ status: 'Passed', passed: true }) === 'Passed', '');
check('execution successful becomes WA', fmt.resolveRunStatus({ status: 'Execution Successful', passed: false }) === 'Wrong Answer', '');
check('judge error kept', fmt.resolveRunStatus({ status: 'Judge Error', passed: false }) === 'Judge Error', '');

process.exit(fail === 0 ? 0 : 1);
