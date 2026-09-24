# Einspeisebremse

**Null- oder begrenzte Einspeisung für mehrere Wechselrichter und Hybrid-Speicher.**
Misst am Netzzähler, füllt erst den Speicher, regelt erst dann ab.

Version 0.9.25 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

---

## Neu in 0.9.25

Eine Zweitschrift ohne Einstellungen (kein `aktionstoken`, etwa `{}`) wird bei
der Installation nicht mehr kopiert und als „Konfiguration aus Sicherung
wiederhergestellt“ gemeldet, sondern mit „Sicherung ohne Einstellungen - nichts
zurueckgespielt“; eine vorhandene gute Konfiguration wird wie bisher nie
überschrieben (gemessen in WSL, `Pruefung-Einspeisebremse-0.9.25/postinstall_hinweis.md`).

## Neu in 0.9.24

Nach einem Update meldet die Installation nicht mehr „Die Regelung selbst ist
noch AUS“ samt Erstanleitung: ist die Konfiguration nach dem Zurückspielen
heil (gültiges JSON mit `aktionstoken`, dasselbe Merkmal wie `eb_config()`),
steht dort der übernommene Zustand der Regelung und
`<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen.`; die
„Nächsten Schritte“ erscheinen nur bei der Erstinstallation oder wenn die
Rückholung gescheitert ist (gemessen in WSL,
`Pruefung-Einspeisebremse-0.9.24/postinstall_hinweis.md`).

## Neu in 0.9.23

In WSL gemessen (Prüfstand `Pruefung-Einspeisebremse-0.9.23`, 20 Fälle, 43
Prüfzeilen, alle grün; jede Korrektur einzeln zurückgebaut und geeicht),
nicht am Gerät:

- **Kein Stellbefehl mehr in jedem Takt, wenn nichts freizugeben ist.** Stand
  die Grenze schon auf der Anlagenleistung (Summe der eingetragenen
  Spitzenleistungen) und wurde Strom aus dem Netz bezogen, meldete die
  Regelung bis 0.9.22 in jedem Takt „Freigabe" und schickte jedem Stellglied
  denselben Wert noch einmal — gemessen 11 Stellbefehle in 20 s. Manche
  Adapter und Wechselrichter schreiben jede empfangene Grenze in ihren
  Flash-Speicher. Jetzt wird die Anlagenleistung vor dem Vergleich
  angewandt: eine Freigabe geht nur hinaus, wenn sie die Grenze wirklich
  hebt, der letzte Schritt bis an die Anlagenleistung genau einmal. In
  dieser Lage steht im MQTT-Thema `tat` jetzt `0` statt `3` und in `anlass`
  `nichts_zu_holen` statt `freigabe`. Rechenkern 1.4.1 (146 Fälle).
- **Auch eine stehende Drosselung wird nicht mehr in jedem Takt gestellt.**
  Stand die Grenze gedrosselt still — am Boden (`drossel_min_w`) oder auf
  dem Notwert bei ausgefallenem Zähler —, ging der Stellbefehl bis 0.9.22
  in jedem Takt an jedes Stellglied (gemessen 14 Befehle je Stellglied in
  32 s). Jetzt geht ein Befehl nur bei einer Änderung der Grenze hinaus.
  Das Auffrischen übernehmen die eigenen Wege: ein SunSpec-Stellglied vor
  Ablauf seines Rückfalls (Adresse), MQTT- und **jetzt auch
  HTTP-Stellglieder** im Abstand **MQTT/HTTP: erneut senden alle (s)**
  (Vorgabe 300, `0` = aus, weniger als 10 s geht nicht), beides nur,
  solange gedrosselt wird. **Achtung Flash-Verschleiß:** manche Adapter und
  Wechselrichter schreiben jede empfangene Grenze dauerhaft in ihren
  Speicher — dort den Abstand groß wählen oder auf 0 stellen und am Gerät
  eine eigene Rückfallgrenze einstellen. Ein Stellglied, dessen Anteil sich
  bei gleicher Gesamtgrenze ändert (Anteil, Spitze, neu eingetragen), bekommt
  seinen Wert genau einmal; ein gescheiterter Befehl wird nach 30 s
  wiederholt, nicht mehr in jedem Takt.
- **`notfall`, `anlass` und `wirkung` gehen nicht mehr retained hinaus.** Es
  sind Aussagen des Dienstes: `notfall` heißt, der Dienst hält den
  Zählerwert nach seiner eigenen Altersrechnung für ausgefallen und fährt
  auf den Notwert; `anlass` ist die Begründung seiner Entscheidung,
  `wirkung` sein Urteil, ob die Grenze gewirkt hat. Zurückbehalten stünde
  nach dem Ausfall des Dienstes „kein Notfall" im Broker (Hausstandard,
  Entscheidung vom 19.09.2026). Der alte zurückbehaltene Wert wird beim
  Dienststart abgeräumt, wie bei den übrigen nicht retained gesendeten
  Themen. Nach einem Neustart von Broker oder Gateway fehlen die drei
  Werte, bis sie sich ändern oder der volle Satz hinausgeht (alle 300 s).
  Aus demselben Grund gehen auch `tat` (was die Regelung gerade tut) und
  `speicher` (ihr Urteil, ob der Speicher folgt) nicht mehr retained
  hinaus. Retained bleiben nur `ein`, `stufe`, `ziel` und `stellerN/name`:
  sie gibt die Einstellung vor, nicht ein Gerät und keine Rechnung des
  Dienstes, und sie bleiben wahr, wenn der Dienst nicht mehr läuft.
- **Die Deinstallation räumt die zurückbehaltenen Themen der Bremse ab.**
  Bis 0.9.22 blieben `ein`, `tat`, `stufe`, `ziel`, `speicher`,
  `stellerN/name`, `notfall`, `anlass` und `wirkung` nach dem
  Entfernen für immer im Broker stehen. Jetzt sieht `uninstall` nach dem
  Freigeben und dem Anhalten des Dienstes am Broker nach, was unter
  `<Präfix>/` zurückbehalten ist, räumt genau die eigenen Themen ab — ein
  fremdes Thema unter demselben Präfix bleibt — und sieht danach noch einmal
  nach; nur wenn nichts mehr steht, meldet es `<OK>`. Lässt sich nicht
  nachsehen, werden alle Themen der Bremse vorsorglich abgeräumt, und die
  Meldung sagt das. Das gilt auch bei abgeschalteter MQTT-Ausgabe. Wie
  `mosquitto_sub` bei abgelaufener Frist endet (Rückgabe 27), ist nach der
  Handbuchseite nachgebaut, nicht an einem echten Broker gemessen.

## Neu in 0.9.22

In WSL gemessen (Prüfstand `Pruefung-Einspeisebremse-0.9.22`, 58 Fälle, 101
Prüfzeilen, alle grün; jede Korrektur einzeln zurückgebaut und geeicht),
nicht am Gerät:

- **`dienst.sh` liest die LoxBerry-Wurzel, statt sie auszurechnen.** Bis
  0.9.21 galt „drei Ebenen über dem eigenen Ablageort", der Ordnername war
  fest `einspeisebremse`, und gestartet wurde das `eb_dienst.php` **neben**
  dem Skript. Ein `start` aus einem Prüfarchiv unter
  `<LoxBerry-Wurzel>/pruefung/einspeisebremse/bin` startete dadurch den
  Regler **aus dem Archiv** mit PID-Datei und Konfiguration der Anlage —
  fremder Code gegen dieselben Wechselrichter. Jetzt: zuerst `$LBHOMEDIR`,
  sonst aufwärts bis zu einem Ordner mit `config/plugins`, `data/plugins`
  **und** `config/system/general.json`; findet sich keiner, endet das Skript
  mit `FEHLER` (`status` mit 4), ohne etwas anzulegen, zu starten oder
  anzuhalten. Es gibt keinen Rückfall auf feste Ebenen und keinen festen
  Pfad. Der Ordnername kommt aus dem Ablageort (eine Zweitinstallation heißt
  `einspeisebremse01`).
- **Aus einem ausgepackten Archiv wird nichts gestartet oder angehalten.**
  `start`, `stop` und `restart` laufen nur aus
  `<LoxBerry-Wurzel>/bin/plugins/<ordner>`; `status` gibt von dort aus
  Auskunft über den Dienst der Anlage. Bis 0.9.21 startete ein `restart`
  aus dem Archiv einen zweiten Dienst neben dem laufenden.
- **Ordner werden erst beim Start angelegt, nach der Prüfung der
  Upgrade-Marke** — nicht mehr bei jedem Aufruf. Bis 0.9.21 legte in der
  Upgrade-Lücke schon ein `status` den eben geleerten Datenordner wieder an.
- **Der Dienst wird argumentweise erkannt**: `php` mit genau dem Dienstskript
  der Anlage als einzigem Argument. Bis 0.9.21 galt jeder Prozess unter der
  Nummer der PID-Datei, dessen Befehlszeile auf `eb_dienst.php` endete — ein
  `tail -f …/eb_dienst.php` wurde als Dienst gemeldet und von `stop`
  beendet, und `start` startete keinen.
- **Die Upgrade-Marke gilt höchstens 300 s aus der Zukunft** (bis 0.9.21
  unbegrenzt), Markeninhalt und Uhr werden als Zahl geprüft, bevor gerechnet
  wird; ohne lesbare Uhr wird nicht gestartet. Bis 0.9.21 ging die Ausgabe
  von `date` ungeprüft in die Rechnung — eine Ausgabe der Form
  `a[$(befehl)]` führte den Befehl aus (gemessen mit einem untergeschobenen
  `date`).
- **Hakenskripte und Deinstallation** (`preupgrade.sh`, `postinstall.sh`,
  `postupgrade.sh`, `uninstall/uninstall`): ohne fünftes Argument und ohne
  `$LBHOMEDIR` dieselbe Suche mit `general.json` statt „zwei bzw. drei Ebenen
  über dem Ablageort"; ohne Wurzel `<WARNING>`/`<FAIL>`, nichts getan,
  Rückgabe 1. Vorher schrieb die Deinstallation in einem fremden Baum
  dessen Konfiguration um, schickte einen Durchlauf los und löschte Daten.
  Wie vom Installer aufgerufen (mit Argumenten) ändert sich nichts.
- **Die Bibliothek** verlangt bei der Wurzelsuche ebenfalls
  `config/system/general.json` und nimmt `$LBHOMEDIR` nur, wenn darunter
  `config/plugins` liegt. `eb_dienst.php` lädt die Bibliothek des **eigenen**
  Ordners; bis 0.9.21 lud eine Zweitinstallation die der ersten.
- **Ein ausgepacktes Archiv bleibt bei sich.** Liegt die Bibliothek nicht in
  `<LoxBerry-Wurzel>/webfrontend/html/plugins/<ordner>`, arbeitet sie
  ausschließlich im eigenen Ordner (Konfiguration, Daten, Protokoll) — es sei
  denn, der Aufrufer nennt `LBHOMEDIR` **und** `LBPPLUGINDIR` ausdrücklich.
  Bis 0.9.21 nahm sie unter einer echten Wurzel die Pfade der Anlage.
- **Ohne LoxBerry-Wurzel gibt es keinen Broker**: der Dienst hört und sendet
  dann nichts über MQTT und sagt es einmal im Protokoll. Bis 0.9.21 las er
  `general.json` an der Wurzel des Dateisystems und stellte sonst auf
  `localhost:1883`.
- **Sprachdateien** kommen nur aus dem eigenen Ordnernamen (bzw. dem eigenen
  Archiv); eine Zweitinstallation las bisher die der ersten.
- **Waisen und PID-Datei argumentweise**: `postinstall.sh` sucht verwaiste
  Dienste nicht mehr mit `pgrep -f`, und `uninstall` prüft vor `kill -9`
  dieselben drei Merkmale wie `dienst.sh` (PHP, genau das eigene
  Dienstskript, kein weiteres Argument) und den Dienstbenutzer. Vorher wurde
  ein `tail -f` auf die Dienstdatei beendet.

Unverändert: Regelung, MQTT, Retain (`notfall`, `anlass`, `wirkung` bleiben,
wie sie sind — die Entscheidung dazu steht aus).

## Neu in 0.9.21

- **Der Stellbefehl über MQTT geht ohne Retain hinaus.** Bis 0.9.20 blieb
  der zuletzt gestellte Wert im Broker stehen, und der Broker stellte ihn bei
  jeder Neuverbindung des Geräts oder eines weiteren Abnehmers erneut zu —
  auch Stunden später, wenn er längst nicht mehr galt. Das betrifft das
  Stellen, die Freigabe beim Ausschalten und beim Deinstallieren und den
  Speicher-Sollwert; HTTP und SunSpec waren nie betroffen. Beim Beenden des
  Dienstes wird weiterhin nichts gestellt.
- **Ein alter zurückbehaltener Stellwert wird einmal abgeräumt** — beim
  ersten Stellen nach dem Update, je Broker und Thema. Das Abräumen ist eine
  leere Nachricht mit Retain, und die erreicht auch das Gerät. Deshalb sieht
  die Bremse zuerst nach, ob dort wirklich ein zurückbehaltener Wert steht,
  und schickt den gültigen Wert unmittelbar hinterher (im Prüfstand in drei
  Läufen 29 bis 86 ms später, mit nachgebautem `mosquitto_pub`). Lässt sich
  nicht feststellen, ob etwas dasteht, wird nichts
  abgeräumt. Ein Thema mit Platzhalter (`{W}` im Thema) und ein früher
  eingetragenes, inzwischen geändertes Thema prüft die Bremse nicht; der
  Reiter *Test* (MQTT) zeigt je Stellglied, was nachgesehen ist, und die
  beiden Befehle, mit denen man es selbst tut.
- Nach einem Dienststart kann das **erste Stellen bis zu etwa zwei Sekunden
  später** hinausgehen, einmal je MQTT-Stellglied, solange noch nicht
  nachgesehen ist.
- **MQTT-Stellglieder bekommen ihre Grenze regelmäßig erneut.** Ohne Retain
  bekäme ein Gerät, das neu startet oder die Verbindung verliert, seine Grenze
  erst bei der nächsten Änderung — bei stehender Grenze womöglich stundenlang
  nicht. Neu je Stellglied: **MQTT: erneut senden alle (s)**, Vorgabe 300,
  `0` = aus, weniger als 10 s geht nicht. Gesendet wird nur, solange
  gedrosselt wird (Anteil unter der Spitzenleistung; ohne eingetragene
  Spitzenleistung: solange die Gesamtgrenze unter dem Freigabewert liegt), und
  jede Änderung setzt die Frist zurück. **Achtung Flash-Verschleiß:** manche
  Adapter und Wechselrichter schreiben jede empfangene Grenze dauerhaft in
  ihren Speicher — dort den Abstand groß wählen oder auf 0 stellen und am
  Gerät eine eigene Rückfallgrenze einstellen. Bestehende Einstellungen
  bekommen die 300 s beim nächsten Dienststart eingetragen. Der
  Speicher-Sollwert wird nicht aufgefrischt.
- **`stellerN/ok`, `speicherok` und `ersatz` gehen nicht mehr retained
  hinaus.** Das sind Aussagen des Dienstes über sich selbst — ob der eigene
  Stellaufruf abgesetzt werden konnte und ob er den Hauptzähler für
  ausgefallen hält. Zurückbehalten stünde „in Ordnung" auch dann noch da,
  wenn der Dienst längst tot ist (Hausstandard, Entscheidung vom 19.09.2026).
  Der alte zurückbehaltene Wert wird beim Dienststart abgeräumt, wie die
  übrigen nicht mehr retained gesendeten Themen. Gerätezustände (`tat`,
  `ein`, `ziel`, `notfall`, `anlass`, `wirkung` …) bleiben retained.

## Neu in 0.9.20

Am 17.09.2026 an der installierten 0.9.19 gemessen und behoben:

- **Beim Aktualisieren gingen Verlauf und Monatsbilanz verloren.** Der
  Installer legt den Minutentakt rund eine Minute vor `postinstall.sh` an; am
  08.09.2026 startete er in dieser Lücke den neuen Dienst (03:32:00,
  `postinstall.sh` erst 03:32:24). Der Dienst legte Verlauf und Bilanz neu an,
  und die Rettung hielt sie für schon vorhanden. `preupgrade.sh` legt jetzt
  eine Marke, solange sie liegt, startet `dienst.sh` nicht; `postinstall.sh`
  holt die Sicherung dann ohne Rückfrage zurück und entfernt die Marke vor dem
  Start. Die Konfiguration war nicht betroffen — sie kam aus der Zweitschrift.
- **`online` ist wieder 1 oder 0.** 0.9.18 hatte daraus `1;<Zeitstempel>`
  gemacht; ein Baustein, der auf 1 prüft, sah seitdem keine 1 mehr. Der
  Zeitstempel steht jetzt dort, wo der Hausstandard ihn vorsieht:
  `status/ts`, dazu `status/ok` (1 = der Durchlauf hatte einen Zählerwert) und
  `status/zaehler` (0 bis 999). Alle vier gehen bei jedem Durchlauf und nie
  retained hinaus.
- **Alte zurückbehaltene Messwerte werden abgeräumt.** Bis 0.9.17 ging alles
  retained hinaus; im Broker lagen deshalb noch `netz`, `erzeugung`, `grenze`,
  `online` und vier weitere aus der Zeit vor dem Upgrade am 08.09. Beim
  Dienststart wird jedes Thema, das nicht mehr retained ist, einmal mit
  leerer Nutzlast gelöscht.
- **Die Themen-Tabelle sagt je Thema, ob es retained ist.** Dieselbe Funktion
  entscheidet beim Senden.
- **Fehlende Einstellungen werden beim Dienststart eingetragen**, einmal und mit
  Protokollzeile (am Gerät fehlten `q_erzeugung2` und `q_erzeugung3`).
- **Reiter Test:** „Tragen alle Formulare das Merkmal?" zählte seinen eigenen
  Suchtext mit und meldete 20 von 21; „Ist die Anlagenleistung bekannt?"
  kreuzte ohne Stellglied ein zweites Mal für dieselbe Ursache und ist dann
  ein Hinweis.
- Die unwirksame `appearance: menulist` ist aus der Stilvorlage entfernt.
- **Das Gateway-Abo kommt mit.** Bis 0.9.19 musste `einspeisebremse/#` von
  Hand im MQTT-Gateway eingetragen werden; am Gerät stand es nicht da, am
  Miniserver kam über MQTT nichts an. Das Plugin legt jetzt
  `mqtt_subscriptions.cfg` in seinen Konfigurationsordner, die das Gateway
  selbst liest, und führt sie beim Speichern und beim Dienststart auf den
  eingestellten Präfix nach.
- **Das Lebenszeichen geht höchstens alle 30 s hinaus**, nicht bei jedem
  Takt: mit dem Abo schickt das Gateway jeden Wert als eigenen Aufruf an den
  Miniserver.
- **HTTP-Antwort: `ERSATZ` wurde von Loxone nie gefunden.** Die zweite Zeile
  begann mit `ERSATZ=`, der Suchtext lautet aber `\i;ERSATZ=\i`. Sie beginnt
  jetzt mit `STATUS;`. Die Prüfzeile im Reiter Test nimmt die Felder aus der
  Vorlage statt aus der Antwort und hätte es so gefunden. Neu in derselben
  Zeile: `OK` (Zählerwert vorhanden) und `ZAEHLER` (0 bis 999) — für diese
  beiden die Vorlage im Reiter *Loxone* neu importieren.
- **Eine Quelle, die `null` liefert, heißt jetzt `wert_null`** und eine, deren
  Schlüssel fehlt, `pfad_fehlt`. Bisher hieß beides `pfad_leer`, und das las
  sich wie ein leeres Eingabefeld. Fronius liefert `P_Akku: null`, wenn der
  Speicher ruht; `null` wird nicht in 0 umgedeutet.

## Neu in 0.9.19

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 0.9.18 stand hier
  `appearance: menulist` — dann zeichnet ihn der Browser, und er sieht in
  jedem Browser anders aus. Am 05.09.2026 im Browser gegen die Rahmen-CSS des
  Geräts gemessen (LoxBerry 4.0.0.15) und auf den Hausstandard
  umgestellt
  (`Regeln/04`). Sonst ist an dieser Fassung nichts geändert.

## Wofür

Die Bremse hält die Einspeisung auf dem Wert, der erlaubt ist — null bei
einer Nulleinspeisung, 70 Prozent der Modulleistung bei einer
70-Prozent-Regelung. Sie liest den Netzzähler, rechnet den Überschuss aus
und gibt eine Grenze an die Wechselrichter weiter.

Die Reihenfolge ist der eigentliche Gewinn: Was in den Speicher passt,
wandert in den Speicher. Abgeregelt wird nur, was dort nicht mehr
hineingeht. Eingelagerter Strom kostet keinen Ertrag, abgeregelter schon.

## Was sie nicht tut

**Sie erfindet keine Register.** Welche Adresse ein Wechselrichter für
einen Fernsollwert annimmt, weiß der Hersteller und sonst niemand. Ein
geratenes Modbus-Register schreibt im besten Fall ins Leere und im
schlechtesten in eine Werkseinstellung, die nachher niemand mehr findet.
Deshalb gibt es keine eingebaute Geräteliste: Sie tragen Adresse und Inhalt
ein und setzen einen Platzhalter dorthin, wo der Wert hingehört —
`{W}`, `{KW}` oder `{PROZENT}`.

**Sie nimmt keinen Sollwert von außen entgegen.** Aus Loxone lässt sich die
Regelung ein- und ausschalten, mehr nicht. Das Wortzeichen steht offen in
der Adresse; wer damit die Grenze setzen könnte, könnte die Anlage
abschalten. Der Schalter dagegen ist im schlimmsten denkbaren Fall harmlos:
er *gibt frei*, er drosselt nicht.

**Sie greift nicht von selbst ein.** Nach der Installation läuft der Dienst
und misst — die Regelung selbst ist aus. Erst wenn Sie sie einschalten,
geht ein Befehl hinaus. Und einschalten lässt sie sich nur, wenn nichts
Wesentliches fehlt; die Liste steht im Reiter *Test* unter „Einstellung
prüfen“.

## Drei Dinge, die schiefgehen und hier nicht schiefgehen sollen

**Das Vorzeichen.** Im ganzen Plugin gilt *plus = Bezug, minus =
Einspeisung*. Umgedreht wird genau einmal, beim Einlesen. Steht der Haken
falsch, regelt die Bremse exakt verkehrt herum — deshalb zeigt der Reiter
*Test* den Zählerwert so, wie die Regelung ihn sieht.

**Der ausgefallene Zähler.** Kein Messwert heißt nicht „alles in Ordnung“.
Nach der eingestellten Zeit fährt die Anlage auf den Notwert; der darf die
Grenze nur senken, nie anheben. Ein Wert jenseits jedes Hausanschlusses
wird **verworfen**, nicht auf null gebogen — eine Null hieße „keine
Einspeisung“, und daraufhin gäbe die Regelung frei.

**Die Quittung, die keine Wirkung ist.** Ein Wechselrichter, der den
Sollwert mit HTTP 200 quittiert und dann ignoriert, ist der unangenehmste
Fall: alles meldet Erfolg, und die Auflage ist trotzdem verletzt. Nach der
eingestellten Wartezeit wird deshalb am Zähler nachgesehen, ob die
Einspeisung wirklich gefallen ist. Ist sie das nicht, steht es oben als
Warnung und geht als `WIRKUNG = -1` nach Loxone.

## Beim Ausschalten

Wird die Regelung ausgeschaltet — in der Oberfläche, über Loxone oder beim
Deinstallieren —, wird die Anlage **einmal freigegeben**. Sonst bliebe sie
auf der zuletzt gestellten Grenze stehen, und der fehlende Ertrag fiele
erst Wochen später auf. Erreicht die Freigabe nicht alle Geräte, wird sie
im nächsten Durchlauf wiederholt und der Fehlschlag protokolliert.

Beim *Beenden des Dienstes* bleibt die Grenze dagegen bestehen. Ein Dienst,
der beim Beenden alles freigibt, hebt genau in dem Augenblick eine Auflage
auf, in dem niemand mehr hinsieht — beim Neustart, beim Update, beim
Absturz.

## Mehrere Wechselrichter

Die Gesamtgrenze wird im Verhältnis der Anteile aufgeteilt, gedeckelt durch
die eingetragene Spitzenleistung. Stößt ein Gerät an seine Spitze, wandert
der Rest zu den anderen — so lange, bis nichts mehr übrig ist oder niemand
mehr Luft hat. Ohne diese Runden summierten sich die gestellten Grenzen auf
weniger als die erlaubte, und die Anlage bliebe dauerhaft zu scharf
abgeregelt.

## Prüfstand

* `php bin/eb_dienst.php --selbsttest` — 142 Fälle des Regelkerns, ohne
  Anlage und ohne Netz. Die Zahl steht in der Schlusszeile des Laufs;
  wer sie hier ändert, liest sie dort ab.
* Reiter *Test*, **Selbstprüfung** — eine stehende Liste, die ohne Loxone
  beantwortet, ob die Einrichtung trägt. Je Zeile eine Frage mit Häkchen
  oder Kreuz; die Zusammenfassung zählt die Kreuze, nicht die Häkchen.
* `php bin/eb_dienst.php --probe` — die Messwerte einmal lesen und zeigen.
* `php bin/eb_dienst.php --einmal` — ein Durchlauf im Vordergrund.
* Reiter *Test*, **Trockenlauf** — was die Regelung jetzt täte, samt der
  vollständigen Befehle, die dabei hinausgingen. Ohne dass etwas gestellt
  wird.

Die Oberfläche ist gegen PHP 7.4.33 und 8.2.32 gerendert worden: alle fünf
Reiter, ohne Meldung, ohne unübersetzten Schlüssel, `sm-active`
serverseitig gesetzt.

## Ordner

```
bin/            Dienst und Startskript
cron/           Minutentakt — startet den Dienst, falls er steht
dpkg/apt        mosquitto-clients (wird von LoxBerry als root installiert)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Regelkern, Bibliothek und Endpunkt; htmlauth = Oberfläche
uninstall/      gibt die Anlage frei, bevor das Plugin verschwindet
```

## Fassung 0.9.16 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/eb_lib.php:927`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. **Hier war der
Fehler wirksam, nicht nur latent**: `bin/eb_dienst.php` ruft `eb_log()` in
seiner Warteschleife. Das Protokoll wuchs auf der Ramdisk unbegrenzt weiter,
und niemand sah es.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.


## Fassung 0.9.18 — was eine Durchsicht findet, wenn die Prüfkette grün ist

Am 04.09.2026 ist diese Linie noch einmal vollständig durchgesehen worden.
Das Freigabetor meldete dabei **14 Prüfungen, 0 Beanstandungen** — und
darunter lagen sieben schwere Befunde. Die wichtigsten:

**Der Aktualisierungsfall verlor die Langzeitwerte.** `postinstall.sh`
startete den Dienst und holte den geretteten Verlauf erst 24 Zeilen später
zurück. Gemessen: der Dienst legt `verlauf.json` und `bilanz.json` 0,41 s
nach seinem Start selbst an; die Rettung fand dann eine nicht leere Datei
vor, übersprang sich selbst — und löschte die Sicherung trotzdem. Bei
**jedem** Update gingen Verlauf und Monatsbilanz verloren. `bilanz.json`
stand außerdem in keiner Rettungsliste. Jetzt steht die Rettung vor dem
Dienststart, sichert beide Dateien und prüft gegen den *Inhalt* statt gegen
„nicht leer".

**Eine angebrochene Konfiguration kostete alles.** Die Selbstheilung
entschied nach der Form (`""` oder `{}`) statt nach dem Inhalt. Eine halb
geschriebene Datei — Stromausfall, volle Ramdisk — ist weder das eine noch
das andere: die Anlage fiel lautlos auf Werkseinstellung, und der nächste
Blick in die Oberfläche kopierte diesen Werkszustand über die letzte heile
Zweitschrift. Jetzt entscheidet der Inhalt, die beschädigte Datei bleibt als
`.kaputt` liegen, es gibt genau eine Protokollzeile, und der Reiter *Test*
sagt, was war — auch dann, wenn die Heilung schon gegriffen hat.

**Die Sicherung prüfte keinen einzigen Wert.** Eine Datei mit einem
einzigen Schlüssel wurde angenommen; die übrigen 31 gingen still auf Werk,
darunter das Aktionstoken. Ein Aktionstoken als Feld statt als Text
überlebte, und `(string)` machte daraus das Wort `Array` — damit stand der
Endpunkt für jeden offen, der `?token=Array` schreibt. Jetzt müssen alle
Schlüssel da sein, jeder Wert wird gegen dieselbe Erwartung geprüft wie im
Formular, und die Datei trägt einen lesbaren Kopf mit dem Hinweis, dass sie
ein Geheimnis enthält.

**Ein unlesbarer Zahlenwert fiel auf die Untergrenze zurück**, nicht auf
die Vorgabe. `frei_w` wurde damit 0 statt 100 000 — und eine 0 dort schaltet
die Anlage beim Ausschalten der Bremse ab. Der Kommentar an der Vorgabe
benannte genau diese Gefahr.

**`dienst.sh stop` meldete Erfolg, ohne die Wirkung zu prüfen.** Scheiterte
das `kill`, wurde die PID-Datei trotzdem entfernt — und der Minutentakt
startete daraufhin einen zweiten Dienst neben den laufenden. Zwei Dienste
stellen unabhängig voneinander Grenzen an dieselben Wechselrichter. Jetzt
wird nachgesehen, ob der Prozess wirklich weg ist; sonst bleibt die
PID-Datei liegen und der Rückgabewert ist 1.

**Der unangemeldete Endpunkt legte die Konfiguration an**, bevor das
Wortzeichen geprüft war. Gemessen mit falschem Token: der Ordner entstand
und die Konfiguration kam aus der Zweitschrift zurück. Er liest jetzt nur.

**Die Wirkungsprüfung konnte in genau dem Fall nie auslösen, für den es sie
gibt.** Nimmt ein Wechselrichter den Wert an und regelt trotzdem nicht,
blieb der Zustand auf *drosseln*, und das Messfenster wurde in jedem Takt
neu gestartet — die Zeile *KEINE WIRKUNG* erschien nie. Fenster und Befehl
sind jetzt getrennt: gestellt wird weiter bei jedem Durchlauf, gemessen wird
ab der echten Änderung.

Dazu zehn mittlere und zwölf kleinere Punkte, darunter:

* **MQTT folgt dem Hausstandard**: Zustände retained, Messwerte mit
  Zeitbezug nicht, das Lebenszeichen nie. `online` trägt jetzt seinen
  Zeitstempel im Wert und geht bei **jedem** Durchgang hinaus — vorher stand
  nach einem Absturz für immer `online=1` mit frischen Zahlen im Broker.
* **Der Reiter *Test* fragt nur noch das Netz, wenn er offen ist.** Der
  Selbstaufruf lief bisher bei jedem Seitenaufruf auf jedem Reiter mit; er
  geht jetzt außerdem über `127.0.0.1` statt über den Host-Kopf, in dem das
  Aktionstoken stand. „Keine Antwort" ist ein Hinweis, kein Kreuz.
* **Drei Prüfzeilen mehr**: ist die Konfiguration heil, tragen alle
  Formulare das Merkmal gegen fremde Absender, ist jeder Suchtext eindeutig.
* **Die Suchtexte tragen das Trennzeichen** (`\i;NAME=\i\v`). `ALTER=`
  steckte in `MESSALTER=`; dass es nicht schiefging, lag allein an der
  Reihenfolge in der Antwortzeile.
* **Für Stellglieder gibt es die Doppelt-Prüfung**, die es für die
  Erzeugungsquellen längst gab. Zwei Einträge auf demselben Gerät
  halbierten die erlaubte Leistung, ohne dass irgendwo ein Fehler stand.
* **Breite Tabellen stehen im Rollbehälter.** Bei sechs Spalten mit
  Eingabefeldern lag die letzte Spalte außerhalb und war unerreichbar — bei
  den Quellen der Haken *invertieren*, bei den Stellgliedern *stilllegen*.
* **Leere Felder löschen nichts mehr.** Ein geleertes Faktorfeld wurde
  still 1 (bei einem Zähler in kW ein Messfehler um drei Größenordnungen),
  ein geleertes MQTT-Thema still `einspeisebremse`, ein großgeschriebenes
  Thema still kleingeschrieben. Alle drei behalten jetzt den alten Wert und
  sagen es.
* **Nach dem Zurückspielen zeigt die Seite den neuen Stand.** Vorher standen
  in jedem Feld weiter die alten Werte, und ein anschließendes Speichern
  machte das Zurückspielen rückgängig.
* **Alle Beispieladressen liegen im Dokumentationsbereich** nach RFC 5737
  (`192.0.2.0/24`) — vorher standen 25 Adressen aus einem echten Heimnetz
  in Hilfetexten, Kommentaren, Vorgabewerten und Selbsttestfällen.

Der Regelkern zählt jetzt **142** statt 134 Fälle; die acht neuen prüfen
das Wirkungsfenster, an dem der siebte Befund hing. Was eine laufende
Anlage braucht, ist damit nicht geprüft — es steht als Prüfzeile im Reiter
*Test* und in der Übergabe.
