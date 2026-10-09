// Skalen, Teilstriche und Pfade für die SVG-Diagramme (Abschnitt 9).
const f = (value) => Math.round(value * 10) / 10;

export function linear(domain, range) {
  const [d0, d1] = domain;
  const [r0, r1] = range;
  const k = d1 === d0 ? 0 : (r1 - r0) / (d1 - d0);
  const scale = (value) => r0 + (value - d0) * k;
  scale.invert = (pixel) => (k === 0 ? d0 : d0 + (pixel - r0) / k);
  scale.domain = domain;
  scale.range = range;
  return scale;
}

/** Runde Obergrenze mit 3 bis 5 Schritten aus 1, 2, 2,5 oder 5 mal Zehnerpotenz. */
export function nice(max, target = 4) {
  if (!(max > 0)) return { max: 1, step: 0.25 };
  const raw = max / target;
  const power = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * power).find((s) => s >= raw) || 10 * power;
  return { max: Math.ceil(max / step - 1e-9) * step, step };
}

export function ticks(max, step) {
  const out = [];
  for (let value = 0; value <= max + step / 1000; value += step) out.push(Math.round(value * 1e6) / 1e6);
  return out;
}

/** Teilt Punkte an Lücken (y null) in zusammenhängende Läufe. */
export function runs(points) {
  const out = [];
  let current = [];
  for (const point of points) {
    if (point[1] === null || Number.isNaN(point[1])) {
      if (current.length) out.push(current);
      current = [];
    } else {
      current.push(point);
    }
  }
  if (current.length) out.push(current);
  return out;
}

export const linePath = (pts) => pts.map((p, i) => `${i ? 'L' : 'M'}${f(p[0])},${f(p[1])}`).join('');

export function stepPath(pts) {
  if (!pts.length) return '';
  let d = `M${f(pts[0][0])},${f(pts[0][1])}`;
  for (let i = 1; i < pts.length; i += 1) d += `H${f(pts[i][0])}V${f(pts[i][1])}`;
  return d;
}

/**
 * Monotone kubische Interpolation nach Fritsch–Carlson. Kein Überschwingen:
 * zwischen zwei Punkten bleibt die Kurve zwischen deren Werten.
 */
export function monotonePath(pts) {
  const n = pts.length;
  if (n < 3) return linePath(pts);
  const dx = [];
  const m = [];
  for (let i = 0; i < n - 1; i += 1) {
    dx[i] = pts[i + 1][0] - pts[i][0];
    m[i] = dx[i] === 0 ? 0 : (pts[i + 1][1] - pts[i][1]) / dx[i];
  }
  const t = new Array(n);
  t[0] = m[0];
  t[n - 1] = m[n - 2];
  for (let i = 1; i < n - 1; i += 1) t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
  for (let i = 0; i < n - 1; i += 1) {
    if (m[i] === 0) {
      t[i] = 0;
      t[i + 1] = 0;
      continue;
    }
    const a = t[i] / m[i];
    const b = t[i + 1] / m[i];
    const s = a * a + b * b;
    if (s > 9) {
      const tau = 3 / Math.sqrt(s);
      t[i] = tau * a * m[i];
      t[i + 1] = tau * b * m[i];
    }
  }
  let d = `M${f(pts[0][0])},${f(pts[0][1])}`;
  for (let i = 0; i < n - 1; i += 1) {
    const h = dx[i] / 3;
    d += `C${f(pts[i][0] + h)},${f(pts[i][1] + t[i] * h)},${f(pts[i + 1][0] - h)},${f(pts[i + 1][1] - t[i + 1] * h)},${f(pts[i + 1][0])},${f(pts[i + 1][1])}`;
  }
  return d;
}

export function curve(pts, kind) {
  if (kind === 'step') return stepPath(pts);
  if (kind === 'linear' || pts.length > 600) return linePath(pts);
  return monotonePath(pts);
}

/** Nächster Index in sortierten x-Werten (binäre Suche). */
export function nearest(xs, x) {
  let lo = 0;
  let hi = xs.length - 1;
  if (hi < 0) return -1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (xs[mid] < x) lo = mid + 1;
    else hi = mid;
  }
  if (lo > 0 && Math.abs(xs[lo - 1] - x) < Math.abs(xs[lo] - x)) return lo - 1;
  return lo;
}

/** Farbnamen aus den Series-Antworten auf die Daten-Tokens. */
const COLORS = {
  pv: 'solar', sun: 'solar', solar: 'solar', export: 'grid-out', 'grid-out': 'grid-out', net: 'grid-in', 'grid-in': 'grid-in',
  import: 'price', price: 'price', battery: 'battery', house: 'v1', wallbox: 'v2', muted: 'muted', ink: 'ink',
  v1: 'v1', v2: 'v2', v3: 'v3', v4: 'v4', v5: 'v5', accent: 'accent',
};
export const colorClass = (name) => `c-${COLORS[name] || 'ink'}`;

export function unitFromTitle(title) {
  const match = /\(([^)]+)\)/.exec(title || '');
  return match ? match[1] : '';
}
