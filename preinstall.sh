#!/bin/bash
# Einspeisebremse - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Entscheidung 1 vom 29.09.2026 (X-1). Der Installer ruft dieses Skript bei
# JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem
# Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und
# Upgrade-Sicherung braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json, mit Aktionstoken, Stellgliedern und
# dem Schalter der Regelung) und eine liegengebliebene Upgrade-Sicherung
# (data/plugins/<ordner>.upgrade_sicherung: Verlauf, Bilanz, Retain-Merker)
# einer frueheren Installation gehen nach <name>.alt, gemeldet mit genau
# einer <WARNING>. Bis 0.9.28 spielte postinstall.sh die Zweitschrift
# zurueck, und schon vorher heilte eb_config() im ersten Minutentakt die
# leere Konfiguration aus ihr - eine frisch installierte Bremse stand damit
# auf der Regelung einer frueheren Installation. Die Selbstheilung liest
# .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-einspeisebremse}"
# ---------- Die Wurzel: GELESEN, nicht gerechnet ----------
# Wie in preupgrade.sh, postinstall.sh, postupgrade.sh und uninstall: das
# fuenfte Argument, sonst $LBHOMEDIR, sonst die Suche oberhalb des eigenen
# Ablageorts nach config/plugins, data/plugins und config/system/general.json.
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
# Ohne erkennbaren LoxBerry wird nichts angefasst - und die Installation
# nicht abgebrochen: beiseitelegen ist Vorsorge, keine Voraussetzung.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$SICHER"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    EB_TEXT="<WARNING> Neuinstallation: Einstellungen und Langzeitwerte einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && EB_TEXT="$EB_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && EB_TEXT="$EB_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$EB_TEXT"
fi
exit 0
