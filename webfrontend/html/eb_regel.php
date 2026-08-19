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

define('EB_KERN', '1.2.0');

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
 * Folgt der Speicher dem Ladesoll ueberhaupt?
 *
 * Ein Ladesoll ist eine BITTE. Ob der Speicher sie annimmt, sagt allein die
 * gemessene Ladeleistung. Bis 0.9.4 hat der Kern den Ueberschuss dem
 * Speicher gutgeschrieben und daraufhin NICHT abgeregelt - auch dann, wenn
 * gar keine Ladeleistung gemessen wurde. Gemessen am 18.08.2026: bei
 * eingeschaltetem Speicher-Vorrang und fehlender Ladeleistungs-Quelle
 * speiste die Anlage dauerhaft weiter, waehrend der Anlass "der Ueberschuss
 * geht in den Speicher" meldete und nirgends ein Fehler stand. Das ist
 * dieselbe Klasse wie eine Quittung, die keine Wirkung ist.
 *
 * $zust traegt sp_probe_seit (wann zuletzt hingesehen wurde),
 * sp_probe_lade (welche Ladeleistung damals anlag) und sp_sperre_bis.
 *
 * Rueckgabe: array(folgt, probe_seit, probe_lade, sperre_bis)
 *   folgt = 1  der Speicherweg ist offen - er folgt, oder die Wartezeit laeuft
 *   folgt = 0  gemessen: er folgt nicht, der Weg bleibt eine Weile gesperrt
 */
function eb_speicher_wirkt($lade_ist, $lade_soll, $zust, $jetzt, $wartezeit_s)
{
    $seit = eb_zahl(isset($zust['sp_probe_seit']) ? $zust['sp_probe_seit'] : 0, 0.0);
    $basis = eb_zahl(isset($zust['sp_probe_lade']) ? $zust['sp_probe_lade'] : 0, 0.0);
    $sperre = eb_zahl(isset($zust['sp_sperre_bis']) ? $zust['sp_sperre_bis'] : 0, 0.0);
    $ist = max(0.0, eb_zahl($lade_ist, 0.0));
    $soll = max(0.0, eb_zahl($lade_soll, 0.0));
    $warte = max(1.0, eb_zahl($wartezeit_s, 20.0));
    $jetzt = eb_zahl($jetzt, 0.0);

    // Die Sperre laeuft noch: nicht in jeder Runde neu probieren.
    if ($sperre > 0.0 && $jetzt < $sperre) { return array(0, 0.0, 0.0, $sperre); }
    // Es wird gar nichts verlangt - dann gibt es auch nichts zu pruefen.
    if ($soll <= 0.0) { return array(1, 0.0, 0.0, 0.0); }
    // Erste Runde: Zeitpunkt und Ausgangsleistung merken.
    if ($seit <= 0.0) { return array(1, $jetzt, $ist, 0.0); }
    // Die Wartezeit laeuft noch - dem Speicher Zeit lassen.
    if ($jetzt - $seit < $warte) { return array(1, $seit, $basis, 0.0); }
    // Jetzt wird gemessen: ist die Ladeleistung wirklich gestiegen?
    $verlangt = max(100.0, 0.3 * max(0.0, $soll - $basis));
    if ($ist - $basis >= $verlangt) { return array(1, $jetzt, $ist, 0.0); }
    // Er folgt nicht. Der Weg wird fuer zehn Wartezeiten gesperrt; solange
    // wird abgeregelt, statt weiter auf einen Speicher zu hoffen.
    return array(0, 0.0, 0.0, $jetzt + 10.0 * $warte);
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
 * Die harten Grenzen wahren - der einzige Ausgang von eb_regeln().
 *
 * Die Anlagenobergrenze ist die Summe der eingetragenen Spitzenleistungen.
 * Ohne sie stand nach dem Ausschalten der Freigabewert (Vorgabe 100000) im
 * Zustand, und beim Wiedereinschalten rampte die Regelung von dort herunter:
 * gemessen am 18.08.2026 waren das 48 Takte, in denen die erste gestellte
 * Grenze 98000 W betrug - also gar keine Grenze.
 */
function eb_grenzen_wahren($erg, $anlage_max_w, $lade_max_w)
{
    $anlage_max = max(0.0, eb_zahl($anlage_max_w, 0.0));
    $lade_max = max(0.0, eb_zahl($lade_max_w, 0.0));
    if ($anlage_max > 0.0) { $erg['drossel_w'] = min($erg['drossel_w'], $anlage_max); }
    $erg['drossel_w'] = max(0.0, $erg['drossel_w']);
    $erg['lade_soll_w'] = ($lade_max > 0.0)
        ? max(0.0, min($lade_max, $erg['lade_soll_w']))
        : 0.0;
    return $erg;
}

/**
 * Der ganze Entschluss eines Durchlaufs.
 *
 * $mess:  netz, erzeugung (null = nicht gemessen), soc, lade_ist, alter_s
 * $cfg:   ziel_w, totband_w, rampe_ab_w, rampe_auf_w, soc_max, lade_max_w,
 *         drossel_min_w, notfall_s, notfall_w, speicher_zuerst, wirkung_s,
 *         anlage_max_w (0 = unbekannt)
 * $zust:  drossel_w, lade_soll_w, sp_probe_seit, sp_probe_lade, sp_sperre_bis
 *
 * Rueckgabe: drossel_w, lade_soll_w, tat, anlass, ueberschuss_w, notfall,
 *            erzeugung_ersatz, speicher_folgt, sp_probe_seit, sp_probe_lade,
 *            sp_sperre_bis
 */
function eb_regeln($mess, $cfg, $zust, $jetzt)
{
    $alter = eb_zahl(isset($mess['alter_s']) ? $mess['alter_s'] : -1, -1.0);
    $notfall_s = max(5.0, eb_zahl(isset($cfg['notfall_s']) ? $cfg['notfall_s'] : 60, 60.0));
    $lade_max = max(0.0, eb_zahl(isset($cfg['lade_max_w']) ? $cfg['lade_max_w'] : 0, 0.0));
    $anlage_max = max(0.0, eb_zahl(isset($cfg['anlage_max_w']) ? $cfg['anlage_max_w'] : 0, 0.0));
    $lade_alt = max(0.0, eb_zahl(isset($zust['lade_soll_w']) ? $zust['lade_soll_w'] : 0, 0.0));

    /* Die Erzeugung zu messen ist FREIWILLIG. Fehlt sie, tritt die zuletzt
     * gestellte Grenze an ihre Stelle - mehr als die kann die Anlage gerade
     * nicht liefern. Bis 0.9.4 wurde stattdessen 0 angenommen; damit ergab
     * "Erzeugung minus Ueberschuss" immer die Untergrenze, und die Regelung
     * fuhr sich dauerhaft zu tief fest. Gemessen am 18.08.2026: 600 W statt
     * moeglicher 1000 W, mit dem Anlass "es ist nichts mehr freizugeben"
     * und ohne dass irgendwo ein Fehler stand. */
    $erz_roh = isset($mess['erzeugung']) ? $mess['erzeugung'] : null;
    $erz_gemessen = ($erz_roh !== null && $erz_roh !== '');
    $erz_mess = $erz_gemessen ? max(0.0, eb_zahl($erz_roh, 0.0)) : 0.0;
    $drossel_alt = isset($zust['drossel_w'])
        ? eb_zahl($zust['drossel_w'], $erz_mess)
        : ($erz_gemessen ? $erz_mess : $anlage_max);
    $erzeugung = $erz_gemessen ? $erz_mess : max(0.0, $drossel_alt);

    $erg = array(
        'drossel_w' => $drossel_alt,
        'lade_soll_w' => $lade_alt,
        'tat' => EB_NICHTS,
        'anlass' => 'ruhe',
        'ueberschuss_w' => null,
        'notfall' => 0,
        'erzeugung_ersatz' => $erz_gemessen ? 0 : 1,
        'speicher_folgt' => -1,     // -1 = in diesem Durchlauf nicht geprueft
        'sp_probe_seit' => 0.0,
        'sp_probe_lade' => 0.0,
        'sp_sperre_bis' => eb_zahl(isset($zust['sp_sperre_bis']) ? $zust['sp_sperre_bis'] : 0, 0.0),
    );

    /* Der Ueberschuss wird ausgerechnet, sobald ein Zaehlerwert da ist -
     * auch ein alter. Bleibt er unbekannt, steht dort NULL und nicht 0:
     * eine 0 ist in einer Aufzeichnung nicht von "ausgeglichen" zu
     * unterscheiden, und genau so sah der Notbetrieb bis 0.9.4 aus. */
    if (isset($mess['netz']) && $mess['netz'] !== null && is_numeric($mess['netz'])) {
        $erg['ueberschuss_w'] = eb_ueberschuss($mess['netz'],
            isset($cfg['ziel_w']) ? $cfg['ziel_w'] : 0);
    }

    /* ---- Totmannschaltung ----
     * Kein frischer Messwert heisst NICHT "alles in Ordnung". Wer bei
     * ausgefallenem Zaehler weiterregelt, regelt auf eine Erinnerung. Die
     * Anlage geht auf den Notwert - bei Nulleinspeisung ist das die
     * Erzeugung auf den Hausverbrauch herunter, und den kennt hier niemand,
     * also auf den eingestellten sicheren Wert. */
    if ($alter < 0.0 || $alter > $notfall_s) {
        $notfall_w = max(0.0, eb_zahl(isset($cfg['notfall_w']) ? $cfg['notfall_w'] : 0, 0.0));
        $erg['drossel_w'] = min($drossel_alt, $notfall_w);
        /* Das Ladesoll wird NICHT angehoben. Bis 0.9.4 stand hier die volle
         * Ladeleistung; bei der Rueckkehr nahm der Freigabezweig sie zuerst
         * wieder zurueck und liess die Anlage sechs Takte lang auf dem
         * Notwert stehen - mit der Begruendung "der Speicher laedt aus dem
         * Netz", waehrend gar nichts lud. Ohne Zaehler weiss hier ohnehin
         * niemand, ob Laden gerade richtig waere. Gemessen am 18.08.2026. */
        $erg['lade_soll_w'] = $lade_alt;
        $erg['tat'] = EB_DROSSEL;
        $erg['anlass'] = ($alter < 0.0) ? 'kein_messwert' : 'messwert_alt';
        $erg['notfall'] = 1;
        return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
    }

    $netz = eb_zahl(isset($mess['netz']) ? $mess['netz'] : 0, 0.0);
    $ueber = eb_ueberschuss($netz, isset($cfg['ziel_w']) ? $cfg['ziel_w'] : 0);
    $erg['ueberschuss_w'] = $ueber;
    $totband = max(0.0, eb_zahl(isset($cfg['totband_w']) ? $cfg['totband_w'] : 50, 50.0));

    if (abs($ueber) <= $totband) {
        $erg['anlass'] = 'im_band';
        return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
    }

    $rab = isset($cfg['rampe_ab_w']) ? $cfg['rampe_ab_w'] : 2000;
    $rauf = isset($cfg['rampe_auf_w']) ? $cfg['rampe_auf_w'] : 300;

    if ($ueber > 0.0) {
        /* Zu viel geht hinaus. Erst den Speicher fragen - der kostet keinen
         * Ertrag. Abregeln ist immer der zweite Griff, nie der erste.
         * Gefragt wird aber nur, solange der Speicher nachweislich folgt. */
        $luft = 0.0;
        $folgt = 1;
        if (!empty($cfg['speicher_zuerst'])) {
            list($folgt, $ps, $pl, $sb) = eb_speicher_wirkt(
                isset($mess['lade_ist']) ? $mess['lade_ist'] : 0,
                $lade_alt, $zust, $jetzt,
                isset($cfg['wirkung_s']) ? $cfg['wirkung_s'] : 20);
            $erg['speicher_folgt'] = $folgt;
            $erg['sp_probe_seit'] = $ps;
            $erg['sp_probe_lade'] = $pl;
            $erg['sp_sperre_bis'] = $sb;
            if ($folgt) {
                $luft = eb_speicher_luft(
                    isset($mess['soc']) ? $mess['soc'] : -1,
                    isset($cfg['soc_max']) ? $cfg['soc_max'] : 95,
                    isset($mess['lade_ist']) ? $mess['lade_ist'] : 0,
                    $lade_max);
            }
        }
        if ($luft > 0.0) {
            /* Gutgeschrieben wird nur, was nach dem Deckel wirklich mehr
             * verlangt wird. Ohne den Deckel wuchs das Ladesoll je Takt
             * weiter: gemessen 12000 W an einem 3-kW-Speicher nach zwoelf
             * Durchlaeufen. */
            $neu_soll = min($lade_max, $lade_alt + min($luft, $ueber));
            $nimmt = max(0.0, $neu_soll - $lade_alt);
            if ($nimmt > 0.0) {
                $erg['lade_soll_w'] = $neu_soll;
                $erg['tat'] = EB_SPEICHER;
                $erg['anlass'] = 'in_speicher';
                $ueber -= $nimmt;
                if ($ueber <= $totband) {
                    return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
                }
            }
        }
        $min = max(0.0, eb_zahl(isset($cfg['drossel_min_w']) ? $cfg['drossel_min_w'] : 0, 0.0));
        $wunsch = max($min, $erzeugung - $ueber);
        $erg['drossel_w'] = eb_rampe($drossel_alt, $wunsch, $rab, $rauf);
        $erg['tat'] = EB_DROSSEL;
        if ($erg['anlass'] === 'in_speicher') {
            $erg['anlass'] = 'speicher_voll_drossel';
        } elseif (!empty($cfg['speicher_zuerst']) && !$folgt) {
            $erg['anlass'] = 'speicher_folgt_nicht';
        } else {
            $erg['anlass'] = 'drosseln';
        }
        return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
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
        if ($frei <= $totband) {
            return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
        }
    }
    if ($drossel_alt < $erzeugung + $frei) {
        $wunsch = min($drossel_alt + $frei, $erzeugung + $frei);
        $erg['drossel_w'] = eb_rampe($drossel_alt, $wunsch, $rab, $rauf);
        if ($erg['drossel_w'] > $drossel_alt) {
            $erg['tat'] = EB_FREIGABE;
            $erg['anlass'] = ($erg['anlass'] === 'weniger_laden')
                ? 'weniger_laden_freigabe' : 'freigabe';
            return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
        }
    }
    if ($erg['anlass'] === 'ruhe') { $erg['anlass'] = 'nichts_zu_holen'; }
    return eb_grenzen_wahren($erg, $anlage_max, $lade_max);
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
 * ANTEIL 0 HEISST "NICHTS", sobald ein anderes Geraet einen Anteil traegt.
 * Bis 0.9.4 fiel dem Geraet mit Anteil 0 der ganze Rest zu, sobald die
 * uebrigen an ihrer Spitze standen - gemessen 3400 von 5000 W an ein Geraet,
 * das ausdruecklich nichts bekommen sollte. Bleibt dadurch etwas liegen, ist
 * das die sichere Richtung: die Auflage wird eingehalten, und der
 * Trockenlauf weist die Abweichung aus.
 *
 * $steller ist die Liste aus eb_steller(): Schluessel = Platznummer, jeder
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
    // Wer 0 als Anteil traegt, waehrend andere einen haben, bekommt nichts.
    $offen = array();
    foreach ($out as $nr => $unbenutzt) {
        if ($anteil[$nr] > 0.0) { $offen[] = $nr; }
    }
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
    // anfallende Bruchteil geht an die Geraete mit Luft, in der Reihenfolge
    // ihres Anteils. Geraete mit Anteil 0 bleiben aussen vor.
    $verteilt = 0.0;
    foreach ($out as $nr => $w) { $out[$nr] = floor($w); $verteilt += $out[$nr]; }
    $rest = $gesamt - $verteilt;
    if ($rest >= 1.0) {
        $reihe = array();
        foreach ($out as $nr => $unbenutzt) {
            if ($anteil[$nr] > 0.0) { $reihe[] = $nr; }
        }
        usort($reihe, function ($a, $b) use ($anteil) {
            if ($anteil[$a] === $anteil[$b]) { return ($a < $b) ? -1 : 1; }
            return ($anteil[$a] > $anteil[$b]) ? -1 : 1;
        });
        foreach ($reihe as $nr) {
            if ($rest < 1.0) { break; }
            $luft = $deckel[$nr] - $out[$nr];
            if ($luft <= 0) { continue; }
            $gib = floor(min($rest, $luft));
            $out[$nr] = $out[$nr] + $gib;
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
 * ALLE DREI LEISTUNGSWERTE SIND EINSPEISUNGEN. $vorher_w und $nachher_w
 * sind gemessene Einspeisungen (positiv = es fliesst hinaus), $ziel_w ist
 * die erlaubte. Bis 0.9.4 stand an der dritten Stelle die
 * ERZEUGUNGSGRENZE - zwei verschiedene Groessen. Bei Nulleinspeisung ist
 * die Grenze fast immer groesser als die Einspeisung, der erwartete
 * Rueckgang wurde negativ, und die Schwelle fiel auf die Untergrenze von
 * 100 W: jeder Wolkenzug galt als "hat gewirkt". Gemessen am 18.08.2026.
 *
 * Rueckgabe: 1 gewirkt, 0 noch offen, -1 keine Wirkung feststellbar.
 */
function eb_wirkung($vorher_w, $nachher_w, $ziel_w, $wartezeit_s, $vergangen_s, $mindest_w = 100.0)
{
    if ($vergangen_s < max(1.0, eb_zahl($wartezeit_s, 15.0))) { return 0; }
    $vorher = eb_zahl($vorher_w, 0.0);
    $soll_weniger = max(eb_zahl($mindest_w, 100.0),
                        0.3 * max(0.0, $vorher - max(0.0, eb_zahl($ziel_w, 0.0))));
    $ist_weniger = $vorher - eb_zahl($nachher_w, 0.0);
    return ($ist_weniger >= $soll_weniger) ? 1 : -1;
}

/* ==================================================================
 * SunSpec - die Rechnung zum Modbus-Schreibweg
 *
 * Nur Rechnung. Das Abschreiten der Modellkette und das Schreiben selbst
 * stehen in eb_dienst.php, weil sie ans Netz gehen.
 * ================================================================== */

/**
 * Die Adresse eines SunSpec-Stellglieds zerlegen. Rueckgabe: array|null.
 *
 * Form:     IP[:Port]/Geraeteadresse[/Basis[/Rueckfall_s]]
 * Beispiel: 192.168.178.31:502/1/40000/120
 *
 * BASIS ist das Register, in dem die Marke "SunS" steht - beim Fronius
 * Datamanager 40000, am 19.08.2026 an einem Symo Hybrid gemessen. Sie
 * steht trotzdem in der Adresse und nicht fest im Programm: ein anderer
 * Hersteller legt sie anderswohin, und geraten wird hier nichts. Wer die
 * Basis weglaesst, bekommt 40000; wer einen Rueckfall angeben will, muss
 * die Basis mitschreiben, sonst wandert seine Zahl in das falsche Feld.
 *
 * RUECKFALL_S ist der Zeitablauf, den der WECHSELRICHTER selbst fuehrt
 * (WMaxLimPct_RvrtTms). Nach seinem Ablauf beendet das Geraet die
 * Drosselung von allein. 0 heisst: kein Rueckfall - die Drosselung bleibt
 * dann stehen, bis jemand sie zuruecknimmt, auch wenn der LoxBerry stirbt.
 * Das ist eine Entscheidung und keine Kleinigkeit, deshalb steht sie
 * sichtbar in der Adresse statt versteckt in einer Vorgabe.
 */
function eb_sunspec_zerlegen($adresse)
{
    $muster = '#^([0-9A-Za-z\.\-]+)(?::([0-9]{1,5}))?/([0-9]{1,3})'
            . '(?:/([0-9]{1,5}))?(?:/([0-9]{1,5}))?$#';
    if (!preg_match($muster, trim((string) $adresse), $t)) { return null; }
    $port = (!isset($t[2]) || $t[2] === '') ? 502 : (int) $t[2];
    if ($port < 1 || $port > 65535) { return null; }
    $id = (int) $t[3];
    if ($id < 0 || $id > 247) { return null; }
    $basis = (!isset($t[4]) || $t[4] === '') ? 40000 : (int) $t[4];
    if ($basis < 0 || $basis > 65533) { return null; }
    $rueck = (!isset($t[5]) || $t[5] === '') ? 0 : (int) $t[5];
    /* 28800 s ist die Obergrenze des Herstellers. Wer mehr eintraegt,
     * bekommt keinen stillen Deckel, sondern eine abgewiesene Adresse. */
    if ($rueck < 0 || $rueck > 28800) { return null; }
    return array('host' => $t[1], 'port' => $port, 'id' => $id,
                 'basis' => $basis, 'rueckfall_s' => $rueck);
}

/**
 * Aus einer Wattgrenze den Rohwert fuer WMaxLimPct machen.
 *
 * Rueckgabe: array(rohwert|null, anlass).
 *
 * DER SKALIERUNGSFAKTOR WIRD NICHT ANGENOMMEN. Er wird am Geraet gelesen
 * und hier hereingereicht. Am 19.08.2026 lieferte ein Symo Hybrid -2,
 * also Prozent mal hundert: 100 % sind der Rohwert 10000. Das Beispiel im
 * Fronius-Handbuch ("z. B. 30 fuer 30 %") widerspricht der Tabelle
 * daneben und ist falsch. Wer ihm glaubt, drosselt auf 0,30 Prozent und
 * schaltet die Anlage praktisch ab. Genau darum steht hier kein fester
 * Faktor, sondern einer, der vom Wechselrichter kommt.
 *
 * SPITZE_W ist die Nennleistung des Wechselrichters. WMaxLimPct begrenzt
 * die AUSGANGSLEISTUNG in Prozent davon - nicht die Einspeisung. Bei
 * Eigenverbrauch und Speicher ist das nicht dasselbe; ob die Drosselung
 * am Zaehler ankommt, entscheidet weiterhin eb_wirkung().
 */
function eb_sunspec_roh($watt, $spitze_w, $sf)
{
    $spitze = eb_zahl($spitze_w, 0.0);
    if ($spitze <= 0.0) { return array(null, 'ohne_spitzenleistung'); }
    $sf = (int) $sf;
    /* Ein Faktor ausserhalb dieses Bereichs ist kein Faktor, sondern ein
     * falsch gelesenes Register - etwa weil die Kette um eines verrutscht
     * ist. Dann wird nicht gestellt. Ein Versatz ist bei diesem Geraet die
     * teuerste Fehlerklasse ueberhaupt. */
    if ($sf < -4 || $sf > 0) { return array(null, 'faktor_unglaubhaft'); }
    $prozent = max(0.0, min(100.0, eb_zahl($watt, 0.0) / $spitze * 100.0));
    $roh = (int) round($prozent * pow(10, -$sf));
    if ($roh < 0 || $roh > 65535) { return array(null, 'rohwert_ausserhalb'); }
    return array($roh, 'gut');
}

/**
 * Wie oft ein SunSpec-Stellglied aufgefrischt werden muss (Sekunden).
 *
 * Der uebrige Stellweg spricht nur bei AENDERUNG. Ein Wechselrichter mit
 * Zeitablauf faellt aber von allein zurueck, wenn nichts mehr kommt -
 * genau das ist gewollt, wenn die Bremse stirbt, und genau deshalb muss
 * sie, solange sie lebt, VOR Ablauf erneut sprechen. Ohne dieses
 * Auffrischen loeste sich jede laenger stehende Drosselung still auf.
 *
 * Die Haelfte des Zeitablaufs laesst einen ausgefallenen Durchlauf zu,
 * ohne dass die Drosselung springt. Oefter als im Takt laeuft nichts,
 * also ist der Takt die Untergrenze. Rueckfall 0 heisst kein Zeitablauf,
 * also auch nichts aufzufrischen.
 */
function eb_sunspec_auffrischen_s($rueckfall_s, $takt_s)
{
    $r = max(0, (int) $rueckfall_s);
    if ($r === 0) { return 0; }
    return max(max(1, (int) $takt_s), (int) floor($r / 2));
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

    // ---- Folgt der Speicher? ----
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(0, 0, array(), 1000, 20);
    $pruef('ohne Ladesoll gibt es nichts zu pruefen', $fo, 1);
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(0, 2000, array(), 1000, 20);
    $pruef('erste Runde: Weg offen, Probe laeuft an', $fo, 1);
    $pruef('erste Runde: Zeitpunkt gemerkt', $ps, 1000);
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(0, 2000,
        array('sp_probe_seit' => 1000, 'sp_probe_lade' => 0), 1010, 20);
    $pruef('Wartezeit laeuft noch: Weg bleibt offen', $fo, 1);
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(1800, 2000,
        array('sp_probe_seit' => 1000, 'sp_probe_lade' => 0), 1030, 20);
    $pruef('Speicher folgt: Weg bleibt offen', $fo, 1);
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(0, 2000,
        array('sp_probe_seit' => 1000, 'sp_probe_lade' => 0), 1030, 20);
    $pruef('Speicher folgt nicht: Weg gesperrt', $fo, 0);
    $pruef('und zwar bis zehn Wartezeiten spaeter', $sb, 1230);
    list($fo, $ps, $pl, $sb) = eb_speicher_wirkt(0, 2000,
        array('sp_sperre_bis' => 1230), 1100, 20);
    $pruef('waehrend der Sperre bleibt er gesperrt', $fo, 0);

    $cfg = array('ziel_w' => 0, 'totband_w' => 50, 'rampe_ab_w' => 2000, 'rampe_auf_w' => 300,
                 'soc_max' => 95, 'lade_max_w' => 3000, 'drossel_min_w' => 0,
                 'notfall_s' => 60, 'notfall_w' => 0, 'speicher_zuerst' => 1,
                 'wirkung_s' => 20, 'anlage_max_w' => 0);

    // ---- Totmannschaltung ----
    $r = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'alter_s' => 120),
                   $cfg, array('drossel_w' => 6000), 1000);
    $pruef('alter Messwert: Notfall', $r['notfall'], 1);
    $pruef('alter Messwert: Grenze auf Notwert', $r['drossel_w'], 0);
    $pruef('alter Messwert: Anlass', $r['anlass'], 'messwert_alt');
    // Der Ueberschuss wird auch im Notbetrieb ausgewiesen, wenn ein Wert da ist.
    $pruef('Notbetrieb weist den Ueberschuss aus', $r['ueberschuss_w'], 5000);
    // Das Ladesoll wird im Notfall NICHT angehoben (blockierte die Rueckkehr).
    $r2 = eb_regeln(array('netz' => -5000, 'erzeugung' => 6000, 'alter_s' => 120),
                    $cfg, array('drossel_w' => 6000, 'lade_soll_w' => 0), 1000);
    $pruef('Notfall hebt das Ladesoll nicht an', $r2['lade_soll_w'], 0);
    $r = eb_regeln(array('netz' => null, 'erzeugung' => 6000, 'alter_s' => -1),
                   $cfg, array('drossel_w' => 6000), 1000);
    $pruef('nie ein Messwert: Notfall', $r['anlass'], 'kein_messwert');
    // Ohne Zaehlerwert ist der Ueberschuss UNBEKANNT, nicht null.
    $pruef('ohne Zaehlerwert bleibt der Ueberschuss unbekannt',
           $r['ueberschuss_w'] === null ? 'null' : 'zahl', 'null');
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

    /* Der Deckel: das Ladesoll darf die eingetragene Hoechstleistung nicht
     * ueberschreiten, auch nicht ueber viele Durchlaeufe. Bis 0.9.4 wuchs es
     * je Takt weiter - gemessen 12000 W an einem 3-kW-Speicher. */
    $zust = array('drossel_w' => 9000, 'lade_soll_w' => 0);
    for ($i = 0; $i < 12; $i++) {
        $r = eb_regeln(array('netz' => -9000, 'erzeugung' => 9000, 'soc' => 40,
                             'lade_ist' => 0, 'alter_s' => 3), $cfg, $zust, 1000);
        $zust['lade_soll_w'] = $r['lade_soll_w'];
        $zust['sp_probe_seit'] = $r['sp_probe_seit'];
        $zust['sp_probe_lade'] = $r['sp_probe_lade'];
    }
    $pruef('Ladesoll laeuft nicht davon', $zust['lade_soll_w'], 3000);

    /* Und der Grund, warum der Deckel INNEN sitzt und nicht nur am Ausgang:
     * gutgeschrieben werden darf nur, was der Speicher wirklich mehr nimmt.
     * Ohne den inneren Deckel gilt der ganze Ueberschuss als untergebracht,
     * und die uebrigen 1500 W gehen weiter ins Netz - waehrend die Anzeige
     * ein sauber gedeckeltes Ladesoll zeigt. */
    $r = eb_regeln(array('netz' => -2000, 'erzeugung' => 5000, 'soc' => 40,
                         'lade_ist' => 0, 'alter_s' => 3),
                   $cfg, array('drossel_w' => 5000, 'lade_soll_w' => 2500), 1000);
    $pruef('am Deckel wird der Rest abgeregelt', $r['tat'], EB_DROSSEL);
    $pruef('am Deckel bleibt das Ladesoll stehen', $r['lade_soll_w'], 3000);

    /* Der Speicher folgt nicht: nach der Wartezeit wird abgeregelt, statt
     * weiter auf ihn zu hoffen. Das ist der Fall, in dem die Anlage bis
     * 0.9.4 dauerhaft weiterspeiste. */
    /* Der Fall ist so gewaehlt, dass ein FOLGSAMER Speicher den ganzen
     * Ueberschuss aufnaehme. Nur dann sagt die Pruefzeile etwas ueber die
     * Probe aus; sonst waere ohnehin abgeregelt worden. */
    $sp_zust = array('drossel_w' => 5000, 'lade_soll_w' => 1000,
                     'sp_probe_seit' => 1000, 'sp_probe_lade' => 0);
    $sp_mess = array('netz' => -1500, 'erzeugung' => 5000, 'soc' => 40,
                     'lade_ist' => 0, 'alter_s' => 3);
    $r = eb_regeln($sp_mess, $cfg, $sp_zust, 1030);   // Wartezeit abgelaufen
    $pruef('Speicher folgt nicht: es wird gedrosselt', $r['tat'], EB_DROSSEL);
    $pruef('Speicher folgt nicht: Anlass', $r['anlass'], 'speicher_folgt_nicht');
    $pruef('Speicher folgt nicht: Grenze faellt', $r['drossel_w'], 3500);
    $r = eb_regeln($sp_mess, $cfg, $sp_zust, 1010);   // Wartezeit laeuft noch
    $pruef('Wartezeit laeuft: der Speicher bekommt den Ueberschuss', $r['tat'], EB_SPEICHER);
    $pruef('Wartezeit laeuft: nicht gedrosselt', $r['drossel_w'], 5000);

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

    // ---- Ohne Erzeugungsmessung ----
    /* Fehlt die Erzeugungsquelle, tritt die zuletzt gestellte Grenze an ihre
     * Stelle. Bis 0.9.4 wurde 0 angenommen; die Regelung fuhr sich dann bei
     * einem Wert weit unter dem Moeglichen fest. Haus zieht 400 W mehr, als
     * die Anlage bei ihrer Grenze von 600 W liefert -> es MUSS freigegeben
     * werden. */
    $ohne = array_merge($cfg, array('speicher_zuerst' => 0));
    $r = eb_regeln(array('netz' => 400, 'erzeugung' => null, 'soc' => 100, 'alter_s' => 3),
                   $ohne, array('drossel_w' => 600, 'lade_soll_w' => 0), 1000);
    $pruef('ohne Erzeugungsmessung: als Ersatz gekennzeichnet', $r['erzeugung_ersatz'], 1);
    $pruef('ohne Erzeugungsmessung: es wird freigegeben', $r['tat'], EB_FREIGABE);
    $pruef('ohne Erzeugungsmessung: Grenze steigt', $r['drossel_w'], 900);
    $r = eb_regeln(array('netz' => 400, 'erzeugung' => 4000, 'soc' => 100, 'alter_s' => 3),
                   $ohne, array('drossel_w' => 600, 'lade_soll_w' => 0), 1000);
    $pruef('mit Erzeugungsmessung: nicht als Ersatz gekennzeichnet', $r['erzeugung_ersatz'], 0);

    // ---- Anlagenobergrenze ----
    /* Nach dem Ausschalten steht der Freigabewert im Zustand. Ohne Deckel
     * rampte die Regelung von 100000 W herunter und stellte dabei 98000 W. */
    $r = eb_regeln(array('netz' => -500, 'erzeugung' => 4000, 'soc' => 100, 'alter_s' => 3),
                   array_merge($ohne, array('anlage_max_w' => 8000)),
                   array('drossel_w' => 100000, 'lade_soll_w' => 0), 1000);
    $pruef('Anlagenobergrenze deckelt die Grenze', $r['drossel_w'], 8000);
    $r = eb_regeln(array('netz' => -500, 'erzeugung' => 4000, 'soc' => 100, 'alter_s' => 3),
                   $ohne, array('drossel_w' => 100000, 'lade_soll_w' => 0), 1000);
    $pruef('ohne eingetragene Spitzen kein Deckel', $r['drossel_w'], 98000);

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

    /* Anteil 0 heisst nichts, sobald ein anderes Geraet einen Anteil hat -
     * auch dann, wenn dieses an seiner Spitze steht. Bis 0.9.4 fielen dem
     * Geraet mit Anteil 0 hier 3400 W zu. */
    $a = eb_aufteilen(5000, array(1 => $st(100, 1600), 2 => $st(0, 0)));
    $pruef('Anteil 0 bekommt nichts', $a[2], 0);
    $pruef('das andere bleibt auf seiner Spitze', $a[1], 1600);
    $pruef('der Rest bleibt liegen statt falsch zu landen', $summe($a), 1600);

    // Drei Geraete, krumme Zahl: es darf kein Watt verschwinden.
    $a = eb_aufteilen(1000, array(1 => $st(1, 0), 2 => $st(1, 0), 3 => $st(1, 0)));
    $pruef('krumme Teilung verliert nichts', $summe($a), 1000);

    $pruef('Grenze 0 verteilt 0', $summe(eb_aufteilen(0, array(1 => $st(50, 0), 2 => $st(50, 0)))), 0);
    $pruef('ohne Stellglieder leere Liste', count(eb_aufteilen(5000, array())), 0);

    // Luecken in den Platznummern sind erlaubt und aendern nichts.
    $a = eb_aufteilen(5000, array(2 => $st(60, 0), 4 => $st(40, 0)));
    $pruef('Luecke in den Platznummern: Platz 2', $a[2], 3000);
    $pruef('Luecke in den Platznummern: Platz 4', $a[4], 2000);

    // ---- Wirkung ----
    /* Alle drei Werte sind Einspeisungen. 6000 W hinaus bei 0 erlaubt heisst:
     * es muessen mindestens 1800 W verschwinden. */
    $pruef('Wartezeit laeuft noch', eb_wirkung(6000, 6000, 0, 15, 5), 0);
    $pruef('Wirkung eingetreten', eb_wirkung(6000, 100, 0, 15, 20), 1);
    $pruef('keine Wirkung trotz Quittung', eb_wirkung(6000, 5950, 0, 15, 20), -1);
    $pruef('kleine Einspeisung, kleine Wirkung genuegt', eb_wirkung(300, 150, 0, 15, 20), 1);
    /* Der Fall, an dem die alte Fassung scheiterte: dort stand an dritter
     * Stelle die Erzeugungsgrenze. 3000 W hinaus gegen 4000 W Grenze ergaben
     * eine Schwelle von 100 W, und ein Rueckgang um 120 W galt als Erfolg.
     * Gegen die erlaubte Einspeisung sind 900 W noetig. */
    $pruef('Schwelle haengt an der erlaubten Einspeisung', eb_wirkung(3000, 2880, 0, 15, 20), -1);
    // Bei einer 70-Prozent-Regelung ist das Ziel nicht null.
    $pruef('70-Prozent: Rueckgang auf das Erlaubte genuegt', eb_wirkung(6000, 4200, 4200, 15, 20), 1);

    // ---- SunSpec: Adresse zerlegen ----
    $a = eb_sunspec_zerlegen('192.168.178.31:502/1/40000/120');
    $pruef('SunSpec Adresse vollstaendig: Host', $a['host'], '192.168.178.31');
    $pruef('SunSpec Adresse vollstaendig: Port', $a['port'], 502);
    $pruef('SunSpec Adresse vollstaendig: Basis', $a['basis'], 40000);
    $pruef('SunSpec Adresse vollstaendig: Rueckfall', $a['rueckfall_s'], 120);
    $a = eb_sunspec_zerlegen('192.168.178.31/1');
    $pruef('SunSpec ohne Port: 502', $a['port'], 502);
    $pruef('SunSpec ohne Basis: 40000', $a['basis'], 40000);
    $pruef('SunSpec ohne Rueckfall: 0', $a['rueckfall_s'], 0);
    $pruef('SunSpec Rueckfall ueber 28800 abgewiesen',
           eb_sunspec_zerlegen('192.0.2.1/1/40000/28801'), null);
    $pruef('SunSpec Geraeteadresse ueber 247 abgewiesen',
           eb_sunspec_zerlegen('192.0.2.1/248'), null);
    $pruef('SunSpec Leerstring abgewiesen', eb_sunspec_zerlegen(''), null);
    $pruef('SunSpec: das Lesemuster ist KEINE Stelladresse',
           eb_sunspec_zerlegen('192.0.2.1:502/1/52/float32/4'), null);

    // ---- SunSpec: Wattgrenze in den Rohwert ----
    /* Der am 19.08.2026 gemessene Faktor ist -2, also Prozent mal hundert. */
    list($roh, $anl) = eb_sunspec_roh(5000, 10000, -2);
    $pruef('50 Prozent bei Faktor -2 sind 5000', $roh, 5000);
    list($roh, $anl) = eb_sunspec_roh(10000, 10000, -2);
    $pruef('volle Leistung ist 10000', $roh, 10000);
    list($roh, $anl) = eb_sunspec_roh(0, 10000, -2);
    $pruef('null Watt sind null', $roh, 0);
    /* Der Fall, der die Anlage abschalten wuerde, wenn man dem Beispiel im
     * Handbuch glaubte: 30 Prozent sind 3000, nicht 30. */
    list($roh, $anl) = eb_sunspec_roh(3000, 10000, -2);
    $pruef('30 Prozent sind 3000 und nicht 30', $roh, 3000);
    list($roh, $anl) = eb_sunspec_roh(3000, 10000, 0);
    $pruef('bei Faktor 0 waeren 30 Prozent die 30', $roh, 30);
    list($roh, $anl) = eb_sunspec_roh(20000, 10000, -2);
    $pruef('mehr als die Nennleistung wird auf 100 Prozent gedeckelt', $roh, 10000);
    list($roh, $anl) = eb_sunspec_roh(-500, 10000, -2);
    $pruef('negative Grenze wird auf null gedeckelt', $roh, 0);
    list($roh, $anl) = eb_sunspec_roh(5000, 0, -2);
    $pruef('ohne Spitzenleistung wird nicht gestellt', $anl, 'ohne_spitzenleistung');
    list($roh, $anl) = eb_sunspec_roh(5000, 10000, -9);
    $pruef('unglaubhafter Faktor wird nicht gestellt', $anl, 'faktor_unglaubhaft');
    list($roh, $anl) = eb_sunspec_roh(5000, 10000, -4);
    $pruef('Faktor -4 ergaebe 500000 und wird abgewiesen', $anl, 'rohwert_ausserhalb');

    // ---- SunSpec: wie oft aufgefrischt wird ----
    $pruef('Rueckfall 120 bei Takt 5: alle 60 s', eb_sunspec_auffrischen_s(120, 5), 60);
    $pruef('kein Rueckfall: nichts aufzufrischen', eb_sunspec_auffrischen_s(0, 5), 0);
    $pruef('Rueckfall kuerzer als der Takt: hoechstens im Takt',
           eb_sunspec_auffrischen_s(4, 5), 5);

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
