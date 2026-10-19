<?php
/**
 * Einspeisebremse - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
 * geschuetzt. Verglichen wird mit hash_equals, also in gleichbleibender
 * Zeit - ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer
 * Zeichen erraten.
 *
 *   ?selftest=1&token=<TOKEN>       nur: stimmt das Wortzeichen? (schaltet nichts)
 *   ?token=<TOKEN>&aktion=status   alle Werte als Textzeilen (die Vorlage)
 *   ?token=<TOKEN>&aktion=json     dasselbe als JSON
 *   ?token=<TOKEN>&aktion=ein&wert=0|1   die Regelung ein- oder ausschalten
 *   ?token=<TOKEN>&aktion=stufe&wert=0|1|2  die Zielstufe waehlen
 *
 * Gilt der verlangte Wert schon, wird nichts geschrieben und nichts
 * protokolliert, und die Antwort traegt ";UNVERAENDERT=1" (X-7,
 * Entscheidung 19): EIN=1;UNVERAENDERT=1 bzw. STUFE=1;ZIEL=300;UNVERAENDERT=1.
 * Verglichen wird mit der gespeicherten Konfiguration - sie ist der Zustand.
 *
 * Der Schalter ist die EINZIGE Handlung, die von aussen moeglich ist - und
 * er ist es mit Bedacht: eine Wallbox oder ein Notstromfall soll die Bremse
 * aus Loxone heraus abschalten koennen. Einen Sollwert von aussen gibt es
 * NICHT. Wer die Grenze uebers Netz setzen koennte, koennte die Anlage
 * uebers Netz abschalten, geschuetzt nur durch ein Wortzeichen, das offen
 * in der Adresse steht.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/eb_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/* Nur LESEN. Der Aufrufer hat sich noch nicht ausgewiesen; was hier
 * entstuende, legte ein Fremder an. Gemessen am 04.09.2026: bis 0.9.17
 * erzeugte ein Aufruf mit FALSCHEM Wortzeichen den Konfigordner und
 * holte die Konfiguration aus der Zweitschrift zurueck - eine gerade
 * von Hand geleerte Datei kam hinter dem Ruecken des Bedieners wieder. */
$eb_cfg = eb_config(false);
$eb_soll = (string) $eb_cfg['aktionstoken'];
/* Nur eine Zeichenkette ist ein Token. (string) machte aus token[]=... das
 * Wort "Array" - abgewiesen, aber mit einer PHP-Warnung je Aufruf (Klasse 12,
 * gemessen 01.10.2026). */
$eb_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

/* ---- Selbsttest ----
 * Ein Wortzeichen muss sich pruefen lassen, OHNE dass etwas passiert.
 * Sonst gibt es nur zwei schlechte Moeglichkeiten: entweder man schaltet
 * wirklich - dann greift die Bremse in eine laufende Anlage ein -, oder
 * man erfaehrt nie, ob die im Miniserver eingetragene Adresse noch stimmt.
 * Der Zweig steht deshalb hier: die Token-Pruefung greift, die Wirkung
 * nicht. Kein Geraetekontakt, kein Schreibzugriff, kein Protokolleintrag.
 * Ein falsches Wortzeichen wird genauso abgewiesen wie sonst auch - der
 * Selbsttest ist keine Abkuerzung an der Sicherheit vorbei. */
$eb_selftest = isset($_GET['selftest']) && (string) $_GET['selftest'] === '1';
if ($eb_selftest) {
    if ($eb_soll === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!hash_equals($eb_soll, $eb_ist)) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

if ($eb_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Wortzeichen.\n";
    exit;
}
if (!hash_equals($eb_soll, $eb_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

$eb_aktion = isset($_GET['aktion']) ? strtolower((string) $_GET['aktion']) : 'status';
if (!in_array($eb_aktion, array('status', 'json', 'ein', 'stufe'), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: status, json, ein, stufe\n";
    exit;
}

/* ---- Regelung ein/aus und Zielstufe ----
 * Gruppe 3 / MQTT (0.9.37): die Pruefung steht in eb_befehl_ausfuehren() (eb_lib.php); dieselbe Funktion
 * ruft die Befehlsbruecke (bin/befehle_zuordnung.php, Quelle 'mqtt'). Antwort, Code und Protokoll dieses
 * Endpunkts sind unveraendert (Wirkungstest Quelle gegen Bau): wert 0|1 bzw. 0|1|2 (sonst 400 WERT),
 * Einschalten mit sperrendem Mangel 409 MAENGEL, Schreibfehler 500 SPEICHERN, gleicher Zustand
 * ";UNVERAENDERT=1" ohne Schreiben (X-7). Gewaehlt wird eine STUFE, nie ein Wert. */
if ($eb_aktion === 'ein' || $eb_aktion === 'stufe') {
    list($eb_code, $eb_antwort) = eb_befehl_ausfuehren($eb_aktion,
        isset($_GET['wert']) ? (string) $_GET['wert'] : '', 'http');
    if ($eb_code !== 200) { http_response_code($eb_code); }
    echo $eb_antwort;
    exit;
}

$eb_stand = eb_stand();

if ($eb_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_encode($eb_stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        /* json_encode gibt bei ungueltigem UTF-8 false zurueck. Ungeprueft
         * waere die Antwort eine leere Seite mit Status 200 - und eine leere
         * Antwort mit Erfolgsmeldung ist das Schlechteste, was eine
         * Schnittstelle liefern kann: die Gegenstelle haelt sie fuer gueltig. */
        http_response_code(500);
        echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
        exit;
    }
    echo $j;
    exit;
}

echo eb_zeile($eb_stand);
