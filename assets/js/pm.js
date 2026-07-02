"use strict";

const pmState = {
    data: null,
    activeTab: "inbox",
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function esc(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function formatDateTime(value) {
    if (!value) return "—";
    const normalized = String(value).replace(" ", "T");
    const d = new Date(normalized);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString("de-DE", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

async function pmApi(action, payload = null) {
    const options = payload === null ? {} : {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    };

    const response = await fetch(`api/pm.php?action=${encodeURIComponent(action)}`, options);
    const json = await response.json().catch(() => ({ ok: false, error: "Ungültige Serverantwort" }));

    if (!response.ok || !json.ok) {
        throw new Error(json.error || `Request fehlgeschlagen (${response.status})`);
    }

    return json;
}

function setData(data) {
    pmState.data = data;
    fillSelects();
    renderAll();
}

async function loadPm() {
    try {
        const json = await pmApi("bootstrap");
        setData(json.data);
    } catch (error) {
        alert(error.message);
    }
}

function currentUser() {
    return pmState.data?.user || window.POLARIS_USER || { id: 0, role: "guest" };
}

function fillSelects() {
    const data = pmState.data;
    if (!data) return;

    const userOptions = (data.users || [])
        .filter(user => Number(user.id) !== Number(data.user.id))
        .map(user => `<option value="${Number(user.id)}">${esc(user.username)} (${esc(user.role)})</option>`)
        .join("");

    const directSelect = $("#pmDirectRecipient");
    if (directSelect) {
        directSelect.innerHTML = userOptions || `<option value="">Keine aktiven Empfänger gefunden</option>`;
    }

    const projectOptions = (data.projects || [])
        .map(project => `<option value="${Number(project.id)}">${esc(project.name)}</option>`)
        .join("");

    const projectSelect = $("#pmProjectSelect");
    if (projectSelect) {
        projectSelect.innerHTML = projectOptions || `<option value="">Kein sichtbares Projekt gefunden</option>`;
    }

    const filter = $("#pmProjectFilter");
    if (filter && filter.options.length <= 1) {
        filter.innerHTML = `<option value="">Alle sichtbaren Projekte</option>` + projectOptions;
    }
}

function typeLabel(type) {
    if (type === "direct") return "Privat";
    if (type === "project") return "Projekt";
    if (type === "company") return "Gesamt";
    return type;
}

function priorityLabel(priority) {
    if (priority === "urgent") return "Dringend";
    if (priority === "important") return "Wichtig";
    return "Normal";
}

function filteredMessages() {
    const data = pmState.data;
    if (!data) return [];

    const search = ($("#pmSearch")?.value || "").trim().toLowerCase();
    const projectFilter = $("#pmProjectFilter")?.value || "";
    const readFilter = $("#pmReadFilter")?.value || "";

    return (data.messages || []).filter(message => {
        if (projectFilter && Number(message.project_id || 0) !== Number(projectFilter)) {
            return false;
        }
        if (readFilter === "unread" && message.is_read) return false;
        if (readFilter === "read" && !message.is_read) return false;

        if (search) {
            const haystack = [
                message.subject,
                message.content,
                message.sender_name,
                message.recipient_name,
                message.project_name,
                typeLabel(message.type),
            ].join(" ").toLowerCase();
            if (!haystack.includes(search)) return false;
        }

        return true;
    });
}

function messageCard(message) {
    const subject = message.subject ? message.subject : "Ohne Betreff";
    const unread = message.is_read ? "" : " unread";
    const target = message.type === "direct"
        ? `Von ${esc(message.sender_name)} an ${esc(message.recipient_name || "—")}`
        : message.type === "project"
            ? `Projekt: ${esc(message.project_name || "—")} · Von ${esc(message.sender_name)}`
            : `Gesamtchat · Von ${esc(message.sender_name)}`;

    return `
        <article class="pm-card${unread}" data-message-id="${Number(message.id)}">
            <div class="pm-card-head">
                <div>
                    <div class="pm-title">
                        <span class="pm-badge ${esc(message.type)}">${esc(typeLabel(message.type))}</span>
                        ${message.is_read ? "" : `<span class="pm-badge unread">Ungelesen</span>`}
                        <strong>${esc(subject)}</strong>
                    </div>
                    <div class="pm-meta">${target}<br>${formatDateTime(message.created_at)}</div>
                </div>
                <div class="pm-card-actions">
                    ${message.is_read ? "" : `<button type="button" data-action="mark-message-read" data-id="${Number(message.id)}">Als gelesen</button>`}
                    ${message.can_delete ? `<button type="button" class="danger" data-action="delete-message" data-id="${Number(message.id)}">Löschen</button>` : ""}
                </div>
            </div>
            <div class="pm-content">${esc(message.content)}</div>
        </article>
    `;
}

function pinCard(pin) {
    return `
        <article class="pm-card ${esc(pin.priority)}${pin.is_read ? "" : " unread"}" data-pin-id="${Number(pin.id)}">
            <div class="pm-card-head">
                <div>
                    <div class="pm-title">
                        <span class="pm-badge ${esc(pin.priority)}">${esc(priorityLabel(pin.priority))}</span>
                        ${pin.is_active ? "" : `<span class="pm-badge">Entwurf/Inaktiv</span>`}
                        ${pin.is_read ? "" : `<span class="pm-badge unread">Ungelesen</span>`}
                        <strong>${esc(pin.title)}</strong>
                    </div>
                    <div class="pm-meta">
                        Admin: ${esc(pin.created_by_name)} · ${formatDateTime(pin.created_at)}
                        ${pin.expires_at ? `<br>Ablaufdatum: ${esc(pin.expires_at)}` : ""}
                    </div>
                </div>
                <div class="pm-card-actions">
                    ${pin.is_read ? "" : `<button type="button" data-action="mark-pin-read" data-id="${Number(pin.id)}">Als gelesen</button>`}
                    ${currentUser().role === "admin" ? `<button type="button" data-action="edit-pin" data-id="${Number(pin.id)}">Bearbeiten</button><button type="button" class="danger" data-action="delete-pin" data-id="${Number(pin.id)}">Löschen</button>` : ""}
                </div>
            </div>
            <div class="pm-content">${esc(pin.content)}</div>
        </article>
    `;
}

function renderInbox() {
    const list = $("#pmInboxList");
    if (!list) return;

    const messages = filteredMessages();
    list.innerHTML = messages.length
        ? messages.map(messageCard).join("")
        : `<div class="pm-empty">Keine Nachrichten für die aktuelle Auswahl.</div>`;
}

function renderPinboard() {
    const list = $("#pmPinboardList");
    if (!list || !pmState.data) return;

    const pins = pmState.data.pinboard || [];
    list.innerHTML = pins.length
        ? pins.map(pinCard).join("")
        : `<div class="pm-empty">Keine aktiven Pinnwand-Nachrichten vorhanden.</div>`;
}

function renderUnread() {
    const el = $("#pmUnreadTotal");
    if (!el || !pmState.data) return;
    el.textContent = String(pmState.data.unread?.total ?? 0);
}

function renderAll() {
    renderInbox();
    renderPinboard();
    renderUnread();
}

function setTab(tabName) {
    pmState.activeTab = tabName;
    $$(".pm-tab").forEach(button => button.classList.toggle("active", button.dataset.tab === tabName));
    $$(".pm-panel").forEach(panel => {
        panel.hidden = panel.dataset.view !== tabName;
    });
}

function formData(form) {
    return Object.fromEntries(new FormData(form).entries());
}

async function sendMessage(type, form) {
    const payload = formData(form);
    payload.type = type;

    if (type === "direct") payload.recipient_id = Number(payload.recipient_id || 0);
    if (type === "project") payload.project_id = Number(payload.project_id || 0);

    try {
        const json = await pmApi("send_message", payload);
        form.reset();
        setData(json.data);
        setTab("inbox");
    } catch (error) {
        alert(error.message);
    }
}

async function savePin(form) {
    const payload = formData(form);
    payload.id = Number(payload.id || 0);
    payload.is_active = payload.is_active === "1";

    try {
        const json = await pmApi("save_pin", payload);
        form.reset();
        setData(json.data);
        setTab("pinboard");
    } catch (error) {
        alert(error.message);
    }
}

function editPin(id) {
    const pin = (pmState.data?.pinboard || []).find(row => Number(row.id) === Number(id));
    const form = $("#pmPinForm");
    if (!pin || !form) return;

    form.elements.id.value = pin.id;
    form.elements.title.value = pin.title || "";
    form.elements.priority.value = pin.priority || "normal";
    form.elements.is_active.value = pin.is_active ? "1" : "0";
    form.elements.expires_at.value = pin.expires_at || "";
    form.elements.content.value = pin.content || "";
    setTab("admin-pin");
}

async function handleAction(action, id) {
    try {
        let apiAction = null;
        let ask = null;

        if (action === "mark-message-read") apiAction = "mark_message_read";
        if (action === "mark-pin-read") apiAction = "mark_pin_read";
        if (action === "delete-message") {
            apiAction = "delete_message";
            ask = "Nachricht wirklich löschen?";
        }
        if (action === "delete-pin") {
            apiAction = "delete_pin";
            ask = "Pinnwand-Eintrag wirklich löschen?";
        }
        if (action === "edit-pin") {
            editPin(id);
            return;
        }

        if (!apiAction) return;
        if (ask && !confirm(ask)) return;

        const json = await pmApi(apiAction, { id: Number(id) });
        setData(json.data);
    } catch (error) {
        alert(error.message);
    }
}

function bindEvents() {
    $$(".pm-tab").forEach(button => {
        button.addEventListener("click", () => setTab(button.dataset.tab));
    });

    $("#pmReloadBtn")?.addEventListener("click", loadPm);
    $("#pmPrintBtn")?.addEventListener("click", () => window.print());

    $("#pmSearch")?.addEventListener("input", renderInbox);
    $("#pmProjectFilter")?.addEventListener("change", renderInbox);
    $("#pmReadFilter")?.addEventListener("change", renderInbox);

    $("#pmDirectForm")?.addEventListener("submit", event => {
        event.preventDefault();
        sendMessage("direct", event.currentTarget);
    });

    $("#pmProjectForm")?.addEventListener("submit", event => {
        event.preventDefault();
        sendMessage("project", event.currentTarget);
    });

    $("#pmCompanyForm")?.addEventListener("submit", event => {
        event.preventDefault();
        sendMessage("company", event.currentTarget);
    });

    $("#pmPinForm")?.addEventListener("submit", event => {
        event.preventDefault();
        savePin(event.currentTarget);
    });

    $("#pmPinResetBtn")?.addEventListener("click", () => $("#pmPinForm")?.reset());

    document.addEventListener("click", event => {
        const btn = event.target.closest("[data-action]");
        if (!btn) return;
        handleAction(btn.dataset.action, btn.dataset.id);
    });
}

bindEvents();
loadPm();
