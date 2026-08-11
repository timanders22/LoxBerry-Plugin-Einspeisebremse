<?php
/**
 * Einspeisebremse - die Aktionen des Reiters Test
 *
 * Jeder Test gibt Klartext zurueck. Gelesen wird das von einem Menschen,
 * nicht von einem Programm.
 *
 * KEIN Test dieses Reiters stellt etwas. Ein Knopf "einmal drosseln" waere
 * bequem und ein Fehler: er griffe an der Regelung vorbei in eine laufende
 * Anlage, und niemand saehe hinterher, warum die Grenze steht, wo sie
 * steht. Wer stellen will, schaltet die Regelung ein.
 */

function eb_test_ausfuehren($welcher)
{
    switch ($welcher) {
        case 'probe':      return eb_test_probe();
        case 'trocken':    return eb_test_trocken();
        case 'selbsttest': return eb_test_selbsttest();
        case 'maengel':    return eb_test_maengel();
        case 'zeile':      return eb_test_zeile();
        case 'mqtt':       return eb_test_mqtt();
        case 'endpunkt':   return eb_test_endpunkt();
    }
    return eb_t('TEST.M_UNBEKANNT');
}

function eb_dienst_datei()
{
    $p = eb_paths();
    foreach (array($p['bindir'] . '/eb_dienst.php',
                   dirname(dirname(__DIR__)) . '/bin/eb_dienst.php') as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

function eb_php()
{
    /* Nur der Name, nicht der Pfad: welches php gilt, entscheidet die
     * Umgebung. Ein fest verdrahteter Pfad ginge auf Debian 13 schief. */
    return 'php';
}

/** Die Messwerte einmal lesen - genau so, wie es der Dienst tut. */
function eb_test_probe()
{
    $d = eb_dienst_datei();
    if ($d === '') { return eb_t('TEST.M_KEIN_DIENST'); }
    $aus = array();
    @exec(escapeshellcmd(eb_php()) . ' ' . escapeshellarg($d) . ' --probe 2>&1', $aus);
    $text = trim(implode("\n", $aus));
    if ($text === '') { return eb_klartext('TEST.M_PROBE_LEER'); }
    return eb_klartext('TEST.M_PROBE_KOPF') . "\n\n" . $text . "\n\n"
         . eb_klartext('TEST.M_PROBE_FUSS');
}

/**
 * Trockenlauf: was WUERDE die Regelung jetzt tun?
 *
 * Rechnet mit den zuletzt gemessenen Werten und stellt nichts. Das ist der
 * einzige ehrliche Weg, eine Einstellung zu pruefen, ohne die Anlage als
 * Versuchsobjekt zu benutzen.
 */
function eb_test_trocken()
{
    $cfg = eb_config();
    $stand = eb_stand();
    if (!$stand) { return eb_klartext('TEST.M_NOCH_NICHTS'); }

    $o = array(eb_klartext('TEST.T_KOPF'), '');
    $zeig = function ($k, $v, $eh = 'W') {
        return sprintf('%-24s %s', eb_klartext($k),
            $v === null ? '—' : (is_numeric($v) ? round($v, 1) . ' ' . $eh : $v));
    };
    $o[] = $zeig('TEST.T_NETZ', isset($stand['netz']) ? $stand['netz'] : null);
    $o[] = $zeig('TEST.T_ERZEUGUNG', isset($stand['erzeugung']) ? $stand['erzeugung'] : null);
    $o[] = $zeig('TEST.T_SOC', isset($stand['soc']) ? $stand['soc'] : null, '%');
    $o[] = $zeig('TEST.T_LADE', isset($stand['lade_ist']) ? $stand['lade_ist'] : null);
    $o[] = sprintf('%-24s %d s', eb_klartext('TEST.T_ALTER'), eb_alter());
    $o[] = '';

    $mess = array(
        'netz'      => isset($stand['netz']) ? $stand['netz'] : null,
        'erzeugung' => isset($stand['erzeugung']) ? $stand['erzeugung'] : 0,
        'soc'       => isset($stand['soc']) && $stand['soc'] !== null ? $stand['soc'] : -1,
        'lade_ist'  => isset($stand['lade_ist']) ? $stand['lade_ist'] : 0,
        'alter_s'   => (isset($stand['netz']) && $stand['netz'] !== null) ? eb_alter() : -1,
    );
    $zust = array('drossel_w' => isset($stand['drossel_w']) ? $stand['drossel_w'] : 0,
                  'lade_soll_w' => isset($stand['lade_soll_w']) ? $stand['lade_soll_w'] : 0);
    $r = eb_regeln($mess, $cfg, $zust, time());

    $taten = array(EB_NICHTS => 'TAT.NICHTS', EB_SPEICHER => 'TAT.SPEICHER',
                   EB_DROSSEL => 'TAT.DROSSEL', EB_FREIGABE => 'TAT.FREIGABE');
    $o[] = sprintf('%-24s %s', eb_klartext('TEST.T_TAT'),
        eb_klartext(isset($taten[$r['tat']]) ? $taten[$r['tat']] : 'TAT.NICHTS'));
    $o[] = sprintf('%-24s %s', eb_klartext('TEST.T_ANLASS'),
        eb_klartext('ANLASS.' . strtoupper($r['anlass'])));
    $o[] = sprintf('%-24s %d W', eb_klartext('TEST.T_UEBER'), $r['ueberschuss_w']);
    $o[] = sprintf('%-24s %d W', eb_klartext('TEST.T_GRENZE'), $r['drossel_w']);
    $o[] = sprintf('%-24s %d W', eb_klartext('TEST.T_LADESOLL'), $r['lade_soll_w']);
    if ($r['notfall']) { $o[] = ''; $o[] = eb_klartext('TEST.T_NOTFALL'); }

    $steller = eb_steller();
    if ($steller) {
        $o[] = '';
        $o[] = eb_klartext('TEST.T_VERTEILUNG');
        foreach (eb_aufteilen($r['drossel_w'], $steller) as $nr => $w) {
            list($adr, $inh, $ers) = eb_befehl_bauen($steller[$nr], $w);
            $o[] = sprintf('  %d %-18s %7d W   %s', $nr, $steller[$nr]['name'], $w,
                $steller[$nr]['art']);
            $o[] = '      ' . $adr . ($inh !== '' ? '   ' . $inh : '');
        }
        $summe = array_sum(eb_aufteilen($r['drossel_w'], $steller));
        $o[] = sprintf('  %-20s %7d W', eb_klartext('TEST.T_SUMME'), $summe);
        if (abs($summe - $r['drossel_w']) > 1) {
            $o[] = '  ' . sprintf(eb_klartext('TEST.T_SUMME_ABWEICHT'), $r['drossel_w'] - $summe);
        }
    } else {
        $o[] = '';
        $o[] = eb_klartext('TEST.M_KEIN_STELLER');
    }
    $o[] = '';
    $o[] = eb_klartext('TEST.T_FUSS');
    return implode("\n", $o);
}

function eb_test_selbsttest()
{
    ob_start();
    list($n, $f) = eb_selbsttest(true);
    $text = ob_get_clean();
    return $text . "\n" . ($f === 0 ? eb_klartext('TEST.M_SELBSTTEST_OK')
                                    : eb_klartext('TEST.M_SELBSTTEST_FEHL'));
}

function eb_test_maengel()
{
    $m = eb_maengel(eb_config());
    if (!$m) { return eb_klartext('TEST.M_KEINE_MAENGEL'); }
    $o = array(eb_klartext('TEST.M_MAENGEL_KOPF'), '');
    foreach ($m as $k) { $o[] = '  - ' . eb_klartext($k); }
    return implode("\n", $o);
}

function eb_test_zeile()
{
    return eb_klartext('TEST.M_ZEILE_KOPF') . "\n\n" . rtrim(eb_zeile(eb_stand()));
}

function eb_test_mqtt()
{
    $cfg = eb_config();
    $z = eb_mqtt_zustand();
    $ja = eb_klartext('ALLG.JA');
    $nein = eb_klartext('ALLG.NEIN');
    $o = array();
    $o[] = sprintf(eb_klartext('TEST.MQ_GEFUNDEN'), $z['gefunden'] ? $ja : $nein);
    $o[] = sprintf(eb_klartext('TEST.MQ_AUTOSTART'), $z['autostart'] ? $ja : $nein);
    $o[] = sprintf(eb_klartext('TEST.MQ_PORT'), $z['udpport'] ? (string) $z['udpport'] : '—');
    $o[] = sprintf(eb_klartext('TEST.MQ_EIN'), $cfg['mqtt_ein'] ? $ja : $nein);
    /* Ohne mosquitto_sub kann der Dienst keinen MQTT-Zaehler lesen. Das ist
     * der haeufigste Grund, warum eine sonst richtige Einstellung nichts
     * tut - also wird es hier ausdruecklich gesagt. */
    $o[] = '';
    @exec('command -v mosquitto_sub 2>/dev/null', $a1, $r1);
    @exec('command -v mosquitto_pub 2>/dev/null', $a2, $r2);
    $o[] = sprintf(eb_klartext('TEST.MQ_SUB'), $r1 === 0 ? $ja : $nein);
    $o[] = sprintf(eb_klartext('TEST.MQ_PUB'), $r2 === 0 ? $ja : $nein);
    if ($r1 !== 0 || $r2 !== 0) { $o[] = eb_klartext('TEST.MQ_FEHLT'); }
    $o[] = '';
    $o[] = eb_klartext('TEST.MQ_THEMEN');
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    foreach (eb_mqtt_themen() as $k => $unbenutzt) { $o[] = '  ' . $praefix . '/' . $k; }
    $o[] = '';
    $o[] = sprintf(eb_klartext('TEST.MQ_SAEUBERUNG'),
        eb_mqtt_wert_saeubern("Zeile eins\nZeile zwei\tmit Tabulator"));
    return implode("\n", $o);
}

function eb_test_endpunkt()
{
    $url = eb_endpunkt() . '?token=' . eb_token() . '&aktion=status';
    $o = array(sprintf(eb_klartext('TEST.EP_AUFRUF'), $url), '');
    $ctx = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
    $text = @file_get_contents($url, false, $ctx);
    if ($text === false) { $o[] = eb_klartext('TEST.EP_FEHL'); return implode("\n", $o); }
    $o[] = $text;
    /* Ein falsches Wortzeichen MUSS abgewiesen werden. Wird das hier nicht
     * bestaetigt, steht der Endpunkt offen - und das ist wichtiger als jedes
     * andere Ergebnis auf dieser Seite. */
    $o[] = '';
    $o[] = eb_klartext('TEST.EP_GEGENPROBE');
    $falsch = @file_get_contents(eb_endpunkt() . '?token=falsch&aktion=status', false, $ctx);
    $o[] = ($falsch !== false && strpos((string) $falsch, 'GRUND=TOKEN') !== false)
        ? eb_klartext('TEST.EP_ABGEWIESEN')
        : sprintf(eb_klartext('TEST.EP_OFFEN'), substr((string) $falsch, 0, 200));
    return implode("\n", $o);
}
