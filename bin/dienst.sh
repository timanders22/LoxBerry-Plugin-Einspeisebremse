#!/bin/bash
#
# Einspeisebremse - Dienst starten, anhalten, nachsehen
#
# Den eigenen Ort ueber readlink -f bestimmen: LoxBerry legt bin/plugins/<x>
# haeufig als Verweis an. Ohne readlink zeigt dirname "$0" auf den Verweis,
# und der Weg nach oben landet im falschen Ordner.

# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

# ------------------------------------------------ Wurzel und Ordnername
#
# GELESEN, nicht gerechnet (Regeln/06: Wurzelsuche mit
# config/system/general.json). Bis 0.9.21 stand hier
#     BASE=$(cd "$SELF/../../.." && pwd)
#     PLUGIN=einspeisebremse
# und darunter ein "mkdir -p" bei jedem Aufruf. Ein gesetztes $LBHOMEDIR
# wurde nie gelesen, der Ordnername war fest, und gestartet wurde das
# eb_dienst.php NEBEN diesem Skript. In WSL gemessen (24.09.2026,
# Pruefung-Einspeisebremse-0.9.22, messe_h1.sh):
#   - aus einem Pruefarchiv unter <Wurzel>/pruefung/einspeisebremse/bin
#     startete "start" das eb_dienst.php DES ARCHIVS mit PID-Datei und
#     Konfiguration der Anlage - ein Regler aus fremdem Code gegen dieselben
#     Wechselrichter (Fall R2);
#   - aus einem ausgepackten Archiv startete "restart" einen zweiten Dienst
#     neben dem der Anlage (A4), "status" sah den laufenden nicht (A1);
#   - in einem fremden Baum ohne general.json legten start/status/stop
#     Ordner an, start und der Waechter starteten dort einen Dienst (F1-F4);
#   - in der Upgrade-Luecke legte schon "status" den eben geloeschten
#     Datenordner wieder an (U1).
#
# Zwei Stufen fuer die Wurzel, in dieser Reihenfolge:
#   1. $LBHOMEDIR aus der Umgebung, wenn dort config/plugins und
#      data/plugins liegen (am Geraet aus /etc/environment),
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins
#      UND config/system/general.json traegt.
# Findet keine etwas, bricht das Skript ab, BEVOR es etwas anlegt, startet
# oder anhaelt. KEIN Rueckfall auf feste Ebenen und kein fester Pfad: in
# Zendure, Weissware, Govee, Skoda und VolkswagenID wurde der alte Rueckfall
# nach der Suche genau in einem fremden Baum wieder zur Wurzel.
# "pwd -P" auf beiden Seiten: liegt die Wurzel hinter einem Verweis, muss
# der Vergleich unten zwei physische Pfade vergleichen (Fall N7).
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd -P)
eb_wurzel_suchen() {
    eb_v="$SELF"
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
BASE=""
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    BASE=$(cd "$LBHOMEDIR" && pwd -P)
else
    BASE=$(eb_wurzel_suchen) || BASE=""
fi
# Ohne Wurzel: nichts anlegen, nichts starten, nichts anhalten. "status"
# antwortet mit 4 ("Zustand unbekannt"), damit es sich von 3 ("steht")
# unterscheidet; alles andere mit 1. Die Meldung geht nur auf die Ausgabe -
# eine Protokolldatei gibt es ohne Wurzel nicht (Faelle F1-F4).
if [ -z "$BASE" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "FEHLER: \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF traegt"
    echo "FEHLER: kein Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "FEHLER: Es wurde nichts angelegt, nichts gestartet und nichts angehalten."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi
# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# ist $LBPPLUGINDIR keine Umgebungsvariable (Regeln/03) - dann traegt der
# Ablageort, und bei einer regulaeren Installation ist das genau richtig;
# eine Zweitinstallation heisst dort einspeisebremse01.
PLUGIN="${LBPPLUGINDIR:-}"
PLUGIN="${PLUGIN%/}"
PLUGIN="${PLUGIN##*/}"
[ -n "$PLUGIN" ] || PLUGIN=$(basename "$SELF")
PBIN=$(readlink -f "$BASE/bin/plugins/$PLUGIN" 2>/dev/null)
[ -n "$PBIN" ] || PBIN="$BASE/bin/plugins/$PLUGIN"
# Laeuft dieses Skript wirklich AUS der Installation? start, stop und
# restart fallen sonst geschlossen aus (Faelle A2-A4, R2).
INSTALLIERT=0
[ "$SELF" = "$PBIN" ] && INSTALLIERT=1
# Gegenprobe vor allem anderen: liegt dieses Skript nicht im bin-Ordner der
# Anlage, und ist <ordner> dort auch kein eingerichtetes Plugin, dann kommt
# der Aufruf aus einem Pruefordner - auch "status" sagt dann ab, statt
# "steht" zu melden (Fall R1).
if [ "$INSTALLIERT" != "1" ] && [ ! -d "$BASE/config/plugins/$PLUGIN" ]; then
    echo "FEHLER: '$PLUGIN' ist unter $BASE kein eingerichtetes Plugin,"
    echo "FEHLER: und $SELF ist nicht dessen bin-Ordner. Es wurde nichts angelegt."
    echo "FEHLER: Abhilfe: dienst.sh aus <LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi

DATA="$BASE/data/plugins/$PLUGIN"
LOG="$BASE/log/plugins/$PLUGIN"
PIDF="$DATA/dienst.pid"
# Von preupgrade.sh gelegt, von postinstall.sh entfernt. Juenger als eine
# Stunde: eine Installation laeuft, nicht starten. Aelter: eine
# abgebrochene Installation hat sie liegen lassen - dann gilt sie nicht,
# sonst stuende die Bremse fuer immer.
SPERRE="$BASE/data/plugins/$PLUGIN.upgrade_laeuft"
# Der Dienst DER ANLAGE, nicht der neben dieser Datei. Installiert ist das
# derselbe Ordner (INSTALLIERT oben); aus einem ausgepackten Archiv mit
# gesetzter Umgebung sieht "status" so den laufenden Dienst (Fall A1).
SKRIPT="$PBIN/eb_dienst.php"
PHPBIN=$(command -v php || echo /usr/bin/php)

# Angelegt wird erst im Start, NACH der Markenpruefung - nicht bei jedem
# Aufruf. Bis 0.9.21 stand hier "mkdir -p" auf oberster Ebene: in der
# Upgrade-Luecke legten status, stop und ein gesperrter start den eben von
# purge_installation geloeschten Datenordner wieder an (Faelle U1-U3).

nicht_installiert() {
    echo "FEHLER: dieses Skript liegt nicht unter $PBIN -"
    echo "FEHLER: aus einem ausgepackten Archiv wird nichts gestartet oder angehalten."
}

laeuft() {
    [ -f "$PIDF" ] || return 1
    PID=$(cat "$PIDF" 2>/dev/null)
    case "$PID" in ''|*[!0-9]*) return 1 ;; esac
    # Argumentweise pruefen, nicht mit grep ueber die ganze Befehlszeile:
    # argv[0] ist ein PHP, argv[1] zeichengenau das Dienstskript der Anlage,
    # kein drittes Argument. Bis 0.9.21 genuegte eine Zeile, die auf
    # "eb_dienst.php" endet - ein "tail -f <pfad>/eb_dienst.php" unter der
    # Nummer aus der PID-Datei galt als Dienst: status meldete "laeuft",
    # start startete nichts, stop beendete den tail (Fall L1). Ein
    # "php eb_dienst.php --einmal" aus der Oberflaeche ist kein Dienst.
    # Erst nachsehen, ob es den Eintrag gibt. Umleitungen gelten von links
    # nach rechts: steht 2>/dev/null HINTER der Eingabeumleitung und fehlt
    # /proc/<PID>, meldet die Shell das selbst. Die Pruefung mit -r allein
    # reicht nicht - endet der Dienst zwischen Pruefung und Lesen (beim
    # Anhalten die Regel), stand "No such file or directory" in der Ausgabe
    # von stop (im Pruefstand gesehen). Deshalb 2>/dev/null zuerst.
    [ -r "/proc/$PID/cmdline" ] || return 1
    ARGS=$(tr '\0' '\n' 2>/dev/null < "/proc/$PID/cmdline")
    [ "$(printf '%s\n' "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    printf '%s\n' "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    [ "$(printf '%s\n' "$ARGS" | sed '/^$/d' | wc -l)" -eq 2 ] || return 1
    return 0
}

# Gilt die Marke "Aktualisierung laeuft"?
#   - juenger als 3600 s -> sie gilt, es wird nicht gestartet
#   - aelter, mehr als 300 s aus der Zukunft oder ohne Zeitpunkt -> sie
#     gilt NICHT; eine abgebrochene Installation darf die Bremse nicht fuer
#     immer stilllegen. Bis 0.9.21 galt eine Marke aus der Zukunft
#     unbegrenzt (Faelle M5, M6). Die 300 s Vorlauf: die Uhr kann ein
#     Stueck zurueckspringen, nachdem preupgrade.sh die Marke gesetzt hat
#     (Hausform Skoda, Zendure, VolkswagenID).
#   - OHNE LESBARE UHR GILT SIE: wer das Alter nicht messen kann, startet
#     nicht (Fall M7).
# Markeninhalt UND Uhr werden als Zahl geprueft, bevor gerechnet wird:
# bash wertet in $(( )) den Inhalt einer Variablen als Ausdruck aus, ein
# "a[$(befehl)]" fuehrt den Befehl aus. Bis 0.9.21 ging die Ausgabe von
# "date" ungeprueft in die Rechnung - gemessen: der Befehl lief (Fall M9).
marke_gilt() {
    [ -f "$SPERRE" ] || return 1
    MI=$(head -c 32 "$SPERRE" 2>/dev/null | tr -d ' \t\n\r')
    case "$MI" in ''|*[!0-9]*) return 1 ;; esac
    MJ=$(date +%s 2>/dev/null)
    case "$MJ" in ''|*[!0-9]*) return 0 ;; esac
    [ "$MI" -gt $((MJ + 300)) ] && return 1
    [ $((MJ - MI)) -lt 3600 ] && return 0
    return 1
}

case "$1" in
    start)
        if [ "$INSTALLIERT" != "1" ]; then nicht_installiert; exit 1; fi
        if laeuft; then echo "Einspeisebremse laeuft bereits (PID $(cat "$PIDF"))."; exit 0; fi
        if marke_gilt; then
            echo "Eine Installation laeuft - der Dienst wird danach gestartet."
            exit 0
        fi
        mkdir -p "$DATA" "$LOG"
        nohup "$PHPBIN" "$SKRIPT" >> "$LOG/dienst.out" 2>&1 &
        echo $! > "$PIDF"
        sleep 1
        if laeuft; then echo "Einspeisebremse gestartet (PID $(cat "$PIDF"))."; exit 0; fi
        # Die Wirkung pruefen, nicht den Rueckgabewert: nohup meldet Erfolg,
        # auch wenn PHP eine Sekunde spaeter aussteigt.
        echo "Einspeisebremse konnte nicht gestartet werden. Siehe $LOG/dienst.out"
        tail -n 20 "$LOG/dienst.out" 2>/dev/null
        rm -f "$PIDF"
        exit 1
        ;;
    stop)
        if [ "$INSTALLIERT" != "1" ]; then nicht_installiert; exit 1; fi
        if ! laeuft; then echo "Einspeisebremse laeuft nicht."; rm -f "$PIDF"; exit 0; fi
        PID=$(cat "$PIDF")
        # SIGTERM, nicht SIGKILL: der Dienst raeumt seinen MQTT-Zuhoerer ab.
        # Die WIRKUNG pruefen, nicht den Rueckgabewert. Bis 0.9.17 wurde
        # das Ergebnis von kill verworfen, nach dem kill -9 nicht noch
        # einmal nachgesehen, die PID-Datei unbedingt geloescht und
        # unbedingt Erfolg gemeldet - genau der Fall, den der
        # Kopfkommentar oben beschreibt. Ist die PID-Datei erst fort,
        # meldet laeuft() dauerhaft "nein", und der Minutentakt startet
        # einen ZWEITEN Dienst neben den laufenden. Zwei Dienste stellen
        # unabhaengig voneinander Grenzen an dieselben Wechselrichter.
        if ! kill "$PID" 2>/dev/null; then
            echo "Das Anhalten wurde abgewiesen (PID $PID gehoert einem anderen Benutzer?)." >&2
        fi
        for i in 1 2 3 4 5 6 7 8 9 10; do laeuft || break; sleep 1; done
        if laeuft; then kill -9 "$PID" 2>/dev/null; sleep 1; fi
        if laeuft; then
            echo "Einspeisebremse liess sich NICHT anhalten - PID $PID laeuft weiter." >&2
            echo "Die PID-Datei bleibt liegen, damit der Waechter keinen zweiten Dienst startet." >&2
            exit 1
        fi
        rm -f "$PIDF"
        echo "Einspeisebremse angehalten. Die zuletzt gestellte Grenze bleibt bestehen."
        ;;
    restart)
        # Aus einem Archiv heraus haelt "restart" nichts an und startet
        # nichts: bis 0.9.21 lief danach ein zweiter Dienst (Fall A4).
        if [ "$INSTALLIERT" != "1" ]; then nicht_installiert; exit 1; fi
        # Ohne die Abbruchbedingung ist restart der kuerzeste Weg zum
        # Doppeldienst: stop meldet Erfolg, start findet keine PID-Datei.
        "$0" stop || exit 1
        "$0" start
        ;;
    status)
        if laeuft; then echo "laeuft (PID $(cat "$PIDF"))"; exit 0; fi
        echo "steht"; exit 3
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status}"; exit 1
        ;;
esac
