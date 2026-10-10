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

The app reads a PV system, a home battery, a grid meter and a go-e wallbox through Home Assistant entities the user maps in the setup wizard. It computes the energy flow, a charging suggestion and a PV forecast itself. With the master switch (`cfg.control.active`, default off) it controls the go-e like evcc (`Controller`, below) and the battery's backup buffer (`Reserve`). With the switch off it writes nothing, except the backup-buffer default when the user saves it. Push notifications (`Notify`, below) are independent of the switch.

**Two processes share one SQLite file** (`$EMS_DATA/ems.sqlite`, WAL mode):
- **Web:** `app/public/index.php` is a single front controller with an if-chain router and no framework. `page()`/`render()` render `app/views/<name>.php` into `app/views/shell.php`. JSON endpoints: `/api/live` (live payload), `/api/series?chart=…` (data for the SVG charts), `/api/entities` (entity search), `/api/config.json` (export), and the writes `/api/modus`, `/api/limit`, `/api/darstellung`, `/api/grenzen` and `/api/mitteilung` (test notification; CSRF token as `_csrf` in the JSON body; `ingest_json_body()` maps it to `$_POST`).
- **Recorder:** `bin/recorder.php` loops every 10 s: `WeatherFeed::refresh()`, `Snapshot::build()`, `Sessions::tick()`, `Controller::tick()`, `Reserve::sync()`, `Notify::tick()` (own try/catch), and `Series::calibrate()` once a day from 01:00. In Docker, `docker/start.sh` runs both as root so the shared SQLite file works.

**Pages:** five tabs, `/` (Laden), `/speicher`, `/prognose`, `/ladevorgaenge` and `/einstellungen/{bereich}` (gear icon, short label „Optionen“; the master switch card `partials/ems-switch.php` on top, then nine areas, one view each in `app/views/einstellungen/`), plus the wizard `/einrichten/{schritt}`. Second-level content opens in native `<dialog>` sheets. The old routes `/laden`, `/batterie`, `/statistik` and `/mehr/*` redirect. Forms post to `/einstellungen` with `section` and `back` (`/mehr` still accepts posts from old pages); `Actions::saveSettings()` only changes the fields a form sends; with `Accept: application/json` (`wants_json()`) the notify sections answer JSON instead of redirecting.

**Storage:** `ConfigStore` wraps the database. Table `kv` holds settings as JSON (`mapping`, `plant`, `charge`, `battery_strategy`, `tariffs` incl. `co2_g_kwh`, `vehicle`, `chargepoint`, `weather`, `ui`, `wizard_done`) plus `control` (master switch) and operational keys such as `control_state`, `control_kick`, `control_heartbeat`, `charge_target`, `charge_target_done`, `reserve_guard`, `notify_state`, `notify_slug`, `house_mean` and `vehicle_repair`; `notify` holds the notification settings. Defaults are in `ConfigStore::defaults()`; read them through `cfg()`. Other tables: `sessions` (charging sessions), `daily` (actual vs. model kWh per day, `model_mode`), `forecast_days`, `forecast_issues` (every DWD run is kept), `weather_hours` and `notifications` (the last 200 sent). Also in `$EMS_DATA`: `connection.json` (URL and token, mode 0600) and `states-cache.json` (3 s cache of all HA states).

**Setup gate:** until `wizard_done` is set, every page redirects to `/einrichten/…`, shown without navigation. `/api/*` answers regardless.

**Home Assistant:** `ha()` returns a `HaSource`: `HaClient`, or `DemoHaClient` in demo mode. `connection()` in `helpers.php` uses the Supervisor proxy when `SUPERVISOR_TOKEN` is set (`http://supervisor/core`). Otherwise `HA_URL`/`HA_TOKEN` apply, and `connection.json` fills what is missing. `HaClient` uses REST for states and history; its writes are `setNumber()` and `service()`, limited to `number`/`input_number.set_value`, `select`/`input_select.select_option` and `button.press`, plus `notify()` for `notify.<service>` (`notifyServices()` lists them from `/api/services`). Statistics go through a hand-written WebSocket client (`recorder/statistics_during_period`), since REST does not expose them. `Actions::SUGGEST` holds default entity IDs from the author's installation (sonnenbatterie and go-e). They are only suggestions.

**Energy and charging** (`Snapshot::build()` calls into `Energy`):
1. Mapped entities are read and converted to kW/kWh. A missing unit is read as W, with a warning.
2. `Energy::balance()` gives the house base load (minus the wallbox if the house meter includes it) and the inflows and outflows. Surplus = PV − house − battery charge, where the battery term applies only while SOC is below the priority threshold. `Energy::flowBar()` turns the values into the bar segments and the bracket spans; sources and sinks are each scaled to their own total, so both bracket rows always span the full bar even when the meters disagree. `Energy::flowGraph()` feeds the alternative "Energie-Flow" (`ui_energy_flow()`, `components/energy-flow.js`, payload `flow_graph`), laid out Rein → Raus: sources sun, battery and grid on the left, sinks house, car, battery and export on the right, one link per source and sink (`sun_car`, `grid_home`, …). Sun goes first to consumption, then the battery, the rest to the grid; the battery and then the grid cover the rest, split between house and car by their share. The sun node carries today's forecast progress (`Snapshot::flowForecast()`). `Snapshot::flowGraph()` builds it for Laden, `/api/live` and the live preview under Einstellungen → Darstellung and adds the second line under each name: battery `to_full_kwh` (`Energy::toFull()`), house `mean_kw` (30-day mean), grid and export `price_ct` (tariffs), car `range_km`. The script measures the circles and draws the links into an SVG overlay, so the layout is plain CSS. Which one Laden shows is `ui.flow_view` (`bar` or `graph`). Both sit outside a card; `ui_flow_table()` puts Rein and Raus in a card below.
3. `Energy::suggest()` picks a target for the modes `aus`, `smart`, `smart_dauerhaft` and `schnell` (shown as Aus, Nur Solar, Min+Solar, Netzladen): 0.23 kW per A, 6–16 A, 1 or 3 phases. It follows the battery zones of `Energy::zone()` from `battery_strategy`: below `priority_soc` the battery charges first (`house`), above it the car gets the surplus (`car`), from `car_buffer_soc` the battery may hold a running charge (`boost`), from `car_auto_soc` charging starts without sun (`start`). A limit of 100 % switches `boost` and `start` off. `Energy::allot()` splits the result physically: sun to the car first, the rest of the surplus to the battery (capped at its current charge while exporting) and the grid. Whatever the car still lacks comes from the battery down to the backup buffer (`battery_strategy.backup_soc`), capped by `discharge_kw` minus what the house already draws, and then from the grid. With an unknown `discharge_kw`, Netzladen shows it as battery-then-grid (`mixed`). Netzladen with `grid_protect` takes it from the grid only (`Energy::batteryFor()`).
4. `Controller` turns that into the wallbox state like evcc (`core/loadpoint.go`): `step()` is pure and keeps timers on conditions (enable after `on_delay_s`, 60 s; disable after `off_delay_s`, 180 s, holding the minimum current meanwhile; 1p→3p after the enable delay, 3p→1p after the disable delay; `switch_s` as minimum gap between switches), truncates to whole amps, locks the charger without a car and lets PV start at once after plugging in. `aus` and `schnell` act immediately. `tick()` (recorder every 10 s, a step every 30 s or after `control_kick`) also runs `Target::check()`. `apply()` writes only differences and only when the switch is on: `frc` on/off, `amp`, `psm` 1p/3p, ordered so power never overshoots. Select values are handled by meaning (`Controller::OPTIONS`, `meaning()`, `option()`): the write takes the matching option from the entity's `options` attribute, so both the syssi MQTT integration (`charge`/`dont_charge`/`neutral`, `one_phase`/`three_phases`, the author's setup) and marq24's API integration (`2`/`1`/`0`, `1`/`2`) work. A reported value that differs from the written one after 60 s counts as a foreign write; three in ten minutes pause control (`paused`). Turning the switch off writes `frc` 0. `Snapshot` reads the controller state; without a fresh step it computes a preview step without saving.

`Snapshot::control()` turns the controller decision (with countdown) and the suggestions of all three modes into the "Regelung" card under the charge point (`ui_control()`, `components/control.js`, live payload key `control`). The modes and the calculation sit in a `<details>` that starts closed.

`Reserve` owns the backup buffer (`mapping.battery_reserve`, a number entity). Saving under Einstellungen → Speicher writes the default (`backup_soc`). `Reserve::sync()` runs in the recorder, after `/api/modus` and in demo mode from `/api/live`; it only raises while the master switch is on. With `grid_protect` it raises the buffer to the current SOC while the car charges in `schnell`, keeps it raised while the session stays open, and restores the default after a mode change, the session end or unplugging. State, errors (retry after 60 s) and the last steps live in `kv.reserve_guard`. `Reserve::decide()` is the pure decision. The sonnenbatterie number entity reports 0 until it is first written, so the app keeps the default itself.

`Target` holds one charge target per plug-in (`kv.charge_target`): energy since plug-in, a time, the car's SOC or its range (`range`, needs `vehicle.range_km`). With a consumption (`Energy::consumption()`: `vehicle.consumption_kwh` in kWh/100 km, else derived from range, SOC and capacity) every target view names the km (`Energy::kmFromKwh()`, 8 % loss as `Energy::CHARGE_EFFICIENCY`), and so does the session overview; `components/target.js` computes the same hints while typing in the sheet. When reached, the mode switches to the target's follow-up mode (`charge.then_mode` as default, `aus` stops the wallbox); unplugging clears it and applies `charge.after_unplug` if set. It is set from the sheet in the charge point card (`partials/target-form.php`, `section=target`).

`Notify` sends push notifications through the Home Assistant companion app (`notify.mobile_app_…`, any `notify` service except `send_message`) to the devices in `notify.targets`. `Notify::EVENTS` defines 16 events in four groups (Wichtig: `fault`, `offline`, `stuck`; Laden: `plug`, `start`, `stop`, `target`, `unplug`, `mode`, `control`; Speicher und Sonne: `battery_full`, `battery_low`, `surplus`, `sunny`; Berichte: `daily`, `monthly`), each with default on/important flags, title and message templates and its thresholds (`Notify::PARAMS`, stored at the top level of `kv.notify`). `Notify::settings()` merges the stored overrides with the defaults; `stored()` writes titles equal to the default as null. `tick()` builds `inputs()` from the snapshot, sessions, `control_state`, `reserve_guard` and `Target::DONE`, runs the pure `detect()` (state machine in `kv.notify_state`, saved inside `BEGIN IMMEDIATE` before sending so parallel `/api/live` calls in demo mode report once) and delivers the enabled events. The first run only adopts the current state. A charging cycle that closes is reported as `stop` after `PAUSE_S` (10 min) unless a new cycle opens first, immediately when the car is full (car SOC at its limit) or unplugged; `start` is silent after such a short pause. `contextFor()` fills the placeholders (`Notify::VARS`, German names such as `{geladen}`) with the same formatting as the pages; `{geladen}` uses the card's counter while the car is plugged in, `monthly` uses the month's sums. `deliver()` adds the companion-app data (`tag` per event, `group`, Android `channel`, iOS `interruption-level`): important events are time-sensitive and ignore the quiet hours, others are passive during them; `url`/`clickAction` open the app's page: `/app/<slug>` from Home Assistant 2026.2 on, where `/hassio/ingress/<slug>` answers 404, and `/hassio/ingress/<slug>` before (`Notify::panelPath()`, version from `/api/config`; slug from the Supervisor's `addons/self/info`, cached in `kv.notify_slug`). Every send lands in the `notifications` table, shown under Einstellungen → Mitteilungen (`views/einstellungen/mitteilungen.php`, `components/notify.js`: placeholder chips insert at the cursor, live preview, probe and test via `/api/mitteilung`, checkbox list saved by fetch).

Vehicle data can come from ESPHome Tesla BLE: `car_soc`, `car_range`, `car_odometer` and `car_limit`. `Energy::distanceKm()` converts miles. A mapped `car_limit` replaces the app's limit slider. `vehicle.capacity_kwh` is the fallback capacity; `vehicle.consumption_kwh` gives the range (`Energy::rangeFrom()`) when no range entity is mapped. `Sessions::tick()` stores the odometer when a session starts. `Actions::suggestions()` adds pattern matches (Tesla BLE, sonnenbatterie reserve) to the fixed `SUGGEST` IDs. `Sessions::tick()` (recorder only, except in demo mode) opens a session while wallbox power is above 0.2 kW or the car reports `charging`. It closes the session after `off_delay_s` of idle time, splits energy into solar and grid by grid import, and stores the vehicle and charge point names. Each row is one charging cycle. `sessions.plug_at` (from `kv.plug_state`, set when the wallbox car status says connected) ties the cycles of one plug-in together; `Sessions::groups()` merges them into one "Ladevorgang" for lists, sums and counts. `Sessions::backfillPlugs()` fills `plug_at` for older cycles from the HA state history of `wallbox_car` (`HaSource::stateHistory()`), at most hourly and 30 days back. `Sessions::summary()`, `cost()` and `co2()` feed the Ladevorgänge page and the energy overview. Past sessions can be imported once from `sensor.ems_ladelog_historie`, which this repo does not define.

The forecast chart (`chart=power`) carries per-day `marks` with `text` (forecast kWh ± sd) and `actual` (measured kWh from `daily`, today from `Series::yieldToday()`); `charts/time.js` draws both above the plot, side by side or stacked when a day is narrow.

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
- Every page load and every `/api/live` poll runs `Snapshot::build()`. That writes forecast rows and the house-mean cache, and can download the DWD file. Reads have side effects. In demo mode `/api/live` also ticks sessions, the controller, the backup buffer and the notifications, because no recorder runs. Demo notifications only go to the log.
- `Series::repairKnownDays()` is a one-off repair with hard-coded dates in 2026-10, guarded by `kv.model_repair`. It is the only code that sets `model_mode` to `pin` or `drop`.
- The plant and tariff defaults in `ConfigStore::defaults()` (10.03 kWp, azimuth 270, tilt 13, 10 kW inverter, 34.7 / 11.0 ct) describe the author's system.
- `.env` and `data/` are gitignored. Never commit the token.
