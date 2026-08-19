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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
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
command -v mosquitto_sub >/dev/null 2>&1 || FEHLT="mosquitto_sub"
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

# ---------- Dienst starten ----------
# Der Dienst misst und zeigt an. GESTELLT wird erst, wenn der Mensch die
# Regelung in der Oberflaeche einschaltet - eine frisch installierte Bremse
# greift niemals von selbst in eine laufende Anlage ein.
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" restart >/dev/null 2>&1
    sleep 1
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        echo "<OK> Der Dienst laeuft. Die Regelung selbst ist noch AUS."
    else
        echo "<INFO> Der Dienst konnte noch nicht gestartet werden."
        echo "<INFO> Der Minutentakt startet ihn beim naechsten Durchlauf erneut."
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Netzzaehler eintragen - Vorzeichen beachten,"
echo "<INFO>     plus = Bezug, minus = Einspeisung."
echo "<INFO>  2. Stellglieder eintragen, mit Platzhalter {W}, {KW} oder {PROZENT}."
echo "<INFO>  3. Reiter Test: 'Messwerte lesen' und 'Trockenlauf' - dort steht,"
echo "<INFO>     was die Regelung taete und welche Befehle hinausgingen."
echo "<INFO>  4. Erst wenn das stimmt: Regelung einschalten."
exit 0
