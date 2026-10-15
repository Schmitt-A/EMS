// 7.3 Ladepunkt-Karte und 7.4 Ladebalken: Schalter „Laden“, Modus und Netzladen optimistisch, Teile nur für eine
// Auswahl von Netzladen (data-grid-show) ein- und ausblenden, beide Ladeziele live, Limit-Marke ziehbar und per Tastatur.
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
    if (field === 'grid') paintGrid(card, input.value);
    try {
      applyLive(await postJson('/api/modus', { [field]: input.value }));
      previous = input.value;
    } catch {
      const back = $(`input[value="${previous}"]`, fieldset);
      if (back) back.checked = true;
      if (field === 'mode') paintOn(card, wasOn);
      if (field === 'grid') paintGrid(card, previous);
      fail(card, error);
    }
  });
}

// Ziel fürs Netzladen und die Wahl der Fenster gelten nur bei „Jetzt“ oder „In Ladefenstern“.
function paintGrid(card, grid) {
  for (const node of $$('[data-grid-show]', card)) node.hidden = !node.dataset.gridShow.split(' ').includes(grid);
}

// Schalter „Ladefenster nutzen“ und „Stoppen“ für Netzladen „Jetzt“; „Jetzt“ selbst startet im Ziel-Sheet.
function bindGrid(card) {
  const box = $('[data-grid-windows]', card);
  box?.addEventListener('change', async () => {
    const on = box.checked;
    try {
      applyLive(await postJson('/api/modus', { windows: on ? '1' : '0' }));
    } catch {
      box.checked = !on;
      fail(card, 'Die Ladefenster wurden nicht umgeschaltet. Bitte noch einmal versuchen.');
    }
  });
  const stop = $('[data-grid-stop]', card);
  stop?.addEventListener('click', async () => {
    stop.disabled = true;
    try {
      applyLive(await postJson('/api/modus', { grid: 'stop' }));
    } catch {
      fail(card, 'Netzladen wurde nicht gestoppt. Bitte noch einmal versuchen.');
    } finally {
      stop.disabled = false;
    }
  });
}

// Ladeziele für Sonne und Speicher und fürs Netzladen: Zeile oder „… setzen“, Fortschritt als Breite.
function paintTarget(card, scope, target) {
  const row = $(`[data-target="${scope}"]`, card);
  const none = $(`[data-target-none="${scope}"]`, card);
  if (row && target) {
    row.hidden = !target.active;
    if (none) none.hidden = Boolean(target.active);
    if (target.active) row.dataset.progress = String(target.progress ?? 0);
  }
  if (row) {
    const fill = $('.cp-target-fill', row);
    if (fill) fill.style.setProperty('--progress', String(Math.max(0, Math.min(1, Number(row.dataset.progress) || 0))));
  }
}

// Wartezeit der Regelung: zählt zwischen zwei Abfragen sekundengenau herunter, der Balken füllt sich bis zum Schalten.
const wait = (s) => (s < 60 ? `${s}${NNBSP}s` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}${NNBSP}min`);

function paintTimer(card, timer, at = Date.now()) {
  const box = $('[data-cp-timer]', card);
  if (!box) return;
  box.hidden = !timer;
  clearInterval(box.tick);
  if (!timer) return;
  const label = $('[data-cp-timer-label]', box);
  const then = $('[data-cp-timer-then]', box);
  if (label && timer.label) label.textContent = timer.label;
  if (then && timer.then) then.textContent = timer.then;
  const total = Math.max(1, Number(timer.total_s) || 1);
  const draw = () => {
    const left = Math.max(0, Math.round(Number(timer.remaining_s) - (Date.now() - at) / 1000));
    const out = $('[data-cp-timer-left]', box);
    if (out) out.textContent = wait(left);
    box.style.setProperty('--cp-timer', String(Math.min(1, Math.max(0, (total - left) / total))));
  };
  draw();
  box.tick = setInterval(draw, 1000);
}

// Übersicht nur bei offenem Ladevorgang.
function paintSession(card, session) {
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
    bindGrid(card);
    if (bar) bindLimit(card, bar);
    paintTarget(card, 'sun', null);
    paintTarget(card, 'grid', null);
    paintTarget(card, 'now', null);
    const timerBox = $('[data-cp-timer]', card);
    if (timerBox && !timerBox.hidden) paintTimer(card, { remaining_s: Number(timerBox.dataset.left), total_s: Number(timerBox.dataset.total) });
    onLive((data) => {
      paintTarget(card, 'sun', data.target);
      paintTarget(card, 'grid', data.grid_target);
      paintTarget(card, 'now', data.now_target);
      paintSession(card, data.session_view);
      if (data.chargepoint) paintTimer(card, data.chargepoint.timer || null);
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
        paintGrid(card, cp.grid);
        const windows = $('[data-grid-windows]', card);
        if (windows && !windows.disabled && document.activeElement !== windows) windows.checked = Boolean(cp.grid_windows);
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
