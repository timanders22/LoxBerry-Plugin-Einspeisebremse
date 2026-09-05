# Einspeisebremse

**Null- oder begrenzte Einspeisung für mehrere Wechselrichter und Hybrid-Speicher.**
Misst am Netzzähler, füllt erst den Speicher, regelt erst dann ab.

Version 0.9.18 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

---

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
