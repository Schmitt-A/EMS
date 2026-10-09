// 7.1 Energiefluss-Balken: Breiten per flex-grow, Klammern per Position, Werte nur ab 56 px Segmentbreite.
import { $, $$ } from '../core/dom.js';
import { withUnit } from '../core/format.js';
import { onLive } from '../core/live.js';

const SOURCES = { grid: 'Netz', battery: 'Speicher', pv: 'PV' };
const SINKS = { house: 'Haus', wallbox: 'Wallbox', battery: 'Speicher', grid: 'Einspeisung' };

function placeBrackets(figure, where, items) {
  const box = $(`.flow-brackets-${where}`, figure);
  if (!box) return;
  const width = box.getBoundingClientRect().width;
  for (const bracket of $$('[data-bracket]', box)) {
    const item = (items || []).find((entry) => entry.key === bracket.dataset.bracket);
    const span = item ? item.to - item.from : 0;
    bracket.toggleAttribute('data-gone', !item || span < 0.002);
    if (!item) continue;
    bracket.style.left = `${(item.from * 100).toFixed(3)}%`;
    bracket.style.width = `${(span * 100).toFixed(3)}%`;
    bracket.toggleAttribute('data-narrow', span * width < 36);
  }
}

function fitValues(figure) {
  for (const segment of $$('[data-seg]', figure)) {
    segment.toggleAttribute('data-fits', segment.getBoundingClientRect().width >= 56);
  }
}

function summary(flow) {
  const part = (items, names) =>
    (items || []).filter((item) => item.kw > 0.01).map((item) => `${names[item.key] || item.key} ${withUnit(item.kw, 'kW')}`).join(', ');
  const total = flow.total_kw ?? 0;
  return `Energiefluss, gesamt ${withUnit(total, 'kW')}. Rein: ${part(flow.sources, SOURCES) || 'nichts'}. Raus: ${part(flow.sinks, SINKS) || 'nichts'}.`;
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
  }
  bar.setAttribute('aria-label', summary(flow));
  figure.setAttribute('data-ready', '');
  requestAnimationFrame(() => {
    placeBrackets(figure, 'top', flow.sources);
    placeBrackets(figure, 'bottom', flow.sinks);
    fitValues(figure);
  });
  figure._flow = flow;
}

function renderRows(figure, rows) {
  if (!rows) return;
  for (const side of ['in', 'out']) {
    const sum = $(`[data-live-flow-sum="${side === 'in' ? 'rein' : 'raus'}"]`, figure);
    if (sum && rows[`${side}_kw`] !== undefined) sum.textContent = withUnit(rows[`${side}_kw`], 'kW');
    for (const [key, kw] of Object.entries(rows[side] || {})) {
      const row = $(`[data-flow-row="${key}"]`, $(`#${figure.id}-details`) || figure);
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
      if (!figure._flow) return;
      placeBrackets(figure, 'top', figure._flow.sources);
      placeBrackets(figure, 'bottom', figure._flow.sinks);
      fitValues(figure);
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
