<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Einspeisebremse.
 *
 * Gruppe 3 / MQTT (0.9.37; Bauvorschrift wz_bild/GRUPPE3_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe3_eb.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE3_MQTT.md; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php und
 * vb_g2_rb_bau/bin/befehle_zuordnung.php). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die
 * Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - eb_befehl_ausfuehren() - mit Quelle
 * 'mqtt' (Wertpruefung, sperrende Maengel, X-7, eb_config_feld_setzen() unter Sperre, Zuruecklesen) und gibt
 * array(bool ok, string grund) zurueck (eb_befehl_bruecke()). Die Token-Pruefung des Endpunkts ersetzen die Anmeldung
 * am Broker und die beiden Haken (Entwurf gemeinsam E13). Von aussen nur ein/aus und eine STUFE, nie ein Wattwert.
 *
 * Zwei Pulse (nur "1"; Loxone sendet feste 1) statt eines Schalters: die Musterseite hat zwei TASTEN (#37, #39); ein
 * Schalter sendete beim Loslassen der Taste "0" und schaltete die Regelung gleich wieder aus (Entwurf eb E7). Dazu die
 * Stufe 0..2 (<v.0>). Alle drei greifen ein (Ausschalten gibt die Anlage frei und hebt die Einspeisegrenze auf) und
 * stehen hinter dem zweiten Haken "Bremse ueber MQTT steuern" (befehle_mqtt_steuern, ab Werk aus, Entwurf eb E6).
 * Sperrzeit 0 s (ENTSCHEIDUNGEN Gruppe 3: das Plugin schreibt bei gleichem Wert nichts).
 */
if (!function_exists('eb_befehl_ausfuehren')) {
    $eb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $eb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/eb_lib.php';
    }
    $eb_kand[] = dirname(__DIR__) . '/webfrontend/html/eb_lib.php';
    foreach ($eb_kand as $eb_k) {
        if (is_file($eb_k)) { require_once $eb_k; break; }
    }
}
$eb_ohne_sperre = 'Das Plugin schreibt bei gleichem Zustand nichts (X-7, eb_befehl_ausfuehren()); eine Sperre koennte nur '
    . 'einen gewollten Wechsel verschlucken - aus, ein, aus binnen 60 s liesse das zweite Aus abprallen, die Regelung '
    . 'bliebe an (ENTSCHEIDUNGEN Gruppe 3, Entwurf eb E7).';
return array(
    'fassung' => 1,
    'linie'   => 'einspeisebremse',
    'plugin'  => 'Einspeisebremse',
    'geladen' => function_exists('eb_config') && function_exists('eb_befehl_ausfuehren') && function_exists('eb_befehl_bruecke'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return eb_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        eb_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => array(
        'einschalten' => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $eb_ohne_sperre,
                               'titel' => 'Einspeisebremse Regelung einschalten (MQTT)', 'kachel' => 'BEFEHL.K_EIN',
                               'quittung' => 'ausgefuehrt',
                               'tun' => function ($w, $t) {
                                   return eb_befehl_bruecke(eb_befehl_ausfuehren('ein', '1', 'mqtt'));
                               }),
        'ausschalten' => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $eb_ohne_sperre,
                               'titel' => 'Einspeisebremse Regelung ausschalten (MQTT)', 'kachel' => 'BEFEHL.K_AUS',
                               'quittung' => 'ausgefuehrt',
                               'tun' => function ($w, $t) {
                                   return eb_befehl_bruecke(eb_befehl_ausfuehren('ein', '0', 'mqtt'));
                               }),
        'stufe'       => array('art' => 'ganz', 'min' => 0, 'max' => 2, 'eingreifend' => true, 'sperre_s' => 0,
                               'begruendung' => $eb_ohne_sperre,
                               'titel' => 'Einspeisebremse Zielstufe wählen (MQTT)', 'kachel' => 'BEFEHL.K_STUFE',
                               'quittung' => 'ausgefuehrt',
                               'tun' => function ($w, $t) {
                                   return eb_befehl_bruecke(eb_befehl_ausfuehren('stufe', (string) $w, 'mqtt'));
                               }),
    ),
);
