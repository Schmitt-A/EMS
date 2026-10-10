// Mitteilungen unter Einstellungen: Platzhalter an der Schreibmarke einfügen, Vorschau mit den Werten von jetzt,
// Test und Probe per /api/mitteilung an die angekreuzten Geräte, die Häkchen der Meldungen gleich speichern.
import { $, $$, postJson } from '../core/dom.js';

const PLACEHOLDER = /\{([a-z_]+)\}/g;

const render = (template, vars) => template.replace(PLACEHOLDER, (match, key) => (key in vars ? vars[key] : match));
const unknown = (template, vars) => [...template.matchAll(PLACEHOLDER)].map((m) => m[1]).filter((key) => !(key in vars));

// Status neben dem Knopf; im Sheet steht er im Fuß.
const statusOf = (form) => $('[data-nt-status]', form.closest('dialog') || form);

function say(status, text, ok = true) {
  if (!status) return;
  status.textContent = text;
  status.dataset.tone = ok ? 'ok' : 'error';
}

// Angekreuzte Geräte, auch noch nicht gespeicherte; ohne die Liste die gespeicherten.
function checkedTargets() {
  const form = $('[data-nt-targets]');
  if (!form) return undefined;
  return $$('input[name="targets[]"]:checked', form).map((input) => input.value).join(',');
}

function logRow(row) {
  const item = document.createElement('li');
  item.className = 'nt-log-row';
  const head = document.createElement('div');
  head.className = 'nt-log-head';
  const event = document.createElement('span');
  event.className = 'nt-log-event';
  event.textContent = row.event;
  const pill = document.createElement('span');
  pill.className = `pill${row.status === 'error' ? ' pill-error' : row.status === 'sent' ? '' : ' pill-neutral'}`;
  pill.textContent = row.status_text;
  head.append(event, pill);
  item.append(head);
  for (const [className, text] of [['nt-log-title', row.title], ['nt-log-text', row.message], ['nt-log-meta', row.time + (row.to ? ` · an ${row.to}` : '')], ['nt-log-error', row.error]]) {
    if (!text) continue;
    const line = document.createElement('p');
    line.className = className;
    line.textContent = text;
    item.append(line);
  }
  return item;
}

function addToLog(row) {
  const list = $('[data-nt-log]');
  if (!list || !row) return;
  list.prepend(logRow(row));
  $('[data-nt-empty]')?.setAttribute('hidden', '');
}

function bindComposer(form) {
  let vars = {};
  try {
    vars = JSON.parse(form.dataset.vars || '{}');
  } catch {
    vars = {};
  }
  const fields = { title: $('[data-nt-field="title"]', form), message: $('[data-nt-field="message"]', form) };
  let last = fields.message;
  const update = () => {
    for (const [key, field] of Object.entries(fields)) {
      const out = $(`[data-preview="${key}"]`, form);
      if (field && out) out.textContent = render(field.value, vars);
    }
    const missing = [...new Set([...unknown(fields.title?.value || '', vars), ...unknown(fields.message?.value || '', vars)])];
    const note = $('[data-unknown]', form);
    if (note) {
      note.hidden = !missing.length;
      note.textContent = missing.length ? `Unbekannt: ${missing.map((key) => `{${key}}`).join(', ')}` : '';
    }
  };
  for (const field of Object.values(fields)) {
    field?.addEventListener('focus', () => {
      last = field;
    });
    field?.addEventListener('input', update);
  }
  form.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-insert]');
    if (!chip || !last) return;
    const start = last.selectionStart ?? last.value.length;
    const end = last.selectionEnd ?? last.value.length;
    // Klebt der Platzhalter sonst an einem Wort oder Satzzeichen, kommt ein Leerzeichen davor.
    const glue = start > 0 && /[\p{L}\p{N}.,:;!?)}]/u.test(last.value[start - 1]) ? ' ' : '';
    last.setRangeText(glue + chip.dataset.insert, start, end, 'end');
    last.focus();
    update();
  });
  // Probe und Test gehen per fetch raus; Speichern bleibt ein normales Formular.
  form.addEventListener('submit', async (event) => {
    const button = event.submitter;
    if (!button?.hasAttribute('data-nt-test')) return;
    event.preventDefault();
    const status = statusOf(form);
    say(status, 'Wird gesendet …');
    button.disabled = true;
    try {
      const body = { event: form.dataset.event, title: fields.title?.value || '', message: fields.message?.value || '' };
      const targets = checkedTargets();
      if (targets !== undefined) body.targets = targets;
      const result = await postJson('/api/mitteilung', body);
      say(status, result.message, result.ok);
      addToLog(result.row);
    } catch {
      say(status, 'Die Mitteilung ging nicht raus. Bitte die Seite neu laden.', false);
    } finally {
      button.disabled = false;
    }
  });
}

// Häkchen der Meldungen: jede Änderung gleich speichern, der Knopf bleibt für Seiten ohne JavaScript.
function bindEvents(form) {
  const status = statusOf(form);
  $('[data-nt-save]', form)?.setAttribute('hidden', '');
  form.addEventListener('change', async (event) => {
    if (!event.target.matches('input[type="checkbox"]')) return;
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`Status ${response.status}`);
      const result = await response.json();
      say(status, result.message || 'Gespeichert.');
    } catch {
      say(status, 'Nicht gespeichert. Bitte die Seite neu laden.', false);
    }
  });
}

export function initNotify(root = document) {
  for (const form of $$('[data-nt-form]', root)) bindComposer(form);
  for (const form of $$('[data-nt-events]', root)) bindEvents(form);
}
