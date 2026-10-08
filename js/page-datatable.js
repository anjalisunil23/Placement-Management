/* Turn list tables into DataTables after each module fills them. */
(function () {
  const page = document.body?.dataset?.page || '';
  if (page === 'students.html' || page === 'coding.html') return;
  if (typeof DataTable === 'undefined') return;
  if (DataTable.ext) DataTable.ext.errMode = 'throw';

  let silence = 0;
  const pending = new Set();
  const busy = new WeakSet();
  let timer = 0;

  function isListTable(table) {
    if (!table || table.tagName !== 'TABLE') return false;
    if (table.hasAttribute('data-no-datatable')) return false;
    if (table.closest('[data-no-datatable]')) return false;
    const head = table.tHead && table.tHead.rows[0];
    if (!head || head.cells.length < 2) return false;
    if (!table.tBodies[0]) return false;
    return table.classList.contains('table-modern')
      || table.classList.contains('registry-table')
      || table.classList.contains('table-pms');
  }

  function isPlaceholder(tbody) {
    const rows = tbody.rows;
    return rows.length === 1
      && rows[0].cells.length === 1
      && rows[0].cells[0].colSpan > 1;
  }

  function dtInstance(table) {
    try {
      if (typeof DataTable.get === 'function') {
        const api = DataTable.get(table);
        if (api) return api;
      }
    } catch (_) { /* ignore */ }
    try {
      if (window.jQuery && jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable(table)) {
        return jQuery(table).DataTable();
      }
    } catch (_) { /* ignore */ }
    return null;
  }

  function isInited(table) {
    try {
      if (typeof DataTable.isDataTable === 'function' && DataTable.isDataTable(table)) return true;
    } catch (_) { /* ignore */ }
    return !!dtInstance(table);
  }

  function destroyDt(table) {
    const api = dtInstance(table);
    if (!api) return;
    try { api.destroy(); } catch (_) { /* ignore */ }
  }

  function actionTargets(table) {
    const targets = [];
    Array.from(table.tHead.rows[0].cells).forEach((th, index) => {
      const label = th.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
      if (!label || label === 'action' || label === 'actions' || th.querySelector('input')) {
        targets.push(index);
      }
    });
    return targets;
  }

  function optionsFor(table) {
    const targets = actionTargets(table);
    return {
      destroy: true,
      pageLength: 25,
      lengthMenu: [10, 25, 50, 100, 250],
      order: [],
      columnDefs: targets.length
        ? [{ orderable: false, searchable: false, targets: targets }]
        : [],
      layout: {
        topStart: 'pageLength',
        topEnd: 'search',
        bottomStart: 'info',
        bottomEnd: 'paging',
      },
      language: {
        search: '',
        searchPlaceholder: 'Search…',
        emptyTable: 'No records.',
        zeroRecords: 'No records match your search.',
        lengthMenu: 'Show _MENU_ rows',
      },
    };
  }

  function upgrade(table) {
    if (silence || !isListTable(table) || busy.has(table)) return;
    const tbody = table.tBodies[0];
    if (!tbody) return;
    if (table.dataset.dtDrawing === '1') return;

    busy.add(table);
    silence += 1;
    const release = () => {
      busy.delete(table);
      setTimeout(() => { silence = Math.max(0, silence - 1); }, 0);
    };
    try {
      if (isPlaceholder(tbody)) {
        if (isInited(table)) {
          try { destroyDt(table); } catch (_) { /* ignore */ }
        }
        return;
      }

      const html = tbody.innerHTML;
      if (isInited(table)) {
        try { destroyDt(table); } catch (_) { /* ignore */ }
        if (table.tBodies[0]) table.tBodies[0].innerHTML = html;
      }
      if (isInited(table)) return;

      const api = new DataTable(table, optionsFor(table));
      api.on('preDraw', () => { table.dataset.dtDrawing = '1'; });
      api.on('draw', () => {
        setTimeout(() => { delete table.dataset.dtDrawing; }, 0);
        document.dispatchEvent(new CustomEvent('pms-table-draw', { detail: { table: table } }));
      });
    } catch (_) {
      /* leave the plain table if this layout cannot be paged */
    } finally {
      release();
    }
  }

  function queue(table) {
    if (silence || !isListTable(table) || table.dataset.dtDrawing === '1') return;
    pending.add(table);
    clearTimeout(timer);
    timer = setTimeout(() => {
      const batch = Array.from(pending);
      pending.clear();
      batch.forEach(upgrade);
    }, 40);
  }

  function tableFromNode(node) {
    if (!node || node.nodeType !== 1) return null;
    if (node.tagName === 'TABLE') return node;
    if (node.tagName === 'TBODY' || node.tagName === 'THEAD') return node.closest('table');
    return node.closest ? node.closest('table') : null;
  }

  const observer = new MutationObserver((records) => {
    if (silence) return;
    records.forEach((record) => {
      const targetTable = tableFromNode(record.target);
      if (targetTable && targetTable.dataset.dtDrawing === '1') return;
      if (targetTable && isInited(targetTable)) {
        const tbodyChanged = record.target.tagName === 'TBODY'
          || (record.target.closest && record.target.closest('tbody'));
        if (tbodyChanged) queue(targetTable);
      } else if (targetTable) {
        queue(targetTable);
      }
      record.addedNodes.forEach((node) => {
        if (!node || node.nodeType !== 1) return;
        if (node.classList?.contains('dt-container') || node.classList?.contains('dataTables_wrapper')) return;
        const added = tableFromNode(node);
        if (added && !isInited(added)) queue(added);
        if (node.querySelectorAll) {
          node.querySelectorAll('table').forEach((nested) => {
            if (!isInited(nested)) queue(nested);
          });
        }
      });
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
  document.querySelectorAll('table').forEach((table) => queue(table));

  document.addEventListener('shown.bs.tab', () => {
    document.querySelectorAll('table').forEach((table) => {
      if (!isInited(table)) return;
      try { dtInstance(table)?.columns.adjust(); } catch (_) { /* ignore */ }
    });
  });
})();
