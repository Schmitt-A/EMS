// Gemeinsamer Rahmen: feste y-Achse(n), scrollender Plot, Tooltip, versteckte Datentabelle, Leerzustand.
import { html, icon, svg } from '../core/dom.js';

export function buildFrame(figure, { right = false } = {}) {
  const frame = figure.querySelector('.chart-frame');
  frame.removeAttribute('data-loading');
  figure.style.setProperty('--axis-w-right', right ? '2.75rem' : '0rem');
  const keepScroll = frame.querySelector('.chart-scroll')?.scrollLeft ?? null;
  frame.replaceChildren();
  const yAxis = svg('svg', { class: 'chart-y', 'aria-hidden': 'true' }, frame);
  const y1Axis = right ? svg('svg', { class: 'chart-y1', 'aria-hidden': 'true' }, frame) : null;
  const scroll = html('div', { class: 'chart-scroll' });
  frame.append(scroll);
  const plot = svg('svg', { class: 'chart-plot', role: 'img', tabindex: '0' }, scroll);
  const tip = html('div', { class: 'chart-tip', 'aria-hidden': 'true' });
  frame.append(tip);
  return { figure, frame, yAxis, y1Axis, scroll, plot, tip, keepScroll, width: scroll.clientWidth, height: frame.clientHeight };
}

export function showEmpty(figure, text) {
  const frame = figure.querySelector('.chart-frame');
  frame.removeAttribute('data-loading');
  const box = html('div', { class: 'chart-empty' });
  box.append(icon('chart-line', 'icon-40'), html('p', { class: 'body-sm' }, text));
  frame.replaceChildren(box);
}

/** Tooltip neben dem Punkt, innerhalb des Rahmens gehalten. rows: [{label, value, cls}] */
export function showTip(f, pixelX, pixelY, title, rows) {
  const { tip, frame, scroll } = f;
  tip.replaceChildren();
  tip.append(html('div', { class: 'chart-tip-title' }, title));
  for (const row of rows) {
    const line = html('div', { class: `chart-tip-row ${row.cls || ''}` });
    line.append(html('span', { class: 'swatch swatch-dot' }), html('span', {}, row.label), html('strong', {}, row.value));
    tip.append(line);
  }
  tip.setAttribute('data-show', '');
  const offset = scroll.offsetLeft - scroll.scrollLeft;
  const box = tip.getBoundingClientRect();
  const width = frame.clientWidth;
  let left = pixelX + offset + 12;
  if (left + box.width > width) left = pixelX + offset - box.width - 12;
  left = Math.max(0, Math.min(width - box.width, left));
  const top = Math.max(0, Math.min(frame.clientHeight - box.height, pixelY - box.height / 2));
  tip.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
}

export function hideTip(f) {
  f.tip.removeAttribute('data-show');
}

/** Visuell versteckte Tabelle mit denselben Daten für Screenreader. */
export function dataTable(figure, caption, head, rows) {
  figure.querySelector('table[data-chart-table]')?.remove();
  const table = html('table', { class: 'sr-only', 'data-chart-table': '' });
  table.append(html('caption', {}, caption));
  const thead = html('thead');
  const tr = html('tr');
  for (const cell of head) tr.append(html('th', { scope: 'col' }, cell));
  thead.append(tr);
  const tbody = html('tbody');
  for (const row of rows) {
    const line = html('tr');
    row.forEach((cell, i) => line.append(html(i ? 'td' : 'th', i ? {} : { scope: 'row' }, cell)));
    tbody.append(line);
  }
  table.append(thead, tbody);
  figure.append(table);
}

/** Achsenbeschriftung links (oder rechts) mit Teilstrichen. */
export function drawAxis(target, scale, values, format, { right = false, unit = '' } = {}) {
  target.replaceChildren();
  const width = target.getBoundingClientRect().width || 44;
  const spacing = values.length > 1 ? Math.abs(scale(values[1]) - scale(values[0])) : 100;
  const every = Math.max(1, Math.ceil(20 / Math.max(1, spacing)));
  for (const [i, value] of values.entries()) {
    if (i % every !== 0) continue;
    const y = scale(value);
    svg('text', { x: right ? 6 : width - 6, y: y + 4, 'text-anchor': right ? 'start' : 'end' }, target).textContent = format(value);
  }
  if (unit) {
    svg('text', { class: 'axis-title', x: right ? 6 : width - 6, y: 12, 'text-anchor': right ? 'start' : 'end' }, target).textContent = unit;
  }
}

/** Pfeiltasten wandern über die Punkte; Pos1 und Ende springen an die Ränder. */
export function bindKeys(f, count, onIndex) {
  let index = -1;
  f.plot.addEventListener('keydown', (event) => {
    const keys = { ArrowRight: 1, ArrowLeft: -1, PageDown: 10, PageUp: -10 };
    if (event.key in keys) index = Math.max(0, Math.min(count() - 1, (index < 0 ? 0 : index) + keys[event.key]));
    else if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = count() - 1;
    else if (event.key === 'Escape') {
      hideTip(f);
      onIndex(-1);
      return;
    } else return;
    event.preventDefault();
    onIndex(index);
  });
  f.plot.addEventListener('blur', () => {
    hideTip(f);
    onIndex(-1, true);
  });
}
