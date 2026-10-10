// Energie-Flow: Werte in den Kreisen, Ringe für die Mischung aus Sonne, Speicher und Netz, Punkte auf den Linien.
// Der Takt der Punkte steht als data-speed (CSS), die Ringe als SVG-Attribute; keine Inline-Styles.
import { $, $$ } from '../core/dom.js';
import { withUnit } from '../core/format.js';
import { onLive } from '../core/live.js';

const STEPS = [[0.3, 1], [1, 2], [2.5, 3], [5, 4], [8, 5]];
const speed = (kw) => (STEPS.find(([limit]) => kw < limit) || [0, 6])[1];
const kwText = (value) => withUnit(Number(value) || 0, 'kW');
const socText = (value) => (value === null || value === undefined ? '' : withUnit(value, '%', 0));

function setText(figure, key, value) {
  const node = $(`[data-ef="${key}"]`, figure);
  if (node && node.textContent !== value) node.textContent = value;
}

function setAria(figure, key, value) {
  $(`[data-ef-aria="${key}"]`, figure)?.setAttribute('aria-label', value);
}

function rings(figure, mix) {
  for (const ring of $$('.ef-ring', figure)) {
    let from = 0;
    for (const part of ['sun', 'battery', 'grid']) {
      const arc = $(`[data-arc="${part}"]`, ring);
      if (!arc) continue;
      const len = Math.max(0, Math.min(100, (Number(mix?.[part]) || 0) * 100));
      arc.setAttribute('stroke-dasharray', `${len.toFixed(2)} ${(100 - len).toFixed(2)}`);
      arc.setAttribute('stroke-dashoffset', (-from).toFixed(2));
      from += len;
    }
  }
}

function render(figure, graph) {
  if (!graph?.nodes) return;
  const { nodes: n, links = {} } = graph;
  setText(figure, 'pv', kwText(n.pv.kw));
  setText(figure, 'grid_in', `→ ${kwText(n.grid.in_kw)}`);
  setText(figure, 'grid_out', `← ${kwText(n.grid.out_kw)}`);
  setText(figure, 'battery_soc', socText(n.battery.soc));
  setText(figure, 'battery_out', `↑ ${kwText(n.battery.out_kw)}`);
  setText(figure, 'battery_in', `↓ ${kwText(n.battery.in_kw)}`);
  setText(figure, 'home', kwText(n.home.kw));
  setText(figure, 'house', `Haus ${kwText(n.home.house_kw)}`);
  setText(figure, 'car', kwText(n.car.kw));
  setText(figure, 'car_soc', socText(n.car.soc));

  for (const path of $$('[data-link]', figure)) {
    const kw = Number(links[path.dataset.link]) || 0;
    path.toggleAttribute('data-idle', !(kw >= 0.01));
    path.dataset.speed = String(speed(kw));
  }
  for (const track of $$('[data-track]', figure)) {
    track.toggleAttribute('data-active', Boolean($(`[data-track-of="${track.dataset.track}"]:not([data-idle])`, figure)));
  }
  rings(figure, graph.mix);

  // Kreise ohne Leistung treten zurück, Ein- und Ausgang nur, wenn etwas fließt.
  const idle = {
    pv: !(n.pv.kw >= 0.01),
    grid: !(n.grid.in_kw >= 0.01 || n.grid.out_kw >= 0.01),
    battery: !(n.battery.in_kw >= 0.01 || n.battery.out_kw >= 0.01),
    home: !(n.home.kw >= 0.01),
    car: !(n.car.kw >= 0.01),
  };
  for (const [key, value] of Object.entries(idle)) $(`[data-ef-node="${key}"]`, figure)?.toggleAttribute('data-idle', value);
  // Speist das Netz nur ein, trägt sein Ring die Farbe der Einspeisung.
  $('[data-ef-node="grid"]', figure)?.toggleAttribute('data-export', !(n.grid.in_kw >= 0.01) && n.grid.out_kw >= 0.01);
  for (const [key, a, b] of [['grid', 'in_kw', 'out_kw'], ['battery', 'in_kw', 'out_kw']]) {
    const node = $(`[data-ef-node="${key}"]`, figure);
    $('.ef-in', node)?.toggleAttribute('data-zero', !(n[key][a] >= 0.01));
    $('.ef-out', node)?.toggleAttribute('data-zero', !(n[key][b] >= 0.01));
  }

  const car = $('[data-ef-node="car"] .ef-label', figure)?.firstChild?.textContent || 'Auto';
  setAria(figure, 'pv', `Sonne ${kwText(n.pv.kw)}, zur Prognose`);
  setAria(figure, 'grid', `Netz: Bezug ${kwText(n.grid.in_kw)}, Einspeisung ${kwText(n.grid.out_kw)}`);
  setAria(figure, 'battery', `Speicher ${socText(n.battery.soc)}, lädt ${kwText(n.battery.in_kw)}, entlädt ${kwText(n.battery.out_kw)}, zum Speicher`);
  setAria(figure, 'home', `Verbrauch ${kwText(n.home.kw)}, davon Haus ${kwText(n.home.house_kw)}`);
  setAria(figure, 'car', `${car} lädt mit ${kwText(n.car.kw)}, zum Ladepunkt`);
  const mix = graph.mix || {};
  const pct = (part) => withUnit((Number(mix[part]) || 0) * 100, '%', 0);
  const summary = $('[data-ef-summary]', figure);
  if (summary) {
    summary.textContent = `Energie-Flow. Sonne ${kwText(n.pv.kw)}. Netz: Bezug ${kwText(n.grid.in_kw)}, Einspeisung ${kwText(n.grid.out_kw)}. `
      + `Speicher ${socText(n.battery.soc)}, lädt ${kwText(n.battery.in_kw)}, entlädt ${kwText(n.battery.out_kw)}. `
      + `Verbrauch ${kwText(n.home.kw)}, davon ${car} ${kwText(n.car.kw)}, aus Sonne ${pct('sun')}, Speicher ${pct('battery')}, Netz ${pct('grid')}.`;
  }
}

export function initEnergyFlow(root = document) {
  for (const figure of $$('[data-energy-flow]', root)) {
    try {
      render(figure, JSON.parse(figure.dataset.energyFlow || 'null'));
    } catch {
      // Ohne Startwerte bleiben die Kreise, wie der Server sie gezeichnet hat.
    }
    if (figure.hasAttribute('data-static')) continue;
    onLive((data) => render(figure, data.flow_graph));
  }
}
