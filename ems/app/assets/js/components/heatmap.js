// Statistik-Heatmap: holt die Zellen von /api/series?chart=heatmap und zeichnet sie je nach Aufbau: 24 Stunden eines
// Tages, Monatsblöcke im Kalender (Monat, Quartal, Jahr, eigener Zeitraum), Tage × Stunden oder Jahre × Monate.
// Die Farbstärke steht als --v (0…1) über CSSOM an der Zelle, die Schrift wird hell oder dunkel nach der Helligkeit
// des Felds. Antippen oder Zeigen schreibt den Wert unter die Heatmap; die Tabelle darunter ist die zugängliche Form.
import { $, $$, getJson, html } from '../core/dom.js';

const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const MONTHS = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];

// Farbe eines Felds als sRGB 0…1, aus rgb(…) oder color(srgb …), wie getComputedStyle sie liefert.
function channels(text) {
  const srgb = /color\(srgb\s+([\d.e-]+)\s+([\d.e-]+)\s+([\d.e-]+)/.exec(text);
  if (srgb) return srgb.slice(1, 4).map(Number);
  const rgb = /rgba?\(([^)]+)\)/.exec(text);
  if (rgb) return rgb[1].split(/[\s,/]+/).slice(0, 3).map((part) => Number(part) / 255);
  return null;
}

function luminance(color) {
  const [r, g, b] = color.map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

// Schwarz oder Weiß, je nachdem, was auf dem Feld mehr Kontrast hat (Umschlag bei Helligkeit 0,179).
export function ink(cells) {
  for (const cell of cells) {
    const color = channels(getComputedStyle(cell).backgroundColor);
    if (color) cell.dataset.ink = luminance(color) > 0.179 ? 'dark' : 'light';
  }
}

function cell(data, index, extra = {}) {
  const node = html('div', { class: 'hm-cell', 'data-i': index, ...extra });
  if (data.o) node.dataset.out = '';
  if (data.v === null || data.v === undefined) node.dataset.empty = '';
  else node.style.setProperty('--v', String(data.v));
  if (data.f) node.dataset.future = '';
  return node;
}

function label(text, className) {
  return html('span', { class: className }, text);
}

function hours(data, list) {
  const grid = html('div', { class: 'hm-hours' });
  data.cells.forEach((item, i) => {
    list.push(item);
    const node = cell(item, list.length - 1);
    node.append(label(String(i), 'hm-d'));
    if (item.n !== null) node.append(label(item.n, 'hm-n'));
    grid.append(node);
  });
  return grid;
}

function calendar(data, list) {
  const months = html('div', { class: 'hm-months' });
  if (data.groups.length === 1) months.dataset.single = '';
  for (const group of data.groups) {
    const block = html('section', { class: 'hm-month' });
    block.append(html('h3', { class: 'hm-month-title' }, group.label));
    const week = html('div', { class: 'hm-week' });
    for (const day of WEEKDAYS) week.append(label(day, 'hm-weekday'));
    const days = html('div', { class: 'hm-days' });
    for (let i = 0; i < group.offset; i += 1) days.append(html('span', { class: 'hm-gap' }));
    for (const item of group.cells) {
      list.push(item);
      const node = cell(item, list.length - 1);
      node.append(label(String(item.d), 'hm-d'));
      if (item.n !== null && !item.o) node.append(label(item.n, 'hm-n'));
      days.append(node);
    }
    block.append(week, days);
    months.append(block);
  }
  return months;
}

function matrix(data, list) {
  const grid = html('div', { class: 'hm-matrix' });
  grid.append(html('span', { class: 'hm-corner' }));
  for (let h = 0; h < 24; h += 1) grid.append(label(h % 3 === 0 ? String(h) : '', 'hm-col'));
  for (const row of data.rows) {
    grid.append(label(row.label, 'hm-row'));
    for (const item of row.cells) {
      list.push(item);
      grid.append(cell(item, list.length - 1));
    }
  }
  return grid;
}

function years(data, list) {
  const grid = html('div', { class: 'hm-years' });
  grid.append(html('span', { class: 'hm-corner' }));
  for (const month of MONTHS) grid.append(label(month, 'hm-col'));
  for (const row of data.rows) {
    grid.append(label(row.label, 'hm-row'));
    for (const item of row.cells) {
      list.push(item);
      const node = cell(item, list.length - 1);
      if (item.n !== null) node.append(label(item.n, 'hm-n'));
      grid.append(node);
    }
  }
  return grid;
}

function table(list, metric) {
  const rows = list.filter((item) => item.t !== null && !item.o);
  if (!rows.length) return html('p', { class: 'body-sm muted' }, 'Keine Werte in diesem Zeitraum.');
  const wrap = html('div', { class: 'table-wrap', tabindex: '0', role: 'region', 'aria-label': `${metric} als Tabelle` });
  const tableNode = html('table', { class: 'table table-compact' });
  const head = html('thead');
  const headRow = html('tr');
  headRow.append(html('th', { scope: 'col' }, 'Zeit'), html('th', { scope: 'col', class: 'num' }, metric));
  head.append(headRow);
  const body = html('tbody');
  for (const item of rows) {
    const row = html('tr');
    row.append(html('th', { scope: 'row' }, item.l), html('td', { class: 'num' }, item.t));
    body.append(row);
  }
  tableNode.append(head, body);
  wrap.append(tableNode);
  return wrap;
}

function summary(box, items) {
  box.replaceChildren();
  for (const item of items) {
    const entry = html('div');
    const value = html('dd', {}, item.text);
    if (item.sub) value.append(html('span', { class: 'sub' }, item.sub));
    entry.append(html('dt', {}, item.label), value);
    box.append(entry);
  }
}

function render(figure, data) {
  const frame = $('[data-hm-frame]', figure);
  const card = figure.closest('.hm-card') || document;
  const detail = $('[data-hm-detail]', card);
  const list = [];
  const draw = { hours, calendar, matrix, years }[data.layout];
  frame.replaceChildren(draw ? draw(data, list) : html('p', {}, ''));
  if (data.empty) {
    frame.append(html('p', { class: 'body-sm muted hm-empty' }, 'Für diesen Zeitraum gibt es keine Werte.'));
  }
  ink($$('.hm-cell:not([data-empty])', frame));

  const legend = $('[data-hm-legend]', figure);
  if (legend) {
    legend.hidden = Boolean(data.empty);
    $('[data-hm-lo]', legend).textContent = data.scale.lo;
    $('[data-hm-hi]', legend).textContent = data.scale.hi;
  }
  summary($('[data-hm-summary]', card), data.summary || []);
  const notes = $('[data-hm-notes]', card);
  if (notes) {
    notes.replaceChildren(...(data.notes || []).map((text) => html('p', { class: 'caption muted' }, text)));
  }
  const tableBox = $('[data-hm-table]', card);
  if (tableBox) tableBox.replaceChildren(table(list, data.metric.label));

  // Wert eines Felds unter der Heatmap; Zeigen (Maus) und Antippen (Handy) wählen es aus.
  let active = null;
  const show = (node) => {
    const item = list[Number(node.dataset.i)];
    if (!item || item.o) return;
    active?.removeAttribute('data-active');
    active = node;
    node.setAttribute('data-active', '');
    if (detail) detail.textContent = `${item.l}: ${item.t ?? (item.f ? 'noch keine Werte' : 'keine Daten')}`;
  };
  frame.addEventListener('pointerover', (event) => {
    if (event.pointerType !== 'mouse') return;
    const node = event.target.closest('.hm-cell');
    if (node) show(node);
  });
  frame.addEventListener('click', (event) => {
    const node = event.target.closest('.hm-cell');
    if (node) show(node);
  });
}

async function load(figure) {
  const frame = $('[data-hm-frame]', figure);
  try {
    const data = await getJson(figure.dataset.src);
    frame.removeAttribute('data-loading');
    render(figure, data);
  } catch {
    frame.removeAttribute('data-loading');
    frame.replaceChildren(html('p', { class: 'body-sm hm-empty' }, 'Die Statistik ließ sich gerade nicht laden. Bitte die Seite neu laden.'));
  }
}

export function initHeatmaps(root = document) {
  for (const figure of $$('[data-heatmap]', root)) load(figure);
}
