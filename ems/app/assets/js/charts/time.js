// Zeitreihen (9.1, 9.2): Fläche mit monotoner Kurve, Tagesgrenzen gestrichelt, Tag unter der Achse,
// heute hinterlegt, „Jetzt“-Linie, Peak-Badges mit Tagesenergie, Tages-Min/Max.
// Lange Reihen scrollen waagerecht, die y-Achse bleibt stehen.
import { svg } from '../core/dom.js';
import { dayLabel, fmt, NNBSP, timeLabel, weekday } from '../core/format.js';
import { bindKeys, bindPointer, buildFrame, dataTable, drawAxis, hideTip, showTip } from './frame.js';
import { colorClass, curve, linear, nearest, nice, runs, ticks, unitFromTitle } from './util.js';

const DAY = 86400000;
const TOP = 36;
const MAX_WIDTH = 16000;
let uid = 0;

// Breite einer Beschriftung in 12-px-Inter, grob geschätzt, damit nichts am Rand klebt.
const textWidth = (text) => String(text).length * 6.6 + 6;

function startOfDay(ms) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(ms));
  const guess = Date.parse(`${parts}T00:00:00Z`);
  const offset = new Date(guess).toLocaleString('en-US', { timeZone: 'Europe/Berlin', hour12: false, hour: '2-digit' });
  return guess - Number(offset) * 3600000;
}

/** Sichtbares Fenster: „today“ ab heute, anchor „now“ volle Tage bis heute (heute ganz rechts). */
export function windowRange(name, anchor, payload, bounds) {
  const now = payload.now || Date.now();
  const today = payload.today ? payload.today[0] : startOfDay(now);
  if (name === 'all') return bounds;
  if (name === 'today') return [today, today + DAY];
  const days = name === '24' ? 1 : Number(name) || 3;
  return anchor === 'now' ? [today + DAY - days * DAY, today + DAY] : [today, today + days * DAY];
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
  const now = payload.now || Date.now();
  const today = payload.today || [startOfDay(now), startOfDay(now) + DAY];

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
  // Mit Uhrzeiten gibt es zwei Zeilen unter der Achse: oben die Stunden, darunter der Tag.
  const dayPx = DAY * perMs;
  const hours = dayPx >= 260;
  const bottom = hours ? 46 : 30;

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
  const y = linear([0, axis.max], [height - bottom, TOP]);
  const unit = style._unit || unitFromTitle(axis.title);
  drawAxis(f.yAxis, y, ticks(axis.dataMax ?? axis.max, axis.step), (v) => fmt(v, axis.decimals), { unit });
  let y1 = null;
  if (f.y1Axis) {
    const max1 = Math.max(1, ...right.flatMap((series) => (series.data || []).map((p) => p.y ?? 0)));
    const n1 = nice(max1 * 1.05, 4);
    y1 = linear([0, n1.max], [height - bottom, TOP]);
    drawAxis(f.y1Axis, y1, ticks(n1.max, n1.step), (v) => fmt(v, n1.step < 1 ? 1 : 0), { right: true, unit: unitFromTitle(payload.y1Title) });
  }

  const plot = f.plot;
  if (state.first) plot.classList.add('enter');
  const id = `g${(uid += 1)}`;
  const defs = svg('defs', {}, plot);

  // Heute hinterlegt, damit der aktuelle Tag beim Scrollen eindeutig bleibt.
  if (today[1] > bounds[0] && today[0] < bounds[1]) {
    const from = x(Math.max(today[0], bounds[0]));
    svg('rect', { class: 'today-zone', x: from, y: TOP - 8, width: x(Math.min(today[1], bounds[1])) - from, height: height - bottom - TOP + 8 }, plot);
  }
  svg('line', { class: 'base-line', x1: 0, x2: plotWidth, y1: y(0), y2: y(0) }, plot);

  // Tagesgrenzen um Mitternacht; der Tag mittig im sichtbaren Teil, nie über den Rand hinaus.
  const marks = dayMarks(payload, bounds[0], bounds[1]);
  const dayY = hours ? height - 6 : height - 9;
  for (const mark of marks) {
    if (mark.start > bounds[0] + 1000 && mark.start < bounds[1]) {
      svg('line', { class: 'day-line', x1: x(mark.start), x2: x(mark.start), y1: TOP - 8, y2: height - bottom }, plot);
    }
    const from = Math.max(mark.start, bounds[0]);
    const to = Math.min(mark.start + DAY, bounds[1]);
    if (to <= from) continue;
    const isToday = mark.x >= today[0] && mark.x < today[1];
    const room = x(to) - x(from);
    const long = isToday ? 'Heute' : mark.label || dayLabel(mark.x);
    const text = room >= textWidth(long) + 8 ? long : isToday ? 'Heute' : weekday(mark.x);
    const w = textWidth(text);
    // Heute steht immer da, notfalls etwas breiter als sein Tag; die anderen Tage nur, wenn sie passen.
    if (room >= w + 4 || isToday) {
      const middle = (x(from) + x(to)) / 2;
      const cx = room >= w + 4 ? Math.max(x(from) + w / 2 + 2, Math.min(x(to) - w / 2 - 2, middle)) : Math.max(w / 2 + 2, Math.min(plotWidth - w / 2 - 2, middle));
      svg('text', { x: cx, y: dayY, 'text-anchor': 'middle', class: isToday ? 'day-today' : null }, plot).textContent = text;
    }
    if (hours) {
      for (const hour of [6, 12, 18]) {
        const at = mark.start + hour * 3600000;
        if (at <= bounds[0] + 1800000 || at >= bounds[1] - 1800000) continue;
        svg('text', { x: x(at), y: height - bottom + 14, 'text-anchor': 'middle', class: 'hour' }, plot).textContent = `${hour}:00`;
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
      svg('stop', { offset: 0, class: 'grad-stop', 'stop-opacity': series.opacity ?? 0.35 }, gradient);
      svg('stop', { offset: 1, class: 'grad-stop', 'stop-opacity': 0 }, gradient);
      for (const run of runs(points)) {
        const d = `${curve(run, kind)}L${run[run.length - 1][0]},${scaleY(0)}L${run[0][0]},${scaleY(0)}Z`;
        svg('path', { class: 'series-area', d, fill: `url(#${id}-${series.key})` }, group);
      }
    }
    for (const run of runs(points)) {
      const cls = `series-line${series.width === 'thin' ? ' thin' : ''}${series.width === 'bold' ? ' bold' : ''}${series.dash ? ' dashed' : ''}`;
      svg('path', { class: cls, d: curve(run, kind) }, group);
    }
    lines.push({ series, xs: (series.data || []).map((p) => p.x), scaleY });
  }

  // Peak-Badge pro Tag über dem höchsten Punkt der Prognose, mit Unsicherheit, wenn sie in den Tag passt.
  const peakSeries = visible.find((series) => series.peak) || null;
  if (peakSeries) {
    for (const mark of marks) {
      if (!mark.text || mark.x < bounds[0] || mark.x > bounds[1]) continue;
      const inDay = (peakSeries.data || []).filter((p) => p.x >= mark.start && p.x < mark.start + DAY && p.y !== null);
      if (!inDay.length) continue;
      const top = inDay.reduce((best, p) => (p.y > best.y ? p : best), inDay[0]);
      const full = String(mark.text);
      const [energy] = full.split(' ± ');
      const short = /kWh$/.test(energy) ? energy : `${energy}${NNBSP}kWh`;
      const dayLeft = Math.max(x(mark.start), x(bounds[0])) + 2;
      const dayRight = Math.min(x(mark.start + DAY), x(bounds[1])) - 2;
      const label = dayRight - dayLeft >= full.length * 6.4 + 20 ? full : short;
      const w = label.length * 6.4 + 16;
      const bx = dayRight - dayLeft < w ? x(top.x) - w / 2 : Math.max(dayLeft, Math.min(dayRight - w, x(top.x) - w / 2));
      const by = Math.max(4, y(top.y) - 32);
      const badge = svg('g', { class: 'peak' }, plot);
      svg('rect', { x: bx, y: by, width: w, height: 24, rx: 8 }, badge);
      svg('text', { x: bx + w / 2, y: by + 16, 'text-anchor': 'middle' }, badge).textContent = label;
    }
  }

  // Tages-Maximum und -Minimum (Speicherverlauf) als Kapseln wie die Peaks der Solarprognose:
  // das Maximum gefüllt über dem Punkt, das Minimum umrandet darunter, am Rand des Plots auf der anderen Seite.
  const extrema = payload.extrema?.[unitKey];
  if (extrema?.length) {
    const group = svg('g', { class: `extrema ${colorClass(visible[0]?.color)}` }, plot);
    const placed = [];
    const overlaps = (box) => placed.some((other) => box.l < other.r && box.r > other.l && box.t < other.b && box.b > other.t);
    const floor = height - bottom;
    for (const day of extrema) {
      for (const [kind, point] of [['max', day.max], ['min', day.min]]) {
        if (!point || point.x < bounds[0] || point.x > bounds[1]) continue;
        const cx = x(point.x);
        const cy = y(point.y);
        svg('circle', { cx, cy, r: 4 }, group);
        // Unter 70 px je Tag nur die Punkte; den Wert zeigt das Antippen.
        if (dayPx < 70) continue;
        const text = `${kind} ${fmt(point.y, axis.decimals)}${NNBSP}${unit}`;
        const w = text.length * 6.4 + 16;
        const above = cy - 34;
        const below = cy + 10;
        const by = kind === 'max' ? (above >= 0 ? above : below) : (below + 24 <= floor - 2 ? below : above);
        // Die Kapsel bleibt in ihrem Tag, damit sie am Rand des Fensters nicht angeschnitten wird.
        const dayStart = startOfDay(point.x);
        const dayLeft = Math.max(2, x(dayStart) + 2);
        const dayRight = Math.min(plotWidth - 2, x(dayStart + DAY) - 2);
        const bx = dayRight - dayLeft >= w ? Math.max(dayLeft, Math.min(dayRight - w, cx - w / 2)) : Math.max(2, Math.min(plotWidth - w - 2, cx - w / 2));
        // Kapseln, die sich überdecken würden, entfallen; der Punkt bleibt.
        const box = { l: bx - 3, r: bx + w + 3, t: by - 3, b: by + 27 };
        if (overlaps(box)) continue;
        placed.push(box);
        const badge = svg('g', { class: `badge badge-${kind}` }, group);
        svg('rect', { x: bx, y: by, width: w, height: 24, rx: 8 }, badge);
        svg('text', { x: bx + w / 2, y: by + 16, 'text-anchor': 'middle' }, badge).textContent = text;
      }
    }
  }

  // „Jetzt“: senkrechte Linie mit Punkt auf der ersten Serie
  if (now > bounds[0] && now < bounds[1]) {
    svg('line', { class: 'now-line', x1: x(now), x2: x(now), y1: TOP - 8, y2: height - bottom }, plot);
    const first = lines[0];
    if (first) {
      const i = nearest(first.xs, now);
      const value = i >= 0 ? first.series.data[i].y : null;
      if (value !== null && value !== undefined) svg('circle', { class: 'now-dot', cx: x(now), cy: first.scaleY(value), r: 3 }, plot);
    }
  }

  // Fadenkreuz, Tooltip, Tippen und Tastatur über alle Zeitpunkte der sichtbaren Serien
  const cross = svg('line', { class: 'crosshair', x1: 0, x2: 0, y1: TOP - 8, y2: height - bottom, visibility: 'hidden' }, plot);
  const primary = lines.find((line) => line.xs.length) || null;
  const stamps = [...new Set(lines.flatMap((line) => line.xs))].sort((a, b) => a - b);
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
    const anchor = lines.find((line) => valueOf(line, ms) !== null);
    const v0 = anchor ? valueOf(anchor, ms) : null;
    showTip(f, px, v0 === null ? height / 2 : anchor.scaleY(v0), `${dayLabel(ms)}, ${timeLabel(ms)}`, rows);
  };
  const clear = () => {
    hideTip(f);
    cross.setAttribute('visibility', 'hidden');
  };
  bindPointer(f, (px) => {
    const i = nearest(stamps, x.invert(px));
    if (i >= 0) show(stamps[i]);
  }, clear);
  const inView = () => stamps.filter((ms) => ms >= bounds[0] && ms <= bounds[1]);
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
    const shown = stamps.filter((ms) => ms >= view[0] && ms <= view[1]);
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
