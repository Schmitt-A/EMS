// Abnahme der Oberfläche im Demo-Modus (Abschnitt 10.3 der Vorgabe).
// Startet einen eigenen Demo-Server und steuert das installierte Google Chrome über playwright-core.
//   npm run check:ui                      alle Seiten
//   CHECK_ROUTES=/,/speicher npm run check:ui
//   CHECK_SHOTS=0 npm run check:ui        ohne Screenshots
//   CHECK_TARIFF=dynamic npm run check:ui mit dynamischem Tarif (Ladefenster, Fensterwahl)
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const port = Number(process.env.CHECK_PORT || 8198);
const base = `http://127.0.0.1:${port}`;
const routes = (process.env.CHECK_ROUTES || '/,/speicher,/prognose,/prognose/strompreis,/ladevorgaenge,/statistik,/einstellungen,/einstellungen/tarif,/einstellungen/speicher,/einstellungen/fahrzeug,/einstellungen/ladepunkt,/einstellungen/energie,/einstellungen/mitteilungen,/einstellungen/darstellung,/einstellungen/system,/einrichten/speicher,/einrichten/pruefen,/komponenten').split(',').filter(Boolean);
const widths = [360, 390, 768, 1024, 1440, 1920];
const themes = ['light', 'dark'];
const axeWidths = [390, 1440];
const shots = process.env.CHECK_SHOTS !== '0' ? join(root, 'tests/screenshots') : null;
// Überstand auf schmalen Handys, auch mit größerer Schrift (Zoom in der App von Home Assistant, Systemschrift): Seite und
// jedes Sheet. html und body schneiden seitlich ab (overflow-x: clip), darum misst die Prüfung die Elemente selbst.
const narrowWidths = [320, 360, 390];
const zooms = [100, 115];

// Elemente, die über den rechten oder linken Rand ragen, ohne dass ein Vorfahr sie abschneidet oder scrollt; mit
// offenem Dialog an dessen Rand. Unsichtbare Tooltips zählen nicht.
function overhang() {
  const open = document.querySelector('dialog[open]');
  const stop = open || null;
  const right = open ? Math.min(window.innerWidth, open.getBoundingClientRect().right) : window.innerWidth;
  const left = open ? Math.max(0, open.getBoundingClientRect().left) : 0;
  const contained = (el) => {
    for (let p = el.parentElement; p && p !== stop && p !== document.body; p = p.parentElement) {
      if (getComputedStyle(p).overflowX !== 'visible') return true;
    }
    return false;
  };
  const out = [];
  for (const el of (open || document.body).querySelectorAll('*')) {
    if ((el instanceof SVGElement && el.tagName !== 'svg') || !el.getClientRects().length || el.closest('.sr-only, .tip-body, dialog:not([open])')) continue;
    if (getComputedStyle(el).visibility === 'hidden') continue;
    const box = el.getBoundingClientRect();
    if (box.width === 0 || (box.right <= right + 0.5 && box.left >= left - 0.5) || contained(el)) continue;
    out.push(`${el.tagName.toLowerCase()}${typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : ''} ${Math.round(box.left)}…${Math.round(box.right)}`);
  }
  const body = open?.querySelector('.dialog-body');
  if (body && body.scrollWidth > body.clientWidth + 1) out.push(`Sheet scrollt seitlich um ${body.scrollWidth - body.clientWidth}px`);
  return [...new Set(out)].slice(0, 4);
}
const axeSource = readFileSync(join(root, 'node_modules/axe-core/axe.min.js'), 'utf8');

const data = mkdtempSync(join(tmpdir(), 'ems-check-'));
const env = { ...process.env, EMS_DEMO: '1', EMS_DATA: data, EMS_DEMO_CLOCK: process.env.EMS_DEMO_CLOCK || '12:30' };
const seeded = spawnSync('php', ['bin/demo-seed.php'], { cwd: root, env, encoding: 'utf8' });
if (seeded.status !== 0) {
  console.error(seeded.stderr || seeded.stdout);
  process.exit(1);
}
// CHECK_TARIFF=dynamic: dieselbe Demo mit dynamischem Tarif und Netzladen „In Ladefenstern“, damit Ladefenster,
// Fensterwahl und Preisbereich geprüft werden; mit statischem Tarif gibt es sie nicht.
if (process.env.CHECK_TARIFF === 'dynamic') {
  const php = "require 'app/src/bootstrap.php'; $t = new Tariff(store()); $c = $t->tariffAt(time()); $c['mode'] = 'dynamic'; "
    + "$t->saveHistory(Tariff::withEntry($t->history(), date('Y-m-d'), $c), time()); store()->merge('charge', ['grid' => 'windows']); "
    + "$w = new PriceWindows(store()); $w->save(['range_to' => 25.0] + $w->config(), time());";
  const switched = spawnSync('php', ['-r', php], { cwd: root, env, encoding: 'utf8' });
  if (switched.status !== 0) {
    console.error(switched.stderr || switched.stdout);
    process.exit(1);
  }
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
        for (const text of await page.evaluate(overhang)) fail(where, `ragt über den Rand: ${text}`);
        if (!report.inter || !/^"?Inter/.test(report.font)) fail(where, `Schrift ${report.font}, Inter geladen: ${report.inter}`);
        for (const text of report.clipped) fail(where, `abgeschnitten: ${text}`);
        for (const text of [...new Set(report.small)].slice(0, 8)) fail(where, `Trefferfläche zu klein: ${text}`);
        if (shots) await page.screenshot({ path: join(shots, `${route.replace(/\W+/g, '_') || 'home'}-${width}-${theme}.png`), fullPage: true });
        await context.close();
      }
    }

    // Schmale Handys mit größerer Schrift: Seite und jedes Sheet ohne Überstand.
    for (const width of narrowWidths) {
      for (const zoom of zooms) {
        const where = `${route} ${width}px ${zoom}%`;
        const context = await browser.newContext({ viewport: { width, height: 800 }, isMobile: true, hasTouch: true, bypassCSP: true });
        const page = await context.newPage();
        await page.goto(base + route, { waitUntil: 'networkidle' });
        await page.evaluate((z) => document.documentElement.style.setProperty('font-size', `${z}%`), zoom);
        for (const text of await page.evaluate(overhang)) fail(where, `ragt über den Rand: ${text}`);
        const dialogs = await page.evaluate(() => [...new Set([...document.querySelectorAll('[data-open-dialog]')].map((b) => b.dataset.openDialog))]);
        for (const id of dialogs) {
          if (!(await page.evaluate((dialogId) => { const d = document.getElementById(dialogId); d?.showModal(); return Boolean(d); }, id))) continue;
          for (const text of await page.evaluate(overhang)) fail(`${where} #${id}`, `ragt über den Rand: ${text}`);
          await page.evaluate((dialogId) => document.getElementById(dialogId).close(), id);
        }
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
console.log(`check:ui ok: ${routes.length} Seiten × ${widths.length} Breiten × hell/dunkel, axe, reduzierte Bewegung, Überstand auf ${narrowWidths.join('/')} px mit ${zooms.join('/')} % Schrift samt Sheets${shots ? `, Screenshots in ${shots}` : ''}`);
