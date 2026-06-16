/**
 * PMS API client — handles JWT auth and REST calls.
 */
const PMS = (() => {
  const API_BASE = '/backend/api';

  function getToken() {
    return localStorage.getItem('pms_token');
  }

  function setToken(token) {
    if (token) localStorage.setItem('pms_token', token);
    else localStorage.removeItem('pms_token');
  }

  function getUser() {
    const raw = localStorage.getItem('pms_user');
    return raw ? JSON.parse(raw) : null;
  }

  function setUser(user) {
    if (user) localStorage.setItem('pms_user', JSON.stringify(user));
    else localStorage.removeItem('pms_user');
  }

  async function request(method, path, body = null, isForm = false) {
    const headers = {};
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const opts = { method, headers };
    if (body) {
      if (isForm) {
        opts.body = body;
      } else {
        headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }

    const res = await fetch(`${API_BASE}${path}`, opts);
    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
      const err = new Error(data.message || 'Request failed');
      err.status = res.status;
      err.errors = data.errors;
      throw err;
    }
    return data;
  }

  return {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    del: (path) => request('DELETE', path),
    upload: (path, formData) => request('POST', path, formData, true),
    getToken, setToken, getUser, setUser,
    logout: async () => {
      try { await request('POST', '/auth/logout'); } catch (_) {}
      setToken(null);
      setUser(null);
      window.location.href = '/frontend/pages/login.html';
    },
    requireAuth: (roles = []) => {
      const user = getUser();
      if (!user || !getToken()) {
        window.location.href = '/frontend/pages/login.html';
        return null;
      }
      if (roles.length && !roles.includes(user.role)) {
        window.location.href = user.dashboard || '/frontend/pages/login.html';
        return null;
      }
      return user;
    }
  };
})();
