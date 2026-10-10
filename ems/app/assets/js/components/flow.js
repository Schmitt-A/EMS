// 7.1 Energiefluss-Balken: Segmente per flex-grow, Klammern je Seite über die ganze Breite.
// Jede Klammer zeigt Icon und Leistung; reicht der Platz nicht, nur das Icon, dann nur die Linie.
import { $, $$ } from '../core/dom.js';
import { withUnit } from '../core/format.js';
import { onLive } from '../core/live.js';

const SOURCES = { grid: 'Netzbezug', battery: 'Speicher', pv: 'PV' };
const SINKS = { house: 'Haus', wallbox: 'Ladepunkt', battery: 'Speicher', grid: 'Einspeisung' };

function placeBrackets(figure, where, items, soc) {
  const box = $(`.flow-brackets-${where}`, figure);
  if (!box) return;
  const width = box.getBoundingClientRect().width;
  for (const bracket of $$('[data-bracket]', box)) {
    const item = (items || []).find((entry) => entry.key === bracket.dataset.bracket);
    const px = item ? (item.to - item.from) * width : 0;
    bracket.toggleAttribute('data-gone', !item || px < 2);
    if (!item) continue;
    bracket.style.setProperty('left', `${(item.from * 100).toFixed(3)}%`);
    bracket.style.setProperty('width', `${((item.to - item.from) * 100).toFixed(3)}%`);
    const kw = $('.bracket-kw', bracket);
    if (kw) kw.textContent = withUnit(item.kw, 'kW');
    const level = $('.bracket-soc', bracket);
    if (level) {
      level.hidden = soc === null || soc === undefined;
      level.textContent = withUnit(soc, '%', 0);
    }
    const label = $('.bracket-label', bracket);
    bracket.dataset.size = 'full';
    if (label && label.getBoundingClientRect().width + 20 > px) bracket.dataset.size = px >= 36 ? 'icon' : 'line';
  }
}

function fitValues(figure) {
  for (const segment of $$('[data-seg]', figure)) {
    segment.toggleAttribute('data-fits', segment.getBoundingClientRect().width >= 56);
  }
}

function summary(flow) {
  const part = (items, names) =>
    (items || []).map((item) => `${names[item.key] || item.key} ${withUnit(item.kw, 'kW')}`).join(', ');
  const soc = flow.soc === null || flow.soc === undefined ? '' : ` Speicher bei ${withUnit(flow.soc, '%', 0)}.`;
  return `Energiefluss. Rein ${withUnit(flow.in_kw ?? 0, 'kW')}: ${part(flow.sources, SOURCES) || 'nichts'}. `
    + `Raus ${withUnit(flow.out_kw ?? 0, 'kW')}: ${part(flow.sinks, SINKS) || 'nichts'}.${soc}`;
}

function layout(figure, flow) {
  placeBrackets(figure, 'top', flow.sources, flow.soc);
  placeBrackets(figure, 'bottom', flow.sinks, flow.soc);
  fitValues(figure);
}

function render(figure, flow) {
  if (!flow) return;
  const bar = $('[data-flow-bar]', figure);
  for (const segment of $$('[data-seg]', bar)) {
    const item = (flow.segments || []).find((entry) => entry.key === segment.dataset.seg);
    const kw = item ? Number(item.kw) : 0;
    segment.toggleAttribute('data-zero', !(kw > 0.005));
    segment.style.flexGrow = String(Math.max(0, kw));
    const value = $('.flow-val', segment);
    if (value) value.textContent = withUnit(kw, 'kW');
    const legend = $(`[data-legend="${segment.dataset.seg}"]`, figure);
    if (legend) legend.hidden = !(kw > 0.005);
  }
  bar.setAttribute('aria-label', summary(flow));
  figure.setAttribute('data-ready', '');
  figure._flow = flow;
  requestAnimationFrame(() => layout(figure, flow));
}

function renderRows(figure, rows) {
  if (!rows) return;
  for (const side of ['in', 'out']) {
    const column = $(`[data-flow-side="${side}"]`, figure);
    if (!column) continue;
    const sum = $('[data-flow-sum]', column);
    if (sum && rows[`${side}_kw`] !== undefined) sum.textContent = withUnit(rows[`${side}_kw`], 'kW');
    for (const [key, kw] of Object.entries(rows[side] || {})) {
      const row = $(`[data-flow-row="${key}"]`, column);
      if (!row) continue;
      const cell = $('.flow-row-kw', row);
      if (cell) cell.textContent = withUnit(kw, 'kW');
      row.toggleAttribute('data-idle', !(kw >= 0.01));
    }
  }
}

export function initFlow(root = document) {
  for (const figure of $$('[data-flow]', root)) {
    let flow = null;
    try {
      flow = JSON.parse(figure.dataset.flow || 'null');
    } catch {
      flow = null;
    }
    render(figure, flow);
    new ResizeObserver(() => {
      if (figure._flow) layout(figure, figure._flow);
    }).observe($('[data-flow-bar]', figure));
    const toggle = $('[data-flow-toggle]', figure);
    toggle?.addEventListener('click', () => {
      const details = document.getElementById(toggle.getAttribute('aria-controls'));
      const open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      details?.toggleAttribute('data-collapsed', !open);
    });
    if (figure.hasAttribute('data-static')) continue;
    onLive((data) => {
      render(figure, data.flow);
      renderRows(figure, data.flow_rows);
    });
  }
}
