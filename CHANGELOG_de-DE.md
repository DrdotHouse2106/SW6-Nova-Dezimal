# 1.0.8
- Sicherheit: In Verkaufskanälen, in denen Nova Dezimal aus ist, werden Dezimal-Angaben aus dem Browser nicht mehr in die Bestellung übernommen
- Sicherheit (weclapp-Korrektur): nur Bestellungen aus Kanälen mit aktivem Nova Dezimal; jede Position wird gegen den Warenkorb-Status, den Positionspreis und die Artikeleinstellungen (Höchstwert, Stückmaß) geprüft, unplausible Positionen werden nicht geändert und als `fehler` markiert
- Sicherheit: Mindestwert, Höchstwert und Schrittweite gelten auch, wenn die Dezimal-Angaben einer Position (z. B. per API) entfernt wurden
- weclapp: Verbindung nur noch über `https://`, keine Weiterleitungen; fehlende Menge in der weclapp-Antwort bricht den Lauf nicht mehr ab
- weclapp: Status und Info an der Bestellung werden nur noch geschrieben, wenn sich etwas ändert (bisher alle 10 Minuten bei „wartet“/„test“)

# 1.0.7
- Produktseite: Eingabefeld genau so hoch wie der Kaufen-Button

# 1.0.6
- Produktseite: Eingabefeld als eine Einheit mit durchgehendem Rahmen (−  1,50 m²  +), keine abgeschnittenen Ecken mehr bei abgerundeten Mengenfeldern im Theme

# 1.0.5
- Seiten-Warenkorb (Off-Canvas): Wert lässt sich jetzt direkt ändern (Enter oder „Übernehmen“, ohne Neuladen der Seite)
- Warenkorbseite: „Übernehmen“ steht als eigener Button unter dem Eingabefeld statt eingequetscht daneben

# 1.0.4
- Warenkorb: beim Ändern des Wertes erscheint der Hinweis, wenn auf Mindest-/Höchstwert oder Schrittweite angepasst wurde
- Diagnose-Kommentar im Seitenquelltext entfernt

# 1.0.3
- Warenkorb: Produktdaten für Dezimal-Positionen werden beim erneuten Laden selbst nachgeladen – Prüfung von Min/Max/Schritt und Preis laufen damit bei jeder Berechnung (vorher nur beim Hinzufügen)

# 1.0.2
- Warenkorb: Preis der Position wird zusätzlich als Preisdefinition gesetzt (Wert × Einheitspreis bleibt bei jeder Neuberechnung erhalten), Status zur Fehlersuche an der Position
- Produktseite: der große Preis zeigt den Gesamtpreis für den gewählten Wert, der Preis je Einheit steht klein darunter
- Neu: „= x laufende Meter“ bei Rollenware in m² (Rollenbreite aus Feld, Feldzuordnung oder Artikelname)
- Warenkorb: unter dem Wert „× Preis / Einheit“ und laufende Meter

# 1.0.1
- Neue Einstellungen „Feldzuordnung“, „Zusätzlicher Payload-Schlüssel“ und „Nicht gleichzeitig mit Plugin“: vorhandene Zusatzfelder und Mail-Vorlagen weiter nutzen, ohne feste Namen im Code

# 1.0.0
- Erste Version: Verkauf mit Dezimalwert (z. B. 2,35 m²), Platten mit festem Maß je Stück (Preis pro m² × Fläche), Wert im Warenkorb änderbar
- Optional: Menge und Einzelpreis im weclapp-Auftrag automatisch korrigieren (mit Testmodus, Startdatum und Status-Freigabe)
