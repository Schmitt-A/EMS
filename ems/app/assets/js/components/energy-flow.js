// Energie-Flow Rein → Raus: Linien zwischen den Kreisen nach ihrer Lage im Layout, breiter bei mehr Leistung,
// Punkte in der Farbe der Quelle von links nach rechts. Werte, Ringe und Takt kommen aus /api/live (flow_graph).
// Breiten und Wege stehen als SVG-Attribute, der Takt als data-speed (CSS); keine Inline-Styles.
import { $, $$, svg } from '../core/dom.js';
import { withUnit } from '../core/format.js';
import { onLive } from '../core/live.js';

const FROM = { sun: 'sun', battery: 'battery_out', grid: 'grid_in' };
const TO = { home: 'home', car: 'car', battery: 'battery_in', grid: 'grid_out' };
const COLOR = { sun: 'c-solar', battery: 'c-battery', grid: 'c-grid-in' };
const LINKS = ['sun_home', 'sun_car', 'sun_battery', 'sun_grid', 'battery_home', 'battery_car', 'battery_grid', 'grid_home', 'grid_car', 'grid_battery'];
const NAMES = { sun: 'Sonne', battery_out: 'Speicher', grid_in: 'Netz', home: 'Haus', battery_in: 'Speicher', grid_out: 'Einspeisung' };
const STEPS = [[0.3, 1], [1, 2], [2.5, 3], [5, 4], [8, 5]];

const speed = (kw) => (STEPS.find(([limit]) => kw < limit) || [0, 6])[1];
const width = (kw) => Math.min(10, Math.max(2.5, 2 + kw * 1.1));
const kwText = (value) => withUnit(Number(value) || 0, 'kW');
const socText = (value) => (value === null || value === undefined ? '' : withUnit(value, '%', 0));

function setText(figure, key, value) {
  const node = $(`[data-ef="${key}"]`, figure);
  if (node && node.textContent !== value) node.textContent = value;
}

/** Wege von der rechten Kante der Quelle zur linken Kante des Ziels, als weiche S-Kurve. */
function layout(figure) {
  const box = figure.getBoundingClientRect();
  if (box.width < 1) return;
  const lines = $('.ef-links', figure);
  lines.setAttribute('viewBox', `0 0 ${box.width.toFixed(1)} ${box.height.toFixed(1)}`);
  const point = (key) => {
    const circle = $(`[data-ef-node="${key}"] .ef-circle`, figure)?.getBoundingClientRect();
    return circle ? { x: circle.left - box.left + circle.width / 2, y: circle.top - box.top + circle.height / 2, r: circle.width / 2 } : null;
  };
  for (const key of LINKS) {
    const [from, to] = key.split('_');
    const a = point(FROM[from]);
    const b = point(TO[to]);
    if (!a || !b) continue;
    const x1 = a.x + a.r;
    const x2 = b.x - b.r;
    const dx = (x2 - x1) * 0.55;
    const d = `M${x1.toFixed(1)},${a.y.toFixed(1)} C${(x1 + dx).toFixed(1)},${a.y.toFixed(1)} ${(x2 - dx).toFixed(1)},${b.y.toFixed(1)} ${x2.toFixed(1)},${b.y.toFixed(1)}`;
    let track = $(`.ef-track[data-link="${key}"]`, lines);
    let dots = $(`.ef-dots[data-link="${key}"]`, lines);
    if (!track) {
      track = svg('path', { class: `ef-track ${COLOR[from]}`, 'data-link': key, 'data-idle': '' }, lines);
      dots = svg('path', { class: `ef-dots ${COLOR[from]}`, 'data-link': key, 'data-idle': '' }, lines);
    }
    track.setAttribute('d', d);
    dots.setAttribute('d', d);
  }
  if (figure._graph) links(figure, figure._graph);
}

function links(figure, graph) {
  const values = graph.links || {};
  for (const path of $$('[data-link]', figure)) {
    const kw = Number(values[path.dataset.link]) || 0;
    path.toggleAttribute('data-idle', !(kw >= 0.01));
    if (path.classList.contains('ef-track')) path.setAttribute('stroke-width', width(kw).toFixed(1));
    else path.dataset.speed = String(speed(kw));
  }
}

function rings(figure, graph) {
  const progress = graph.nodes.sun.progress;
  const sun = $('[data-ef-node="sun"] [data-arc="progress"]', figure);
  if (sun) {
    const len = progress === null || progress === undefined ? 100 : Math.max(0, Math.min(1, progress)) * 100;
    sun.setAttribute('stroke-dasharray', `${len.toFixed(2)} ${(100 - len).toFixed(2)}`);
  }
  for (const node of ['home', 'car']) {
    let from = 0;
    for (const part of ['sun', 'battery', 'grid']) {
      const arc = $(`[data-ef-node="${node}"] [data-arc="${part}"]`, figure);
      if (!arc) continue;
      const len = Math.max(0, Math.min(100, (Number(graph.mix?.[part]) || 0) * 100));
      arc.setAttribute('stroke-dasharray', `${len.toFixed(2)} ${(100 - len).toFixed(2)}`);
      arc.setAttribute('stroke-dashoffset', (-from).toFixed(2));
      from += len;
    }
  }
}

function render(figure, graph) {
  if (!graph?.nodes) return;
  figure._graph = graph;
  const n = graph.nodes;
  for (const key of Object.keys(n)) {
    setText(figure, key, kwText(n[key].kw));
    $(`[data-ef-node="${key}"]`, figure)?.toggleAttribute('data-idle', !(n[key].kw >= 0.01));
  }
  const sun = n.sun;
  // Zweite Zeile unter den Namen; eine Kennzahl ohne Wert verschwindet mit ihrem Icon.
  const kwh = (value) => (value === null || value === undefined ? '' : withUnit(value, 'kWh'));
  const ct = (value) => (value === null || value === undefined ? '' : withUnit(value, 'ct/kWh'));
  const facts = {
    sun_done: kwh(sun.done_kwh),
    sun_forecast: kwh(sun.forecast_kwh),
    battery_out_soc: socText(n.battery_out.soc),
    battery_out_full: kwh(n.battery_out.to_full_kwh),
    grid_in_price: ct(n.grid_in.price_ct),
    home_mean: n.home.mean_kw === null || n.home.mean_kw === undefined ? '' : `Ø ${kwText(n.home.mean_kw)}`,
    car_soc: socText(n.car.soc),
    car_range: n.car.range_km === null || n.car.range_km === undefined ? '' : withUnit(n.car.range_km, 'km', 0),
    battery_in_soc: socText(n.battery_in.soc),
    battery_in_full: kwh(n.battery_in.to_full_kwh),
    grid_out_price: ct(n.grid_out.price_ct),
  };
  for (const [key, text] of Object.entries(facts)) {
    const fact = $(`[data-ef-fact="${key}"]`, figure);
    if (!fact) continue;
    fact.hidden = !text;
    setText(figure, key, text);
  }
  links(figure, graph);
  rings(figure, graph);

  const car = $('[data-ef-node="car"] .ef-name', figure)?.textContent || 'Auto';
  const name = (key) => (key === 'car' ? car : NAMES[key]);
  for (const key of Object.keys(n)) {
    const circle = $(`[data-ef-aria="${key}"]`, figure);
    if (circle) circle.setAttribute('aria-label', `${name(key)} ${kwText(n[key].kw)}`);
  }
  const flows = LINKS.filter((key) => (Number(graph.links?.[key]) || 0) >= 0.01).map((key) => {
    const [from, to] = key.split('_');
    return `${name(FROM[from])} zu ${name(TO[to])} ${kwText(graph.links[key])}`;
  });
  const summary = $('[data-ef-summary]', figure);
  if (summary) {
    summary.textContent = `Energie-Flow. ${flows.length ? flows.join(', ') : 'Gerade fließt nichts'}.`
      + (sun.forecast_kwh ? ` Sonne heute ${withUnit(sun.done_kwh ?? 0, 'kWh')} von ${withUnit(sun.forecast_kwh, 'kWh')} laut Prognose.` : '');
  }
}

export function initEnergyFlow(root = document) {
  for (const figure of $$('[data-energy-flow]', root)) {
    try {
      render(figure, JSON.parse(figure.dataset.energyFlow || 'null'));
    } catch {
      // Ohne Startwerte bleiben die Kreise, wie der Server sie gezeichnet hat.
    }
    layout(figure);
    new ResizeObserver(() => layout(figure)).observe(figure);
    if (figure.hasAttribute('data-static')) continue;
    onLive((data) => render(figure, data.flow_graph));
  }
}
