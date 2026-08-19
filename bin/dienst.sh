#!/bin/bash
#
# Einspeisebremse - Dienst starten, anhalten, nachsehen
#
# Den eigenen Ort ueber readlink -f bestimmen: LoxBerry legt bin/plugins/<x>
# haeufig als Verweis an. Ohne readlink zeigt dirname "$0" auf den Verweis,
# und der Weg nach oben landet im falschen Ordner.

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
BASE=$(cd "$SELF/../../.." && pwd)
PLUGIN=einspeisebremse

DATA="$BASE/data/plugins/$PLUGIN"
LOG="$BASE/log/plugins/$PLUGIN"
PIDF="$DATA/dienst.pid"
PHPBIN=$(command -v php || echo /usr/bin/php)

mkdir -p "$DATA" "$LOG"

laeuft() {
    [ -f "$PIDF" ] || return 1
    PID=$(cat "$PIDF" 2>/dev/null)
    [ -n "$PID" ] || return 1
    # Argumentweise pruefen, nicht mit grep ueber die ganze Befehlszeile:
    # ein grep auf "einspeisebremse" faende auch die eigene Suche.
    # Erst nachsehen, ob es den Eintrag gibt. Die Eingabeumleitung wird VOR
    # dem 2>/dev/null ausgewertet - fehlt /proc/<PID>, meldet die Shell das
    # selbst, und beim Anhalten eines toten Dienstes stehen zwei
    # Fehlerzeilen auf dem Bildschirm, die nichts bedeuten.
    [ -r "/proc/$PID/cmdline" ] || return 1
    tr '\0' '\n' < "/proc/$PID/cmdline" 2>/dev/null | grep -q 'eb_dienst\.php$' && return 0
    return 1
}

case "$1" in
    start)
        if laeuft; then echo "Einspeisebremse laeuft bereits (PID $(cat "$PIDF"))."; exit 0; fi
        nohup "$PHPBIN" "$SELF/eb_dienst.php" >> "$LOG/dienst.out" 2>&1 &
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
        if ! laeuft; then echo "Einspeisebremse laeuft nicht."; rm -f "$PIDF"; exit 0; fi
        PID=$(cat "$PIDF")
        # SIGTERM, nicht SIGKILL: der Dienst raeumt seinen MQTT-Zuhoerer ab.
        kill "$PID" 2>/dev/null
        for i in 1 2 3 4 5 6 7 8 9 10; do laeuft || break; sleep 1; done
        if laeuft; then kill -9 "$PID" 2>/dev/null; sleep 1; fi
        rm -f "$PIDF"
        echo "Einspeisebremse angehalten. Die zuletzt gestellte Grenze bleibt bestehen."
        ;;
    restart)
        "$0" stop
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
