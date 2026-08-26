<?php
/**
 * Einspeisebremse - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Gerechnet wird in eb_regel.php,
 * gemessen und gestellt in bin/eb_dienst.php.
 *
 * Praefix 'eb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im UNANGEMELDETEN Bereich. Der Weg dorthin sieht im
 * Archiv anders aus als installiert - deshalb eine Kandidatenliste und
 * keine Rechnung. Genau daran ist das Intercom-Plugin mit HTTP 500
 * gescheitert. */
$eb_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/eb_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/eb_lib.php',
    dirname(__DIR__) . '/html/eb_lib.php',
) as $eb_kandidat) {
    if (is_file($eb_kandidat)) { require_once $eb_kandidat; $eb_gefunden = true; break; }
}
if (!$eb_gefunden) {
    echo '<p><b>Fehler:</b> eb_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/eb_test.php';

$eb_p = eb_paths();
if ($eb_p['home'] !== '' && is_file($eb_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $eb_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $eb_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Die Positivliste der Reiter - AUSGESCHRIEBEN und nicht erzeugt.
 *
 * Eine erzeugte Liste findet kein Pruefwerkzeug im Quelltext; es meldet
 * die Reiter dann als fehlend, und man sucht an der falschen Stelle.
 * Ausschreiben allein genuegt aber nicht: dass diese Liste, die Leiste
 * weiter unten und die Bereiche zusammenpassen, misst der Reiter Test
 * nach. Wer hier einen Reiter ergaenzt, ergaenzt drei Stellen. */
$eb_reiter_liste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$eb_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $eb_reiter_liste, true)) {
    $eb_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $eb_reiter_liste, true)) {
    $eb_tab = 'tab-' . (string) $_GET['form'];
}

$eb_meldungen = array();
$eb_fehler = array();
$eb_testausgabe = '';
$eb_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Vorlage herunterladen ---------------- */
if ($eb_post && isset($_POST['vorlage'])) {
    list($eb_name, $eb_inhalt) = eb_vorlage();
    if ($eb_inhalt === '') {
        $eb_fehler[] = eb_t('LOX.FEHLER_VORLAGE');
        $eb_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        // Anfuehrungszeichen um den Dateinamen: ohne sie bricht jeder Name
        // mit einem Leerzeichen darin.
        header('Content-Disposition: attachment; filename="' . $eb_name . '"');
        echo $eb_inhalt;
        exit;
    }
}

/* ---------------- Regelung ein- oder ausschalten ---------------- */
if ($eb_post && isset($_POST['schalten'])) {
    $eb_cfg = eb_config();
    $eb_neu = ((string) $_POST['schalten'] === '1') ? 1 : 0;
    $eb_mangel = eb_maengel($eb_cfg);
    if ($eb_neu === 1 && $eb_mangel) {
        /* Nicht einschalten, solange etwas fehlt. Eine Regelung, die ohne
         * Zaehler oder ohne Stellglied "laeuft", meldet Betrieb und tut
         * nichts - das ist schlimmer als eine, die gar nicht erst angeht. */
        foreach ($eb_mangel as $eb_k) { $eb_fehler[] = eb_t($eb_k); }
        $eb_fehler[] = eb_t('FEHLER.NICHT_EINGESCHALTET');
    } else {
        $eb_cfg['ein'] = $eb_neu;
        if (eb_config_speichern($eb_cfg)) {
            $eb_meldungen[] = eb_t($eb_neu ? 'ALLG.EINGESCHALTET' : 'ALLG.AUSGESCHALTET');
            eb_log('Regelung ' . ($eb_neu ? 'eingeschaltet' : 'ausgeschaltet') . ' (Oberflaeche).');
        } else {
            $eb_fehler[] = eb_t('FEHLER.SPEICHERN');
        }
    }
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($eb_post && isset($_POST['dienst'])) {
    $eb_befehl = (string) $_POST['dienst'];
    // Positivliste, kein Durchreichen: was nicht dasteht, wird abgewiesen.
    if (!in_array($eb_befehl, array('start', 'stop', 'restart'), true)) {
        $eb_fehler[] = eb_t('TEST.M_UNBEKANNT');
    } else {
        $eb_skript = eb_dienst_skript();
        if ($eb_skript === '') {
            $eb_fehler[] = eb_t('DIENST.KEIN_SKRIPT');
        } else {
            $eb_aus = array();
            @exec(escapeshellarg($eb_skript) . ' ' . escapeshellarg($eb_befehl) . ' 2>&1',
                  $eb_aus, $eb_rc);
            /* Die WIRKUNG melden, nicht den Rueckgabewert: dienst.sh sagt
             * selbst, ob der Dienst hinterher laeuft. */
            if ($eb_rc === 0 || $eb_befehl === 'stop') {
                $eb_meldungen[] = eb_t($eb_befehl === 'start' ? 'DIENST.GESTARTET'
                    : ($eb_befehl === 'stop' ? 'DIENST.ANGEHALTEN' : 'DIENST.NEUGESTARTET'));
            } else {
                $eb_fehler[] = sprintf(eb_t('DIENST.FEHLER'),
                                       substr(implode(' ', $eb_aus), 0, 200));
            }
            eb_log('Dienst ' . $eb_befehl . ' ueber die Oberflaeche.');
        }
    }
    $eb_tab = 'tab-settings';
}

/* ---------------- Vorlage fuer die Messquellen anwenden ---------------- */
if ($eb_post && isset($_POST['vorlage_quellen'])) {
    $eb_vn = (string) $_POST['vorlage_quellen'];
    $eb_alle = eb_quellvorlagen();
    // Positivliste: was nicht dasteht, wird abgewiesen, nicht geraten.
    if (!isset($eb_alle[$eb_vn])) {
        $eb_fehler[] = eb_t('VORLAGE.UNBEKANNT');
    } else {
        $eb_v = $eb_alle[$eb_vn];
        /* Wohin die Vorlage schreibt. "ersatz" nimmt AUSSCHLIESSLICH den
         * Netzzaehler der Vorlage und legt ihn auf den Ersatzzaehler - alles
         * andere bleibt unberuehrt. Ohne diese Wahl liesse sich der
         * Ersatzzaehler ueberhaupt nicht aus einer Vorlage fuellen. */
        $eb_ziel = isset($_POST['vq_ziel']) ? (string) $_POST['vq_ziel'] : 'vorlage';
        if (!in_array($eb_ziel, array('vorlage', 'ersatz'), true)) { $eb_ziel = 'vorlage'; }
        if ($eb_ziel === 'ersatz') {
            if (!isset($eb_v['quellen']['q_netz'])) {
                $eb_fehler[] = eb_t('VORLAGE.OHNE_NETZ');
                $eb_v['quellen'] = array();
                $eb_ziel = 'nichts';
            } else {
                $eb_v['quellen'] = array('q_netz2' => $eb_v['quellen']['q_netz']);
            }
        }
        $eb_wert_v = isset($_POST['vq_wert'])
            ? trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['vq_wert'])) : '';
        if ($eb_v['quellen'] && $eb_wert_v === '') {
            $eb_fehler[] = eb_t('VORLAGE.OHNE_ANGABE');
        } else {
            $eb_cfg = eb_config();
            foreach ($eb_v['quellen'] as $eb_k => $eb_q) {
                $eb_neu_q = eb_quelle_vorgabe();
                $eb_neu_q['art'] = $eb_q['art'];
                $eb_neu_q['adresse'] = sprintf($eb_q['adresse'], $eb_wert_v);
                $eb_neu_q['pfad'] = $eb_q['pfad'];
                $eb_neu_q['faktor'] = $eb_q['faktor'];
                $eb_neu_q['invertieren'] = $eb_q['invertieren'];
                $eb_cfg[$eb_k] = $eb_neu_q;
            }
            if ($eb_ziel === 'nichts') {
                // Schon beanstandet - hier wird bewusst nichts geschrieben.
            } elseif (eb_config_speichern($eb_cfg)) {
                $eb_meldungen[] = eb_t($eb_ziel === 'ersatz'
                    ? 'VORLAGE.ANGEWENDET_ERSATZ' : 'VORLAGE.ANGEWENDET');
                $eb_meldungen[] = eb_t($eb_v['hinweis']);
                eb_log('Quellen-Vorlage ' . $eb_vn . ' angewendet.');
            } else {
                $eb_fehler[] = eb_t('FEHLER.SPEICHERN');
            }
        }
    }
    $eb_tab = 'tab-settings';
}

/* ---------------- Speichern ---------------- */
if ($eb_post && isset($_POST['speichern'])) {
    $eb_cfg = eb_config();

    /* Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein hartes
     * preg_replace auf eine Positivliste zerstoert eingefuegte Werte
     * (belegt am ACTi-Plugin am 26.07.2026). */
    $eb_sauber = function ($s) {
        return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $s));
    };
    $eb_wert = function ($name, $vorgabe = '') use ($eb_sauber) {
        return isset($_POST[$name]) ? $eb_sauber($_POST[$name]) : $vorgabe;
    };
    $eb_reihe = function ($name, $i) use ($eb_sauber) {
        $a = isset($_POST[$name]) ? (array) $_POST[$name] : array();
        return isset($a[$i]) ? $eb_sauber($a[$i]) : '';
    };
    /* Eine Zahl pruefen statt sie stillschweigend zurechtzubiegen. */
    $eb_zahl_pruef = function ($roh, $von, $bis, $bez) use (&$eb_fehler) {
        $roh = str_replace(',', '.', trim((string) $roh));
        if ($roh === '') { return null; }
        if (!is_numeric($roh)) {
            $eb_fehler[] = sprintf(eb_t('FEHLER.KEINE_ZAHL'), $bez, $roh);
            return null;
        }
        $w = (int) round((float) $roh);
        if ($w < $von || $w > $bis) {
            $eb_fehler[] = sprintf(eb_t('FEHLER.AUSSERHALB'), $bez, $roh, $von, $bis);
            return null;
        }
        return $w;
    };

    /* ---- Welches Formular hat gesendet? ----
     * Es gibt zwei mit demselben Knopfnamen: das grosse im Reiter
     * Einstellungen und das kleine im Reiter MQTT. Bis 0.9.3 liefen beide
     * durch denselben Zweig, der ALLE Felder aus $_POST las. Ein Druck auf
     * Speichern im Reiter MQTT loeschte damit Netzzaehler, Messquellen und
     * saemtliche Stellglieder - und die Seite meldete Erfolg. Gemessen an
     * einer gefuellten Anlage am 18.08.2026.
     *
     * Fehlt die Angabe oder ist sie unbekannt, wird ABGEWIESEN und nicht
     * geraten: ein falsch geratenes Formular loescht genau das, was der
     * Benutzer behalten wollte. */
    $eb_formular = isset($_POST['formular']) ? (string) $_POST['formular'] : '';
    if (!in_array($eb_formular, array('einstellungen', 'mqtt'), true)) {
        $eb_fehler[] = eb_t('FEHLER.FORMULAR');
    }

    if ($eb_formular === 'einstellungen') {
        /* ---- Messquellen ---- */
        $eb_qarten = eb_quellarten();
        foreach (eb_quellenfelder() as $eb_k => $eb_f) {
            $eb_bez = $eb_f['bez'];
            $q = eb_quelle_vorgabe();
            $q['art'] = $eb_wert($eb_k . '_art', 'aus');
            $q['adresse'] = $eb_wert($eb_k . '_adresse');
            $q['pfad'] = $eb_wert($eb_k . '_pfad');
            $q['invertieren'] = !empty($_POST[$eb_k . '_inv']) ? 1 : 0;
            $f = str_replace(',', '.', $eb_wert($eb_k . '_faktor', '1'));
            if ($f === '' || !is_numeric($f)) {
                if ($f !== '') { $eb_fehler[] = sprintf(eb_t('FEHLER.KEINE_ZAHL'),
                    eb_t($eb_bez) . ' / ' . eb_t('QUELLE.L_FAKTOR'), $f); }
                $f = '1';
            }
            $q['faktor'] = (float) $f;
            if (!isset($eb_qarten[$q['art']])) { $q['art'] = 'aus'; }
            if ($q['art'] !== 'aus' && $q['adresse'] === '') {
                $eb_fehler[] = sprintf(eb_t('FEHLER.QUELLE_OHNE_ADRESSE'), eb_t($eb_bez));
            }
            $eb_cfg[$eb_k] = $q;
        }

        /* ---- Stellglieder ---- */
        $eb_sarten = eb_stellarten();
        $eb_einh = eb_einheiten();
        $eb_neu_st = array();
        for ($eb_i = 0; $eb_i < EB_STELLER; $eb_i++) {
            $s = eb_steller_vorgabe();
            $s['name'] = $eb_reihe('s_name', $eb_i);
            $s['art'] = $eb_reihe('s_art', $eb_i);
            $s['adresse'] = $eb_reihe('s_adresse', $eb_i);
            $s['inhalt'] = $eb_reihe('s_inhalt', $eb_i);
            $s['einheit'] = $eb_reihe('s_einheit', $eb_i);
            $s['stilllegen'] = !empty($_POST['s_still'][$eb_i]) ? 1 : 0;
            if (!isset($eb_sarten[$s['art']])) { $s['art'] = 'aus'; }
            if (!isset($eb_einh[$s['einheit']])) { $s['einheit'] = 'W'; }
            $bez = eb_t('STELL.STELLER') . ' ' . ($eb_i + 1);
            foreach (array('spitze_w' => array('s_spitze', 0, 1000000),
                           'anteil' => array('s_anteil', 0, 100)) as $eb_f => $eb_d) {
                $w = $eb_zahl_pruef($eb_reihe($eb_d[0], $eb_i), $eb_d[1], $eb_d[2],
                                    $bez . ' / ' . eb_t('STELL.L_' . strtoupper($eb_f)));
                if ($w !== null) { $s[$eb_f] = $w; }
            }
            if ($s['name'] !== '' && $s['art'] !== 'aus') {
                if ($s['adresse'] === '') {
                    $eb_fehler[] = sprintf(eb_t('FEHLER.STELLER_OHNE_ADRESSE'), $eb_i + 1);
                } elseif ($s['art'] === 'sunspec') {
                    /* Kein Platzhalter noetig: der SunSpec-Weg rechnet die
                     * Prozentzahl selbst aus Grenze und Nennleistung. */
                    if (eb_sunspec_zerlegen($s['adresse']) === null) {
                        $eb_fehler[] = sprintf(eb_t('FEHLER.SUNSPEC_FORM'), $eb_i + 1);
                    }
                } elseif (strpos($s['adresse'] . $s['inhalt'], '{') === false) {
                    $eb_fehler[] = sprintf(eb_t('FEHLER.OHNE_PLATZHALTER'), $eb_i + 1);
                }
                if ($s['art'] === 'sunspec' && (int) $s['spitze_w'] <= 0) {
                    $eb_fehler[] = sprintf(eb_t('FEHLER.SUNSPEC_OHNE_SPITZE'), $eb_i + 1);
                } elseif ($s['einheit'] === 'Prozent' && (int) $s['spitze_w'] <= 0) {
                    $eb_fehler[] = sprintf(eb_t('FEHLER.PROZENT_OHNE_SPITZE'), $eb_i + 1);
                }
            }
            $eb_neu_st[$eb_i] = $s;
        }
        $eb_cfg['steller'] = $eb_neu_st;

    /* ---- Der Weg zum Speicher ---- */
    $sp = eb_steller_vorgabe();
    $sp['name'] = $eb_wert('sp_name');
    $sp['art'] = $eb_wert('sp_art', 'aus');
    $sp['adresse'] = $eb_wert('sp_adresse');
    $sp['inhalt'] = $eb_wert('sp_inhalt');
    $sp['einheit'] = $eb_wert('sp_einheit', 'W');
    $sp['stilllegen'] = !empty($_POST['sp_still']) ? 1 : 0;
    if (!isset($eb_sarten[$sp['art']])) { $sp['art'] = 'aus'; }
    if (!isset($eb_einh[$sp['einheit']])) { $sp['einheit'] = 'W'; }
    $w = $eb_zahl_pruef($eb_wert('sp_spitze'), 0, 1000000,
                        eb_t('STELL.SPEICHER') . ' / ' . eb_t('STELL.L_SPITZE_W'));
    if ($w !== null) { $sp['spitze_w'] = $w; }
    if ($sp['art'] !== 'aus') {
        if ($sp['adresse'] === '') {
            $eb_fehler[] = eb_t('MANGEL.SPEICHER_OHNE_ADRESSE');
        } elseif ($sp['art'] === 'sunspec') {
            $eb_fehler[] = eb_t('MANGEL.SPEICHER_SUNSPEC');
        } elseif (strpos($sp['adresse'] . $sp['inhalt'], '{') === false) {
            $eb_fehler[] = eb_t('MANGEL.SPEICHER_OHNE_PLATZHALTER');
        }
        if ($sp['einheit'] === 'Prozent' && (int) $sp['spitze_w'] <= 0) {
            $eb_fehler[] = eb_t('MANGEL.SPEICHER_PROZENT_OHNE_SPITZE');
        }
    }
    $eb_cfg['sp_steller'] = $sp;

        /* ---- Regelgroessen ---- */
        foreach (array(
            'ziel_w' => array(0, 1000000), 'totband_w' => array(0, 10000),
            'rampe_ab_w' => array(10, 1000000), 'rampe_auf_w' => array(10, 1000000),
            'drossel_min_w' => array(0, 1000000), 'notfall_s' => array(5, 3600),
            'notfall_w' => array(0, 1000000), 'frei_w' => array(0, 1000000),
            'soc_max' => array(10, 100), 'lade_max_w' => array(0, 1000000),
            'wirkung_s' => array(5, 600), 'takt' => array(2, 300),
            'quelle_alter_s' => array(10, 86400),
            'ziel1_w' => array(0, 1000000), 'ziel2_w' => array(0, 1000000),
        ) as $eb_f => $eb_gr) {
            $w = $eb_zahl_pruef($eb_wert($eb_f), $eb_gr[0], $eb_gr[1], eb_t('EINST.L_' . strtoupper($eb_f)));
            if ($w !== null) { $eb_cfg[$eb_f] = $w; }
        }
        $eb_cfg['speicher_zuerst'] = !empty($_POST['speicher_zuerst']) ? 1 : 0;
        $eb_cfg['bilanz_ein'] = !empty($_POST['bilanz_ein']) ? 1 : 0;
        $eb_cfg['verlauf_ein'] = !empty($_POST['verlauf_ein']) ? 1 : 0;
    }

    if ($eb_formular === 'mqtt') {
        $eb_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;

        $eb_thema = strtolower($eb_wert('mqtt_topic'));
        $eb_thema = trim($eb_thema, '/');
        if ($eb_thema === '') {
            $eb_cfg['mqtt_topic'] = 'einspeisebremse';
        } elseif (!preg_match('#^[a-z0-9_\-/]+$#', $eb_thema)) {
            // Ein Thema mit + oder # ist ein Filtermuster und als Ziel unbrauchbar.
            $eb_fehler[] = sprintf(eb_t('FEHLER.THEMA'), $eb_thema);
        } else {
            $eb_cfg['mqtt_topic'] = $eb_thema;
        }
    }

    if (!$eb_fehler) {
        if (eb_config_speichern($eb_cfg)) {
            $eb_meldungen[] = eb_t('ALLG.GESPEICHERT');
            eb_log('Einstellungen gespeichert.');
        } else {
            $eb_fehler[] = eb_t('FEHLER.SPEICHERN');
        }
    }
}

/* ---------------- Neues Wortzeichen ---------------- */
if ($eb_post && isset($_POST['token_neu'])) {
    $eb_cfg = eb_config();
    $eb_cfg['aktionstoken'] = eb_token_erzeugen();
    eb_config_speichern($eb_cfg);
    $eb_meldungen[] = eb_t('LOX.TOKEN_NEU_OK');
    eb_log('Neues Wortzeichen erzeugt.');
    $eb_tab = 'tab-loxone';
}

/* ---------------- Protokoll leeren ---------------- */
if ($eb_post && isset($_POST['log_leeren'])) {
    @file_put_contents($eb_p['log'], '');
    eb_log('Protokoll geleert.');
    $eb_meldungen[] = eb_t('LOG.GELEERT');
    $eb_tab = 'tab-log';
}

/* ---------------- Test ---------------- */
if ($eb_post && isset($_POST['test'])) {
    $eb_testausgabe = eb_test_ausfuehren((string) $_POST['test']);
    $eb_tab = 'tab-test';
}

/* ================= Werte fuer die Anzeige ================= */
$eb_cfg = eb_config();
$eb_stand = eb_stand();
$eb_mangel = eb_maengel($eb_cfg);
$eb_mqtt = eb_mqtt_zustand();
$eb_pid = eb_dienst_pid();
$eb_logzeilen = eb_log_ende($eb_p['log'], 400);
$eb_zahlen = function ($v, $eh = ' W') {
    /* Fehlt ein Wert, steht dort ein Strich. Niemals eine 0 - eine erfundene
     * Null saehe hier aus wie "kein Bezug, keine Einspeisung". */
    return ($v === null || !is_numeric($v)) ? '&ndash;' : (int) round($v) . $eh;
};

$eb_rahmen = class_exists('LBWeb', false);
if ($eb_rahmen) {
    LBWeb::lbheader(eb_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($eb_post && isset($_POST['eb_sichern'])) {
    $eb_js = json_encode(eb_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($eb_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="einspeisebremse_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $eb_js;
        exit;
    }
    $eb_fehler[] = eb_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($eb_post && isset($_POST['eb_zurueck'])) {
    if (!isset($_FILES['eb_sicherung']) || !is_array($_FILES['eb_sicherung'])
        || !isset($_FILES['eb_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['eb_sicherung']['tmp_name'])) {
        $eb_fehler[] = eb_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['eb_sicherung']['size'] > 262144) {
        $eb_fehler[] = eb_t('EINST.SICH_ZU_GROSS');
    } else {
        list($eb_neu, $eb_mangel, $eb_n) = eb_sicherung_lesen(
            (string) @file_get_contents($_FILES['eb_sicherung']['tmp_name']));
        if ($eb_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $eb_fehler[] = eb_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $eb_mangel);
        } elseif (eb_config_speichern($eb_neu)) {
            $eb_meldungen[] = sprintf(eb_t('EINST.SICH_UEBERNOMMEN'), $eb_n);
        } else {
            $eb_fehler[] = eb_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
/* Auswahlfelder muessen als solche erkennbar sein. Ohne diese Zeilen baut
   jQuery Mobile sie zu einem Knopf OHNE Pfeil um - dann sieht niemand, dass
   es mehrere Eintraege gibt. Am 19.08.2026 an einem anderen Plugin gemeldet. */
.sm-wrap select { -webkit-appearance: menulist; appearance: menulist;
  border: 1px solid #bbb; border-radius: 4px; padding: 4px 6px; background: #fff;
  font-size: 0.95em; min-width: 12em; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
</style>

<div class="sm-wrap">

<?php if (eb_sprache_fehlt()) { ?>
<!-- Bewusst fest im Quelltext: wenn diese Meldung noetig ist, kann eb_t()
     nichts uebersetzen. -->
<div class="sm-warnung"><b>Die Sprachdateien wurden nicht gefunden.</b>
  Unten stehen deshalb nur die Schl&uuml;ssel statt der Texte. Erwartet werden sie unter
  <span class="sm-mono">&lt;LoxBerry&gt;/templates/plugins/<?= eb_e($eb_p['plugin']) ?>/lang/</span>.
  Meist hilft ein erneutes Installieren des Plugins.</div>
<?php } ?>

<?php if ($eb_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('eb_e', $eb_meldungen)) ?></div>
<?php } ?>
<?php if ($eb_fehler) { ?>
<div class="sm-warnung"><b><?= eb_e(eb_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('eb_e', $eb_fehler)) ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= eb_e(eb_t('ALLG.REGELUNG')) ?>
    <b class="<?= !empty($eb_cfg['ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($eb_cfg['ein']) ? eb_e(eb_t('ALLG.EIN')) : eb_e(eb_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= $eb_pid
        ? eb_e(eb_t('ALLG.DIENST_LAEUFT') . ' (PID ' . $eb_pid . ')')
        : eb_e(eb_t('ALLG.DIENST_STEHT')) ?></span>
  </div>
  <div class="sm-kachel"><?= eb_e(eb_t('ALLG.NETZ')) ?>
    <b><?= $eb_zahlen(isset($eb_stand['netz']) ? $eb_stand['netz'] : null) ?></b>
    <span class="sm-hilfe"><?= eb_e(eb_t('ALLG.NETZ_HILFE')) ?></span>
  </div>
  <div class="sm-kachel"><?= eb_e(eb_t('ALLG.GRENZE')) ?>
    <b><?= $eb_zahlen(isset($eb_stand['drossel_w']) ? $eb_stand['drossel_w'] : null) ?></b>
    <span class="sm-hilfe"><?= eb_e(eb_t('ALLG.GRENZE_HILFE')) ?></span>
  </div>
  <div class="sm-kachel"><?= eb_e(eb_t('ALLG.LADESOLL')) ?>
    <b><?= $eb_zahlen(isset($eb_stand['lade_soll_w']) ? $eb_stand['lade_soll_w'] : null) ?></b>
    <span class="sm-hilfe"><?= eb_e(eb_t('ALLG.LADESOLL_HILFE')) ?></span>
  </div>
  <div class="sm-kachel"><?= eb_e(eb_t('ALLG.LETZTE_MESSUNG')) ?>
    <b class="<?= (eb_alter() >= 0 && eb_alter() <= (int) $eb_cfg['notfall_s']) ? 'sm-an' : 'sm-aus' ?>"><?= eb_alter() < 0 ? '&ndash;' : (int) eb_alter() ?></b>
    <span class="sm-hilfe"><?= eb_alter() < 0 ? eb_e(eb_t('ALLG.NIE')) : eb_e(eb_t('ALLG.SEKUNDEN')) ?></span>
  </div>
</div>

<?php if (!empty($eb_stand['notfall'])) { ?>
<div class="sm-warnung"><b><?= eb_e(eb_t('ALLG.NOTFALL_KOPF')) ?></b><br><?= eb_t('ALLG.NOTFALL_TEXT') ?></div>
<?php } elseif (isset($eb_stand['wirkung']) && (int) $eb_stand['wirkung'] === -1) { ?>
<div class="sm-warnung"><b><?= eb_e(eb_t('ALLG.KEINE_WIRKUNG_KOPF')) ?></b><br><?= eb_t('ALLG.KEINE_WIRKUNG_TEXT') ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und die Seite ohne Skript bedienbar. Welcher Reiter
     offen ist, entscheidet der SERVER. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $eb_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= eb_e(eb_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $eb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $eb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= eb_e(eb_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $eb_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= eb_e(eb_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $eb_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= eb_e(eb_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $eb_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<!-- EINE gesammelte Legende fuer den ganzen Reiter, ganz oben. Nicht je
     Knopfreihe eine eigene: dieselbe Zeile mehrfach untereinander stiftet
     mehr Unruhe als Nutzen. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= eb_t('LEGENDE.LESEN_START') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= eb_t('LEGENDE.AKTION_EINSTELLUNGEN') ?></span>
</div>

<h2><?= eb_e(eb_t('DIENST.H')) ?></h2>
<div class="sm-step"><?= eb_t('DIENST.ERKLAERUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst"
            value="start"><?= eb_e(eb_t('DIENST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst"
            value="restart"><?= eb_e(eb_t('DIENST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst"
            value="stop"><?= eb_e(eb_t('DIENST.K_STOP')) ?></button>
  </form>
</div>

<h2><?= eb_e(eb_t('EINST.H_SCHALTER')) ?></h2>
<div class="sm-step"><?= eb_t('EINST.SCHALTER_ERKLAERUNG') ?></div>
<?php if ($eb_mangel) { ?>
<div class="sm-warnung"><b><?= eb_e(eb_t('EINST.MAENGEL_KOPF')) ?></b><ul>
<?php foreach ($eb_mangel as $eb_m) { ?><li><?= eb_t($eb_m) ?></li><?php } ?>
</ul></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="schalten"
            value="<?= !empty($eb_cfg['ein']) ? '0' : '1' ?>"><?= !empty($eb_cfg['ein']) ? eb_e(eb_t('EINST.K_AUS')) : eb_e(eb_t('EINST.K_EIN')) ?></button>
  </form>
</div>

<h3><?= eb_e(eb_t('VORLAGE.H')) ?></h3>
<div class="sm-step"><?= eb_t('VORLAGE.ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<table class="sm-tbl"><tr>
  <td><label for="eb_vq"><?= eb_e(eb_t('VORLAGE.H')) ?><br>
    <select data-role="none" id="eb_vq" name="vorlage_quellen">
<?php foreach (eb_quellvorlagen() as $eb_vk => $eb_vv) { ?>
      <option value="<?= eb_e($eb_vk) ?>"><?= eb_e(eb_t($eb_vv['bez'])) ?></option>
<?php } ?>
    </select></label></td>
  <td><label for="eb_vqz"><?= eb_e(eb_t('VORLAGE.ZIEL')) ?><br>
    <select data-role="none" id="eb_vqz" name="vq_ziel">
      <option value="vorlage"><?= eb_e(eb_t('VORLAGE.Z_VORLAGE')) ?></option>
      <option value="ersatz"><?= eb_e(eb_t('VORLAGE.Z_ERSATZ')) ?></option>
    </select></label></td>
  <td><label for="eb_vqw"><?= eb_e(eb_t('VORLAGE.F_IP')) ?> / <?= eb_e(eb_t('VORLAGE.F_PRAEFIX')) ?><br>
    <input data-role="none" type="text" size="28" id="eb_vqw" name="vq_wert"
           value="<?= eb_e(isset($_POST['vq_wert']) ? $_POST['vq_wert'] : '') ?>"></label></td>
</tr></table>
<!-- Der Knopf in eine eigene Reihe: als vierte Spalte fiel er auf
     schmaleren Bildschirmen hinten heraus und war abgeschnitten. -->
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= eb_e(eb_t('VORLAGE.K_ANWENDEN')) ?></button>
</div>
</form>
<ul class="sm-hilfe">
<?php foreach (eb_quellvorlagen() as $eb_vk => $eb_vv) { ?>
  <li><b><?= eb_e(eb_t($eb_vv['bez'])) ?>:</b> <?= eb_t($eb_vv['hinweis']) ?></li>
<?php } ?>
</ul>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="<?= eb_e($eb_tab) ?>">
<input data-role="none" type="hidden" name="formular" value="einstellungen">

<h2><?= eb_e(eb_t('EINST.H_QUELLEN')) ?></h2>
<div class="sm-step"><?= eb_t('EINST.QUELLEN_ERKLAERUNG') ?></div>
<table class="sm-tbl">
<tr><th><?= eb_e(eb_t('QUELLE.SP_GROESSE')) ?></th><th><?= eb_e(eb_t('QUELLE.L_ART')) ?></th>
    <th><?= eb_e(eb_t('QUELLE.L_ADRESSE')) ?></th><th><?= eb_e(eb_t('QUELLE.L_PFAD')) ?></th>
    <th><?= eb_e(eb_t('QUELLE.L_FAKTOR')) ?></th><th><?= eb_e(eb_t('QUELLE.L_INV')) ?></th></tr>
<?php foreach (eb_quellenfelder() as $eb_k => $eb_f) {
    $eb_bez = $eb_f['bez'];
    $q = $eb_cfg[$eb_k]; ?>
<tr>
  <td><b><?= eb_e(eb_t($eb_bez)) ?></b></td>
  <td><select data-role="none" name="<?= eb_e($eb_k) ?>_art">
<?php foreach (eb_quellarten() as $eb_a => $eb_as) { ?>
      <option value="<?= eb_e($eb_a) ?>"<?= $q['art'] === $eb_a ? ' selected' : '' ?>><?= eb_e(eb_t($eb_as)) ?></option>
<?php } ?>
  </select></td>
  <td><input data-role="none" type="text" size="30" name="<?= eb_e($eb_k) ?>_adresse" value="<?= eb_e($q['adresse']) ?>"></td>
  <td><input data-role="none" type="text" size="14" name="<?= eb_e($eb_k) ?>_pfad" value="<?= eb_e($q['pfad']) ?>"></td>
  <td><input data-role="none" type="text" size="6" name="<?= eb_e($eb_k) ?>_faktor" value="<?= eb_e($q['faktor']) ?>"></td>
  <td><input data-role="none" type="checkbox" name="<?= eb_e($eb_k) ?>_inv" value="1"<?= $q['invertieren'] ? ' checked' : '' ?>></td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= eb_t('QUELLE.HILFE') ?></p>

<h2><?= eb_e(eb_t('EINST.H_STELLER')) ?></h2>
<div class="sm-step"><?= eb_t('EINST.STELLER_ERKLAERUNG') ?></div>
<?php for ($eb_i = 0; $eb_i < EB_STELLER; $eb_i++) { $s = $eb_cfg['steller'][$eb_i]; ?>
<h3><?= eb_e(eb_t('STELL.STELLER')) ?> <?= $eb_i + 1 ?><?= $s['name'] !== '' ? ': ' . eb_e($s['name']) : '' ?></h3>
<table class="sm-tbl">
<tr>
  <td><label><?= eb_e(eb_t('STELL.L_NAME')) ?><br>
    <input data-role="none" type="text" size="16" name="s_name[<?= $eb_i ?>]" value="<?= eb_e($s['name']) ?>"></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_ART')) ?><br>
    <select data-role="none" name="s_art[<?= $eb_i ?>]">
<?php foreach (eb_stellarten() as $eb_a => $eb_as) { ?>
      <option value="<?= eb_e($eb_a) ?>"<?= $s['art'] === $eb_a ? ' selected' : '' ?>><?= eb_e(eb_t($eb_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_EINHEIT')) ?><br>
    <select data-role="none" name="s_einheit[<?= $eb_i ?>]">
<?php foreach (eb_einheiten() as $eb_a => $eb_as) { ?>
      <option value="<?= eb_e($eb_a) ?>"<?= $s['einheit'] === $eb_a ? ' selected' : '' ?>><?= eb_e(eb_t($eb_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_SPITZE_W')) ?><br>
    <input data-role="none" type="text" size="8" name="s_spitze[<?= $eb_i ?>]" value="<?= (int) $s['spitze_w'] ?>"></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_ANTEIL')) ?><br>
    <input data-role="none" type="text" size="5" name="s_anteil[<?= $eb_i ?>]" value="<?= (int) $s['anteil'] ?>"></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_STILL')) ?><br>
    <input data-role="none" type="checkbox" name="s_still[<?= $eb_i ?>]" value="1"<?= !empty($s['stilllegen']) ? ' checked' : '' ?>></label></td>
</tr>
<tr>
  <td colspan="3"><label><?= eb_e(eb_t('STELL.L_ADRESSE')) ?><br>
    <input data-role="none" type="text" size="70" name="s_adresse[<?= $eb_i ?>]" value="<?= eb_e($s['adresse']) ?>"></label></td>
  <td colspan="2"><label><?= eb_e(eb_t('STELL.L_INHALT')) ?><br>
    <input data-role="none" type="text" size="34" name="s_inhalt[<?= $eb_i ?>]" value="<?= eb_e($s['inhalt']) ?>"></label></td>
</tr>
</table>
<?php } ?>
<p class="sm-hilfe"><?= eb_t('STELL.HILFE') ?></p>
<p class="sm-hilfe"><?= eb_t('STELL.SUNSPEC_HILFE') ?></p>
<p class="sm-hilfe"><?= eb_t('STELL.STILL_HILFE') ?></p>

<h2><?= eb_e(eb_t('EINST.H_SPEICHER_STELLER')) ?></h2>
<div class="sm-step"><?= eb_t('EINST.SPEICHER_STELLER_ERKLAERUNG') ?></div>
<?php $eb_sp = $eb_cfg['sp_steller']; ?>
<table class="sm-tbl">
<tr>
  <td><label><?= eb_e(eb_t('STELL.L_NAME')) ?><br>
    <input data-role="none" type="text" size="16" name="sp_name" value="<?= eb_e($eb_sp['name']) ?>"></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_ART')) ?><br>
    <select data-role="none" name="sp_art">
<?php foreach (eb_stellarten() as $eb_a => $eb_as) { ?>
      <option value="<?= eb_e($eb_a) ?>"<?= $eb_sp['art'] === $eb_a ? ' selected' : '' ?>><?= eb_e(eb_t($eb_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_EINHEIT')) ?><br>
    <select data-role="none" name="sp_einheit">
<?php foreach (eb_einheiten() as $eb_a => $eb_as) { ?>
      <option value="<?= eb_e($eb_a) ?>"<?= $eb_sp['einheit'] === $eb_a ? ' selected' : '' ?>><?= eb_e(eb_t($eb_as)) ?></option>
<?php } ?>
    </select></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_SPITZE_W')) ?><br>
    <input data-role="none" type="text" size="8" name="sp_spitze" value="<?= (int) $eb_sp['spitze_w'] ?>"></label></td>
  <td><label><?= eb_e(eb_t('STELL.L_STILL')) ?><br>
    <input data-role="none" type="checkbox" name="sp_still" value="1"<?= !empty($eb_sp['stilllegen']) ? ' checked' : '' ?>></label></td>
</tr>
<tr>
  <td colspan="3"><label><?= eb_e(eb_t('STELL.L_ADRESSE')) ?><br>
    <input data-role="none" type="text" size="70" name="sp_adresse" value="<?= eb_e($eb_sp['adresse']) ?>"></label></td>
  <td colspan="2"><label><?= eb_e(eb_t('STELL.L_INHALT')) ?><br>
    <input data-role="none" type="text" size="34" name="sp_inhalt" value="<?= eb_e($eb_sp['inhalt']) ?>"></label></td>
</tr>
</table>

<h2><?= eb_e(eb_t('EINST.H_REGELUNG')) ?></h2>
<div class="sm-step"><?= eb_t('EINST.REGELUNG_ERKLAERUNG') ?></div>
<table class="sm-tbl">
<?php
$eb_gruppen = array(
    array('ziel_w', 'totband_w', 'rampe_ab_w', 'rampe_auf_w'),
    array('drossel_min_w', 'notfall_s', 'notfall_w', 'frei_w'),
    array('lade_max_w', 'soc_max', 'wirkung_s', 'takt'),
    array('quelle_alter_s', 'ziel1_w', 'ziel2_w'),
);
foreach ($eb_gruppen as $eb_zeile) { ?>
<tr>
<?php foreach ($eb_zeile as $eb_f) { ?>
  <td><label for="eb_<?= eb_e($eb_f) ?>"><?= eb_e(eb_t('EINST.L_' . strtoupper($eb_f))) ?><br>
    <input data-role="none" type="text" size="9" id="eb_<?= eb_e($eb_f) ?>" name="<?= eb_e($eb_f) ?>" value="<?= (int) $eb_cfg[$eb_f] ?>"></label></td>
<?php } ?>
</tr>
<?php } ?>
</table>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="speicher_zuerst" value="1"<?= !empty($eb_cfg['speicher_zuerst']) ? ' checked' : '' ?>> <?= eb_e(eb_t('EINST.L_SPEICHER_ZUERST')) ?></label>
  <p class="sm-hilfe"><?= eb_t('EINST.H_SPEICHER_ZUERST') ?></p>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="bilanz_ein" value="1"<?= !empty($eb_cfg['bilanz_ein']) ? ' checked' : '' ?>> <?= eb_e(eb_t('EINST.L_BILANZ_EIN')) ?></label>
  <label><input data-role="none" type="checkbox" name="verlauf_ein" value="1"<?= !empty($eb_cfg['verlauf_ein']) ? ' checked' : '' ?>> <?= eb_e(eb_t('EINST.L_VERLAUF_EIN')) ?></label>
</div>
<div class="sm-step"><?= sprintf(eb_t('EINST.STUFEN_ERKLAERUNG'),
        (int) $eb_cfg['stufe'], (int) eb_ziel_w($eb_cfg)) ?></div>
<p class="sm-hilfe"><?= eb_t('EINST.REGELGROESSEN_HILFE') ?></p>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= eb_e(eb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= eb_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= eb_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= eb_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="eb_sichern" value="1"><?= eb_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="eb_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="eb_zurueck" value="1"><?= eb_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $eb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-step"><?= eb_t('MQTT.ERKLAERUNG') ?></div>
<?php if (!$eb_mqtt['gefunden']) { ?>
<div class="sm-warnung"><?= eb_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$eb_mqtt['autostart']) { ?>
<div class="sm-warnung"><?= eb_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(eb_t('MQTT.LAEUFT'), eb_e((string) $eb_mqtt['udpport'])) ?></div>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="formular" value="mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= $eb_cfg['mqtt_ein'] ? ' checked' : '' ?>> <?= eb_e(eb_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="eb_thema"><?= eb_e(eb_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="eb_thema" name="mqtt_topic" value="<?= eb_e($eb_cfg['mqtt_topic']) ?>">
  <p class="sm-hilfe"><?= eb_t('MQTT.THEMA_HILFE') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= eb_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= eb_e(eb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= eb_e(eb_t('MQTT.H_THEMEN')) ?></h3>
<table class="sm-tbl">
<tr><th><?= eb_e(eb_t('MQTT.SP_THEMA')) ?></th><th><?= eb_e(eb_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (eb_mqtt_themen() as $eb_k => $eb_schl) { ?>
<tr><td><span class="sm-mono"><?= eb_e($eb_cfg['mqtt_topic'] . '/' . $eb_k) ?></span></td>
    <td><?= eb_e(eb_t($eb_schl)) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= eb_t('MQTT.STELLERN_HILFE') ?></p>

<h3><?= eb_e(eb_t('MQTT.H_ABO')) ?></h3>
<div class="sm-step"><?= eb_t('MQTT.ABO_HILFE') ?>
  <p><span class="sm-mono"><?= eb_e($eb_cfg['mqtt_topic']) ?>/#</span></p></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $eb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= eb_e(eb_t('LOX.H_SCHRITTE')) ?></h2>
<div class="sm-step"><?= eb_t('LOX.S1') ?></div>
<div class="sm-step"><?= eb_t('LOX.S2') ?></div>
<div class="sm-step"><?= eb_t('LOX.S3') ?></div>
<div class="sm-step"><?= eb_t('LOX.S4') ?></div>
<div class="sm-step"><?= eb_t('LOX.S5') ?></div>
<div class="sm-step"><?= eb_t('LOX.S6') ?></div>
<div class="sm-step"><?= eb_t('LOX.S7') ?></div>

<h2><?= eb_e(eb_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-step"><?= eb_t('LOX.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= eb_t('LEGENDE.TECHNIK_XML') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= eb_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= eb_e(eb_t('LOX.K_VI')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= eb_e(eb_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>

<?php if (eb_adresse_zweifelhaft()) {
    $eb_ip = eb_lan_adresse();
    $eb_wohin = $eb_ip !== ''
        ? sprintf(eb_t('LOX.ADRESSE_IP'), eb_e($eb_ip))
        : eb_t('LOX.ADRESSE_IP_UNBEKANNT'); ?>
<div class="sm-warnung"><?= sprintf(eb_t('LOX.ADRESSE_WARNUNG'),
    eb_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '?'), $eb_wohin) ?></div>
<?php } ?>
<h3><?= eb_e(eb_t('LOX.H_ADRESSE')) ?></h3>
<p class="sm-hilfe"><?= eb_t('LOX.ADRESSE_HILFE') ?></p>
<table class="sm-tbl">
<tr><th><?= eb_e(eb_t('LOX.SP_ZWECK')) ?></th><th><?= eb_e(eb_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= eb_e(eb_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= eb_e(eb_endpunkt() . '?token=' . eb_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= eb_e(eb_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= eb_e(eb_endpunkt() . '?token=' . eb_token() . '&aktion=json') ?></span></td></tr>
<tr><td><?= eb_e(eb_t('LOX.Z_EIN')) ?></td><td><span class="sm-mono"><?= eb_e(eb_endpunkt() . '?token=' . eb_token() . '&aktion=ein&wert=1') ?></span></td></tr>
<tr><td><?= eb_e(eb_t('LOX.Z_AUS')) ?></td><td><span class="sm-mono"><?= eb_e(eb_endpunkt() . '?token=' . eb_token() . '&aktion=ein&wert=0') ?></span></td></tr>
<tr><td><?= eb_e(eb_t('LOX.Z_STUFE')) ?></td><td><span class="sm-mono"><?= eb_e(eb_endpunkt() . '?token=' . eb_token() . '&aktion=stufe&wert=0') ?></span></td></tr>
</table>
<p class="sm-hilfe"><?= eb_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= eb_e(eb_t('LOX.H_FELDER')) ?></h3>
<table class="sm-tbl">
<tr><th><?= eb_e(eb_t('LOX.SP_FELD')) ?></th><th><?= eb_e(eb_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (eb_felder() as $eb_f => $eb_info) { ?>
<tr><td><span class="sm-mono"><?= eb_e($eb_f) ?></span></td>
    <td><?= eb_e(eb_t($eb_info[3])) ?><?= $eb_info[0] !== '' ? ' [' . eb_e($eb_info[0]) . ']' : '' ?></td></tr>
<?php } ?>
</table>

<h3><?= eb_e(eb_t('LOX.H_BAUSTEINE')) ?></h3>
<?= eb_t('LOX.BAUSTEINE') ?>
<p class="sm-hilfe"><?= eb_t('LOX.BAUSTEINE_ERL') ?></p>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $eb_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<!-- EINE gesammelte Legende, ganz oben - vor jeder Knopfreihe. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= eb_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= eb_t('LEGENDE.TECHNIK') ?></span>
</div>
<h2><?= eb_e(eb_t('TEST.H')) ?></h2>
<div class="sm-step"><?= eb_t('TEST.ERKLAERUNG') ?></div>
<!-- Ausgeschrieben und nicht erzeugt: eine erzeugte Reihe zeigt die
     Farbklassen nirgends im Quelltext, und die Hausprüfung meldet dann
     eine Legende, die angeblich nicht passt. Wer hier einen Knopf
     ergänzt, ergänzt auch die Positivliste in eb_test.php. -->
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test"
            value="probe"><?= eb_e(eb_t('TEST.K_PROBE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test"
            value="trocken"><?= eb_e(eb_t('TEST.K_TROCKEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test"
            value="maengel"><?= eb_e(eb_t('TEST.K_MAENGEL')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test"
            value="selbsttest"><?= eb_e(eb_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test"
            value="zeile"><?= eb_e(eb_t('TEST.K_ZEILE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test"
            value="mqtt"><?= eb_e(eb_t('TEST.K_MQTT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test"
            value="endpunkt"><?= eb_e(eb_t('TEST.K_ENDPUNKT')) ?></button>
  </form>
</div>
<?php if ($eb_testausgabe !== '') { ?>
<div class="sm-pre"><?= eb_e($eb_testausgabe) ?></div>
<?php } ?>

<h2><?= eb_e(eb_t('TEST.H_SELBST')) ?></h2>
<div class="sm-step"><?= eb_t('TEST.SELBST_ERKLAERUNG') ?></div>
<?= eb_selbstpruefung_html() ?>

<h2><?= eb_e(eb_t('TEST.H_BILANZ')) ?></h2>
<div class="sm-step"><?= eb_t('TEST.BILANZ_ERKLAERUNG') ?></div>
<?= eb_bilanz_html() ?>

<h2><?= eb_e(eb_t('TEST.H_VERLAUF')) ?></h2>
<div class="sm-step"><?= eb_t('TEST.VERLAUF_ERKLAERUNG') ?></div>
<?php $eb_svg = eb_verlauf_svg(); ?>
<?php if ($eb_svg !== '') { ?>
<?= $eb_svg ?>
<p class="sm-hilfe"><?= eb_t('TEST.V_LEGENDE') ?></p>
<?php } else { ?>
<div class="sm-hinweis"><?= eb_t('TEST.V_LEER') ?></div>
<?php } ?>

<h2><?= eb_e(eb_t('TEST.H_WENN')) ?></h2>
<div class="sm-step"><?= eb_t('TEST.WENN_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<table class="sm-tbl"><tr>
  <td><label><?= eb_e(eb_t('TEST.W_NETZ')) ?><br>
    <input data-role="none" type="text" size="8" name="w_netz" value="<?= eb_e(isset($_POST['w_netz']) ? $_POST['w_netz'] : '-3000') ?>"></label></td>
  <td><label><?= eb_e(eb_t('TEST.W_ERZEUGUNG')) ?><br>
    <input data-role="none" type="text" size="8" name="w_erz" value="<?= eb_e(isset($_POST['w_erz']) ? $_POST['w_erz'] : '') ?>"></label></td>
  <td><label><?= eb_e(eb_t('TEST.W_SOC')) ?><br>
    <input data-role="none" type="text" size="5" name="w_soc" value="<?= eb_e(isset($_POST['w_soc']) ? $_POST['w_soc'] : '') ?>"></label></td>
  <td><label><?= eb_e(eb_t('TEST.W_LADE')) ?><br>
    <input data-role="none" type="text" size="8" name="w_lade" value="<?= eb_e(isset($_POST['w_lade']) ? $_POST['w_lade'] : '') ?>"></label></td>
</tr></table>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="wenn"><?= eb_e(eb_t('TEST.K_WENN')) ?></button>
</div>
</form>

</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $eb_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= eb_e(eb_t('LOG.H')) ?></h2>
<p class="sm-hilfe"><?= eb_t('LOG.ERKLAERUNG') ?>
<span class="sm-mono"><?= eb_e($eb_p['log']) ?></span></p>
<?php if ($eb_logzeilen) { ?>
<div class="sm-log"><?= eb_e(implode("\n", $eb_logzeilen)) ?></div>
<?php
/* Der Hausstandard verlangt hier zusaetzlich die Liste der Protokolldateien
 * aus dem SDK. Sie steht nur zur Verfuegung, wenn das SDK geladen ist -
 * im Archiv und in der Attrappe ist es das nicht, deshalb die Abfrage. */
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html(array('PLUGIN' => $eb_p['plugin'], 'NAME' => 'einspeisebremse'));
}
?>
<?php } else { ?>
<div class="sm-hinweis"><?= eb_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= eb_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= eb_e(eb_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($eb_tab) ?>);
})();
</script>
<?php
if ($eb_rahmen) {
    LBWeb::lbfooter();
}
