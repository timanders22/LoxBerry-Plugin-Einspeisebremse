<?php
/**
 * Einspeisebremse - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche.
 *
 * Praefix 'eb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

require_once __DIR__ . '/eb_regel.php';

if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) { return $d; }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

if (!function_exists('eb_e')) {
    function eb_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
function eb_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

define('EB_STELLER', 4);        // so viele Stellglieder fuehrt die Oberflaeche

function eb_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html' || $dir === 'plugins') {
        $dir = 'einspeisebremse';
    }
    $basis = $home !== '' ? $home : dirname(dirname(__DIR__));
    $p = array(
        'home'      => $home,
        'plugin'    => $dir,
        'configdir' => $basis . '/config/plugins/' . $dir,
        'config'    => $basis . '/config/plugins/' . $dir . '/einspeisebremse.json',
        'geheim'    => $basis . '/config/plugins/' . $dir . '/geheim.json',
        'sicherung' => $basis . '/config/plugins/' . $dir . '.backup.json',
        'datadir'   => $basis . '/data/plugins/' . $dir,
        'logdir'    => $basis . '/log/plugins/' . $dir,
        'log'       => $basis . '/log/plugins/' . $dir . '/einspeisebremse.log',
        'bindir'    => $basis . '/bin/plugins/' . $dir,
    );
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/** Woher ein einzelner Messwert kommt. */
function eb_quelle_vorgabe()
{
    return array('art' => 'aus', 'adresse' => '', 'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0);
}

function eb_quellarten()
{
    return array(
        'aus'  => 'EB_QUELLE.AUS',
        'mqtt' => 'EB_QUELLE.MQTT',
        'http' => 'EB_QUELLE.HTTP',
    );
}

function eb_steller_vorgabe()
{
    return array(
        'name' => '', 'aktiv' => 1, 'art' => 'aus', 'adresse' => '',
        'inhalt' => '', 'einheit' => 'W', 'spitze_w' => 0, 'anteil' => 0,
    );
}

function eb_stellarten()
{
    return array(
        'aus'       => 'EB_STELL.AUS',
        'mqtt'      => 'EB_STELL.MQTT',
        'http_get'  => 'EB_STELL.HTTP_GET',
        'http_post' => 'EB_STELL.HTTP_POST',
    );
}

function eb_einheiten()
{
    return array(
        'W'       => 'EB_EINHEIT.W',
        'kW'      => 'EB_EINHEIT.KW',
        'Prozent' => 'EB_EINHEIT.PROZENT',
    );
}

function eb_vorgaben()
{
    return array(
        'ein'             => 0,      // die Regelung selbst - aus bis der Mensch ja sagt
        'ziel_w'          => 0,
        'totband_w'       => 50,
        'rampe_ab_w'      => 2000,
        'rampe_auf_w'     => 300,
        'drossel_min_w'   => 0,
        'notfall_s'       => 60,
        'notfall_w'       => 0,
        'speicher_zuerst' => 1,
        'soc_max'         => 95,
        'lade_max_w'      => 0,
        'wirkung_s'       => 20,
        /* Die Grenze, die beim AUSSCHALTEN der Regelung einmal gestellt
         * wird. Hoch angesetzt, weil "aus" heissen soll: die Anlage darf
         * wieder alles. Eine 0 waere hier die gefaehrlichste Vorgabe von
         * allen - sie schaltete die Anlage beim Ausschalten der Bremse ab. */
        'frei_w'          => 100000,
        'takt'            => 5,
        'q_netz'          => null,
        'q_erzeugung'     => null,
        'q_soc'           => null,
        'q_lade'          => null,
        'steller'         => array(),
        'mqtt_ein'        => 1,
        'mqtt_topic'      => 'einspeisebremse',
        'aktionstoken'    => '',
    );
}

function eb_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen.
 *
 * Die Nebendatei traegt Prozessnummer und Zufallsanteil: Dienst und
 * Oberflaeche koennen im selben Augenblick schreiben. json_encode wird
 * geprueft - bei ungueltigem UTF-8 liefert es false, und file_put_contents
 * machte daraus eine LEERE Datei mit Rueckgabe 0, also nicht false. Der
 * Verlust waere als Erfolg gemeldet.
 */
function eb_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

function eb_quelle_richten($q)
{
    $g = is_array($q) ? $q : array();
    $g += eb_quelle_vorgabe();
    $arten = eb_quellarten();
    if (!isset($arten[$g['art']])) { $g['art'] = 'aus'; }
    $g['adresse'] = trim((string) $g['adresse']);
    $g['pfad'] = trim((string) $g['pfad']);
    $g['faktor'] = eb_zahl($g['faktor'], 1.0);
    if ($g['faktor'] == 0.0) { $g['faktor'] = 1.0; }
    $g['invertieren'] = empty($g['invertieren']) ? 0 : 1;
    return $g;
}

function eb_config()
{
    $p = eb_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = array_merge(eb_vorgaben(), eb_json_lesen($p['config']));

    foreach (array('q_netz', 'q_erzeugung', 'q_soc', 'q_lade') as $k) {
        $cfg[$k] = eb_quelle_richten($cfg[$k]);
    }

    if (!is_array($cfg['steller'])) { $cfg['steller'] = array(); }
    $sa = eb_stellarten();
    $eh = eb_einheiten();
    for ($i = 0; $i < EB_STELLER; $i++) {
        $s = isset($cfg['steller'][$i]) && is_array($cfg['steller'][$i]) ? $cfg['steller'][$i] : array();
        $s += eb_steller_vorgabe();
        $s['name'] = trim((string) $s['name']);
        if (!isset($sa[$s['art']])) { $s['art'] = 'aus'; }
        if (!isset($eh[$s['einheit']])) { $s['einheit'] = 'W'; }
        $s['aktiv'] = empty($s['aktiv']) ? 0 : 1;
        $s['spitze_w'] = max(0, min(1000000, (int) eb_zahl($s['spitze_w'], 0)));
        $s['anteil'] = max(0, min(100, (int) eb_zahl($s['anteil'], 0)));
        $cfg['steller'][$i] = $s;
    }

    $cfg['ein'] = empty($cfg['ein']) ? 0 : 1;
    $cfg['speicher_zuerst'] = empty($cfg['speicher_zuerst']) ? 0 : 1;
    $cfg['mqtt_ein'] = empty($cfg['mqtt_ein']) ? 0 : 1;
    foreach (array('ziel_w' => array(0, 1000000), 'totband_w' => array(0, 10000),
                   'rampe_ab_w' => array(10, 1000000), 'rampe_auf_w' => array(10, 1000000),
                   'drossel_min_w' => array(0, 1000000), 'notfall_s' => array(5, 3600),
                   'notfall_w' => array(0, 1000000), 'soc_max' => array(10, 100),
                   'lade_max_w' => array(0, 1000000), 'wirkung_s' => array(5, 600), 'frei_w' => array(0, 1000000),
                   'takt' => array(2, 300)) as $k => $gr) {
        $cfg[$k] = (int) max($gr[0], min($gr[1], eb_zahl($cfg[$k], $gr[0])));
    }
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    $cfg['mqtt_topic'] = trim($t, '/') !== '' ? trim($t, '/') : 'einspeisebremse';
    return $cfg;
}

function eb_config_speichern($cfg)
{
    $p = eb_paths();
    if (!eb_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/** Nur die Stellglieder mit Namen und eingeschalteter Art. 1-basiert. */
function eb_steller()
{
    $out = array();
    $n = 0;
    foreach (eb_config()['steller'] as $s) {
        if (trim((string) $s['name']) === '' || $s['art'] === 'aus') { continue; }
        $n++;
        $s['nr'] = $n;
        $out[$n] = $s;
    }
    return $out;
}

/**
 * Den Befehl fuer ein Stellglied bauen.
 *
 * ES WERDEN KEINE REGISTER ERFUNDEN. Was ein Wechselrichter annimmt,
 * weiss der Hersteller und sonst niemand; ein geratenes Modbus-Register
 * schreibt im besten Fall ins Leere und im schlechtesten in eine
 * Werkseinstellung. Der Mensch traegt hier die Adresse ein, die sein
 * Geraet versteht, und setzt einen Platzhalter, wohin der Wert gehoert:
 *
 *   {W}        die Grenze in Watt, ganzzahlig
 *   {KW}       dieselbe in Kilowatt mit drei Nachkommastellen
 *   {PROZENT}  Anteil an der eingetragenen Spitzenleistung, 0 bis 100
 */
function eb_befehl_bauen($steller, $watt)
{
    $w = max(0.0, eb_zahl($watt, 0.0));
    $ersetzung = array(
        '{W}'  => (string) (int) round($w),
        '{KW}' => number_format($w / 1000.0, 3, '.', ''),
    );
    $spitze = (int) $steller['spitze_w'];
    $ersetzung['{PROZENT}'] = ($spitze > 0)
        ? (string) (int) round(max(0.0, min(100.0, $w / $spitze * 100.0)))
        : '';
    $adresse = strtr((string) $steller['adresse'], $ersetzung);
    $inhalt = strtr((string) $steller['inhalt'], $ersetzung);
    return array($adresse, $inhalt, $ersetzung);
}

/**
 * Was an dieser Einstellung nicht aufgeht.
 *
 * Wird sowohl beim Speichern als auch im Reiter Test gerufen. Lieber eine
 * Liste unbequemer Saetze als eine Anlage, die still nichts regelt.
 */
function eb_maengel($cfg)
{
    $m = array();
    if ($cfg['q_netz']['art'] === 'aus') { $m[] = 'MANGEL.KEIN_ZAEHLER'; }
    elseif ($cfg['q_netz']['adresse'] === '') { $m[] = 'MANGEL.ZAEHLER_OHNE_ADRESSE'; }
    $st = array();
    foreach ($cfg['steller'] as $s) {
        if (trim((string) $s['name']) === '' || $s['art'] === 'aus') { continue; }
        $st[] = $s;
        if ($s['adresse'] === '') { $m[] = 'MANGEL.STELLER_OHNE_ADRESSE'; }
        elseif (strpos($s['adresse'] . $s['inhalt'], '{') === false) {
            $m[] = 'MANGEL.OHNE_PLATZHALTER';
        }
        if ($s['einheit'] === 'Prozent' && (int) $s['spitze_w'] <= 0) {
            $m[] = 'MANGEL.PROZENT_OHNE_SPITZE';
        }
    }
    if (!$st) { $m[] = 'MANGEL.KEIN_STELLER'; }
    if (!empty($cfg['speicher_zuerst']) && (int) $cfg['lade_max_w'] <= 0) {
        $m[] = 'MANGEL.SPEICHER_OHNE_LEISTUNG';
    }
    if ((int) $cfg['rampe_auf_w'] > (int) $cfg['rampe_ab_w']) { $m[] = 'MANGEL.RAMPE_VERDREHT'; }
    if ((int) $cfg['notfall_w'] > (int) $cfg['ziel_w'] && (int) $cfg['ziel_w'] > 0) {
        $m[] = 'MANGEL.NOTWERT_ZU_HOCH';
    }
    if ((int) $cfg['takt'] * 3 > (int) $cfg['notfall_s']) { $m[] = 'MANGEL.NOTFALL_ZU_KURZ'; }
    return array_values(array_unique($m));
}

/* ==================================================================
 * Zustand, Protokoll, Dienst
 * ================================================================== */

function eb_stand() { return eb_json_lesen(eb_paths()['datadir'] . '/stand.json'); }

function eb_alter()
{
    $s = eb_stand();
    return isset($s['zeit']) && (int) $s['zeit'] > 0 ? max(0, time() - (int) $s['zeit']) : -1;
}

function eb_verlauf() { return eb_json_lesen(eb_paths()['datadir'] . '/verlauf.json'); }

function eb_log($text)
{
    $p = eb_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei
     * frisst Arbeitsspeicher, nicht Plattenplatz. Bei einem Takt von fuenf
     * Sekunden ist das keine ferne Moeglichkeit. */
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Die letzten Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek.
 * Gemessen an 12.000 Zeilen: file() 0,37 ms und 2 MB, exec("tail") 2,17 ms,
 * fseek 0,05 ms und 0 kB.
 */
function eb_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) { return array(); }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

function eb_dienst_pid()
{
    $f = eb_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0) { return 0; }
    /* Argumentweise pruefen, nicht per grep ueber die ganze Befehlszeile:
     * ein grep faende auch die eigene Suche und jeden offenen Editor. */
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) { return 0; }
    foreach (explode("\0", $cmd) as $teil) {
        if (basename($teil) === 'eb_dienst.php') { return $pid; }
    }
    return 0;
}

function eb_token_erzeugen($laenge = 24)
{
    $z = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $z[random_int(0, strlen($z) - 1)]; }
    return $t;
}

/**
 * Das Aktionstoken holen, bei Bedarf erzeugen - hinter einer Dateisperre.
 * Ohne sie erzeugten zwei gleichzeitige Aufrufe je ein eigenes; der zuerst
 * angezeigte Wert waere schon ueberholt, und die daraus gebaute
 * Loxone-Vorlage truege ein Token, das nicht mehr gilt.
 */
function eb_token()
{
    $cfg = eb_config();
    if (trim((string) $cfg['aktionstoken']) !== '') { return (string) $cfg['aktionstoken']; }
    $p = eb_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $fp = @fopen($p['datadir'] . '/token.lock', 'c+');
    if ($fp === false) {
        $cfg['aktionstoken'] = eb_token_erzeugen();
        eb_config_speichern($cfg);
        return (string) $cfg['aktionstoken'];
    }
    if (@flock($fp, LOCK_EX)) {
        $cfg = eb_config();
        if (trim((string) $cfg['aktionstoken']) === '') {
            $cfg['aktionstoken'] = eb_token_erzeugen();
            eb_config_speichern($cfg);
        }
        @flock($fp, LOCK_UN);
    }
    fclose($fp);
    return (string) $cfg['aktionstoken'];
}

/* ==================================================================
 * MQTT
 * ================================================================== */

function eb_mqtt_zustand()
{
    $p = eb_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0);
    if ($p['home'] === '') { return $leer; }
    $gen = eb_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $leer; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'  => 1,
        'autostart' => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'),
                                array('1', 'true'), true) ? 1 : 0,
        'udpport'   => (int) $hol('Udpinport', 'udpinport'),
    );
}

/** Dieselbe Saeuberung wie im Dienst - fuer die Selbstpruefung. */
function eb_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function eb_mqtt_themen()
{
    return array(
        'ein'            => 'EB_MQTT.EIN',
        'netz'           => 'EB_MQTT.NETZ',
        'erzeugung'      => 'EB_MQTT.ERZEUGUNG',
        'ueberschuss'    => 'EB_MQTT.UEBERSCHUSS',
        'grenze'         => 'EB_MQTT.GRENZE',
        'ladesoll'       => 'EB_MQTT.LADESOLL',
        'anlass'         => 'EB_MQTT.ANLASS',
        'notfall'        => 'EB_MQTT.NOTFALL',
        'wirkung'        => 'EB_MQTT.WIRKUNG',
        'alter'          => 'EB_MQTT.ALTER',
        'stellerN/name'  => 'EB_MQTT.S_NAME',
        'stellerN/watt'  => 'EB_MQTT.S_WATT',
        'stellerN/ok'    => 'EB_MQTT.S_OK',
    );
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * ================================================================== */

function eb_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . eb_x($kopf['title']) . '" ';
    $o .= 'Comment="' . eb_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . eb_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . eb_x(isset($kopf['polling']) ? $kopf['polling'] : '10') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . eb_x($c['title']) . '" ';
        $o .= 'Comment="' . eb_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . eb_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . eb_x(isset($c['min']) ? $c['min'] : '-100') . '" ';
        $o .= 'MaxVal="' . eb_x(isset($c['max']) ? $c['max'] : '100') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Summenfelder.
 *
 * NETZ geht ausdruecklich ins Minus - das ist die Einspeisung und der
 * eigentliche Gegenstand des Plugins. Eine Untergrenze von 0 machte aus
 * jeder Einspeisung stillschweigend eine Null.
 * WIRKUNG kennt -1 fuer "gestellt, aber nichts passiert"; auch das darf
 * nicht auf 0 ("noch offen") gequetscht werden.
 */
function eb_felder()
{
    return array(
        'EIN'         => array('',  0,       1,       'EB_FELD.EIN'),
        'NETZ'        => array('W', -200000, 200000,  'EB_FELD.NETZ'),
        'ERZEUGUNG'   => array('W', 0,       200000,  'EB_FELD.ERZEUGUNG'),
        'UEBERSCHUSS' => array('W', -200000, 200000,  'EB_FELD.UEBERSCHUSS'),
        'GRENZE'      => array('W', 0,       200000,  'EB_FELD.GRENZE'),
        'LADESOLL'    => array('W', 0,       200000,  'EB_FELD.LADESOLL'),
        'NOTFALL'     => array('',  0,       1,       'EB_FELD.NOTFALL'),
        'WIRKUNG'     => array('',  -1,      1,       'EB_FELD.WIRKUNG'),
        'ALTER'       => array('s', -1,      86400,   'EB_FELD.ALTER'),
    );
}

function eb_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(eb_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

function eb_endpunkt()
{
    $p = eb_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php';
}

function eb_vorlage()
{
    $cmds = array();
    foreach (eb_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'EB_' . $feld,
            'comment' => eb_klartext($info[3]) . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[1],
            'max'     => $info[2],
        );
    }
    foreach (eb_steller() as $nr => $s) {
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s['name']));
        if ($kurz === '') { $kurz = 'STELLER' . $nr; }
        $kurz = substr($kurz, 0, 12);
        $cmds[] = array(
            'title'   => 'EB_' . $kurz . '_WATT',
            'comment' => $s['name'] . ': ' . eb_klartext('EB_FELD.S_WATT') . ' [W]',
            'check'   => '\iS' . $nr . 'W=\i\v',
            'min'     => 0, 'max' => 200000,
        );
        $cmds[] = array(
            'title'   => 'EB_' . $kurz . '_OK',
            'comment' => $s['name'] . ': ' . eb_klartext('EB_FELD.S_OK'),
            'check'   => '\iS' . $nr . 'OK=\i\v',
            'min'     => 0, 'max' => 1,
        );
    }
    $adresse = eb_endpunkt() . '?token=' . eb_token() . '&aktion=status';
    return array('VI_EINSPEISEBREMSE.xml', eb_xml_virtual_in_http(array(
        'title'   => 'Einspeisebremse',
        'address' => $adresse,
        'polling' => '10',
        'comment' => sprintf(eb_klartext('EB_XML.KOPF'), date('d.m.Y')),
    ), $cmds));
}

/** Die Statuszeile fuer den Miniserver. */
function eb_zeile($stand)
{
    $w = function ($v) { return ($v === null || !is_numeric($v)) ? '-' : (string) (int) round($v); };
    $h = function ($k) use ($stand) { return isset($stand[$k]) ? $stand[$k] : null; };
    $o = sprintf("EINSPEISEBREMSE;EIN=%d;NETZ=%s;ERZEUGUNG=%s;UEBERSCHUSS=%s;GRENZE=%s;LADESOLL=%s;NOTFALL=%d;WIRKUNG=%s;ALTER=%d\n",
        empty($stand['ein']) ? 0 : 1,
        $w($h('netz')), $w($h('erzeugung')), $w($h('ueberschuss_w')),
        $w($h('drossel_w')), $w($h('lade_soll_w')),
        empty($stand['notfall']) ? 0 : 1,
        $h('wirkung') === null ? '-' : (string) (int) $h('wirkung'),
        eb_alter());
    foreach ((array) (isset($stand['steller']) ? $stand['steller'] : array()) as $nr => $e) {
        $o .= sprintf("STELLER%d;S%dW=%s;S%dOK=%s\n",
            (int) $nr, (int) $nr, $w(isset($e['watt']) ? $e['watt'] : null),
            (int) $nr, $w(isset($e['ok']) ? $e['ok'] : null));
    }
    return $o;
}

/* ==================================================================
 * Sprache - Englisch ist die Rueckfallebene, nicht Deutsch
 * ================================================================== */

function eb_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/**
 * Der Ordner mit den Sprachdateien.
 *
 * Gesucht wird der Ordner, der wirklich eine language_de.ini enthaelt -
 * nicht einer, aus dem man auf ihn schliessen koennte. Genau daran ist das
 * Kodi-Plugin gescheitert: dort wurde vom Konfigurations- auf den
 * Vorlagenordner geschlossen, und die ganze Oberflaeche stand
 * unbeschriftet da, ohne dass irgendwo ein Fehler auftauchte.
 */
function eb_langdir()
{
    static $gefunden = null;
    if ($gefunden !== null) { return $gefunden; }
    $p = eb_paths();
    $k = array();
    if ($p['home'] !== '') {
        $k[] = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        $k[] = $p['home'] . '/templates/plugins/einspeisebremse/lang';
    }
    $k[] = dirname(dirname(__DIR__)) . '/templates/lang';
    $k[] = dirname(dirname(dirname(__DIR__))) . '/templates/lang';
    foreach ($k as $d) {
        if (is_file($d . '/language_de.ini') || is_file($d . '/language_en.ini')) {
            $gefunden = $d;
            return $gefunden;
        }
    }
    $gefunden = '';
    return $gefunden;
}

function eb_sprache_fehlt() { return eb_langdir() === ''; }

function eb_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $pfad = eb_langdir();
        $texte = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_' . eb_sprache() . '.ini', true, INI_SCANNER_RAW)
            : array();
        if (!is_array($texte)) { $texte = array(); }
        $rueck = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW) : array();
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}
