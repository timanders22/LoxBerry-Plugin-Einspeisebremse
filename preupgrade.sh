#!/bin/bash
# Einspeisebremse - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# sechsten Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-einspeisebremse}"
# ---------- Die Wurzel: GELESEN, nicht gerechnet ----------
# Bis 0.9.21 fiel dieses Skript ohne fuenftes Argument und ohne $LBHOMEDIR
# auf "zwei Ebenen ueber dem eigenen Ablageort" zurueck - ohne zu pruefen,
# ob dort ein LoxBerry liegt. In WSL gemessen (24.09.2026,
# Pruefung-Einspeisebremse-0.9.22, messe_h1.sh, Fall Ha1): in einem
# fremden Baum ohne general.json legte es Marke und Sicherung an
# und hielt dessen Dienst an, rc 0.
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
    echo "<WARNING> Es wurde NICHTS gesichert, keine Marke gelegt und kein Dienst angehalten."
    exit 1
fi

# Zuerst die Marke "Aktualisierung laeuft". Der Installer legt die
# Cron-Datei rund eine Minute VOR postinstall.sh neu an; am 08.09.2026
# startete der Minutentakt in dieser Luecke den neuen Dienst (03:32:00,
# postinstall erst 03:32:24). Der legte Verlauf und Bilanz selbst an, und
# die Rettung unten in postinstall.sh uebersprang sich. dienst.sh startet
# nicht, solange die Marke liegt; postinstall.sh entfernt sie. Sie liegt
# NEBEN dem Datenordner, weil purge_installation den Ordner selbst loescht.
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" 2>/dev/null
[ -s "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] \
    && echo "<OK> Dienststart bis zum Ende der Installation gesperrt."

CF="$BASE/config/plugins/$PFOLDER/einspeisebremse.json"
ZW="$BASE/config/plugins/$PFOLDER.backup.json"
# Nur eine BRAUCHBARE Konfiguration ueber die Zweitschrift legen - nach
# Inhalt entschieden wie die Selbstheilung (lesbares JSON mit Wortzeichen).
# Bis 0.9.25 kopierte dieser Block jede vorhandene Datei: eine leere, "{}"
# oder abgeschnittene Konfiguration ueberschrieb die heile Zweitschrift, und
# nach dem Update waren Token, Stellglieder und Regelung fort, gemeldet als
# "<OK> Konfiguration gesichert." (WSL, Faelle U3a-c, 28.09.2026).
if [ -f "$CF" ]; then
    if command -v php >/dev/null 2>&1; then
        php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
                exit((is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                      && $d["aktionstoken"] !== "") ? 0 : 1);' "$CF" 2>/dev/null
        CF_OK=$?
    else
        grep -Eq '"aktionstoken"[[:space:]]*:[[:space:]]*"[A-Za-z0-9_.-]+"' "$CF" 2>/dev/null
        CF_OK=$?
    fi
    if [ "$CF_OK" = "0" ]; then
        cp -p "$CF" "$ZW" \
            && chmod 600 "$ZW" 2>/dev/null \
            && echo "<OK> Konfiguration gesichert."
    elif [ -s "$ZW" ]; then
        echo "<WARNING> Die Konfiguration ist leer oder beschaedigt; die bisherige Zweitschrift bleibt unberuehrt und wird nach dem Update zurueckgespielt."
    else
        echo "<WARNING> Die Konfiguration ist leer oder beschaedigt, und es gibt keine Zweitschrift. Nach dem Update ist das Plugin neu einzurichten."
    fi
fi

# Den Dienst anhalten, BEVOR seine Dateien ersetzt werden. Ein laufender
# Prozess, dessen Quelltext unter ihm ausgetauscht wird, ist eine Wette;
# postinstall.sh startet ihn hinterher ohnehin neu.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
[ -x "$DIENST" ] && "$DIENST" stop >/dev/null 2>&1
echo "<OK> preupgrade abgeschlossen."

# ---------- Langzeitwerte retten ----------
# der Verlauf der Abregelungen - die Zahl, an der man ueber Wochen sieht, ob
# die Bremse wirkt - und die Bilanz mit Tages-, Vortags- und Monatswerten.
# Der Installer loescht data/plugins/<x>/ bei JEDEM Update - gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): &purge_installation steht
# im Upgrade-Zweig (:886), und ihr Rumpf loescht ohne Bedingung (:1631).
# Deshalb NEBEN den Ordner: "rm -rf .../<x>/" trifft den Nachbarn mit dem
# Punkt nicht. postinstall.sh holt ihn zurueck und raeumt ihn weg.
# Dazu der Merker retain_stellbefehl.json: fuer welches Stellthema ein
# alter zurueckbehaltener Stellwert schon abgeraeumt oder nicht vorhanden
# war (eb_lib.php, eb_stell_merker_lesen()). Ginge er verloren, saehe der
# Dienst nur erneut nach - abgeraeumt wird nur, was wirklich dasteht.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$LANG_SICHER" 2>/dev/null
chmod 0700 "$LANG_SICHER" 2>/dev/null
for LANG_F in verlauf.json bilanz.json retain_stellbefehl.json; do
    [ -f "$BASE/data/plugins/$PFOLDER/$LANG_F" ] \
        && cp -p "$BASE/data/plugins/$PFOLDER/$LANG_F" "$LANG_SICHER/$LANG_F" 2>/dev/null
done
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher etwas da?
if [ -n "$(ls -A "$LANG_SICHER" 2>/dev/null)" ]; then
    echo "<OK> Langzeitwerte gesichert."
fi
exit 0
