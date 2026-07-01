/* --------------------------------------------------------------------------
   Eigenständige Kundenverwaltung

   Die Seite liest Projekt-, Board- und Aufgabenstände aus PolarisNova, speichert
   Kundendaten aber getrennt über api/customers.php in data/customers.json.
   -------------------------------------------------------------------------- */

const customerState = {
  user: null,
  meta: {},
  customers: [],
  projects: [],
  canCreateCustomer: false
};

let initialProjectFilterApplied = false;

const $ = (selector) => document.querySelector(selector);

function esc(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

async function customerApi(action, data = null){
  const options = data === null ? {} : {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(data)
  };

  const response = await fetch(`api/customers.php?action=${encodeURIComponent(action)}`, options);
  const payload = await response.json().catch(() => ({ok:false, error:'Ungültige Serverantwort'}));

  if(!payload.ok){
    throw new Error(payload.error || 'Unbekannter Fehler');
  }

  return payload;
}

function statusLabel(status){
  return {
    lead: 'Lead',
    active: 'Aktiv',
    paused: 'Pausiert',
    archived: 'Archiviert'
  }[status] || status || 'Lead';
}

function typeLabel(type){
  return {
    customer: 'Kunde',
    prospect: 'Interessent',
    partner: 'Partner',
    internal: 'Intern'
  }[type] || type || 'Kunde';
}

function projectById(id){
  return customerState.projects.find(project => Number(project.id) === Number(id));
}

function projectNameById(id){
  const project = projectById(id);
  return project ? project.name : `Projekt #${id}`;
}

function firstBoardForProject(project){
  return (project?.boards || [])[0] || null;
}

function kanbanBoardUrl(project){
  const board = firstBoardForProject(project);
  return board ? `index.php?board_id=${encodeURIComponent(board.id)}` : 'index.php';
}

function applyInitialProjectFilter(){
  if (initialProjectFilterApplied) return;
  initialProjectFilterApplied = true;

  const projectId = Number(new URLSearchParams(window.location.search).get('project_id') || 0);
  const filter = $('#customerProjectFilter');

  if (projectId > 0 && filter && projectById(projectId)) {
    filter.value = String(projectId);
  }
}

function selectedProjectIds(){
  return [...document.querySelectorAll('#customerProjectChecks input[type="checkbox"]:checked')]
    .map(input => Number(input.value))
    .filter(Boolean);
}

function setModalOpen(id, open){
  const modal = document.getElementById(id);
  if(!modal) return;
  modal.classList.toggle('open', open);
  modal.setAttribute('aria-hidden', open ? 'false' : 'true');
}

function renderStats(){
  const customers = customerState.customers;
  const active = customers.filter(customer => customer.status === 'active').length;
  const leads = customers.filter(customer => customer.status === 'lead').length;
  const paused = customers.filter(customer => customer.status === 'paused').length;
  const archived = customers.filter(customer => customer.status === 'archived').length;

  const orphan = customers.filter(customer => customer.has_orphan_projects).length;

  $('#customerStats').innerHTML = `
    <div class="customer-stat-grid">
      <span class="customer-stat">${customers.length} gesamt</span>
      <span class="customer-stat">${active} aktiv</span>
      <span class="customer-stat">${leads} Leads</span>
      <span class="customer-stat">${paused} pausiert</span>
      <span class="customer-stat">${archived} archiviert</span>
      ${orphan ? `<span class="customer-stat">${orphan} ohne Projekt</span>` : ''}
    </div>
  `;
}

function renderProjectFilter(){
  const filter = $('#customerProjectFilter');
  const current = filter.value;

  filter.innerHTML = '<option value="">Alle Projekte</option>' + customerState.projects.map(project => `
    <option value="${project.id}">${esc(project.name)}</option>
  `).join('');

  filter.value = current;
}

function renderProjectChecks(selectedIds = []){
  const selected = new Set(selectedIds.map(Number));
  const box = $('#customerProjectChecks');

  if(!customerState.projects.length){
    box.innerHTML = '<div class="muted">Keine sichtbaren PolarisNova-Projekte vorhanden.</div>';
    return;
  }

  box.innerHTML = customerState.projects.map(project => {
    const canUse = customerState.user?.role === 'admin' || project.is_responsible;
    const disabled = canUse ? '' : 'disabled';
    const checked = selected.has(Number(project.id)) ? 'checked' : '';
    const hint = project.is_responsible ? 'Projektverantwortung' : `Verantwortlich: ${esc(project.responsible_name || '—')}`;

    return `
      <label class="project-check">
        <input type="checkbox" value="${project.id}" ${checked} ${disabled}>
        <span><b>${esc(project.name)}</b><br><small class="muted">${hint}</small></span>
      </label>
    `;
  }).join('');
}

function renderProjectSummary(project){
  const counts = project.task_counts || {};
  const boards = (project.boards || []).map(board => board.name).join(', ') || '—';
  const members = (project.members || []).join(', ') || '—';
  const board = firstBoardForProject(project);

  return `
    <article class="project-summary-card">
      <strong>${esc(project.name)}</strong>
      <p class="muted">Board: ${esc(boards)}</p>
      <p class="muted">Verantwortlich: ${esc(project.responsible_name || '—')}</p>
      <p class="muted">Mitarbeiter: ${esc(members)}</p>
      <div class="task-counts">
        <span class="task-count">${counts.all || 0} Aufgaben</span>
        <span class="task-count">${counts.open || 0} offen</span>
        <span class="task-count">${counts.in_progress || 0} in Arbeit</span>
        <span class="task-count">${counts.done || 0} erledigt</span>
      </div>
      ${board ? `<p><a class="ghost customer-link-button" href="${kanbanBoardUrl(project)}">Board im Kanban öffnen</a></p>` : ''}
    </article>
  `;
}

function renderProjectSummaries(){
  const grid = $('#projectSummaryGrid');

  if(!customerState.projects.length){
    grid.innerHTML = '<div class="empty-state">Keine sichtbaren PolarisNova-Projekte vorhanden.</div>';
    return;
  }

  grid.innerHTML = customerState.projects.map(renderProjectSummary).join('');
}

function customerMatchesFilters(customer){
  const search = ($('#customerSearch').value || '').trim().toLowerCase();
  const status = $('#customerStatusFilter').value;
  const projectId = Number($('#customerProjectFilter').value || 0);

  if(status && customer.status !== status){
    return false;
  }

  const projectIds = (customer.project_ids || []).map(Number);
  if(projectId && !projectIds.includes(projectId)){
    return false;
  }

  if(!search){
    return true;
  }

  const projectNames = projectIds.map(projectNameById).join(' ');
  const haystack = [
    customer.company,
    customer.contact_name,
    customer.email,
    customer.phone,
    customer.website,
    customer.city,
    customer.address,
    customer.source,
    customer.notes,
    projectNames
  ].join(' ').toLowerCase();

  return haystack.includes(search);
}

function renderLinkedProjectBox(projectId){
  const project = projectById(projectId);

  if(!project){
    return `
      <div class="customer-project-box">
        <strong>Projekt #${projectId}</strong>
        <p class="muted">Nicht mehr sichtbar oder nicht mehr vorhanden.</p>
      </div>
    `;
  }

  const counts = project.task_counts || {};
  const board = firstBoardForProject(project);
  return `
    <div class="customer-project-box">
      <strong>${esc(project.name)}</strong>
      <p class="muted">Verantwortlich: ${esc(project.responsible_name || '—')}</p>
      <div class="task-counts">
        <span class="task-count">${counts.all || 0} Aufgaben</span>
        <span class="task-count">${counts.open || 0} offen</span>
        <span class="task-count">${counts.in_progress || 0} in Arbeit</span>
        <span class="task-count">${counts.done || 0} erledigt</span>
      </div>
      ${board ? `<a class="ghost customer-link-button" href="${kanbanBoardUrl(project)}">Board öffnen</a>` : ''}
    </div>
  `;
}

function renderCustomerCard(customer){
  const projectIds = (customer.project_ids || []).map(Number);
  const contact = [customer.contact_name, customer.email, customer.phone].filter(Boolean);
  const location = [customer.address, customer.city].filter(Boolean).join(', ');

  return `
    <article class="customer-card" data-id="${customer.id}">
      <div class="customer-card-head">
        <div>
          <h2>${esc(customer.company)}</h2>
          <p><span class="status-badge ${esc(customer.status)}">${esc(statusLabel(customer.status))}</span> <span class="pill">${esc(typeLabel(customer.type))}</span></p>
        </div>
        ${customer.can_manage ? `
          <div class="customer-card-actions">
            <button type="button" class="ghost" data-edit-customer="${customer.id}">Bearbeiten</button>
            <button type="button" class="danger" data-delete-customer="${customer.id}">Löschen</button>
          </div>
        ` : ''}
      </div>

      <div class="customer-contact-line">
        ${contact.length ? contact.map(item => `<span>${esc(item)}</span>`).join('') : '<span class="muted">Keine Kontaktdaten hinterlegt</span>'}
        ${customer.website ? `<span>${esc(customer.website)}</span>` : ''}
        ${location ? `<span class="muted">${esc(location)}</span>` : ''}
      </div>

      ${customer.source ? `<p class="muted">Quelle: ${esc(customer.source)}</p>` : ''}
      ${customer.notes ? `<p>${esc(customer.notes)}</p>` : ''}

      <div class="customer-project-list">
        ${projectIds.length ? projectIds.map(renderLinkedProjectBox).join('') : `
          <div class="customer-project-box ${customer.has_orphan_projects ? 'warning' : ''}">
            <strong>Keine Projektzuordnung</strong>
            <p class="muted">${esc(customer.has_orphan_projects ? (customer.project_warning || 'Eine frühere Projektzuordnung ist nicht mehr vorhanden.') : 'Nur Admins sehen unzugeordnete Kunden.')}</p>
          </div>
        `}
      </div>

      <small class="muted">Aktualisiert: ${esc(customer.updated_at || '—')}</small>
    </article>
  `;
}

function renderCustomers(){
  const grid = $('#customerGrid');
  const customers = customerState.customers.filter(customerMatchesFilters);

  if(!customers.length){
    grid.innerHTML = '<div class="empty-state">Keine Kunden für die aktuelle Auswahl gefunden.</div>';
    return;
  }

  grid.innerHTML = customers.map(renderCustomerCard).join('');
}

function resetCustomerForm(){
  $('#customerId').value = '';
  $('#customerCompany').value = '';
  $('#customerContact').value = '';
  $('#customerEmail').value = '';
  $('#customerPhone').value = '';
  $('#customerWebsite').value = '';
  $('#customerCity').value = '';
  $('#customerAddress').value = '';
  $('#customerType').value = 'customer';
  $('#customerStatus').value = 'lead';
  $('#customerSource').value = '';
  $('#customerNotes').value = '';
  renderProjectChecks([]);
}

function openCustomerForm(customer = null){
  resetCustomerForm();

  if(customer){
    $('#customerModalTitle').textContent = 'Kunde bearbeiten';
    $('#customerId').value = customer.id;
    $('#customerCompany').value = customer.company || '';
    $('#customerContact').value = customer.contact_name || '';
    $('#customerEmail').value = customer.email || '';
    $('#customerPhone').value = customer.phone || '';
    $('#customerWebsite').value = customer.website || '';
    $('#customerCity').value = customer.city || '';
    $('#customerAddress').value = customer.address || '';
    $('#customerType').value = customer.type || 'customer';
    $('#customerStatus').value = customer.status || 'lead';
    $('#customerSource').value = customer.source || '';
    $('#customerNotes').value = customer.notes || '';
    renderProjectChecks(customer.project_ids || []);
  } else {
    $('#customerModalTitle').textContent = 'Kunde anlegen';
  }

  setModalOpen('customerModal', true);
}

async function saveCustomer(){
  const data = {
    id: Number($('#customerId').value || 0),
    company: $('#customerCompany').value,
    contact_name: $('#customerContact').value,
    email: $('#customerEmail').value,
    phone: $('#customerPhone').value,
    website: $('#customerWebsite').value,
    city: $('#customerCity').value,
    address: $('#customerAddress').value,
    type: $('#customerType').value,
    status: $('#customerStatus').value,
    source: $('#customerSource').value,
    notes: $('#customerNotes').value,
    project_ids: selectedProjectIds()
  };

  try{
    await customerApi('save_customer', data);
    setModalOpen('customerModal', false);
    await bootstrapCustomers();
  }catch(error){
    alert(error.message);
  }
}

async function deleteCustomer(id){
  if(!confirm('Diesen Kunden wirklich löschen?')){
    return;
  }

  try{
    await customerApi('delete_customer', {id});
    await bootstrapCustomers();
  }catch(error){
    alert(error.message);
  }
}

function bindEvents(){
  $('#newCustomerBtn').addEventListener('click', () => openCustomerForm());
  $('#saveCustomerBtn').addEventListener('click', saveCustomer);

  ['customerSearch','customerStatusFilter','customerProjectFilter'].forEach(id => {
    document.getElementById(id).addEventListener('input', renderCustomers);
    document.getElementById(id).addEventListener('change', renderCustomers);
  });

  document.addEventListener('click', event => {
    const closeId = event.target?.dataset?.close;
    if(closeId){
      setModalOpen(closeId, false);
    }

    const editId = event.target?.dataset?.editCustomer;
    if(editId){
      const customer = customerState.customers.find(item => Number(item.id) === Number(editId));
      if(customer){
        openCustomerForm(customer);
      }
    }

    const deleteId = event.target?.dataset?.deleteCustomer;
    if(deleteId){
      deleteCustomer(Number(deleteId));
    }
  });
}

async function bootstrapCustomers(){
  const payload = await customerApi('bootstrap');
  customerState.user = payload.data.user;
  customerState.meta = payload.data.meta || {};
  customerState.customers = payload.data.customers || [];
  customerState.projects = payload.data.projects || [];
  customerState.canCreateCustomer = !!payload.data.can_create_customer;

  $('#newCustomerBtn').hidden = !customerState.canCreateCustomer;
  renderStats();
  renderProjectFilter();
  applyInitialProjectFilter();
  renderProjectSummaries();
  renderCustomers();
}

bindEvents();
bootstrapCustomers().catch(error => {
  $('#customerGrid').innerHTML = `<div class="alert">${esc(error.message)}</div>`;
});
