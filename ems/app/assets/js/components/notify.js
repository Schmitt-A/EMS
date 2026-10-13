// Mitteilungen unter Einstellungen: Platzhalter an der Schreibmarke einfügen, Vorschau mit den Werten von jetzt,
// Test und Probe per /api/mitteilung an die angekreuzten Geräte, die Häkchen der Meldungen gleich speichern. Bei eigenen
// Mitteilungen passen Vergleich, Grenze und Einheit jeder Bedingung zu ihrem Wert, daneben steht der Wert von jetzt und
// darunter, ob sie gerade gilt (wie Notify::holds()).
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
      if (form.hasAttribute('data-nt-rule')) {
        body.rule = $('input[name="rule"]', form)?.value || '';
        body.name = $('[data-nt-name]', form)?.value || '';
        body.important = $('[data-nt-important]', form)?.checked ? '1' : '0';
      }
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

const parse = (text, fallback) => {
  try {
    return JSON.parse(text || '') ?? fallback;
  } catch {
    return fallback;
  }
};

// Gilt eine Bedingung beim Wert von jetzt? Fehlt der Wert, gilt sie nicht.
function holds(def, value, op, target) {
  if (!def || value === null || value === undefined) return false;
  if (def.type === 'num') {
    const limit = parseFloat(String(target ?? '').replace(',', '.'));
    if (Number.isNaN(limit)) return false;
    const factor = 10 ** (def.decimals ?? 0);
    const a = Math.round(value * factor) / factor;
    const b = Math.round(limit * factor) / factor;
    return { lt: value < limit, le: a <= b, gt: value > limit, ge: a >= b, eq: a === b, ne: a !== b }[op] ?? false;
  }
  if (def.type === 'bool') return op === 'yes' ? value === true : value === false;
  if (def.type === 'time') {
    const m = /^(\d{1,2}):(\d{2})/.exec(target || '');
    if (!m) return false;
    const minute = Number(m[1]) * 60 + Number(m[2]);
    return op === 'ge' ? value >= minute : value < minute;
  }
  return op === 'eq' ? String(value) === String(target) : String(value) !== String(target);
}

function fill(select, entries, current) {
  select.replaceChildren(...entries.map(([value, label]) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    return option;
  }));
  if (entries.some(([value]) => value === current)) select.value = current;
}

function bindRule(form) {
  const defs = parse(form.dataset.conditions, {});
  const ops = parse(form.dataset.operators, {});
  const values = parse(form.dataset.values, {});
  const texts = parse(form.dataset.valueTexts, {});
  const rows = () => $$('[data-cond]', form);
  const add = $('[data-cond-add]', form);
  const status = $('[data-rule-status]', form);
  const match = $('select[name="match"]', form);

  const sync = (row) => {
    const name = $('[data-cond-var]', row).value;
    const def = defs[name];
    const type = def?.type || 'num';
    const op = $('[data-cond-op]', row);
    fill(op, Object.entries(ops[type] || {}), op.value);
    for (const box of $$('[data-kind]', row)) box.hidden = !def || box.dataset.kind !== type;
    if (def?.type === 'enum') {
      const select = $('[data-cond-enum]', row);
      fill(select, Object.entries(def.options || {}).map(([value, label]) => [`${name}:${value}`, label]), select.value);
    }
    $('[data-unit]', row).textContent = def?.unit || '';
    $('[data-cond-now]', row).textContent = def ? `jetzt ${texts[name] ?? '—'}` : '';
  };
  const result = (row) => {
    const name = $('[data-cond-var]', row).value;
    const def = defs[name];
    if (!def) return null;
    const target = def.type === 'num' ? $('[name="cond_value[]"]', row).value
      : def.type === 'time' ? $('[name="cond_time[]"]', row).value
        : def.type === 'enum' ? ($('[data-cond-enum]', row).value || '').slice(name.length + 1) : null;
    return holds(def, values[name], $('[data-cond-op]', row).value, target);
  };
  const evaluate = () => {
    const results = rows().filter((row) => !row.hidden).map(result).filter((r) => r !== null);
    const met = results.length > 0 && (match?.value === 'any' ? results.includes(true) : !results.includes(false));
    if (!status) return;
    status.textContent = results.length ? (met ? 'Gerade erfüllt.' : 'Gerade nicht erfüllt.') : 'Noch keine Bedingung gewählt.';
    status.toggleAttribute('data-met', met);
  };
  const layout = () => {
    const visible = rows().filter((row) => !row.hidden);
    if (add) add.hidden = visible.length >= rows().length;
    for (const row of rows()) $('[data-cond-remove]', row).hidden = visible.length <= 1;
  };

  // Leere Zeilen nach der ersten bleiben verborgen, bis man eine Bedingung hinzufügt.
  for (const row of rows()) {
    if (row.hasAttribute('data-blank')) row.hidden = true;
    sync(row);
  }
  layout();
  evaluate();
  form.addEventListener('change', (event) => {
    const row = event.target.closest('[data-cond]');
    if (row && event.target.matches('[data-cond-var]')) sync(row);
    evaluate();
  });
  form.addEventListener('input', (event) => {
    if (event.target.closest('[data-cond]')) evaluate();
  });
  add?.addEventListener('click', () => {
    const row = rows().find((r) => r.hidden);
    if (!row) return;
    row.hidden = false;
    layout();
    $('[data-cond-var]', row).focus();
  });
  form.addEventListener('click', (event) => {
    const button = event.target.closest('[data-cond-remove]');
    if (!button) return;
    const row = button.closest('[data-cond]');
    $('[data-cond-var]', row).value = '';
    sync(row);
    row.hidden = true;
    // Die leere Zeile kommt ans Ende, so bleibt die Reihenfolge der übrigen.
    row.parentElement.append(row);
    layout();
    evaluate();
    add?.focus();
  });
  // Löschen fragt einmal nach: der erste Tipp macht daraus „Wirklich löschen?“, fünf Sekunden lang.
  const remove = $('[data-nt-delete]', form.closest('dialog') || form);
  let timer = 0;
  remove?.addEventListener('click', (event) => {
    if (remove.dataset.armed === '1') return;
    event.preventDefault();
    remove.dataset.armed = '1';
    const label = $('span', remove);
    if (label) label.textContent = 'Wirklich löschen?';
    clearTimeout(timer);
    timer = setTimeout(() => {
      remove.dataset.armed = '';
      if (label) label.textContent = 'Löschen';
    }, 5000);
  });
}

export function initNotify(root = document) {
  for (const form of $$('[data-nt-form]', root)) bindComposer(form);
  for (const form of $$('[data-nt-rule]', root)) bindRule(form);
  for (const form of $$('[data-nt-events]', root)) bindEvents(form);
}
