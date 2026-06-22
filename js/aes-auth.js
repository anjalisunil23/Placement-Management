/**
 * AES login gate for the public portal — sign in on every site entry.
 * Uses PORTAL_AUTH_PAGE / portalAuthUrl from api.js.
 */
let aesLoginModal = null;

function getAesLoginModal() {
  const node = document.getElementById('aesLoginModal');
  if (!node || typeof bootstrap === 'undefined') return null;
  if (!aesLoginModal) {
    aesLoginModal = bootstrap.Modal.getOrCreateInstance(node, {
      backdrop: 'static',
      keyboard: false,
    });
  }
  return aesLoginModal;
}

function showAesLoginError(msg) {
  const el = document.getElementById('aesLoginError');
  if (!el) {
    if (msg && typeof toast === 'function') toast(msg, 'error');
    return;
  }
  if (!msg) {
    el.style.display = 'none';
    el.textContent = '';
    return;
  }
  el.textContent = msg;
  el.style.display = '';
}

function storeAesNextFromUrl() {
  const next = new URLSearchParams(location.search).get('next') || '';
  if (!next) return;
  document.cookie = 'ph-aes-next=' + encodeURIComponent(next) + '; path=/; SameSite=Lax';
}

function openAesLoginModal() {
  showAesLoginError('');
  storeAesNextFromUrl();
  const modal = getAesLoginModal();
  if (!modal) {
    showAesLoginError('Sign-in dialog could not open. Please refresh the page.');
    return;
  }
  modal.show();
  setTimeout(() => document.getElementById('aesUsername')?.focus(), 300);
}

function postAesCallback(params) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '/callback.php';
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value === null || value === undefined) return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = Array.isArray(value) ? JSON.stringify(value) : String(value);
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}

async function submitAesLogin(username, password) {
  const btn = document.getElementById('aesLoginSubmit');
  if (btn) btn.disabled = true;
  showAesLoginError('');
  try {
    const res = await api('/aes/check-login', {
      method: 'POST',
      body: { username, password },
      skipAuthRedirect: true,
    });
    if (!res.success) {
      const aes = res.errors || {};
      const extra = aes.title ? `${aes.title}: ` : '';
      showAesLoginError(extra + (res.message || 'AES sign-in failed'));
      return;
    }
    postAesCallback(res.data || {});
  } catch (e) {
    showAesLoginError(e.message || 'Could not sign in');
  } finally {
    if (btn) btn.disabled = false;
  }
}

function wirePortalAesLoginButtons() {
  if (!window.__portalAesLoginBound) {
    window.__portalAesLoginBound = true;
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.portal-aes-login');
      if (!btn) return;
      e.preventDefault();
      openAesLoginModal();
    });
  }

  const form = document.getElementById('aesLoginForm');
  if (form && !form.dataset.aesBound) {
    form.dataset.aesBound = '1';
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      submitAesLogin(String(data.username || '').trim(), String(data.password || ''));
    });
  }
}

function wireLoggedInPortalButton() {
  document.querySelectorAll('.portal-aes-login').forEach((btn) => {
    const home = Auth.homePage();
    btn.innerHTML = '<i class="bi bi-grid me-1"></i>Open portal';
    btn.classList.remove('portal-aes-login');
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.location.href = home.startsWith('/') ? home : '/' + home;
    });
  });
}

function promptAesLoginGate() {
  const params = new URLSearchParams(location.search);
  const aesErr = params.get('aes_error');
  if (aesErr) {
    showAesLoginError(decodeURIComponent(aesErr.replace(/\+/g, ' ')));
  }
  wirePortalAesLoginButtons();
  setTimeout(() => openAesLoginModal(), aesErr ? 100 : 350);
}

async function bootPortalAesLogin() {
  const params = new URLSearchParams(location.search);
  const viewPublic = params.get('view') === 'public';
  const next = params.get('next');

  if (typeof Auth !== 'undefined') {
    Auth._sessionReady = false;
    const hasSession = await Auth.bootstrap();
    if (hasSession) {
      if (next) {
        window.location.replace(Auth.resolveRedirect(next));
        return;
      }
      if (!viewPublic) {
        const home = Auth.homePage();
        window.location.replace(home.startsWith('/') ? home : '/' + home);
        return;
      }
      wireLoggedInPortalButton();
      return;
    }
    Auth.clear();
  }

  promptAesLoginGate();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootPortalAesLogin);
} else {
  bootPortalAesLogin();
}

window.openAesLoginModal = openAesLoginModal;
