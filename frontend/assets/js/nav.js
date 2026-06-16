/**
 * Shared back navigation for all PMS pages.
 */
(function () {
  function goBack(fallback) {
    const ref = document.referrer;
    const sameSite = ref && ref.startsWith(window.location.origin);
    const notSelf = ref && ref !== window.location.href;

    if (sameSite && notSelf && window.history.length > 1) {
      window.history.back();
      return;
    }
    window.location.href = fallback || '/frontend/pages/login.html';
  }

  function injectBackButton(fallback) {
    if (document.querySelector('[data-pms-back-injected]')) {
      return;
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-secondary btn-sm btn-pms-back';
    btn.setAttribute('data-pms-back-injected', '1');
    btn.innerHTML = '← Back';
    btn.title = 'Go to previous page';
    btn.addEventListener('click', () => goBack(fallback));

    const navbar = document.querySelector('.navbar .container-fluid');
    if (navbar) {
      btn.classList.remove('btn-outline-secondary');
      btn.classList.add('btn-outline-light', 'me-2');
      navbar.insertBefore(btn, navbar.firstChild);
      return;
    }

    btn.classList.add('btn-pms-back-float');
    document.body.appendChild(btn);
  }

  function resolveFallback() {
    const explicit = document.body.getAttribute('data-pms-fallback');
    if (explicit) {
      return explicit;
    }
    const user = window.PMS && PMS.getUser ? PMS.getUser() : null;
    if (user && user.dashboard) {
      return user.dashboard;
    }
    return '/frontend/pages/login.html';
  }

  document.addEventListener('DOMContentLoaded', () => {
    injectBackButton(resolveFallback());
  });

  if (window.PMS) {
    window.PMS.goBack = goBack;
    window.PMS.injectBackButton = injectBackButton;
  }
})();
