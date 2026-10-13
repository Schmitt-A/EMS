// Solar Amber: Einstieg. bin/build.mjs bündelt die Module mit esbuild zu einem Modul-Skript.
import { initCharts } from './charts/index.js';
import { initBattery } from './components/battery.js';
import { initChargepoints } from './components/chargepoint.js';
import { initControl } from './components/control.js';
import { initEnergyFlow } from './components/energy-flow.js';
import { initFlow } from './components/flow.js';
import { initForms, initGlobal } from './components/forms.js';
import { initHeatmaps } from './components/heatmap.js';
import { initHomeAssistant } from './components/homeassistant.js';
import { initNotify } from './components/notify.js';
import { initTargets } from './components/target.js';
import { initWeekMaps } from './components/weekmap.js';
import { initDialogs } from './core/dialog.js';
import { startLive } from './core/live.js';

// In Home Assistant öffnet ein Link auf eine Unterseite von EMS diese; dann lohnt der Rest nicht mehr.
if (!initHomeAssistant()) {
  initGlobal();
  initDialogs();
  initForms();
  initNotify();
  initTargets();
  initHeatmaps();
  initWeekMaps();
  initFlow();
  initEnergyFlow();
  initChargepoints();
  initControl();
  initBattery();
  initCharts();
  startLive();
}
