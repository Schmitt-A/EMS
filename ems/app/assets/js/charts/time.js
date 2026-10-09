// Zeitreihen (9.1, 9.2): Fläche mit monotoner Kurve, Tagesgrenzen gestrichelt, Wochentag unter der Achse,
// „Jetzt“-Linie, Peak-Badges mit Tagesenergie, Tages-Min/Max. Lange Reihen scrollen, die y-Achse bleibt.
import { svg } from '../core/dom.js';
import { dayLabel, fmt, NNBSP, timeLabel, weekday } from '../core/format.js';
import { bindKeys, buildFrame, dataTable, drawAxis, hideTip, showTip } from './frame.js';
import { colorClass, curve, linear, nearest, nice, runs, ticks, unitFromTitle } from './util.js';

const DAY = 86400000;
const TOP = 36;
const BOTTOM = 30;
const MAX_WIDTH = 16000;
let uid = 0;

function startOfDay(ms) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(ms));
  const guess = Date.parse(`${parts}T00:00:00Z`);
  const offset = new Date(guess).toLocaleString('en-US', { timeZone: 'Europe/Berlin', hour12: false, hour: '2-digit' });
  return guess - Number(offset) * 3600000;
}

export function windowRange(name, anchor, payload, bounds) {
  const now = payload.now || Date.now();
  const today = payload.today ? payload.today[0] : startOfDay(now);
  if (name === 'all') return bounds;
  if (name === 'today') return [today, today + DAY];
  const days = name === '24' ? 1 : Number(name) || 3;
  return anchor === 'now' ? [now - days * DAY, now] : [today, today + days * DAY];
}

function dayMarks(payload, b0, b1) {
  if (payload.marks?.length) return payload.marks;
  const marks = [];
  for (let start = startOfDay(b0); start < b1; start += DAY) {
    const day = startOfDay(start + DAY / 2);
    marks.push({ start: day, x: day + DAY / 2, label: dayLabel(day + DAY / 2), text: null });
  }
  return marks;
}

export function renderTime(figure, payload, state) {
  const style = state.style || {};
  const all = (payload.series || []).map((series) => ({ ...series, ...(style[series.key] || {}) }));
  const unitKey = state.unit || payload.unit;
  const visible = all.filter((series) => (payload.axes ? series.key === unitKey : !state.hidden.has(series.key)));
  const left = visible.filter((series) => (series.axis || 'y') !== 'y1');
  const right = visible.filter((series) => series.axis === 'y1');
  const f = buildFrame(figure, { right: all.some((series) => series.axis === 'y1') });
  const width = Math.max(1, f.width);
  const height = Math.max(120, f.height);

  const xs = all.flatMap((series) => (series.data || []).map((point) => point.x)).filter((x) => Number.isFinite(x));
  let bounds = payload.bounds || [Math.min(...xs), Math.max(...xs)];
  const view = state.window ? windowRange(state.window, state.anchor, payload, bounds) : payload.view || bounds;
  bounds = [Math.min(bounds[0], view[0]), Math.max(bounds[1], view[1])];
  const perMs = width / Math.max(1, view[1] - view[0]);
  if ((bounds[1] - bounds[0]) * perMs > MAX_WIDTH) bounds[0] = Math.min(view[0], bounds[1] - MAX_WIDTH / perMs);
  const plotWidth = Math.max(width, Math.round((bounds[1] - bounds[0]) * perMs));
  f.plot.setAttribute('width', String(plotWidth));
  f.plot.setAttribute('height', String(height));
  f.plot.setAttribute('viewBox', `0 0 ${plotWidth} ${height}`);
  const x = linear(bounds, [0, plotWidth]);

  let axis = null;
  if (payload.axes && payload.axes[unitKey]) {
    const a = payload.axes[unitKey];
    axis = { max: a.yMax, step: a.yStep, dataMax: a.yDataMax, decimals: a.yDecimals ?? 1, title: a.yTitle };
  } else if (payload.yLock) {
    axis = { max: payload.yMax, step: payload.yStep, dataMax: payload.yDataMax, decimals: payload.yDecimals ?? 1, title: payload.yTitle };
  } else {
    const maxY = Math.max(0, ...left.flatMap((series) => (series.data || []).map((p) => (p.y ?? p.max ?? 0))));
    const n = nice(maxY * 1.08, 4);
    axis = { max: n.max, step: n.step, dataMax: n.max, decimals: n.step < 1 ? 1 : 0, title: payload.yTitle };
  }
  const y = linear([0, axis.max], [height - BOTTOM, TOP]);
  const unit = style._unit || unitFromTitle(axis.title);
  drawAxis(f.yAxis, y, ticks(axis.dataMax ?? axis.max, axis.step), (v) => fmt(v, axis.decimals), { unit });
  let y1 = null;
  if (f.y1Axis) {
    const max1 = Math.max(1, ...right.flatMap((series) => (series.data || []).map((p) => p.y ?? 0)));
    const n1 = nice(max1 * 1.05, 4);
    y1 = linear([0, n1.max], [height - BOTTOM, TOP]);
    drawAxis(f.y1Axis, y1, ticks(n1.max, n1.step), (v) => fmt(v, n1.step < 1 ? 1 : 0), { right: true, unit: unitFromTitle(payload.y1Title) });
  }

  const plot = f.plot;
  if (state.first) plot.classList.add('enter');
  const id = `g${(uid += 1)}`;
  const defs = svg('defs', {}, plot);
  svg('line', { class: 'base-line', x1: 0, x2: plotWidth, y1: y(0), y2: y(0) }, plot);

  // Tagesgrenzen um Mitternacht und Wochentag unter der Achse
  const marks = dayMarks(payload, bounds[0], bounds[1]);
  const dayPx = DAY * perMs;
  for (const mark of marks) {
    if (mark.start > bounds[0] + 1000 && mark.start < bounds[1]) {
      svg('line', { class: 'day-line', x1: x(mark.start), x2: x(mark.start), y1: TOP - 8, y2: height - BOTTOM }, plot);
    }
    const cx = Math.max(x(bounds[0]) + 24, Math.min(x(bounds[1]) - 24, x(mark.x)));
    if (mark.x < bounds[0] || mark.x > bounds[1]) continue;
    svg('text', { x: cx, y: height - 9, 'text-anchor': 'middle' }, plot).textContent = dayPx < 90 ? weekday(mark.x) : mark.label || dayLabel(mark.x);
    if (dayPx >= 260) {
      for (const hour of [6, 12, 18]) {
        const at = mark.start + hour * 3600000;
        if (at <= bounds[0] || at >= bounds[1]) continue;
        svg('text', { x: x(at), y: height - BOTTOM + 13, 'text-anchor': 'middle', class: 'hour' }, plot).textContent = `${hour}:00`;
      }
    }
  }

  // Serien: Fläche mit Verlauf von 35 % auf 0 %, Linie darüber
  const lines = [];
  for (const series of visible) {
    const scaleY = series.axis === 'y1' && y1 ? y1 : y;
    const points = (series.data || [])
      .filter((p) => p.x >= bounds[0] - DAY && p.x <= bounds[1] + DAY)
      .map((p) => [x(p.x), p.y === null || p.y === undefined ? null : scaleY(Math.max(0, p.y))]);
    const group = svg('g', { class: colorClass(series.color) }, plot);
    const kind = series.curve || (series.type === 'step' ? 'step' : 'monotone');
    if (series.type === 'area') {
      const gradient = svg('linearGradient', { id: `${id}-${series.key}`, x1: 0, y1: 0, x2: 0, y2: 1, class: colorClass(series.color) }, defs);
      svg('stop', { offset: 0, class: 'grad-stop', 'stop-opacity': 0.35 }, gradient);
      svg('stop', { offset: 1, class: 'grad-stop', 'stop-opacity': 0 }, gradient);
      for (const run of runs(points)) {
        const d = `${curve(run, kind)}L${run[run.length - 1][0]},${scaleY(0)}L${run[0][0]},${scaleY(0)}Z`;
        svg('path', { class: 'series-area', d, fill: `url(#${id}-${series.key})` }, group);
      }
    }
    for (const run of runs(points)) {
      svg('path', { class: `series-line${series.width === 'thin' ? ' thin' : ''}${series.dash ? ' dashed' : ''}`, d: curve(run, kind) }, group);
    }
    lines.push({ series, xs: (series.data || []).map((p) => p.x), scaleY });
  }

  // Peak-Badge pro Tag über dem höchsten Punkt der Prognose
  const peakSeries = visible.find((series) => series.peak) || null;
  if (peakSeries) {
    for (const mark of marks) {
      if (!mark.text || mark.x < bounds[0] || mark.x > bounds[1]) continue;
      const inDay = (peakSeries.data || []).filter((p) => p.x >= mark.start && p.x < mark.start + DAY && p.y !== null);
      if (!inDay.length) continue;
      const top = inDay.reduce((best, p) => (p.y > best.y ? p : best), inDay[0]);
      const [energy] = String(mark.text).split(' ± ');
      const label = /kWh$/.test(energy) ? energy : `${energy}${NNBSP}kWh`;
      const w = label.length * 6.4 + 16;
      const dayLeft = Math.max(x(mark.start), x(bounds[0])) + 2;
      const dayRight = Math.min(x(mark.start + DAY), x(bounds[1])) - 2;
      const bx = dayRight - dayLeft < w ? x(top.x) - w / 2 : Math.max(dayLeft, Math.min(dayRight - w, x(top.x) - w / 2));
      const by = Math.max(4, y(top.y) - 32);
      const badge = svg('g', { class: 'peak' }, plot);
      svg('rect', { x: bx, y: by, width: w, height: 24, rx: 8 }, badge);
      svg('text', { x: bx + w / 2, y: by + 16, 'text-anchor': 'middle' }, badge).textContent = label;
    }
  }

  // Tages-Minimum und -Maximum (Speicherverlauf)
  const extrema = payload.extrema?.[unitKey];
  if (extrema?.length) {
    const group = svg('g', { class: `extrema ${colorClass(visible[0]?.color)}` }, plot);
    for (const day of extrema) {
      for (const [kind, point] of [['max', day.max], ['min', day.min]]) {
        if (!point || point.x < bounds[0] || point.x > bounds[1]) continue;
        const cx = x(point.x);
        const cy = y(point.y);
        svg('circle', { cx, cy, r: 3.5 }, group);
        const text = `${kind} ${fmt(point.y, axis.decimals)}${NNBSP}${unit}`;
        svg('text', { x: cx, y: kind === 'max' ? cy - 8 : cy + 16, 'text-anchor': 'middle' }, group).textContent = text;
      }
    }
  }

  // „Jetzt“: senkrechte Linie mit Punkt auf der ersten Serie
  const now = payload.now || Date.now();
  if (now > bounds[0] && now < bounds[1]) {
    svg('line', { class: 'now-line', x1: x(now), x2: x(now), y1: TOP - 8, y2: height - BOTTOM }, plot);
    const first = lines[0];
    if (first) {
      const i = nearest(first.xs, now);
      const value = i >= 0 ? first.series.data[i].y : null;
      if (value !== null && value !== undefined) svg('circle', { class: 'now-dot', cx: x(now), cy: first.scaleY(value), r: 3 }, plot);
    }
  }

  // Fadenkreuz, Tooltip und Tastatur
  const cross = svg('line', { class: 'crosshair', x1: 0, x2: 0, y1: TOP - 8, y2: height - BOTTOM, visibility: 'hidden' }, plot);
  const primary = lines.find((line) => line.xs.length) || null;
  const valueOf = (line, ms) => {
    const i = nearest(line.xs, ms);
    if (i < 0) return null;
    const point = line.series.data[i];
    return Math.abs(point.x - ms) <= DAY / 24 ? point.y : null;
  };
  const show = (ms) => {
    const px = x(ms);
    cross.setAttribute('x1', String(px));
    cross.setAttribute('x2', String(px));
    cross.setAttribute('visibility', 'visible');
    const rows = lines.map((line) => {
      const v = valueOf(line, ms);
      const u = line.series.unit || (line.series.axis === 'y1' ? unitFromTitle(payload.y1Title) : unit);
      return { label: line.series.label, value: v === null ? '—' : `${fmt(v, line.series.decimals ?? axis.decimals)}${u ? NNBSP + u : ''}`, cls: colorClass(line.series.color) };
    });
    const v0 = primary ? valueOf(primary, ms) : null;
    showTip(f, px, v0 === null ? height / 2 : primary.scaleY(v0), `${dayLabel(ms)}, ${timeLabel(ms)}`, rows);
  };
  plot.addEventListener('pointermove', (event) => {
    const box = plot.getBoundingClientRect();
    const ms = x.invert(event.clientX - box.left);
    if (!primary) return;
    const i = nearest(primary.xs, ms);
    if (i >= 0) show(primary.xs[i]);
  });
  plot.addEventListener('pointerleave', () => {
    hideTip(f);
    cross.setAttribute('visibility', 'hidden');
  });
  const inView = () => (primary ? primary.xs.filter((ms) => ms >= bounds[0] && ms <= bounds[1]) : []);
  bindKeys(f, () => inView().length, (index) => {
    const list = inView();
    if (index < 0 || !list.length) {
      cross.setAttribute('visibility', 'hidden');
      return;
    }
    const ms = list[index];
    const px = x(ms);
    if (px < f.scroll.scrollLeft || px > f.scroll.scrollLeft + width) f.scroll.scrollLeft = px - width / 2;
    show(ms);
  });

  // Barrierefreiheit: Zusammenfassung und Tabelle mit höchstens 48 Zeilen aus dem sichtbaren Fenster
  const label = figure.dataset.label || 'Diagramm';
  plot.setAttribute('aria-label', `${label}. ${dayLabel(view[0])} bis ${dayLabel(view[1] - 1)}. Pfeiltasten zeigen einzelne Werte.`);
  if (primary) {
    const shown = primary.xs.filter((ms) => ms >= view[0] && ms <= view[1]);
    const step = Math.max(1, Math.ceil(shown.length / 48));
    const rows = shown.filter((_, i) => i % step === 0).map((ms) => [
      `${dayLabel(ms)}, ${timeLabel(ms)}`,
      ...lines.map((line) => {
        const v = valueOf(line, ms);
        return v === null ? '—' : `${fmt(v, line.series.decimals ?? axis.decimals)}${NNBSP}${line.series.unit || unit}`;
      }),
    ]);
    dataTable(figure, label, ['Zeit', ...lines.map((line) => line.series.label)], rows);
  }

  f.scroll.scrollLeft = f.keepScroll !== null && !state.jump ? f.keepScroll : x(view[0]);
  return { bounds, view };
}
