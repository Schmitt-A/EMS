// 7.5 Heimspeicher-Säule: Schwellen per Ziehen, Tastatur oder Dialog. Reihenfolge Haus ≤ Auto ≤ Start
// wie zone_thresholds() in PHP; gespeichert wird verzögert über /api/grenzen.
import { $, $$, postJson } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';
import { applyLive, onLive } from '../core/live.js';

const snap = (value) => Math.max(0, Math.min(100, Math.round(Number(value) / 5) * 5));
const pct = (value) => `${fmt(value, 0)}${NNBSP}%`;

function order(zones, active) {
  let { priority, buffer, auto } = zones;
  if (active === 'buffer') {
    priority = Math.min(priority, buffer);
    auto = Math.max(auto, buffer);
  } else if (active === 'auto') {
    buffer = Math.min(buffer, auto);
    priority = Math.min(priority, buffer);
  } else {
    buffer = Math.max(buffer, priority);
    auto = Math.max(auto, buffer);
  }
  return { priority, buffer, auto };
}

function paint(col) {
  const z = col._zones;
  col.style.setProperty('--s1', `${z.priority}%`);
  col.style.setProperty('--s2', `${z.buffer}%`);
  col.style.setProperty('--auto', `${z.auto}%`);
  const soc = col.dataset.soc;
  col.style.setProperty('--soc', `${soc === undefined ? 0 : Math.max(0, Math.min(100, Number(soc)))}%`);
  const body = $('[data-bc-body]', col);
  const height = body ? body.getBoundingClientRect().height : 240;
  const bands = { house: z.priority, car: z.buffer - z.priority, boost: 100 - z.buffer };
  for (const [key, size] of Object.entries(bands)) {
    $(`[data-zone="${key}"]`, col)?.toggleAttribute('data-small', (size / 100) * height < 32);
  }
  $('.bc-auto', col)?.toggleAttribute('data-off', z.auto >= 99.5);
  for (const mark of $$('[data-mark]', col)) {
    const value = mark.dataset.mark === 'priority' ? z.priority : z.buffer;
    mark.textContent = pct(value);
    if (mark.getAttribute('role') !== 'slider') continue;
    mark.setAttribute('aria-valuenow', String(value));
    mark.setAttribute('aria-valuetext', pct(value));
  }
  for (const out of $$('[data-zone-read]')) out.textContent = String(z[out.dataset.zoneRead]);
  // Auf 100 % sind Stützung und Start ohne Sonne aus; der Satz wechselt mit.
  for (const node of $$('[data-zone-when]')) {
    const [key, state] = node.dataset.zoneWhen.split('-');
    node.hidden = (z[key] >= 99.5) !== (state === 'off');
  }
  for (const input of $$('[data-zone-input]')) {
    if (document.activeElement !== input) input.value = String(z[input.dataset.zoneInput]);
    const output = input.closest('.range')?.querySelector('output');
    if (output) output.textContent = pct(z[input.dataset.zoneInput]);
  }
}

function persist(col) {
  clearTimeout(col._save);
  col._save = setTimeout(async () => {
    const z = col._zones;
    try {
      const data = await postJson('/api/grenzen', { priority_soc: z.priority, car_buffer_soc: z.buffer, car_auto_soc: z.auto });
      if (data.zones) {
        col._zones = { priority: data.zones.priority_soc, buffer: data.zones.car_buffer_soc, auto: data.zones.car_auto_soc };
        paint(col);
      }
      if (data.live) applyLive(data.live);
    } catch {
      col.dispatchEvent(new CustomEvent('ems:error', { bubbles: true, detail: 'Die Grenzen wurden nicht gespeichert.' }));
    }
  }, 500);
}

function update(col, key, value) {
  const next = { ...col._zones, [key]: snap(value) };
  col._zones = order(next, key);
  paint(col);
  persist(col);
}

function bindMark(col, mark) {
  const key = mark.dataset.mark;
  let start = null;
  mark.addEventListener('pointerdown', (event) => {
    start = { y: event.clientY, id: event.pointerId, moved: false };
    mark.setPointerCapture(event.pointerId);
  });
  mark.addEventListener('pointermove', (event) => {
    if (!start || event.pointerId !== start.id) return;
    if (!start.moved && Math.abs(event.clientY - start.y) < 4) return;
    start.moved = true;
    mark.setAttribute('data-dragging', '');
    mark.setAttribute('data-dragged', '');
    const box = $('[data-bc-body]', col).getBoundingClientRect();
    update(col, key, ((box.bottom - event.clientY) / box.height) * 100);
  });
  const end = (event) => {
    if (!start || event.pointerId !== start.id) return;
    const moved = start.moved;
    start = null;
    mark.removeAttribute('data-dragging');
    if (moved) setTimeout(() => mark.removeAttribute('data-dragged'), 0);
  };
  mark.addEventListener('pointerup', end);
  mark.addEventListener('pointercancel', end);
  mark.addEventListener('keydown', (event) => {
    const steps = { ArrowUp: 5, ArrowRight: 5, ArrowDown: -5, ArrowLeft: -5, PageUp: 10, PageDown: -10 };
    if (!(event.key in steps)) return;
    event.preventDefault();
    update(col, key, col._zones[key] + steps[event.key]);
  });
}

export function initBattery(root = document) {
  for (const col of $$('[data-battery-col]', root)) {
    col._zones = {
      priority: Number(col.dataset.s1 ?? 50),
      buffer: Number(col.dataset.s2 ?? 80),
      auto: Number(col.dataset.auto ?? 100),
    };
    paint(col);
    new ResizeObserver(() => paint(col)).observe(col);
    if (!col.hasAttribute('data-static')) {
      for (const mark of $$('button[data-mark]', col)) bindMark(col, mark);
      for (const input of $$('[data-zone-input]')) {
        input.addEventListener('input', () => update(col, input.dataset.zoneInput, input.value));
      }
      onLive((data) => {
        if (!data.battery) return;
        if (data.battery.soc === null || data.battery.soc === undefined) delete col.dataset.soc;
        else col.dataset.soc = String(data.battery.soc);
        col.dataset.activity = data.battery.flow || 'ruhe';
        paint(col);
      });
    }
  }
}
