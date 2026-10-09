// Erzeugt app/demo/beispieldaten.json mit festem Seed. Alle Zeitangaben sind relativ zu „heute“,
// damit bin/demo-seed.php die Daten an jedem Tag frisch einspielen kann.
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const file = join(root, 'app/demo/beispieldaten.json');

// mulberry32: kleiner, reproduzierbarer Zufall
let seed = 20261009;
const rand = () => {
  seed |= 0;
  seed = (seed + 0x6d2b79f5) | 0;
  let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};
const between = (lo, hi) => lo + rand() * (hi - lo);
const round = (value, digits = 2) => Math.round(value * 10 ** digits) / 10 ** digits;

// Saison über den Tag im Jahr: 0 im Winter, 1 im Sommer.
const today = new Date();
const season = (offset) => {
  const day = new Date(today.getTime() + offset * 86400000);
  const start = new Date(day.getFullYear(), 0, 0);
  const doy = Math.floor((day - start) / 86400000);
  return 0.5 + 0.5 * Math.sin((2 * Math.PI * (doy - 100)) / 365);
};

const tage = [];
for (let offset = -150; offset <= 12; offset += 1) {
  const s = season(offset);
  const sonne = Math.min(1, Math.max(0.08, 0.35 + 0.45 * s + (rand() - 0.5) * 0.75));
  tage.push({
    offset,
    sonne: round(sonne, 3),
    prognose_fehler: round(between(0.86, 1.14), 3),
    temp_mittel: round(2 + 17 * s + (rand() - 0.5) * 6, 1),
  });
}

// Ladevorgänge über drei Jahre: im Mittel drei pro Woche, im Sommer mit mehr Sonne.
const ladevorgaenge = [];
let km = 18240;
let zaehler = 3120.4;
for (let back = 3 * 365; back >= 1; back -= 1) {
  km += between(25, 55);
  const s = season(-back);
  if (rand() > 0.43) continue;
  const kwh = round(between(6, 44), 2);
  const sonneAnteil = Math.min(1, Math.max(0, 0.12 + 0.75 * s + (rand() - 0.5) * 0.45));
  const sonneKwh = round(kwh * sonneAnteil, 2);
  const leistung = sonneAnteil > 0.6 ? between(2.2, 7.4) : between(5.5, 11);
  const dauer = Math.max(20, Math.round((kwh / leistung) * 60));
  const startMinute = sonneAnteil > 0.5 ? Math.round(between(9 * 60, 13 * 60)) : Math.round(between(16 * 60, 21 * 60));
  ladevorgaenge.push({
    tage_zurueck: back,
    start: `${String(Math.floor(startMinute / 60)).padStart(2, '0')}:${String(startMinute % 60).padStart(2, '0')}`,
    dauer_min: dauer,
    kwh,
    sonne_kwh: sonneKwh,
    netz_kwh: round(kwh - sonneKwh, 2),
    km: rand() < 0.7 ? Math.round(km) : null,
    zaehler_start: round(zaehler, 1),
    zaehler_ende: round(zaehler + kwh, 1),
  });
  zaehler += kwh;
}

const daten = {
  beschreibung: 'Beispieldaten für EMS_DEMO=1. Erzeugt von bin/demo-data.mjs, Zeitangaben relativ zum Einspielen.',
  anlage: { kwp: 14, wechselrichter_kw: 12, neigung: 30, ausrichtung: 180, speicher_kwh: 13.4, speicher_max_kw: 5 },
  ladepunkt: { name: 'Garage' },
  fahrzeug: { name: 'ID.3', kapazitaet_kwh: 77, reichweite_voll_km: 520, limit: 80, soc_morgens: 38 },
  tarif: { bezug_ct: 34.7, einspeisung_ct: 8.1, co2_g_kwh: 380 },
  haus: {
    grundlast_kw: 0.28,
    profil: [0.05, 0.04, 0.04, 0.04, 0.05, 0.12, 0.45, 0.62, 0.48, 0.32, 0.3, 0.38, 0.52, 0.36, 0.28, 0.3, 0.38, 0.62, 0.95, 1.1, 0.92, 0.66, 0.34, 0.14],
  },
  speicher_grenzen: { priority_soc: 50, car_buffer_soc: 80, car_auto_soc: 90 },
  tage,
  ladevorgaenge,
};

mkdirSync(dirname(file), { recursive: true });
writeFileSync(file, JSON.stringify(daten, null, 1) + '\n');
console.log(`beispieldaten.json: ${tage.length} Tage, ${ladevorgaenge.length} Ladevorgänge`);
