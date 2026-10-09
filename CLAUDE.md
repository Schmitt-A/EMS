# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Layout

The git root is this folder. The Home Assistant add-on lives in `ems/` (manifest `ems/config.yaml`), and the root `repository.yaml` lets the HA add-on store find it. Commands run from the repo root unless noted. Paths without a prefix are relative to `ems/`.

## Commands

Build the front end. This is required on a fresh clone: `bin/build.mjs` writes everything the pages load to `app/public/assets/build/` (gitignored): `app.css` (lightningcss bundle of `app/assets/css/app.css`), `app.js` (esbuild bundle of `app/assets/js/main.js`), the icon sprite `icons.svg`, `InterVariable.woff2`, `favicon.svg` and `manifest.json` with content hashes for `asset()`.

```sh
cd ems && npm install && npm run build
```

Run locally. The PHP app never reads `.env` (only `docker compose` does), so pass the variables inline or export them. Alternatively, enter URL and token in the setup wizard, which writes `data/connection.json`.

```sh
EMS_DATA=./data HA_URL=http://<ha-host>:8123 HA_TOKEN=<token> php -S 127.0.0.1:8099 -t ems/app/public ems/app/public/router.php
EMS_DATA=./data php ems/bin/recorder.php   # second process: sessions, forecast memory, calibration
```

Demo mode needs no Home Assistant. `DemoModel` simulates a plant from `app/demo/beispieldaten.json` (regenerate with `npm run demo:data`), `DemoHaClient` answers in place of `HaClient`, and `/api/live` advances the running session because no recorder runs. `EMS_DEMO_CLOCK=HH:MM` freezes the live clock. `/komponenten` (component gallery) only exists in demo mode.

```sh
EMS_DEMO=1 EMS_DATA=/tmp/ems-demo php ems/bin/demo-seed.php   # fresh demo database, never /data
EMS_DEMO=1 EMS_DATA=/tmp/ems-demo php -S 127.0.0.1:8099 -t ems/app/public ems/app/public/router.php
```

Docker runs php-fpm, nginx and the recorder in one container on port 8099 (`.env` via compose, `./data` mounted at `/data`):

```sh
docker compose up --build
```

Checks:
- `php ems/bin/selftest.php` is a hand-written script of `check(bool, 'German message')` calls. It exits 1 at the first failing check, so single cases cannot be selected. It covers pure logic in `Energy`, `Forecast`, `WeatherFeed`, `Series`, `Sessions` and the helpers, not HTTP routes, HA calls or JavaScript. Two extra checks run only if `/tmp/MOSMIX_L_LATEST_F9519.kmz` exists.
- `cd ems && npm run check:contrast` checks every token color pair in light and dark against WCAG AA.
- `cd ems && npm run check:ui` seeds its own demo database, starts a PHP server on port 8198 and drives the installed Google Chrome through playwright-core. Per page it checks 360 to 1920 px in light and dark: no horizontal scroll, no clipped labels, hit areas of at least 44 px, Inter loaded, no foreign requests, no CSP or console errors, axe `wcag22aa` on the page and on every dialog, no endless animation with reduced motion. Screenshots go to `ems/tests/screenshots/`. `CHECK_ROUTES=/,/speicher` limits the pages, `CHECK_SHOTS=0` skips screenshots.

No linter is configured; use `php -l <file>` for syntax.

Debugging: `EMS_DEBUG=1` shows PHP errors. Recorder exceptions go to `$EMS_DATA/recorder.log`, not the console.

Release: bump `version` in `ems/config.yaml`. The Supervisor compares that number to offer updates.

## Architecture

The app reads a PV system, a home battery, a grid meter and a go-e wallbox through Home Assistant entities the user maps in the setup wizard. It computes the energy flow, a charging suggestion and a PV forecast itself. It currently writes nothing to the wallbox (see README).

**Two processes share one SQLite file** (`$EMS_DATA/ems.sqlite`, WAL mode):
- **Web:** `app/public/index.php` is a single front controller with an if-chain router and no framework. `page()`/`render()` render `app/views/<name>.php` into `app/views/shell.php`. JSON endpoints: `/api/live` (live payload), `/api/series?chart=…` (data for the SVG charts), `/api/entities` (entity search), `/api/config.json` (export), and the writes `/api/modus`, `/api/limit`, `/api/darstellung`, `/api/grenzen` (CSRF token as `_csrf` in the JSON body; `ingest_json_body()` maps it to `$_POST`).
- **Recorder:** `bin/recorder.php` loops every 10 s: `WeatherFeed::refresh()`, `Snapshot::build()`, `Sessions::tick()`, and `Series::calibrate()` once a day from 01:00. In Docker, `docker/start.sh` runs both as root so the shared SQLite file works.

**Pages:** five tabs, `/` (Laden), `/speicher`, `/prognose`, `/ladevorgaenge` and `/mehr/{bereich}` (settings in seven areas, one view each in `app/views/mehr/`), plus the wizard `/einrichten/{schritt}`. Second-level content opens in native `<dialog>` sheets. The old routes `/laden`, `/batterie`, `/statistik` and `/einstellungen` redirect. Forms post to `/mehr` with `section` and `back`; `Actions::saveSettings()` only changes the fields a form sends.

**Storage:** `ConfigStore` wraps the database. Table `kv` holds settings as JSON (`mapping`, `plant`, `charge`, `battery_strategy`, `tariffs` incl. `co2_g_kwh`, `vehicle`, `chargepoint`, `weather`, `ui`, `wizard_done`) plus operational keys such as `suggestion_latch`, `house_mean` and `vehicle_repair`. Defaults are in `ConfigStore::defaults()`; read them through `cfg()`. Other tables: `sessions` (charging sessions), `daily` (actual vs. model kWh per day, `model_mode`), `forecast_days`, `forecast_issues` (every DWD run is kept) and `weather_hours`. Also in `$EMS_DATA`: `connection.json` (URL and token, mode 0600) and `states-cache.json` (3 s cache of all HA states).

**Setup gate:** until `wizard_done` is set, every page redirects to `/einrichten/…`, shown without navigation. `/api/*` answers regardless.

**Home Assistant:** `ha()` returns a `HaSource`: `HaClient`, or `DemoHaClient` in demo mode. `connection()` in `helpers.php` uses the Supervisor proxy when `SUPERVISOR_TOKEN` is set (`http://supervisor/core`). Otherwise `HA_URL`/`HA_TOKEN` apply, and `connection.json` fills what is missing. `HaClient` uses REST for states and history. Statistics go through a hand-written WebSocket client (`recorder/statistics_during_period`), since REST does not expose them. `Actions::SUGGEST` holds default entity IDs from the author's installation (sonnenbatterie and go-e). They are only suggestions.

**Energy and charging** (`Snapshot::build()` calls into `Energy`):
1. Mapped entities are read and converted to kW/kWh. A missing unit is read as W, with a warning.
2. `Energy::balance()` gives the house base load (minus the wallbox if the house meter includes it) and the inflows and outflows. Surplus = PV − house − battery charge, where the battery term applies only while SOC is below the priority threshold. `Energy::flowBar()` turns the values into the segments of the flow bar.
3. `Energy::suggest()` picks a target for the modes `aus`, `smart`, `smart_dauerhaft` and `schnell` (shown as Aus, Solar, Min+Solar, Schnell): 0.23 kW per A, 6–16 A, 1 or 3 phases.
4. `Energy::latch()` delays changes (at least 60 s for on/off/phase switches) and stores its state in `kv.suggestion_latch`.

The result is shown as "Vorschlag". `Sessions::tick()` (recorder only, except in demo mode) opens a session while wallbox power is above 0.2 kW or the car reports `charging`. It closes the session after `off_delay_s` of idle time, splits energy into solar and grid by grid import, and stores the vehicle and charge point names. `Sessions::summary()`, `cost()` and `co2()` feed the Ladevorgänge page and the energy overview. Past sessions can be imported once from `sensor.ems_ladelog_historie`, which this repo does not define.

**PV forecast:** `WeatherFeed` downloads the DWD MOSMIX KMZ for station F9519, refetching every 30 min, and on the next recorder tick after a failure. `Rad1h` is kJ/m² per hour; divide by 3.6 for W/m². `Forecast::powerKw()` computes kWp × constants × factor, capped at the inverter. `Series::calibrate()` (also called from `Series::days()`) sets `plant.factor` to the mean of actual/model, reset to 0.93 with fewer than 5 days. From 5 days on it also fits a regression `a + b·model`. The `*_locked` flags keep manual values. `Forecast::storageOutlook()` estimates when the battery reaches full, priority and buffer SOC, using the 30-day house mean.

**Front end:**
- Plain CSS in `app/assets/css/` (`tokens.css` holds all colors, type, radii, spacing and motion as custom properties with `light-dark()`; `components/*.css` one file per component). Mobile first from 360 px; larger layouts only via `min-width` and container queries.
- ES modules in `app/assets/js/` (`core/` for DOM, formatting, dialogs and live polling, `components/`, and `charts/` for the hand-written SVG charts).
- PHP components are the `ui_*` functions in `app/views/kit.php`. They return HTML strings and escape through `e()`.
- Theme is server-side: `ui.theme` sets `data-theme` on `<html>`.

## Git and GitHub

- `origin` is `git@github.com:Schmitt-A/EMS.git`. SSH authentication to GitHub works, so `git push` needs no extra login. Pull requests with `gh` need `gh auth login` first.
- The add-on store installs from the default branch `main`, so changes to `main` reach users. Work on a branch unless told otherwise.

## Conventions and gotchas

- UI text, code comments and commit messages are German; identifiers are English. Commit messages are full German sentences with no type prefix.
- Format numbers with `num()`, `kw()`, `kwh()`, `pct()`, `ct()` or `euro()`: decimal comma and a narrow no-break space (`NNBSP`, U+202F) before the unit. `kw()` shows one decimal; the control details show two. Input fields use `ui_field_num()`, which has no thousands separator, because `post_float()` would read `1.500` as 1.5.
- Every link goes through `url()`, which adds the HA Ingress prefix from `X-Ingress-Path`. JavaScript reads it from `data-base` on `<html>` (`base()` in `core/dom.js`).
- CSP is `default-src 'self'` plus a hash for one inline style. No inline scripts, no `style` attributes, no external resources. Proportions travel as `data-*` attributes and JavaScript sets them through CSSOM (`el.style.setProperty`).
- The view transition opt-in (`VIEW_TRANSITION_CSS` in `helpers.php`) sits inline in the head of `shell.php`, because Chrome reads it when `<body>` is inserted, often before `app.css` has loaded. Any output before `<!doctype html>`, such as a PHP warning, puts the page into quirks mode and aborts the transition with "ViewTransition opt-in disabled".
- Every POST handler calls `csrf_check()`, and every POST form includes `csrf_field()`.
- A new page needs a view, a branch in `index.php`, and an entry in `$nav` in `shell.php` (with a short label if the name does not fit 72 px at 360 px).
- A new setting needs a default in `ConfigStore::defaults()` and handling in `Actions::saveSettings()`. Add it to `portable()`/`applyPortable()` if it should survive JSON export and import.
- `icon('x')` references `#x` in the sprite. New Lucide names go into the list in `bin/build.mjs`, followed by a rebuild. `app/assets/icons/battery.svg` is hand-drawn.
- Live values: `core/live.js` polls `/api/live` every 5 s only if the page has `[data-live]`, `[data-live-num]` or a live component. `data-live` takes a dot path into the payload (`battery.soc_text`); a path that does not exist is silently skipped.
- Changing `$version` in `Series::calibrate()` forces one recalibration.
- Every page load and every `/api/live` poll runs `Snapshot::build()`. That writes `suggestion_latch`, forecast rows and the house-mean cache, and can download the DWD file. Reads have side effects.
- `Series::repairKnownDays()` is a one-off repair with hard-coded dates in 2026-10, guarded by `kv.model_repair`. It is the only code that sets `model_mode` to `pin` or `drop`.
- The plant and tariff defaults in `ConfigStore::defaults()` (10.03 kWp, azimuth 270, tilt 13, 10 kW inverter, 34.7 / 11.0 ct) describe the author's system.
- `.env` and `data/` are gitignored. Never commit the token.
