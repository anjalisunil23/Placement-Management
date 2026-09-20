/**
 * AES login modal for the public portal.
 * Proxies credentials through /aes/check-login, then POSTs to /callback.php.
 */
let aesLoginModal = null;

function getAesLoginModal() {
  const node = document.getElementById('aesLoginModal');
  if (!node || typeof bootstrap === 'undefined') return null;
  if (!aesLoginModal) {
    aesLoginModal = bootstrap.Modal.getOrCreateInstance(node, {
      backdrop: true,
      keyboard: true,
    });
  }
  return aesLoginModal;
}

function showAesLoginError(msg) {
  const el = document.getElementById('aesLoginFormError');
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
  const next = typeof readAuthNextTarget === 'function'
    ? readAuthNextTarget()
    : (new URLSearchParams(location.search).get('next') || '');
  if (!next) return;
  if (typeof persistAuthNextTarget === 'function') {
    persistAuthNextTarget(next);
    return;
  }
  document.cookie = `ph_auth_next=${encodeURIComponent(next)}; path=/; max-age=600; SameSite=Lax`;
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

function flattenAesCallbackPayload(params) {
  const payload = { ...(params && typeof params === 'object' ? params : {}) };
  if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
    Object.assign(payload, payload.data);
  }
  return payload;
}

function postAesCallback(params) {
  const payload = flattenAesCallbackPayload(params);
  if (!payload.checksum && !payload.token && !payload.auth_token) {
    showAesLoginError('AES did not return a login token. Please try again.');
    return;
  }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '/callback.php';
  Object.entries(payload).forEach(([key, value]) => {
    if (value === null || value === undefined) return;
    if (typeof value === 'object') return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = String(value);
    form.appendChild(input);
  });
  const next = typeof readAuthNextTarget === 'function' ? readAuthNextTarget() : '';
  if (next) {
    const nextInput = document.createElement('input');
    nextInput.type = 'hidden';
    nextInput.name = 'ph_auth_next';
    nextInput.value = next;
    form.appendChild(nextInput);
  }
  document.body.appendChild(form);
  form.submit();
}

async function submitAesCheckLogin(dataOrPromise, isSocial) {
  const btn = document.getElementById('aesLoginSubmit');
  const socialBtns = document.querySelectorAll('.aes-social-btn');
  if (btn) btn.disabled = true;
  socialBtns.forEach((b) => { b.disabled = true; });
  showAesLoginError('');
  try {
    const data = dataOrPromise instanceof Promise ? await dataOrPromise : dataOrPromise;
    const res = await api('/aes/check-login', {
      method: 'POST',
      body: data,
      skipAuthRedirect: true,
      skipAuthRetry: true,
    });
    if (!res.success) {
      const aes = res.errors || {};
      const extra = aes.title ? `${aes.title}: ` : '';
      showAesLoginError(extra + (res.message || 'AES sign-in failed'));
      return;
    }
    postAesCallback(res.data || {});
  } catch (e) {
    const msg = isSocial && e.code === 'auth/popup-closed-by-user'
      ? ''
      : (e.message || 'Could not sign in');
    if (msg) showAesLoginError(msg);
  } finally {
    if (btn) btn.disabled = false;
    socialBtns.forEach((b) => { b.disabled = false; });
  }
}

async function submitAesLogin(username, password) {
  return submitAesCheckLogin({ username, password }, false);
}

function wirePortalAesLoginButtons() {
  if (!window.__portalAesLoginBound) {
    window.__portalAesLoginBound = true;
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.portal-aes-login, .aes-dologin');
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

  const bindSocial = (id, fnName) => {
    const el = document.getElementById(id);
    if (!el || el.dataset.aesBound) return;
    el.dataset.aesBound = '1';
    el.addEventListener('click', () => {
      const fn = window[fnName];
      if (typeof fn === 'function') fn();
      else showAesLoginError('Social sign-in is still loading. Please try again.');
    });
  };
  bindSocial('aesBtnGoogle', 'aesLoginWithGoogle');
  bindSocial('aesBtnMicrosoft', 'aesLoginWithMicrosoft');
  bindSocial('aesBtnWhatsapp', 'aesLoginWithWhatsapp');

  const waClose = document.getElementById('aesWaOtpClose');
  if (waClose && !waClose.dataset.aesBound) {
    waClose.dataset.aesBound = '1';
    waClose.addEventListener('click', () => {
      const panel = document.getElementById('aesWaOtpPanel');
      if (panel) panel.hidden = true;
    });
  }
}

function wireLoggedInPortalButton() {
  document.querySelectorAll('.portal-aes-login, .aes-dologin').forEach((btn) => {
    const home = typeof Auth !== 'undefined' ? Auth.homePage() : 'dashboard.html';
    btn.innerHTML = '<i class="bi bi-grid me-1" aria-hidden="true"></i><span>Open portal</span>';
    btn.classList.remove('portal-aes-login', 'aes-dologin');
    btn.classList.add('portal-open-home');
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.location.href = home.startsWith('/') ? home : '/' + home;
    });
  });
}

async function bootPortalAesLogin() {
  const params = new URLSearchParams(location.search);
  const next = params.get('next');
  const aesErr = params.get('aes_error');

  wirePortalAesLoginButtons();
  storeAesNextFromUrl();

  if (typeof Auth !== 'undefined') {
    try {
      const hasSession = await Auth.bootstrap({ soft: true });
      if (hasSession) {
        if (next) {
          window.location.replace(Auth.resolveRedirect(next));
          return;
        }
        wireLoggedInPortalButton();
        return;
      }
    } catch (_) { /* stay on login UI */ }
  }

  if (aesErr) {
    openAesLoginModal();
    showAesLoginError(decodeURIComponent(aesErr.replace(/\+/g, ' ')));
  } else if (next) {
    // Returning from a protected page — open sign-in promptly.
    setTimeout(() => openAesLoginModal(), 200);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootPortalAesLogin);
} else {
  bootPortalAesLogin();
}

window.openAesLoginModal = openAesLoginModal;
window.showAesLoginError = showAesLoginError;
window.submitAesCheckLogin = submitAesCheckLogin;
