// Prüft die Kontraste der Tokens in Hell und Dunkel (Abschnitt 3.2 und 10.3 der Vorgabe).
// Text braucht 4,5:1, große Zahlen und Grafikelemente 3:1.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const css = readFileSync(join(root, 'app/assets/css/tokens.css'), 'utf8');

const split = (args) => {
  let depth = 0;
  for (let i = 0; i < args.length; i += 1) {
    if (args[i] === '(') depth += 1;
    if (args[i] === ')') depth -= 1;
    if (args[i] === ',' && depth === 0) return [args.slice(0, i).trim(), args.slice(i + 1).trim()];
  }
  throw new Error(`light-dark ohne zwei Werte: ${args}`);
};

const tokens = {};
for (const match of css.matchAll(/--([a-z0-9-]+):\s*light-dark\((.+)\);/g)) {
  const [light, dark] = split(match[2]);
  tokens[match[1]] = { light, dark };
}

const parse = (value) => {
  const v = value.trim();
  if (v === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
  let m = v.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (m) {
    const hex = m[1].length === 3 ? m[1].split('').map((c) => c + c).join('') : m[1];
    return { r: parseInt(hex.slice(0, 2), 16), g: parseInt(hex.slice(2, 4), 16), b: parseInt(hex.slice(4, 6), 16), a: 1 };
  }
  m = v.match(/^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*(?:\/\s*([\d.]+))?\s*\)$/);
  if (m) return { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] };
  throw new Error(`Farbe nicht lesbar: ${value}`);
};

const over = (fg, bg) => ({
  r: fg.r * fg.a + bg.r * (1 - fg.a),
  g: fg.g * fg.a + bg.g * (1 - fg.a),
  b: fg.b * fg.a + bg.b * (1 - fg.a),
  a: 1,
});

const luminance = ({ r, g, b }) => {
  const lin = (c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
};

const ratio = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

// Hintergrund als Token oder als [halbtransparenter Token, Untergrund].
const color = (spec, mode) => {
  if (Array.isArray(spec)) return over(color(spec[0], mode), color(spec[1], mode));
  const token = tokens[spec];
  if (!token) throw new Error(`Token fehlt: --${spec}`);
  const c = parse(token[mode]);
  return c.a < 1 ? over(c, parse(tokens.surface[mode])) : c;
};

const text = 4.5;
const graphic = 3;
const pairs = [
  ['text', 'bg', text], ['text', 'surface', text], ['text', 'surface-2', text],
  ['text-2', 'bg', text], ['text-2', 'surface', text], ['text-2', 'surface-2', text],
  ['on-ink', 'ink', text], ['on-accent', 'accent', text],
  ['accent-strong', 'bg', text], ['accent-strong', 'surface', text],
  ['accent-on-soft', ['accent-soft', 'surface'], text], ['accent-on-soft', ['accent-soft', 'bg'], text],
  ['success', 'surface', text], ['success', 'bg', text], ['danger', 'surface', text], ['danger', 'bg', text],
  ['text', ['danger-soft', 'surface'], text], ['danger', ['danger-soft', 'surface'], graphic], ['info', 'surface', text],
  ['on-accent', 'data-solar', text], ['on-ink', 'data-grid-in', text], ['on-accent', 'data-grid-out', text], ['on-ink', 'data-battery', text],
  ...['data-solar', 'data-grid-in', 'data-grid-out', 'data-battery', 'data-price', 'data-vehicle-1', 'data-vehicle-2', 'data-vehicle-3', 'data-vehicle-4', 'data-vehicle-5']
    .map((key) => [key, 'surface', graphic]),
  ['accent', 'surface', graphic], ['ink', 'surface-2', graphic],
  ['border-control', 'surface', graphic], ['border-control', 'bg', graphic], ['accent-strong', 'bg', graphic],
];

let failed = 0;
for (const mode of ['light', 'dark']) {
  console.log(mode === 'light' ? 'Hell' : 'Dunkel');
  for (const [fg, bg, min] of pairs) {
    const value = ratio(color(fg, mode), color(bg, mode));
    const ok = value + 1e-9 >= min;
    if (!ok) failed += 1;
    const name = Array.isArray(bg) ? `${bg[0]} über ${bg[1]}` : bg;
    console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${value.toFixed(2).padStart(5)}:1  --${fg} auf --${name} (min ${min})`);
  }
}
if (failed) {
  console.error(`${failed} Kontraste zu schwach.`);
  process.exit(1);
}
console.log('check:contrast ok');
