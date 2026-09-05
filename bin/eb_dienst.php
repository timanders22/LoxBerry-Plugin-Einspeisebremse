<?php
/**
 * Einspeisebremse - der Regeldienst
 *
 * Hier steht alles, was zieht und schaltet. Gerechnet wird nichts: der
 * Entschluss faellt in eb_regeln() (eb_regel.php), und der Teil laesst sich
 * ohne Anlage pruefen.
 *
 * Aufruf:
 *   php eb_dienst.php               Dauerbetrieb
 *   php eb_dienst.php --einmal      ein Durchlauf im Vordergrund
 *   php eb_dienst.php --selbsttest  nur der Rechenkern
 *   php eb_dienst.php --probe       Messwerte einmal lesen und zeigen
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$eb_hier = __DIR__;
$eb_lib = '';
foreach (array(
    $eb_hier . '/../../../webfrontend/html/plugins/einspeisebremse/eb_lib.php',
    $eb_hier . '/../webfrontend/html/eb_lib.php',
    $eb_hier . '/eb_lib.php',
) as $eb_k) {
    if (is_file($eb_k)) { $eb_lib = $eb_k; break; }
}
if ($eb_lib === '') {
    /* Den Weg zur Bibliothek nicht ausrechnen, sondern suchen - im Archiv
     * liegt sie anders als installiert. Und wenn sie fehlt, wird das GESAGT:
     * ein Dienst, der still nicht startet, ist bei einer Regelung das
     * Schlimmste, was passieren kann. */
    fwrite(STDERR, "eb_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.\n");
    exit(1);
}
require_once $eb_lib;

$eb_argv = isset($argv) ? $argv : array();
$eb_hat = function ($x) use ($eb_argv) { return in_array($x, $eb_argv, true); };

if ($eb_hat('--selbsttest')) {
    list($n, $f) = eb_selbsttest(true);
    exit($f === 0 ? 0 : 1);
}

/* ==================================================================
 * MQTT lesen - ein dauerhafter Zuhoerer, kein Prozess je Abfrage
 *
 * mosquitto_sub bei jedem Durchlauf neu zu starten waere bei einem Takt
 * von fuenf Sekunden Unfug: der Prozessstart kostet mehr als die Messung,
 * und retained-lose Themen liefern in der kurzen Wartezeit gar nichts.
 * Also laeuft EIN Zuhoerer mit, und der Dienst liest ab, was inzwischen
 * hereingekommen ist.
 * ================================================================== */

$eb_hoerer = null;      // Prozesskennung
$eb_rohr = null;        // Leseende
$eb_werte = array();    // thema => array(text, zeit)
$eb_rest = '';

function eb_broker()
{
    $p = eb_paths();
    $gen = eb_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    $hol = function ($a, $b) use ($m) {
        if (isset($m[$a])) { return (string) $m[$a]; }
        return isset($m[$b]) ? (string) $m[$b] : '';
    };
    $host = $hol('Brokerhost', 'brokerhost');
    $port = (int) $hol('Brokerport', 'brokerport');
    return array(
        'host' => $host !== '' ? $host : 'localhost',
        'port' => $port > 0 ? $port : 1883,
        'user' => $hol('Brokeruser', 'brokeruser'),
        'pass' => $hol('Brokerpass', 'brokerpass'),
    );
}

function eb_themen($cfg)
{
    $t = array();
    /* q_netz2 fehlte hier bis 0.9.8: ein Ersatzzaehler ueber MQTT wurde nie
     * abonniert und lieferte deshalb NIE einen Wert - stillschweigend. Ein
     * Ersatzweg, der nicht traegt, ist schlimmer als keiner. Seither kommt
     * die Liste aus eb_quellenfelder() und kann nicht mehr abweichen. */
    foreach (array_keys(eb_quellenfelder()) as $k) {
        if ($cfg[$k]['art'] === 'mqtt' && $cfg[$k]['adresse'] !== '') {
            $t[$cfg[$k]['adresse']] = 1;
        }
    }
    return array_keys($t);
}

function eb_hoerer_starten($cfg)
{
    global $eb_hoerer, $eb_rohr, $eb_rest;
    eb_hoerer_beenden();
    $themen = eb_themen($cfg);
    if (!$themen) { return true; }          // nichts zu hoeren ist kein Fehler

    $b = eb_broker();
    $argv = array('mosquitto_sub', '-h', $b['host'], '-p', (string) $b['port'], '-v', '-q', '1');
    if ($b['user'] !== '') { $argv[] = '-u'; $argv[] = $b['user']; }
    if ($b['pass'] !== '') { $argv[] = '-P'; $argv[] = $b['pass']; }
    foreach ($themen as $th) { $argv[] = '-t'; $argv[] = $th; }

    /* Als Argumentliste, nicht als Zeichenkette: so kann ein Thema mit
     * Sonderzeichen kein zweiter Befehl werden. Die Zugangsdaten stehen
     * damit zwar in der Prozessliste - das ist bei mosquitto_sub nicht zu
     * vermeiden und gilt fuer jedes Werkzeug, das sie als Argument nimmt. */
    $befehl = implode(' ', array_map('escapeshellarg', $argv));
    $rohre = array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a'));
    $ph = @proc_open($befehl, $rohre, $pipes);
    if (!is_resource($ph)) {
        eb_log('MQTT: mosquitto_sub konnte nicht gestartet werden.');
        return false;
    }
    stream_set_blocking($pipes[1], false);
    $eb_hoerer = $ph;
    $eb_rohr = $pipes[1];
    $eb_rest = '';
    eb_log('MQTT: Zuhoerer gestartet fuer ' . count($themen) . ' Thema/Themen.');
    return true;
}

function eb_hoerer_beenden()
{
    global $eb_hoerer, $eb_rohr;
    if (is_resource($eb_rohr)) { @fclose($eb_rohr); }
    if (is_resource($eb_hoerer)) { @proc_terminate($eb_hoerer); @proc_close($eb_hoerer); }
    $eb_rohr = null;
    $eb_hoerer = null;
}

function eb_hoerer_lebt()
{
    global $eb_hoerer;
    if (!is_resource($eb_hoerer)) { return false; }
    $s = @proc_get_status($eb_hoerer);
    return is_array($s) && !empty($s['running']);
}

/** Alles abholen, was inzwischen hereinkam. Blockiert nicht. */
function eb_hoerer_abholen()
{
    global $eb_rohr, $eb_werte, $eb_rest;
    if (!is_resource($eb_rohr)) { return; }
    $neu = @stream_get_contents($eb_rohr);
    if ($neu === false || $neu === '') { return; }
    $eb_rest .= $neu;
    $zeilen = explode("\n", $eb_rest);
    // Die letzte Zeile kann angeschnitten sein - sie wartet auf den Rest.
    $eb_rest = array_pop($zeilen);
    foreach ($zeilen as $z) {
        $z = rtrim($z, "\r");
        if ($z === '') { continue; }
        $pos = strpos($z, ' ');
        if ($pos === false) { continue; }
        $thema = substr($z, 0, $pos);
        $wert = substr($z, $pos + 1);
        $eb_werte[$thema] = array($wert, microtime(true));
    }
}

/* ==================================================================
 * Messwerte
 * ================================================================== */

/** Einen Wert aus einer JSON-Struktur holen: a.b.0.c */
function eb_json_pfad($daten, $pfad)
{
    if ($pfad === '') { return $daten; }
    foreach (explode('.', $pfad) as $teil) {
        if (is_array($daten) && array_key_exists($teil, $daten)) { $daten = $daten[$teil]; }
        else { return null; }
    }
    return $daten;
}

/**
 * Eine Adresse abrufen. $status nimmt den HTTP-Code auf.
 *
 * ignore_errors liefert auch bei 404 und 500 einen Rumpf statt false. Wer
 * nur auf false prueft, verbucht die Abweisung eines Geraets als
 * abgesetzten Befehl - gemessen am 18.08.2026 am laufenden Dienst: ein
 * Wechselrichter, der mit 404 antwortete, meldete dem Miniserver S1OK=1.
 * Der Code steht in $http_response_header und wird jetzt gelesen.
 */
/* Die Antworten eines Durchlaufs. Ein Fronius Symo liefert Netzleistung,
 * Erzeugung, Ladestand und Ladeleistung aus DERSELBEN Adresse - ohne
 * Zwischenspeicher waeren das vier Abrufe je Takt an dasselbe Geraet, bei
 * Takt 5 s also 2880 in der Stunde. Gespeichert wird nur, was GELESEN
 * wird; ein Stellbefehl geht immer hinaus, auch wenn er gleich lautet. */
$eb_http_zwischen = array();

function eb_http_frisch()
{
    global $eb_http_zwischen;
    $eb_http_zwischen = array();
}

function eb_http_holen($url, $zeit = 5, &$status = null, $merken = false)
{
    global $eb_http_zwischen;
    if ($merken && isset($eb_http_zwischen[$url])) {
        $status = $eb_http_zwischen[$url][1];
        return $eb_http_zwischen[$url][0];
    }
    $status = 0;
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $zeit, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\n")));
    $t = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $zeile) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $zeile, $tr)) {
                $status = (int) $tr[1];
            }
        }
    }
    $erg = ($t === false) ? null : $t;
    if ($merken) { $eb_http_zwischen[$url] = array($erg, $status); }
    return $erg;
}

/** 2xx ist Erfolg, alles andere nicht. 0 heisst: kein Code erkennbar. */
function eb_http_gut($status)
{
    return ($status === 0 || ($status >= 200 && $status <= 299));
}

/**
 * Ein Register ueber Modbus TCP lesen. Rueckgabe: array(wert|null, anlass).
 *
 * Ueber stream_socket_client, NICHT ueber socket_create: die Erweiterung
 * php-sockets ist nicht garantiert geladen, und ein fehlendes
 * socket_create() ist kein abfangbarer Fehler, sondern ein fataler - im
 * Cron, der nach /dev/null schreibt, sieht das niemand.
 *
 * Der Rahmen: 2 Byte Vorgangsnummer, 2 Byte Protokoll (0), 2 Byte Laenge,
 * 1 Byte Geraeteadresse, dann Funktionscode, Startregister, Anzahl.
 */
function eb_modbus_lesen($adresse, $zeit = 4)
{
    $a = eb_modbus_zerlegen($adresse);
    if ($a === null) { return array(null, 'adresse_unlesbar'); }
    $anzahl = in_array($a['typ'], array('int16', 'uint16'), true) ? 1 : 2;

    $fp = @stream_socket_client('tcp://' . $a['host'] . ':' . $a['port'],
                                $eno, $estr, $zeit);
    if (!$fp) { return array(null, 'unerreichbar'); }
    stream_set_timeout($fp, $zeit);

    $vorgang = 1;
    $pdu = pack('Cnn', $a['fc'], $a['reg'], $anzahl);
    $rahmen = pack('nnn', $vorgang, 0, strlen($pdu) + 1) . chr($a['id']) . $pdu;
    if (@fwrite($fp, $rahmen) === false) { @fclose($fp); return array(null, 'senden'); }

    $kopf = '';
    while (strlen($kopf) < 8) {
        $st = @fread($fp, 8 - strlen($kopf));
        if ($st === false || $st === '') { break; }
        $kopf .= $st;
    }
    if (strlen($kopf) < 8) { @fclose($fp); return array(null, 'keine_antwort'); }
    $k = unpack('nvorgang/nproto/nlaenge/Cid/Cfc', $kopf);
    if (($k['fc'] & 0x80) !== 0) {
        $fehler = @fread($fp, 1);
        @fclose($fp);
        return array(null, 'modbus_fehler_' . ($fehler === false || $fehler === ''
                                               ? '?' : ord($fehler)));
    }
    if ($k['fc'] !== $a['fc']) { @fclose($fp); return array(null, 'falsche_antwort'); }
    $anz = @fread($fp, 1);
    if ($anz === false || $anz === '') { @fclose($fp); return array(null, 'keine_antwort'); }
    $n = ord($anz);
    $daten = '';
    while (strlen($daten) < $n) {
        $st = @fread($fp, $n - strlen($daten));
        if ($st === false || $st === '') { break; }
        $daten .= $st;
    }
    @fclose($fp);
    if (strlen($daten) < $n || $n < 2) { return array(null, 'zu_kurz'); }

    /* Hohes Wort zuerst - so liefert es der SDM630 und die meisten Zaehler. */
    switch ($a['typ']) {
        case 'float32':
            $v = unpack('G', substr($daten, 0, 4));
            $wert = $v[1];
            if (!is_finite($wert)) { return array(null, 'unlesbar'); }
            break;
        case 'uint32':
            $v = unpack('N', substr($daten, 0, 4)); $wert = $v[1]; break;
        case 'int32':
            $v = unpack('N', substr($daten, 0, 4));
            $wert = ($v[1] >= 2147483648) ? $v[1] - 4294967296 : $v[1];
            break;
        case 'uint16':
            $v = unpack('n', substr($daten, 0, 2)); $wert = $v[1]; break;
        default:
            $v = unpack('n', substr($daten, 0, 2));
            $wert = ($v[1] >= 32768) ? $v[1] - 65536 : $v[1];
    }
    return array((float) $wert, 'gut');
}

/* ==================================================================
 * Modbus schreiben - der SunSpec-Stellweg
 *
 * Die Rahmenbehandlung steht hier ein zweites Mal, neben der in
 * eb_modbus_lesen(). Das ist Absicht: die Lesefunktion ist im Betrieb
 * bewaehrt, oeffnet ihre Verbindung selbst und holt genau einen Wert. Der
 * Stellweg braucht das Gegenteil - EINE Verbindung fuer eine ganze Folge,
 * weil Kette, Faktor und die drei Schreibvorgaenge zusammengehoeren. Wer
 * beides in eine Funktion zwaengt, verliert entweder die Bewaehrung oder
 * die Folge. Verlangt hier etwas eine Aenderung, gilt sie fuer beide
 * Stellen - das ist der Preis, und er steht hier, damit ihn niemand
 * uebersieht.
 * ================================================================== */

/**
 * Ein Modbus-Gespraech auf einer schon offenen Verbindung.
 *
 * Rueckgabe: array(daten|null, anlass) - ohne MBAP-Kopf, ohne
 * Geraeteadresse, ohne Funktionscode.
 */
function eb_modbus_austausch($fp, $id, $fc, $rumpf, &$vorgang)
{
    $vorgang = ($vorgang % 65535) + 1;
    $pdu = chr($fc) . $rumpf;
    $rahmen = pack('nnn', $vorgang, 0, strlen($pdu) + 1) . chr($id) . $pdu;
    if (@fwrite($fp, $rahmen) === false) { return array(null, 'senden'); }

    $kopf = '';
    while (strlen($kopf) < 8) {
        $st = @fread($fp, 8 - strlen($kopf));
        if ($st === false || $st === '') { break; }
        $kopf .= $st;
    }
    if (strlen($kopf) < 8) { return array(null, 'keine_antwort'); }
    $k = unpack('nvorgang/nproto/nlaenge/Cid/Cfc', $kopf);
    if (($k['fc'] & 0x80) !== 0) {
        $f = @fread($fp, 1);
        return array(null, 'modbus_fehler_' . ($f === false || $f === '' ? '?' : ord($f)));
    }
    if ($k['fc'] !== $fc) { return array(null, 'falsche_antwort'); }
    /* Die Laenge im Kopf zaehlt Geraeteadresse und Funktionscode mit; die
     * beiden sind schon gelesen. */
    $offen = (int) $k['laenge'] - 2;
    if ($offen < 0 || $offen > 260) { return array(null, 'laenge_unglaubhaft'); }
    $daten = '';
    while (strlen($daten) < $offen) {
        $st = @fread($fp, $offen - strlen($daten));
        if ($st === false || $st === '') { break; }
        $daten .= $st;
    }
    if (strlen($daten) < $offen) { return array(null, 'zu_kurz'); }
    return array($daten, 'gut');
}

/** Register lesen (FC3). Rueckgabe: array(liste|null, anlass). */
function eb_modbus_regs_lesen($fp, $id, $reg, $anzahl, &$vorgang)
{
    list($d, $anl) = eb_modbus_austausch($fp, $id, 3, pack('nn', $reg, $anzahl), $vorgang);
    if ($d === null) { return array(null, $anl); }
    $n = strlen($d) > 0 ? ord(substr($d, 0, 1)) : 0;
    if ($n !== $anzahl * 2 || strlen($d) < 1 + $n) { return array(null, 'zu_kurz'); }
    $w = array();
    for ($i = 0; $i < $anzahl; $i++) {
        $v = unpack('n', substr($d, 1 + $i * 2, 2));
        $w[] = (int) $v[1];
    }
    return array($w, 'gut');
}

/**
 * Ein einzelnes Register schreiben (FC6). Rueckgabe: array(1/0, anlass).
 *
 * Bewusst FC6 und bewusst nur EIN Register. FC16 waere kuerzer, aber ein
 * Versatz um eine Stelle traefe damit gleich mehrere Register auf einmal,
 * und ein Versatz ist bei diesem Geraet die teuerste Fehlerklasse
 * ueberhaupt. Der Wechselrichter spiegelt bei FC6 Adresse und Wert
 * zurueck; das wird geprueft, statt der Quittung zu glauben.
 */
function eb_modbus_reg_schreiben($fp, $id, $reg, $wert, &$vorgang)
{
    $w = (int) $wert;
    if ($w < 0 || $w > 65535) { return array(0, 'wert_ausserhalb'); }
    list($d, $anl) = eb_modbus_austausch($fp, $id, 6, pack('nn', $reg, $w), $vorgang);
    if ($d === null) { return array(0, $anl); }
    if (strlen($d) < 4) { return array(0, 'zu_kurz'); }
    $e = unpack('nreg/nwert', substr($d, 0, 4));
    if ((int) $e['reg'] !== (int) $reg || (int) $e['wert'] !== $w) {
        return array(0, 'echo_weicht_ab');
    }
    return array(1, 'gut');
}

/**
 * Ein SunSpec-Modell in der Kette suchen. Rueckgabe: array(adresse|null, anlass).
 *
 * Es wird nichts gerechnet und nichts aus einer Tabelle genommen: ab der
 * Basis Kennung und Laenge lesen, um Laenge+2 weiterspringen, wiederholen.
 * Die festen Startadressen im Handbuch gelten nur fuer eine bestimmte
 * Geraetezusammenstellung - die Kette gilt immer. Gemessen am 19.08.2026
 * an einem Symo Hybrid: 40000 SunS, 40002 Modell 1, 40069 Modell 113,
 * 40131 Modell 120, 40159 Modell 121, 40191 Modell 122, 40237 Modell 123.
 */
function eb_sunspec_modell($fp, $id, $basis, $modell, &$vorgang)
{
    list($m, $anl) = eb_modbus_regs_lesen($fp, $id, $basis, 2, $vorgang);
    if ($m === null) { return array(null, $anl); }
    /* "SunS". Fehlt die Marke, ist die Basis falsch - dann wird nicht
     * weitergesucht, sondern abgebrochen. Eine Kette, die an der falschen
     * Stelle beginnt, findet irgendwann irgendetwas. */
    if ($m[0] !== 0x5375 || $m[1] !== 0x6E53) { return array(null, 'keine_sunspec_marke'); }

    $zeiger = $basis + 2;
    for ($i = 0; $i < 40; $i++) {
        list($k, $anl) = eb_modbus_regs_lesen($fp, $id, $zeiger, 2, $vorgang);
        if ($k === null) { return array(null, $anl); }
        if ($k[0] === 0xFFFF) { return array(null, 'modell_nicht_in_der_kette'); }
        if ($k[0] === (int) $modell) { return array($zeiger, 'gut'); }
        $zeiger += 2 + $k[1];
        if ($zeiger > 65530) { return array(null, 'kette_laeuft_aus'); }
    }
    return array(null, 'kette_zu_lang');
}

/**
 * Ein SunSpec-Stellglied stellen. Rueckgabe: array(1/0, Klartext).
 *
 * Die Folge steht so im Fronius-Handbuch und wurde am 19.08.2026 an einem
 * Symo Hybrid nachgemessen (Modell 123 bei 40237, Faktor -2):
 *   1. Modell 123 in der Kette suchen
 *   2. WMaxLimPct_SF lesen - der Faktor wird NICHT angenommen
 *   3. Grenze -> Prozent -> Rohwert
 *   4. WMaxLimPct         schreiben  (Offset  6, also Kennung + 5)
 *   5. WMaxLimPct_RvrtTms schreiben  (Offset  8, also Kennung + 7)
 *   6. WMaxLim_Ena        schreiben  (Offset 10, also Kennung + 9)
 *
 * Schritt 6 kommt zuletzt, und er kommt jedes Mal: das Handbuch sagt
 * ausdruecklich, dass eine Aenderung bei laufender Betriebsart erst
 * greift, wenn danach erneut 1 nach WMaxLim_Ena geschrieben wird.
 *
 * Bei voller Freigabe wird die Betriebsart BEENDET (0 nach WMaxLim_Ena)
 * statt auf hundert Prozent gestellt. Eine beendete Drosselung ist etwas
 * anderes als eine Drosselung auf hundert Prozent: nur bei der ersten
 * sieht man am Geraet, dass niemand mehr eingreift.
 *
 * WICHTIG: Die Rueckgabe sagt nur, dass der Wechselrichter die Register
 * angenommen hat. Ob er auch drosselt, entscheidet eb_wirkung() am
 * Zaehler. Ist im Datamanager "Wechselrichter-Steuerung ueber Modbus"
 * nicht eingeschaltet - oder ist "Steuerung einschraenken" aktiv und der
 * LoxBerry steht nicht in der Liste -, quittiert das Geraet freundlich
 * und tut nichts. Genau dafuer ist die Wirkungspruefung da.
 */
function eb_sunspec_stellen($s, $watt)
{
    $a = eb_sunspec_zerlegen($s['adresse']);
    if ($a === null) { return array(0, 'SunSpec: Adresse passt nicht auf das Muster'); }

    $eno = 0; $estr = '';
    $fp = @stream_socket_client('tcp://' . $a['host'] . ':' . $a['port'], $eno, $estr, 4);
    if (!$fp) { return array(0, 'SunSpec ' . $a['host'] . ':' . $a['port'] . ' - unerreichbar'); }
    stream_set_timeout($fp, 4);
    $v = 0;
    $kurz = 'SunSpec ' . $a['host'] . ' ';

    list($start, $anl) = eb_sunspec_modell($fp, $a['id'], $a['basis'], 123, $v);
    if ($start === null) { @fclose($fp); return array(0, $kurz . '- Modell 123: ' . $anl); }

    list($sfr, $anl) = eb_modbus_regs_lesen($fp, $a['id'], $start + 23, 1, $v);
    if ($sfr === null) { @fclose($fp); return array(0, $kurz . '- Faktor nicht lesbar: ' . $anl); }
    $sf = ($sfr[0] >= 32768) ? $sfr[0] - 65536 : $sfr[0];

    list($roh, $anl) = eb_sunspec_roh($watt, $s['spitze_w'], $sf);
    if ($roh === null) { @fclose($fp); return array(0, $kurz . '- ' . $anl); }

    $voll = (int) round(100.0 * pow(10, -$sf));
    if ($roh >= $voll) {
        list($ok, $anl) = eb_modbus_reg_schreiben($fp, $a['id'], $start + 9, 0, $v);
        @fclose($fp);
        return $ok
            ? array(1, $kurz . '- Drosselung beendet (Modell 123 bei ' . $start . ')')
            : array(0, $kurz . '- WMaxLim_Ena nicht geschrieben: ' . $anl);
    }

    $folge = array(
        array($start + 5, $roh,              'WMaxLimPct'),
        array($start + 7, $a['rueckfall_s'], 'WMaxLimPct_RvrtTms'),
        array($start + 9, 1,                 'WMaxLim_Ena'),
    );
    foreach ($folge as $t) {
        list($ok, $anl) = eb_modbus_reg_schreiben($fp, $a['id'], $t[0], $t[1], $v);
        if (!$ok) {
            @fclose($fp);
            return array(0, $kurz . '- ' . $t[2] . ' (' . $t[0] . ') nicht geschrieben: ' . $anl);
        }
    }
    @fclose($fp);
    return array(1, sprintf('%s- %d W = %s %% (roh %d) an Modell 123 bei %d, Rueckfall %d s',
                            $kurz, (int) round($watt),
                            rtrim(rtrim(number_format($roh * pow(10, $sf), 2, '.', ''), '0'), '.'),
                            $roh, $start, $a['rueckfall_s']));
}

/**
 * Eine Quelle lesen. Rueckgabe: array(wert|null, alter_s|-1, anlass).
 *
 * Der Anlass wird durchgereicht und nicht verschluckt. Ein Zaehler, der
 * "n/a" liefert, ist etwas anderes als einer, der gar nicht antwortet -
 * und beides ist etwas anderes als eine Null.
 */
function eb_quelle_lesen($q)
{
    global $eb_werte;
    if ($q['art'] === 'aus' || $q['adresse'] === '') { return array(null, -1.0, 'aus'); }

    $roh = null;
    $alter = -1.0;
    if ($q['art'] === 'mqtt') {
        if (!isset($eb_werte[$q['adresse']])) { return array(null, -1.0, 'nichts_empfangen'); }
        list($text, $ts) = $eb_werte[$q['adresse']];
        $alter = max(0.0, microtime(true) - $ts);
        $roh = $text;
    } elseif ($q['art'] === 'modbus') {
        /* Ein Register liefert eine Zahl, kein JSON. Ein stehengebliebener
         * Pfad - etwa aus einer zuvor angewendeten Vorlage - wird deshalb
         * GEMELDET und nicht stillschweigend uebergangen. */
        if ($q['pfad'] !== '') { return array(null, -1.0, 'pfad_gehoert_nicht_zu_modbus'); }
        list($mw, $manlass) = eb_modbus_lesen($q['adresse']);
        if ($mw === null) { return array(null, -1.0, 'modbus_' . $manlass); }
        $alter = 0.0;
        $roh = $mw;
    } else {
        $st = 0;
        $text = eb_http_holen($q['adresse'], 5, $st, true);
        if ($text === null) { return array(null, -1.0, 'http_unerreichbar'); }
        // Ein Gateway, das 404 oder 500 antwortet, hat keinen Messwert
        // geliefert - auch wenn ein Rumpf zurueckkam.
        if (!eb_http_gut($st)) { return array(null, -1.0, 'http_' . $st); }
        $alter = 0.0;
        $roh = $text;
    }

    if ($q['pfad'] !== '') {
        $d = json_decode((string) $roh, true);
        if (!is_array($d)) { return array(null, $alter, 'kein_json'); }
        $roh = eb_json_pfad($d, $q['pfad']);
        if ($roh === null) { return array(null, $alter, 'pfad_leer'); }
    }

    list($taugt, $anlass) = eb_messwert_taugt($roh);
    if (!$taugt) { return array(null, $alter, $anlass); }
    $wert = eb_zahl($roh, 0.0) * eb_zahl($q['faktor'], 1.0);
    if (!empty($q['invertieren'])) { $wert = -$wert; }
    return array($wert, $alter, 'gut');
}

/* ==================================================================
 * Stellglieder
 * ================================================================== */

function eb_mqtt_wert_saeubern_d($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Ein Thema senden. $retained laesst den Wert im Broker stehen, damit ein
 * spaeter hinzukommender Abonnent sofort etwas sieht statt zu warten.
 *
 * Einen echten Letzten Willen gibt es hier nicht: mosquitto_pub baut je
 * Aufruf eine eigene Verbindung auf und wieder ab, und ein Letzter Wille
 * haengt an einer stehenden Verbindung. Stattdessen wird online=1 in jedem
 * Durchlauf gesendet und beim geordneten Beenden einmal online=0. Wer den
 * Absturzfall braucht, sieht auf ALTER - das steigt dann weiter.
 */
function eb_mqtt_veroeffentlichen($thema, $wert, $retained = true)
{
    $b = eb_broker();
    $argv = array('mosquitto_pub', '-h', $b['host'], '-p', (string) $b['port'],
                  '-t', $thema, '-m', eb_mqtt_wert_saeubern_d($wert));
    if ($retained) { $argv[] = '-r'; }
    if ($b['user'] !== '') { $argv[] = '-u'; $argv[] = $b['user']; }
    if ($b['pass'] !== '') { $argv[] = '-P'; $argv[] = $b['pass']; }
    $befehl = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
    @exec($befehl, $aus, $rc);
    return $rc === 0;
}

/**
 * Einen Stellwert abgeben. Rueckgabe: array(1/0, Beschreibung).
 *
 * Die Rueckgabe sagt nur, ob der Befehl ABGESETZT wurde. Ob er auch
 * gewirkt hat, entscheidet spaeter eb_wirkung() am Zaehler - eine Quittung
 * ist keine Wirkung.
 */
function eb_stellen($s, $watt)
{
    /* Der SunSpec-Weg rechnet selbst und braucht keine Platzhalter: die
     * Prozentzahl entsteht aus der Grenze und der Nennleistung, der
     * Skalierungsfaktor kommt aus dem Geraet. Deshalb steht er VOR
     * eb_befehl_bauen(), das nur Text ersetzt. */
    if ($s['art'] === 'sunspec') { return eb_sunspec_stellen($s, $watt); }

    list($adresse, $inhalt, $ers) = eb_befehl_bauen($s, $watt);
    if ($s['einheit'] === 'Prozent' && $ers['{PROZENT}'] === '') {
        return array(0, 'Prozent verlangt eine Spitzenleistung; es ist keine eingetragen.');
    }
    if ($adresse === '') { return array(0, 'keine Adresse'); }

    if ($s['art'] === 'mqtt') {
        $ok = eb_mqtt_veroeffentlichen($adresse, $inhalt !== '' ? $inhalt : $ers['{W}']);
        return array($ok ? 1 : 0, 'mqtt ' . $adresse . ' = ' . ($inhalt !== '' ? $inhalt : $ers['{W}']));
    }
    if ($s['art'] === 'http_get') {
        $st = 0;
        $t = eb_http_holen($adresse, 8, $st);
        if ($t === null) { return array(0, 'GET ' . $adresse . ' - keine Antwort'); }
        if (!eb_http_gut($st)) {
            return array(0, 'GET ' . $adresse . ' - abgewiesen mit HTTP ' . $st);
        }
        return array(1, 'GET ' . $adresse . ($st ? ' - HTTP ' . $st : ''));
    }
    if ($s['art'] === 'http_post') {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => $inhalt)));
        $t = @file_get_contents($adresse, false, $ctx);
        $st = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $zeile) {
                if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $zeile, $tr)) { $st = (int) $tr[1]; }
            }
        }
        $kurz = 'POST ' . $adresse . ' ' . substr($inhalt, 0, 80);
        if ($t === false) { return array(0, $kurz . ' - keine Antwort'); }
        if (!eb_http_gut($st)) { return array(0, $kurz . ' - abgewiesen mit HTTP ' . $st); }
        return array(1, $kurz . ($st ? ' - HTTP ' . $st : ''));
    }
    return array(0, 'Art ' . $s['art'] . ' ist nicht vorgesehen');
}

/* ==================================================================
 * Ein Durchlauf
 * ================================================================== */

function eb_durchlauf($cfg, $stand)
{
    $jetzt = microtime(true);
    // Jeder Durchlauf fragt frisch - der Zwischenspeicher gilt nur innerhalb.
    eb_http_frisch();

    list($netz, $netz_alter, $netz_anlass) = eb_quelle_lesen($cfg['q_netz']);
    /* Die Erzeugung darf aus MEHREREN Quellen kommen und wird addiert - eine
     * Anlage mit zwei Wechselrichtern ist keine halbe Anlage. Faellt ein
     * eingetragener Summand aus, liefert eb_erzeugung_summe() null statt
     * einer Teilsumme; warum, steht dort. */
    $eb_teile = array();
    foreach (eb_erzeugungsfelder() as $eb_qk) {
        $eb_teile[] = eb_quelle_lesen($cfg[$eb_qk]);
    }
    list($erz, $erz_alter, $erz_anlass) = eb_erzeugung_summe($eb_teile);
    list($soc, $soc_alter, $soc_anlass) = eb_quelle_lesen($cfg['q_soc']);
    list($lade, $lade_alter, $lade_anlass) = eb_quelle_lesen($cfg['q_lade']);

    /* ---- Altersgrenze fuer die Nebenquellen ----
     * Bis 0.9.4 hatte nur der Netzzaehler eine. Ein retained MQTT-Thema
     * liefert nach einem Geraeteausfall stundenlang denselben Wert, und fuer
     * den Kern war er frisch. Beim Ladestand ist das unmittelbar
     * gefaehrlich: ein alter Wert von 40 Prozent laesst den Speicher als
     * aufnahmefaehig erscheinen, und dann wird nicht abgeregelt. */
    $agrenze = max(10.0, eb_zahl($cfg['quelle_alter_s'], 300.0));
    if ($erz !== null && $erz_alter >= 0.0 && $erz_alter > $agrenze) { $erz = null; $erz_anlass = 'zu_alt'; }
    if ($soc !== null && $soc_alter >= 0.0 && $soc_alter > $agrenze) { $soc = null; $soc_anlass = 'zu_alt'; }
    if ($lade !== null && $lade_alter >= 0.0 && $lade_alter > $agrenze) { $lade = null; $lade_anlass = 'zu_alt'; }

    /* ---- Der Ersatzzaehler ----
     * Er wird gezogen, BEVOR der Notbetrieb greift - und er wird
     * angezeigt. Ein Ersatzweg, den niemand sieht, wird unbemerkt zum
     * Normalfall; genau davor warnt der Hausstandard. */
    $ersatz = 0;
    if ($cfg['q_netz2']['art'] !== 'aus'
        && ($netz === null || ($netz_alter >= 0.0 && $netz_alter > (float) $cfg['notfall_s']))) {
        list($n2, $a2, $an2) = eb_quelle_lesen($cfg['q_netz2']);
        if ($n2 !== null && ($a2 < 0.0 || $a2 <= (float) $cfg['notfall_s'])) {
            $netz = $n2;
            $netz_alter = $a2;
            $netz_anlass = 'ersatz_' . $an2;
            $ersatz = 1;
        }
    }

    /* ---- Die gewaehlte Zielstufe ----
     * Der Kern kennt nur ein ziel_w. Welche der drei eingetragenen Stufen
     * das gerade ist, entscheidet sich hier - und ausschliesslich aus der
     * oertlichen Konfiguration. */
    $cfg['ziel_w'] = eb_ziel_w($cfg);

    $steller = eb_steller();
    $sp_st = eb_speicher_steller();

    $mess = array(
        'netz'      => $netz,
        /* NULL heisst "nicht gemessen" und NICHT 0. Der Kern setzt dann die
         * zuletzt gestellte Grenze an die Stelle der Erzeugung; mit einer 0
         * fuhr sich die Regelung dauerhaft zu tief fest. */
        'erzeugung' => $erz,
        'soc'       => $soc === null ? -1 : $soc,
        'lade_ist'  => $lade === null ? 0 : $lade,
        /* Kein Wert heisst: unendlich alt. Nicht null - null hiesse
         * "gerade eben gemessen", und darauf wuerde die Regelung die
         * Erzeugung freigeben. */
        'alter_s'   => ($netz === null) ? -1 : $netz_alter,
    );

    $cfg['anlage_max_w'] = eb_anlage_max($steller);

    /* ---- Rampenstart beim Einschalten ----
     * Nach dem Ausschalten steht der Freigabewert im Zustand (Vorgabe
     * 100000). Wer die Regelung wieder einschaltet, rampte bis 0.9.4 von
     * dort herunter und stellte dabei vier Minuten lang Grenzen, die keine
     * sind - gemessen 98000 W im ersten Takt. */
    $war_aus = empty($stand) || empty($stand['ein']);
    $drossel_start = isset($stand['drossel_w']) ? $stand['drossel_w'] : null;
    if (!empty($cfg['ein']) && $war_aus) {
        $drossel_start = ($cfg['anlage_max_w'] > 0)
            ? (float) $cfg['anlage_max_w']
            : ($erz !== null ? max(0.0, eb_zahl($erz, 0.0)) : (float) $cfg['drossel_min_w']);
    }

    $zust = array(
        'lade_soll_w'   => isset($stand['lade_soll_w']) ? $stand['lade_soll_w'] : 0,
        'sp_probe_seit' => isset($stand['sp_probe_seit']) ? $stand['sp_probe_seit'] : 0,
        'sp_probe_lade' => isset($stand['sp_probe_lade']) ? $stand['sp_probe_lade'] : 0,
        'sp_sperre_bis' => isset($stand['sp_sperre_bis']) ? $stand['sp_sperre_bis'] : 0,
    );
    if ($drossel_start !== null) { $zust['drossel_w'] = $drossel_start; }

    $r = eb_regeln($mess, $cfg, $zust, $jetzt);

    $neu = array(
        'zeit'          => time(),
        'ein'           => $cfg['ein'],
        'netz'          => $netz,
        'netz_alter'    => ($netz === null) ? null : $netz_alter,
        'netz_anlass'   => $netz_anlass,
        'ersatz'        => $ersatz,
        'stufe'         => (int) $cfg['stufe'],
        'ziel_w'        => (int) $cfg['ziel_w'],
        'erzeugung'     => $erz,
        'erz_anlass'    => $erz_anlass,
        'soc'           => $soc,
        'soc_anlass'    => $soc_anlass,
        'lade_ist'      => $lade,
        'lade_anlass'   => $lade_anlass,
        'ueberschuss_w' => $r['ueberschuss_w'],
        'drossel_w'     => $r['drossel_w'],
        'lade_soll_w'   => $r['lade_soll_w'],
        'tat'           => $r['tat'],
        'anlass'        => $r['anlass'],
        'notfall'       => $r['notfall'],
        'erzeugung_ersatz' => $r['erzeugung_ersatz'],
        'speicher_folgt'   => $r['speicher_folgt'],
        'sp_probe_seit'    => $r['sp_probe_seit'],
        'sp_probe_lade'    => $r['sp_probe_lade'],
        'sp_sperre_bis'    => $r['sp_sperre_bis'],
        'anlage_max_w'  => $cfg['anlage_max_w'],
        'wirkung'       => isset($stand['wirkung']) ? $stand['wirkung'] : 0,
        'steller'       => isset($stand['steller']) ? $stand['steller'] : array(),
        'gestellt_w'    => isset($stand['gestellt_w']) ? $stand['gestellt_w'] : null,
        'sp_watt'       => isset($stand['sp_watt']) ? $stand['sp_watt'] : null,
        'sp_ok'         => isset($stand['sp_ok']) ? $stand['sp_ok'] : null,
        'sp_was'        => isset($stand['sp_was']) ? $stand['sp_was'] : '',
        'gestellt_um'   => isset($stand['gestellt_um']) ? $stand['gestellt_um'] : 0,
        'auffrisch_um'  => isset($stand['auffrisch_um']) ? (float) $stand['auffrisch_um'] : 0.0,
        'netz_vorher'   => isset($stand['netz_vorher']) ? $stand['netz_vorher'] : null,
        'grenze_vorher' => isset($stand['grenze_vorher']) ? $stand['grenze_vorher'] : null,
        'frei_versuch'  => isset($stand['frei_versuch']) ? (int) $stand['frei_versuch'] : 0,
        'frei_zuletzt'  => isset($stand['frei_zuletzt']) ? (float) $stand['frei_zuletzt'] : 0.0,
    );

    /* ---- Ausgeschaltet heisst ausgeschaltet ----
     * Dann wird gemessen und angezeigt, aber nicht mehr geregelt. Wer eine
     * Regelung einbaut, die auch im Zustand "aus" weiterschaltet, hat keine
     * Regelung gebaut, sondern eine Falle.
     *
     * EINMAL wird aber noch gestellt, naemlich die Freigabe. Sonst bliebe
     * die Anlage auf dem zuletzt gestellten Wert stehen - der Mensch
     * schaltet die Bremse aus und wundert sich wochenlang ueber den
     * fehlenden Ertrag, ohne dass irgendwo ein Fehler steht. */
    if (empty($cfg['ein'])) {
        $neu['anlass'] = 'aus';
        $neu['tat'] = EB_NICHTS;
        $neu['freigegeben'] = !empty($stand['freigegeben']) ? 1 : 0;
        /* Erreicht die Freigabe nicht alle Geraete, wird sie wiederholt -
         * aber nicht in jedem Takt. Bis 0.9.4 lief der Block bei einem
         * unerreichbaren Geraet alle fuenf Sekunden erneut, samt zwei
         * Protokollzeilen, dauerhaft. Der Abstand waechst jetzt bis auf
         * eine Stunde. */
        $abstand = min(3600.0, 60.0 * max(1, $neu['frei_versuch']));
        $faellig = ($neu['frei_zuletzt'] <= 0.0) || ($jetzt - $neu['frei_zuletzt'] >= $abstand);
        if (!$neu['freigegeben'] && $steller && $faellig) {
            $frei = (float) $cfg['frei_w'];
            $anteile = eb_aufteilen($frei, $steller);
            $neu['steller'] = array();
            $alle_ok = 1;
            $summe = 0.0;
            foreach ($steller as $nr => $s) {
                $watt = isset($anteile[$nr]) ? $anteile[$nr] : 0.0;
                list($ok, $was) = eb_stellen($s, $watt);
                $neu['steller'][(string) $nr] = array('name' => $s['name'], 'watt' => $watt,
                                                     'ok' => $ok, 'was' => $was);
                $summe += $watt;
                if (!$ok) { $alle_ok = 0; eb_log('Freigabe an ' . $s['name'] . ': ' . $was); }
            }
            /* Der Speicher wird beim Ausschalten ebenfalls freigegeben: sein
             * Sollwert geht auf 0, sonst laedt er weiter nach einer Vorgabe,
             * die niemand mehr pflegt. */
            if ($sp_st) {
                list($spok, $spwas) = eb_stellen($sp_st, 0.0);
                $neu['sp_watt'] = 0; $neu['sp_ok'] = $spok; $neu['sp_was'] = $spwas;
                if (!$spok) { $alle_ok = 0; eb_log('Freigabe an ' . $sp_st['name'] . ': ' . $spwas); }
            }
            $neu['drossel_w'] = $frei;
            $neu['gestellt_w'] = $summe;
            $neu['lade_soll_w'] = 0;
            $neu['frei_zuletzt'] = $jetzt;
            $neu['frei_versuch'] = $alle_ok ? 0 : ($neu['frei_versuch'] + 1);
            /* Nur als erledigt vermerken, wenn wirklich alle erreicht
             * wurden - sonst versucht es ein spaeterer Durchlauf erneut. */
            $neu['freigegeben'] = $alle_ok;
            eb_log($alle_ok
                ? sprintf('Regelung ausgeschaltet: Anlage auf %d W freigegeben.', (int) $frei)
                : sprintf('Regelung ausgeschaltet: die Freigabe hat nicht alle Stellglieder '
                        . 'erreicht (Versuch %d). Der naechste Versuch folgt in %d s.',
                          $neu['frei_versuch'], (int) min(3600.0, 60.0 * $neu['frei_versuch'])));
        }
        return $neu;
    }
    $neu['freigegeben'] = 0;
    $neu['frei_versuch'] = 0;
    $neu['frei_zuletzt'] = 0.0;

    if (!$steller) {
        $neu['anlass'] = 'kein_steller';
        return $neu;
    }

    /* ZWEI verschiedene Fragen, die bis 0.9.17 eine einzige waren:
     *
     *   $wert_neu  - hat sich der gestellte Wert wirklich geaendert?
     *   $aendert   - soll der Befehl (erneut) hinausgehen?
     *
     * Das zweite ist absichtlich weiter gefasst: bei DROSSEL und FREIGABE
     * wird auch dann gestellt, wenn der Wert gleich bleibt, weil ein
     * SunSpec-Stellglied seinen Zeitablauf hat und die Drosselung sonst
     * still ausliefe.
     *
     * Das ERSTE aber entscheidet ueber das Wirkungsfenster, und dort
     * stand bis 0.9.17 dasselbe $aendert. Folge: nimmt das Geraet den
     * Wert an und regelt trotzdem nicht, bleibt tat auf DROSSEL, das
     * Fenster wurde in JEDEM Takt neu gestartet, vergangen_s blieb 0,
     * und eb_wirkung() gibt unterhalb der Wartezeit immer 0 zurueck. Die
     * Zeile "KEINE WIRKUNG" konnte genau in dem Fall nie erscheinen,
     * fuer den sie gebaut wurde. */
    $grenze_alt = isset($zust['drossel_w']) ? $zust['drossel_w'] : null;
    $wert_neu = ($grenze_alt === null
                 || (int) round($r['drossel_w']) !== (int) round($grenze_alt));
    $aendert = ($r['tat'] === EB_DROSSEL || $r['tat'] === EB_FREIGABE || $wert_neu);

    if ($aendert) {
        $anteile = eb_aufteilen($r['drossel_w'], $steller);
        $neu['steller'] = array();
        $summe = 0.0;
        foreach ($steller as $nr => $s) {
            $watt = isset($anteile[$nr]) ? $anteile[$nr] : 0.0;
            list($ok, $was) = eb_stellen($s, $watt);
            $neu['steller'][(string) $nr] = array('name' => $s['name'], 'watt' => $watt,
                                                  'ok' => $ok, 'was' => $was);
            $summe += $watt;
            if (!$ok) { eb_log('Stellglied ' . $s['name'] . ': ' . $was); }
        }
        /* GESTELLT ist die Summe dessen, was WIRKLICH hinausging. Sie kann
         * kleiner sein als GRENZE, wenn eine Spitzenleistung deckelt -
         * GRENZE allein sagt das nicht. */
        $neu['gestellt_w'] = $summe;
        /* Das Fenster wird geoeffnet, wenn sich der Wert wirklich geaendert
         * hat - oder wenn gerade keines laeuft (nach einem Urteil setzt
         * die Auswertung unten gestellt_um auf 0 zurueck). Es wird NICHT
         * bei jedem Auffrischen neu gestartet. */
        if ($wert_neu || empty($neu['gestellt_um'])) {
            $neu['gestellt_um'] = $jetzt;
            $neu['netz_vorher'] = ($netz === null) ? null : -$netz;   // Einspeisung vorher
            $neu['grenze_vorher'] = $grenze_alt;
            $neu['wirkung'] = 0;
        }
        /* Nur die echte Aenderung kommt ins Protokoll. Bei stehender
         * Grenze stuenden sonst im Fuenfsekundentakt 17280 gleichlautende
         * Zeilen am Tag auf einer Ramdisk. */
        if ($wert_neu) {
            eb_log(sprintf('%s: Netz %s W, Ueberschuss %s W -> Grenze %d W (gestellt %d W), Ladesoll %d W',
                $r['anlass'], $netz === null ? '-' : (int) $netz,
                $r['ueberschuss_w'] === null ? '-' : (int) $r['ueberschuss_w'],
                (int) $r['drossel_w'], (int) $summe, (int) $r['lade_soll_w']));
        }
    }

    /* ---- Stellglieder mit Zeitablauf auffrischen ----
     * Der Weg oben spricht nur bei AENDERUNG. Ein SunSpec-Stellglied setzt
     * im Wechselrichter aber einen Zeitablauf (WMaxLimPct_RvrtTms): laeuft
     * der ab, ohne dass etwas Neues kommt, nimmt das Geraet die Drosselung
     * von allein zurueck. Genau so ist es gewollt, wenn die Bremse stirbt.
     * Solange sie lebt, muss sie deshalb VOR Ablauf erneut sprechen - sonst
     * loeste sich jede laenger stehende Drosselung still auf, und die
     * Anlage speiste wieder voll ein, waehrend hier "gedrosselt" stuende.
     *
     * Aufgefrischt wird nur, was auch etwas haelt: liegt die Grenze auf
     * oder ueber der Nennleistung des Geraets, gibt es nichts zu halten. */
    if ($aendert) {
        $neu['auffrisch_um'] = $jetzt;
    } else {
        $faellig_s = 0;
        foreach ($steller as $s) {
            if ($s['art'] !== 'sunspec') { continue; }
            $sa = eb_sunspec_zerlegen($s['adresse']);
            if ($sa === null) { continue; }
            $w = eb_sunspec_auffrischen_s($sa['rueckfall_s'], (int) $cfg['takt']);
            if ($w > 0 && ($faellig_s === 0 || $w < $faellig_s)) { $faellig_s = $w; }
        }
        if ($faellig_s > 0 && ($jetzt - (float) $neu['auffrisch_um']) >= $faellig_s) {
            $anteile = eb_aufteilen($r['drossel_w'], $steller);
            $etwas = 0;
            foreach ($steller as $nr => $s) {
                if ($s['art'] !== 'sunspec') { continue; }
                $watt = isset($anteile[$nr]) ? $anteile[$nr] : 0.0;
                $spitze = (float) $s['spitze_w'];
                if ($spitze > 0.0 && $watt >= $spitze) { continue; }
                list($ok, $was) = eb_stellen($s, $watt);
                $neu['steller'][(string) $nr] = array('name' => $s['name'], 'watt' => $watt,
                                                      'ok' => $ok, 'was' => $was);
                $etwas = 1;
                if (!$ok) { eb_log('Auffrischen ' . $s['name'] . ': ' . $was); }
            }
            if ($etwas) { $neu['auffrisch_um'] = $jetzt; }
        }
    }

    /* ---- Der Speicher bekommt seinen Sollwert ----
     * Nur wenn ein Weg eingetragen ist und sich der Wert geaendert hat. */
    if ($sp_st) {
        $alt_soll = isset($stand['sp_watt']) ? (int) round($stand['sp_watt']) : null;
        $neu_soll = (int) round($r['lade_soll_w']);
        if ($alt_soll === null || $alt_soll !== $neu_soll) {
            list($spok, $spwas) = eb_stellen($sp_st, $neu_soll);
            $neu['sp_watt'] = $neu_soll;
            $neu['sp_ok'] = $spok;
            $neu['sp_was'] = $spwas;
            if (!$spok) { eb_log('Speicher ' . $sp_st['name'] . ': ' . $spwas); }
        }
    }

    /* ---- Hat es gewirkt? ----
     * Nicht die Quittung zaehlt, sondern der Zaehler. Ein Wechselrichter,
     * der 200 OK sagt und weiterspeist, ist der unangenehmste Fall - er
     * meldet Erfolg, und die Auflage ist trotzdem verletzt.
     *
     * Verglichen wird EINSPEISUNG gegen ERLAUBTE EINSPEISUNG. Bis 0.9.4
     * stand dort die Erzeugungsgrenze, also eine andere Groesse; die
     * Schwelle fiel damit auf ihre Untergrenze, und jeder Wolkenzug galt
     * als Erfolg. */
    if (!empty($neu['gestellt_um']) && $netz !== null && $neu['netz_vorher'] !== null) {
        $w = eb_wirkung($neu['netz_vorher'], -$netz, $cfg['ziel_w'],
                        $cfg['wirkung_s'], $jetzt - $neu['gestellt_um']);
        if ($w !== 0) {
            $vorher = isset($zust['wirkung']) ? (int) $zust['wirkung'] : 0;
            $neu['wirkung'] = $w;
            /* Nur beim WECHSEL melden. Eine Anlage, die dauerhaft nicht
             * folgt, schriebe sonst alle wirkung_s dieselbe Zeile. */
            if ($w === -1 && $vorher !== -1) {
                eb_log('KEINE WIRKUNG: die Grenze wurde gestellt, die Einspeisung ist nicht '
                     . 'gefallen. Nimmt das Geraet den Wert wirklich an?');
            }
            if ($w === 1 && $vorher === -1) {
                eb_log('Die Drosselung wirkt wieder.');
            }
            $neu['gestellt_um'] = 0;
        }
    }

    return $neu;
}

/**
 * Den Zustand nach MQTT geben.
 *
 * Gesendet wird nur, was sich GEAENDERT hat - bis 0.9.4 startete jeder
 * Durchlauf je Thema einen eigenen mosquitto_pub, bei zwei Stellgliedern
 * also sechzehn Prozesse alle fuenf Sekunden, dauerhaft, auf einem
 * Kleinrechner. Alle fuenf Minuten geht der volle Satz hinaus, damit ein
 * neu gestarteter Broker nicht dauerhaft leer bleibt.
 */
/**
 * Welche Themen gehen RETAINED hinaus?
 *
 * Hausstandard seit 03.09.2026: Zustaende ja - damit Loxone nach einem
 * Neustart des Miniservers oder des Gateways sofort den Stand hat.
 * Messwerte mit Zeitbezug nein - damit nach einem Ausfall kein alter Wert
 * als aktueller erscheint. Das Lebenszeichen NIE: retained zeigte es
 * immer "lebt", und genau das soll es nicht koennen.
 *
 * Bis 0.9.17 ging alles retained hinaus, weil keine der drei
 * Aufrufstellen den dritten Parameter uebergab.
 */
function eb_mqtt_retained($k)
{
    /* Messwerte und Alter: der letzte gemessene Wert darf nach einem
     * Ausfall nicht als aktueller Wert im Broker stehenbleiben. */
    $fluechtig = array('netz', 'erzeugung', 'ueberschuss', 'grenze', 'gestellt',
                       'ladesoll', 'alter', 'messalter', 'speichersoll', 'online');
    if (in_array($k, $fluechtig, true)) { return false; }
    /* stellerN/watt ist ebenfalls ein Messwert, stellerN/ok ein Zustand. */
    if (preg_match('#^steller[0-9]+/watt$#', $k) === 1) { return false; }
    return true;
}

function eb_veroeffentlichen($cfg, $stand)
{
    static $letzte = array();
    static $voll_um = 0.0;
    if (empty($cfg['mqtt_ein'])) { return; }
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    $jetzt = microtime(true);
    $voll = ($jetzt - $voll_um) > 300.0;
    if ($voll) { $voll_um = $jetzt; }

    $paare = array(
        'ein' => (int) $stand['ein'],
        'netz' => $stand['netz'] === null ? '' : (int) $stand['netz'],
        'erzeugung' => $stand['erzeugung'] === null ? '' : (int) $stand['erzeugung'],
        'ueberschuss' => $stand['ueberschuss_w'] === null ? '' : (int) $stand['ueberschuss_w'],
        'grenze' => (int) $stand['drossel_w'],
        'gestellt' => $stand['gestellt_w'] === null ? '' : (int) $stand['gestellt_w'],
        'ladesoll' => (int) $stand['lade_soll_w'],
        'anlass' => $stand['anlass'],
        'tat' => (int) $stand['tat'],
        'notfall' => (int) $stand['notfall'],
        'wirkung' => (int) $stand['wirkung'],
        'speicher' => (int) $stand['speicher_folgt'],
        'alter' => max(0, time() - (int) $stand['zeit']),
        'messalter' => $stand['netz_alter'] === null ? '' : (int) round($stand['netz_alter']),
        /* Das Lebenszeichen traegt den Zeitstempel im Wert und geht in
         * JEDEM Durchlauf hinaus, nicht retained. Ein retained 'online'
         * ohne Zeitbezug stuende nach einem Absturz fuer immer auf 1. */
        'online' => '1;' . time(),
        'ersatz' => (int) $stand['ersatz'],
        'stufe' => (int) $stand['stufe'],
        'ziel' => (int) $stand['ziel_w'],
    );
    if (isset($stand['sp_watt']) && $stand['sp_watt'] !== null) {
        $paare['speichersoll'] = (int) $stand['sp_watt'];
        $paare['speicherok'] = (int) $stand['sp_ok'];
    }
    foreach ((array) $stand['steller'] as $nr => $e) {
        $paare['steller' . $nr . '/name'] = $e['name'];
        $paare['steller' . $nr . '/watt'] = (int) $e['watt'];
        $paare['steller' . $nr . '/ok'] = (int) $e['ok'];
    }
    foreach ($paare as $k => $v) {
        if ($v === '') { continue; }        // kein Wert ist keine Null
        /* alter und messalter aendern sich in jedem Durchlauf; sie wuerden
         * die Ersparnis auffressen und gehen deshalb nur im vollen Satz
         * hinaus. Wer das Alter braucht, liest es ueber den Endpunkt. */
        /* 'online' geht bei JEDEM Durchgang hinaus - es ist das
         * Lebenszeichen, und sein Wert wechselt durch den Zeitstempel
         * ohnehin. 'alter' und 'messalter' wechseln in jedem Durchlauf und
         * gehen nur im vollen Satz hinaus; wer das Alter genau braucht,
         * rechnet es aus dem Zeitstempel des Lebenszeichens. */
        if ($k === 'online') {
            if (eb_mqtt_veroeffentlichen($praefix . '/online', $v, false)) { $letzte[$k] = $v; }
            continue;
        }
        $immer = ($k !== 'alter' && $k !== 'messalter');
        $neu = !array_key_exists($k, $letzte) || $letzte[$k] !== $v;
        if (!$voll && !($neu && $immer)) { continue; }
        if (eb_mqtt_veroeffentlichen($praefix . '/' . $k, $v, eb_mqtt_retained($k))) {
            $letzte[$k] = $v;
        }
    }
}

/* ==================================================================
 * Bilanz und Verlauf
 *
 * Beides wird im Speicher gefuehrt und nur selten geschrieben:
 * data/plugins liegt auf der Karte, und ein Schreibvorgang je Takt waere
 * bei fuenf Sekunden eine Dauerlast ohne Erkenntnisgewinn.
 * ================================================================== */

$eb_bilanz_stand = null;
$eb_bilanz_um = 0.0;
$eb_bilanz_letzte = 0.0;
$eb_verlauf_punkte = null;
$eb_verlauf_um = 0.0;

function eb_bilanz_fortschreiben($cfg, $stand, $jetzt)
{
    global $eb_bilanz_stand, $eb_bilanz_um, $eb_bilanz_letzte;
    if (empty($cfg['bilanz_ein'])) { return; }
    if ($eb_bilanz_stand === null) { $eb_bilanz_stand = eb_bilanz(); }
    if (!is_array($eb_bilanz_stand)) { $eb_bilanz_stand = array(); }

    $heute = date('Y-m-d');
    $monat = date('Y-m');
    if (!isset($eb_bilanz_stand['tag']) || $eb_bilanz_stand['tag'] !== $heute) {
        $eb_bilanz_stand['gestern_tag'] = isset($eb_bilanz_stand['tag']) ? $eb_bilanz_stand['tag'] : '';
        $eb_bilanz_stand['gestern'] = isset($eb_bilanz_stand['heute'])
            ? $eb_bilanz_stand['heute'] : eb_bilanz_leer();
        $eb_bilanz_stand['heute'] = eb_bilanz_leer();
        $eb_bilanz_stand['tag'] = $heute;
        $eb_bilanz_letzte = 0.0;
    }
    if (!isset($eb_bilanz_stand['monat']) || $eb_bilanz_stand['monat'] !== $monat) {
        $eb_bilanz_stand['monat'] = $monat;
        $eb_bilanz_stand['monat_werte'] = eb_bilanz_leer();
    }

    /* Der Zeitschritt wird gedeckelt. Nach einem Neustart oder einer Pause
     * waere er sonst stundenlang, und der letzte Messwert wuerde ueber die
     * ganze Luecke hochgerechnet - eine erfundene Arbeit, die hinterher wie
     * eine gemessene aussieht. */
    $dt = ($eb_bilanz_letzte > 0.0) ? min(120.0, max(0.0, $jetzt - $eb_bilanz_letzte)) : 0.0;
    $eb_bilanz_letzte = $jetzt;
    if ($dt > 0.0) {
        foreach (array('heute', 'monat_werte') as $k) {
            $b = array_merge(eb_bilanz_leer(),
                is_array(isset($eb_bilanz_stand[$k]) ? $eb_bilanz_stand[$k] : null)
                    ? $eb_bilanz_stand[$k] : array());
            if ($stand['netz'] !== null) {
                $b['eingespeist_ws'] += max(0.0, -eb_zahl($stand['netz'], 0.0)) * $dt;
                $b['bezogen_ws'] += max(0.0, eb_zahl($stand['netz'], 0.0)) * $dt;
            }
            if ($stand['erzeugung'] !== null) {
                $b['erzeugt_ws'] += max(0.0, eb_zahl($stand['erzeugung'], 0.0)) * $dt;
                $b['erzeugt_gemessen'] = 1;
            }
            if ($stand['lade_ist'] !== null) {
                $b['speicher_ws'] += max(0.0, eb_zahl($stand['lade_ist'], 0.0)) * $dt;
                $b['speicher_gemessen'] = 1;
            }
            /* Gezaehlt wird die DAUER der Abregelung, nicht eine
             * Kilowattstundenzahl: was die Anlage ohne Grenze geliefert
             * haette, weiss niemand - sie hat es nicht geliefert. */
            if ((int) $stand['tat'] === EB_DROSSEL && empty($stand['notfall'])) {
                $b['gedrosselt_s'] += $dt;
            }
            $eb_bilanz_stand[$k] = $b;
        }
    }
    if ($jetzt - $eb_bilanz_um >= 60.0) {
        $eb_bilanz_um = $jetzt;
        $eb_bilanz_stand['zeit'] = time();
        eb_json_schreiben(eb_paths()['datadir'] . '/bilanz.json', $eb_bilanz_stand);
    }
}

function eb_verlauf_fortschreiben($cfg, $stand, $jetzt)
{
    global $eb_verlauf_punkte, $eb_verlauf_um;
    if (empty($cfg['verlauf_ein'])) { return; }
    if ($eb_verlauf_punkte === null) {
        $v = eb_verlauf();
        $eb_verlauf_punkte = (isset($v['punkte']) && is_array($v['punkte'])) ? $v['punkte'] : array();
    }
    if ($eb_verlauf_um > 0.0 && $jetzt - $eb_verlauf_um < 30.0) { return; }
    $eb_verlauf_um = $jetzt;
    $eb_verlauf_punkte[] = array(time(),
        $stand['netz'] === null ? null : (int) round($stand['netz']),
        (int) round($stand['drossel_w']),
        (int) round($stand['lade_soll_w']));
    // Zwei Stunden bei 30 s Abstand. Mehr waere Schreiblast ohne Erkenntnis.
    if (count($eb_verlauf_punkte) > 240) {
        $eb_verlauf_punkte = array_slice($eb_verlauf_punkte, -240);
    }
    eb_json_schreiben(eb_paths()['datadir'] . '/verlauf.json',
                      array('punkte' => $eb_verlauf_punkte));
}

/** Beim geordneten Beenden einmal online=0 hinterlassen. */
function eb_mqtt_abmelden($cfg)
{
    if (empty($cfg['mqtt_ein'])) { return; }
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    eb_mqtt_veroeffentlichen($praefix . '/online', '0;' . time(), false);
}

/* ==================================================================
 * Aufruf
 * ================================================================== */

if ($eb_hat('--probe')) {
    $cfg = eb_config();
    eb_hoerer_starten($cfg);
    // Dem Zuhoerer einen Augenblick geben - ohne retained-Flag kommt sonst
    // nichts an, und das saehe nach einem Fehler aus, wo keiner ist.
    for ($i = 0; $i < 30; $i++) { usleep(100000); eb_hoerer_abholen(); }
    foreach (eb_quellenfelder() as $k => $eb_f) {
        $name = $eb_f['kurz'];
        list($w, $a, $anlass) = eb_quelle_lesen($cfg[$k]);
        $grenze = max(10.0, eb_zahl($cfg['quelle_alter_s'], 300.0));
        if ($k !== 'q_netz' && $w !== null && $a >= 0.0 && $a > $grenze) {
            $anlass = 'zu_alt (Grenze ' . (int) $grenze . ' s)';
            $w = null;
        }
        printf("%-13s %-10s %s\n", $name,
            $w === null ? '-' : round($w, 1),
            $anlass . ($w === null ? '' : sprintf(' (%.1f s alt)', $a)));
    }
    eb_hoerer_beenden();
    exit(0);
}

/* ==================================================================
 * --sunspec: den Schreibweg ansehen, ohne zu schreiben
 *
 * Ruft NICHTS auf, was stellt. Schreitet die Modellkette ab, liest den
 * Skalierungsfaktor und rechnet vor, was bei der aktuellen Grenze in
 * welches Register ginge - und geht dann nach Hause. Wer den Schreibweg
 * in Betrieb nehmen will, sieht damit vorher, ob die Adresse stimmt, ob
 * Modell 123 gefunden wird und welche Zahl gleich das Geraet erreichen
 * wuerde. Am 19.08.2026 an einem Symo Hybrid gemessen: Modell 123 bei
 * 40237, Faktor -2.
 *
 * Was dieser Modus NICHT sagen kann: ob die Wechselrichter-Steuerung im
 * Datamanager freigegeben ist. Das zeigt sich erst beim Schreiben - und
 * ob es gewirkt hat, sagt danach allein der Zaehler.
 * ================================================================== */
if ($eb_hat('--sunspec')) {
    $cfg = eb_config();
    $gefunden = 0;
    $stellglieder = $cfg['steller'];
    $stellglieder[] = $cfg['sp_steller'];
    foreach ($stellglieder as $s) {
        if ($s['art'] !== 'sunspec') { continue; }
        $gefunden++;
        $name = $s['name'] !== '' ? $s['name'] : '(ohne Namen)';
        echo "\n" . $name . ' -> ' . $s['adresse'] . "\n";

        $a = eb_sunspec_zerlegen($s['adresse']);
        if ($a === null) { echo "  Adresse passt nicht auf das Muster.\n"; continue; }
        printf("  Host %s, Port %d, Geraeteadresse %d, Basis %d, Rueckfall %d s\n",
               $a['host'], $a['port'], $a['id'], $a['basis'], $a['rueckfall_s']);

        $eno = 0; $estr = '';
        $fp = @stream_socket_client('tcp://' . $a['host'] . ':' . $a['port'], $eno, $estr, 4);
        if (!$fp) { echo "  unerreichbar\n"; continue; }
        stream_set_timeout($fp, 4);
        $v = 0;

        /* Die ganze Kette zeigen, nicht nur den Treffer: wer sie sieht,
         * erkennt auch, WARUM Modell 123 dort liegt, wo es liegt. */
        list($m, $anl) = eb_modbus_regs_lesen($fp, $a['id'], $a['basis'], 2, $v);
        if ($m === null) { echo '  Basis nicht lesbar: ' . $anl . "\n"; @fclose($fp); continue; }
        if ($m[0] !== 0x5375 || $m[1] !== 0x6E53) {
            printf("  Bei %d steht keine SunSpec-Marke (gelesen %d %d).\n", $a['basis'], $m[0], $m[1]);
            @fclose($fp); continue;
        }
        echo "  Kette:\n";
        $zeiger = $a['basis'] + 2;
        for ($i = 0; $i < 40; $i++) {
            list($k, $anl) = eb_modbus_regs_lesen($fp, $a['id'], $zeiger, 2, $v);
            if ($k === null) { echo '    ' . $zeiger . ' nicht lesbar: ' . $anl . "\n"; break; }
            if ($k[0] === 0xFFFF) { printf("    %-6d Endblock\n", $zeiger); break; }
            printf("    %-6d Modell %-4d Laenge %-3d weiter bei %d\n",
                   $zeiger, $k[0], $k[1], $zeiger + 2 + $k[1]);
            $zeiger += 2 + $k[1];
        }

        list($start, $anl) = eb_sunspec_modell($fp, $a['id'], $a['basis'], 123, $v);
        if ($start === null) { echo '  Modell 123: ' . $anl . "\n"; @fclose($fp); continue; }

        $felder = array(array(5, 'WMaxLimPct'), array(7, 'WMaxLimPct_RvrtTms'),
                        array(9, 'WMaxLim_Ena'), array(23, 'WMaxLimPct_SF'));
        echo "  Modell 123 bei " . $start . ", Stellregister wie sie JETZT stehen:\n";
        $sf = null;
        foreach ($felder as $fd) {
            list($w, $anl) = eb_modbus_regs_lesen($fp, $a['id'], $start + $fd[0], 1, $v);
            if ($w === null) { printf("    %-20s %-6d nicht lesbar: %s\n", $fd[1], $start + $fd[0], $anl); continue; }
            $sv = ($w[0] >= 32768) ? $w[0] - 65536 : $w[0];
            printf("    %-20s %-6d %d\n", $fd[1], $start + $fd[0], $sv);
            if ($fd[0] === 23) { $sf = $sv; }
        }
        @fclose($fp);

        if ($sf === null) { echo "  Ohne Faktor wird nichts gerechnet.\n"; continue; }
        $stand = eb_stand();
        /* Ohne gelaufenen Dienst gibt es KEINE letzte Grenze. Dann hier
         * eine Null hinzuschreiben waere eine Zahl, die aussieht wie eine
         * gemessene - und ausgerechnet die Null ist die gefaehrlichste,
         * weil sie unter der Standby-Linie liegt. */
        if (!isset($stand['drossel_w'])) {
            echo "  Es gibt noch keine gestellte Grenze (der Dienst lief hier noch
";
            echo "  nicht). Deshalb wird nichts vorgerechnet.
";
            continue;
        }
        $grenze = eb_zahl($stand['drossel_w'], 0.0);
        list($rohw, $anl) = eb_sunspec_roh($grenze, $s['spitze_w'], $sf);
        if ($rohw === null) { echo '  Rohwert: ' . $anl . "\n"; continue; }
        printf("  Bei der letzten Grenze von %d W und %d W Nennleistung ginge:\n",
               (int) round($grenze), (int) $s['spitze_w']);
        $voll = (int) round(100.0 * pow(10, -$sf));
        if ($rohw >= $voll) {
            printf("    %-6d WMaxLim_Ena = 0   (volle Freigabe, Betriebsart beenden)\n", $start + 9);
        } else {
            printf("    %-6d WMaxLimPct        = %d\n", $start + 5, $rohw);
            printf("    %-6d WMaxLimPct_RvrtTms = %d\n", $start + 7, $a['rueckfall_s']);
            printf("    %-6d WMaxLim_Ena       = 1\n", $start + 9);
        }
        echo "  Geschrieben wurde nichts.\n";
    }
    if (!$gefunden) { echo "Kein Stellglied steht auf dem SunSpec-Weg.\n"; }
    exit(0);
}

$eb_p = eb_paths();
if (!is_dir($eb_p['datadir'])) { @mkdir($eb_p['datadir'], 0775, true); }

if ($eb_hat('--einmal')) {
    $cfg = eb_config();
    eb_hoerer_starten($cfg);
    for ($i = 0; $i < 30; $i++) { usleep(100000); eb_hoerer_abholen(); }
    $neu = eb_durchlauf($cfg, eb_stand());
    eb_json_schreiben($eb_p['datadir'] . '/stand.json', $neu);
    eb_bilanz_fortschreiben($cfg, $neu, microtime(true));
    eb_verlauf_fortschreiben($cfg, $neu, microtime(true));
    eb_veroeffentlichen($cfg, $neu);
    echo eb_zeile($neu);
    eb_hoerer_beenden();
    exit(0);
}

/* ---- Dauerbetrieb ---- */
$eb_laeuft = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () { global $eb_laeuft; $eb_laeuft = false; });
    pcntl_signal(SIGINT, function () { global $eb_laeuft; $eb_laeuft = false; });
}

$eb_cfg = eb_config();
$eb_themen_alt = implode('|', eb_themen($eb_cfg));
eb_hoerer_starten($eb_cfg);
/* Dem Zuhoerer dieselbe Anlaufzeit geben wie --einmal und --probe. Ohne
 * sie lief der erste Durchlauf, bevor irgendein MQTT-Wert eingetroffen
 * war: alter_s war -1, und die Anlage ging in den Notbetrieb - bei der
 * Vorgabe notfall_w = 0 also auf null. Da der Minutentakt den Dienst
 * nachstartet, kostete jeder Neustart eine Vollabregelung samt langsamer
 * Rueckkehr. Bei HTTP-Quellen tritt das nicht auf, die werden im
 * Durchlauf selbst gelesen. */
if (eb_themen($eb_cfg)) {
    for ($eb_i = 0; $eb_i < 30; $eb_i++) { usleep(100000); eb_hoerer_abholen(); }
}
eb_log('Dienst gestartet (Takt ' . $eb_cfg['takt'] . ' s).');

$eb_naechste = 0.0;
while ($eb_laeuft) {
    eb_hoerer_abholen();

    $jetzt = microtime(true);
    if ($jetzt >= $eb_naechste) {
        $eb_cfg = eb_config();

        /* Aendert sich die Themenliste, muss der Zuhoerer neu gestartet
         * werden - sonst laeuft er still auf den alten Themen weiter und
         * die Regelung wartet auf einen Wert, den niemand mehr sendet. */
        $themen_neu = implode('|', eb_themen($eb_cfg));
        if ($themen_neu !== $eb_themen_alt || !eb_hoerer_lebt()) {
            if (!eb_hoerer_lebt() && $themen_neu !== '') {
                eb_log('MQTT: der Zuhoerer lief nicht mehr und wird neu gestartet.');
            }
            eb_hoerer_starten($eb_cfg);
            $eb_themen_alt = $themen_neu;
        }

        $eb_neu = eb_durchlauf($eb_cfg, eb_stand());
        eb_json_schreiben($eb_p['datadir'] . '/stand.json', $eb_neu);
        eb_bilanz_fortschreiben($eb_cfg, $eb_neu, $jetzt);
        eb_verlauf_fortschreiben($eb_cfg, $eb_neu, $jetzt);
        eb_veroeffentlichen($eb_cfg, $eb_neu);
        $eb_naechste = $jetzt + max(2, (int) $eb_cfg['takt']);
    }
    usleep(200000);
}

/* ---- Beim Beenden: die Anlage nicht gedrosselt zuruecklassen? ----
 * Doch. Die Grenze bleibt, wie sie ist. Ein Dienst, der beim Beenden alles
 * freigibt, hebt genau in dem Augenblick eine Auflage auf, in dem niemand
 * mehr hinsieht - beim Neustart des LoxBerry, beim Update, beim Absturz.
 * Wer die Drosselung loswerden will, schaltet die Regelung in der
 * Oberflaeche aus; dann wird sie einmal freigegeben und danach nichts mehr
 * gestellt. */
eb_hoerer_beenden();
eb_mqtt_abmelden($eb_cfg);
eb_log('Dienst beendet. Die zuletzt gestellte Grenze bleibt bestehen.');
exit(0);
