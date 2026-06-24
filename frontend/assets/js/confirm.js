function ensurePmsConfirmModal() {
  if (document.getElementById('phConfirmModal')) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = `
<div class="modal fade" id="phConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="phConfirmTitle">Confirm action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><p class="mb-0" id="phConfirmMessage"></p></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="phConfirmCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="phConfirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>`;
  document.body.appendChild(wrap.firstElementChild);
}

function confirmAction(opts) {
  const options = typeof opts === 'string' ? { message: opts } : (opts || {});
  ensurePmsConfirmModal();
  return new Promise((resolve) => {
    const modalEl = document.getElementById('phConfirmModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('phConfirmTitle').textContent = options.title || 'Confirm action';
    document.getElementById('phConfirmMessage').textContent = options.message || 'Are you sure you want to continue?';
    const okBtn = document.getElementById('phConfirmOk');
    const cancelBtn = document.getElementById('phConfirmCancel');
    okBtn.textContent = options.confirmText || 'Confirm';
    cancelBtn.textContent = options.cancelText || 'Cancel';
    okBtn.className = `btn btn-${options.variant || 'primary'}`;
    let settled = false;
    const finish = (value) => { if (!settled) { settled = true; resolve(value); } };
    const onOk = () => { modal.hide(); finish(true); };
    const onHidden = () => {
      okBtn.removeEventListener('click', onOk);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      if (!settled) finish(false);
    };
    okBtn.addEventListener('click', onOk);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    modal.show();
  });
}

async function confirmThen(e, opts, handler) {
  if (e?.preventDefault) e.preventDefault();
  if (!(await confirmAction(opts))) return;
  await handler();
}
