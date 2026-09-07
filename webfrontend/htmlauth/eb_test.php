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
        case 'wenn':       return eb_test_wenn();
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

    /* GENAU dieselben Eingaben wie eb_durchlauf(). Ein Trockenlauf, der
     * anders rechnet als der Betrieb, zeigt etwas, das nie passiert -
     * und das waere schlimmer als kein Trockenlauf. */
    $mess = array(
        'netz'      => isset($stand['netz']) ? $stand['netz'] : null,
        // null heisst "nicht gemessen"; der Kern setzt dann die Grenze ein.
        'erzeugung' => isset($stand['erzeugung']) ? $stand['erzeugung'] : null,
        'soc'       => isset($stand['soc']) && $stand['soc'] !== null ? $stand['soc'] : -1,
        'lade_ist'  => isset($stand['lade_ist']) && $stand['lade_ist'] !== null ? $stand['lade_ist'] : 0,
        'alter_s'   => isset($stand['netz_alter']) && $stand['netz_alter'] !== null
                       ? $stand['netz_alter'] : -1,
    );
    $cfg['anlage_max_w'] = eb_anlage_max();
    $zust = array(
        'drossel_w'     => isset($stand['drossel_w']) ? $stand['drossel_w'] : 0,
        'lade_soll_w'   => isset($stand['lade_soll_w']) ? $stand['lade_soll_w'] : 0,
        'sp_probe_seit' => isset($stand['sp_probe_seit']) ? $stand['sp_probe_seit'] : 0,
        'sp_probe_lade' => isset($stand['sp_probe_lade']) ? $stand['sp_probe_lade'] : 0,
        'sp_sperre_bis' => isset($stand['sp_sperre_bis']) ? $stand['sp_sperre_bis'] : 0,
    );
    $r = eb_regeln($mess, $cfg, $zust, microtime(true));

    $taten = array(EB_NICHTS => 'TAT.NICHTS', EB_SPEICHER => 'TAT.SPEICHER',
                   EB_DROSSEL => 'TAT.DROSSEL', EB_FREIGABE => 'TAT.FREIGABE');
    $o[] = sprintf('%-24s %s', eb_klartext('TEST.T_TAT'),
        eb_klartext(isset($taten[$r['tat']]) ? $taten[$r['tat']] : 'TAT.NICHTS'));
    $o[] = sprintf('%-24s %s', eb_klartext('TEST.T_ANLASS'),
        eb_klartext('ANLASS.' . strtoupper($r['anlass'])));
    /* Ein unbekannter Ueberschuss ist nicht 0. eb_regeln() laesst den
     * Wert ausdruecklich auf null, wenn kein Zaehlerwert vorliegt; %d
     * machte daraus eine 0, und eine 0 liest sich wie "ausgeglichen" -
     * genau die Verwechslung, gegen die der Kern gebaut ist. Die
     * Schwesterstelle im "Was waere, wenn" macht es richtig. */
    $o[] = sprintf('%-24s %s', eb_klartext('TEST.T_UEBER'),
        $r['ueberschuss_w'] === null ? '-' : ((int) $r['ueberschuss_w']) . ' W');
    $o[] = sprintf('%-24s %d W', eb_klartext('TEST.T_GRENZE'), $r['drossel_w']);
    $o[] = sprintf('%-24s %d W', eb_klartext('TEST.T_LADESOLL'), $r['lade_soll_w']);
    if (!empty($r['erzeugung_ersatz'])) { $o[] = eb_klartext('TEST.T_ERSATZ'); }
    if ((int) $r['speicher_folgt'] === 0) { $o[] = eb_klartext('TEST.T_SPEICHER_NEIN'); }
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
    /* Der Speicher bekommt seinen eigenen Befehl - auch der geht im Betrieb
     * wirklich hinaus und gehoert deshalb hierher. */
    $sp = eb_speicher_steller();
    if ($sp) {
        list($adr, $inh, $ers) = eb_befehl_bauen($sp, $r['lade_soll_w']);
        $o[] = '';
        $o[] = eb_klartext('TEST.T_SPEICHER');
        $o[] = sprintf('  %-20s %7d W   %s', $sp['name'], $r['lade_soll_w'], $sp['art']);
        $o[] = '      ' . $adr . ($inh !== '' ? '   ' . $inh : '');
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

/* ==================================================================
 * Die stehende Selbstpruefung
 *
 * Je Zeile eine Frage, die sich OHNE Loxone beantworten laesst. Drei
 * Regeln aus REGELN_1, Abschnitt 12, gelten hier woertlich:
 *
 *   - Eine Pruefung, die einen leeren Befund erklaert, muss die Erklaerung
 *     belegen koennen. "Ohne Speicher ist das normal" darf nur dastehen,
 *     wenn geprueft ist, dass keiner da ist.
 *   - Die Ursache gehoert VOR die Wirkung: "laeuft der Dienst" steht vor
 *     "liegt ein Messwert vor", weil das eine das andere erklaert.
 *   - Ein Hinweis ist fuer "geht mich nichts an" da, nicht fuer "ich weiss
 *     es nicht". Unklarheit ist ein Kreuz.
 * ================================================================== */

/**
 * Ist der Reiter Test der serverseitig offene?
 *
 * Nur dann laufen die Zeilen, die ins Netz gehen oder etwas kosten. Der
 * Wert wird von index.php gesetzt, bevor die Selbstpruefung gerendert
 * wird; ohne Aufruf gilt "nein", damit ein fremder Einstieg (Prueflauf,
 * Kommandozeile) nicht ungewollt Anfragen ausloest.
 */
function eb_test_offen($setzen = null)
{
    static $offen = false;
    if ($setzen !== null) { $offen = (bool) $setzen; }
    return $offen;
}

/**
 * Tragen alle Formulare der Oberflaeche das Merkmal gegen fremde Absender?
 *
 * Gezaehlt wird ueber ALLE Dateien, aus denen die Oberflaeche besteht -
 * index.php UND diese Datei. Eine Zaehlung nur ueber index.php meldete
 * "12 von 12" bei siebzehn vorhandenen Formularen; die Zahl stimmte mit
 * sich selbst ueberein und war trotzdem falsch, weil die Grundmenge zu
 * klein war.
 *
 * Rueckgabe: array(Formulare, davon mit Merkmal)
 */
function eb_formulare_zaehlen()
{
    $ganz = 0;
    $mit = 0;
    foreach (array(__DIR__ . '/index.php', __FILE__) as $datei) {
        if (!is_file($datei)) { continue; }
        $t = (string) @file_get_contents($datei);
        $teile = explode('<form', $t);
        foreach ($teile as $i => $stueck) {
            if ($i === 0) { continue; }
            $ende = strpos($stueck, '</form>');
            $block = $ende === false ? $stueck : substr($stueck, 0, $ende);
            $ganz++;
            if (strpos($block, 'eb_fmt()') !== false
                || strpos($block, 'name="fmt"') !== false) { $mit++; }
        }
    }
    return array($ganz, $mit);
}

/**
 * Setzt der Server das sm-active selbst, oder braucht die Seite dafuer
 * JavaScript?
 *
 * Faellt das Skript aus und steht die Klasse nur per Skript, ist die
 * Seite vollstaendig leer - .sm-seite steht auf display:none. Genau das
 * ist einer Schwesterlinie sechs Tage lang nicht aufgefallen, weil im
 * Quelltext der Satz stand, sie sei weiterhin bedienbar.
 *
 * Erwartet wird je Reiter zweimal der serverseitige Ausdruck: einmal an
 * der Leiste, einmal am Bereich.
 * Rueckgabe: array(gefunden, erwartet)
 */
function eb_active_serverseitig()
{
    $datei = __DIR__ . '/index.php';
    $t = is_file($datei) ? (string) @file_get_contents($datei) : '';
    if ($t === '') { return array(0, 0); }
    list($nl, $nb, $nli, $gleich) = eb_reiter_zaehlen();
    return array(substr_count($t, "' sm-active' : ''"), $nl + $nb);
}

/**
 * Ist jeder Feldname der Antwortzeile eindeutig?
 *
 * Loxone sucht woertlich und nimmt die ERSTE Fundstelle. Endet ein
 * Feldname auf einen anderen, liest der virtuelle Eingang still den
 * falschen Wert. Gemessen wird an der ERZEUGTEN Antwortzeile, nicht am
 * Quelltext, und der Suchtext kommt aus eb_check() - derselben Funktion,
 * aus der ihn auch die Importvorlage nimmt.
 *
 * Rueckgabe: array(Zahl der Felder, Liste der Kollisionen)
 */
function eb_suchtexte_eindeutig()
{
    $zeile = eb_zeile(eb_stand());
    if (!preg_match_all('/;([A-Z0-9_]+)=/', $zeile, $tr)) {
        return array(0, array());
    }
    $felder = array_values(array_unique($tr[1]));
    $stoss = array();
    foreach ($felder as $a) {
        /* Der Suchtext, den die Vorlage wirklich traegt. Trifft er in
         * dieser Antwortzeile mehr als einmal, ist er nicht eindeutig -
         * das ist die Frage, und sie wird am Ergebnis gestellt, nicht an
         * einer Namensliste. */
        $muster = str_replace(array('\i', '\v'), '', eb_check($a));
        if (substr_count($zeile, $muster) !== 1) {
            $stoss[] = $a;
            continue;
        }
        foreach ($felder as $b) {
            if ($a === $b || strlen($b) <= strlen($a)) { continue; }
            /* Gegenprobe ohne Trennzeichen: WAERE der Suchtext ohne das
             * Semikolon gebaut, traefe er hier zuerst den laengeren
             * Namen. Die Zeile bleibt gruen - sie belegt, dass das
             * Trennzeichen den Unterschied macht. */
            if (substr($b, -strlen($a)) === $a
                && strpos($zeile, ';' . $a . '=') === false) {
                $stoss[] = $a . ' in ' . $b;
            }
        }
    }
    return array(count($felder), array_values(array_unique($stoss)));
}

/** Eine Zeile der Selbstpruefung. $ok: 1 Haekchen, 0 Kreuz, -1 Hinweis. */
function eb_pruefzeile($frage, $ok, $bemerkung = '')
{
    return array('frage' => $frage, 'ok' => (int) $ok, 'bemerkung' => (string) $bemerkung);
}

/** Findet dienst.sh - im Archiv anders als installiert. */
function eb_dienst_skript()
{
    $p = eb_paths();
    foreach (array($p['bindir'] . '/dienst.sh',
                   dirname(dirname(__DIR__)) . '/bin/dienst.sh') as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

/** Ruft den eigenen Endpunkt mit ?selftest=1 auf. Rueckgabe: array(code, text). */
/**
 * Die Adresse fuer den EIGENEN Abruf.
 *
 * eb_endpunkt() baut aus dem Host-Kopf der Anfrage - richtig fuer eine
 * Adresse, die ein Mensch abschreibt oder die in die Loxone-Vorlage
 * wandert, falsch fuer einen Abruf, den der Server selbst macht: der
 * Host-Kopf kommt von aussen, und das Aktionstoken steht in der Adresse.
 * Fuer den eigenen Abruf ist die Rueckschleife richtig, denn der Endpunkt
 * liegt auf demselben Geraet.
 */
function eb_endpunkt_selbst()
{
    $p = eb_paths();
    return 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php';
}

function eb_endpunkt_selftest($token)
{
    $url = eb_endpunkt_selbst() . '?selftest=1&token=' . rawurlencode($token);
    /* Drei Sekunden, nicht acht: diese Zeile steht in einer Seite, die bei
     * jedem Aufruf des Reiters neu aufgebaut wird. Auf dem Geraet antwortet
     * der eigene Webserver in Millisekunden; die Zeitueberschreitung greift
     * nur, wenn etwas nicht stimmt - und dann soll man nicht minutenlang
     * vor einer leeren Seite sitzen. */
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
    $t = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
        }
    }
    return array($code, $t === false ? '' : trim((string) $t));
}

/**
 * Zaehlt die drei Stellen der Reiterleiste gegeneinander.
 *
 * Die Leiste steht ausgeschrieben, damit hausstandard_pruefen.py sie
 * ueberhaupt findet - eine erzeugte Leiste macht diese Pruefung blind, und
 * genau das stand in REGELN_1 schon dreimal. Ausschreiben allein genuegt
 * aber nicht: die Uebereinstimmung wird hier nachgemessen.
 *
 * Rueckgabe: array(zahl_leiste, zahl_bereiche, zahl_liste, gleich)
 */
function eb_reiter_zaehlen()
{
    $datei = __DIR__ . '/index.php';
    $t = is_file($datei) ? (string) @file_get_contents($datei) : '';
    if ($t === '') { return array(0, 0, 0, 0); }
    $a = 'data-' . 'ziel="';
    $leiste = array();
    foreach (explode($a, $t) as $i => $stueck) {
        if ($i === 0) { continue; }
        $ende = strpos($stueck, '"');
        if ($ende !== false) { $leiste[substr($stueck, 0, $ende)] = 1; }
    }
    $bereiche = array();
    if (preg_match_all('#id="(tab-[a-z0-9]+)"#', $t, $m)) {
        foreach ($m[1] as $id) { $bereiche[$id] = 1; }
    }
    /* Die Positivliste steht als ausgeschriebenes Feld in derselben Datei -
     * dort sucht auch das Hauswerkzeug. Gelesen wird sie hier aus der Datei,
     * damit es keine zweite Stelle gibt, die man mitpflegen muesste. */
    $liste = array();
    $anf = strpos($t, '$eb_reiter_liste');
    if ($anf !== false) {
        $stueck = substr($t, $anf, 400);
        if (preg_match_all("#'(tab-[a-z0-9]+)'#", $stueck, $ml)) {
            foreach ($ml[1] as $id) { $liste[$id] = 1; }
        }
    }
    $gleich = (count($leiste) > 0
               && array_keys($leiste) == array_keys($bereiche)
               && count(array_diff(array_keys($leiste), array_keys($liste))) === 0) ? 1 : 0;
    return array(count($leiste), count($bereiche), count($liste), $gleich);
}

/** Alle Zeilen der Selbstpruefung. */
function eb_selbstpruefung()
{
    $cfg = eb_config();
    $stand = eb_stand();
    $z = array();
    $ja = eb_klartext('ALLG.JA');
    $nein = eb_klartext('ALLG.NEIN');

    /* ---- Zuerst die Ursachen ---- */
    $pid = eb_dienst_pid();
    $z[] = eb_pruefzeile(eb_klartext('SP.DIENST'), $pid ? 1 : 0,
        $pid ? sprintf(eb_klartext('SP.DIENST_PID'), $pid) : eb_klartext('SP.DIENST_STEHT'));

    $alter = eb_alter();
    $frisch = ($alter >= 0 && $alter <= (int) $cfg['notfall_s']);
    $z[] = eb_pruefzeile(eb_klartext('SP.DURCHLAUF'), $frisch ? 1 : 0,
        $alter < 0 ? eb_klartext('SP.NIE_GELAUFEN')
                   : sprintf(eb_klartext('SP.SEKUNDEN_HER'), (int) $alter));

    $mess_alter = isset($stand['netz_alter']) ? $stand['netz_alter'] : null;
    $z[] = eb_pruefzeile(eb_klartext('SP.ZAEHLER'),
        ($mess_alter !== null && $mess_alter <= (int) $cfg['notfall_s']) ? 1 : 0,
        $mess_alter === null
            ? sprintf(eb_klartext('SP.ZAEHLER_KEINER'),
                      isset($stand['netz_anlass']) ? $stand['netz_anlass'] : '-')
            : sprintf(eb_klartext('SP.SEKUNDEN_HER'), (int) round($mess_alter)));

    /* ---- Dann die Einstellung ---- */
    $m = eb_maengel($cfg);
    $z[] = eb_pruefzeile(eb_klartext('SP.MAENGEL'), $m ? 0 : 1,
        $m ? sprintf(eb_klartext('SP.MAENGEL_ZAHL'), count($m)) : eb_klartext('SP.MAENGEL_KEINE'));

    $z[] = eb_pruefzeile(eb_klartext('SP.REGELUNG'), empty($cfg['ein']) ? -1 : 1,
        empty($cfg['ein']) ? eb_klartext('SP.REGELUNG_AUS') : eb_klartext('SP.REGELUNG_EIN'));

    $steller = eb_steller();
    $z[] = eb_pruefzeile(eb_klartext('SP.STELLER'), $steller ? 1 : 0,
        sprintf(eb_klartext('SP.STELLER_ZAHL'), count($steller)));

    $anlage = eb_anlage_max($steller);
    $z[] = eb_pruefzeile(eb_klartext('SP.ANLAGE_MAX'), $anlage > 0 ? 1 : 0,
        $anlage > 0 ? sprintf(eb_klartext('SP.ANLAGE_MAX_W'), $anlage)
                    : eb_klartext('SP.ANLAGE_MAX_FEHLT'));

    /* Der Speicherzweig wird nur beurteilt, wenn er ueberhaupt gewaehlt ist -
     * sonst waere die Zeile eine Beschwichtigung. */
    if (!empty($cfg['speicher_zuerst'])) {
        $gemessen = ($cfg['q_lade']['art'] !== 'aus');
        $z[] = eb_pruefzeile(eb_klartext('SP.SPEICHER_MESSUNG'), $gemessen ? 1 : 0,
            $gemessen ? $ja : eb_klartext('SP.SPEICHER_UNGEMESSEN'));
        $folgt = isset($stand['speicher_folgt']) ? (int) $stand['speicher_folgt'] : -1;
        $z[] = eb_pruefzeile(eb_klartext('SP.SPEICHER_FOLGT'),
            $folgt === 0 ? 0 : ($folgt === 1 ? 1 : -1),
            $folgt === 1 ? $ja : ($folgt === 0 ? $nein : eb_klartext('SP.NOCH_NICHT_GEPRUEFT')));
        $sp = eb_speicher_steller();
        $z[] = eb_pruefzeile(eb_klartext('SP.SPEICHER_WEG'), $sp ? 1 : -1,
            $sp ? $sp['name'] : eb_klartext('SP.SPEICHER_WEG_FEHLT'));
    }

    /* Der Ersatzzaehler: ein Ersatzweg, den niemand sieht, wird
     * unbemerkt zum Normalfall. */
    $hat_ersatz = ($cfg['q_netz2']['art'] !== 'aus');
    $laeuft_ersatz = !empty($stand['ersatz']);
    $z[] = eb_pruefzeile(eb_klartext('SP.ERSATZ'), $laeuft_ersatz ? 0 : 1,
        $laeuft_ersatz ? eb_klartext('SP.ERSATZ_NEIN')
            : ($hat_ersatz ? eb_klartext('SP.ERSATZ_JA') : eb_klartext('SP.ERSATZ_KEINER')));

    /* Taugt die Adresse, die in der Vorlage landet, fuer den Miniserver? */
    $zweifel = eb_adresse_zweifelhaft();
    $wo = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $z[] = eb_pruefzeile(eb_klartext('SP.ADRESSE'), $zweifel ? 0 : 1,
        sprintf(eb_klartext($zweifel ? 'SP.ADRESSE_ZWEIFELHAFT' : 'SP.ADRESSE_GUT'),
                $wo !== '' ? $wo : '-'));

    /* ---- Der Endpunkt, den der Miniserver aufruft ----
     *
     * Nur wenn der Reiter Test serverseitig der offene ist. Alle Reiter
     * werden mitgerendert; bis 0.9.17 lief dieser Netzaufruf deshalb bei
     * JEDEM Seitenaufruf, auch beim Blick in die Einstellungen. Am
     * 04.09.2026 gemessen: gegen eine Adresse, die nicht antwortet,
     * kostete das auf allen fuenf Reitern denselben Aufschlag. */
    if (!eb_test_offen()) {
        $z[] = eb_pruefzeile(eb_klartext('SP.EP_ERREICHBAR'), -1,
                             eb_klartext('SP.EP_NUR_IM_REITER'));
        $code = null;
    } else {
    $token = eb_token();
    list($code, $text) = eb_endpunkt_selftest($token);
    /* Drei Ausgaenge, nicht zwei: "ich konnte es nicht messen" darf
     * nicht wie "es ist kaputt" aussehen. Ein Webserver, der nur eine
     * Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus
     * nicht selbst aufrufen - im Pruefaufbau faellt genau das an. */
    $z[] = eb_pruefzeile(eb_klartext('SP.EP_ERREICHBAR'),
        $code === 0 ? -1 : (($code === 200 && strpos($text, 'OK=1') !== false) ? 1 : 0),
        $code ? sprintf(eb_klartext('SP.EP_ANTWORT'), $code, substr($text, 0, 40))
              : eb_klartext('SP.EP_KEINE_ANTWORT'));
    /* Die Gegenprobe nur, wenn ueberhaupt jemand geantwortet hat. Zweimal
     * ins Leere zu laufen kostet nur Wartezeit und sagt nichts Neues. */
    if ($code === 0) {
        $z[] = eb_pruefzeile(eb_klartext('SP.EP_ABWEISUNG'), -1,
                             eb_klartext('SP.EP_KEINE_ANTWORT'));
    } else {
        list($code2, $text2) = eb_endpunkt_selftest('falsch');
        $z[] = eb_pruefzeile(eb_klartext('SP.EP_ABWEISUNG'),
            ($code2 === 403 && strpos($text2, 'ERR=TOKEN') !== false) ? 1 : 0,
            sprintf(eb_klartext('SP.EP_ANTWORT'), $code2, substr($text2, 0, 40)));
    }
    }

    /* ---- Die Vorlage fuer Loxone ---- */
    list($vname, $vxml) = eb_vorlage();
    libxml_use_internal_errors(true);
    $x = @simplexml_load_string($vxml);
    $z[] = eb_pruefzeile(eb_klartext('SP.VORLAGE_XML'), $x === false ? 0 : 1,
        $x === false ? eb_klartext('SP.VORLAGE_KAPUTT') : $vname);
    if ($x !== false) {
        $titel = array();
        foreach ($x->VirtualInHttpCmd as $c) { $titel[] = (string) $c['Title']; }
        $doppelt = array_filter(array_count_values($titel), function ($n) { return $n > 1; });
        $z[] = eb_pruefzeile(eb_klartext('SP.VORLAGE_TITEL'), $doppelt ? 0 : 1,
            $doppelt ? implode(', ', array_keys($doppelt))
                     : sprintf(eb_klartext('SP.VORLAGE_ZAHL'), count($titel)));
    }

    /* ---- MQTT, aber nur wenn es gebraucht wird ---- */
    $braucht_mqtt = !empty($cfg['mqtt_ein']);
    /* Aus der EINEN Liste. Vier Namen standen hier fest verdrahtet,
     * eb_quellenfelder() fuehrt sieben: der Ersatzzaehler und der
     * zweite und dritte Wechselrichter fehlten. Lief einer davon ueber
     * MQTT und war der MQTT-Haken aus, fielen die beiden Zeilen unten
     * aus der Pruefung, und die Zusammenfassung meldete gruen. */
    foreach (array_keys(eb_quellenfelder()) as $k) {
        if ($cfg[$k]['art'] === 'mqtt') { $braucht_mqtt = true; }
    }
    foreach ($steller as $s) { if ($s['art'] === 'mqtt') { $braucht_mqtt = true; } }
    if ($braucht_mqtt) {
        @exec('command -v mosquitto_sub 2>/dev/null', $a1, $r1);
        @exec('command -v mosquitto_pub 2>/dev/null', $a2, $r2);
        $z[] = eb_pruefzeile(eb_klartext('SP.MQTT_WERKZEUGE'),
            ($r1 === 0 && $r2 === 0) ? 1 : 0,
            ($r1 === 0 && $r2 === 0) ? $ja : eb_klartext('SP.MQTT_WERKZEUGE_FEHLEN'));
        $mz = eb_mqtt_zustand();
        $z[] = eb_pruefzeile(eb_klartext('SP.MQTT_GATEWAY'),
            ($mz['gefunden'] && $mz['autostart']) ? 1 : 0,
            $mz['gefunden'] ? ($mz['autostart'] ? $ja : eb_klartext('SP.MQTT_KEIN_AUTOSTART'))
                            : eb_klartext('SP.MQTT_NICHT_GEFUNDEN'));
    }

    /* ---- Die eigene Oberflaeche ---- */
    list($nl, $nb, $nli, $gleich) = eb_reiter_zaehlen();
    $z[] = eb_pruefzeile(eb_klartext('SP.REITER'), $gleich ? 1 : 0,
        sprintf(eb_klartext('SP.REITER_ZAHL'), $nl, $nb, $nli));

    $z[] = eb_pruefzeile(eb_klartext('SP.SPRACHE'), eb_sprache_fehlt() ? 0 : 1,
        eb_sprache_fehlt() ? eb_klartext('SP.SPRACHE_FEHLT') : eb_sprache());

    /* ---- Ist die Konfiguration heil? ----
     * Der zuerst festgestellte Zustand, nicht der nach der Selbstheilung:
     * ein geheilter Schaden ist kein Nicht-Schaden. Die Zweitschrift kann
     * aelter sein als das, was verlorenging, und die Ursache besteht fort. */
    $eb_lagen = array(
        'ok'                       => 'SP.KONFIG_OK',
        'leer'                     => 'SP.KONFIG_LEER',
        'aus der Zweitschrift'     => 'SP.KONFIG_ZWEITSCHRIFT',
        'kaputt'                   => 'SP.KONFIG_KAPUTT',
        'kaputt ohne Zweitschrift' => 'SP.KONFIG_KAPUTT_OHNE',
        'unlesbarer Wert'          => 'SP.KONFIG_WERT',
    );
    $lage = eb_config_lage();
    $z[] = eb_pruefzeile(eb_klartext('SP.KONFIG'), $lage === 'ok' ? 1 : 0,
        eb_klartext(isset($eb_lagen[$lage]) ? $eb_lagen[$lage] : 'SP.KONFIG_UNBEKANNT'));

    /* ---- Tragen alle Formulare das Merkmal? ---- */
    list($fg, $fm) = eb_formulare_zaehlen();
    $z[] = eb_pruefzeile(eb_klartext('SP.FORMULARE'),
        ($fg > 0 && $fg === $fm) ? 1 : 0,
        sprintf(eb_klartext('SP.FORMULARE_ZAHL'), $fm, $fg));

    /* ---- Setzt der Server das sm-active? ---- */
    list($ag, $ae) = eb_active_serverseitig();
    $z[] = eb_pruefzeile(eb_klartext('SP.ACTIVE'),
        ($ae > 0 && $ag >= $ae) ? 1 : 0,
        sprintf(eb_klartext('SP.ACTIVE_ZAHL'), $ag, $ae));

    /* ---- Ist jeder Suchtext eindeutig? ---- */
    list($sz, $stoss) = eb_suchtexte_eindeutig();
    $z[] = eb_pruefzeile(eb_klartext('SP.SUCHTEXT'),
        $sz === 0 ? -1 : ($stoss ? 0 : 1),
        $sz === 0 ? eb_klartext('SP.SUCHTEXT_LEER')
                  : ($stoss ? implode(', ', $stoss)
                            : sprintf(eb_klartext('SP.SUCHTEXT_ZAHL'), $sz)));

    /* ---- Zuletzt der Rechenkern ---- */
    list($n, $f) = eb_selbsttest(false);
    $z[] = eb_pruefzeile(eb_klartext('SP.KERN'), $f === 0 ? 1 : 0,
        sprintf(eb_klartext('SP.KERN_ZAHL'), EB_KERN, $n, $f));

    return $z;
}

/** Die Selbstpruefung als Tabelle. */
function eb_selbstpruefung_html()
{
    $z = eb_selbstpruefung();
    $gruen = $rot = $hinweis = 0;
    foreach ($z as $e) {
        if ($e['ok'] === 1) { $gruen++; } elseif ($e['ok'] === 0) { $rot++; } else { $hinweis++; }
    }
    $o = '<table class="sm-tbl"><tr><th style="width:2em"></th><th>'
       . eb_e(eb_klartext('SP.SP_FRAGE')) . '</th><th>'
       . eb_e(eb_klartext('SP.SP_BEFUND')) . '</th></tr>';
    foreach ($z as $e) {
        $zeichen = $e['ok'] === 1 ? '&#10003;' : ($e['ok'] === 0 ? '&#10007;' : '&#8226;');
        $farbe = $e['ok'] === 1 ? 'sm-an' : ($e['ok'] === 0 ? 'sm-aus' : '');
        $o .= '<tr><td class="' . $farbe . '" style="text-align:center;font-weight:700">'
            . $zeichen . '</td><td>' . eb_e($e['frage']) . '</td><td>'
            . eb_e($e['bemerkung']) . '</td></tr>';
    }
    $o .= '</table>';
    /* Die Zusammenfassung darf nicht besser aussehen als ihr schlechtester
     * Punkt: gezaehlt werden die Kreuze, nicht die Haekchen. */
    $o .= '<p class="sm-hilfe"><b>'
        . eb_e(sprintf(eb_klartext($rot ? 'SP.SUMME_ROT' : 'SP.SUMME_GRUEN'),
                       $rot, count($z), $hinweis))
        . '</b></p>';
    return $o;
}

/* ==================================================================
 * Was waere, wenn?
 *
 * Der Trockenlauf rechnet mit den ZULETZT GEMESSENEN Werten. Nachts, bei
 * Regen oder vor der ersten Messung sagt er deshalb wenig. Hier gibt der
 * Mensch die Werte vor und sieht, was die Regelung damit taete - mit
 * demselben Kern und denselben Befehlen wie im Betrieb.
 *
 * Es wird nichts gestellt und nichts gespeichert.
 * ================================================================== */
function eb_test_wenn()
{
    $cfg = eb_config();
    $hol = function ($name, $vorgabe) {
        $r = isset($_POST[$name]) ? str_replace(',', '.', trim((string) $_POST[$name])) : '';
        if ($r === '') { return $vorgabe; }
        // Abweisen statt zurechtbiegen - auch in einem Denkspiel.
        return is_numeric($r) ? (float) $r : null;
    };
    $netz = $hol('w_netz', null);
    $erz  = $hol('w_erz', null);
    $soc  = $hol('w_soc', -1.0);
    $lade = $hol('w_lade', 0.0);
    if ($netz === null && (!isset($_POST['w_netz']) || trim((string) $_POST['w_netz']) !== '')) {
        return eb_klartext('TEST.W_KEINE_ZAHL');
    }
    foreach (array('w_erz' => $erz, 'w_soc' => $soc, 'w_lade' => $lade) as $k => $v) {
        if ($v === null && trim((string) (isset($_POST[$k]) ? $_POST[$k] : '')) !== '') {
            return eb_klartext('TEST.W_KEINE_ZAHL');
        }
    }
    if ($netz === null) { return eb_klartext('TEST.W_OHNE_NETZ'); }

    $cfg['ziel_w'] = eb_ziel_w($cfg);
    $cfg['anlage_max_w'] = eb_anlage_max();
    $steller = eb_steller();
    $mess = array('netz' => $netz, 'erzeugung' => $erz,
                  'soc' => ($soc === null ? -1.0 : $soc),
                  'lade_ist' => ($lade === null ? 0.0 : $lade), 'alter_s' => 1);
    /* Als Ausgangslage die zuletzt gestellte Grenze, sonst die Anlagenspitze -
     * genau wie der Dienst beim Einschalten. */
    $stand = eb_stand();
    $start = isset($stand['drossel_w']) ? (float) $stand['drossel_w']
           : ($cfg['anlage_max_w'] > 0 ? (float) $cfg['anlage_max_w'] : 0.0);
    $r = eb_regeln($mess, $cfg, array('drossel_w' => $start, 'lade_soll_w' => 0), microtime(true));

    $taten = array(EB_NICHTS => 'TAT.NICHTS', EB_SPEICHER => 'TAT.SPEICHER',
                   EB_DROSSEL => 'TAT.DROSSEL', EB_FREIGABE => 'TAT.FREIGABE');
    $o = array(eb_klartext('TEST.W_KOPF'), '');
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.W_NETZ'), (int) $netz);
    $o[] = sprintf('%-26s %s', eb_klartext('TEST.W_ERZEUGUNG'),
        $erz === null ? eb_klartext('TEST.W_UNGEMESSEN') : (int) $erz . ' W');
    $o[] = sprintf('%-26s %s', eb_klartext('TEST.W_SOC'),
        $soc < 0 ? eb_klartext('TEST.W_UNBEKANNT') : (int) $soc . ' %');
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.W_LADE'), (int) $lade);
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.W_START'), (int) $start);
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.W_ZIEL'), (int) $cfg['ziel_w']);
    $o[] = '';
    $o[] = sprintf('%-26s %s', eb_klartext('TEST.T_TAT'),
        eb_klartext(isset($taten[$r['tat']]) ? $taten[$r['tat']] : 'TAT.NICHTS'));
    $o[] = sprintf('%-26s %s', eb_klartext('TEST.T_ANLASS'),
        eb_klartext('ANLASS.' . strtoupper($r['anlass'])));
    $o[] = sprintf('%-26s %s W', eb_klartext('TEST.T_UEBER'),
        $r['ueberschuss_w'] === null ? '-' : (int) $r['ueberschuss_w']);
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.T_GRENZE'), $r['drossel_w']);
    $o[] = sprintf('%-26s %d W', eb_klartext('TEST.T_LADESOLL'), $r['lade_soll_w']);
    if (!empty($r['erzeugung_ersatz'])) { $o[] = eb_klartext('TEST.T_ERSATZ'); }
    if ($steller) {
        $o[] = '';
        $o[] = eb_klartext('TEST.T_VERTEILUNG');
        foreach (eb_aufteilen($r['drossel_w'], $steller) as $nr => $w) {
            list($adr, $inh, $ers) = eb_befehl_bauen($steller[$nr], $w);
            $o[] = sprintf('  %d %-18s %7d W', $nr, $steller[$nr]['name'], $w);
            $o[] = '      ' . $adr . ($inh !== '' ? '   ' . $inh : '');
        }
    }
    $o[] = '';
    $o[] = eb_klartext('TEST.W_FUSS');
    return implode("\n", $o);
}

/* ==================================================================
 * Bilanz
 *
 * Gezaehlt wird NUR, was gemessen ist. Eine Kilowattstundenzahl fuer den
 * entgangenen Ertrag steht hier ausdruecklich NICHT: um sie zu bilden,
 * muesste bekannt sein, was die Anlage ohne Grenze geliefert haette - und
 * das weiss niemand, denn sie hat es nicht geliefert. Ausgewiesen wird
 * stattdessen die DAUER der Abregelung. Eine Zahl, die niemand gemessen
 * hat, darf nicht aussehen wie eine, die jemand gemessen hat.
 * ================================================================== */
function eb_bilanz_html()
{
    $bz = eb_bilanz();
    if (!$bz || !isset($bz['heute'])) { return '<p class="sm-hilfe">'
        . eb_e(eb_klartext('TEST.B_NOCH_NICHTS')) . '</p>'; }
    $spalten = array(
        'heute'       => sprintf(eb_klartext('TEST.B_HEUTE'), isset($bz['tag']) ? $bz['tag'] : '-'),
        'gestern'     => sprintf(eb_klartext('TEST.B_GESTERN'), isset($bz['gestern_tag']) ? $bz['gestern_tag'] : '-'),
        'monat_werte' => sprintf(eb_klartext('TEST.B_MONAT'), isset($bz['monat']) ? $bz['monat'] : '-'),
    );
    $zeilen = array(
        'erzeugt_ws'     => array('TEST.B_ERZEUGT', 'kwh', 'erzeugt_gemessen'),
        'eingespeist_ws' => array('TEST.B_EINGESPEIST', 'kwh', ''),
        'bezogen_ws'     => array('TEST.B_BEZOGEN', 'kwh', ''),
        'speicher_ws'    => array('TEST.B_SPEICHER', 'kwh', 'speicher_gemessen'),
        'gedrosselt_s'   => array('TEST.B_GEDROSSELT', 'std', ''),
    );
    $o = '<table class="sm-tbl"><tr><th></th>';
    foreach ($spalten as $ueber) { $o .= '<th>' . eb_e($ueber) . '</th>'; }
    $o .= '</tr>';
    foreach ($zeilen as $feld => $info) {
        $o .= '<tr><td>' . eb_e(eb_klartext($info[0])) . '</td>';
        foreach ($spalten as $k => $unbenutzt) {
            $s = isset($bz[$k]) && is_array($bz[$k]) ? $bz[$k] : array();
            $belegt = ($info[2] === '' || !empty($s[$info[2]]));
            $w = isset($s[$feld]) ? eb_zahl($s[$feld], 0.0) : 0.0;
            if (!$belegt) {
                $o .= '<td>' . eb_e(eb_klartext('TEST.B_NICHT_GEMESSEN')) . '</td>';
            } elseif ($info[1] === 'kwh') {
                $o .= '<td>' . number_format(eb_kwh($w), 2, ',', '.') . ' kWh</td>';
            } else {
                $o .= '<td>' . number_format($w / 3600.0, 1, ',', '.') . ' h</td>';
            }
        }
        $o .= '</tr>';
    }
    return $o . '</table>';
}
