# Nova Dezimal

Shopware-6-Plugin (6.6 und 6.7), mit dem Artikel nach Meter, Quadratmeter oder einer anderen Einheit **mit Dezimalwert** verkauft werden (z. B. 2,35 m² Dämmmatte). Außerdem verkauft es **Platten mit festem Maß** stückweise, wenn der Preis pro m² gepflegt ist. Optional korrigiert es den Auftrag in **weclapp**, sodass Menge und Einzelpreis dort nicht mehr von Hand nachgetragen werden müssen.

English: [README.en.md](README.en.md)

## Funktionen

### Freie Menge (Rollen- und Meterware)
- Auf der Produktseite gibt der Kunde statt der Stückzahl einen Wert ein, mit Plus und Minus, z. B. „2,35 m²“. Mindest-, Höchstwert und Schrittweite werden angezeigt, der Gesamtpreis rechnet live mit.
- **Preis = Wert × Einheitspreis.** Die Position hat in Shopware immer Menge 1. Gibt es Staffelpreise, richten sie sich nach dem Wert.
- Legt der Kunde denselben Artikel erneut in den Warenkorb, werden die Werte **addiert** (2 m² + 1,5 m² = 3,5 m²).
- Im Warenkorb kann der Wert geändert werden (abschaltbar). Der Warenkorb zeigt den Preis je Einheit („19,90 € / m²“).
- Werte außerhalb von Min/Max oder zwischen den Schritten passt der Server an und zeigt einen Hinweis. Manipulierte Werte sind damit wirkungslos.
- In Artikellisten erscheint „Details“ statt „In den Warenkorb“, denn der Wert wird auf der Produktseite gewählt.
- Für die Versandkosten zählt das Gewicht je Einheit × Wert.

### Festes Maß je Stück (Platten)
- Im ERP und in Shopware steht der **Preis pro m²**, verkauft wird pro Platte. Das Plugin multipliziert den Preis überall mit der Fläche je Stück: auf der Produktseite, in Listen, in der Suche und im Warenkorb.
- Der Grundpreis zeigt weiter den m²-Preis, wenn die Verkaufseinheit (= Fläche) gepflegt ist.
- Die Stückzahl wird normal gewählt. Darunter steht „2 Stück = 3,00 m²“, und die Gesamtfläche wird an der Position gespeichert.

### weclapp-Korrektur (optional)
weclapp übernimmt aus Shopware nur ganze Mengen. Aus „2,35 m²“ wird dort „1 × 46,77 €“, aus 2 Platten werden „2 × Plattenpreis“.

Alle 10 Minuten setzt Nova Dezimal die Position im weclapp-Auftrag auf die echte Menge in der Einheit, also 2,35 m² bzw. 2 × 1,5 = 3 m². Den Einzelpreis rechnet es so um, dass der Positionsbetrag gleich bleibt. Das passt dann auch zu Lieferanten, die in m² abrechnen.

Schutzregeln:
- **Testmodus** (Standard): Es wird nur protokolliert, was geändert würde.
- Nur Bestellungen ab dem **Startdatum** werden bearbeitet. Es wird beim Aktivieren automatisch gesetzt. Ältere, von Hand korrigierte Aufträge werden nie angefasst.
- Nur Aufträge in **freigegebenen weclapp-Status** werden geändert (Standard: in Erfassung, Auftragsbestätigung gedruckt). Gelieferte oder berechnete Aufträge bleiben, wie sie sind.
- Eine Position wird nur geändert, wenn ihre Menge noch der Shopware-Menge entspricht. Von Hand korrigierte Positionen bleiben unverändert.
- Das Ergebnis steht an der Bestellung, im Tab Zusatzfelder unter „Nova Dezimal (weclapp)“:
  - `ok`, `test`, `wartet` (noch nicht in weclapp angekommen), `uebersprungen`, `fehler`
  - dazu das Detail „Menge × Preis vorher → nachher“

Von Hand starten:
```
bin/console nova-dezimal:weclapp --order=12345 --test   # nur anzeigen
bin/console nova-dezimal:weclapp --order=12345 --write  # wirklich ändern
bin/console nova-dezimal:weclapp                        # alle offenen (wie die geplante Aufgabe)
```

## Einrichtung

1. Plugin installieren und aktivieren.
2. **Artikel einrichten:** Artikel → Zusatzfelder → **Nova Dezimal**

   | Feld | Bedeutung |
   |---|---|
   | Dezimal-Artikel | an = Nova Dezimal ist für diesen Artikel zuständig |
   | Art | „Freie Menge“ (Rollen, Meterware) oder „Festes Maß je Stück“ (Platten) |
   | Einheit | z. B. `m²` oder `m`. Leer = Maßeinheit des Artikels |
   | Bezeichnung | z. B. „Quadratmeter“. Erscheint im Warenkorb und in der Mail |
   | Mindestwert | Bei Rollen die Rollenbreite in m² (1 laufender Meter), mindestens 1 |
   | Höchstwert / Schrittweite / Vorgabewert | Leer = 100 / 0,01 / Mindestwert |
   | Fläche je Stück | Nur bei Platten, z. B. 1,5 für 1000 × 1500 mm. Leer = Verkaufseinheit des Artikels |

   Bei Platten: Preis pro m² pflegen, Verkaufseinheit = Fläche je Platte, Grundeinheit 1, Maßeinheit m².
3. **Vorhandene Daten (optional):** In der Konfiguration unter „Vorhandene Daten“ lassen sich bestehende Produkt-Zusatzfelder als zweite Quelle einbinden („Feldzuordnung“, je Zeile `schlüssel=zusatzfeld`). Außerdem kann der Wert zusätzlich unter einem eigenen Payload-Schlüssel gespeichert werden, für vorhandene Mail-Vorlagen. Mit „Nicht gleichzeitig mit Plugin“ pausiert Nova Dezimal, solange ein anderes Plugin aktiv ist.
4. **weclapp (optional):** Konfiguration → Karte „weclapp“:
   - Adresse und API-Token eintragen und die Korrektur einschalten. Den Testmodus anlassen.
   - Nach ein paar Bestellungen die Einträge „test“ an den Bestellungen prüfen.
   - Dann den Testmodus ausschalten. Die Test-Bestellungen werden danach automatisch richtig korrigiert.

## Grenzen

- Der Lagerbestand zählt Positionen bzw. Stück, nicht Meter oder Quadratmeter.
- Bearbeitet man eine Bestellung im Admin (Positionen ändern, neu berechnen), rechnet Shopware ohne den Dezimalwert neu. Dezimal-Positionen deshalb nicht im Admin nachbearbeiten.
- Bei Platten wird nach dem gespeicherten m²-Preis sortiert und gefiltert (z. B. Preisfilter in Listen), angezeigt wird der Stückpreis.
- Ist Nova Dezimal für einen Verkaufskanal ausgeschaltet, gilt bei Platten der m²-Preis als Stückpreis. „Festes Maß je Stück“ nur mit aktivem Plugin verwenden.

## Häufige Probleme und Lösungen

- **Dezimal-Feld erscheint nicht auf der Produktseite:**
  - Ist „Dezimal-Artikel“ gesetzt?
  - Den Cache leeren.
- **Platte zeigt den m²-Preis als Stückpreis:** Bei „Festes Maß je Stück“ fehlt die Fläche je Stück und die Verkaufseinheit. Ohne Fläche verhält sich der Artikel wie ein normaler Artikel.
- **weclapp-Korrektur meldet „wartet“:** Der Auftrag ist noch nicht in weclapp angekommen. Die Aufgabe versucht es weiter, bis der eingestellte Zeitraum (Standard 14 Tage) vorbei ist.
- **weclapp-Korrektur meldet „keine passende Position“:** Die Artikelnummer in weclapp weicht von der Shopware-Produktnummer ab, oder die Menge wurde schon von Hand geändert.
- **Betrag in weclapp weicht um 1 Cent ab:** weclapp rundet die Position selbst. Das Detail an der Bestellung nennt den Brutto-Betrag vorher und nachher. Die Nachkommastellen des Einzelpreises lassen sich in der Konfiguration erhöhen.

## Lizenz

MIT
