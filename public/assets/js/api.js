/* ============================================================
   api.js — the single fetch wrapper every page uses.
   Handles JSON, the CSRF header, and 401 redirects uniformly.
   ============================================================ */
const Api = (() => {
  let csrf = null;

  async function request(method, path, body) {
    const headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf;

    const res = await fetch(path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    let json = {};
    try { json = await res.json(); } catch (_) {}

    if (res.status === 401 && !path.endsWith('/auth/me')) {
      window.location.href = '/login';
      throw new Error('unauthorized');
    }
    if (!res.ok) {
      const err = new Error(json.message || `Request failed (${res.status})`);
      err.status = res.status;
      err.payload = json;
      throw err;
    }
    return json;
  }

  return {
    setCsrf: (t) => { csrf = t; },
    get: (p) => request('GET', p),
    post: (p, b) => request('POST', p, b ?? {}),
    /** Load identity + CSRF token; returns null if not authenticated. */
    async session() {
      try {
        const r = await request('GET', '/api/auth/me');
        csrf = r.data.csrf;
        return r.data.user;
      } catch (_) {
        return null;
      }
    },
  };
})();

/* Tiny toast helper shared by the app + admin shells. */
function toast(message, isError = false) {
  let el = document.querySelector('.toast');
  if (!el) {
    el = document.createElement('div');
    el.className = 'toast';
    document.body.appendChild(el);
  }
  el.textContent = message;
  el.classList.toggle('err', isError);
  el.classList.add('show');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove('show'), 2600);
}

function money(cents) {
  return '$' + (cents / 100).toLocaleString(undefined, { minimumFractionDigits: cents % 100 ? 2 : 0 });
}
