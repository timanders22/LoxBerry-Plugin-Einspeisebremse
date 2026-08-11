<?php
/**
 * Einspeisebremse - der Regelkern
 *
 * Hier steht ausschliesslich Rechnung. Kein Netz, keine Datei, keine Uhr,
 * die nicht uebergeben wurde. Das ist der Grund, warum sich dieser Teil
 * ohne Wechselrichter, ohne Speicher und ohne Sonne pruefen laesst - und
 * warum eine Aenderung daran nicht heimlich etwas anderes tut, als sie
 * verspricht. Alles, was zieht und schaltet, steht in eb_dienst.php.
 *
 * VORZEICHEN, ein fuer alle Mal:
 *   netz > 0  Bezug aus dem Netz
 *   netz < 0  Einspeisung ins Netz
 * Jedes Messgeraet macht es anders; umgedreht wird EINMAL beim Einlesen,
 * danach gilt im ganzen Plugin diese eine Regel.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

define('EB_KERN', '1.0.0');

/* Was das Stellwerk in einem Durchlauf tun kann. */
define('EB_NICHTS',   0);
define('EB_SPEICHER', 1);   // Ueberschuss in den Speicher laden
define('EB_DROSSEL',  2);   // Erzeugung abregeln - kostet Ertrag
define('EB_FREIGABE', 3);   // Drosselung zuruecknehmen

/* Warum der Kern gerade so entscheidet. Diese Woerter erscheinen
 * uebersetzt in der Oberflaeche; wer hier eines hinzufuegt, muss es in
 * beiden Sprachdateien unter [ANLASS] nachtragen. */

function eb_zahl($wert, $vorgabe = 0.0)
{
    if (is_bool($wert) || $wert === null) { return (float) $vorgabe; }
    if (is_int($wert) || is_float($wert)) {
        return is_finite((float) $wert) ? (float) $wert : (float) $vorgabe;
    }
    $s = str_replace(',', '.', trim((string) $wert));
    if ($s === '' || !is_numeric($s)) { return (float) $vorgabe; }
    $f = (float) $s;
    return is_finite($f) ? $f : (float) $vorgabe;
}

/**
 * Taugt dieser Messwert?
 *
 * Rueckgabe: (1/0, Anlass). Ein Hausanschluss fuehrt keine Megawatt. Ein
 * Wert jenseits der Grenze ist kein Betriebszustand, sondern ein
 * Uebertragungsfehler - und der darf NICHT auf null zurechtgebogen werden:
 * null hiesse "kein Bezug, keine Einspeisung", und daraufhin gaebe die
 * Regelung die Erzeugung frei. Verworfen wird er, nicht geglaettet.
 */
function eb_messwert_taugt($roh, $grenze_w = 200000.0)
{
    if ($roh === null || $roh === '' || is_bool($roh)) { return array(0, 'fehlt'); }
    $s = is_string($roh) ? str_replace(',', '.', trim($roh)) : $roh;
    if (is_string($s) && !is_numeric($s)) { return array(0, 'unlesbar'); }
    $w = (float) $s;
    if (!is_finite($w)) { return array(0, 'unlesbar'); }
    if (abs($w) > $grenze_w) { return array(0, 'unglaubhaft'); }
    return array(1, 'gut');
}

/** Das Vorzeichen des Messgeraets auf die Hausregel bringen. */
function eb_netz_richten($roh, $invertieren)
{
    $w = eb_zahl($roh, 0.0);
    return $invertieren ? -$w : $w;
}

/**
 * Wie viel Einspeisung ist gerade zu viel?
 *
 * Positiv: so viele Watt muessen weg. Negativ: so viel Luft ist noch da.
 * $ziel_w ist die ERLAUBTE Einspeisung als positive Zahl - 0 bei
 * Nulleinspeisung, 4200 bei einer 70-Prozent-Regelung an 6 kWp.
 */
function eb_ueberschuss($netz_w, $ziel_w)
{
    $einspeisung = -eb_zahl($netz_w, 0.0);          // positiv = es fliesst hinaus
    return $einspeisung - max(0.0, eb_zahl($ziel_w, 0.0));
}

/**
 * Wie viel kann der Speicher jetzt noch aufnehmen?
 *
 * Nicht "wie viel passt theoretisch hinein", sondern was in dieser Sekunde
 * zusaetzlich geht: die Ladeleistung ist begrenzt, und oberhalb des
 * eingestellten Ladeschlusses wird nichts mehr angenommen. Wer das
 * uebersieht, verteilt Leistung an einen vollen Speicher und wundert sich,
 * dass die Einspeisung bleibt.
 */
function eb_speicher_luft($soc, $soc_max, $lade_ist_w, $lade_max_w)
{
    $soc = eb_zahl($soc, -1.0);
    $lade_max = max(0.0, eb_zahl($lade_max_w, 0.0));
    $lade_ist = max(0.0, eb_zahl($lade_ist_w, 0.0));
    if ($lade_max <= 0.0) { return 0.0; }
    // Ein unbekannter Ladestand ist kein voller und kein leerer. Er wird als
    // "unbekannt" behandelt: laden ja, aber nur bis zur halben Leistung.
    if ($soc < 0.0) { return max(0.0, $lade_max * 0.5 - $lade_ist); }
    if ($soc >= eb_zahl($soc_max, 95.0)) { return 0.0; }
    return max(0.0, $lade_max - $lade_ist);
}

/**
 * Eine Aenderung an der Leine fuehren.
 *
 * Abwaerts darf es schnell gehen - das ist die Richtung, die eine Auflage
 * einhaelt. Aufwaerts langsam, sonst schaukelt sich die Regelung auf: jede
 * Freigabe erzeugt neue Einspeisung, die im naechsten Durchlauf wieder
 * abgeregelt wird, und die Anlage pumpt im Sekundentakt.
 */
function eb_rampe($alt_w, $wunsch_w, $rampe_ab_w, $rampe_auf_w)
{
    $alt = eb_zahl($alt_w, 0.0);
    $neu = eb_zahl($wunsch_w, 0.0);
    $ab = max(1.0, eb_zahl($rampe_ab_w, 2000.0));
    $auf = max(1.0, eb_zahl($rampe_auf_w, 300.0));
    if ($neu < $alt) { return max($neu, $alt - $ab); }
    if ($neu > $alt) { return min($neu, $alt + $auf); }
    return $alt;
}

/**
 * Der ganze Entschluss eines Durchlaufs.
 *
 * $mess:  netz, erzeugung, soc, lade_ist, alter_s (Alter des Netzmesswerts)
 * $cfg:   ziel_w, totband_w, rampe_ab_w, rampe_auf_w, soc_max,
 *         lade_max_w, drossel_min_w, notfall_s, notfall_w, speicher_zuerst
 * $zust:  drossel_w (zuletzt gestellte Erzeugungsgrenze), lade_soll_w
 *
 * Rueckgabe: drossel_w, lade_soll_w, tat, anlass, ueberschuss_w, notfall
 */
function eb_regeln($mess, $cfg, $zust, $jetzt)
{
    $erzeugung = max(0.0, eb_zahl(isset($mess['erzeugung']) ? $mess['erzeugung'] : 0, 0.0));
    $drossel_alt = eb_zahl(isset($zust['drossel_w']) ? $zust['drossel_w'] : $erzeugung, $erzeugung);
    $lade_alt = max(0.0, eb_zahl(isset($zust['lade_soll_w']) ? $zust['lade_soll_w'] : 0, 0.0));
    $alter = eb_zahl(isset($mess['alter_s']) ? $mess['alter_s'] : -1, -1.0);
    $notfall_s = max(5.0, eb_zahl(isset($cfg['notfall_s']) ? $cfg['notfall_s'] : 60, 60.0));

    $erg = array(
        'drossel_w' => $drossel_alt,
        'lade_soll_w' => $lade_alt,
        'tat' => EB_NICHTS,
        'anlass' => 'ruhe',
        'ueberschuss_w' => 0.0,
        'notfall' => 0,
    );

    /* ---- Totmannschaltung ----
     * Kein frischer Messwert heisst NICHT "alles in Ordnung". Wer bei
     * ausgefallenem Zaehler weiterregelt, regelt auf eine Erinnerung. Die
     * Anlage geht auf den Notwert - bei Nulleinspeisung ist das die
     * Erzeugung auf den Hausverbrauch herunter, und den kennt hier niemand,
     * also auf den eingestellten sicheren Wert. */
    if ($alter < 0.0 || $alter > $notfall_s) {
        $notfall_w = max(0.0, eb_zahl(isset($cfg['notfall_w']) ? $cfg['notfall_w'] : 0, 0.0));
        $erg['drossel_w'] = min($drossel_alt, $notfall_w);
        $erg['lade_soll_w'] = max(0.0, eb_zahl(isset($cfg['lade_max_w']) ? $cfg['lade_max_w'] : 0, 0.0));
        $erg['tat'] = EB_DROSSEL;
        $erg['anlass'] = ($alter < 0.0) ? 'kein_messwert' : 'messwert_alt';
        $erg['notfall'] = 1;
        return $erg;
    }

    $netz = eb_zahl(isset($mess['netz']) ? $mess['netz'] : 0, 0.0);
    $ueber = eb_ueberschuss($netz, isset($cfg['ziel_w']) ? $cfg['ziel_w'] : 0);
    $erg['ueberschuss_w'] = $ueber;
    $totband = max(0.0, eb_zahl(isset($cfg['totband_w']) ? $cfg['totband_w'] : 50, 50.0));

    if (abs($ueber) <= $totband) {
        $erg['anlass'] = 'im_band';
        return $erg;
    }

    $rab = isset($cfg['rampe_ab_w']) ? $cfg['rampe_ab_w'] : 2000;
    $rauf = isset($cfg['rampe_auf_w']) ? $cfg['rampe_auf_w'] : 300;

    if ($ueber > 0.0) {
        /* Zu viel geht hinaus. Erst den Speicher fragen - der kostet keinen
         * Ertrag. Abregeln ist immer der zweite Griff, nie der erste. */
        $luft = 0.0;
        if (!empty($cfg['speicher_zuerst'])) {
            $luft = eb_speicher_luft(
                isset($mess['soc']) ? $mess['soc'] : -1,
                isset($cfg['soc_max']) ? $cfg['soc_max'] : 95,
                isset($mess['lade_ist']) ? $mess['lade_ist'] : 0,
                isset($cfg['lade_max_w']) ? $cfg['lade_max_w'] : 0);
        }
        if ($luft > 0.0) {
            $nimmt = min($luft, $ueber);
            $erg['lade_soll_w'] = $lade_alt + $nimmt;
            $erg['tat'] = EB_SPEICHER;
            $erg['anlass'] = 'in_speicher';
            $ueber -= $nimmt;
            if ($ueber <= $totband) { return $erg; }
        }
        $min = max(0.0, eb_zahl(isset($cfg['drossel_min_w']) ? $cfg['drossel_min_w'] : 0, 0.0));
        $wunsch = max($min, $erzeugung - $ueber);
        $erg['drossel_w'] = eb_rampe($drossel_alt, $wunsch, $rab, $rauf);
        $erg['tat'] = EB_DROSSEL;
        $erg['anlass'] = ($erg['anlass'] === 'in_speicher') ? 'speicher_voll_drossel' : 'drosseln';
        return $erg;
    }

    /* Luft nach oben. Die Reihenfolge ist hier NICHT dieselbe wie oben.
     *
     * Zuerst wird die Ladeleistung zurueckgenommen. Denn "Luft nach oben"
     * heisst bei Nulleinspeisung: es wird gerade Strom aus dem Netz bezogen -
     * und wenn zugleich der Speicher laedt, dann laedt er aus dem Netz. Das
     * ist teuer, verlustbehaftet und genau das Gegenteil der Absicht. Erst
     * wenn das abgestellt ist, lohnt es, die Drosselung zurueckzunehmen. */
    $frei = -$ueber;
    if ($lade_alt > 0.0) {
        $weniger = min($lade_alt, $frei);
        $erg['lade_soll_w'] = $lade_alt - $weniger;
        $erg['tat'] = EB_SPEICHER;
        $erg['anlass'] = 'weniger_laden';
        $frei -= $weniger;
        if ($frei <= $totband) { return $erg; }
    }
    if ($drossel_alt < $erzeugung + $frei) {
        $wunsch = min($drossel_alt + $frei, $erzeugung + $frei);
        $erg['drossel_w'] = eb_rampe($drossel_alt, $wunsch, $rab, $rauf);
        if ($erg['drossel_w'] > $drossel_alt) {
            $erg['tat'] = EB_FREIGABE;
            $erg['anlass'] = ($erg['anlass'] === 'weniger_laden')
                ? 'weniger_laden_freigabe' : 'freigabe';
            return $erg;
        }
    }
    if ($erg['anlass'] === 'ruhe') { $erg['anlass'] = 'nichts_zu_holen'; }
    return $erg;
}

/**
 * Die Gesamtgrenze auf mehrere Stellglieder aufteilen.
 *
 * Ohne Anteilsangaben gleichmaessig, sonst im Verhaeltnis der Anteile. Eine
 * eingetragene Spitzenleistung deckelt, was ein Geraet bekommen kann.
 *
 * DER REST DARF NICHT VERSCHWINDEN. Wird ein Geraet durch seine Spitze
 * gedeckelt, muss das Uebrige an die anderen gehen - und zwar so lange, bis
 * nichts mehr uebrig ist oder niemand mehr Luft hat. Ohne diese Runden
 * summieren sich die gestellten Grenzen auf weniger als die erlaubte, und
 * die Anlage bleibt dauerhaft zu scharf abgeregelt, ohne dass irgendwo ein
 * Fehler steht. (Gefunden am 11.08.2026: 5200 W Grenze, 60/40 auf zwei
 * Geraete, das erste bei 1600 W gedeckelt - verteilt wurden 3680 W.)
 *
 * $steller ist die Liste aus eb_steller(): Schluessel = Nummer, jeder
 * Eintrag mit 'anteil' und 'spitze_w'.
 */
function eb_aufteilen($gesamt_w, $steller)
{
    $out = array();
    if (!$steller) { return $out; }
    $gesamt = max(0.0, eb_zahl($gesamt_w, 0.0));

    $deckel = array();
    $anteil = array();
    $summe = 0.0;
    foreach ($steller as $nr => $s) {
        $out[$nr] = 0.0;
        $sp = isset($s['spitze_w']) ? (int) $s['spitze_w'] : 0;
        // 0 heisst "keine Spitze eingetragen", also kein Deckel - nicht "null Watt".
        $deckel[$nr] = ($sp > 0) ? (float) $sp : INF;
        $anteil[$nr] = max(0.0, (float) (isset($s['anteil']) ? $s['anteil'] : 0));
        $summe += $anteil[$nr];
    }
    if ($summe <= 0.0) {
        foreach ($anteil as $nr => $unbenutzt) { $anteil[$nr] = 1.0; }
        $summe = (float) count($steller);
    }

    $rest = $gesamt;
    $offen = array_keys($out);
    // Hoechstens so viele Runden, wie es Geraete gibt: in jeder Runde faellt
    // mindestens eines auf seinen Deckel, sonst ist der Rest verteilt.
    for ($runde = 0; $runde < count($steller) + 1 && $rest > 0.5 && $offen; $runde++) {
        $teilsumme = 0.0;
        foreach ($offen as $nr) { $teilsumme += $anteil[$nr]; }
        if ($teilsumme <= 0.0) { break; }
        $verteilt = 0.0;
        $neu_offen = array();
        foreach ($offen as $nr) {
            $will = $out[$nr] + $rest * ($anteil[$nr] / $teilsumme);
            if ($will >= $deckel[$nr]) {
                $verteilt += $deckel[$nr] - $out[$nr];
                $out[$nr] = $deckel[$nr];
            } else {
                $verteilt += $will - $out[$nr];
                $out[$nr] = $will;
                $neu_offen[] = $nr;
            }
        }
        $rest -= $verteilt;
        $offen = $neu_offen;
    }

    // Abrunden, damit keine krummen Watt hinausgehen - und der dabei
    // anfallende Bruchteil geht an den Ersten, der noch Luft hat.
    $verteilt = 0.0;
    foreach ($out as $nr => $w) { $out[$nr] = floor($w); $verteilt += $out[$nr]; }
    $rest = $gesamt - $verteilt;
    if ($rest >= 1.0) {
        foreach ($out as $nr => $w) {
            if ($rest < 1.0) { break; }
            $luft = $deckel[$nr] - $w;
            if ($luft <= 0) { continue; }
            $gib = floor(min($rest, $luft));
            $out[$nr] = $w + $gib;
            $rest -= $gib;
        }
    }
    return $out;
}

/**
 * Hat der Befehl gewirkt?
 *
 * Ein Stellwert, den ein Wechselrichter mit HTTP 200 quittiert und dann
 * ignoriert, ist der unangenehmste Fall des ganzen Plugins: alles meldet
 * Erfolg, und die Einspeisung bleibt. Deshalb wird nicht die Antwort
 * geprueft, sondern die Wirkung - naemlich ob die Einspeisung nach der
 * Wartezeit tatsaechlich gefallen ist.
 *
 * Rueckgabe: 1 gewirkt, 0 noch offen, -1 keine Wirkung feststellbar.
 */
function eb_wirkung($vorher_w, $nachher_w, $gestellt_w, $wartezeit_s, $vergangen_s, $mindest_w = 100.0)
{
    if ($vergangen_s < max(1.0, eb_zahl($wartezeit_s, 15.0))) { return 0; }
    $soll_weniger = max(eb_zahl($mindest_w, 100.0),
                        0.3 * max(0.0, eb_zahl($vorher_w, 0.0) - eb_zahl($gestellt_w, 0.0)));
    $ist_weniger = eb_zahl($vorher_w, 0.0) - eb_zahl($nachher_w, 0.0);
    return ($ist_weniger >= $soll_weniger) ? 1 : -1;
}

/* ==================================================================
 * Selbsttest
 *
 * Rechnet die Faelle durch, die im Betrieb wehtun. Aufruf:
 *   php eb_regel.php
 * ================================================================== */

function eb_selbsttest($ausgabe = true)
{
    $n = 0; $f = 0;
    $pruef = function ($name, $ist, $soll, $genau = 0.5) use (&$n, &$f, $ausgabe) {
        $n++;
        $ok = is_string($soll) ? ($ist === $soll) : (abs($ist - $soll) <= $genau);
        if (!$ok) { $f++; }
        if ($ausgabe) {
            echo ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
            if (!$ok) { echo '  -> ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true); }
            echo "\n";
        }
    };

    // ---- Messwerte annehmen oder abweisen ----
    list($ok, $a) = eb_messwert_taugt('-2350.5');   $pruef('Zahl als Text wird angenommen', $ok, 1);
    list($ok, $a) = eb_messwert_taugt(null);        $pruef('null wird abgewiesen', $a, 'fehlt');
    list($ok, $a) = eb_messwert_taugt('');          $pruef('Leerstring wird abgewiesen', $a, 'fehlt');
    list($ok, $a) = eb_messwert_taugt('n/a');       $pruef('unlesbarer Text wird abgewiesen', $a, 'unlesbar');
    list($ok, $a) = eb_messwert_taugt(9999999);     $pruef('Megawatt am Hausanschluss abgewiesen', $a, 'unglaubhaft');
    list($ok, $a) = eb_messwert_taugt(0);           $pruef('Null ist ein gueltiger Messwert', $ok, 1);

    // ---- Vorzeichen ----
    $pruef('Vorzeichen unveraendert', eb_netz_richten(-1200, false), -1200);
    $pruef('Vorzeichen gedreht', eb_netz_richten(-1200, true), 1200);

    // ---- Ueberschuss ----
    $pruef('3000 W hinaus, 0 erlaubt', eb_ueberschuss(-3000, 0), 3000);
    $pruef('3000 W hinaus, 4200 erlaubt', eb_ueberschuss(-3000, 4200), -1200);
    $pruef('Bezug: nichts abzuregeln', eb_ueberschuss(800, 0), -800);

    // ---- Speicherluft ----
    $pruef('halbvoll, laedt nicht', eb_speicher_luft(50, 95, 0, 3000), 3000);
    $pruef('halbvoll, laedt schon 1200', eb_speicher_luft(50, 95, 1200, 3000), 1800);
    $pruef('voll: keine Luft', eb_speicher_luft(96, 95, 0, 3000), 0);
    $pruef('kein Speicher: keine Luft', eb_speicher_luft(50, 95, 0, 0), 0);
    // Unbekannter Ladestand: nicht raten, aber auch nicht blockieren.
    $pruef('Ladestand unbekannt: halbe Leistung', eb_speicher_luft(-1, 95, 0, 3000), 1500);

    // ---- Rampe ----
    $pruef('abwaerts darf weit springen', eb_rampe(6000, 1000, 2000, 300), 4000);
    $pruef('aufwaerts nur in kleinen Schritten', eb_rampe(1000, 6000, 2000, 300), 1300);
    $pruef('Ziel innerhalb der Rampe wird erreicht', eb_rampe(1000, 1200, 2000, 300), 1200);

    $cfg = array('ziel_w' => 0, 'totband_w' => 50, 'rampe_ab_w' => 2000, 'rampe_auf_w' => 300,
                 'soc_max' => 95, 'lade_max_w' => 3000, 'drossel_min_w' => 0,
                 'notfall_s' => 60, 'notfall_w' => 0, 'speicher_zuerst' => 1);

    // ---- Totmannschaltung ----
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'alter_s' => 120),
                   $cfg, array('drossel_w' => 6000), 1000);
    $pruef('alter Messwert: Notfall', $r['notfall'], 1);
    $pruef('alter Messwert: Grenze auf Notwert', $r['drossel_w'], 0);
    $pruef('alter Messwert: Anlass', $r['anlass'], 'messwert_alt');
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'alter_s' => -1),
                   $cfg, array('drossel_w' => 6000), 1000);
    $pruef('nie ein Messwert: Notfall', $r['anlass'], 'kein_messwert');
    // Der Notfall darf die Grenze nur SENKEN, nie anheben.
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'alter_s' => 120),
                   array_merge($cfg, array('notfall_w' => 9999)),
                   array('drossel_w' => 1500), 1000);
    $pruef('Notfall hebt eine Drosselung nicht auf', $r['drossel_w'], 1500);

    // ---- Regelfaelle ----
    $r = eb_regeln(array('netz' => -20, 'erzeugung' => 3000, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 3000), 1000);
    $pruef('im Totband: nichts tun', $r['tat'], EB_NICHTS);

    // 2000 W hinaus, Speicher nimmt alles - kein Ertrag geht verloren.
    $r = eb_regeln(array('netz' => -2000, 'erzeugung' => 5000, 'soc' => 40, 'lade_ist' => 0, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 5000, 'lade_soll_w' => 0), 1000);
    $pruef('Speicher zuerst: Tat', $r['tat'], EB_SPEICHER);
    $pruef('Speicher zuerst: Ladesoll', $r['lade_soll_w'], 2000);
    $pruef('Speicher zuerst: nicht gedrosselt', $r['drossel_w'], 5000);

    // 5000 W hinaus, Speicher kann nur 3000 - der Rest muss abgeregelt werden.
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 7000, 'soc' => 40, 'lade_ist' => 0, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 7000, 'lade_soll_w' => 0), 1000);
    $pruef('Speicher voll ausgereizt: Ladesoll', $r['lade_soll_w'], 3000);
    $pruef('Rest wird gedrosselt', $r['drossel_w'], 5000);
    $pruef('Anlass nennt beides', $r['anlass'], 'speicher_voll_drossel');

    // Speicher voll: sofort abregeln, ohne Umweg.
    $r = eb_regeln(array('netz' => -1500, 'erzeugung' => 4000, 'soc' => 100, 'lade_ist' => 0, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 4000, 'lade_soll_w' => 0), 1000);
    $pruef('voller Speicher: gedrosselt', $r['tat'], EB_DROSSEL);
    $pruef('voller Speicher: Grenze', $r['drossel_w'], 2500);

    // Ohne Speicher-Vorrang wird sofort gedrosselt.
    $r = eb_regeln(array('netz' => -2000, 'erzeugung' => 5000, 'soc' => 40, 'lade_ist' => 0, 'alter_s' => 3),
                   array_merge($cfg, array('speicher_zuerst' => 0)),
                   array('drossel_w' => 5000, 'lade_soll_w' => 0), 1000);
    $pruef('ohne Speicher-Vorrang: gedrosselt', $r['tat'], EB_DROSSEL);

    // Die Untergrenze wird eingehalten.
    $r = eb_regeln(array('netz' => -9000, 'erzeugung' => 9000, 'soc' => 100, 'alter_s' => 3),
                   array_merge($cfg, array('drossel_min_w' => 1000, 'rampe_ab_w' => 99000)),
                   array('drossel_w' => 9000), 1000);
    $pruef('Untergrenze wird nicht unterschritten', $r['drossel_w'], 1000);

    // Freigabe: Bezug aus dem Netz, Drosselung darf zurueck.
    $r = eb_regeln(array('netz' => 1000, 'erzeugung' => 2000, 'soc' => 60, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 2000, 'lade_soll_w' => 0), 1000);
    $pruef('Freigabe: Tat', $r['tat'], EB_FREIGABE);
    $pruef('Freigabe nur in Rampenschritten', $r['drossel_w'], 2300);

    /* Bezug aus dem Netz, WAEHREND der Speicher laedt: dann laedt der
     * Speicher aus dem Netz. Das muss zuerst aufhoeren - vor jeder
     * Freigabe. Ohne diese Reihenfolge kauft die Anlage Strom, um ihn
     * einzulagern, und meldet dabei ordnungsgemaesse Regelung. */
    $r = eb_regeln(array('netz' => 800, 'erzeugung' => 4000, 'soc' => 60, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 4000, 'lade_soll_w' => 2000), 1000);
    $pruef('Bezug bei ladendem Speicher: weniger laden', $r['anlass'], 'weniger_laden');
    $pruef('Ladesoll faellt um den Bezug', $r['lade_soll_w'], 1200);
    $pruef('Drosselung bleibt vorerst', $r['drossel_w'], 4000);

    // Reicht das Zuruecknehmen nicht aus, wird zusaetzlich freigegeben.
    $r = eb_regeln(array('netz' => 2500, 'erzeugung' => 4000, 'soc' => 60, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 4000, 'lade_soll_w' => 500), 1000);
    $pruef('erst Laden herunter, dann Freigabe', $r['anlass'], 'weniger_laden_freigabe');
    $pruef('Ladesoll auf null', $r['lade_soll_w'], 0);
    $pruef('danach Freigabe in Rampenschritten', $r['drossel_w'], 4300);

    // 70-Prozent-Regelung: 3000 W hinaus sind erlaubt, nichts zu tun.
    $r = eb_regeln(array('netz' => -3000, 'erzeugung' => 6000, 'soc' => 60, 'alter_s' => 3),
                   array_merge($cfg, array('ziel_w' => 4200)),
                   array('drossel_w' => 6000), 1000);
    $pruef('70-Prozent: 3000 W hinaus sind erlaubt', $r['tat'], EB_FREIGABE);
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'soc' => 100, 'alter_s' => 3),
                   array_merge($cfg, array('ziel_w' => 4200)),
                   array('drossel_w' => 6000), 1000);
    $pruef('70-Prozent: 5000 W hinaus sind zu viel', $r['drossel_w'], 5200);

    // ---- Aufteilen ----
    $st = function ($anteil, $spitze) { return array('anteil' => $anteil, 'spitze_w' => $spitze); };
    $summe = function ($a) { return array_sum($a); };

    $a = eb_aufteilen(4000, array(1 => $st(0, 0), 2 => $st(0, 0)));
    $pruef('ohne Anteile: gleichmaessig (1)', $a[1], 2000);
    $pruef('ohne Anteile: gleichmaessig (2)', $a[2], 2000);

    $a = eb_aufteilen(5000, array(1 => $st(60, 0), 2 => $st(40, 0)));
    $pruef('60 Prozent', $a[1], 3000);
    $pruef('40 Prozent', $a[2], 2000);

    /* Der Fall, an dem die erste Fassung gescheitert ist: das anteilig
     * groessere Geraet stoesst an seine Spitze. Der Rest MUSS zum anderen
     * wandern, sonst regelt die Anlage dauerhaft zu scharf ab. */
    $a = eb_aufteilen(5200, array(1 => $st(60, 1600), 2 => $st(40, 5000)));
    $pruef('gedeckeltes Geraet bleibt auf seiner Spitze', $a[1], 1600);
    $pruef('der Rest wandert zum anderen', $a[2], 3600);
    $pruef('nichts geht verloren', $summe($a), 5200);

    // Beide gedeckelt: dann bleibt etwas uebrig - und das ist ehrlich so.
    $a = eb_aufteilen(9000, array(1 => $st(50, 1600), 2 => $st(50, 2000)));
    $pruef('beide am Anschlag (1)', $a[1], 1600);
    $pruef('beide am Anschlag (2)', $a[2], 2000);
    $pruef('mehr als die Anlage kann bleibt liegen', $summe($a), 3600);

    // Drei Geraete, krumme Zahl: es darf kein Watt verschwinden.
    $a = eb_aufteilen(1000, array(1 => $st(1, 0), 2 => $st(1, 0), 3 => $st(1, 0)));
    $pruef('krumme Teilung verliert nichts', $summe($a), 1000);

    $pruef('Grenze 0 verteilt 0', $summe(eb_aufteilen(0, array(1 => $st(50, 0), 2 => $st(50, 0)))), 0);
    $pruef('ohne Stellglieder leere Liste', count(eb_aufteilen(5000, array())), 0);

    // ---- Wirkung ----
    $pruef('Wartezeit laeuft noch', eb_wirkung(6000, 6000, 3000, 15, 5), 0);
    $pruef('Wirkung eingetreten', eb_wirkung(6000, 3100, 3000, 15, 20), 1);
    $pruef('keine Wirkung trotz Quittung', eb_wirkung(6000, 5950, 3000, 15, 20), -1);
    $pruef('kleine Aenderung, kleine Wirkung genuegt', eb_wirkung(1000, 880, 900, 15, 20), 1);

    if ($ausgabe) {
        echo sprintf("\nEinspeisebremse-Kern %s: %d Faelle geprueft, %d Fehlschlaege.\n",
                     EB_KERN, $n, $f);
    }
    return array($n, $f);
}

/* Nur beim direkten Aufruf. Wird die Datei eingebunden, passiert nichts. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    list($n, $f) = eb_selbsttest(true);
    exit($f === 0 ? 0 : 1);
}
