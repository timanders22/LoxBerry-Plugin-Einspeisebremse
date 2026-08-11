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
    foreach (array('q_netz', 'q_erzeugung', 'q_soc', 'q_lade') as $k) {
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

function eb_http_holen($url, $zeit = 5)
{
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $zeit, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\n")));
    $t = @file_get_contents($url, false, $ctx);
    return $t === false ? null : $t;
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
    } else {
        $text = eb_http_holen($q['adresse']);
        if ($text === null) { return array(null, -1.0, 'unerreichbar'); }
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

function eb_mqtt_veroeffentlichen($thema, $wert)
{
    $b = eb_broker();
    $argv = array('mosquitto_pub', '-h', $b['host'], '-p', (string) $b['port'],
                  '-t', $thema, '-m', eb_mqtt_wert_saeubern_d($wert));
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
        $t = eb_http_holen($adresse, 8);
        return array($t === null ? 0 : 1, 'GET ' . $adresse);
    }
    if ($s['art'] === 'http_post') {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => $inhalt)));
        $t = @file_get_contents($adresse, false, $ctx);
        return array($t === false ? 0 : 1, 'POST ' . $adresse . ' ' . substr($inhalt, 0, 80));
    }
    return array(0, 'Art ' . $s['art'] . ' ist nicht vorgesehen');
}

/* ==================================================================
 * Ein Durchlauf
 * ================================================================== */

function eb_durchlauf($cfg, $stand)
{
    $jetzt = microtime(true);

    list($netz, $netz_alter, $netz_anlass) = eb_quelle_lesen($cfg['q_netz']);
    list($erz, $erz_alter, $erz_anlass) = eb_quelle_lesen($cfg['q_erzeugung']);
    list($soc, $soc_alter, $soc_anlass) = eb_quelle_lesen($cfg['q_soc']);
    list($lade, $lade_alter, $lade_anlass) = eb_quelle_lesen($cfg['q_lade']);

    $mess = array(
        'netz'      => $netz,
        'erzeugung' => $erz === null ? 0 : $erz,
        'soc'       => $soc === null ? -1 : $soc,
        'lade_ist'  => $lade === null ? 0 : $lade,
        /* Kein Wert heisst: unendlich alt. Nicht null - null hiesse
         * "gerade eben gemessen", und darauf wuerde die Regelung die
         * Erzeugung freigeben. */
        'alter_s'   => ($netz === null) ? -1 : $netz_alter,
    );

    $zust = array(
        'drossel_w'   => isset($stand['drossel_w']) ? $stand['drossel_w'] : $mess['erzeugung'],
        'lade_soll_w' => isset($stand['lade_soll_w']) ? $stand['lade_soll_w'] : 0,
    );

    $r = eb_regeln($mess, $cfg, $zust, $jetzt);

    $neu = array(
        'zeit'          => time(),
        'ein'           => $cfg['ein'],
        'netz'          => $netz,
        'netz_anlass'   => $netz_anlass,
        'erzeugung'     => $erz,
        'soc'           => $soc,
        'lade_ist'      => $lade,
        'ueberschuss_w' => $r['ueberschuss_w'],
        'drossel_w'     => $r['drossel_w'],
        'lade_soll_w'   => $r['lade_soll_w'],
        'tat'           => $r['tat'],
        'anlass'        => $r['anlass'],
        'notfall'       => $r['notfall'],
        'wirkung'       => isset($stand['wirkung']) ? $stand['wirkung'] : 0,
        'steller'       => isset($stand['steller']) ? $stand['steller'] : array(),
        'gestellt_um'   => isset($stand['gestellt_um']) ? $stand['gestellt_um'] : 0,
        'netz_vorher'   => isset($stand['netz_vorher']) ? $stand['netz_vorher'] : null,
        'grenze_vorher' => isset($stand['grenze_vorher']) ? $stand['grenze_vorher'] : null,
    );

    $steller = eb_steller();

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
        if (!$neu['freigegeben'] && $steller) {
            $frei = (float) $cfg['frei_w'];
            $anteile = eb_aufteilen($frei, $steller);
            $neu['steller'] = array();
            $alle_ok = 1;
            foreach ($steller as $nr => $s) {
                $watt = isset($anteile[$nr]) ? $anteile[$nr] : 0.0;
                list($ok, $was) = eb_stellen($s, $watt);
                $neu['steller'][(string) $nr] = array('name' => $s['name'], 'watt' => $watt,
                                                     'ok' => $ok, 'was' => $was);
                if (!$ok) { $alle_ok = 0; eb_log('Freigabe an ' . $s['name'] . ': ' . $was); }
            }
            $neu['drossel_w'] = $frei;
            /* Nur als erledigt vermerken, wenn wirklich alle erreicht
             * wurden - sonst versucht es der naechste Durchlauf erneut. */
            $neu['freigegeben'] = $alle_ok;
            eb_log($alle_ok
                ? sprintf('Regelung ausgeschaltet: Anlage auf %d W freigegeben.', (int) $frei)
                : 'Regelung ausgeschaltet: die Freigabe hat nicht alle Stellglieder erreicht, '
                  . 'sie wird im naechsten Durchlauf wiederholt.');
        }
        return $neu;
    }
    $neu['freigegeben'] = 0;

    if (!$steller) {
        $neu['anlass'] = 'kein_steller';
        return $neu;
    }

    $aendert = ($r['tat'] === EB_DROSSEL || $r['tat'] === EB_FREIGABE
                || (int) round($r['drossel_w']) !== (int) round($zust['drossel_w']));

    if ($aendert) {
        $anteile = eb_aufteilen($r['drossel_w'], $steller);
        $neu['steller'] = array();
        foreach ($steller as $nr => $s) {
            $watt = isset($anteile[$nr]) ? $anteile[$nr] : 0.0;
            list($ok, $was) = eb_stellen($s, $watt);
            $neu['steller'][(string) $nr] = array('name' => $s['name'], 'watt' => $watt,
                                                  'ok' => $ok, 'was' => $was);
            if (!$ok) { eb_log('Stellglied ' . $s['name'] . ': ' . $was); }
        }
        $neu['gestellt_um'] = $jetzt;
        $neu['netz_vorher'] = ($netz === null) ? null : -$netz;   // Einspeisung vorher
        $neu['grenze_vorher'] = $zust['drossel_w'];
        $neu['wirkung'] = 0;
        eb_log(sprintf('%s: Netz %s W, Ueberschuss %d W -> Grenze %d W, Ladesoll %d W',
            $r['anlass'], $netz === null ? '-' : (int) $netz,
            (int) $r['ueberschuss_w'], (int) $r['drossel_w'], (int) $r['lade_soll_w']));
    }

    /* ---- Hat es gewirkt? ----
     * Nicht die Quittung zaehlt, sondern der Zaehler. Ein Wechselrichter,
     * der 200 OK sagt und weiterspeist, ist der unangenehmste Fall - er
     * meldet Erfolg, und die Auflage ist trotzdem verletzt. */
    if (!empty($neu['gestellt_um']) && $netz !== null && $neu['netz_vorher'] !== null) {
        $w = eb_wirkung($neu['netz_vorher'], -$netz, $r['drossel_w'],
                        $cfg['wirkung_s'], $jetzt - $neu['gestellt_um']);
        if ($w !== 0) {
            $neu['wirkung'] = $w;
            if ($w === -1) {
                eb_log('KEINE WIRKUNG: die Grenze wurde gestellt, die Einspeisung ist nicht '
                     . 'gefallen. Nimmt das Geraet den Wert wirklich an?');
            }
            $neu['gestellt_um'] = 0;
        }
    }

    return $neu;
}

function eb_veroeffentlichen($cfg, $stand)
{
    if (empty($cfg['mqtt_ein'])) { return; }
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    $paare = array(
        'ein' => (int) $stand['ein'],
        'netz' => $stand['netz'] === null ? '' : (int) $stand['netz'],
        'erzeugung' => $stand['erzeugung'] === null ? '' : (int) $stand['erzeugung'],
        'ueberschuss' => (int) $stand['ueberschuss_w'],
        'grenze' => (int) $stand['drossel_w'],
        'ladesoll' => (int) $stand['lade_soll_w'],
        'anlass' => $stand['anlass'],
        'notfall' => (int) $stand['notfall'],
        'wirkung' => (int) $stand['wirkung'],
        'alter' => max(0, time() - (int) $stand['zeit']),
    );
    foreach ((array) $stand['steller'] as $nr => $e) {
        $paare['steller' . $nr . '/name'] = $e['name'];
        $paare['steller' . $nr . '/watt'] = (int) $e['watt'];
        $paare['steller' . $nr . '/ok'] = (int) $e['ok'];
    }
    foreach ($paare as $k => $v) {
        if ($v === '') { continue; }        // kein Wert ist keine Null
        eb_mqtt_veroeffentlichen($praefix . '/' . $k, $v);
    }
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
    foreach (array('q_netz' => 'Netz', 'q_erzeugung' => 'Erzeugung',
                   'q_soc' => 'Ladestand', 'q_lade' => 'Ladeleistung') as $k => $name) {
        list($w, $a, $anlass) = eb_quelle_lesen($cfg[$k]);
        printf("%-13s %-10s %s\n", $name,
            $w === null ? '-' : round($w, 1),
            $anlass . ($w === null ? '' : sprintf(' (%.1f s alt)', $a)));
    }
    eb_hoerer_beenden();
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
eb_log('Dienst beendet. Die zuletzt gestellte Grenze bleibt bestehen.');
exit(0);
