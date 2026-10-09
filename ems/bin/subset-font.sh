#!/bin/sh
# Baut app/assets/fonts/InterVariable.woff2 neu: Latein-Teilmenge des offiziellen Inter-Releases
# mit allen OpenType-Features (cv11, ss01, tnum …) und beiden Achsen (wght, opsz).
# Die Fontsource-Pakete enthalten cv11 und ss01 nicht, deshalb diese Datei.
#
# Aufruf: sh bin/subset-font.sh /pfad/zu/InterVariable.woff2
# Die Quelle steckt im Release-Zip https://github.com/rsms/inter/releases (web/InterVariable.woff2).
# Braucht fonttools und brotli (pip install fonttools brotli).
set -eu
src="${1:?Pfad zur offiziellen InterVariable.woff2 fehlt}"
dir="$(cd "$(dirname "$0")/.." && pwd)"
python3 -m fontTools.subset "$src" \
  --output-file="$dir/app/assets/fonts/InterVariable.woff2" \
  --flavor=woff2 \
  --layout-features='*' \
  --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+0160-0161,U+0178,U+017D-017E,U+0192,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+1E9E,U+2000-206F,U+2070-209F,U+20AC,U+2122,U+2190-2199,U+2212,U+2215,U+221E,U+2248,U+2260,U+2264-2265,U+FEFF,U+FFFD"
echo "app/assets/fonts/InterVariable.woff2 neu geschrieben"
