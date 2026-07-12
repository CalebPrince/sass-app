/* ============================================================
   admin.js — hydrates the Global Admin Control Center.
   All endpoints sit behind the super_admin guard server-side.
   ============================================================ */
(async function () {
  const user = await Api.session();
  if (!user) { window.location.href = '/login'; return; }
  if (user.role !== 'super_admin') { window.location.href = '/app'; return; }

  const TIERS = ['starter', 'pro', 'enterprise'];

  // ---- tabs ----
  const buttons = document.querySelectorAll('.side-nav button');
  buttons.forEach((b) => b.addEventListener('click', () => {
    buttons.forEach((x) => x.classList.remove('active'));
    b.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
    document.getElementById('tab-' + b.dataset.tab).classList.add('active');
    ({ metrics: loadMetrics, users: loadUsers, audit: loadAudit }[b.dataset.tab] || (() => {}))();
  }));

  // ---- metrics ----
  async function loadMetrics() {
    const { data } = await Api.get('/api/admin/metrics');
    document.getElementById('m-users').textContent = data.active_users.toLocaleString();
    document.getElementById('m-mrr').textContent = money(data.mrr_cents);
    document.getElementById('m-arr').textContent = money(data.arr_cents);
    document.getElementById('m-tenants').textContent = data.total_tenants.toLocaleString();
    document.getElementById('m-banned').textContent = data.banned_users;
    document.getElementById('m-events').textContent = data.health.events_today.toLocaleString();

    document.getElementById('m-tiers').innerHTML = Object.entries(data.tier_breakdown)
      .map(([tier, n]) => `<div style="display:flex;justify-content:space-between;padding:4px 0">
        <span class="muted">${tier}</span><strong>${n}</strong></div>`).join('');

    const h = data.health;
    const dot = (ok) => `<span class="badge ${ok ? 'badge-ok' : 'badge-danger'}">${ok ? 'OK' : 'OFF'}</span>`;
    document.getElementById('m-health').innerHTML = `
      <div style="display:flex;justify-content:space-between;padding:4px 0"><span class="muted">DB writable</span>${dot(h.db_writable)}</div>
      <div style="display:flex;justify-content:space-between;padding:4px 0"><span class="muted">Signups open</span>${dot(h.signups_open)}</div>
      <div style="display:flex;justify-content:space-between;padding:4px 0"><span class="muted">Maintenance</span>
        <span class="badge ${h.maintenance ? 'badge-warn' : 'badge-muted'}">${h.maintenance ? 'ON' : 'off'}</span></div>`;

    // prime settings toggles
    document.getElementById('set-maintenance').checked = h.maintenance;
    document.getElementById('set-signups').checked = h.signups_open;
  }

  // ---- users & overrides ----
  const tierSelect = (id, current) =>
    `<select data-tenant="${id}" class="input btn-sm" style="width:auto;padding:.3rem">
       ${TIERS.map((t) => `<option value="${t}" ${t === current ? 'selected' : ''}>${t}</option>`).join('')}
     </select>`;

  async function loadUsers() {
    const q = document.getElementById('search').value.trim();
    const { data } = await Api.get('/api/admin/users' + (q ? '?q=' + encodeURIComponent(q) : ''));
    document.getElementById('user-rows').innerHTML = data.users.length ? data.users.map((u) => {
      const banned = u.status === 'banned';
      return `<tr>
        <td>${u.name}<br><span class="muted">${u.email}</span></td>
        <td>${u.tenant_name}<br><span class="badge ${u.tenant_status === 'active' ? 'badge-ok' : 'badge-danger'}">${u.tenant_status}</span></td>
        <td>${u.role}</td>
        <td>${tierSelect(u.tenant_id, u.tier || 'starter')}</td>
        <td>
          <input class="input btn-sm" style="width:90px;padding:.3rem" type="number" value="${u.resource_limit || 0}" data-limit="${u.tenant_id}">
        </td>
        <td><span class="badge ${banned ? 'badge-danger' : 'badge-ok'}">${u.status}</span></td>
        <td style="display:flex;gap:6px">
          <button class="btn btn-danger btn-sm" data-ban="${u.id}" data-status="${banned ? 'active' : 'banned'}">${banned ? 'Unban' : 'Ban'}</button>
        </td>
      </tr>`;
    }).join('') : '<tr><td colspan="7" class="muted">No users found.</td></tr>';

    // ban / unban
    document.querySelectorAll('button[data-ban]').forEach((b) => b.addEventListener('click', async () => {
      try {
        const r = await Api.post(`/api/admin/users/${b.dataset.ban}/status`, { status: b.dataset.status });
        toast(r.message); loadUsers();
      } catch (e) { toast(e.message, true); }
    }));

    // tier override on change
    document.querySelectorAll('select[data-tenant]').forEach((s) => s.addEventListener('change', async () => {
      try {
        const r = await Api.post(`/api/admin/tenants/${s.dataset.tenant}/tier`, { tier: s.value });
        toast(r.message); loadUsers();
      } catch (e) { toast(e.message, true); }
    }));

    // resource-limit override on Enter / blur
    document.querySelectorAll('input[data-limit]').forEach((inp) => {
      const commit = async () => {
        try {
          const r = await Api.post(`/api/admin/tenants/${inp.dataset.limit}/limit`, { resource_limit: Number(inp.value) });
          toast(r.message);
        } catch (e) { toast(e.message, true); }
      };
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') commit(); });
      inp.addEventListener('blur', commit);
    });
  }

  document.getElementById('refresh').addEventListener('click', loadUsers);
  document.getElementById('search').addEventListener('input', () => {
    clearTimeout(window._st); window._st = setTimeout(loadUsers, 250);
  });

  // ---- settings ----
  document.getElementById('save-settings').addEventListener('click', async () => {
    try {
      const r = await Api.post('/api/admin/settings', {
        maintenance_mode: document.getElementById('set-maintenance').checked,
        signups_open: document.getElementById('set-signups').checked,
      });
      toast(r.message); loadMetrics();
    } catch (e) { toast(e.message, true); }
  });

  // ---- audit ----
  async function loadAudit() {
    const { data } = await Api.get('/api/admin/audit');
    document.getElementById('audit-rows').innerHTML = data.logs.length ? data.logs.map((l) => `
      <tr>
        <td class="muted">${new Date(l.created_at).toLocaleString()}</td>
        <td>${l.actor_email || '—'}</td>
        <td><span class="badge badge-muted">${l.action}</span></td>
        <td class="muted">${l.target_type}${l.target_id ? ' #' + l.target_id : ''}</td>
        <td class="muted" style="max-width:280px;white-space:normal">${l.detail}</td>
      </tr>`).join('') : '<tr><td colspan="5" class="muted">No audit entries yet.</td></tr>';
  }

  document.getElementById('logout').addEventListener('click', async () => {
    await Api.post('/api/auth/logout');
    window.location.href = '/login';
  });

  await loadMetrics();
})();
