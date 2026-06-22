/**
 * AES institute SSO on the public portal (login.aesajce.in).
 */
const PORTAL_AUTH_PAGE = 'public-stats.html';

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
  if (aesErr && typeof toast === 'function') {
    toast(decodeURIComponent(aesErr.replace(/\+/g, ' ')), 'error');
  }

  document.querySelectorAll('.aes-dologin').forEach((btn) => {
    btn.addEventListener('click', () => {
      storeAesNextRedirect(params.get('next') || '');
    });
  });

  if (params.get('login') === '1') {
    const trigger = document.querySelector('.aes-dologin');
    if (trigger) setTimeout(() => trigger.click(), 400);
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

document.addEventListener('DOMContentLoaded', async () => {
  const skipAesPrompt = await initPortalSessionRedirect();
  if (!skipAesPrompt) initPortalAesLogin();
});
