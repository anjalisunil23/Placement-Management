/**
 * AES institute SSO on the public portal (login.aesajce.in modal).
 */
const PORTAL_AUTH_PAGE = 'public-stats.html';
const AES_LOGIN_API_KEY = '95872781e44306cdb4c9c9f16c060b63be079dcb';
const AES_LOGIN_SCRIPT = `https://login.aesajce.in/aes-login.js?api=${AES_LOGIN_API_KEY}`;

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

function initPortalAesLogin() {
  const params = new URLSearchParams(location.search);
  const aesErr = params.get('aes_error');
  if (aesErr) {
    const msg = decodeURIComponent(aesErr.replace(/\+/g, ' '));
    if (typeof toast === 'function') toast(msg, 'error');
  }

  document.querySelectorAll('.aes-dologin').forEach((btn) => {
    btn.addEventListener('click', () => {
      storeAesNextRedirect(params.get('next') || '');
    });
  });

  if (params.get('login') === '1') {
    const trigger = document.querySelector('.aes-dologin');
    if (trigger) {
      setTimeout(() => trigger.click(), 400);
    }
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
  document.querySelectorAll('.aes-dologin').forEach((btn) => {
    const home = Auth.homePage();
    btn.innerHTML = '<i class="bi bi-grid me-1"></i>Open portal';
    btn.classList.remove('aes-dologin');
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
  loadAesLoginScript();
  const skipAesPrompt = await initPortalSessionRedirect();
  if (!skipAesPrompt) initPortalAesLogin();
});
