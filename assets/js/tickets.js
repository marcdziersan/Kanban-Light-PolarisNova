"use strict";

const ticketState = {
    data: null,
    activeTab: "create",
    selectedTicketId: null,
};

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function ticketApi(action, payload = null) {
    const options = { headers: { "Content-Type": "application/json" } };
    if (payload !== null) {
        options.method = "POST";
        options.body = JSON.stringify(payload);
    }
    return fetch(`api/tickets.php?action=${encodeURIComponent(action)}`, options)
        .then(async response => {
            const data = await response.json().catch(() => null);
            if (!data || !data.ok) {
                throw new Error((data && data.error) ? data.error : `HTTP ${response.status}`);
            }
            return data;
        });
}

function esc(value) {
    return String(value ?? "").replace(/[&<>'"]/g, ch => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "'": "&#039;",
        '"': "&quot;",
    }[ch]));
}

function nl2br(value) {
    return esc(value).replace(/\n/g, "<br>");
}

function formatDate(value) {
    if (!value) return "–";
    return String(value).replace("T", " ");
}

function priorityLabel(value) {
    const map = { low: "Niedrig", normal: "Normal", high: "Hoch", critical: "Kritisch" };
    return map[value] || value || "Normal";
}

function setOptions(select, rows, emptyLabel = null) {
    if (!select) return;
    const current = select.value;
    select.innerHTML = "";
    if (emptyLabel !== null) {
        const opt = document.createElement("option");
        opt.value = "";
        opt.textContent = emptyLabel;
        select.appendChild(opt);
    }
    rows.forEach(row => {
        const opt = document.createElement("option");
        opt.value = row.value ?? row.id;
        opt.textContent = row.label ?? row.name ?? row.title ?? row.username ?? String(opt.value);
        select.appendChild(opt);
    });
    if ([...select.options].some(opt => opt.value === current)) {
        select.value = current;
    }
}

function currentFilters() {
    return {
        search: ($("#ticketSearch")?.value || "").trim().toLowerCase(),
        status: $("#ticketStatusFilter")?.value || "",
        area: $("#ticketAreaFilter")?.value || "",
        priority: $("#ticketPriorityFilter")?.value || "",
    };
}

function filteredTickets() {
    const data = ticketState.data;
    if (!data) return [];
    const f = currentFilters();
    return (data.tickets || []).filter(ticket => {
        if (f.status && ticket.status !== f.status) return false;
        if (f.area && ticket.area !== f.area) return false;
        if (f.priority && ticket.priority !== f.priority) return false;
        if (f.search) {
            const haystack = [
                ticket.ticket_number,
                ticket.title,
                ticket.area_label,
                ticket.status_label,
                ticket.priority,
                ticket.project_name,
                ticket.task_title,
                ticket.created_by_name,
                ticket.expected_result,
                ticket.actual_result,
                ticket.steps,
                ticket.admin_note,
            ].join(" ").toLowerCase();
            if (!haystack.includes(f.search)) return false;
        }
        return true;
    });
}

function ticketBadges(ticket) {
    return `
        <span class="ticket-badge status-${esc(ticket.status)}">${esc(ticket.status_label)}</span>
        <span class="ticket-badge priority-${esc(ticket.priority)}">${esc(priorityLabel(ticket.priority))}</span>
        <span class="ticket-badge">${esc(ticket.area_label)}</span>
        ${ticket.project_name ? `<span class="ticket-badge">Projekt: ${esc(ticket.project_name)}</span>` : ""}
        ${ticket.task_title ? `<span class="ticket-badge">Aufgabe: ${esc(ticket.task_title)}</span>` : ""}
    `;
}

function renderTicketList() {
    const box = $("#ticketList");
    if (!box || !ticketState.data) return;
    const tickets = filteredTickets();
    if (tickets.length === 0) {
        box.innerHTML = `<p class="muted no-margin-left">Keine passenden Tickets gefunden.</p>`;
        return;
    }
    box.innerHTML = tickets.map(ticket => `
        <article class="ticket-item" data-ticket-id="${ticket.id}">
            <div class="ticket-item-head">
                <div>
                    <h3>${esc(ticket.ticket_number)} · ${esc(ticket.title)}</h3>
                    <p class="muted no-margin-left">Erstellt von ${esc(ticket.created_by_name)} · ${formatDate(ticket.created_at)}</p>
                </div>
                <button type="button" data-open-ticket="${ticket.id}">Öffnen</button>
            </div>
            <div class="ticket-meta">${ticketBadges(ticket)}</div>
            <p>${nl2br(ticket.actual_result || "")}</p>
            ${ticket.admin_note ? `<p><b>Admin-Notiz:</b> ${nl2br(ticket.admin_note)}</p>` : ""}
            <div class="ticket-actions">
                ${ticket.can_confirm ? `<button type="button" data-confirm-ticket="${ticket.id}">Ergebnis bestätigen</button>` : ""}
            </div>
        </article>
    `).join("");
}

function renderBoard() {
    const board = $("#ticketBoard");
    if (!board || !ticketState.data || !ticketState.data.user.is_admin) return;
    const tickets = filteredTickets();
    const columns = ticketState.data.status_columns || [];
    board.innerHTML = columns.map(column => {
        const colTickets = tickets.filter(ticket => ticket.status === column.value);
        return `
            <section class="ticket-column" data-status="${esc(column.value)}">
                <h3>${esc(column.label)} <span class="ticket-column-count">${colTickets.length}</span></h3>
                ${colTickets.map(ticket => `
                    <article class="ticket-card" data-ticket-id="${ticket.id}">
                        <div class="ticket-card-head">
                            <div>
                                <h3>${esc(ticket.ticket_number)}</h3>
                                <p><b>${esc(ticket.title)}</b></p>
                            </div>
                        </div>
                        <div class="ticket-meta">${ticketBadges(ticket)}</div>
                        <p class="muted no-margin-left">Von ${esc(ticket.created_by_name)} · ${formatDate(ticket.created_at)}</p>
                        <div class="ticket-actions">
                            <button type="button" data-open-ticket="${ticket.id}">Bearbeiten</button>
                            ${adminQuickButtons(ticket)}
                        </div>
                    </article>
                `).join("") || `<p class="muted no-margin-left">Keine Tickets.</p>`}
            </section>
        `;
    }).join("");
}

function adminQuickButtons(ticket) {
    if (!ticketState.data?.user?.is_admin) return "";
    const transitions = [];
    if (ticket.status === "new") transitions.push(["accepted", "Annehmen"]);
    if (["new", "accepted", "waiting"].includes(ticket.status)) transitions.push(["in_progress", "In Arbeit"]);
    if (["accepted", "in_progress", "waiting"].includes(ticket.status)) transitions.push(["done", "Erledigt"]);
    if (!["done", "confirmed", "rejected"].includes(ticket.status)) transitions.push(["waiting", "Rückfrage"]);
    if (!["confirmed", "rejected"].includes(ticket.status)) transitions.push(["rejected", "Ablehnen"]);
    return transitions.map(([status, label]) => `<button type="button" data-status-ticket="${ticket.id}" data-status="${status}">${label}</button>`).join("");
}

function renderFiltersAndForms() {
    const data = ticketState.data;
    if (!data) return;

    setOptions($("#ticketAreaSelect"), data.areas || []);
    setOptions($("#ticketAreaFilter"), data.areas || [], "Alle Bereiche");
    setOptions($("#ticketStatusFilter"), data.status_columns || [], "Alle Status");
    setOptions($("#ticketProjectSelect"), data.projects || [], "Ohne Projektbezug");

    const taskSelect = $("#ticketTaskSelect");
    const projectSelect = $("#ticketProjectSelect");
    if (taskSelect) {
        const projectId = Number(projectSelect?.value || 0);
        const tasks = (data.tasks || [])
            .filter(task => !projectId || Number(task.project_id) === projectId)
            .map(task => ({ id: task.id, title: `${task.project_name ? task.project_name + " · " : ""}${task.title}` }));
        setOptions(taskSelect, tasks, "Ohne Aufgabenbezug");
    }
}

function renderAll() {
    renderFiltersAndForms();
    renderTicketList();
    renderBoard();
}

function loadTickets() {
    return ticketApi("bootstrap")
        .then(res => {
            ticketState.data = res.data;
            renderAll();
        })
        .catch(err => alert(err.message));
}

function showTab(tab) {
    ticketState.activeTab = tab;
    $$(".ticket-tab").forEach(btn => btn.classList.toggle("active", btn.dataset.tab === tab));
    $$(".ticket-panel").forEach(panel => {
        panel.hidden = panel.dataset.view !== tab;
    });
}

function ticketById(id) {
    return (ticketState.data?.tickets || []).find(ticket => Number(ticket.id) === Number(id));
}

function adminForm(ticket) {
    if (!ticketState.data?.user?.is_admin) return "";
    const admins = ticketState.data.admins || [];
    const statuses = ticketState.data.status_columns || [];
    return `
        <section class="ticket-detail-box">
            <h4>Admin-Bearbeitung</h4>
            <form class="ticket-admin-form" id="ticketAdminForm">
                <input type="hidden" name="id" value="${ticket.id}">
                <div class="ticket-admin-grid">
                    <label>Status
                        <select name="status">
                            ${statuses.map(s => `<option value="${esc(s.value)}" ${s.value === ticket.status ? "selected" : ""}>${esc(s.label)}</option>`).join("")}
                        </select>
                    </label>
                    <label>Priorität
                        <select name="priority">
                            ${["critical", "high", "normal", "low"].map(p => `<option value="${p}" ${p === ticket.priority ? "selected" : ""}>${esc(priorityLabel(p))}</option>`).join("")}
                        </select>
                    </label>
                    <label>Zuständiger Admin
                        <select name="assigned_admin_id">
                            <option value="">Nicht zugewiesen</option>
                            ${admins.map(a => `<option value="${a.id}" ${Number(a.id) === Number(ticket.assigned_admin_id) ? "selected" : ""}>${esc(a.username)}</option>`).join("")}
                        </select>
                    </label>
                </div>
                <label>Admin-Notiz intern/öffentlich sichtbar im Ticket
                    <textarea name="admin_note" placeholder="Interne Bearbeitungsnotiz oder Ergebnisnotiz">${esc(ticket.admin_note || "")}</textarea>
                </label>
                <label>Kommentar zur Rückmeldung
                    <textarea name="comment" placeholder="Diese Rückmeldung geht an den Ticketersteller, wenn nicht admin-intern markiert."></textarea>
                </label>
                <label><input type="checkbox" name="admin_only" value="1"> Kommentar nur intern für Admins speichern</label>
                <button type="submit">Ticket speichern und Ersteller informieren</button>
            </form>
        </section>
    `;
}

function userConfirmBlock(ticket) {
    if (!ticket.can_confirm) return "";
    return `
        <section class="ticket-detail-box">
            <h4>Ergebnis bestätigen</h4>
            <form id="ticketConfirmForm" class="ticket-comment-form">
                <input type="hidden" name="id" value="${ticket.id}">
                <label>Notiz optional
                    <textarea name="note" placeholder="Optional: Rückmeldung zum Ergebnis"></textarea>
                </label>
                <button type="submit">Ticket als bestätigt markieren</button>
            </form>
        </section>
    `;
}

function commentsBlock(ticket) {
    const comments = ticket.comments || [];
    return `
        <section class="ticket-detail-box">
            <h4>Kommentare und Verlauf</h4>
            ${comments.length ? comments.map(c => `
                <div class="ticket-comment ${c.visibility === "admin" ? "admin-only" : ""}">
                    <b>${esc(c.user_name)}</b> <span class="muted">${formatDate(c.created_at)}${c.visibility === "admin" ? " · admin-intern" : ""}</span>
                    <p>${nl2br(c.content)}</p>
                </div>
            `).join("") : `<p class="muted no-margin-left">Noch keine Kommentare.</p>`}
            <form id="ticketCommentForm" class="ticket-comment-form">
                <input type="hidden" name="id" value="${ticket.id}">
                <label>Kommentar
                    <textarea name="content" placeholder="Kommentar oder Rückfrage ergänzen" required></textarea>
                </label>
                ${ticketState.data?.user?.is_admin ? `<label><input type="checkbox" name="admin_only" value="1"> nur admin-intern speichern</label>` : ""}
                <button type="submit">Kommentar speichern</button>
            </form>
        </section>
    `;
}

function openTicket(id) {
    const ticket = ticketById(id);
    if (!ticket) return;
    ticketState.selectedTicketId = id;
    $("#ticketModalTitle").textContent = `${ticket.ticket_number} · ${ticket.title}`;
    $("#ticketModalMeta").textContent = `${ticket.status_label} · ${ticket.area_label} · ${formatDate(ticket.created_at)}`;
    $("#ticketModalBody").innerHTML = `
        <div class="ticket-meta">${ticketBadges(ticket)}</div>
        <div class="ticket-detail-grid">
            <section class="ticket-detail-box">
                <h4>Ist-Zustand</h4>
                <p>${nl2br(ticket.actual_result || "–")}</p>
            </section>
            <section class="ticket-detail-box">
                <h4>Soll-Zustand</h4>
                <p>${nl2br(ticket.expected_result || "–")}</p>
            </section>
            <section class="ticket-detail-box">
                <h4>Schritte / Kontext</h4>
                <p>${nl2br(ticket.steps || "–")}</p>
            </section>
            <section class="ticket-detail-box">
                <h4>Zuordnung</h4>
                <p><b>Ersteller:</b> ${esc(ticket.created_by_name)}</p>
                <p><b>Admin:</b> ${esc(ticket.assigned_admin_name || "nicht zugewiesen")}</p>
                <p><b>Projekt:</b> ${esc(ticket.project_name || "–")}</p>
                <p><b>Aufgabe:</b> ${esc(ticket.task_title || "–")}</p>
            </section>
        </div>
        ${adminForm(ticket)}
        ${userConfirmBlock(ticket)}
        ${commentsBlock(ticket)}
    `;
    $("#ticketModal").hidden = false;
}

function closeTicketModal() {
    $("#ticketModal").hidden = true;
    ticketState.selectedTicketId = null;
}

function formToObject(form) {
    const fd = new FormData(form);
    const out = {};
    for (const [key, value] of fd.entries()) {
        out[key] = value;
    }
    return out;
}

function submitCreate(form) {
    const payload = formToObject(form);
    payload.project_id = payload.project_id ? Number(payload.project_id) : null;
    payload.task_id = payload.task_id ? Number(payload.task_id) : null;
    ticketApi("create_ticket", payload)
        .then(res => {
            ticketState.data = res.data;
            form.reset();
            renderAll();
            showTab("mine");
            alert("Ticket wurde erstellt.");
        })
        .catch(err => alert(err.message));
}

function submitAdmin(form) {
    const payload = formToObject(form);
    payload.id = Number(payload.id);
    payload.assigned_admin_id = payload.assigned_admin_id ? Number(payload.assigned_admin_id) : null;
    payload.admin_only = !!form.querySelector('[name="admin_only"]')?.checked;
    ticketApi("update_ticket", payload)
        .then(res => {
            ticketState.data = res.data;
            renderAll();
            openTicket(payload.id);
            alert("Ticket wurde aktualisiert. Der Ersteller wurde informiert, sofern eine öffentliche Rückmeldung vorliegt oder der Status geändert wurde.");
        })
        .catch(err => alert(err.message));
}

function submitComment(form) {
    const payload = formToObject(form);
    payload.id = Number(payload.id);
    payload.admin_only = !!form.querySelector('[name="admin_only"]')?.checked;
    ticketApi("add_comment", payload)
        .then(res => {
            ticketState.data = res.data;
            renderAll();
            openTicket(payload.id);
        })
        .catch(err => alert(err.message));
}

function submitConfirm(form) {
    const payload = formToObject(form);
    payload.id = Number(payload.id);
    ticketApi("confirm_ticket", payload)
        .then(res => {
            ticketState.data = res.data;
            renderAll();
            openTicket(payload.id);
            alert("Ticket wurde bestätigt.");
        })
        .catch(err => alert(err.message));
}

function quickStatus(id, status) {
    ticketApi("update_ticket", { id: Number(id), status, assigned_admin_id: window.POLARIS_USER.id, comment: "" })
        .then(res => {
            ticketState.data = res.data;
            renderAll();
        })
        .catch(err => alert(err.message));
}

document.addEventListener("click", event => {
    const tab = event.target.closest(".ticket-tab");
    if (tab) {
        showTab(tab.dataset.tab);
        return;
    }

    const openBtn = event.target.closest("[data-open-ticket]");
    if (openBtn) {
        openTicket(Number(openBtn.dataset.openTicket));
        return;
    }

    const statusBtn = event.target.closest("[data-status-ticket]");
    if (statusBtn) {
        quickStatus(statusBtn.dataset.statusTicket, statusBtn.dataset.status);
        return;
    }

    const confirmBtn = event.target.closest("[data-confirm-ticket]");
    if (confirmBtn) {
        ticketApi("confirm_ticket", { id: Number(confirmBtn.dataset.confirmTicket), note: "" })
            .then(res => {
                ticketState.data = res.data;
                renderAll();
                alert("Ticket wurde bestätigt.");
            })
            .catch(err => alert(err.message));
        return;
    }

    if (event.target.closest("#ticketModalClose")) {
        closeTicketModal();
    }
});

document.addEventListener("submit", event => {
    const form = event.target;
    if (form.id === "ticketCreateForm") {
        event.preventDefault();
        submitCreate(form);
    }
    if (form.id === "ticketAdminForm") {
        event.preventDefault();
        submitAdmin(form);
    }
    if (form.id === "ticketCommentForm") {
        event.preventDefault();
        submitComment(form);
    }
    if (form.id === "ticketConfirmForm") {
        event.preventDefault();
        submitConfirm(form);
    }
});

["ticketSearch", "ticketStatusFilter", "ticketAreaFilter", "ticketPriorityFilter"].forEach(id => {
    document.addEventListener("input", event => {
        if (event.target.id === id) renderAll();
    });
    document.addEventListener("change", event => {
        if (event.target.id === id) renderAll();
    });
});

$("#ticketProjectSelect")?.addEventListener("change", renderFiltersAndForms);
$("#ticketReloadBtn")?.addEventListener("click", loadTickets);
$("#ticketPrintBtn")?.addEventListener("click", () => window.print());

loadTickets();
