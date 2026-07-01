/**
 * Frontend-Logik für Kanban Light PolarisNova.
 *
 * Neue Projekt-/Board-Ebene:
 * - Nach dem Login wird zuerst eine Projektübersicht angezeigt.
 * - Admins sehen alle Projekte/Boards und können Mitarbeiter zuordnen.
 * - Mitarbeiter sehen nur Projekte, denen sie über project_members zugeordnet sind.
 * - Nach Klick auf ein Projekt wird die vorhandene Kanban-Ansicht mit den
 *   dazugehörigen Spalten, Aufgaben, Kommentaren, Zeiten und Historien geladen.
 */

// -----------------------------------------------------------------------------
// Globale Zustände und kleine DOM-Helfer
// -----------------------------------------------------------------------------

const API = 'api/api.php?action=';

const APP_VERSION_LABEL = window.APP_VERSION_LABEL || 'Weiterentwicklung v1.8.4';
const APP_LAST_UPDATE = window.APP_LAST_UPDATE || '24.06.2026';
const APP_VERSION_NOTE = window.APP_VERSION_NOTE || 'UI-State-Fix und erweiterte Zeiterfassungs-/Abrechnungsreports.';

let state = {
  meta: {},
  users: [],
  projects: [],
  boards: [],
  project_members: [],
  columns: [],
  tasks: [],
  comments: [],
  time_entries: [],
  events: [],
  history: []
};

let currentBoardId = null;
let currentTaskId = null;
let taskRefreshTimer = null;
let taskRefreshBusy = false;
let lastTaskSnapshot = '';
let lastMysqlRestorePreview = null;

const $ = selector => document.querySelector(selector);
const $$ = selector => [...document.querySelectorAll(selector)];
const esc = value => String(value ?? '').replace(/[&<>"']/g, match => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#039;'
}[match]));

function setHiddenSafe(selector, value) {
  const el = document.querySelector(selector);
  if (!el) return;

  // Doppelte Absicherung:
  // Das HTML-Attribut hidden alleine kann durch eigene display-Regeln
  // wie .layout{display:grid} oder .project-overview{display:grid}
  // optisch übersteuert werden. Darum zusätzlich eine CSS-Klasse setzen.
  el.hidden = value;
  el.classList.toggle('is-hidden', Boolean(value));
}

// -----------------------------------------------------------------------------
// API-Kommunikation
// -----------------------------------------------------------------------------

async function request(action, payload = null, options = {}) {
  const params = new URLSearchParams();

  if (options.boardId) {
    params.set('board_id', String(options.boardId));
  }

  const query = params.toString();
  const url = API + encodeURIComponent(action) + (query ? '&' + query : '');
  const fetchOptions = payload
    ? {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
        credentials: 'include'
      }
    : {credentials: 'include'};

  const response = await fetch(url, fetchOptions);
  const data = await response.json().catch(() => ({}));

  if (!response.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${response.status}`);
  }

  return data;
}


async function downloadJsonExport() {
  const response = await fetch(API + encodeURIComponent('json_export'), {credentials: 'include'});

  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.error || `HTTP ${response.status}`);
  }

  const blob = await response.blob();
  const disposition = response.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename="?([^";]+)"?/i);
  const filename = match ? match[1] : `polarisnova_backup_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.json`;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function renderJsonSyncInfo() {
  const storageMode = $('#jsonStorageMode');
  if (!storageMode) return;

  const label = state.meta?.storage_mode === 'json_offline'
    ? 'JSON Offline-Fallback aktiv'
    : (state.meta?.storage || window.APP_STORAGE_MODE_LABEL || 'MySQL/PDO aktiv');

  storageMode.textContent = state.meta?.json_restore_pending
    ? label + ' · JSON-Wiederherstellung offen'
    : label;
}

function summarizeImportReport(report) {
  if (!report || typeof report !== 'object') return 'Keine Detailauswertung vorhanden.';

  return Object.entries(report).map(([table, stats]) => {
    const added = Number(stats.added || 0);
    const updated = Number(stats.updated || 0);
    const kept = Number(stats.kept_current || 0);
    const unchanged = Number(stats.unchanged || 0);
    return `${table}: +${added}, aktualisiert ${updated}, behalten ${kept}, unverändert ${unchanged}`;
  }).join('\n');
}

function summarizeMysqlRestoreReport(report) {
  if (!report || typeof report !== 'object') return 'Keine Detailauswertung vorhanden.';

  return Object.entries(report).map(([table, stats]) => {
    const added = Number(stats.added || 0);
    const updated = Number(stats.updated || 0);
    const conflicts = Number(stats.conflicts || 0);
    const resolvedJson = Number(stats.resolved_json || 0);
    const keptMysql = Number((stats.kept_mysql ?? stats.kept_current) || 0);
    const unchanged = Number(stats.unchanged || 0);
    return `${table}: +${added}, aktualisiert ${updated}, Konflikte ${conflicts}, JSON gewinnt ${resolvedJson}, MySQL behalten ${keptMysql}, unverändert ${unchanged}`;
  }).join('\n');
}

function summarizeMysqlRestorePreview(preview) {
  if (!preview || typeof preview !== 'object') return 'Keine Vorschau vorhanden.';

  const totals = preview.totals || {};
  const lines = [
    `Datei: ${preview.json_file || 'data.json'}`,
    `Ausstehender JSON-Offline-Stand: ${preview.pending_restore ? 'ja' : 'nein / nicht eindeutig'}`,
    '',
    `Gesamt: +${Number(totals.added || 0)}, aktualisiert ${Number(totals.updated || 0)}, Konflikte ${Number(totals.conflicts || 0)}, MySQL-only behalten ${Number(totals.kept_mysql || 0)}, unverändert ${Number(totals.unchanged || 0)}`,
    '',
    summarizeMysqlRestoreReport(preview.report)
  ];

  const conflicts = preview.conflicts || [];
  if (conflicts.length) {
    lines.push('', 'Konflikte, erste Treffer:');
    conflicts.slice(0, 20).forEach(conflict => {
      lines.push(`- ${conflict.table} #${conflict.id}: ${conflict.label || ''} | MySQL ${conflict.mysql_time} | JSON ${conflict.json_time} | ${conflict.reason || ''}`);
    });
  }

  return lines.join('\n');
}

// -----------------------------------------------------------------------------
// Projekt-, Board- und Zuordnungs-Helfer
// -----------------------------------------------------------------------------

function projectById(id) {
  return state.projects.find(project => Number(project.id) === Number(id));
}

function boardById(id) {
  return state.boards.find(board => Number(board.id) === Number(id));
}

function boardsForProject(projectId) {
  return state.boards.filter(board => Number(board.project_id) === Number(projectId));
}

function firstBoardForProject(projectId) {
  return boardsForProject(projectId)[0] || null;
}

function membersForProject(projectId) {
  return state.project_members.filter(member => Number(member.project_id) === Number(projectId));
}

function userName(id) {
  const user = state.users.find(entry => Number(entry.id) === Number(id));
  return user ? user.username : '—';
}

function projectIdForColumn(columnId) {
  const column = state.columns.find(entry => Number(entry.id) === Number(columnId));
  const board = column ? boardById(column.board_id) : null;

  return board ? Number(board.project_id) : 0;
}

function projectForTask(task) {
  const projectId = projectIdForColumn(task?.column_id || 0);

  return projectId ? projectById(projectId) : null;
}

function userCanManageProject(projectId) {
  const project = projectById(projectId);

  if (APP_USER.role === 'admin') return true;
  if (!project || APP_USER.role === 'guest') return false;

  return Number(project.responsible_id || 0) === Number(APP_USER.id);
}

function userCanManageActiveBoard() {
  const {project} = activeProjectAndBoard();

  return project ? userCanManageProject(project.id) : APP_USER.role === 'admin';
}

function userCanManageTask(task) {
  const project = projectForTask(task);

  return project ? userCanManageProject(project.id) : APP_USER.role === 'admin';
}

function userIsProjectMember(projectId) {
  if (!projectId || APP_USER.role === 'guest') return false;

  return membersForProject(projectId).some(member => Number(member.user_id) === Number(APP_USER.id));
}

function userCanModifyTask(task) {
  if (userCanManageTask(task)) return true;
  if (APP_USER.role === 'guest') return false;

  const project = projectForTask(task);
  if (project && userIsProjectMember(project.id)) return true;

  return Number(task?.assigned_to || 0) === Number(APP_USER.id);
}

function activeProjectAndBoard() {
  const board = boardById(currentBoardId);
  const project = board ? projectById(board.project_id) : null;

  return {project, board};
}

function boardUrl(boardId) {
  return `index.php?board_id=${encodeURIComponent(boardId)}`;
}

function customerProjectUrl(projectId) {
  return `customers.php?project_id=${encodeURIComponent(projectId)}`;
}

function replaceUrl(url) {
  if (!window.history || !window.history.replaceState) return;
  window.history.replaceState(null, '', url);
}

// -----------------------------------------------------------------------------
// Laden und Umschalten zwischen Übersicht und Board
// -----------------------------------------------------------------------------

async function loadOverview() {
  stopTaskAutoRefresh();
  currentBoardId = null;
  currentTaskId = null;
  replaceUrl('index.php');

  const response = await request('bootstrap');
  state = response.data;

  renderJsonSyncInfo();
  renderSystemStand();
  fillProjectFilters();
  renderProjectGrid();
  renderUsers();
  renderProjectMemberList();
  showProjectOverview();
}

async function openBoard(boardId) {
  currentBoardId = Number(boardId);
  currentTaskId = null;
  replaceUrl(boardUrl(currentBoardId));

  const response = await request('bootstrap', null, {boardId: currentBoardId});
  state = response.data;

  renderJsonSyncInfo();
  fillSelects();
  renderActiveBoardInfo();
  renderBoard();
  renderUsers();
  renderProjectMemberList();
  showBoardWorkspace();
  startTaskAutoRefresh();
}

function showProjectOverview() {
  setHiddenSafe('#projectOverview', false);
  setHiddenSafe('#boardWorkspace', true);
  setHiddenSafe('#backToProjects', true);
  setHiddenSafe('#newTaskBtn', true);
  setHiddenSafe('#openReports', true);

  // Nach Rücksprung auf die Übersicht wieder oben beginnen.
  window.scrollTo(0, 0);
}

function showBoardWorkspace() {
  setHiddenSafe('#projectOverview', true);
  setHiddenSafe('#boardWorkspace', false);
  setHiddenSafe('#backToProjects', false);
  setHiddenSafe('#newTaskBtn', APP_USER.role === 'guest');
  setHiddenSafe('#openReports', false);

  // Nach dem Öffnen eines Boards nicht an der alten Scrollposition bleiben.
  window.scrollTo(0, 0);
}

function renderActiveBoardInfo() {
  const {project, board} = activeProjectAndBoard();

  const title = $('#activeProjectTitle');
  const description = $('#activeProjectDescription');
  const boardName = $('#activeBoardName');
  const projectRole = $('#activeProjectRole');

  if (title) title.textContent = project ? project.name : 'Projekt';
  if (description) description.textContent = project?.description || 'Keine Projektbeschreibung hinterlegt.';
  if (boardName) boardName.textContent = board ? board.name : '—';
  if (projectRole) {
    if (APP_USER.role === 'admin') {
      projectRole.textContent = 'Admin';
    } else if (project && userCanManageProject(project.id)) {
      projectRole.textContent = 'Projektverantwortlicher';
    } else {
      projectRole.textContent = APP_USER.role;
    }
  }
}

// -----------------------------------------------------------------------------
// Projektübersicht rendern
// -----------------------------------------------------------------------------

function renderSystemStand() {
  const version = $('#appVersion');
  const lastUpdate = $('#appLastUpdate');
  const note = $('#appVersionNote');

  if (version) version.textContent = state.meta?.version_label || APP_VERSION_LABEL;
  if (lastUpdate) lastUpdate.textContent = state.meta?.last_update || APP_LAST_UPDATE;
  if (note) note.textContent = state.meta?.release_notes || APP_VERSION_NOTE;
}

function fillProjectFilters() {
  const memberFilter = $('#projectMemberFilter');
  const responsibleFilter = $('#projectResponsibleFilter');

  if (memberFilter) {
    const current = memberFilter.value;
    memberFilter.innerHTML = '<option value="">Alle Mitarbeiter</option>' +
      state.users
        .filter(user => user.role !== 'guest')
        .map(user => `<option value="${esc(user.id)}">${esc(user.username)}</option>`)
        .join('');
    memberFilter.value = current;
  }

  if (responsibleFilter) {
    const current = responsibleFilter.value;
    const responsibleIds = [...new Set(state.projects
      .map(project => Number(project.responsible_id || 0))
      .filter(Boolean))];

    responsibleFilter.innerHTML = '<option value="">Alle Verantwortlichen</option>' +
      responsibleIds
        .map(id => `<option value="${esc(id)}">${esc(userName(id))}</option>`)
        .join('');
    responsibleFilter.value = current;
  }
}

function projectMatchesOverviewFilters(project) {
  const term = String($('#projectSearch')?.value || '').trim().toLowerCase();
  const memberId = Number($('#projectMemberFilter')?.value || 0);
  const responsibleId = Number($('#projectResponsibleFilter')?.value || 0);
  const board = firstBoardForProject(project.id);
  const members = membersForProject(project.id).map(member => userName(member.user_id));
  const responsible = project.responsible_id ? userName(project.responsible_id) : '';

  if (memberId && !membersForProject(project.id).some(member => Number(member.user_id) === memberId)) {
    return false;
  }

  if (responsibleId && Number(project.responsible_id || 0) !== responsibleId) {
    return false;
  }

  if (!term) {
    return true;
  }

  const haystack = [
    project.name,
    project.description,
    board?.name,
    responsible,
    members.join(' ')
  ].join(' ').toLowerCase();

  return haystack.includes(term);
}

function renderProjectGrid() {
  const grid = $('#projectGrid');
  if (!grid) return;

  if (!state.projects.length) {
    grid.innerHTML = `
      <div class="empty-state">
        <h2>Keine Projekte sichtbar</h2>
        <p>Es gibt noch kein Projekt oder dieser Benutzer wurde noch keinem Projekt zugeordnet.</p>
      </div>`;
    return;
  }

  const visibleProjects = state.projects.filter(projectMatchesOverviewFilters);

  if (!visibleProjects.length) {
    grid.innerHTML = `
      <div class="empty-state">
        <h2>Keine Treffer</h2>
        <p>Zu den gesetzten Filtern wurde kein Projekt gefunden.</p>
      </div>`;
    return;
  }

  grid.innerHTML = visibleProjects.map(project => {
    const board = firstBoardForProject(project.id);
    const members = membersForProject(project.id).map(member => userName(member.user_id));
    const responsible = project.responsible_id ? userName(project.responsible_id) : 'Nicht gesetzt';
    const columns = board ? state.columns.filter(column => Number(column.board_id) === Number(board.id)) : [];
    const columnIds = columns.map(column => Number(column.id));
    const tasks = state.tasks.filter(task => columnIds.includes(Number(task.column_id)));

    return `
      <article class="project-card" data-board-id="${board ? esc(board.id) : ''}">
        <div class="project-card-top">
          <span class="pill">Projekt #${esc(project.id)}</span>
          <span class="pill">${tasks.length} Aufgaben</span>
        </div>
        <h2>${esc(project.name)}</h2>
        <p>${esc(project.description || 'Keine Projektbeschreibung hinterlegt.')}</p>
        <div class="project-meta">
          <span><b>Board:</b> ${esc(board?.name || 'Kein Board')}</span>
          <span><b>Verantwortlich:</b> ${esc(responsible)}</span>
          <span><b>Mitarbeiter:</b> ${members.length ? esc(members.join(', ')) : 'Keine Zuordnung'}</span>
        </div>
        <div class="actions project-actions">
          <button type="button" data-open-board="${board ? esc(board.id) : ''}" ${board ? '' : 'disabled'}>Board öffnen</button>
          <a class="ghost" href="${customerProjectUrl(project.id)}" data-project-customers="${esc(project.id)}">Kunden</a>
          ${userCanManageProject(project.id) ? `<button type="button" class="ghost" data-edit-project="${esc(project.id)}">Bearbeiten</button>` : ''}
          ${userCanManageProject(project.id) ? `<button type="button" class="danger" data-delete-project="${esc(project.id)}">Löschen</button>` : ''}
        </div>
      </article>`;
  }).join('');

  $$('[data-open-board]').forEach(button => {
    button.addEventListener('click', event => {
      event.stopPropagation();
      const boardId = Number(button.dataset.openBoard);
      if (boardId) openBoard(boardId).catch(error => alert(error.message));
    });
  });

  $$('[data-edit-project]').forEach(button => {
    button.addEventListener('click', event => {
      event.stopPropagation();
      openProjectEditor(Number(button.dataset.editProject));
    });
  });

  $$('[data-project-customers]').forEach(link => {
    link.addEventListener('click', event => event.stopPropagation());
  });

  $$('[data-delete-project]').forEach(button => {
    button.addEventListener('click', event => {
      event.stopPropagation();
      deleteProject(Number(button.dataset.deleteProject));
    });
  });

  $$('.project-card').forEach(card => {
    card.addEventListener('click', () => {
      const boardId = Number(card.dataset.boardId);
      if (boardId) openBoard(boardId).catch(error => alert(error.message));
    });
  });
}

// -----------------------------------------------------------------------------
// Selects und Board rendern
// -----------------------------------------------------------------------------

function fillSelects() {
  const assigneeFilter = $('#assigneeFilter');
  if (assigneeFilter) {
    assigneeFilter.innerHTML = '<option value="">Alle Benutzer</option>' +
      state.users.map(user => `<option value="${user.id}">${esc(user.username)}</option>`).join('');
  }

  $$('select[name="assigned_to"]').forEach(select => {
    const assignableUsers = userCanManageActiveBoard()
      ? state.users.filter(user => user.role !== 'guest')
      : state.users.filter(user => Number(user.id) === Number(APP_USER.id));

    select.innerHTML = '<option value="">Nicht zugewiesen</option>' +
      assignableUsers
        .map(user => `<option value="${user.id}">${esc(user.username)} (${esc(user.role)})</option>`)
        .join('');
  });

  $$('select[name="column_id"]').forEach(select => {
    select.innerHTML = state.columns
      .sort((a, b) => Number(a.position) - Number(b.position))
      .map(column => `<option value="${column.id}">${esc(column.name)}</option>`)
      .join('');
  });
}

/**
 * Ermittelt eine führende Nummer im Aufgabentitel.
 * Beispiele:
 * - "03 Pflichtenheft erstellen" => 3
 * - "10 Implementierung" => 10
 * - "Bugfix ohne Nummer" => null
 */
function taskTitleNumber(task) {
  const match = String(task?.title || '').match(/^\s*(\d{1,6})(?=[\s.)\-:_]|$)/);

  return match ? Number(match[1]) : null;
}

/**
 * Sortiert Aufgaben automatisch fachlich sinnvoll:
 * 1. Aufgaben mit führender Nummer werden numerisch sortiert.
 * 2. Aufgaben ohne Nummer bleiben danach nach gespeicherter Position sortiert.
 * 3. Bei Gleichstand entscheidet der Titel und zuletzt die ID.
 */
function compareTasksAuto(left, right) {
  const leftNumber = taskTitleNumber(left);
  const rightNumber = taskTitleNumber(right);
  const leftHasNumber = leftNumber !== null;
  const rightHasNumber = rightNumber !== null;

  if (leftHasNumber && rightHasNumber && leftNumber !== rightNumber) {
    return leftNumber - rightNumber;
  }

  if (leftHasNumber !== rightHasNumber) {
    return leftHasNumber ? -1 : 1;
  }

  const leftPosition = Number(left.position || 999);
  const rightPosition = Number(right.position || 999);

  if (leftPosition !== rightPosition) {
    return leftPosition - rightPosition;
  }

  const titleCompare = String(left.title || '').localeCompare(String(right.title || ''), 'de', {numeric: true, sensitivity: 'base'});
  if (titleCompare !== 0) return titleCompare;

  return Number(left.id || 0) - Number(right.id || 0);
}

function visibleTasks(columnId) {
  const query = ($('#search')?.value || '').toLowerCase();
  const priority = $('#priorityFilter')?.value || '';
  const assignee = $('#assigneeFilter')?.value || '';

  return state.tasks
    .filter(task => Number(task.column_id) === Number(columnId))
    .filter(task => !query || (task.title + ' ' + task.description).toLowerCase().includes(query))
    .filter(task => !priority || task.priority === priority)
    .filter(task => !assignee || Number(task.assigned_to) === Number(assignee))
    .sort(compareTasksAuto);
}

function renderBoard() {
  const board = $('#board');
  if (!board) return;

  board.innerHTML = state.columns
    .sort((a, b) => Number(a.position) - Number(b.position))
    .map(column => {
      const tasks = visibleTasks(column.id);
      return `
        <section class="column" data-column="${column.id}">
          <h2>${esc(column.name)} <span class="count">${tasks.length}</span></h2>
          <div class="dropzone" data-column="${column.id}">
            ${tasks.map(taskHtml).join('')}
          </div>
        </section>`;
    })
    .join('');

  bindDnD();
}

function priorityName(priority) {
  return ({low: 'niedrig', medium: 'mittel', high: 'hoch', critical: 'kritisch'}[priority] || priority || 'mittel');
}

function taskHtml(task) {
  const desc = (task.description || '').slice(0, 90);
  const due = dueState(task.due_at);
  const canDrag = userCanModifyTask(task);

  return `
    <article class="task ${due.cls} ${canDrag ? '' : 'readonly-task'}" draggable="${canDrag}" data-id="${task.id}">
      <h3>${esc(task.title)}</h3>
      <p>${esc(desc)}${task.description && task.description.length > 90 ? '…' : ''}</p>
      <div class="meta">
        <span class="pill ${esc(task.priority)}">${priorityName(task.priority)}</span>
        <span class="pill">${esc(userName(task.assigned_to))}</span>
        ${task.due_at ? `<span class="pill due-pill">${esc(due.label)}</span>` : ''}
        ${task.locked_by ? `<span class="pill locked">🔒 ${esc(userName(task.locked_by))}</span>` : ''}
        ${!canDrag ? '<span class="pill readonly-pill">nur Ansicht</span>' : ''}
      </div>
    </article>`;
}

// -----------------------------------------------------------------------------
// Drag & Drop
// -----------------------------------------------------------------------------

function bindDnD() {
  $$('.task').forEach(taskElement => {
    taskElement.addEventListener('click', () => openTask(Number(taskElement.dataset.id)));

    taskElement.addEventListener('dragstart', event => {
      const task = state.tasks.find(entry => Number(entry.id) === Number(taskElement.dataset.id));
      if (!userCanModifyTask(task)) {
        event.preventDefault();
        return;
      }

      taskElement.classList.add('dragging');
      event.dataTransfer.setData('text/plain', taskElement.dataset.id);
    });

    taskElement.addEventListener('dragend', () => taskElement.classList.remove('dragging'));
  });

  $$('.dropzone').forEach(zone => {
    zone.addEventListener('dragover', event => event.preventDefault());

    zone.addEventListener('drop', async event => {
      event.preventDefault();

      const id = Number(event.dataTransfer.getData('text/plain'));
      const columnId = Number(zone.dataset.column);
      const position = zone.children.length + 1;
      const task = state.tasks.find(entry => Number(entry.id) === id);
      const allowed = canMoveToColumn(task, columnId);

      if (!allowed.ok) {
        alert(allowed.msg);
        return;
      }

      try {
        await request('move_task', {task_id: id, column_id: columnId, position}, {boardId: currentBoardId});
        if (task) {
          task.column_id = columnId;
          task.position = position;
        }
        renderBoard();
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

// -----------------------------------------------------------------------------
// Fälligkeit / Stichtag
// -----------------------------------------------------------------------------

function parseDue(due) {
  if (!due) return null;
  const date = new Date(due);

  return isNaN(date.getTime()) ? null : date;
}

function dueState(due) {
  const date = parseDue(due);
  if (!date) return {cls: '', label: 'Kein Stichtag'};

  const now = new Date();
  const diff = date.getTime() - now.getTime();
  const oneDay = 24 * 60 * 60 * 1000;

  if (diff < 0) return {cls: 'overdue', label: 'Überfällig: ' + formatDue(due)};
  if (diff <= oneDay) return {cls: 'due-red', label: 'Fällig: ' + formatDue(due)};
  if (diff <= 3 * oneDay) return {cls: 'due-yellow', label: 'Bald fällig: ' + formatDue(due)};

  return {cls: '', label: 'Fällig: ' + formatDue(due)};
}

function formatDue(due) {
  const date = parseDue(due);
  if (!date) return '—';

  return date.toLocaleString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

// -----------------------------------------------------------------------------
// Spaltenregeln im Frontend spiegeln die API-Regeln für bessere Nutzerführung
// -----------------------------------------------------------------------------

function columnById(id) {
  return state.columns.find(column => Number(column.id) === Number(id));
}

function columnName(id) {
  const column = columnById(id);

  return (column && column.name ? column.name : '').toLowerCase();
}

function isInProgressColumn(id) {
  const name = columnName(id);

  return name.includes('arbeit') || name.includes('progress');
}

function isDoneColumn(id) {
  const name = columnName(id);

  return name.includes('erledigt') || name.includes('done') || name.includes('fertig');
}

function countMyInProgress(ignoreTaskId = 0) {
  return state.tasks.filter(task =>
    Number(task.id) !== Number(ignoreTaskId) &&
    Number(task.assigned_to) === Number(APP_USER.id) &&
    isInProgressColumn(task.column_id)
  ).length;
}

function canMoveToColumn(task, columnId) {
  if (!task) return {ok: false, msg: 'Aufgabe nicht gefunden.'};
  if (!userCanModifyTask(task)) return {ok: false, msg: 'Diese Aufgabe ist Ihnen nicht zugewiesen.'};
  if (userCanManageTask(task)) return {ok: true};
  if (APP_USER.role === 'guest') return {ok: false, msg: 'Gäste dürfen Aufgaben nicht verschieben.'};
  if (isDoneColumn(columnId)) return {ok: false, msg: 'Nur Admins oder Projektverantwortliche dürfen Aufgaben nach Erledigt verschieben.'};
  if (isInProgressColumn(columnId) && countMyInProgress(task?.id || 0) >= 2) {
    return {ok: false, msg: 'Limit erreicht: Maximal 2 Aufgaben gleichzeitig in Arbeit.'};
  }

  return {ok: true};
}

// -----------------------------------------------------------------------------
// Modale und Aufgabenbearbeitung
// -----------------------------------------------------------------------------

function openModal(id) {
  const modal = $('#' + id);
  if (modal) modal.classList.add('open');
}

function closeModals() {
  $$('.modal').forEach(modal => modal.classList.remove('open'));
}

function lockOwnerName(task) {
  return task && task.locked_by ? userName(task.locked_by) : '';
}

function isLockedByOther(task) {
  return !!(task && task.locked_by && Number(task.locked_by) !== Number(APP_USER.id));
}

function isLockedByMe(task) {
  return !!(task && task.locked_by && Number(task.locked_by) === Number(APP_USER.id));
}

function resetLockUi() {
  const status = $('#lockStatus');
  const button = $('#lockTask');

  if (status) {
    status.hidden = true;
    status.textContent = '';
    status.className = 'lock-status';
  }

  if (button) {
    button.hidden = true;
    button.style.display = 'none';
    button.disabled = false;
  }
}

function renderLockUi(task) {
  const status = $('#lockStatus');
  const button = $('#lockTask');
  const other = isLockedByOther(task);
  const me = isLockedByMe(task);

  if (status) {
    status.hidden = !task.locked_by;
    status.textContent = task.locked_by
      ? `🔒 Bearbeitung gesperrt durch ${lockOwnerName(task)}${task.locked_at ? ' seit ' + task.locked_at : ''}`
      : '';
    status.className = 'lock-status' + (me ? ' locked-me' : '') + (other ? ' locked-other' : '');
  }

  if (button) {
    const canUseLock = userCanModifyTask(task) && !!task.id;
    button.hidden = !canUseLock;
    button.style.display = canUseLock ? '' : 'none';
    button.disabled = other;
    button.textContent = me ? 'Bearbeitung freigeben' : 'Bearbeitung sperren';
    button.title = other ? 'Diese Aufgabe ist durch einen anderen Benutzer gesperrt.' : '';
  }
}

function setEditReadonly(readonly) {
  const form = $('#taskForm');
  if (!form) return;

  [...form.querySelectorAll('input,textarea,select')].forEach(element => {
    if (element.type !== 'hidden' && element.id !== 'commentText') {
      element.disabled = readonly;
    }
  });

  const save = form.querySelector('button[type="submit"]');
  if (save) save.style.display = readonly ? 'none' : '';

  ['addComment', 'startTime', 'stopTime'].forEach(id => {
    const button = document.getElementById(id);
    if (button) button.style.display = readonly ? 'none' : '';
  });
}

function openTask(id = null) {
  if (!currentBoardId) {
    alert('Bitte zuerst ein Projektboard öffnen.');
    return;
  }

  currentTaskId = id;
  const form = $('#taskForm');
  if (!form) return;

  form.reset();
  form.id.value = '';

  setHiddenSafe('#deleteTask', true);
  setHiddenSafe('#commentBox', true);
  setHiddenSafe('#timeBox', true);
  setHiddenSafe('#historyBox', true);

  setEditReadonly(false);
  resetLockUi();

  if (id) {
    const task = state.tasks.find(entry => Number(entry.id) === Number(id));
    if (!task) return;

    renderLockUi(task);

    form.id.value = task.id;
    form.title.value = task.title || '';
    form.description.value = task.description || '';
    form.priority.value = task.priority || 'medium';
    form.assigned_to.value = task.assigned_to || '';
    if (form.due_at) form.due_at.value = task.due_at || '';
    form.column_id.value = task.column_id;

    const readonly = !userCanModifyTask(task) || isLockedByOther(task);
    setEditReadonly(readonly);

    // Normale Projektmitglieder dürfen Aufgaben bearbeiten/kommentieren/Zeiten
    // erfassen, aber die Zuweisung bleibt Admins und Projektverantwortlichen
    // vorbehalten. So wird keine Aufgabe unbemerkt einem anderen Benutzer
    // weggezogen.
    if (form.assigned_to) form.assigned_to.disabled = readonly || !userCanManageTask(task);

    setHiddenSafe('#deleteTask', !userCanManageTask(task) || isLockedByOther(task));
    setHiddenSafe('#commentBox', false);
    setHiddenSafe('#historyBox', false);
    setHiddenSafe('#timeBox', false);

    renderComments(id);
    renderHistory(id);
    renderTimes(id);
  } else {
    if (form.due_at) form.due_at.value = '';
    form.column_id.value = state.columns[0]?.id || '';
    if (APP_USER.role === 'user') form.assigned_to.value = APP_USER.id;
    if (form.assigned_to) form.assigned_to.disabled = APP_USER.role === 'user';
    resetLockUi();
  }

  openModal('taskModal');
}

// -----------------------------------------------------------------------------
// Kommentare, Zeiten und Historie
// -----------------------------------------------------------------------------

function renderHistory(id) {
  const list = $('#historyList');
  if (!list) return;

  const entries = (state.history || [])
    .filter(entry => Number(entry.task_id) === Number(id))
    .sort((a, b) => Number(b.id) - Number(a.id));

  list.innerHTML = entries.length
    ? entries.map(entry => `
      <div class="history-entry">
        <b>${esc(historyActionName(entry.action))}</b>
        <span>${esc(entry.message || '')}</span>
        ${entry.field ? `<small>Feld: ${esc(entry.field)} · Alt: ${esc(displayHistoryValue(entry.old_value))} · Neu: ${esc(displayHistoryValue(entry.new_value))}</small>` : ''}
        <small>${esc(userName(entry.user_id))} · ${esc(entry.created_at)}</small>
      </div>`).join('')
    : '<p class="muted">Noch keine Änderungen protokolliert.</p>';
}

function historyActionName(action) {
  return ({
    task_imported: 'Import',
    task_created: 'Erstellt',
    task_changed: 'Geändert',
    task_updated: 'Bearbeitet',
    task_moved: 'Verschoben',
    task_deleted: 'Gelöscht',
    comment_added: 'Kommentar',
    task_locked: 'Gesperrt',
    task_unlocked: 'Freigegeben',
    time_started: 'Zeit gestartet',
    time_stopped: 'Zeit gestoppt'
  }[action] || action || 'Änderung');
}

function displayHistoryValue(value) {
  if (value === null || value === undefined || value === '') return '—';

  if (String(value).match(/^\d+$/)) {
    const user = state.users.find(entry => Number(entry.id) === Number(value));
    if (user) return user.username;

    const column = state.columns.find(entry => Number(entry.id) === Number(value));
    if (column) return column.name;
  }

  return value;
}

function renderComments(id) {
  const list = $('#comments');
  if (!list) return;

  const comments = state.comments.filter(comment => Number(comment.task_id) === Number(id));
  list.innerHTML = comments.length
    ? comments.map(comment => `
      <div class="comment">
        <b>${esc(userName(comment.user_id))}</b><br>
        ${esc(comment.content)}<br>
        <small>${esc(comment.created_at)}</small>
      </div>`).join('')
    : '<p class="muted">Noch keine Kommentare.</p>';
}

function effectiveTimeSeconds(time) {
  let seconds = Number(time?.seconds || 0);

  if (seconds <= 0 && time?.started_at) {
    const start = new Date(time.started_at.replace(' ', 'T')).getTime();
    const stop = time.stopped_at ? new Date(time.stopped_at.replace(' ', 'T')).getTime() : Date.now();

    if (!Number.isNaN(start) && !Number.isNaN(stop)) {
      seconds = Math.max(1, Math.floor((stop - start) / 1000));
    }
  }

  return seconds;
}

function updateTimeButtons(taskId) {
  const startButton = $('#startTime');
  const stopButton = $('#stopTime');
  const runningByMe = state.time_entries.find(time => Number(time.user_id) === Number(APP_USER.id) && !time.stopped_at);
  const runningThisTaskByMe = runningByMe && Number(runningByMe.task_id) === Number(taskId);

  if (startButton) {
    startButton.disabled = !!runningByMe;
    startButton.title = runningByMe
      ? `Es läuft bereits eine Zeitmessung auf Aufgabe #${runningByMe.task_id}. Bitte diese zuerst stoppen.`
      : 'Zeitmessung für diese Aufgabe starten.';
  }

  if (stopButton) {
    stopButton.disabled = !runningThisTaskByMe;
    stopButton.title = runningThisTaskByMe
      ? 'Eigene laufende Zeitmessung stoppen.'
      : 'Für diese Aufgabe läuft keine eigene Zeitmessung.';
  }
}

function renderTimes(id) {
  const list = $('#timeList');
  if (!list) return;

  const times = state.time_entries
    .filter(time => Number(time.task_id) === Number(id))
    .sort((a, b) => String(b.started_at || '').localeCompare(String(a.started_at || '')));

  list.innerHTML = times.length
    ? times.map(time => `
      <div class="time ${!time.stopped_at ? 'running-time' : ''}">
        <b>${esc(userName(time.user_id))}</b> · ${formatSeconds(effectiveTimeSeconds(time))}${time.stopped_at ? '' : ' · läuft'}
        <small>${esc(time.started_at)}${time.stopped_at ? ' – ' + esc(time.stopped_at) : ' – noch aktiv'}</small>
      </div>`).join('')
    : '<p class="muted">Noch keine Zeiten.</p>';

  updateTimeButtons(id);
}

function formatSeconds(seconds) {
  seconds = Number(seconds || 0);

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  return `${hours}h ${minutes}m`;
}

function reportRows(rows, emptyText = 'Keine Zeiten vorhanden.') {
  return rows && rows.length
    ? rows.map(row => `
      <div class="report-row">
        <div>
          <b>${esc(row.label || row.user_name || row.key || '—')}</b><br>
          <small>${esc(row.entries || 0)} Buchung(en)</small>
        </div>
        <strong>${formatSeconds(row.seconds)}</strong>
      </div>`).join('')
    : `<p class="muted">${esc(emptyText)}</p>`;
}

function reportDetailRows(rows) {
  return rows && rows.length
    ? rows.map(row => `
      <div class="report-row">
        <div>
          <b>${esc(row.user_name)}</b> · ${esc(row.task_title)}<br>
          <small>${esc(row.started_at)}${row.stopped_at ? ' – ' + esc(row.stopped_at) : ' – läuft'} · ${esc(row.day || '')}</small>
        </div>
        <strong>${formatSeconds(row.seconds)}</strong>
      </div>`).join('')
    : '<p class="muted">Keine Einzelbuchungen vorhanden.</p>';
}

// -----------------------------------------------------------------------------
// Projektverwaltung im Adminbereich
// -----------------------------------------------------------------------------

function renderProjectMemberList(projectId = 0) {
  const box = $('#projectMemberList');
  const responsibleSelect = $('#projectResponsible');

  const project = projectById(projectId);
  const responsibleId = Number(project?.responsible_id || (APP_USER.role === 'admin' ? APP_USER.id : 0));

  if (responsibleSelect) {
    const candidates = state.users.filter(user => user.role !== 'guest' && user.is_active !== false);
    responsibleSelect.innerHTML = '<option value="">Kein Verantwortlicher</option>' +
      candidates.map(user => `<option value="${esc(user.id)}">${esc(user.username)} (${esc(user.role)})</option>`).join('');
    responsibleSelect.value = responsibleId ? String(responsibleId) : '';
  }

  if (!box) return;

  const assignedIds = membersForProject(projectId).map(member => Number(member.user_id));
  const employees = state.users.filter(user => user.role === 'user');

  box.innerHTML = employees.length
    ? employees.map(user => `
      <label class="check-row">
        <input type="checkbox" name="member_ids[]" value="${esc(user.id)}" ${assignedIds.includes(Number(user.id)) ? 'checked' : ''}>
        <span>${esc(user.username)} ${Number(user.id) === responsibleId ? ' · verantwortlich' : ''} ${user.is_active === false ? '(gesperrt)' : ''}</span>
      </label>`).join('')
    : '<p class="muted">Noch keine Mitarbeiter vorhanden.</p>';
}

function resetProjectForm() {
  const form = $('#projectForm');
  if (!form) return;

  form.reset();
  if (form.elements['id']) form.elements['id'].value = '';
  const deleteButton = $('#deleteProject');
  if (deleteButton) deleteButton.hidden = true;
  renderProjectMemberList(0);
}

function openProjectEditor(projectId) {
  const form = $('#projectForm');
  if (!form) return;

  const project = projectById(projectId);
  const board = firstBoardForProject(projectId);
  if (!project) return;

  if (form.elements['id']) form.elements['id'].value = project.id;
  if (form.elements['name']) form.elements['name'].value = project.name || '';
  if (form.elements['board_name']) form.elements['board_name'].value = board?.name || '';
  if (form.elements['description']) form.elements['description'].value = project.description || '';
  if (form.elements['responsible_id']) form.elements['responsible_id'].value = project.responsible_id || '';

  const deleteButton = $('#deleteProject');
  if (deleteButton) deleteButton.hidden = !userCanManageProject(projectId);

  renderProjectMemberList(projectId);
  openModal('projectModal');
}

async function deleteProject(projectId) {
  const project = projectById(projectId);
  const board = firstBoardForProject(projectId);
  const label = project?.name || `Projekt #${projectId}`;

  if (!projectId) return;

  const message = `Projekt/Board "${label}" wirklich löschen?\n\nDabei werden Board, Spalten, Aufgaben, Kommentare, Zeiten und Projektzuordnungen entfernt. Kundendaten bleiben erhalten; Projektlinks werden dort nur gelöst.`;
  if (!confirm(message)) return;

  try {
    await request('delete_project', {id: projectId, board_id: board?.id || 0});
    closeModals();
    resetProjectForm();
    await loadOverview();
  } catch (error) {
    alert(error.message);
  }
}

// -----------------------------------------------------------------------------
// Benutzerverwaltung im Adminbereich
// -----------------------------------------------------------------------------

function renderUsers() {
  const list = $('#userList');
  if (!list) return;

  list.innerHTML = '<h3>Benutzerliste</h3>' + state.users.map(user => `
    <div class="comment user-row" data-user="${user.id}">
      <b>${esc(user.username)}</b> · ${esc(user.role)} ·
      <span class="${user.is_active === false ? 'badtxt' : 'oktxt'}">${user.is_active === false ? 'gesperrt' : 'aktiv'}</span><br>
      <small>${esc(user.email || '')}</small>
      <div class="user-actions">
        <button type="button" data-edit-user="${user.id}">Bearbeiten</button>
        <button type="button" data-lock-user="${user.id}">${user.is_active === false ? 'Freigeben' : 'Sperren'}</button>
        <button type="button" class="danger" data-delete-user="${user.id}">Löschen</button>
      </div>
    </div>`).join('');

  $$('[data-edit-user]').forEach(button => {
    button.addEventListener('click', () => {
      const user = state.users.find(entry => Number(entry.id) === Number(button.dataset.editUser));
      if (!user) return;

      const form = $('#userForm');
      form.id.value = user.id;
      form.username.value = user.username || '';
      form.email.value = user.email || '';
      form.password.value = '';
      form.role.value = user.role || 'user';
      if (form.is_active) form.is_active.value = user.is_active === false ? '0' : '1';

      const deleteButton = $('#deleteUser');
      if (deleteButton) deleteButton.hidden = false;
    });
  });

  $$('[data-lock-user]').forEach(button => {
    button.addEventListener('click', async () => {
      const id = Number(button.dataset.lockUser);
      try {
        await request('toggle_user_lock', {id});
        await loadOverview();
      } catch (error) {
        alert(error.message);
      }
    });
  });

  $$('[data-delete-user]').forEach(button => {
    button.addEventListener('click', async () => {
      const id = Number(button.dataset.deleteUser);
      const user = state.users.find(entry => Number(entry.id) === id);

      if (!confirm(`Benutzer "${user?.username || id}" wirklich löschen?\nHinweis: Benutzer mit Aufgaben, Kommentaren oder Zeiten können aus Nachvollziehbarkeitsgründen nicht gelöscht werden.`)) return;

      try {
        await request('delete_user', {id});
        await loadOverview();
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

// -----------------------------------------------------------------------------
// Teil-Refresh für das geöffnete Board
// -----------------------------------------------------------------------------

function taskSnapshot(tasks) {
  return JSON.stringify((tasks || []).map(task => ({
    id: task.id,
    column_id: task.column_id,
    position: task.position,
    title: task.title,
    description: task.description,
    priority: task.priority,
    assigned_to: task.assigned_to,
    locked_by: task.locked_by || null,
    locked_at: task.locked_at || null,
    updated_at: task.updated_at || null,
    due_at: task.due_at || ''
  })).sort((a, b) => Number(a.id) - Number(b.id)));
}

async function refreshTasksOnly() {
  if (!currentBoardId || taskRefreshBusy) return;

  const modalOpen = !!document.querySelector('.modal.open');
  const taskForm = document.querySelector('#taskForm');
  const isTypingInTaskModal = modalOpen &&
    document.activeElement &&
    taskForm &&
    taskForm.contains(document.activeElement) &&
    ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);

  taskRefreshBusy = true;

  try {
    const response = await request('tasks_only', null, {boardId: currentBoardId});
    if (!response || !response.data) return;

    const nextSnapshot = taskSnapshot(response.data.tasks || []);
    if (nextSnapshot === lastTaskSnapshot) return;

    state.meta = response.data.meta || state.meta || {};
    state.columns = response.data.columns || [];
    state.tasks = response.data.tasks || [];
    state.comments = response.data.comments || [];
    state.time_entries = response.data.time_entries || [];
    state.events = response.data.events || [];
    state.history = response.data.history || [];
    lastTaskSnapshot = nextSnapshot;

    renderBoard();

    if (currentTaskId && modalOpen) {
      const task = state.tasks.find(entry => Number(entry.id) === Number(currentTaskId));
      if (task) {
        renderLockUi(task);

        if (!isTypingInTaskModal) {
          renderComments(currentTaskId);
          renderHistory(currentTaskId);
          renderTimes(currentTaskId);
        }

        setEditReadonly(!userCanModifyTask(task) || isLockedByOther(task));
      } else {
        closeModals();
        currentTaskId = null;
      }
    }
  } catch (error) {
    console.warn('Task-Refresh fehlgeschlagen:', error.message);
  } finally {
    taskRefreshBusy = false;
  }
}

function startTaskAutoRefresh() {
  stopTaskAutoRefresh();
  lastTaskSnapshot = taskSnapshot(state.tasks);
  taskRefreshTimer = setInterval(refreshTasksOnly, 5000);
}

function stopTaskAutoRefresh() {
  if (taskRefreshTimer) clearInterval(taskRefreshTimer);
  taskRefreshTimer = null;
}

// -----------------------------------------------------------------------------
// Event-Handler
// -----------------------------------------------------------------------------

$('#backToProjects')?.addEventListener('click', () => {
  loadOverview().catch(error => alert(error.message));
});

$('#taskForm')?.addEventListener('submit', async event => {
  event.preventDefault();

  const payload = Object.fromEntries(new FormData(event.target).entries());
  const task = payload.id ? state.tasks.find(entry => Number(entry.id) === Number(payload.id)) : null;
  const allowed = canMoveToColumn(task || {id: 0, assigned_to: Number(payload.assigned_to || APP_USER.id), column_id: Number(payload.column_id)}, Number(payload.column_id));

  if (!allowed.ok) {
    alert(allowed.msg);
    return;
  }

  if (!payload.id) delete payload.id;

  try {
    await request('save_task', payload, {boardId: currentBoardId});
    closeModals();
    await openBoard(currentBoardId);
  } catch (error) {
    alert(error.message);
  }
});

$('#lockTask')?.addEventListener('click', async event => {
  event.preventDefault();
  event.stopPropagation();

  const taskId = Number(currentTaskId || 0);
  if (!taskId) return;

  try {
    const response = await request('toggle_lock', {task_id: taskId}, {boardId: currentBoardId});
    const index = state.tasks.findIndex(entry => Number(entry.id) === Number(taskId));
    if (index >= 0 && response.task) state.tasks[index] = response.task;

    closeModals();
    await openBoard(currentBoardId);
    openTask(taskId);
  } catch (error) {
    alert(error.message);
  }
});

$('#deleteTask')?.addEventListener('click', async () => {
  if (!currentTaskId || !confirm('Aufgabe wirklich löschen?')) return;

  try {
    await request('delete_task', {id: currentTaskId}, {boardId: currentBoardId});
    closeModals();
    await openBoard(currentBoardId);
  } catch (error) {
    alert(error.message);
  }
});

$('#addComment')?.addEventListener('click', async () => {
  const content = $('#commentText').value.trim();
  if (!content || !currentTaskId) return;

  try {
    const response = await request('add_comment', {task_id: currentTaskId, content}, {boardId: currentBoardId});
    state.comments.push(response.comment);
    $('#commentText').value = '';
    renderComments(currentTaskId);
  } catch (error) {
    alert(error.message);
  }
});

$('#startTime')?.addEventListener('click', async event => {
  event.preventDefault();
  event.stopPropagation();

  const taskId = Number(currentTaskId || 0);
  if (!taskId) return;

  try {
    await request('start_time', {task_id: taskId}, {boardId: currentBoardId});
    await openBoard(currentBoardId);
    openTask(taskId);
  } catch (error) {
    alert(error.message);
  }
});

$('#stopTime')?.addEventListener('click', async event => {
  event.preventDefault();
  event.stopPropagation();

  const taskId = Number(currentTaskId || 0);
  if (!taskId) return;

  try {
    await request('stop_time', {task_id: taskId}, {boardId: currentBoardId});
    await openBoard(currentBoardId);
    openTask(taskId);
  } catch (error) {
    alert(error.message);
  }
});

$('#projectForm')?.addEventListener('submit', async event => {
  event.preventDefault();

  const form = event.target;
  const payload = Object.fromEntries(new FormData(form).entries());
  payload.member_ids = [...form.querySelectorAll('input[name="member_ids[]"]:checked')].map(input => Number(input.value));
  payload.responsible_id = payload.responsible_id ? Number(payload.responsible_id) : '';

  if (!payload.id) delete payload.id;

  try {
    await request('save_project', payload);
    closeModals();
    resetProjectForm();
    await loadOverview();
  } catch (error) {
    alert(error.message);
  }
});

$('#resetProjectForm')?.addEventListener('click', resetProjectForm);

$('#deleteProject')?.addEventListener('click', () => {
  const projectId = Number($('#projectForm')?.querySelector('input[name="id"]')?.value || 0);
  deleteProject(projectId);
});

$('#userForm')?.addEventListener('submit', async event => {
  event.preventDefault();

  const payload = Object.fromEntries(new FormData(event.target).entries());
  if (!payload.id) delete payload.id;

  try {
    await request('save_user', payload);
    event.target.reset();
    await loadOverview();
  } catch (error) {
    alert(error.message);
  }
});

$('#jsonExportBtn')?.addEventListener('click', async () => {
  try {
    await downloadJsonExport();
  } catch (error) {
    alert(error.message);
  }
});

$('#jsonImportBtn')?.addEventListener('click', async () => {
  const fileInput = $('#jsonImportFile');
  const result = $('#jsonImportResult');
  const file = fileInput?.files?.[0];

  if (!file) {
    alert('Bitte zuerst eine JSON-Datei auswählen.');
    return;
  }

  if (!confirm('JSON wirklich importieren?\nEs wird vorher automatisch ein Backup des aktuellen JSON-Stands angelegt.')) {
    return;
  }

  try {
    const text = await file.text();
    const data = JSON.parse(text);
    const response = await request('json_import', {mode: 'merge', data});

    if (result) {
      result.hidden = false;
      result.textContent = `${response.message || 'Import abgeschlossen.'}\nBackup: ${response.backup_file || '—'}\n\n${summarizeImportReport(response.report)}`;
    }

    alert('JSON-Import abgeschlossen. Die Ansicht wird neu geladen.');

    if (currentBoardId) {
      await openBoard(currentBoardId);
    } else {
      await loadOverview();
    }
  } catch (error) {
    if (result) {
      result.hidden = false;
      result.textContent = 'Import fehlgeschlagen: ' + error.message;
    }
    alert(error.message);
  }
});

$('#jsonMysqlPreviewBtn')?.addEventListener('click', async () => {
  const result = $('#jsonMysqlRestoreResult');
  const restoreBtn = $('#jsonMysqlRestoreBtn');

  try {
    const response = await request('json_mysql_restore_preview');
    lastMysqlRestorePreview = response.preview || null;

    if (result) {
      result.hidden = false;
      result.textContent = `${response.message || 'Vorschau erstellt.'}\n\n${summarizeMysqlRestorePreview(lastMysqlRestorePreview)}`;
    }

    if (restoreBtn) {
      restoreBtn.disabled = false;
    }
  } catch (error) {
    lastMysqlRestorePreview = null;
    if (restoreBtn) restoreBtn.disabled = true;
    if (result) {
      result.hidden = false;
      result.textContent = 'Vorschau fehlgeschlagen: ' + error.message;
    }
    alert(error.message);
  }
});

$('#jsonMysqlRestoreBtn')?.addEventListener('click', async () => {
  const result = $('#jsonMysqlRestoreResult');
  const overwriteConflicts = $('#jsonMysqlOverwriteConflicts')?.checked;
  const conflictCount = Number(lastMysqlRestorePreview?.totals?.conflicts || 0);
  const policy = overwriteConflicts ? 'json_wins' : 'keep_mysql';

  let message = 'JSON-Stand wirklich nach MySQL schreiben?\n\nVorher wird der aktuelle MySQL-Stand als Backup gesichert.';
  if (conflictCount > 0 && overwriteConflicts) {
    message += `\n\nAchtung: ${conflictCount} Konflikt(e) werden durch JSON überschrieben.`;
  } else if (conflictCount > 0) {
    message += `\n\nHinweis: ${conflictCount} Konflikt(e) bleiben in MySQL erhalten.`;
  }

  if (!confirm(message)) {
    return;
  }

  try {
    const response = await request('json_mysql_restore_commit', {conflict_policy: policy});
    lastMysqlRestorePreview = null;

    if (result) {
      result.hidden = false;
      result.textContent = `${response.message || 'Wiederherstellung abgeschlossen.'}\nMySQL-Backup: ${response.backup_file || '—'}\nKonfliktregel: ${response.conflict_policy || policy}\n\n${summarizeMysqlRestoreReport(response.report)}`;
    }

    alert('JSON wurde nach MySQL wiederhergestellt. Die Ansicht wird neu geladen.');

    if (currentBoardId) {
      await openBoard(currentBoardId);
    } else {
      await loadOverview();
    }
  } catch (error) {
    if (result) {
      result.hidden = false;
      result.textContent = 'Wiederherstellung fehlgeschlagen: ' + error.message;
    }
    alert(error.message);
  }
});

$('#openReports')?.addEventListener('click', async () => {
  if (!currentBoardId) {
    alert('Bitte zuerst ein Projektboard öffnen.');
    return;
  }

  try {
    const response = await request('reports', null, {boardId: currentBoardId});
    const summary = response.time_summary || {};
    const reportHtml = (response.reports || []).map(entry => `
      <div class="report-row">
        <div>
          <b>${esc(entry.task.title)}</b><br>
          <small>${esc(entry.task.description || '')}</small>
        </div>
        <strong>${formatSeconds(entry.seconds)}</strong>
      </div>`).join('');
    const historyHtml = (response.history || []).map(entry => `
      <div class="history-entry">
        <b>${esc(historyActionName(entry.action))}</b>
        <span>${esc(entry.message || '')}</span>
        <small>${esc(userName(entry.user_id))} · Aufgabe #${esc(entry.task_id)} · ${esc(entry.created_at)}</small>
      </div>`).join('');

    $('#reports').innerHTML = `
      <h3>Zeit- und Abrechnungsreport</h3>
      <div class="report-total">Gesamtzeit im Board: <strong>${formatSeconds(summary.total_seconds || 0)}</strong></div>

      <div class="report-grid">
        <section>
          <h4>Mitarbeiter</h4>
          ${reportRows(summary.by_user)}
        </section>
        <section>
          <h4>Tagesreport</h4>
          ${reportRows(summary.by_day)}
        </section>
        <section>
          <h4>Monatsreport</h4>
          ${reportRows(summary.by_month)}
        </section>
        <section>
          <h4>Jahresreport</h4>
          ${reportRows(summary.by_year)}
        </section>
        <section>
          <h4>Tag · Mitarbeiter</h4>
          ${reportRows(summary.by_user_day)}
        </section>
        <section>
          <h4>Monat · Mitarbeiter</h4>
          ${reportRows(summary.by_user_month)}
        </section>
        <section>
          <h4>Jahr · Mitarbeiter</h4>
          ${reportRows(summary.by_user_year)}
        </section>
      </div>

      <h3>Einzelbuchungen</h3>
      <div class="report-list compact">${reportDetailRows(response.time_details || [])}</div>

      <h3>Zeit je Aufgabe</h3>
      <div class="report-list">${reportHtml || '<p class="muted">Keine Zeiten vorhanden.</p>'}</div>

      <h3>Letzte Änderungen</h3>
      <div id="globalHistoryList">${historyHtml || '<p class="muted">Noch keine Historie vorhanden.</p>'}</div>`;

    openModal('reportModal');
  } catch (error) {
    alert(error.message);
  }
});

$$('[data-open]').forEach(button => {
  button.addEventListener('click', () => {
    if (button.dataset.open === 'taskModal') {
      openTask(null);
      return;
    }

    if (button.dataset.open === 'projectModal') {
      resetProjectForm();
    }

    openModal(button.dataset.open);
  });
});

$$('[data-close]').forEach(button => button.addEventListener('click', closeModals));
$$('.modal').forEach(modal => modal.addEventListener('click', event => {
  if (event.target === modal) closeModals();
}));

['projectSearch', 'projectMemberFilter', 'projectResponsibleFilter'].forEach(id => {
  $('#' + id)?.addEventListener('input', renderProjectGrid);
  $('#' + id)?.addEventListener('change', renderProjectGrid);
});

['search', 'priorityFilter', 'assigneeFilter'].forEach(id => {
  $('#' + id)?.addEventListener('input', renderBoard);
});

// -----------------------------------------------------------------------------
// Start nach dem Login
// -----------------------------------------------------------------------------

async function loadInitialView() {
  const params = new URLSearchParams(window.location.search);
  const boardId = Number(params.get('board_id') || 0);

  if (boardId > 0) {
    try {
      await openBoard(boardId);
      return;
    } catch (error) {
      alert(error.message);
      replaceUrl('index.php');
    }
  }

  await loadOverview();
}

loadInitialView().catch(error => alert(error.message));
