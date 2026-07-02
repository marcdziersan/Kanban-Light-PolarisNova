"use strict";

const ACC_API = "api/accounting.php?action=";
const $ = selector => document.querySelector(selector);
const $$ = selector => [...document.querySelectorAll(selector)];
const esc = value => String(value ?? "").replace(/[&<>"']/g, match => ({
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  "\"": "&quot;",
  "'": "&#039;"
}[match]));

let accState = {
  users: [],
  customers: [],
  projects: [],
  invoices: [],
  invoice_items: [],
  eur_entries: [],
  time_rows: [],
  user: window.APP_USER || {}
};

function todayValue() {
  return new Date().toISOString().slice(0, 10);
}

function currentMonthValue() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function formatDateGerman(value) {
  if (!value) return "—";
  const parts = String(value).slice(0, 10).split("-");
  return parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : String(value);
}

function euro(value) {
  return new Intl.NumberFormat("de-DE", {style: "currency", currency: "EUR"}).format(Number(value || 0));
}

function hours(value) {
  return `${Number(value || 0).toLocaleString("de-DE", {minimumFractionDigits: 2, maximumFractionDigits: 2})} h`;
}

function csvEscape(value) {
  return '"' + String(value ?? "").replace(/"/g, '""') + '"';
}

async function accRequest(action, payload = null) {
  const options = payload
    ? {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify(payload), credentials: "include"}
    : {credentials: "include"};

  const response = await fetch(ACC_API + encodeURIComponent(action), options);
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${response.status}`);
  }
  return data;
}

function userName(id) {
  const user = accState.users.find(row => Number(row.id) === Number(id));
  return user ? user.username : "—";
}

function customerName(id) {
  const customer = accState.customers.find(row => Number(row.id) === Number(id));
  return customer ? customer.company : "—";
}

function projectName(id) {
  const project = accState.projects.find(row => Number(row.id) === Number(id));
  return project ? project.name : "—";
}

function invoiceItems(invoiceId) {
  return accState.invoice_items.filter(item => Number(item.invoice_id) === Number(invoiceId));
}

function invoiceById(invoiceId) {
  return accState.invoices.find(inv => Number(inv.id) === Number(invoiceId));
}

function setView(view) {
  $$(".accounting-panel").forEach(panel => {
    const active = panel.dataset.view === view;
    panel.hidden = !active;
  });
  $$(".accounting-tab").forEach(button => button.classList.toggle("active", button.dataset.tab === view));
}

function statusLabel(status) {
  return {
    draft: "Entwurf",
    sent: "Gesendet",
    paid: "Bezahlt",
    cancelled: "Storniert"
  }[status] || status || "—";
}

function statusClass(status) {
  return `status-badge status-${esc(status || "draft")}`;
}

function renderDashboard() {
  const paid = accState.invoices.filter(inv => inv.status === "paid");
  const open = accState.invoices.filter(inv => inv.status !== "paid" && inv.status !== "cancelled");
  const invoiceNet = accState.invoices.reduce((sum, inv) => sum + Number(inv.net_amount || 0), 0);
  const paidNet = paid.reduce((sum, inv) => sum + Number(inv.net_amount || 0), 0);
  const expenseNet = accState.eur_entries.filter(e => e.type === "expense").reduce((sum, e) => sum + Number(e.amount_net || 0), 0);
  const uninvoicedSeconds = accState.time_rows.filter(row => !row.invoiced && row.stopped_at).reduce((sum, row) => sum + Number(row.seconds || 0), 0);

  $("#accountingDashboard").innerHTML = `
    <article class="accounting-kpi"><b>${esc(accState.invoices.length)}</b><span>Rechnungen gesamt</span></article>
    <article class="accounting-kpi"><b>${esc(open.length)}</b><span>offen / Entwurf</span></article>
    <article class="accounting-kpi"><b>${euro(paidNet)}</b><span>bezahlter Umsatz netto</span></article>
    <article class="accounting-kpi"><b>${euro(invoiceNet)}</b><span>Rechnungsvolumen netto</span></article>
    <article class="accounting-kpi"><b>${euro(expenseNet)}</b><span>manuelle Ausgaben netto</span></article>
    <article class="accounting-kpi"><b>${hours(uninvoicedSeconds / 3600)}</b><span>noch nicht abgerechnete Zeiten</span></article>
  `;
}

function renderInvoiceList() {
  const filter = $("#invoiceStatusFilter")?.value || "";
  const term = String($("#invoiceSearch")?.value || "").trim().toLowerCase();
  const rows = accState.invoices
    .filter(inv => !filter || inv.status === filter)
    .filter(inv => {
      if (!term) return true;
      return [inv.number, inv.customer_name, inv.project_name, inv.title, inv.status].join(" ").toLowerCase().includes(term);
    });

  $("#invoiceList").innerHTML = rows.length ? rows.map(inv => {
    const items = invoiceItems(inv.id);
    const itemHtml = items.map(item => `
      <tr>
        <td>${esc(item.position)}</td>
        <td>${esc(formatDateGerman(item.work_date))}</td>
        <td>${esc(item.user_name || userName(item.user_id))}</td>
        <td>${esc(item.project_name || projectName(item.project_id))}</td>
        <td>${esc(item.task_title || "—")}<br><small>${esc(item.description || "")}</small></td>
        <td class="num">${hours(item.quantity_hours)}</td>
        <td class="num">${euro(item.unit_price)}</td>
        <td class="num"><b>${euro(item.net_amount)}</b></td>
      </tr>`).join("");

    const adminControls = accState.user.role === "admin" ? `
      <div class="invoice-actions">
        <select data-invoice-status="${esc(inv.id)}">
          ${["draft", "sent", "paid", "cancelled"].map(status => `<option value="${status}" ${inv.status === status ? "selected" : ""}>${statusLabel(status)}</option>`).join("")}
        </select>
        <input type="date" data-invoice-paid="${esc(inv.id)}" value="${esc(inv.paid_at || todayValue())}">
        <button type="button" data-save-invoice="${esc(inv.id)}">Status speichern</button>
        <button type="button" class="danger" data-delete-invoice="${esc(inv.id)}">Löschen</button>
      </div>` : "";

    return `
      <article class="invoice-card">
        <div class="invoice-card-head">
          <div>
            <h3>${esc(inv.number)} · ${esc(inv.title || "Rechnung")}</h3>
            <p class="muted no-margin-left">${esc(inv.customer_name || "kein Kunde")} · ${esc(inv.project_name || "kein Projekt")} · ${formatDateGerman(inv.invoice_date)} · fällig ${formatDateGerman(inv.due_date)}</p>
          </div>
          <div class="invoice-total">
            <span class="${statusClass(inv.status)}">${statusLabel(inv.status)}</span>
            <b>${euro(inv.gross_amount)}</b>
            <small>netto ${euro(inv.net_amount)} · USt. ${euro(inv.vat_amount)}</small>
          </div>
        </div>
        ${adminControls}
        <div class="accounting-table-wrap">
          <table class="accounting-table">
            <thead><tr><th>#</th><th>Datum</th><th>Mitarbeiter</th><th>Projekt</th><th>Aufgabe/Leistung</th><th class="num">Zeit</th><th class="num">Satz</th><th class="num">Netto</th></tr></thead>
            <tbody>${itemHtml || `<tr><td colspan="8">Keine Positionen vorhanden.</td></tr>`}</tbody>
          </table>
        </div>
        ${inv.notes ? `<p class="invoice-note">${esc(inv.notes)}</p>` : ""}
      </article>`;
  }).join("") : `<p class="muted">Keine Rechnungen vorhanden.</p>`;

  bindInvoiceButtons();
}

function bindInvoiceButtons() {
  $$('[data-save-invoice]').forEach(button => {
    button.addEventListener('click', async () => {
      const id = Number(button.dataset.saveInvoice);
      const status = document.querySelector(`[data-invoice-status="${id}"]`)?.value || 'draft';
      const paidAt = document.querySelector(`[data-invoice-paid="${id}"]`)?.value || todayValue();
      try {
        const response = await accRequest('save_invoice_status', {id, status, paid_at: paidAt});
        accState = response.data;
        renderAll();
      } catch (error) {
        alert(error.message);
      }
    });
  });

  $$('[data-delete-invoice]').forEach(button => {
    button.addEventListener('click', async () => {
      const id = Number(button.dataset.deleteInvoice);
      if (!confirm('Rechnung wirklich löschen?')) return;
      try {
        const response = await accRequest('delete_invoice', {id});
        accState = response.data;
        renderAll();
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

function fillBuilderSelects() {
  const customerSelect = $("#invoiceCustomer");
  const projectSelect = $("#invoiceProject");
  const eurUser = $("#eurUser");
  const eurEntryUser = $("#eurEntryUser");

  if (customerSelect) {
    customerSelect.innerHTML = `<option value="">Kunde aus Projekt übernehmen</option>` +
      accState.customers.map(c => `<option value="${esc(c.id)}">${esc(c.company)}</option>`).join("");
  }

  if (projectSelect) {
    projectSelect.innerHTML = `<option value="">Alle Projekte</option>` +
      accState.projects.map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join("");
  }

  const userOptions = accState.users
    .filter(user => user.role !== "guest")
    .map(user => `<option value="${esc(user.id)}">${esc(user.username)} (${esc(user.role)})</option>`).join("");

  if (eurUser) {
    if (accState.user.role === "admin") {
      eurUser.innerHTML = `<option value="">Chef / Gesamt</option>` + userOptions;
    } else {
      eurUser.innerHTML = `<option value="${esc(accState.user.id)}">${esc(accState.user.username)}</option>`;
    }
  }

  if (eurEntryUser) {
    eurEntryUser.innerHTML = `<option value="">Allgemein / Chef</option>` + userOptions;
  }
}

function renderBillableTimes() {
  const projectId = Number($("#invoiceProject")?.value || 0);
  const customerId = Number($("#invoiceCustomer")?.value || 0);
  const rows = accState.time_rows.filter(row => {
    if (row.invoiced || !row.stopped_at || Number(row.seconds || 0) <= 0) return false;
    if (projectId && Number(row.project_id) !== projectId) return false;
    if (customerId && Number(row.customer_id || 0) !== customerId) return false;
    return true;
  });

  const body = rows.length ? rows.map(row => `
    <tr>
      <td><input type="checkbox" class="billable-time-check" value="${esc(row.id)}"></td>
      <td>${esc(formatDateGerman(row.work_date))}</td>
      <td>${esc(row.customer_name || '—')}</td>
      <td>${esc(row.project_name)}</td>
      <td>${esc(row.user_name)}</td>
      <td>${esc(row.task_title)}<br><small>#${esc(row.task_id)}</small></td>
      <td class="num"><b>${hours(row.hours)}</b></td>
    </tr>`).join("") : `<tr><td colspan="7"><p class="muted">Keine abrechenbaren Zeiten für diese Auswahl vorhanden.</p></td></tr>`;

  $("#billableTimeList").innerHTML = `
    <table class="accounting-table">
      <thead><tr><th></th><th>Datum</th><th>Kunde</th><th>Projekt</th><th>Mitarbeiter</th><th>Aufgabe</th><th class="num">Zeit</th></tr></thead>
      <tbody>${body}</tbody>
    </table>`;
}

function eurRowsForSelection() {
  const month = $("#eurMonth")?.value || currentMonthValue();
  const userId = Number($("#eurUser")?.value || 0);
  const start = `${month}-01`;
  const endDate = new Date(`${month}-01T12:00:00`);
  endDate.setMonth(endDate.getMonth() + 1);
  const end = endDate.toISOString().slice(0, 10);

  const rows = [];
  accState.invoices.filter(inv => inv.status === "paid").forEach(inv => {
    const date = String(inv.paid_at || inv.invoice_date || "").slice(0, 10);
    if (!date || date < start || date >= end) return;
    invoiceItems(inv.id).forEach(item => {
      if (userId && Number(item.user_id || 0) !== userId) return;
      const net = Number(item.net_amount || 0);
      const vatRate = Number(inv.vat_rate || 0);
      const vat = Math.round(net * vatRate) / 100;
      rows.push({
        source: "Rechnung",
        date,
        type: "income",
        category: `Rechnung ${inv.number}`,
        description: `${inv.customer_name || "Kunde"} · ${item.task_title || "Leistung"}`,
        user_id: item.user_id,
        user_name: item.user_name,
        amount_net: net,
        vat_rate: vatRate,
        vat_amount: vat,
        amount_gross: net + vat
      });
    });
  });

  accState.eur_entries.forEach(entry => {
    const date = String(entry.entry_date || "").slice(0, 10);
    if (!date || date < start || date >= end) return;
    if (userId) {
      if (Number(entry.user_id || 0) !== userId) return;
    }
    rows.push({...entry, source: "Manuell", date});
  });

  rows.sort((a, b) => String(a.date).localeCompare(String(b.date)) || String(a.category || "").localeCompare(String(b.category || "")));
  return rows;
}

function renderEurReport() {
  const rows = eurRowsForSelection();
  const income = rows.filter(row => row.type === "income").reduce((sum, row) => sum + Number(row.amount_net || 0), 0);
  const expenses = rows.filter(row => row.type === "expense").reduce((sum, row) => sum + Number(row.amount_net || 0), 0);
  const vatIn = rows.filter(row => row.type === "income").reduce((sum, row) => sum + Number(row.vat_amount || 0), 0);
  const vatOut = rows.filter(row => row.type === "expense").reduce((sum, row) => sum + Number(row.vat_amount || 0), 0);
  const result = income - expenses;

  const userId = Number($("#eurUser")?.value || 0);
  const title = userId ? `Mitarbeiter: ${userName(userId)}` : "Chef / Gesamt";

  const table = rows.length ? rows.map(row => `
    <tr class="eur-${esc(row.type)}">
      <td>${formatDateGerman(row.date || row.entry_date)}</td>
      <td>${row.type === "income" ? "Einnahme" : "Ausgabe"}</td>
      <td>${esc(row.source || "")}</td>
      <td>${esc(row.category || "")}</td>
      <td>${esc(row.user_name || userName(row.user_id) || "allgemein")}</td>
      <td>${esc(row.description || "")}</td>
      <td class="num">${euro(row.amount_net)}</td>
      <td class="num">${euro(row.vat_amount)}</td>
      <td class="num">${euro(row.amount_gross)}</td>
      ${accState.user.role === "admin" && row.source === "Manuell" ? `<td><button type="button" class="danger mini" data-delete-eur="${esc(row.id)}">löschen</button></td>` : `<td></td>`}
    </tr>`).join("") : `<tr><td colspan="10"><p class="muted">Keine EÜR-Zeilen für diese Auswahl vorhanden.</p></td></tr>`;

  $("#eurReport").innerHTML = `
    <div class="eur-head">
      <h3>${esc(title)}</h3>
      <p class="muted no-margin-left">Vorbereitende Übersicht auf Basis bezahlter Rechnungen und manueller EÜR-Buchungen.</p>
    </div>
    <div class="accounting-grid eur-kpis">
      <article class="accounting-kpi"><b>${euro(income)}</b><span>Einnahmen netto</span></article>
      <article class="accounting-kpi"><b>${euro(expenses)}</b><span>Ausgaben netto</span></article>
      <article class="accounting-kpi"><b>${euro(result)}</b><span>Überschuss netto</span></article>
      <article class="accounting-kpi"><b>${euro(vatIn)}</b><span>USt. aus Einnahmen</span></article>
      <article class="accounting-kpi"><b>${euro(vatOut)}</b><span>Vorsteuer aus Ausgaben</span></article>
      <article class="accounting-kpi"><b>${euro(vatIn - vatOut)}</b><span>USt.-Saldo</span></article>
    </div>
    <div class="accounting-table-wrap">
      <table class="accounting-table">
        <thead><tr><th>Datum</th><th>Art</th><th>Quelle</th><th>Kategorie</th><th>Mitarbeiter</th><th>Beschreibung</th><th class="num">Netto</th><th class="num">USt.</th><th class="num">Brutto</th><th></th></tr></thead>
        <tbody>${table}</tbody>
      </table>
    </div>`;

  $$('[data-delete-eur]').forEach(button => {
    button.addEventListener('click', async () => {
      if (!confirm('EÜR-Buchung löschen?')) return;
      try {
        const response = await accRequest('delete_eur_entry', {id: Number(button.dataset.deleteEur)});
        accState = response.data;
        renderAll();
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

function renderAll() {
  fillBuilderSelects();
  renderDashboard();
  renderInvoiceList();
  renderBillableTimes();
  renderEurReport();
}

async function loadAccounting() {
  const response = await accRequest('bootstrap');
  accState = response.data;
  if ($("#eurMonth") && !$("#eurMonth").value) $("#eurMonth").value = currentMonthValue();
  const invoiceDate = document.querySelector('[name="invoice_date"]');
  const dueDate = document.querySelector('[name="due_date"]');
  const entryDate = document.querySelector('[name="entry_date"]');
  if (invoiceDate && !invoiceDate.value) invoiceDate.value = todayValue();
  if (dueDate && !dueDate.value) {
    const d = new Date(todayValue() + 'T12:00:00');
    d.setDate(d.getDate() + 14);
    dueDate.value = d.toISOString().slice(0, 10);
  }
  if (entryDate && !entryDate.value) entryDate.value = todayValue();
  renderAll();
}

function bindStaticEvents() {
  $$(".accounting-tab").forEach(button => button.addEventListener("click", () => setView(button.dataset.tab)));
  $("#accReloadBtn")?.addEventListener("click", () => loadAccounting().catch(error => alert(error.message)));
  $("#invoiceStatusFilter")?.addEventListener("change", renderInvoiceList);
  $("#invoiceSearch")?.addEventListener("input", renderInvoiceList);
  $("#invoiceProject")?.addEventListener("change", renderBillableTimes);
  $("#invoiceCustomer")?.addEventListener("change", renderBillableTimes);
  $("#eurApplyBtn")?.addEventListener("click", renderEurReport);
  $("#eurMonth")?.addEventListener("change", renderEurReport);
  $("#eurUser")?.addEventListener("change", renderEurReport);
  $("#accCsvBtn")?.addEventListener("click", exportEurCsv);
  $("#accPrintBtn")?.addEventListener("click", printAccounting);

  $("#selectAllTimesBtn")?.addEventListener("click", () => {
    $$(".billable-time-check").forEach(check => { check.checked = true; });
  });

  $("#invoiceBuilderForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const timeIds = $$(".billable-time-check:checked").map(check => Number(check.value));
    const payload = Object.fromEntries(form.entries());
    payload.time_entry_ids = timeIds;
    payload.customer_id = $("#invoiceCustomer")?.value || "";
    payload.project_id = $("#invoiceProject")?.value || "";

    try {
      const response = await accRequest("create_invoice_from_times", payload);
      accState = response.data;
      renderAll();
      setView("invoices");
      alert("Rechnung wurde als Entwurf erzeugt.");
    } catch (error) {
      alert(error.message);
    }
  });

  $("#eurEntryForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      const response = await accRequest("save_eur_entry", payload);
      accState = response.data;
      event.currentTarget.reset();
      const entryDate = document.querySelector('[name="entry_date"]');
      if (entryDate) entryDate.value = todayValue();
      renderAll();
      setView("eur");
    } catch (error) {
      alert(error.message);
    }
  });
}

function exportEurCsv() {
  const rows = eurRowsForSelection();
  const header = ["Datum", "Art", "Quelle", "Kategorie", "Mitarbeiter", "Beschreibung", "Netto", "USt", "Brutto"];
  const lines = [header.map(csvEscape).join(";")];
  rows.forEach(row => {
    lines.push([
      formatDateGerman(row.date || row.entry_date),
      row.type === "income" ? "Einnahme" : "Ausgabe",
      row.source || "",
      row.category || "",
      row.user_name || userName(row.user_id) || "allgemein",
      row.description || "",
      Number(row.amount_net || 0).toFixed(2).replace(".", ","),
      Number(row.vat_amount || 0).toFixed(2).replace(".", ","),
      Number(row.amount_gross || 0).toFixed(2).replace(".", ",")
    ].map(csvEscape).join(";"));
  });
  const blob = new Blob(["\ufeff" + lines.join("\n")], {type: "text/csv;charset=utf-8"});
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `polarisnova_eur_${$("#eurMonth")?.value || currentMonthValue()}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function printAccounting() {
  const content = document.querySelector('.accounting-main')?.innerHTML || '';
  const win = window.open('', '_blank', 'width=1100,height=800');
  if (!win) {
    alert('Druckfenster konnte nicht geöffnet werden. Bitte Popup-Blocker prüfen.');
    return;
  }
  win.document.write(`<!doctype html><html lang="de"><head><meta charset="utf-8"><title>PolarisNova Rechnungen EÜR</title><style>body{font-family:Arial,sans-serif;margin:20px;color:#111827}.actions,.accounting-controls,.accounting-sidebar,button,select,input,textarea{display:none!important}.accounting-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.accounting-kpi{border:1px solid #d1d5db;padding:10px;border-radius:8px}.accounting-kpi b{display:block;font-size:20px}.accounting-table{width:100%;border-collapse:collapse;font-size:11px}.accounting-table th,.accounting-table td{border:1px solid #d1d5db;padding:5px;vertical-align:top}.accounting-table th{background:#f3f4f6}.invoice-card{border:1px solid #d1d5db;border-radius:8px;padding:10px;margin:12px 0}.invoice-card-head{display:flex;justify-content:space-between;gap:20px}.muted{color:#4b5563}.num{text-align:right}@media print{body{margin:10mm}}</style></head><body>${content}</body></html>`);
  win.document.close();
  win.focus();
  win.print();
}

bindStaticEvents();
loadAccounting().catch(error => alert(error.message));
