#!/bin/bash
# Einspeisebremse - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar
# sein, ohne Schaden anzurichten.
#
# Dieses Skript laeuft als Benutzer loxberry, NICHT als root. Deshalb steht
# mosquitto-clients in dpkg/apt und wird nicht hier installiert.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-einspeisebremse}"
# ---------- Die Wurzel: GELESEN, nicht gerechnet ----------
# Bis 0.9.21 fiel dieses Skript ohne fuenftes Argument und ohne $LBHOMEDIR
# auf "zwei Ebenen ueber dem eigenen Ablageort" zurueck - ohne zu pruefen,
# ob dort ein LoxBerry liegt. In WSL gemessen (24.09.2026,
# Pruefung-Einspeisebremse-0.9.22, messe_h1.sh, Fall Ha2): in einem
# fremden Baum ohne general.json legte es Ordner an und startete
# dort einen Dienst, rc 0.
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
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry liess sich nicht bestimmen:"
    echo "<FAIL> weder das fuenfte Argument noch \$LBHOMEDIR noch die Suche oberhalb"
    echo "<FAIL> des eigenen Ablageorts fuehrten auf config/plugins, data/plugins"
    echo "<FAIL> und config/system/general.json."
    echo "<FAIL> Es wurde nichts eingerichtet und kein Dienst gestartet."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/einspeisebremse.json" ] || echo '{}' > "$PCONFIG/einspeisebremse.json"
chmod 600 "$PCONFIG/einspeisebremse.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/einspeisebremse.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. Ohne PHP laeuft weder die Oberflaeche noch der Dienst."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- mosquitto-Werkzeuge pruefen ----------
# Nicht installieren, nur nachsehen und sagen. Sie stehen in dpkg/apt; wenn
# sie hier fehlen, ist an der Installation etwas schiefgegangen, und das
# soll man erfahren, bevor man sich wundert, warum nichts geregelt wird.
FEHLT=""
command -v mosquitto_sub >/dev/null 2>&1 || FEHLT="$FEHLT mosquitto_sub"
command -v mosquitto_pub >/dev/null 2>&1 || FEHLT="$FEHLT mosquitto_pub"
if [ -n "$FEHLT" ]; then
    echo "<INFO> Es fehlt:$FEHLT"
    echo "<INFO> Ohne diese Werkzeuge kann die Bremse keinen MQTT-Zaehler lesen"
    echo "<INFO> und nichts ueber MQTT stellen. Nachholen mit:"
    echo "<INFO>   sudo apt install mosquitto-clients"
else
    echo "<OK> mosquitto_sub und mosquitto_pub sind vorhanden."
fi

# ---------- Selbsttest des Regelkerns ----------
# Ohne Anlage und ohne Netz: rechnet die Entscheidungen durch und
# vergleicht sie mit den hinterlegten Sollwerten.
if [ -f "$PBIN/eb_dienst.php" ]; then
    if AUS=$(php "$PBIN/eb_dienst.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest: $(echo "$AUS" | tail -1)"
    else
        echo "<INFO> Der Selbsttest ist nicht sauber durchgelaufen:"
        echo "$AUS" | tail -20 | sed 's/^/<INFO> /'
    fi
fi

# KEIN chown: dieses Skript laeuft als Benutzer loxberry (siehe Kopf), ein
# "chown -R loxberry:loxberry ... 2>/dev/null" scheiterte dort still und sah
# nur nach Absicherung aus. Ein Befehl, der genau dann nichts tut, wenn man
# ihn braeuchte, ist schlimmer als keiner. Stattdessen wird nachgesehen und
# gesagt, falls etwas dem falschen Benutzer gehoert.
WER=$(id -un)
FREMD=$(find "$PDATA" "$PLOG" "$PCONFIG" ! -user "$WER" 2>/dev/null | head -3)
if [ -n "$FREMD" ]; then
    echo "<INFO> Diese Dateien gehoeren nicht $WER:"
    echo "$FREMD" | sed 's/^/<INFO>   /'
    echo "<INFO> Der Dienst laeuft als $WER und koennte sie nicht schreiben."
fi

# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden. Zurueckgeholt wird nur, was fehlt - eine Neuinstallation
# findet nichts vor und faengt sauber bei null an.
#
# DIESER BLOCK STEHT VOR DEM DIENSTSTART. Bis 0.9.17 stand er dahinter,
# und das machte ihn wirkungslos: am 04.09.2026 gemessen, legt der Dienst
# verlauf.json und bilanz.json 0,41 s nach seinem Start selbst an. Der
# Block fand dann eine nicht leere Datei vor, uebersprang die Rettung -
# und loeschte die Sicherung trotzdem. Wer die Reihenfolge wieder dreht,
# nimmt jedem Anwender bei jedem Update Verlauf und Monatsbilanz.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
SPERRE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
# Liegt die Marke aus preupgrade.sh, ist die Sicherung von eben: dann wird
# zurueckgeholt, was dort liegt, ohne nach dem Inhalt der Zieldatei zu
# fragen. Die Inhaltspruefung allein hat am 08.09.2026 nicht gereicht - ein
# vom Minutentakt gestarteter Dienst hatte schon echte Punkte geschrieben.
# Laeuft trotz Marke ein Dienst (der alte Takt in der Sekunde vor dem
# Abraeumen), wird er zuerst angehalten, sonst schriebe er darueber.
FRISCH=""
if [ -f "$SPERRE" ]; then
    FRISCH="ja"
    [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" stop >/dev/null 2>&1
    # Ein Dienst, dessen PID-Datei mit dem Datenordner geloescht wurde, ist
    # fuer dienst.sh unsichtbar. Er wuerde neben dem neuen weiterlaufen und
    # seinen Verlauf aus dem Speicher ueber die gerettete Datei schreiben.
    # Argumentweise, wie laeuft() in bin/dienst.sh: argv[0] ein PHP, argv[1]
    # zeichengenau das Dienstskript dieser Installation, kein drittes
    # Argument, und der Prozess gehoert dem Dienstbenutzer. Bis 0.9.21 stand
    # hier "pgrep -f" - das sucht eine Teilzeichenkette in der GANZEN
    # Befehlszeile und beendete in WSL einen "tail -f <dienstpfad>"
    # (24.09.2026, Pruefung-Einspeisebremse-0.9.22, Fall G1; Regeln/06).
    EB_DIENSTUID=$(id -u loxberry 2>/dev/null || id -u)
    EB_SKRIPT="$(readlink -f "$PBIN" 2>/dev/null || echo "$PBIN")/eb_dienst.php"
    eb_ist_dienst() {
        [ -r "/proc/$1/cmdline" ] || return 1
        [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$EB_DIENSTUID" ] || return 1
        eb_a=$(tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline")
        [ "$(printf '%s\n' "$eb_a" | sed -n '2p')" = "$EB_SKRIPT" ] || return 1
        printf '%s\n' "$eb_a" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
        [ "$(printf '%s\n' "$eb_a" | sed '/^$/d' | wc -l)" -eq 2 ] || return 1
        return 0
    }
    WAISEN=""
    for eb_d in /proc/[0-9]*; do
        eb_ist_dienst "${eb_d#/proc/}" && WAISEN="$WAISEN ${eb_d#/proc/}"
    done
    if [ -n "$WAISEN" ]; then
        kill $WAISEN 2>/dev/null
        for i in 1 2 3 4 5 6 7 8 9 10; do
            eb_rest=""
            for eb_p in $WAISEN; do eb_ist_dienst "$eb_p" && eb_rest="ja"; done
            [ -z "$eb_rest" ] && break
            sleep 1
        done
        echo "<INFO> Ein Dienst ohne PID-Datei lief waehrend der Installation und wurde beendet."
    fi
fi
if [ -d "$LANG_SICHER" ]; then
    for LANG_F in verlauf.json bilanz.json retain_stellbefehl.json; do
        [ -f "$LANG_SICHER/$LANG_F" ] || continue
        ZIEL="$BASE/data/plugins/$PFOLDER/$LANG_F"
        # Gegen den INHALT pruefen, nicht nur gegen "nicht leer": eine
        # frisch angelegte Datei mit leerer Punktliste ist 23 Byte gross
        # und haette die Rettung sonst verhindert. Dieselbe Sorgfalt wie
        # oben bei der Konfiguration.
        DA=""
        if [ -z "$FRISCH" ] && [ -s "$ZIEL" ]; then
            INH=$(tr -d ' \n\r\t' < "$ZIEL" 2>/dev/null)
            case "$INH" in
                ''|'{}'|'[]'|'{"punkte":[]}') DA="" ;;
                *) DA="ja" ;;
            esac
        fi
        if [ -z "$DA" ]; then
            mkdir -p "$BASE/data/plugins/$PFOLDER" 2>/dev/null
            if cp -p "$LANG_SICHER/$LANG_F" "$ZIEL" 2>/dev/null; then
                echo "<OK> $LANG_F ueber das Update gerettet."
            else
                echo "<INFO> $LANG_F liess sich nicht zurueckholen."
            fi
        fi
    done
    rm -rf "$LANG_SICHER" 2>/dev/null
fi
# Die Marke VOR dem Start entfernen - sonst verweigert dienst.sh ihn.
rm -f "$SPERRE" 2>/dev/null

# ---------- Ist die Bremse eingerichtet? ----------
# Dieses Skript laeuft auch bei jedem Upgrade (siehe Kopf). Bis 0.9.23
# meldete es danach "Die Regelung selbst ist noch AUS" und die
# Erstanleitung - auch wenn die Konfiguration mit eingeschalteter Regelung
# eben zurueckgespielt war (gemessen 24.09.2026 in WSL,
# Pruefung-Einspeisebremse-0.9.24, Fall b). Entschieden wird nach dem
# INHALT, nicht nach der Upgrade-Marke: gueltiges JSON mit dem Schluessel
# aktionstoken - dasselbe Merkmal, mit dem eb_config() in
# webfrontend/html/eb_lib.php Konfiguration und Zweitschrift als heil
# erkennt. "{}" oder eine unlesbare Datei heisst: nicht eingerichtet, also
# Erstinstallation oder gescheiterte Rueckholung. PHP ist hier sicher da
# (Pruefung weiter oben).
EB_LAGE=$(php -r '$d = json_decode(trim((string) @file_get_contents($argv[1])), true);
if (!is_array($d) || !array_key_exists("aktionstoken", $d)) { echo "neu"; exit(0); }
echo empty($d["ein"]) ? "aus" : "ein";' -- "$CF" 2>/dev/null)

# ---------- Dienst starten ----------
# Der Dienst misst und zeigt an. GESTELLT wird erst, wenn der Mensch die
# Regelung in der Oberflaeche einschaltet - eine frisch installierte Bremse
# greift niemals von selbst in eine laufende Anlage ein.
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" restart >/dev/null 2>&1
    sleep 1
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        case "$EB_LAGE" in
            ein) echo "<OK> Der Dienst laeuft. Die Regelung ist eingeschaltet (Einstellung uebernommen)." ;;
            aus) echo "<OK> Der Dienst laeuft. Die Regelung ist ausgeschaltet (Einstellung uebernommen)." ;;
            *)   echo "<OK> Der Dienst laeuft. Die Regelung selbst ist noch AUS." ;;
        esac
    else
        echo "<INFO> Der Dienst konnte noch nicht gestartet werden."
        echo "<INFO> Der Minutentakt startet ihn beim naechsten Durchlauf erneut."
    fi
fi

# Die Erstanleitung nur ohne eingerichtete Konfiguration (EB_LAGE, oben).
if [ "$EB_LAGE" = "ein" ] || [ "$EB_LAGE" = "aus" ]; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
    echo "<INFO>  1. Reiter Einstellungen: Netzzaehler eintragen - Vorzeichen beachten,"
    echo "<INFO>     plus = Bezug, minus = Einspeisung."
    echo "<INFO>  2. Stellglieder eintragen, mit Platzhalter {W}, {KW} oder {PROZENT}."
    echo "<INFO>  3. Reiter Test: 'Messwerte lesen' und 'Trockenlauf' - dort steht,"
    echo "<INFO>     was die Regelung taete und welche Befehle hinausgingen."
    echo "<INFO>  4. Erst wenn das stimmt: Regelung einschalten."
fi
exit 0
