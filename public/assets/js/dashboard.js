/* ============================================================
   dashboard.js — hydrates the Client Control Center.
   Every request is tenant-scoped server-side; this file never
   sends a tenant id, and could not read another company's data.
   ============================================================ */
(async function () {
  const user = await Api.session();
  if (!user) { window.location.href = '/login'; return; }
  if (user.role === 'super_admin') { window.location.href = '/admin'; return; }

  document.getElementById('hello').textContent = `Hi, ${user.name.split(' ')[0]}`;
  document.getElementById('tenant-label').textContent = user.email;

  // ---- tab switching ----
  const buttons = document.querySelectorAll('.side-nav button');
  buttons.forEach((b) => b.addEventListener('click', () => {
    buttons.forEach((x) => x.classList.remove('active'));
    b.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
    document.getElementById('tab-' + b.dataset.tab).classList.add('active');
    if (b.dataset.tab === 'subscription') loadSubscription();
  }));

  // ---- overview ----
  async function loadOverview() {
    const { data } = await Api.get('/api/dashboard/overview');
    document.getElementById('stat-plan').textContent = data.subscription.label;
    document.getElementById('plan-badge').textContent = data.subscription.label;
    document.getElementById('stat-status').textContent = data.subscription.status;
    document.getElementById('stat-usage').textContent =
      `${data.usage.used.toLocaleString()} / ${data.usage.limit.toLocaleString()}`;
    document.getElementById('usage-bar').style.width = data.usage.percent + '%';
    document.getElementById('usage-text').textContent =
      `${data.usage.percent}% of your monthly resource limit used.`;

    const rows = data.activity.map((a) => `
      <tr>
        <td class="muted">${new Date(a.created_at).toLocaleString()}</td>
        <td>${a.action}</td>
        <td class="muted">${a.resource || '—'}</td>
        <td>${a.quantity}</td>
      </tr>`).join('');
    document.getElementById('activity').innerHTML =
      rows || '<tr><td colspan="4" class="muted">No activity yet.</td></tr>';
  }

  document.getElementById('do-work').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      await Api.post('/api/dashboard/track', { action: 'api.call' });
      toast('Usage recorded');
      await loadOverview();
    } catch (err) {
      toast(err.message, true);
    } finally {
      e.target.disabled = false;
    }
  });

  // ---- subscription ----
  async function loadSubscription() {
    const { data } = await Api.get('/api/subscription');
    const p = data.plan;
    document.getElementById('plan-detail').innerHTML = `
      <div style="display:flex;gap:26px;flex-wrap:wrap">
        <div><div class="k muted">Plan</div><strong>${p.label}</strong></div>
        <div><div class="k muted">Price</div><strong>${money(p.price_cents)}/mo</strong></div>
        <div><div class="k muted">Seats</div><strong>${p.seats}</strong></div>
        <div><div class="k muted">Monthly limit</div><strong>${p.resource_limit.toLocaleString()}</strong></div>
        <div><div class="k muted">Renews</div><strong>${new Date(p.renews_at).toLocaleDateString()}</strong></div>
      </div>
      <div style="margin-top:12px">
        <span class="k muted">Active features</span><br>
        ${p.features.map((f) => `<span class="badge badge-muted" style="margin:3px 3px 0 0">${f}</span>`).join('')}
      </div>`;

    document.getElementById('catalog').innerHTML = data.catalog.map((t) => {
      const current = t.tier === p.tier;
      return `<div class="card price-card ${current ? 'popular' : ''}">
        ${current ? '<span class="tag">CURRENT</span>' : ''}
        <h3>${t.label}</h3>
        <div class="price">${money(t.price_cents)}<small>/mo</small></div>
        <ul class="feature-list">
          <li>${t.seats} seats</li>
          <li>${t.resource_limit.toLocaleString()} events / mo</li>
          ${t.features.slice(0, 3).map((f) => `<li>${f}</li>`).join('')}
        </ul>
        <button class="btn ${current ? 'btn-ghost' : 'btn-primary'}" data-tier="${t.tier}" ${current ? 'disabled' : ''}>
          ${current ? 'Current plan' : 'Switch to ' + t.label}
        </button>
      </div>`;
    }).join('');

    document.querySelectorAll('#catalog button[data-tier]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const r = await Api.post('/api/subscription/change', { tier: btn.dataset.tier });
          toast(r.message);
          await loadSubscription();
          await loadOverview();
        } catch (err) {
          toast(err.message, true);
          btn.disabled = false;
        }
      });
    });

    document.getElementById('invoices').innerHTML = data.invoices.length
      ? data.invoices.map((inv) => `
        <tr>
          <td>${inv.number}</td>
          <td class="muted">${new Date(inv.period_start).toLocaleDateString()} – ${new Date(inv.period_end).toLocaleDateString()}</td>
          <td>${money(inv.amount_cents)}</td>
          <td><span class="badge ${inv.status === 'paid' ? 'badge-ok' : 'badge-warn'}">${inv.status}</span></td>
        </tr>`).join('')
      : '<tr><td colspan="4" class="muted">No invoices yet — you are on the free plan.</td></tr>';
  }

  // ---- account ----
  document.getElementById('pw-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('pw-msg');
    msg.className = 'form-msg';
    try {
      await Api.post('/api/auth/password', {
        current_password: document.getElementById('cur-pw').value,
        new_password: document.getElementById('new-pw').value,
      });
      msg.className = 'form-msg ok';
      msg.textContent = 'Password updated.';
      e.target.reset();
    } catch (err) {
      msg.className = 'form-msg error';
      msg.textContent = err.message;
    }
  });

  document.getElementById('logout').addEventListener('click', async () => {
    await Api.post('/api/auth/logout');
    window.location.href = '/login';
  });

  await loadOverview();
})();
