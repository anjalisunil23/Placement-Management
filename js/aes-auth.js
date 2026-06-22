/**
 * AES institute SSO + email sign-in on the public portal.
 */
const PORTAL_AUTH_PAGE = 'public-stats.html';
const AES_LOGIN_API_KEY = '95872781e44306cdb4c9c9f16c060b63be079dcb';
const AES_LOGIN_SCRIPT = `https://login.aesajce.in/aes-login.js?api=${AES_LOGIN_API_KEY}`;

let portalLoginModal = null;
let aesHostRegistered = null;

function portalAuthUrl(next = '', autoLogin = true) {
  const params = new URLSearchParams();
  if (next) params.set('next', next);
  if (autoLogin) params.set('login', '1');
  const qs = params.toString();
  return qs ? `${PORTAL_AUTH_PAGE}?${qs}` : PORTAL_AUTH_PAGE;
}

function storeAesNextRedirect(next) {
  const raw = String(next || '').trim();
  if (!raw) return;
  document.cookie = 'ph-aes-next=' + encodeURIComponent(raw) + '; path=/; SameSite=Lax';
}

function showPortalLoginError(msg) {
  const el = document.getElementById('portalLoginError');
  if (!el) {
    if (typeof toast === 'function' && msg) toast(msg, 'error');
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

function showPortalAesWarning(msg) {
  const el = document.getElementById('portalAesWarn');
  if (!el) return;
  if (!msg) {
    el.style.display = 'none';
    el.textContent = '';
    return;
  }
  el.textContent = msg;
  el.style.display = '';
}

function getPortalLoginModal() {
  const node = document.getElementById('portalLoginModal');
  if (!node || typeof bootstrap === 'undefined') return null;
  if (!portalLoginModal) {
    portalLoginModal = bootstrap.Modal.getOrCreateInstance(node);
  }
  return portalLoginModal;
}

function openPortalLoginModal() {
  const params = new URLSearchParams(location.search);
  const aesErr = params.get('aes_error');
  if (aesErr) {
    showPortalLoginError(decodeURIComponent(aesErr.replace(/\+/g, ' ')));
  }
  if (aesHostRegistered === false) {
    showPortalAesWarning(
      'AES college login is not enabled for this site yet. Use your email and password below, or ask the placement cell to register this domain with AES.'
    );
  }
  getPortalLoginModal()?.show();
}

function wirePortalLoginTriggers() {
  document.querySelectorAll('.portal-login-trigger').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openPortalLoginModal();
    });
  });
}

function initPortalAesLogin() {
  const params = new URLSearchParams(location.search);

  document.querySelectorAll('.aes-dologin').forEach((btn) => {
    btn.addEventListener('click', () => {
      storeAesNextRedirect(params.get('next') || '');
      if (aesHostRegistered === false) {
        showPortalAesWarning(
          'AES sign-in is not available on this domain yet. Use email sign-in below.'
        );
        openPortalLoginModal();
      }
    });
  });

  if (params.get('login') === '1') {
    setTimeout(() => openPortalLoginModal(), 300);
  }
}

function wirePortalEmailLogin() {
  const form = document.getElementById('portalLoginForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    showPortalLoginError('');
    const data = Object.fromEntries(new FormData(form).entries());
    const next = new URLSearchParams(location.search).get('next') || '';
    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      if (typeof performServerLogin !== 'function') {
        showPortalLoginError('Sign-in is unavailable. Please refresh the page.');
        return;
      }
      const result = await performServerLogin(data.email, data.password, next);
      if (result.success) {
        if (typeof toast === 'function') toast('Welcome back!', 'success');
        const target = result.redirect.startsWith('/') ? result.redirect : '/' + result.redirect;
        window.location.replace(target);
        return;
      }
      showPortalLoginError(result.message || 'Sign-in failed');
    } finally {
      if (btn) btn.disabled = false;
    }
  });
}

async function probeAesHostRegistration() {
  try {
    const res = await fetch(AES_LOGIN_SCRIPT, { credentials: 'omit', cache: 'no-store' });
    const text = await res.text();
    if (text.includes('Unknown Host')) {
      aesHostRegistered = false;
      return false;
    }
    aesHostRegistered = true;
    return true;
  } catch (_) {
    aesHostRegistered = null;
    return null;
  }
}

async function initPortalSessionRedirect() {
  if (typeof Auth === 'undefined') return false;
  const params = new URLSearchParams(location.search);
  const next = params.get('next');
  Auth._sessionReady = false;
  const ok = await Auth.bootstrap();
  if (!ok) return false;
  if (next) {
    window.location.replace(Auth.resolveRedirect(next));
    return true;
  }
  document.querySelectorAll('.portal-login-trigger').forEach((btn) => {
    const home = Auth.homePage();
    btn.innerHTML = '<i class="bi bi-grid me-1"></i>Open portal';
    btn.classList.remove('portal-login-trigger');
    btn.addEventListener('click', () => {
      window.location.href = home.startsWith('/') ? home : '/' + home;
    });
  });
  return params.get('login') === '1';
}

function loadAesLoginScript() {
  if (document.querySelector('script[data-aes-login]')) return;
  const s = document.createElement('script');
  s.type = 'module';
  s.src = AES_LOGIN_SCRIPT;
  s.dataset.aesLogin = '1';
  document.head.appendChild(s);
}

document.addEventListener('DOMContentLoaded', async () => {
  wirePortalLoginTriggers();
  wirePortalEmailLogin();
  loadAesLoginScript();
  await probeAesHostRegistration();
  const skipAesPrompt = await initPortalSessionRedirect();
  if (!skipAesPrompt) initPortalAesLogin();
});
