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

/**
 * Die Messquellen - EINMAL, in Anzeigereihenfolge.
 *
 * Diese Liste stand bis 0.9.8 an SECHS Stellen im Programm: beim
 * MQTT-Abo, beim Einlesen der Konfiguration, in der Maengelpruefung,
 * im Prueflauf --probe und zweimal in der Oberflaeche. Eine davon war
 * bereits auseinandergelaufen - das MQTT-Abo kannte den Ersatzzaehler
 * nicht, weshalb ein Ersatzzaehler ueber MQTT nie einen Wert lieferte.
 *
 * Seither stehen sie hier, und die sechs Stellen leiten daraus ab. Eine
 * weitere Quelle ist damit eine Zeile und keine Suche.
 *
 *   bez    Schluessel der Beschriftung in den Sprachdateien
 *   kurz   Klartext fuer den Dienst, der keine Uebersetzung laedt
 *   summe  gesetzt bei den Quellen, die zur ERZEUGUNG addiert werden
 */
function eb_quellenfelder()
{
    return array(
        'q_netz'       => array('bez' => 'QUELLE.NETZ',       'kurz' => 'Netz'),
        'q_netz2'      => array('bez' => 'QUELLE.NETZ2',      'kurz' => 'Netz (Ersatz)'),
        'q_erzeugung'  => array('bez' => 'QUELLE.ERZEUGUNG',  'kurz' => 'Erzeugung',   'summe' => 1),
        'q_erzeugung2' => array('bez' => 'QUELLE.ERZEUGUNG2', 'kurz' => 'Erzeugung 2', 'summe' => 1),
        'q_erzeugung3' => array('bez' => 'QUELLE.ERZEUGUNG3', 'kurz' => 'Erzeugung 3', 'summe' => 1),
        'q_soc'        => array('bez' => 'QUELLE.SOC',        'kurz' => 'Ladestand'),
        'q_lade'       => array('bez' => 'QUELLE.LADE',       'kurz' => 'Ladeleistung'),
    );
}

/** Die Quellen, die zur Erzeugung addiert werden - in ihrer Reihenfolge. */
function eb_erzeugungsfelder()
{
    $r = array();
    foreach (eb_quellenfelder() as $k => $f) {
        if (!empty($f['summe'])) { $r[] = $k; }
    }
    return $r;
}

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
        'modbus' => 'EB_QUELLE.MODBUS',
    );
}

function eb_steller_vorgabe()
{
    return array(
        /* 'stilllegen' statt eines 'aktiv': ein fehlendes Feld bedeutet
         * dann "in Betrieb", und bestehende Anlagen behalten nach einem
         * Update ihre Stellglieder. */
        'name' => '', 'stilllegen' => 0, 'art' => 'aus', 'adresse' => '',
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
        'sunspec'   => 'EB_STELL.SUNSPEC',
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
    $v = array(
        'ein'             => 0,      // die Regelung selbst - aus bis der Mensch ja sagt
        'ziel_w'          => 0,
        /* Zwei weitere Zielwerte und die gewaehlte Stufe. Loxone waehlt
         * ueber den Endpunkt AUS, es gibt keinen Sollwert von aussen: der
         * schlimmste denkbare Fall ist die schaerfste Stufe, die der
         * Betreiber selbst eingetragen hat. */
        'ziel1_w'         => 0,
        'ziel2_w'         => 0,
        'stufe'           => 0,
        'bilanz_ein'      => 1,
        'verlauf_ein'     => 1,
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
        /* Wie alt ein Wert der NEBENquellen (Erzeugung, Ladestand,
         * Ladeleistung) hoechstens sein darf. Bis 0.9.4 hatte nur der
         * Netzzaehler eine Altersgrenze; ein retained MQTT-Thema lieferte
         * nach einem Geraeteausfall stundenlang denselben Wert, und fuer
         * den Kern war er frisch. Beim Ladestand ist das unmittelbar
         * gefaehrlich: ein alter Wert von 40 Prozent laesst den Speicher
         * als aufnahmefaehig erscheinen. */
        'quelle_alter_s'  => 300,
        /* Die Grenze, die beim AUSSCHALTEN der Regelung einmal gestellt
         * wird. Hoch angesetzt, weil "aus" heissen soll: die Anlage darf
         * wieder alles. Eine 0 waere hier die gefaehrlichste Vorgabe von
         * allen - sie schaltete die Anlage beim Ausschalten der Bremse ab. */
        'frei_w'          => 100000,
        'takt'            => 5,
        'q_netz'          => null,
        /* Der Ersatzzaehler. Der ausgefallene Zaehler ist einer der drei
         * Punkte, die das README als Kern benennt - bis 0.9.5 gab es
         * trotzdem nur eine Quelle. Der Ersatz wird gezogen, BEVOR der
         * Notbetrieb greift, und er wird angezeigt: sonst wird aus dem
         * Ersatz unbemerkt der Normalfall. */
        'q_netz2'         => null,
        /* Die uebrigen Quellen kommen aus eb_quellenfelder() - siehe dort,
         * warum diese Liste nur noch an einer Stelle steht. q_netz und
         * q_netz2 bleiben hier stehen, weil ihre Kommentare hierher
         * gehoeren; doppelt gesetzt schadet nichts. */
        'steller'         => array(),
        /* Der Weg zum Speicher. Ohne ihn ist das Ladesoll nur eine Zahl
         * auf dem Bildschirm: der Kern rechnet mit einem Freiheitsgrad,
         * den niemand stellt. Gleiche Form wie ein Stellglied. */
        'sp_steller'      => array(),
        'mqtt_ein'        => 1,
        'mqtt_topic'      => 'einspeisebremse',
        'aktionstoken'    => '',
    );
    /* Jede Messquelle bekommt ihren Platz - aus der EINEN Liste. Was oben
     * schon steht, bleibt dort (mitsamt seinem Kommentar); alles Weitere
     * kommt von hier. So kann keine Quelle mehr vergessen werden. */
    foreach (array_keys(eb_quellenfelder()) as $k) {
        if (!array_key_exists($k, $v)) { $v[$k] = null; }
    }
    return $v;
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

/**
 * Eine Modbus-Adresse zerlegen. Rueckgabe: array oder null.
 *
 * Form:  IP[:Port]/Geraeteadresse/Register/Typ[/Funktionscode]
 * Beispiel: 192.168.178.40:502/1/52/float32/4
 *
 * ES WIRD NICHTS ZURECHTGEBOGEN. Was nicht auf das Muster passt, ergibt
 * null, und der Reiter Test sagt es. Ein halb verstandener Registerwert
 * ist schlimmer als gar keiner: er sieht aus wie ein Messwert.
 */
function eb_modbus_zerlegen($adresse)
{
    $m = array();
    $muster = '#^([0-9A-Za-z\.\-]+)(?::([0-9]{1,5}))?/([0-9]{1,3})/([0-9]{1,5})'
            . '/(float32|int32|uint32|int16|uint16)(?:/([34]))?$#';
    if (!preg_match($muster, trim((string) $adresse), $t)) { return null; }
    $port = ($t[2] === '' || !isset($t[2])) ? 502 : (int) $t[2];
    if ($port < 1 || $port > 65535) { return null; }
    $id = (int) $t[3];
    if ($id < 0 || $id > 247) { return null; }
    return array(
        'host' => $t[1], 'port' => $port, 'id' => $id,
        'reg' => (int) $t[4], 'typ' => $t[5],
        'fc' => (isset($t[6]) && $t[6] !== '') ? (int) $t[6] : 4,
    );
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

    foreach (array_keys(eb_quellenfelder()) as $k) {
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
        $s['stilllegen'] = empty($s['stilllegen']) ? 0 : 1;
        unset($s['aktiv']);
        $s['spitze_w'] = max(0, min(1000000, (int) eb_zahl($s['spitze_w'], 0)));
        $s['anteil'] = max(0, min(100, (int) eb_zahl($s['anteil'], 0)));
        $cfg['steller'][$i] = $s;
    }

    /* Der Speicher-Stelleintrag wird genauso gerichtet wie ein Stellglied. */
    $sp = is_array($cfg['sp_steller']) ? $cfg['sp_steller'] : array();
    $sp += eb_steller_vorgabe();
    $sp['name'] = trim((string) $sp['name']);
    if (!isset($sa[$sp['art']])) { $sp['art'] = 'aus'; }
    if (!isset($eh[$sp['einheit']])) { $sp['einheit'] = 'W'; }
    $sp['spitze_w'] = max(0, min(1000000, (int) eb_zahl($sp['spitze_w'], 0)));
    $sp['anteil'] = 0;
    $sp['stilllegen'] = empty($sp['stilllegen']) ? 0 : 1;
    unset($sp['aktiv']);
    $cfg['sp_steller'] = $sp;

    $cfg['ein'] = empty($cfg['ein']) ? 0 : 1;
    $cfg['speicher_zuerst'] = empty($cfg['speicher_zuerst']) ? 0 : 1;
    $cfg['mqtt_ein'] = empty($cfg['mqtt_ein']) ? 0 : 1;
    $cfg['bilanz_ein'] = empty($cfg['bilanz_ein']) ? 0 : 1;
    $cfg['verlauf_ein'] = empty($cfg['verlauf_ein']) ? 0 : 1;
    $cfg['stufe'] = max(0, min(2, (int) eb_zahl($cfg['stufe'], 0)));
    foreach (array('ziel_w' => array(0, 1000000), 'ziel1_w' => array(0, 1000000),
                   'ziel2_w' => array(0, 1000000), 'totband_w' => array(0, 10000),
                   'rampe_ab_w' => array(10, 1000000), 'rampe_auf_w' => array(10, 1000000),
                   'drossel_min_w' => array(0, 1000000), 'notfall_s' => array(5, 3600),
                   'notfall_w' => array(0, 1000000), 'soc_max' => array(10, 100),
                   'lade_max_w' => array(0, 1000000), 'wirkung_s' => array(5, 600), 'frei_w' => array(0, 1000000),
                   'quelle_alter_s' => array(10, 86400),
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

/**
 * Fertige Vorbelegungen fuer die Messquellen.
 *
 * JEDES Feld hier ist an einem echten Geraet gemessen. Was nicht gemessen
 * ist, steht nicht drin - lieber ein leeres Feld als ein geratener Pfad,
 * der aussieht, als haette ihn jemand nachgesehen.
 *
 * %s in der Adresse wird durch die Angabe des Benutzers ersetzt (IP oder
 * Themen-Praefix). 'invertieren' folgt der Hausregel plus = Bezug.
 */
function eb_quellvorlagen()
{
    return array(
        'hand' => array(
            'bez' => 'VORLAGE.HAND', 'hinweis' => 'VORLAGE.HAND_HINWEIS',
            'feld' => '', 'vorgabe' => '', 'quellen' => array(),
        ),
        /* Gemessen am 19.08.2026 an den Themen einer laufenden Anlage.
         * OBIS 16.7.0 ist die momentane Summenwirkleistung. OB DIESER
         * ZAEHLER DAS VORZEICHEN FUEHRT, ist damit noch nicht belegt -
         * deshalb steht es im Hinweis und nicht als stille Annahme. */
        'smartmeter' => array(
            'bez' => 'VORLAGE.SMARTMETER', 'hinweis' => 'VORLAGE.SMARTMETER_HINWEIS',
            'feld' => 'VORLAGE.F_PRAEFIX', 'vorgabe' => 'smartmeter/0047',
            'quellen' => array(
                'q_netz' => array('art' => 'mqtt', 'adresse' => '%s/Total_Power_OBIS_16.7.0',
                                  'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0),
            ),
        ),
        /* Gemessen am 19.08.2026, 00:23 Uhr, an einem Symo Hybrid:
         *   P_Grid  -0.51   P_PV 0.887   P_Akku 726.9   P_Load -693.49   SOC 57
         * Nachts, PV praktisch null, Haus zieht 693 W, der Speicher liefert
         * 727 W. Daraus folgt belegt: P_Akku POSITIV heisst ENTLADEN, also
         * wird die Ladeleistung umgedreht. P_Grid folgt bereits der
         * Hausregel (plus = Bezug) und wird NICHT umgedreht. */
        /* Register 52, Funktionscode 4, Wert in Watt - belegt aus der
         * Loxone-Vorlage MB_Eastron SDM630-TCP.xml, Kanal
         * "027 - Total system power": ModbusAddress 52, ModbusCmd 4,
         * Anzeige in kW mit SourceValHigh 1000, also liefert das Register
         * Watt. Ob es vorzeichenbehaftet ist, steht dort nicht - deshalb
         * der Hinweis statt einer stillen Annahme. */
        'eastron' => array(
            'bez' => 'VORLAGE.EASTRON', 'hinweis' => 'VORLAGE.EASTRON_HINWEIS',
            'feld' => 'VORLAGE.F_IP', 'vorgabe' => '192.168.178.40',
            'quellen' => array(
                'q_netz' => array('art' => 'modbus', 'adresse' => '%s:502/1/52/float32/4',
                                  'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0),
            ),
        ),
        'fronius' => array(
            'bez' => 'VORLAGE.FRONIUS', 'hinweis' => 'VORLAGE.FRONIUS_HINWEIS',
            'feld' => 'VORLAGE.F_IP', 'vorgabe' => '192.168.178.31',
            'quellen' => array(
                'q_netz' => array('art' => 'http',
                    'adresse' => 'http://%s/solar_api/v1/GetPowerFlowRealtimeData.fcgi',
                    'pfad' => 'Body.Data.Site.P_Grid', 'faktor' => 1.0, 'invertieren' => 0),
                'q_erzeugung' => array('art' => 'http',
                    'adresse' => 'http://%s/solar_api/v1/GetPowerFlowRealtimeData.fcgi',
                    'pfad' => 'Body.Data.Site.P_PV', 'faktor' => 1.0, 'invertieren' => 0),
                'q_soc' => array('art' => 'http',
                    'adresse' => 'http://%s/solar_api/v1/GetPowerFlowRealtimeData.fcgi',
                    'pfad' => 'Body.Data.Inverters.1.SOC', 'faktor' => 1.0, 'invertieren' => 0),
                'q_lade' => array('art' => 'http',
                    'adresse' => 'http://%s/solar_api/v1/GetPowerFlowRealtimeData.fcgi',
                    'pfad' => 'Body.Data.Site.P_Akku', 'faktor' => 1.0, 'invertieren' => 1),
            ),
        ),
        /* Alles hier ist am 19.08.2026 an einem Symo Hybrid GEMESSEN,
         * nichts aus einer Handbuchtabelle abgezaehlt.
         *
         * Adressregel des Geraets: Draht = Startadresse(Handbuch) + Offset
         * - 1. Die Modelle liegen NICHT an festen Adressen, sondern in
         * einer Kette; die Zahlen unten gelten fuer einen Symo Hybrid mit
         * Gleitkomma-Einstellung ("float", Modell 113). Steht ein Geraet
         * auf "int+SF", verschiebt sich alles um zehn Register - dann
         * zeigt der Reiter Test mit --sunspec die richtige Kette.
         *
         * NETZ, Draht 40097, Geraeteadresse 240: der Zaehler haengt am
         * Datamanager und wird ueber dessen Gateway erreicht, deshalb 240
         * und nicht 1. Belegt durch Gegenprobe gegen die Solar API: bei
         * 180,79 W Bezug meldeten beide dieselbe Zahl mit demselben
         * Vorzeichen. Also plus = Bezug, NICHT umdrehen.
         *
         * ERZEUGUNG, Draht 40091, Geraeteadresse 1: das ist die
         * WECHSELRICHTER-AUSGANGSLEISTUNG aus Modell 113, NICHT die
         * PV-Erzeugung. Beim Hybrid steckt die Entladung des Speichers
         * mit darin. Am 19.08.2026 nachts gemessen: Modbus 763 W,
         * waehrend die Solar API P_PV mit 2,15 W und P_Akku mit 799,6 W
         * meldete - die 763 W kamen also fast vollstaendig aus dem
         * Speicher. Wer nachts "Erzeugung 700 W" liest, sieht keinen
         * Fehler, sondern den Speicher.
         *
         * Und es ist trotzdem der RICHTIGE Wert fuer dieses Plugin: der
         * Kern rechnet die neue Grenze als "Erzeugung minus Ueberschuss"
         * (eb_regeln), und die Grenze wirkt ueber WMaxLimPct auf genau
         * diese Ausgangsleistung. Beides muss dieselbe Groesse sein.
         * P_PV waere die DC-Seite und damit die falsche.
         *
         * LADESTAND, Draht 40321, Geraeteadresse 1: ChaState aus Modell
         * 124, Rohwert mit Faktor -2, also mal 0,01. Belegt gegen die
         * Solar API: 37 zu 37 und 38 zu 38.
         *
         * LADELEISTUNG bleibt bei der Solar API. SunSpec fuehrt in Modell
         * 124 KEIN Register fuer die Lade- oder Entladeleistung - es gibt
         * dort schlicht nichts zu lesen. Eine gemischte Vorlage ist
         * ehrlicher als ein erfundenes Register. P_Akku ist beim ENTLADEN
         * positiv und wird deshalb umgedreht. */
        'fronius_modbus' => array(
            'bez' => 'VORLAGE.FRONIUS_MODBUS', 'hinweis' => 'VORLAGE.FRONIUS_MODBUS_HINWEIS',
            'feld' => 'VORLAGE.F_IP', 'vorgabe' => '192.168.178.31',
            'quellen' => array(
                'q_netz' => array('art' => 'modbus', 'adresse' => '%s:502/240/40097/float32/3',
                                  'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0),
                'q_erzeugung' => array('art' => 'modbus', 'adresse' => '%s:502/1/40091/float32/3',
                                       'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0),
                'q_soc' => array('art' => 'modbus', 'adresse' => '%s:502/1/40321/uint16/3',
                                 'pfad' => '', 'faktor' => 0.01, 'invertieren' => 0),
                'q_lade' => array('art' => 'http',
                    'adresse' => 'http://%s/solar_api/v1/GetPowerFlowRealtimeData.fcgi',
                    'pfad' => 'Body.Data.Site.P_Akku', 'faktor' => 1.0, 'invertieren' => 1),
            ),
        ),
        /* Der ZWEITE Wechselrichter - eine eigene Vorlage, kein zweites
         * Feld in der ersten.
         *
         * Der Grund steht in der Mechanik des Vorlagenwerks: es setzt EINE
         * Angabe des Anwenders in alle Adressen ein. Beim zweiten Geraet
         * unterscheiden sich aber ZWEI Dinge - die Adresse des
         * Datamanagers UND die Geraeteadresse dahinter. Am 19.08.2026
         * gemessen: der Hybrid liegt auf 192.168.178.31 unter 1, der Symo
         * 8.2 auf 192.168.178.30 unter 2.
         *
         * Deshalb fragt diese Vorlage nicht nach der IP, sondern nach
         * "Host:Port/Geraeteadresse" - genau dem Stueck, das sich
         * unterscheidet. Das ist eine Handvoll Zeichen mehr zu tippen und
         * dafuer nichts geraten. Wer nur eine IP eintraegt, bekommt eine
         * Adresse, die nicht auf das Muster passt, und der Reiter Test
         * sagt es - statt still das falsche Geraet zu lesen.
         *
         * Register 40091 ist dasselbe wie beim ersten Geraet: die
         * Ausgangsleistung aus Inverter Model 113. Sie wird zur ersten
         * Erzeugung ADDIERT. */
        'fronius_modbus_zweiter' => array(
            'bez' => 'VORLAGE.FRONIUS_MODBUS_ZWEITER',
            'hinweis' => 'VORLAGE.FRONIUS_MODBUS_ZWEITER_HINWEIS',
            'feld' => 'VORLAGE.F_GERAET', 'vorgabe' => '192.168.178.30:502/2',
            'quellen' => array(
                'q_erzeugung2' => array('art' => 'modbus', 'adresse' => '%s/40091/float32/3',
                                        'pfad' => '', 'faktor' => 1.0, 'invertieren' => 0),
            ),
        ),
    );
}

/**
 * Die erlaubte Einspeisung der gewaehlten Stufe.
 *
 * Stufe 0 ist der Wert, der schon immer 'ziel_w' hiess; 1 und 2 sind
 * zusaetzlich eintragbar. Aus Loxone laesst sich nur die STUFE waehlen,
 * nie ein Wert: das Wortzeichen steht offen in der Adresse, und wer damit
 * eine Zahl setzen koennte, koennte die Anlage abschalten. Eine Stufe
 * dagegen fuehrt hoechstens zu dem, was der Betreiber selbst eingetragen
 * hat - im schlimmsten Fall zur schaerfsten der drei.
 */
function eb_ziel_w($cfg)
{
    $stufe = max(0, min(2, (int) eb_zahl(isset($cfg['stufe']) ? $cfg['stufe'] : 0, 0)));
    $schluessel = array(0 => 'ziel_w', 1 => 'ziel1_w', 2 => 'ziel2_w');
    $k = $schluessel[$stufe];
    return max(0.0, eb_zahl(isset($cfg[$k]) ? $cfg[$k] : 0, 0.0));
}

/**
 * Die Adresse, unter der der Miniserver den LoxBerry erreicht - oder ''.
 *
 * eb_endpunkt() nimmt den Namen, unter dem der ANWENDER gerade diese Seite
 * aufgerufen hat. Wer die Oberflaeche ueber localhost oeffnet, laedt eine
 * Vorlage herunter, die im Miniserver auf ihn selbst zeigt. Diese Funktion
 * sagt, ob das gerade droht.
 */
function eb_adresse_zweifelhaft()
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    $ohne_port = preg_replace('/:\\d+$/', '', $host);
    return in_array($ohne_port, array('localhost', '127.0.0.1', '::1', 'loxberry.local'), true)
           || $ohne_port === '';
}

/** Die eigene Adresse im Netz, soweit das SDK sie kennt. */
function eb_lan_adresse()
{
    /* Nicht in der Attrappe vorhanden heisst nicht "gibt es nicht" - deshalb
     * abgesichert und nicht vorausgesetzt. Vier Linien des Bestands benutzen
     * dieselbe Funktion. */
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        $ip = trim((string) @LBSystem::get_localip());
        if ($ip !== '') { return $ip; }
    }
    return '';
}

/* ==================================================================
 * Bilanz und Verlauf
 *
 * WAS HIER NICHT STEHT, UND WARUM: eine Kilowattstundenzahl fuer den
 * entgangenen Ertrag. Sie waere erfunden. Um sie zu bilden, muesste
 * bekannt sein, was die Anlage OHNE Grenze geliefert haette - und das
 * weiss niemand, denn sie hat es nicht geliefert. Gezaehlt wird deshalb
 * nur, was gemessen ist, plus die DAUER der Abregelung. Eine Zahl, die
 * niemand gemessen hat, darf nicht aussehen wie eine, die jemand gemessen
 * hat.
 * ================================================================== */

function eb_bilanz() { return eb_json_lesen(eb_paths()['datadir'] . '/bilanz.json'); }

/** Ein leerer Zaehlersatz. Alles in Wattsekunden, erst die Anzeige rechnet um. */
function eb_bilanz_leer()
{
    return array('erzeugt_ws' => 0.0, 'eingespeist_ws' => 0.0, 'bezogen_ws' => 0.0,
                 'speicher_ws' => 0.0, 'gedrosselt_s' => 0.0,
                 'erzeugt_gemessen' => 0, 'speicher_gemessen' => 0);
}

function eb_kwh($ws) { return eb_zahl($ws, 0.0) / 3600000.0; }

function eb_verlauf() { return eb_json_lesen(eb_paths()['datadir'] . '/verlauf.json'); }

/**
 * Der Verlauf als SVG - ohne fremde Bibliothek und ohne Skript.
 *
 * Drei Linien: Zaehlerwert, gestellte Grenze, Ladesoll. Die Nulllinie ist
 * beschriftet, denn beim Zaehlerwert ist das Vorzeichen die halbe Aussage.
 */
function eb_verlauf_svg($breite = 940, $hoehe = 200)
{
    $v = eb_verlauf();
    $p = isset($v['punkte']) && is_array($v['punkte']) ? $v['punkte'] : array();
    if (count($p) < 2) { return ''; }
    $min = 0.0; $max = 0.0;
    foreach ($p as $e) {
        foreach (array(1, 2, 3) as $i) {
            if (!isset($e[$i]) || $e[$i] === null) { continue; }
            $w = eb_zahl($e[$i], 0.0);
            if ($w < $min) { $min = $w; }
            if ($w > $max) { $max = $w; }
        }
    }
    if ($max - $min < 100.0) { $max = $min + 100.0; }
    $rand = 26;
    $x = function ($i) use ($p, $breite, $rand) {
        return $rand + ($breite - 2 * $rand) * ($i / max(1, count($p) - 1));
    };
    $y = function ($w) use ($min, $max, $hoehe, $rand) {
        return $hoehe - $rand - ($hoehe - 2 * $rand) * ((eb_zahl($w, 0.0) - $min) / ($max - $min));
    };
    $farben = array(1 => '#b00000', 2 => '#6dac20', 3 => '#546e7a');
    $o = '<svg viewBox="0 0 ' . (int) $breite . ' ' . (int) $hoehe . '" width="100%" '
       . 'height="' . (int) $hoehe . '" role="img">';
    $o .= '<rect x="0" y="0" width="' . (int) $breite . '" height="' . (int) $hoehe
       . '" fill="#fafafa" stroke="#ddd"/>';
    if ($min < 0.0 && $max > 0.0) {
        $y0 = $y(0);
        $o .= '<line x1="' . $rand . '" y1="' . round($y0, 1) . '" x2="' . ($breite - $rand)
            . '" y2="' . round($y0, 1) . '" stroke="#999" stroke-dasharray="3,3"/>';
        $o .= '<text x="2" y="' . round($y0 + 4, 1) . '" font-size="11" fill="#666">0</text>';
    }
    $o .= '<text x="2" y="14" font-size="11" fill="#666">' . (int) round($max) . ' W</text>';
    $o .= '<text x="2" y="' . ((int) $hoehe - 4) . '" font-size="11" fill="#666">'
        . (int) round($min) . ' W</text>';
    foreach ($farben as $i => $farbe) {
        $d = ''; $offen = false;
        foreach ($p as $k => $e) {
            if (!isset($e[$i]) || $e[$i] === null) { $offen = false; continue; }
            $d .= ($offen ? ' L ' : ' M ') . round($x($k), 1) . ' ' . round($y($e[$i]), 1);
            $offen = true;
        }
        if ($d !== '') {
            $o .= '<path d="' . trim($d) . '" fill="none" stroke="' . $farbe . '" stroke-width="1.6"/>';
        }
    }
    return $o . '</svg>';
}

/**
 * Nur die Stellglieder mit Namen und eingeschalteter Art.
 *
 * DIE NUMMER IST DER PLATZ IM FORMULAR, NICHT EINE LAUFENDE ZAEHLUNG.
 * Daran haengt alles, was das Geraet nach aussen kennzeichnet: das
 * MQTT-Thema stellerN/..., die Zeile STELLERn;SnW=... und der
 * Pruefausdruck der Loxone-Vorlage.
 *
 * Bis 0.9.3 wurde fortlaufend ueber die AUSGEFUELLTEN Zeilen gezaehlt.
 * Wer das erste Geraet herausnahm, verschob damit alle folgenden: der
 * bereits eingespielte virtuelle Eingang EB_OST_WATT las weiter \iS1W=
 * und zeigte ab da die Leistung von West. Der Name blieb, der Inhalt
 * wechselte, und nirgends stand ein Fehler - die schlimmste Fehlerart.
 * Gemessen am 18.08.2026.
 *
 * Die Nummern koennen deshalb Luecken haben: sind nur die Zeilen 1 und 3
 * belegt, gibt es steller1 und steller3 und kein steller2. Das ist die
 * Absicht - eine Luecke ist harmlos, eine Verschiebung nicht.
 */
function eb_steller()
{
    $out = array();
    foreach (eb_config()['steller'] as $i => $s) {
        if (trim((string) $s['name']) === '' || $s['art'] === 'aus') { continue; }
        // Stillgelegt heisst: bleibt eingetragen, bekommt aber nichts mehr.
        if (!empty($s['stilllegen'])) { continue; }
        $nr = (int) $i + 1;
        $s['nr'] = $nr;
        $out[$nr] = $s;
    }
    return $out;
}

/**
 * Der Stell-Eintrag fuer den Speicher, oder null.
 *
 * Bis 0.9.4 hatte das Ladesoll gar keinen Weg nach draussen: der Kern hat
 * es ausgerechnet, die Oberflaeche hat es angezeigt, und ob es jemand
 * umsetzt, wusste niemand. Wer hier nichts eintraegt, aendert daran nichts
 * - dann arbeitet die neue Wirkungspruefung im Kern und regelt ab, statt
 * auf einen Speicher zu hoffen.
 */
function eb_speicher_steller()
{
    $s = eb_config()['sp_steller'];
    if ($s['art'] === 'aus' || trim((string) $s['adresse']) === '') { return null; }
    if (!empty($s['stilllegen'])) { return null; }
    $s['nr'] = 0;
    if ($s['name'] === '') { $s['name'] = 'Speicher'; }
    return $s;
}

/**
 * Was die Anlage hoechstens kann - die Summe der eingetragenen
 * Spitzenleistungen.
 *
 * 0 heisst UNBEKANNT und nicht "null Watt": fehlt auch nur bei einem
 * eingeschalteten Stellglied die Spitze, laesst sich die Summe nicht
 * bilden, und dann wird nicht gedeckelt. Der Deckel verhindert, dass die
 * Regelung nach dem Wiedereinschalten vom Freigabewert herunterrampt und
 * dabei Grenzen stellt, die gar keine sind (gemessen 98000 W).
 */
function eb_anlage_max($steller = null)
{
    if ($steller === null) { $steller = eb_steller(); }
    if (!$steller) { return 0; }
    $summe = 0;
    foreach ($steller as $s) {
        $sp = (int) $s['spitze_w'];
        if ($sp <= 0) { return 0; }
        $summe += $sp;
    }
    return $summe;
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
    /* Eine Modbus-Adresse, die nicht auf das Muster passt, wird hier
     * beanstandet - nicht erst im Betrieb, wo sie nur eine leere Messung
     * ergaebe. */
    foreach (array_keys(eb_quellenfelder()) as $mk) {
        if ($cfg[$mk]['art'] === 'modbus' && $cfg[$mk]['adresse'] !== ''
            && eb_modbus_zerlegen($cfg[$mk]['adresse']) === null) {
            $m[] = 'MANGEL.MODBUS_FORM';
        }
    }
    if ($cfg['q_netz2']['art'] !== 'aus' && $cfg['q_netz2']['adresse'] === '') {
        $m[] = 'MANGEL.ERSATZ_OHNE_ADRESSE';
    }
    /* Die weiteren Erzeugungsquellen werden ADDIERT, nicht ersetzt. Steht
     * eine auf einer Art ohne Adresse, liefert sie nichts - und weil eine
     * Summe alles oder nichts ist, faellt damit die GANZE
     * Erzeugungsmessung aus. Das ist schlimmer als gar keine weitere
     * Quelle und wird deshalb ausdruecklich beanstandet. */
    $eb_ef = eb_erzeugungsfelder();
    $eb_erste = array_shift($eb_ef);
    foreach ($eb_ef as $k) {
        if ($cfg[$k]['art'] === 'aus') { continue; }
        if ($cfg[$k]['adresse'] === '') { $m[] = 'MANGEL.ERZEUGUNG_WEITERE_OHNE_ADRESSE'; }
        if ($cfg[$eb_erste]['art'] === 'aus') { $m[] = 'MANGEL.ERZEUGUNG_WEITERE_OHNE_ERSTE'; }
    }
    /* Zweimal dasselbe Geraet waere eine doppelte Zaehlung: die Erzeugung
     * erschiene doppelt so gross, die Grenze wuerde zu hoch gesetzt und
     * die Bremse bremste zu wenig. Mit drei Feldern und Zwischenablage ist
     * das ein realistischer Griff, deshalb wird es geprueft. */
    $eb_gesehen = array();
    foreach (eb_erzeugungsfelder() as $k) {
        $a = strtolower(trim((string) $cfg[$k]['adresse']));
        $p = strtolower(trim((string) $cfg[$k]['pfad']));
        if ($cfg[$k]['art'] === 'aus' || $a === '') { continue; }
        $marke = $cfg[$k]['art'] . '|' . $a . '|' . $p;
        if (isset($eb_gesehen[$marke])) { $m[] = 'MANGEL.ERZEUGUNG_DOPPELT'; }
        $eb_gesehen[$marke] = 1;
    }
    if ($cfg['q_netz']['art'] === 'aus') { $m[] = 'MANGEL.KEIN_ZAEHLER'; }
    elseif ($cfg['q_netz']['adresse'] === '') { $m[] = 'MANGEL.ZAEHLER_OHNE_ADRESSE'; }
    $st = array();
    foreach ($cfg['steller'] as $s) {
        if (trim((string) $s['name']) === '' || $s['art'] === 'aus') { continue; }
        $st[] = $s;
        if ($s['adresse'] === '') { $m[] = 'MANGEL.STELLER_OHNE_ADRESSE'; }
        elseif ($s['art'] === 'sunspec') {
            /* Der SunSpec-Weg braucht keinen Platzhalter - er rechnet die
             * Prozentzahl selbst -, dafuer aber eine Adresse nach seinem
             * eigenen Muster und eine Nennleistung, auf die sich Prozente
             * ueberhaupt beziehen koennen. */
            $sa = eb_sunspec_zerlegen($s['adresse']);
            if ($sa === null) { $m[] = 'MANGEL.SUNSPEC_FORM'; }
            if ((int) $s['spitze_w'] <= 0) { $m[] = 'MANGEL.SUNSPEC_OHNE_SPITZE'; }
            if ($sa !== null) {
                /* Ohne Zeitablauf faellt der Wechselrichter NICHT von allein
                 * auf Normalbetrieb zurueck. Stirbt der LoxBerry, bleibt die
                 * Anlage gedrosselt, bis jemand es merkt. Das darf man
                 * wollen - aber nicht versehentlich. */
                if ($sa['rueckfall_s'] === 0) { $m[] = 'MANGEL.SUNSPEC_OHNE_RUECKFALL'; }
                /* Aufgefrischt wird hoechstens im Takt. Ist der Zeitablauf
                 * kuerzer als drei Takte, faellt die Drosselung zwischen
                 * zwei Auffrischungen weg und die Anlage springt. */
                elseif ($sa['rueckfall_s'] < 3 * (int) $cfg['takt']) {
                    $m[] = 'MANGEL.SUNSPEC_RUECKFALL_ZU_KURZ';
                }
            }
            /* Frueher offen, am 19.08.2026 GEMESSEN (Symo 8.2-3-M,
             * Software 0.3.30.2): bis hinunter zu 0,09 % trat KEIN
             * erzwungener Standby ein - das Geraet drosselte sauber
             * (8,00 % -> 654 W, 2,00 % -> 162 W) und nahm die Betriebsart
             * nach dem Zeitablauf selbst zurueck. Die Handbuchwarnung gilt
             * also nicht fuer jede Software-Fassung.
             *
             * Der Hinweis bleibt trotzdem, aus zwei anderen Gruenden:
             * das Handbuch nennt die Gefahr ausdruecklich softwareabhaengig,
             * und unterhalb von rund einem halben Prozent der Nennleistung
             * folgt das Geraet der Vorgabe nicht mehr (Vorgabe 7 W,
             * gehalten 39 W). Eine Untergrenze darunter ist wirkungslos,
             * nicht scharf. */
            if ((int) $s['spitze_w'] > 0
                && (int) $cfg['drossel_min_w'] < (int) ceil(0.10 * (int) $s['spitze_w'])) {
                $m[] = 'MANGEL.SUNSPEC_UNTER_ZEHN';
            }
            /* Der Notbetrieb geht an der Untergrenze VORBEI: in eb_regeln()
             * steht dort min($drossel_alt, $notfall_w), und danach wahrt
             * eb_grenzen_wahren() nur die Anlagengrenze, nicht
             * drossel_min_w. Ein Notwert von 0 - die Vorgabe! - stellt den
             * Wechselrichter also auf null Prozent, und das ist unterhalb
             * der Standby-Linie.
             *
             * Am 19.08.2026 gemessen, warum das auf einem Hybrid teuer ist:
             * eine Drosselung um 77 W erzeugte 78 W Netzbezug, weil der
             * Speicher gerade das Haus versorgte. Was der Wechselrichter
             * nicht mehr abgeben darf, holt das Haus aus dem Netz - eins zu
             * eins. Ein Notwert von 0 schiebt bei einem Zaehlerausfall den
             * GANZEN Hausverbrauch ins Netz.
             *
             * Trotzdem nur ein Mangel und keine Sperre: wer lieber gar
             * nicht einspeist als unkontrolliert, darf das so einstellen -
             * er soll es nur nicht versehentlich tun. */
            if ((int) $s['spitze_w'] > 0
                && (int) $cfg['notfall_w'] < (int) ceil(0.10 * (int) $s['spitze_w'])) {
                $m[] = 'MANGEL.SUNSPEC_NOTWERT_ZU_TIEF';
            }
        }
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
    /* Ohne gemessene Ladeleistung laesst sich nicht feststellen, ob der
     * Speicher den Ueberschuss wirklich aufnimmt. Bis 0.9.4 galt er
     * ungeprueft als aufnahmefaehig, und die Anlage speiste dauerhaft
     * weiter, waehrend der Anlass "der Ueberschuss geht in den Speicher"
     * meldete. Gemessen am 18.08.2026. */
    if (!empty($cfg['speicher_zuerst']) && $cfg['q_lade']['art'] === 'aus') {
        $m[] = 'MANGEL.SPEICHER_OHNE_MESSUNG';
    }
    $spst = $cfg['sp_steller'];
    if ($spst['art'] !== 'aus') {
        /* SunSpec stellt Modell 123 - die Drosselung des Wechselrichters.
         * Der Speicher haengt an Modell 124, und das ist hier nicht gebaut.
         * Wer den Weg hier waehlt, wuerde beim Laden abregeln. */
        if ($spst['art'] === 'sunspec') { $m[] = 'MANGEL.SPEICHER_SUNSPEC'; }
        if (trim((string) $spst['adresse']) === '') { $m[] = 'MANGEL.SPEICHER_OHNE_ADRESSE'; }
        elseif (strpos($spst['adresse'] . $spst['inhalt'], '{') === false) {
            $m[] = 'MANGEL.SPEICHER_OHNE_PLATZHALTER';
        }
        if ($spst['einheit'] === 'Prozent' && (int) $spst['spitze_w'] <= 0) {
            $m[] = 'MANGEL.SPEICHER_PROZENT_OHNE_SPITZE';
        }
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

function eb_log($text)
{
    $p = eb_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei
     * frisst Arbeitsspeicher, nicht Plattenplatz. Bei einem Takt von fuenf
     * Sekunden ist das keine ferne Moeglichkeit. */
    clearstatcache(true, $p['log']);
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
        'messalter'      => 'EB_MQTT.MESSALTER',
        'tat'            => 'EB_MQTT.TAT',
        'gestellt'       => 'EB_MQTT.GESTELLT',
        'speicher'       => 'EB_MQTT.SPEICHER',
        'speichersoll'   => 'EB_MQTT.SPEICHERSOLL',
        'speicherok'     => 'EB_MQTT.SPEICHEROK',
        'online'         => 'EB_MQTT.ONLINE',
        'ersatz'         => 'EB_MQTT.ERSATZ',
        'stufe'          => 'EB_MQTT.STUFE',
        'ziel'           => 'EB_MQTT.ZIEL',
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
        'EIN'         => array('',  0,       1,       'EB_FELD.EIN', 'EB_TITEL.EIN'),
        'NETZ'        => array('W', -200000, 200000,  'EB_FELD.NETZ', 'EB_TITEL.NETZ'),
        'ERZEUGUNG'   => array('W', 0,       200000,  'EB_FELD.ERZEUGUNG', 'EB_TITEL.ERZEUGUNG'),
        'UEBERSCHUSS' => array('W', -200000, 200000,  'EB_FELD.UEBERSCHUSS', 'EB_TITEL.UEBERSCHUSS'),
        'GRENZE'      => array('W', 0,       200000,  'EB_FELD.GRENZE', 'EB_TITEL.GRENZE'),
        'LADESOLL'    => array('W', 0,       200000,  'EB_FELD.LADESOLL', 'EB_TITEL.LADESOLL'),
        'NOTFALL'     => array('',  0,       1,       'EB_FELD.NOTFALL', 'EB_TITEL.NOTFALL'),
        'WIRKUNG'     => array('',  -1,      1,       'EB_FELD.WIRKUNG', 'EB_TITEL.WIRKUNG'),
        'ALTER'       => array('s', -1,      86400,   'EB_FELD.ALTER', 'EB_TITEL.ALTER'),
        /* MESSALTER ist das Alter des ZAEHLERWERTS, ALTER das des
         * Durchlaufs. Bis 0.9.4 gab es nur das zweite - damit liess sich
         * im Miniserver nicht unterscheiden, ob der Dienst steht oder ob
         * ihm der Zaehler weggebrochen ist. -1 heisst: kein Wert. */
        'MESSALTER'   => array('s', -1,      86400,   'EB_FELD.MESSALTER', 'EB_TITEL.MESSALTER'),
        /* TAT als Zahl: 0 nichts, 1 Speicher, 2 abregeln, 3 freigeben.
         * Den Anlass gab es bisher nur als Text ueber MQTT. */
        'TAT'         => array('',  0,       3,       'EB_FELD.TAT', 'EB_TITEL.TAT'),
        /* GESTELLT ist die Summe dessen, was WIRKLICH an die Geraete ging.
         * Sie kann kleiner sein als GRENZE, wenn eine Spitzenleistung
         * deckelt - GRENZE allein sagt das nicht. */
        'GESTELLT'    => array('W', 0,       200000,  'EB_FELD.GESTELLT', 'EB_TITEL.GESTELLT'),
        /* 1 der Speicher folgt dem Ladesoll, 0 er folgt nachweislich
         * nicht, -1 in diesem Durchlauf nicht geprueft. */
        'SPEICHER'    => array('',  -1,      1,       'EB_FELD.SPEICHER', 'EB_TITEL.SPEICHER'),
        /* 1, wenn gerade der Ersatzzaehler benutzt wird. Ein Ersatzweg, den
         * niemand sieht, wird unbemerkt zum Normalfall. */
        'ERSATZ'      => array('',  0,       1,       'EB_FELD.ERSATZ', 'EB_TITEL.ERSATZ'),
        /* Die gewaehlte Zielstufe, 0 bis 2. */
        'STUFE'       => array('',  0,       2,       'EB_FELD.STUFE', 'EB_TITEL.STUFE'),
        'ZIEL'        => array('W', 0,       200000,  'EB_FELD.ZIEL', 'EB_TITEL.ZIEL'),
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
        /* Der Titel ist das, was in Loxone Config im Baum steht - er gehoert
         * lesbar. Die technische Kennung (EB_NETZ) steht im Kommentar, wo sie
         * hingehoert: sie taucht in der Antwortzeile auf, nicht im Baustein. */
        $cmds[] = array(
            'title'   => eb_klartext($info[4]),
            'comment' => 'EB_' . $feld . ' - ' . eb_klartext($info[3])
                       . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[1],
            'max'     => $info[2],
        );
    }
    foreach (eb_steller() as $nr => $s) {
        /* Lesbarer Titel aus dem Namen, den der Mensch vergeben hat. Die
         * Platznummer steht im Kommentar und im Pruefausdruck; dass zwei
         * Geraete nicht denselben Namen tragen, misst der Reiter Test nach. */
        $kurz = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s['name']));
        if ($kurz === '') { $kurz = 'STELLER'; }
        $kurz = substr($kurz, 0, 12) . '_S' . $nr;
        $cmds[] = array(
            'title'   => sprintf(eb_klartext('EB_TITEL.S_WATT'), $s['name']),
            'comment' => 'EB_' . $kurz . '_WATT - ' . eb_klartext('EB_FELD.S_WATT') . ' [W]',
            'check'   => '\iS' . $nr . 'W=\i\v',
            'min'     => 0, 'max' => 200000,
        );
        $cmds[] = array(
            'title'   => sprintf(eb_klartext('EB_TITEL.S_OK'), $s['name']),
            'comment' => 'EB_' . $kurz . '_OK - ' . eb_klartext('EB_FELD.S_OK'),
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
    $o = sprintf("EINSPEISEBREMSE;EIN=%d;NETZ=%s;ERZEUGUNG=%s;UEBERSCHUSS=%s;GRENZE=%s;LADESOLL=%s;NOTFALL=%d;WIRKUNG=%s;ALTER=%d;MESSALTER=%s;TAT=%d;GESTELLT=%s;SPEICHER=%d\n",
        empty($stand['ein']) ? 0 : 1,
        $w($h('netz')), $w($h('erzeugung')), $w($h('ueberschuss_w')),
        $w($h('drossel_w')), $w($h('lade_soll_w')),
        empty($stand['notfall']) ? 0 : 1,
        $h('wirkung') === null ? '-' : (string) (int) $h('wirkung'),
        eb_alter(),
        $h('netz_alter') === null ? '-1' : (string) (int) round($h('netz_alter')),
        (int) $h('tat'),
        $w($h('gestellt_w')),
        $h('speicher_folgt') === null ? -1 : (int) $h('speicher_folgt'));
    $o .= sprintf("ERSATZ=%d;STUFE=%d;ZIEL=%d\n",
        empty($stand['ersatz']) ? 0 : 1,
        (int) $h('stufe'), (int) $h('ziel_w'));
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function eb_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(eb_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = eb_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(eb_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = eb_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function eb_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = eb_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function eb_formtoken()
{
    $grund = eb_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function eb_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(eb_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function eb_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = eb_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return eb_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return eb_t('WACHE.FALSCH');
    }
    return '';
}
