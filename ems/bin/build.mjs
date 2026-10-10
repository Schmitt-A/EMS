// Baut alles, was die Oberfläche zur Laufzeit braucht, nach app/public/assets/build/.
// Zur Laufzeit lädt die Seite nur diese Dateien: CSS, JS-Bündel, Icon-Sprite und Inter.
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import browserslist from 'browserslist';
import { build } from 'esbuild';
import { browserslistToTargets, bundle } from 'lightningcss';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'app/public/assets/build');
const targets = 'safari >= 15.4, ios_saf >= 15.4, chrome >= 105, edge >= 105, firefox >= 115';

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

const hash = (content) => createHash('sha256').update(content).digest('hex').slice(0, 10);
const manifest = {};

// Schrift: Latein-Teilmenge des offiziellen InterVariable.woff2 mit allen Features (siehe bin/subset-font.sh).
const font = readFileSync(join(root, 'app/assets/fonts/InterVariable.woff2'));
writeFileSync(join(out, 'InterVariable.woff2'), font);
manifest['InterVariable.woff2'] = hash(font);

// Icon-Sprite: Lucide-Linien mit 1,75 px Strich, dazu die eigene Batterie (gefüllte Zelle).
const lucide = [
  'activity', 'arrow-down', 'arrow-left', 'arrow-right', 'arrow-up', 'battery-charging', 'battery-full', 'battery-low', 'battery-medium', 'battery-plus',
  'bell', 'calendar', 'car', 'chart-column', 'chart-line', 'check', 'chevron-down', 'chevron-left', 'chevron-right',
  'circle-alert', 'circle-check', 'cloud-sun', 'coins', 'compass', 'ellipsis', 'gauge', 'hand-coins', 'history',
  'house', 'info', 'leaf', 'list', 'monitor', 'moon', 'pencil', 'plug', 'plus', 'rotate-ccw', 'route', 'scale',
  'power', 'search', 'send', 'server', 'settings', 'shield', 'sliders-horizontal', 'smartphone', 'sun', 'sun-medium', 'target',
  'timer', 'trash-2', 'triangle-alert', 'unplug', 'utility-pole', 'wifi-off', 'x', 'zap',
];
const symbol = (id, file) => {
  const source = readFileSync(file, 'utf8').replace(/<!--[\s\S]*?-->/g, '');
  const open = source.match(/<svg\b([^>]*)>/);
  if (!open) throw new Error(`Kein <svg> in ${file}`);
  const viewBox = (open[1].match(/viewBox="([^"]+)"/) || [null, '0 0 24 24'])[1];
  const inner = source.slice(open.index + open[0].length, source.lastIndexOf('</svg>')).trim().replace(/\s+/g, ' ');
  return `<symbol id="${id}" viewBox="${viewBox}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
};
const symbols = lucide.map((name) => {
  const file = join(root, 'node_modules/lucide-static/icons', `${name}.svg`);
  if (!existsSync(file)) throw new Error(`Icon fehlt: ${name}`);
  return symbol(name, file);
});
symbols.push(symbol('battery', join(root, 'app/assets/icons/battery.svg')));
const sprite = `<svg xmlns="http://www.w3.org/2000/svg">${symbols.join('')}</svg>\n`;
writeFileSync(join(out, 'icons.svg'), sprite);
manifest['icons.svg'] = hash(sprite);

// Favicon: Blitz auf Amber, lokal statt aus einem CDN.
const favicon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="9" fill="#D97706"/>'
  + '<path transform="translate(4 4)" fill="#1C1917" d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>\n';
writeFileSync(join(out, 'favicon.svg'), favicon);
manifest['favicon.svg'] = hash(favicon);

// CSS: @import-Kette bündeln, Nesting für ältere WebViews absenken, minifizieren.
const css = bundle({
  filename: join(root, 'app/assets/css/app.css'),
  minify: true,
  targets: browserslistToTargets(browserslist(targets)),
});
const cssCode = css.code.toString().replaceAll('InterVariable.woff2', `InterVariable.woff2?v=${manifest['InterVariable.woff2']}`);
writeFileSync(join(out, 'app.css'), cssCode);
manifest['app.css'] = hash(cssCode);

// JS: ES-Module zu einem Modul-Bündel.
const js = await build({
  entryPoints: [join(root, 'app/assets/js/main.js')],
  bundle: true,
  format: 'esm',
  minify: true,
  target: ['es2020', 'safari15'],
  write: false,
  legalComments: 'none',
});
const jsCode = js.outputFiles[0].text;
writeFileSync(join(out, 'app.js'), jsCode);
manifest['app.js'] = hash(jsCode);

writeFileSync(join(out, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`build: css ${(cssCode.length / 1024).toFixed(1)} KB, js ${(jsCode.length / 1024).toFixed(1)} KB, ${symbols.length} Icons`);
