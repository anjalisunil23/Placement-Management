/**
 * AES institute SSO on the public portal (login.aesajce.in).
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

function showAesSetupError(message) {
  if (typeof toast === 'function') {
    toast(message, 'error');
    return;
  }
  alert(message);
}

async function ensureAesLoginScript() {
  const existing = document.querySelector('script[data-aes-login]');
  if (existing && existing.dataset.aesReady === '1') {
    return existing.dataset.aesOk === '1';
  }

  try {
    const res = await fetch(AES_LOGIN_SCRIPT, { credentials: 'omit', cache: 'no-store' });
    const text = await res.text();
    if (text.includes('Unknown Host')) {
      const host = location.hostname;
      showAesSetupError(
        `AES login is not enabled for ${host} yet. Ask the AES/IT team to register this domain and callback URL (https://${host}/callback.php) for API key ${AES_LOGIN_API_KEY}.`
      );
      if (existing) existing.dataset.aesReady = '1';
      if (existing) existing.dataset.aesOk = '0';
      return false;
    }
    if (text.includes('API Key not provided or invalid')) {
      showAesSetupError('AES login API key is invalid on this site.');
      return false;
    }
    if (!existing) {
      const s = document.createElement('script');
      s.type = 'module';
      s.src = AES_LOGIN_SCRIPT;
      s.dataset.aesLogin = '1';
      document.head.appendChild(s);
    }
    if (existing) {
      existing.dataset.aesReady = '1';
      existing.dataset.aesOk = '1';
    }
    return true;
  } catch (_) {
    showAesSetupError('Could not load AES login. Check your internet connection and try again.');
    return false;
  }
}

function initPortalAesLogin() {
  const params = new URLSearchParams(location.search);
  const aesErr = params.get('aes_error');
  if (aesErr) {
    showAesSetupError(decodeURIComponent(aesErr.replace(/\+/g, ' ')));
  }

  document.querySelectorAll('.aes-dologin').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      storeAesNextRedirect(params.get('next') || '');
      const ok = await ensureAesLoginScript();
      if (!ok) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }, true);
  });

  if (params.get('login') === '1') {
    ensureAesLoginScript().then((ok) => {
      if (!ok) return;
      const trigger = document.querySelector('.aes-dologin');
      if (trigger) setTimeout(() => trigger.click(), 400);
    });
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
  await ensureAesLoginScript();
  const skipAesPrompt = await initPortalSessionRedirect();
  if (!skipAesPrompt) initPortalAesLogin();
});
