// 7.3 Ladepunkt-Karte und 7.4 Ladebalken: Moduswechsel optimistisch, Limit-Marke ziehbar und per Tastatur.
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

function bindMode(card) {
  const fieldset = $('[data-mode-switch]', card);
  if (!fieldset) return;
  let previous = $('input:checked', fieldset)?.value;
  fieldset.addEventListener('change', async (event) => {
    const input = event.target.closest('input[type="radio"]');
    if (!input) return;
    try {
      applyLive(await postJson('/api/modus', { mode: input.value }));
      previous = input.value;
    } catch {
      const back = $(`input[value="${previous}"]`, fieldset);
      if (back) back.checked = true;
      card.dispatchEvent(new CustomEvent('ems:error', { bubbles: true, detail: 'Der Modus wurde nicht gespeichert. Bitte noch einmal versuchen.' }));
    }
  });
}

export function initChargepoints(root = document) {
  for (const bar of $$('[data-chargebar]', root)) {
    paintBar(bar, bar.dataset.soc === undefined ? null : Number(bar.dataset.soc), bar.dataset.limit === undefined ? null : Number(bar.dataset.limit));
  }
  for (const card of $$('[data-chargepoint]', root)) {
    const bar = $('[data-chargebar]', card);
    bindMode(card);
    if (bar) bindLimit(card, bar);
    onLive((data) => {
      const cp = data.chargepoint;
      const vehicle = data.vehicle;
      if (cp) {
        card.dataset.charging = String(Boolean(cp.charging));
        card.dataset.solarOnly = String(Boolean(cp.solar_only));
        const mode = $(`[data-mode-switch] input[value="${cp.mode}"]`, card);
        if (mode && !mode.checked && !$('[data-mode-switch]', card)?.contains(document.activeElement)) mode.checked = true;
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
