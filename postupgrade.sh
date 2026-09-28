#!/bin/bash
# Einspeisebremse - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf, und dort wird auch der Dienst wieder gestartet. Wuerde dieses Skript
# es zusaetzlich aufrufen, liefe es ZWEIMAL, mit allem, was darin nicht
# idempotent ist.
#
# Seit 0.9.26 loescht dieses Skript stand.json NICHT mehr. Bis 0.9.25 tat
# es das, um ein altes Abbild zu verwerfen - aber der Installer hat den
# Datenordner vorher ohnehin geleert, und postinstall.sh hat den NEUEN
# Dienst schon gestartet: geloescht wurde also dessen frischer Stand. In
# WSL gemessen (28.09.2026, Fall U5): bei ein=0 gingen danach zwei
# Freigaben im Abstand von 5 s hinaus, obwohl vorher freigegeben war.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-einspeisebremse}"
# ---------- Die Wurzel: GELESEN, nicht gerechnet ----------
# Bis 0.9.21 fiel dieses Skript ohne fuenftes Argument und ohne $LBHOMEDIR
# auf "zwei Ebenen ueber dem eigenen Ablageort" zurueck - ohne zu pruefen,
# ob dort ein LoxBerry liegt. In WSL gemessen (24.09.2026,
# Pruefung-Einspeisebremse-0.9.22, messe_h1.sh, Fall Ha3): in einem
# fremden Baum ohne general.json loeschte es stand.json, rc 0.
# Eine LoxBerry-Wurzel traegt immer config/system/general.json (Regeln/06,
# Wurzelsuche). Nach der Suche kein Rueckfall auf feste Ebenen und kein
# fester Pfad.
eb_wurzel_suchen() {
    eb_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    eb_i=0
    while [ -n "$eb_v" ] && [ "$eb_v" != "/" ] && [ "$eb_i" -lt 8 ]; do
        if [ -d "$eb_v/config/plugins" ] && [ -d "$eb_v/data/plugins" ] \
           && [ -f "$eb_v/config/system/general.json" ]; then
            echo "$eb_v"
            return 0
        fi
        eb_v=$(dirname "$eb_v")
        eb_i=$((eb_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(eb_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry liess sich nicht bestimmen:"
    echo "<WARNING> weder das fuenfte Argument noch \$LBHOMEDIR noch die Suche oberhalb"
    echo "<WARNING> des eigenen Ablageorts fuehrten auf config/plugins, data/plugins"
    echo "<WARNING> und config/system/general.json."
    echo "<WARNING> Es wurde nichts entfernt."
    exit 1
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
