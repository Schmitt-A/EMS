// Regelung unter der Ladepunkt-Karte: Balken per flex-grow, Stufe und Sonne auf der Phasen-Leiter.
// Die Texte schreibt live.js über data-live; hier nur Breiten, Positionen und der aktive Modus.
import { $, $$ } from '../core/dom.js';
import { withUnit } from '../core/format.js';
import { onLive } from '../core/live.js';

const PARTS = ['sun', 'charge', 'export', 'battery', 'grid', 'mixed'];
// Die Balken der Modi zeigen nur, woher das Auto seine Leistung bekäme.
const CAR = ['sun', 'battery', 'grid', 'mixed'];

function fill(bar, flows, scale) {
  if (!bar) return;
  let used = 0;
  for (const seg of $$('[data-part]', bar)) {
    if (seg.dataset.part === 'rest') continue;
    const kw = Math.max(0, Number(flows?.[`${seg.dataset.part}_kw`] ?? 0));
    used += kw;
    seg.toggleAttribute('data-zero', !(kw >= 0.01));
    seg.style.flexGrow = String(kw);
  }
  const rest = $('[data-part="rest"]', bar);
  if (rest) rest.style.flexGrow = String(Math.max(0, scale - used));
}

function ladder(box, data) {
  if (!box || !data) return;
  const max = Math.max(0.1, Number(data.max_kw) || 0);
  const at = (kw) => `${Math.min(100, Math.max(0, (kw / max) * 100)).toFixed(2)}%`;
  for (const row of $$('[data-lane]', box)) {
    const level = $('[data-ladder-level]', row);
    if (level) {
      level.setAttribute('cx', at(Number(data.level_kw) || 0));
      level.setAttribute('visibility', Number(data.level_phases) === Number(row.dataset.lane) ? 'visible' : 'hidden');
    }
  }
  const sun = Math.max(0, Number(data.sun_kw) || 0);
  for (const line of $$('[data-ladder-sun]', box)) {
    line.setAttribute('x1', at(sun));
    line.setAttribute('x2', at(sun));
  }
  const label = $('[data-ladder-sun-label]', box);
  if (label) {
    label.setAttribute('x', at(sun));
    label.setAttribute('text-anchor', sun / max > 0.5 ? 'end' : 'start');
    label.textContent = `Sonne fürs Auto ${withUnit(sun, 'kW')}`;
  }
}

// Legende nur mit Farben, die im Block gerade vorkommen; Speicher steht für Laden und Entladen.
function legend(list, seen) {
  if (!list) return;
  const keys = { sun: ['sun'], battery: ['charge', 'battery'], grid: ['grid'], export: ['export'], mixed: ['mixed'] };
  for (const [key, parts] of Object.entries(keys)) {
    const item = $(`.ctl-key-${key}`, list);
    if (item) item.hidden = !parts.some((part) => seen.has(part));
  }
}

function render(card, data) {
  const flows = data.flows || {};
  const split = Math.max(0.1, Number(flows.spare_kw) || 0, Number(flows.car_kw) || 0);
  fill($('[data-ctl-bar="spare"]', card), flows, split);
  fill($('[data-ctl-bar="car"]', card), flows, split);
  legend($('[data-legend="split"]', card), new Set(PARTS.filter((part) => Number(flows[`${part}_kw`]) >= 0.01)));
  const seen = new Set();
  for (const [key, mode] of Object.entries(data.modes || {})) {
    fill($(`[data-ctl-bar="mode-${key}"]`, card), mode.flows, Math.max(0.1, Number(data.scale_kw) || 0));
    $(`[data-ctl-mode="${key}"]`, card)?.toggleAttribute('data-active', Boolean(mode.active));
    for (const part of CAR) if (Number(mode.flows?.[`${part}_kw`]) >= 0.01) seen.add(part);
  }
  legend($('[data-legend="modes"]', card), seen);
  ladder($('[data-ladder]', card), data.ladder);
}

export function initControl(root = document) {
  for (const card of $$('[data-control]', root)) {
    try {
      render(card, JSON.parse(card.dataset.control || '{}'));
    } catch {
      // Ohne Startwerte bleiben die Balken leer, bis die nächste Abfrage kommt.
    }
    if (!card.hasAttribute('data-control-live')) continue;
    onLive((live) => {
      if (live.control) render(card, live.control);
    });
  }
}
