/* ============================================================
   projects.js — the Projects tab in the Client Control Center.
   One tab holds both levels: a project list up top, and a task
   panel below driven by whichever project card is selected
   (client-side state only — this app has no router).
   Tenant-scoped server-side, same as the rest of the dashboard.
   ============================================================ */
(function () {
  const PROJECT_BADGE = { active: 'badge-ok', archived: 'badge-muted' };
  const TASK_BADGE = { todo: 'badge-muted', in_progress: 'badge-warn', done: 'badge-ok' };
  const TASK_LABEL = { todo: 'To do', in_progress: 'In progress', done: 'Done' };

  let projects = [];
  let selectedProjectId = null;
  let team = null; // lazy-loaded, cached

  // ---- project form ----
  const pForm = document.getElementById('project-form');
  const pEditingId = document.getElementById('project-editing-id');
  const pName = document.getElementById('project-name');
  const pDescription = document.getElementById('project-description');
  const pStatus = document.getElementById('project-status');
  const pMsg = document.getElementById('project-msg');
  const pSubmit = document.getElementById('project-submit');
  const pCancel = document.getElementById('project-cancel-edit');
  const pFormTitle = document.getElementById('project-form-title');

  function resetProjectForm() {
    pForm.reset();
    pEditingId.value = '';
    pStatus.value = 'active';
    pFormTitle.textContent = 'New project';
    pSubmit.textContent = 'Add project';
    pCancel.style.display = 'none';
    pMsg.className = 'form-msg';
    pMsg.textContent = '';
  }

  function startEditProject(p) {
    pEditingId.value = p.id;
    pName.value = p.name;
    pDescription.value = p.description;
    pStatus.value = p.status;
    pFormTitle.textContent = 'Edit project';
    pSubmit.textContent = 'Save changes';
    pCancel.style.display = 'inline-block';
    pName.focus();
  }

  async function loadProjects() {
    const { data } = await Api.get('/api/projects');
    projects = data.projects;

    document.getElementById('project-cards').innerHTML = projects.length
      ? projects.map((p) => `
        <div class="card project-card ${String(p.id) === String(selectedProjectId) ? 'selected' : ''}" data-select="${p.id}">
          <h3>${p.name}</h3>
          <p class="muted">${p.description || 'No description'}</p>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="badge ${PROJECT_BADGE[p.status] || 'badge-muted'}">${p.status}</span>
            <span class="muted">${p.task_count} task${p.task_count === 1 ? '' : 's'}</span>
          </div>
          <div style="display:flex;gap:6px;margin-top:12px">
            <button class="btn btn-ghost btn-sm" data-edit="${p.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-delete="${p.id}">Delete</button>
          </div>
        </div>`).join('')
      : '<p class="muted">No projects yet — create one above.</p>';

    document.querySelectorAll('.project-card').forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('button')) return;
        selectProject(card.dataset.select);
      });
    });

    document.querySelectorAll('#project-cards button[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const project = projects.find((p) => String(p.id) === btn.dataset.edit);
        if (project) startEditProject(project);
      });
    });

    document.querySelectorAll('#project-cards button[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this project and all its tasks?')) return;
        btn.disabled = true;
        try {
          await Api.post(`/api/projects/${btn.dataset.delete}/delete`);
          toast('Project deleted');
          if (pEditingId.value === btn.dataset.delete) resetProjectForm();
          if (String(selectedProjectId) === btn.dataset.delete) {
            selectedProjectId = null;
            document.getElementById('task-panel').style.display = 'none';
          }
          await loadProjects();
        } catch (err) {
          toast(err.message, true);
          btn.disabled = false;
        }
      });
    });
  }

  pForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    pMsg.className = 'form-msg';
    pSubmit.disabled = true;

    const payload = {
      name: pName.value.trim(),
      description: pDescription.value.trim(),
      status: pStatus.value,
    };

    try {
      const editingId = pEditingId.value;
      if (editingId) {
        await Api.post(`/api/projects/${editingId}`, payload);
        toast('Project updated');
      } else {
        await Api.post('/api/projects', payload);
        toast('Project added');
      }
      resetProjectForm();
      await loadProjects();
    } catch (err) {
      pMsg.className = 'form-msg error';
      pMsg.textContent = err.message;
    } finally {
      pSubmit.disabled = false;
    }
  });

  pCancel.addEventListener('click', resetProjectForm);

  // ---- task panel ----
  const tForm = document.getElementById('task-form');
  const tEditingId = document.getElementById('task-editing-id');
  const tTitle = document.getElementById('task-title');
  const tDescription = document.getElementById('task-description');
  const tAssignee = document.getElementById('task-assignee');
  const tDueDate = document.getElementById('task-due-date');
  const tStatus = document.getElementById('task-status');
  const tMsg = document.getElementById('task-msg');
  const tSubmit = document.getElementById('task-submit');
  const tCancel = document.getElementById('task-cancel-edit');

  function resetTaskForm() {
    tForm.reset();
    tEditingId.value = '';
    tStatus.value = 'todo';
    tSubmit.textContent = 'Add task';
    tCancel.style.display = 'none';
    tMsg.className = 'form-msg';
    tMsg.textContent = '';
  }

  function startEditTask(t) {
    tEditingId.value = t.id;
    tTitle.value = t.title;
    tDescription.value = t.description;
    tAssignee.value = t.assignee_user_id || '';
    tDueDate.value = t.due_date || '';
    tStatus.value = t.status;
    tSubmit.textContent = 'Save changes';
    tCancel.style.display = 'inline-block';
    tTitle.focus();
  }

  async function ensureTeamLoaded() {
    if (team !== null) return;
    const { data } = await Api.get('/api/team');
    team = data.users;
    tAssignee.innerHTML = '<option value="">Unassigned</option>' +
      team.map((u) => `<option value="${u.id}">${u.name}</option>`).join('');
  }

  async function selectProject(id) {
    selectedProjectId = id;
    document.querySelectorAll('.project-card').forEach((card) => {
      card.classList.toggle('selected', card.dataset.select === String(id));
    });
    const project = projects.find((p) => String(p.id) === String(id));
    document.getElementById('task-panel-project-name').textContent = project ? project.name : '';
    document.getElementById('task-panel').style.display = 'block';
    resetTaskForm();
    await ensureTeamLoaded();
    await loadTasks();
  }

  async function loadTasks() {
    if (!selectedProjectId) return;
    const { data } = await Api.get(`/api/projects/${selectedProjectId}/tasks`);
    const rows = data.tasks;

    document.getElementById('task-rows').innerHTML = rows.length
      ? rows.map((t) => `
        <tr>
          <td>${t.title}<br><span class="muted">${t.description || ''}</span></td>
          <td class="muted">${t.assignee_name || 'Unassigned'}</td>
          <td class="muted">${t.due_date || '—'}</td>
          <td><span class="badge ${TASK_BADGE[t.status] || 'badge-muted'}">${TASK_LABEL[t.status] || t.status}</span></td>
          <td style="display:flex;gap:6px">
            <button class="btn btn-ghost btn-sm" data-edit="${t.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-delete="${t.id}">Delete</button>
          </td>
        </tr>`).join('')
      : '<tr><td colspan="5" class="muted">No tasks yet.</td></tr>';

    document.querySelectorAll('#task-rows button[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const task = rows.find((t) => String(t.id) === btn.dataset.edit);
        if (task) startEditTask(task);
      });
    });

    document.querySelectorAll('#task-rows button[data-delete]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this task?')) return;
        btn.disabled = true;
        try {
          await Api.post(`/api/tasks/${btn.dataset.delete}/delete`);
          toast('Task deleted');
          if (tEditingId.value === btn.dataset.delete) resetTaskForm();
          await loadTasks();
          await loadProjects(); // refresh task_count on the card
        } catch (err) {
          toast(err.message, true);
          btn.disabled = false;
        }
      });
    });
  }

  tForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!selectedProjectId) return;
    tMsg.className = 'form-msg';
    tSubmit.disabled = true;

    const payload = {
      title: tTitle.value.trim(),
      description: tDescription.value.trim(),
      assignee_user_id: tAssignee.value,
      due_date: tDueDate.value,
      status: tStatus.value,
    };

    try {
      const editingId = tEditingId.value;
      if (editingId) {
        await Api.post(`/api/tasks/${editingId}`, payload);
        toast('Task updated');
      } else {
        await Api.post(`/api/projects/${selectedProjectId}/tasks`, payload);
        toast('Task added');
      }
      resetTaskForm();
      await loadTasks();
      await loadProjects(); // refresh task_count on the card
    } catch (err) {
      tMsg.className = 'form-msg error';
      tMsg.textContent = err.message;
    } finally {
      tSubmit.disabled = false;
    }
  });

  tCancel.addEventListener('click', resetTaskForm);

  document.querySelectorAll('.side-nav button').forEach((b) => {
    if (b.dataset.tab === 'projects') {
      b.addEventListener('click', loadProjects);
    }
  });
})();
