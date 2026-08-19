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

$eb_cfg = eb_config();
$eb_soll = (string) $eb_cfg['aktionstoken'];
$eb_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';

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
if (!in_array($eb_aktion, array('status', 'json', 'ein', 'stufe', 'selftest'), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: status, json, ein, stufe\n";
    exit;
}

if ($eb_aktion === 'ein') {
    $roh = isset($_GET['wert']) ? trim((string) $_GET['wert']) : '';
    /* Abweisen statt zurechtbiegen: (int)"aus" waere 0, und die Regelung
     * ginge auf einen Tippfehler hin aus, ohne dass jemand es merkt. */
    if (!in_array($roh, array('0', '1'), true)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=WERT\n";
        echo "wert muss 0 oder 1 sein, empfangen wurde: " . substr($roh, 0, 40) . "\n";
        exit;
    }
    $neu = ($roh === '1') ? 1 : 0;
    /* Dieselbe Pruefung wie in der Oberflaeche. Bis 0.9.4 verweigerte die
     * Oberflaeche das Einschalten bei Maengeln, der Endpunkt schaltete
     * kommentarlos ein - und Loxone bekam EIN=1 fuer eine Regelung, die
     * nichts regeln kann. Fail closed: im Zweifel nicht einschalten. */
    if ($neu === 1) {
        $eb_mangel = eb_maengel($eb_cfg);
        if ($eb_mangel) {
            http_response_code(409);
            echo "FEHLER;OK=0;GRUND=MAENGEL;EIN=" . (int) $eb_cfg['ein'] . "\n";
            foreach ($eb_mangel as $eb_k) { echo eb_klartext($eb_k) . "\n"; }
            exit;
        }
    }
    if ((int) $eb_cfg['ein'] !== $neu) {
        $eb_cfg['ein'] = $neu;
        if (!eb_config_speichern($eb_cfg)) {
            http_response_code(500);
            echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
            exit;
        }
        eb_log('Regelung ueber den Endpunkt ' . ($neu ? 'eingeschaltet' : 'ausgeschaltet') . '.');
    }
    /* Die Wirkung melden, nicht die Absicht: zurueckgelesen aus der Datei. */
    echo "EIN=" . (int) eb_config()['ein'] . "\n";
    exit;
}

/* ---- Die Zielstufe waehlen ----
 * Gewaehlt wird eine STUFE, nie ein Wert. Der Unterschied ist der ganze
 * Sicherheitsgedanke: das Wortzeichen steht offen in der Adresse, und wer
 * damit eine Zahl setzen koennte, koennte die Anlage abschalten. Eine
 * Stufe fuehrt hoechstens zu dem, was der Betreiber selbst eingetragen
 * hat - im schlimmsten Fall zur schaerfsten der drei. */
if ($eb_aktion === 'stufe') {
    $roh = isset($_GET['wert']) ? trim((string) $_GET['wert']) : '';
    if (!in_array($roh, array('0', '1', '2'), true)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=WERT\n";
        echo "wert muss 0, 1 oder 2 sein, empfangen wurde: " . substr($roh, 0, 40) . "\n";
        exit;
    }
    $neu = (int) $roh;
    if ((int) $eb_cfg['stufe'] !== $neu) {
        $eb_cfg['stufe'] = $neu;
        if (!eb_config_speichern($eb_cfg)) {
            http_response_code(500);
            echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
            exit;
        }
        eb_log('Zielstufe ueber den Endpunkt auf ' . $neu . ' gestellt.');
    }
    /* Die Wirkung melden, nicht die Absicht: zurueckgelesen aus der Datei. */
    $eb_frisch = eb_config();
    echo "STUFE=" . (int) $eb_frisch['stufe'] . ";ZIEL=" . (int) eb_ziel_w($eb_frisch) . "\n";
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
