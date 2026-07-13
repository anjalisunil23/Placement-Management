(function () {
  function readSessionRole() {
    try {
      var user = JSON.parse(localStorage.getItem('ph-user') || 'null');
      var role = (user && user.role) || localStorage.getItem('ph-role') || '';
      if (!role) return null;
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
