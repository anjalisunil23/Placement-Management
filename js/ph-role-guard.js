(function () {
  // Hold the app invisible until shell + role panels are painted together.
  try { document.documentElement.classList.add('ph-booting'); } catch (_) { /* ignore */ }

  function looksLikeHod(designation, isHodFlag) {
    if (isHodFlag === true || isHodFlag === 1 || isHodFlag === '1') return true;
    var d = String(designation || '')
      .toUpperCase()
      .replace(/[,/;|]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (!d) return false;
    if (/\bH\.?\s*O\.?\s*D\.?\b/.test(d) || /^HOD\b/.test(d)) return true;
    if (/\bHEAD\s+OF\s+(THE\s+)?(DEPT\.?|DEPARTMENT)\b/.test(d)) return true;
    if (/\b(DEPT\.?|DEPARTMENT)\s+HEAD\b/.test(d)) return true;
    return false;
  }

  function readSessionRole() {
    try {
      var user = JSON.parse(localStorage.getItem('ph-user') || 'null');
      var role = (user && user.role) || localStorage.getItem('ph-role') || '';
      if (!role) return null;
      // HOD stays DB role=staff but must paint placement_officer panels.
      if ((role === 'staff' || !role) && user && looksLikeHod(user.designation, user.isHod)) {
        role = 'placement_officer';
      }
      return {
        role: role,
        employed: !!(user && user.isWorking),
      };
    } catch (_) {
      return null;
    }
  }

  var session = readSessionRole();
  if (!session) return;

  var root = document.documentElement;
  root.setAttribute('data-ph-role', session.role);
  if (session.role === 'alumni') {
    root.setAttribute('data-ph-alumni', session.employed ? 'employed' : 'seeking');
  }
})();
