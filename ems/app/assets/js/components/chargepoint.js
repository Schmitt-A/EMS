// 7.3 Ladepunkt-Karte und 7.4 Ladebalken: Schalter „Laden“, Modus und Netzladen optimistisch, die nächsten
// Ladefenster live, Limit-Marke ziehbar und per Tastatur.
import { $, $$, postJson } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';
import { applyLive, onLive } from '../core/live.js';

const snap = (value) => Math.max(20, Math.min(100, Math.round(value / 5) * 5));

function paintBar(bar, soc, limit) {
  const track = $('[data-chargebar-track]', bar);
  if (!track) return;
  const hasSoc = soc !== null && soc !== undefined && !Number.isNaN(Number(soc));
  bar.toggleAttribute('data-unknown', !hasSoc);
  track.style.setProperty('--soc', `${hasSoc ? Math.max(0, Math.min(100, Number(soc))) : 0}%`);
  if (limit !== null && limit !== undefined) track.style.setProperty('--limit', `${Number(limit)}%`);
  const mark = $('[data-limit-mark]', bar);
  if (mark && limit !== null && limit !== undefined) {
    mark.setAttribute('aria-valuenow', String(Math.round(limit)));
    mark.setAttribute('aria-valuetext', `${fmt(limit, 0)}${NNBSP}%`);
  }
}

function bindLimit(card, bar) {
  const mark = $('[data-limit-mark]', bar);
  const track = $('[data-chargebar-track]', bar);
  if (!mark || !track) return;
  let timer = 0;
  const save = (value) => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      try {
        applyLive(await postJson('/api/limit', { limit: value }));
      } catch {
        card.dispatchEvent(new CustomEvent('ems:error', { bubbles: true, detail: 'Das Limit wurde nicht gespeichert.' }));
      }
    }, 450);
  };
  const set = (value) => {
    const next = snap(value);
    bar.dataset.limit = String(next);
    paintBar(bar, bar.dataset.soc === undefined ? null : Number(bar.dataset.soc), next);
    for (const out of $$('[data-limit-text]', card)) out.textContent = `${fmt(next, 0)}${NNBSP}%`;
    save(next);
  };
  const fromPointer = (event) => {
    const box = track.getBoundingClientRect();
    return ((event.clientX - box.left) / box.width) * 100;
  };
  mark.addEventListener('pointerdown', (event) => {
    event.preventDefault();
    mark.setPointerCapture(event.pointerId);
    mark.focus({ preventScroll: true });
  });
  mark.addEventListener('pointermove', (event) => {
    if (!mark.hasPointerCapture(event.pointerId)) return;
    set(fromPointer(event));
  });
  mark.addEventListener('keydown', (event) => {
    const current = Number(bar.dataset.limit || 80);
    const steps = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5, PageUp: 10, PageDown: -10 };
    if (event.key in steps) set(current + steps[event.key]);
    else if (event.key === 'Home') set(20);
    else if (event.key === 'End') set(100);
    else return;
    event.preventDefault();
  });
}

// Schalter „Laden“: an oder aus; aus sperrt die Wallbox, an gilt der gewählte Modus für Sonne und Speicher.
function paintOn(card, on) {
  card.dataset.on = String(on);
  const box = $('[data-charge-switch]', card);
  if (box && box.checked !== on && document.activeElement !== box) box.checked = on;
  const note = $('[data-off-note]', card);
  if (note) note.hidden = on;
}

const fail = (card, text) => card.dispatchEvent(new CustomEvent('ems:error', { bubbles: true, detail: text }));

function bindSwitch(card) {
  const box = $('[data-charge-switch]', card);
  if (!box) return;
  box.addEventListener('change', async () => {
    const on = box.checked;
    const mode = $('[data-mode-switch] input:checked', card)?.value || 'smart';
    paintOn(card, on);
    try {
      applyLive(await postJson('/api/modus', { mode: on ? mode : 'aus' }));
    } catch {
      box.checked = !on;
      paintOn(card, !on);
      fail(card, 'Laden wurde nicht umgeschaltet. Bitte noch einmal versuchen.');
    }
  });
}

// Ein Modus für Sonne und Speicher schaltet Laden an; Netzladen gilt unabhängig davon.
function bindChoice(card, selector, field, error) {
  const fieldset = $(selector, card);
  if (!fieldset) return;
  let previous = $('input:checked', fieldset)?.value;
  fieldset.addEventListener('change', async (event) => {
    const input = event.target.closest('input[type="radio"]');
    if (!input) return;
    const wasOn = card.dataset.on === 'true';
    if (field === 'mode') paintOn(card, true);
    try {
      applyLive(await postJson('/api/modus', { [field]: input.value }));
      previous = input.value;
    } catch {
      const back = $(`input[value="${previous}"]`, fieldset);
      if (back) back.checked = true;
      if (field === 'mode') paintOn(card, wasOn);
      fail(card, error);
    }
  });
}

// Die nächsten Ladefenster unter Netzladen, nur bei „In Ladefenstern“.
function paintWindows(card, cp) {
  const list = $('[data-grid-windows]', card);
  if (!list || !cp) return;
  const items = Array.isArray(cp.grid_windows) ? cp.grid_windows : [];
  list.replaceChildren(...items.map((w) => {
    const li = document.createElement('li');
    li.toggleAttribute('data-running', Boolean(w.running));
    const label = document.createElement('span');
    label.textContent = w.label;
    const ct = document.createElement('span');
    ct.className = 'num';
    ct.textContent = w.ct;
    li.append(label, ct);
    return li;
  }));
  list.hidden = cp.grid !== 'windows' || items.length === 0;
}

// Ladeziel: Zeile oder „Ladeziel setzen“, Fortschritt als Breite; Übersicht nur bei offenem Ladevorgang.
function paintTarget(card, target, session) {
  const row = $('[data-target]', card);
  const none = $('[data-target-none]', card);
  if (row && target) {
    row.hidden = !target.active;
    if (none) none.hidden = Boolean(target.active);
    if (target.active) row.dataset.progress = String(target.progress ?? 0);
  }
  if (row) {
    const fill = $('.cp-target-fill', row);
    if (fill) fill.style.setProperty('--progress', String(Math.max(0, Math.min(1, Number(row.dataset.progress) || 0))));
  }
  const box = $('[data-session]', card);
  if (box && session) box.hidden = !session.open;
}

export function initChargepoints(root = document) {
  for (const bar of $$('[data-chargebar]', root)) {
    paintBar(bar, bar.dataset.soc === undefined ? null : Number(bar.dataset.soc), bar.dataset.limit === undefined ? null : Number(bar.dataset.limit));
  }
  for (const card of $$('[data-chargepoint]', root)) {
    const bar = $('[data-chargebar]', card);
    bindSwitch(card);
    bindChoice(card, '[data-mode-switch]', 'mode', 'Der Modus wurde nicht gespeichert. Bitte noch einmal versuchen.');
    bindChoice(card, '[data-grid-switch]', 'grid', 'Netzladen wurde nicht umgestellt. Bitte noch einmal versuchen.');
    if (bar) bindLimit(card, bar);
    paintTarget(card, null, null);
    onLive((data) => {
      paintTarget(card, data.target, data.session_view);
      const cp = data.chargepoint;
      const vehicle = data.vehicle;
      if (cp) {
        card.dataset.charging = String(Boolean(cp.charging));
        card.dataset.solarOnly = String(Boolean(cp.solar_only));
        paintOn(card, Boolean(cp.on));
        for (const [selector, value] of [['[data-mode-switch]', cp.sun_mode], ['[data-grid-switch]', cp.grid]]) {
          const fieldset = $(selector, card);
          const input = fieldset && $(`input[value="${value}"]`, fieldset);
          if (input && !input.checked && !fieldset.contains(document.activeElement)) input.checked = true;
        }
        paintWindows(card, cp);
      }
      if (bar && vehicle) {
        bar.dataset.charging = String(Boolean(cp?.charging));
        if (vehicle.soc === null || vehicle.soc === undefined) delete bar.dataset.soc;
        else bar.dataset.soc = String(vehicle.soc);
        if (!$('[data-limit-mark]:focus', bar) && vehicle.limit !== null && vehicle.limit !== undefined) bar.dataset.limit = String(vehicle.limit);
        paintBar(bar, vehicle.soc, Number(bar.dataset.limit));
      }
    });
  }
}
