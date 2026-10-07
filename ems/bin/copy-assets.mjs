import { copyFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const fonts = join(root, 'app/public/assets/fonts');
const js = join(root, 'app/public/assets/js');
const icons = join(root, 'app/assets/icons');
mkdirSync(fonts, { recursive: true });
mkdirSync(js, { recursive: true });
mkdirSync(icons, { recursive: true });

for (const weight of [400, 500, 600]) {
  const name = `inter-latin-${weight}-normal.woff2`;
  copyFileSync(join(root, 'node_modules/@fontsource/inter/files', name), join(fonts, name));
}

const chart = join(root, 'node_modules/chart.js/dist/chart.umd.js');
copyFileSync(chart, join(js, 'chart.js'));

const names = [
  'sun', 'battery', 'battery-charging', 'house', 'utility-pole', 'car', 'zap',
  'settings', 'chart-column', 'chart-line', 'arrow-down', 'arrow-up',
  'circle-check', 'circle-alert', 'search', 'cloud-sun', 'gauge', 'timer',
  'sliders-horizontal', 'info', 'chevron-right', 'activity', 'compass',
  'plug', 'calendar', 'scale', 'triangle-alert', 'sun-medium',
];
for (const name of names) {
  const src = join(root, 'node_modules/lucide-static/icons', `${name}.svg`);
  if (!existsSync(src)) throw new Error(`Icon fehlt: ${name}`);
  copyFileSync(src, join(icons, `${name}.svg`));
}
console.log('assets copied');
