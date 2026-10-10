// Solar Amber: Einstieg. bin/build.mjs bündelt die Module mit esbuild zu einem Modul-Skript.
import { initCharts } from './charts/index.js';
import { initBattery } from './components/battery.js';
import { initChargepoints } from './components/chargepoint.js';
import { initControl } from './components/control.js';
import { initFlow } from './components/flow.js';
import { initForms, initGlobal } from './components/forms.js';
import { initDialogs } from './core/dialog.js';
import { startLive } from './core/live.js';

initGlobal();
initDialogs();
initForms();
initFlow();
initChargepoints();
initControl();
initBattery();
initCharts();
startLive();
