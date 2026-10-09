// Säulen nach Kategorien (9.5): gestapelt oder gruppiert, nur das oberste Segment mit 6 px Radius,
// 2 px Lücke statt Rändern, Linien (Güte, Ø ct/kWh) auf einer zweiten Achse, Legende mit Summen.
import { html, svg } from '../core/dom.js';
import { fmt, NNBSP } from '../core/format.js';
import { bindKeys, buildFrame, dataTable, drawAxis, hideTip, showTip } from './frame.js';
import { colorClass, curve, linear, nice, runs, ticks, unitFromTitle } from './util.js';

const TOP = 20;
const BOTTOM = 30;

function topRounded(x, y, w, h, r) {
  const radius = Math.min(r, w / 2, h);
  if (h <= 0 || w <= 0) return '';
  return `M${x},${y + h}V${y + radius}Q${x},${y} ${x + radius},${y}H${x + w - radius}Q${x + w},${y} ${x + w},${y + radius}V${y + h}Z`;
}

export function renderBars(figure, payload, state) {
  const style = state.style || {};
  const labels = payload.labels || [];
  const all = (payload.series || []).map((series) => ({ ...series, ...(style[series.key] || {}) }));
  const visible = all.filter((series) => !state.hidden.has(series.key));
  const barsList = visible.filter((series) => series.type === 'bar' && (series.axis || 'y') !== 'y1');
  const lineList = visible.filter((series) => series.type !== 'bar');
  const f = buildFrame(figure, { right: all.some((series) => series.axis === 'y1') });
  const width = Math.max(1, f.width);
  const height = Math.max(120, f.height);

  const span = state.window && payload.windows?.[state.window] ? payload.windows[state.window] : payload.bounds || [0, labels.length - 1];
  const view = state.window && payload.windows?.[state.window] ? span : payload.view || span;
  const first = Math.max(0, span[0]);
  const last = Math.min(labels.length - 1, span[1]);
  const count = Math.max(1, last - first + 1);
  const viewCount = Math.max(1, Math.min(last, view[1]) - Math.max(first, view[0]) + 1);
  const slot = Math.max(payload.stacked ? 22 : 30, width / Math.min(count, Math.max(viewCount, 1)));
  const plotWidth = Math.max(width, Math.round(slot * count));
  f.plot.setAttribute('width', String(plotWidth));
  f.plot.setAttribute('height', String(height));
  f.plot.setAttribute('viewBox', `0 0 ${plotWidth} ${height}`);
  const step = plotWidth / count;
  const cx = (i) => (i - first + 0.5) * step;

  const value = (series, i) => {
    const v = series.data?.[i];
    if (v === null || v === undefined) return null;
    return typeof v === 'object' ? v.y : Number(v);
  };
  const indices = Array.from({ length: count }, (_, k) => first + k);
  let maxY = 0;
  for (const i of indices) {
    if (payload.stacked) maxY = Math.max(maxY, barsList.reduce((sum, series) => sum + (value(series, i) || 0), 0));
    else for (const series of [...barsList, ...lineList.filter((s) => (s.axis || 'y') !== 'y1')]) maxY = Math.max(maxY, value(series, i) || 0);
  }
  const n = nice(maxY * 1.1 || 1, 4);
  const y = linear([0, n.max], [height - BOTTOM, TOP]);
  const unit = style._unit || unitFromTitle(payload.yTitle);
  const decimals = style._decimals ?? (n.step < 1 ? 1 : 0);
  drawAxis(f.yAxis, y, ticks(n.max, n.step), (v) => fmt(v, n.step < 1 ? 1 : 0), { unit });
  let y1 = null;
  if (f.y1Axis) {
    const right = visible.filter((series) => series.axis === 'y1');
    const max1 = Math.max(0.1, ...indices.flatMap((i) => right.map((series) => value(series, i) || 0)));
    const n1 = nice(max1 * 1.1, 4);
    y1 = linear([0, n1.max], [height - BOTTOM, TOP]);
    drawAxis(f.y1Axis, y1, ticks(n1.max, n1.step), (v) => fmt(v, n1.step < 1 ? 1 : 0), { right: true, unit: unitFromTitle(payload.y1Title) });
  }

  const plot = f.plot;
  if (state.first) plot.classList.add('enter');
  svg('line', { class: 'base-line', x1: 0, x2: plotWidth, y1: y(0), y2: y(0) }, plot);

  // Säulen
  const groupWidth = Math.min(step * 0.72, payload.stacked ? 36 : 48);
  for (const i of indices) {
    if (payload.stacked) {
      let base = 0;
      const parts = barsList.map((series) => ({ series, v: value(series, i) || 0 })).filter((part) => part.v > 0);
      parts.forEach((part, k) => {
        const top = base + part.v;
        const yTop = y(top) + (k ? 0 : 0);
        const yBottom = y(base) - (k ? 2 : 0);
        const h = Math.max(0, yBottom - yTop);
        const xLeft = cx(i) - groupWidth / 2;
        const g = svg('g', { class: colorClass(part.series.color) }, plot);
        if (k === parts.length - 1) svg('path', { class: 'bar', d: topRounded(xLeft, yTop, groupWidth, h, 6) }, g);
        else svg('rect', { class: 'bar', x: xLeft, y: yTop, width: groupWidth, height: h }, g);
        base = top;
      });
    } else {
      const each = barsList.length ? (groupWidth - (barsList.length - 1) * 2) / barsList.length : 0;
      barsList.forEach((series, k) => {
        const v = value(series, i);
        if (v === null || v <= 0) return;
        const xLeft = cx(i) - groupWidth / 2 + k * (each + 2);
        const g = svg('g', { class: colorClass(series.color) }, plot);
        svg('path', { class: `bar${series.light ? ' bar-light' : ''}`, d: topRounded(xLeft, y(v), each, y(0) - y(v), 6) }, g);
      });
    }
  }

  // Linien über die Kategorien (Güte, Ø-Preis, Vergleichsreihen)
  for (const series of lineList) {
    const scaleY = series.axis === 'y1' && y1 ? y1 : y;
    const points = indices.map((i) => [cx(i), value(series, i) === null ? null : scaleY(value(series, i))]);
    const g = svg('g', { class: colorClass(series.color) }, plot);
    for (const run of runs(points)) {
      svg('path', { class: `series-line${series.dash ? ' dashed ref-line' : ''}${series.width === 'thin' ? ' thin' : ''}`, d: curve(run, series.curve || 'linear') }, g);
      if (!series.dash) for (const [px, py] of run) svg('circle', { class: 'point', cx: px, cy: py, r: 3.5 }, g);
    }
  }

  // Beschriftung unter der Achse, so dicht wie es passt
  const every = Math.max(1, Math.ceil(46 / step));
  indices.forEach((i, k) => {
    if (k % every !== 0) return;
    svg('text', { x: cx(i), y: height - 9, 'text-anchor': 'middle' }, plot).textContent = labels[i] ?? '';
  });

  // Fadenkreuz und Tooltip je Kategorie
  const cross = svg('rect', { class: 'today-band', x: 0, y: TOP - 6, width: step, height: height - BOTTOM - TOP + 6, visibility: 'hidden', rx: 6 }, plot);
  plot.prepend(cross);
  const fmtValue = (series, v) => {
    if (v === null) return '—';
    const u = series.unit ?? (series.axis === 'y1' ? unitFromTitle(payload.y1Title) : unit);
    return `${fmt(v, series.decimals ?? (series.axis === 'y1' ? 2 : decimals))}${u ? NNBSP + u : ''}`;
  };
  const show = (i) => {
    cross.setAttribute('x', String(cx(i) - step / 2));
    cross.setAttribute('visibility', 'visible');
    const rows = visible.filter((series) => !series.dash).map((series) => ({ label: series.label, value: fmtValue(series, value(series, i)), cls: colorClass(series.color) }));
    if (payload.stacked && barsList.length > 1) {
      const sum = barsList.reduce((total, series) => total + (value(series, i) || 0), 0);
      rows.push({ label: 'Summe', value: `${fmt(sum, decimals)}${unit ? NNBSP + unit : ''}`, cls: 'c-ink' });
    }
    const top = Math.min(...visible.map((series) => (value(series, i) === null ? height : (series.axis === 'y1' && y1 ? y1 : y)(value(series, i)))));
    showTip(f, cx(i), Math.min(height / 2, top), labels[i] ?? '', rows);
  };
  plot.addEventListener('pointermove', (event) => {
    const box = plot.getBoundingClientRect();
    const k = Math.max(0, Math.min(count - 1, Math.floor((event.clientX - box.left) / step)));
    show(first + k);
  });
  plot.addEventListener('pointerleave', () => {
    hideTip(f);
    cross.setAttribute('visibility', 'hidden');
  });
  bindKeys(f, () => count, (k) => {
    if (k < 0) {
      cross.setAttribute('visibility', 'hidden');
      return;
    }
    const px = cx(first + k);
    if (px < f.scroll.scrollLeft || px > f.scroll.scrollLeft + width) f.scroll.scrollLeft = px - width / 2;
    show(first + k);
  });

  // Legende mit Summen im sichtbaren Bereich
  figure.querySelector('[data-chart-legend]')?.remove();
  if (payload.legend !== false) {
    const legend = html('ul', { class: 'chart-legend chart-legend-sums caption', 'data-chart-legend': '' });
    for (const series of visible.filter((s) => !s.dash)) {
      const values = indices.map((i) => value(series, i)).filter((v) => v !== null);
      let sum = '';
      if (series.type === 'bar') sum = `${fmt(values.reduce((a, b) => a + b, 0), decimals)}${unit ? NNBSP + unit : ''}`;
      else if (values.length) sum = `Ø ${fmtValue(series, values.reduce((a, b) => a + b, 0) / values.length)}`;
      const item = html('li', { class: colorClass(series.color) });
      item.append(html('span', { class: 'swatch swatch-dot' }), html('span', {}, series.label), html('span', { class: 'sum' }, sum));
      legend.append(item);
    }
    figure.append(legend);
  }

  const label = figure.dataset.label || 'Diagramm';
  plot.setAttribute('aria-label', `${label}. ${labels[first] ?? ''} bis ${labels[last] ?? ''}. Pfeiltasten zeigen einzelne Werte.`);
  dataTable(figure, label, ['', ...visible.filter((s) => !s.dash).map((series) => series.label)], indices.map((i) => [
    labels[i] ?? '',
    ...visible.filter((s) => !s.dash).map((series) => fmtValue(series, value(series, i))),
  ]));
  f.scroll.scrollLeft = f.keepScroll !== null && !state.jump ? f.keepScroll : Math.max(0, cx(Math.max(first, view[0])) - step / 2);
  return { first, last };
}
