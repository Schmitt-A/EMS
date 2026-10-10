// Abnahme der Oberfläche im Demo-Modus (Abschnitt 10.3 der Vorgabe).
// Startet einen eigenen Demo-Server und steuert das installierte Google Chrome über playwright-core.
//   npm run check:ui                      alle Seiten
//   CHECK_ROUTES=/,/speicher npm run check:ui
//   CHECK_SHOTS=0 npm run check:ui        ohne Screenshots
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const port = Number(process.env.CHECK_PORT || 8198);
const base = `http://127.0.0.1:${port}`;
const routes = (process.env.CHECK_ROUTES || '/,/speicher,/prognose,/ladevorgaenge,/einstellungen,/einstellungen/speicher,/einstellungen/fahrzeug,/einstellungen/ladepunkt,/einstellungen/energie,/einstellungen/system,/einrichten/speicher,/einrichten/pruefen,/komponenten').split(',').filter(Boolean);
const widths = [360, 390, 768, 1024, 1440, 1920];
const themes = ['light', 'dark'];
const axeWidths = [390, 1440];
const shots = process.env.CHECK_SHOTS !== '0' ? join(root, 'tests/screenshots') : null;
const axeSource = readFileSync(join(root, 'node_modules/axe-core/axe.min.js'), 'utf8');

const data = mkdtempSync(join(tmpdir(), 'ems-check-'));
const env = { ...process.env, EMS_DEMO: '1', EMS_DATA: data, EMS_DEMO_CLOCK: process.env.EMS_DEMO_CLOCK || '12:30' };
const seeded = spawnSync('php', ['bin/demo-seed.php'], { cwd: root, env, encoding: 'utf8' });
if (seeded.status !== 0) {
  console.error(seeded.stderr || seeded.stdout);
  process.exit(1);
}
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'app/public', 'app/public/router.php'], { cwd: root, env, stdio: 'ignore' });
const stop = () => {
  server.kill();
  rmSync(data, { recursive: true, force: true });
};
for (let i = 0; i < 100; i += 1) {
  try {
    if ((await fetch(`${base}/api/health`)).ok) break;
  } catch {
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}

const failures = [];
const fail = (where, message) => failures.push(`${where}: ${message}`);
if (shots) mkdirSync(shots, { recursive: true });

const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
  for (const route of routes) {
    for (const theme of themes) {
      for (const width of widths) {
        const where = `${route} ${width}px ${theme}`;
        const context = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: theme, deviceScaleFactor: 1 });
        const page = await context.newPage();
        page.on('request', (request) => {
          const url = request.url();
          if (!url.startsWith(base) && !url.startsWith('data:') && !url.startsWith('blob:')) fail(where, `fremde Anfrage ${url}`);
        });
        page.on('console', (message) => {
          const text = message.text();
          if (/Content Security Policy|Refused to/i.test(text)) fail(where, `CSP: ${text}`);
          else if (message.type() === 'error') fail(where, `Konsole: ${text}`);
        });
        page.on('pageerror', (error) => fail(where, `JS-Fehler: ${error.message}`));
        const response = await page.goto(base + route, { waitUntil: 'networkidle' });
        if (!response || response.status() !== 200) {
          fail(where, `Status ${response ? response.status() : 'ohne Antwort'}`);
          await context.close();
          continue;
        }
        await page.evaluate(() => document.fonts.ready);
        const report = await page.evaluate(() => {
          const out = { overflow: 0, clipped: [], small: [], font: '', inter: false };
          out.overflow = document.documentElement.scrollWidth - window.innerWidth;
          out.font = getComputedStyle(document.body).fontFamily;
          out.inter = document.fonts.check('16px Inter');
          const visible = (el) => {
            const box = el.getBoundingClientRect();
            const style = getComputedStyle(el);
            return box.width > 0 && box.height > 0 && style.visibility !== 'hidden' && style.display !== 'none' && !el.closest('[hidden], dialog:not([open]), details:not([open]) > :not(summary)');
          };
          for (const el of document.querySelectorAll('.label, .seg-label, .tab-label, .chip, button, .metric-lg, .metric-sm, th')) {
            if (!visible(el) || el.closest('.sr-only, .chart, svg')) continue;
            const style = getComputedStyle(el);
            if (el.scrollWidth > el.clientWidth + 1 && style.overflow !== 'visible') out.clipped.push(el.textContent.trim().slice(0, 40));
          }
          // Beschriftungen der Navigation müssen in ihrer Kachel bleiben.
          for (const label of document.querySelectorAll('.nav-link .tab-label')) {
            if (!visible(label)) continue;
            const inner = label.getBoundingClientRect();
            const outer = label.closest('.nav-link').getBoundingClientRect();
            if (inner.left < outer.left - 0.5 || inner.right > outer.right + 0.5) out.clipped.push(`Navigation: ${label.textContent.trim()}`);
          }
          for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=slider], summary')) {
            if (!visible(el) || el.closest('p, .prose, .sr-only, .skip-link') || el.matches('input[type=radio], input[type=checkbox]')) continue;
            const w = el.offsetWidth || el.getBoundingClientRect().width;
            const h = el.offsetHeight || el.getBoundingClientRect().height;
            if (w < 43.5 || h < 43.5) out.small.push(`${el.tagName.toLowerCase()} „${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 30)}“ ${Math.round(w)}×${Math.round(h)}`);
          }
          return out;
        });
        if (report.overflow > 1) fail(where, `horizontales Scrollen um ${report.overflow}px`);
        if (!report.inter || !/^"?Inter/.test(report.font)) fail(where, `Schrift ${report.font}, Inter geladen: ${report.inter}`);
        for (const text of report.clipped) fail(where, `abgeschnitten: ${text}`);
        for (const text of [...new Set(report.small)].slice(0, 8)) fail(where, `Trefferfläche zu klein: ${text}`);
        if (shots) await page.screenshot({ path: join(shots, `${route.replace(/\W+/g, '_') || 'home'}-${width}-${theme}.png`), fullPage: true });
        await context.close();
      }
    }

    // Reduzierte Bewegung: keine endlosen Animationen.
    const calm = await browser.newContext({ viewport: { width: 390, height: 900 }, reducedMotion: 'reduce' });
    const calmPage = await calm.newPage();
    await calmPage.goto(base + route, { waitUntil: 'networkidle' });
    const endless = await calmPage.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running' && a.effect?.getComputedTiming().iterations === Infinity).length);
    if (endless) fail(`${route} reduziert`, `${endless} endlose Animationen trotz prefers-reduced-motion`);
    await calm.close();

    // axe in einem eigenen Kontext, der die CSP für das eingeschleuste Skript umgeht.
    for (const theme of themes) {
      for (const width of axeWidths) {
        const where = `${route} ${width}px ${theme} axe`;
        const context = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: theme, bypassCSP: true });
        const page = await context.newPage();
        await page.goto(base + route, { waitUntil: 'networkidle' });
        await page.addScriptTag({ content: axeSource });
        const result = await page.evaluate(async () => {
          const run = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
          return run.violations.map((v) => `${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0]?.target?.join(' ')}`);
        });
        for (const line of result) fail(where, line);
        // Jeder Dialog geöffnet: axe nur auf den Dialog.
        const dialogs = await page.evaluate(() => [...new Set([...document.querySelectorAll('[data-open-dialog]')].map((b) => b.dataset.openDialog))]);
        for (const id of dialogs) {
          const found = await page.evaluate(async (dialogId) => {
            const dialog = document.getElementById(dialogId);
            if (!dialog) return null;
            dialog.showModal();
            await new Promise((resolve) => setTimeout(resolve, 400));
            const run = await window.axe.run(dialog, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
            dialog.close();
            return run.violations.map((v) => `${v.id} (${v.impact}, ${v.nodes.length}×): ${v.nodes[0]?.target?.join(' ')}`);
          }, id);
          if (found === null) fail(where, `Dialog #${id} fehlt`);
          for (const line of found || []) fail(`${where} #${id}`, line);
        }
        await context.close();
      }
    }
  }
} finally {
  await browser.close();
  stop();
}

if (failures.length) {
  console.error(`${failures.length} Befunde:`);
  for (const line of failures) console.error(`  ${line}`);
  process.exit(1);
}
console.log(`check:ui ok: ${routes.length} Seiten × ${widths.length} Breiten × hell/dunkel, axe, reduzierte Bewegung${shots ? `, Screenshots in ${shots}` : ''}`);
