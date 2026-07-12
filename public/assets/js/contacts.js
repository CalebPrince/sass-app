/* ============================================================
   contacts.js — the Contacts tab in the Client Control Center.
   Tenant-scoped server-side, same as the rest of the dashboard.
   ============================================================ */
(function () {
  const STATUS_BADGE = {
    lead: 'badge-muted',
    active: 'badge-warn',
    customer: 'badge-ok',
    inactive: 'badge-danger',
  };

  const form = document.getElementById('contact-form');
  const editingIdInput = document.getElementById('contact-editing-id');
  const nameInput = document.getElementById('contact-name');
  const emailInput = document.getElementById('contact-email');
  const phoneInput = document.getElementById('contact-phone');
  const companyInput = document.getElementById('contact-company');
  const statusInput = document.getElementById('contact-status');
  const notesInput = document.getElementById('contact-notes');
  const msg = document.getElementById('contact-msg');
  const submitBtn = document.getElementById('contact-submit');
  const cancelBtn = document.getElementById('contact-cancel-edit');
  const formTitle = document.getElementById('contact-form-title');

  function resetForm() {
    form.reset();
    editingIdInput.value = '';
    statusInput.value = 'lead';
    formTitle.textContent = 'Add contact';
    submitBtn.textContent = 'Add contact';
    cancelBtn.style.display = 'none';
    msg.className = 'form-msg';
    msg.textContent = '';
  }

  function startEdit(c) {
    editingIdInput.value = c.id;
    nameInput.value = c.name;
    emailInput.value = c.email;
    phoneInput.value = c.phone;
    companyInput.value = c.company;
    statusInput.value = c.status;
    notesInput.value = c.notes;
    formTitle.textContent = 'Edit contact';
    submitBtn.textContent = 'Save changes';
    cancelBtn.style.display = 'inline-block';
    nameInput.focus();
  }

  async function loadContacts() {
    const { data } = await Api.get('/api/contacts');
    const rows = data.contacts;
    document.getElementById('contact-rows').innerHTML = rows.length
      ? rows.map((c) => `
        <tr>
          <td>${c.name}<br><span class="muted">${c.email || '—'}</span></td>
          <td class="muted">${c.company || '—'}</td>
          <td><span class="badge ${STATUS_BADGE[c.status] || 'badge-muted'}">${c.status}</span></td>
          <td style="display:flex;gap:6px">
            <button class="btn btn-ghost btn-sm" data-edit="${c.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-delete="${c.id}">Delete</button>
          </td>
        </tr>`).join('')
      : '<tr><td colspan="4" class="muted">No contacts yet.</td></tr>';

    document.querySelectorAll('#contact-rows button[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const contact = rows.find((c) => String(c.id) === btn.dataset.edit);
        if (contact) startEdit(contact);
      });
    });

    document.querySelectorAll('#contact-rows button[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this contact?')) return;
        btn.disabled = true;
        try {
          await Api.post(`/api/contacts/${btn.dataset.delete}/delete`);
          toast('Contact deleted');
          if (editingIdInput.value === btn.dataset.delete) resetForm();
          await loadContacts();
        } catch (err) {
          toast(err.message, true);
          btn.disabled = false;
        }
      });
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.className = 'form-msg';
    submitBtn.disabled = true;

    const payload = {
      name: nameInput.value.trim(),
      email: emailInput.value.trim(),
      phone: phoneInput.value.trim(),
      company: companyInput.value.trim(),
      status: statusInput.value,
      notes: notesInput.value.trim(),
    };

    try {
      const editingId = editingIdInput.value;
      if (editingId) {
        await Api.post(`/api/contacts/${editingId}`, payload);
        toast('Contact updated');
      } else {
        await Api.post('/api/contacts', payload);
        toast('Contact added');
      }
      resetForm();
      await loadContacts();
    } catch (err) {
      msg.className = 'form-msg error';
      msg.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  cancelBtn.addEventListener('click', resetForm);

  document.querySelectorAll('.side-nav button').forEach((b) => {
    if (b.dataset.tab === 'contacts') {
      b.addEventListener('click', loadContacts);
    }
  });
})();
