# Changelog
## 1.8.0 (10.11.2026)
- Veröffentlichung unter MIT-Lizenz. Neutrale Beispiele und Bezeichnungen (Plugin-Name „Bonus-System“, Beispielartikel ART-1001), Credit-Zeile im Reiter „Danke“, englische README.
## 1.7.3 (10.11.2026)
- Fix: Das Admin-Skript trägt jetzt eine Versionsnummer in der Adresse (entrypoints.json). Dadurch holt sich der Browser nach einem Update die neue Oberfläche, statt eine alte aus dem Zwischenspeicher zu zeigen (Reiter fehlten).
- Bei jeder neuen Version die Nummer in `src/Resources/public/administration/.vite/entrypoints.json` mit hochzählen.
## 1.7.2 (10.11.2026)
- Neues Plugin-Symbol (Stern in grüner Scheibe auf CI-Blau). Lizenzangabe aus der composer.json entfernt.
## 1.7.1 (10.11.2026)
- Reiter „Danke“: Ideengeber und Hersteller Markus Czarnetzki (Oberhausen, NRW), macdata.de und Copyright-Zeile. composer.json mit Autor und Webseite.
## 1.7.0 (10.11.2026)
- Admin-Modul: neuer Reiter „Danke“ mit Mitwirkenden und einem versteckten Easter-Egg (Konfetti), ohne Auswirkung auf Punkte.
## 1.6.1 (10.11.2026)
- Punkte-Box neu gestaltet: Stern-Symbol, Guthaben groß mit Euro-Wert, Level-Kennzeichen, Hinweis-Chip „+X Punkte“, Fortschrittsbalken zum nächsten Level. Die Optik greift jetzt auch im Off-Canvas (vorher ohne Gestaltung).
## 1.6.0 (10.11.2026)
- Einstellungen jetzt im Admin-Modul „Bonuspunkte“ (Reiter „Einstellungen“): alle Optionen als Formular, Stufen und Gratisprodukte als Tabellen mit Hinzufügen und Entfernen, Hinweis zur Rückvergütung, Prüfung der Eingaben. Die Plugin-Konfiguration von Shopware bleibt gültig und schreibt dieselben Werte.
- Knöpfe im Modul haben eine Optik; Status „aktiv“ für Kunden mit Punkten.
## 1.5.2 (10.11.2026)
- Fix: Das Admin-Modul wurde nicht geladen, weil in der Adresse des Skripts der Schrägstrich fehlte (entrypoints.json).
## 1.5.1 (10.11.2026)
- Admin-Modul überarbeitet: Kundendetails als Seitenfenster (Liste bleibt sichtbar), Live-Suche, Filter (im Programm, mit Punkten, Werber, gesperrt, alle), sortierbare Spalten, Kennzahlen als Kacheln.
- Schnellbuchung: Chips (+50, +100 …), Vorlagen für die Begründung, Vorschau des neuen Stands, Rückfrage bei Minusbuchungen und großen Beträgen.
- Sprung zu Kunde und Bestellung, Fortschritt zum nächsten Level, Verknüpfung zu den Einstellungen, CSV-Export (Excel) für die Buchhaltung.
- Nachbuchen erst nach Vorschau möglich.
## 1.5.0 (10.11.2026) – Etappe 3 bis 6
Kern
- Fix: Eingelöste Punkte werden auch dann abgebucht, wenn der Payload der Bestellposition fehlt (Berechnung aus dem Rabattbetrag).
- Fix: Bricht nur die Zahlung ab, bleiben eingelöste Punkte abgebucht; zurück gibt es sie bei Erstattung oder Stornierung der Bestellung.
- Fix: Die Punktegutschrift einer Bestellung wird nicht mehr durch eine Einlösebuchung verhindert.
Etappe 3, Stufen und Regeln
- Stufen-Modell: lebenslang, rollierend (12 Monate) oder aus (reines Punkteprogramm). Beliebig viele Stufen als Text (Name;Schwelle;Faktor;Rabatt %).
- Treuerabatt je Stufe als eigene Position im Warenkorb. Einstellung, ob er mit der Punkte-Einlösung kombiniert werden darf.
- Mindest-Warenwert zum Einlösen mit Hinweis, wie viel noch fehlt.
- Teilnahme: automatisch oder per Beitritt (Checkbox bei Registrierung und Kasse, Knopf unter „Meine Punkte“). Einschränkung auf Kundengruppen. Sperre einzelner Kunden.
Etappe 4, Einlösen überall und Aktionen
- Einlösen auch an der Kasse (mit Regler) und im Off-Canvas (alle möglichen Punkte mit einem Klick).
- Punkte für Registrierung, Newsletter-Anmeldung, freigegebene Produktbewertung und Geburtstag (je einstellbar).
Etappe 5, Verwaltung
- Admin-Modul „Bonuspunkte“ unter Marketing: Kennzahlen, Kundenliste, Detail mit Buchungen, manuelles Buchen, Sperren, Nachbuchen von Bestellungen.
- Neuer Befehl maxxpet:bonus:sperre.
Etappe 6, Gratisprodukte und Empfehlung
- Gratisprodukte nach Warenwert und Level, automatisch oder zur Auswahl für den Kunden.
- Empfehlungssystem: persönlicher Code und Link, dauerhafte Provision in Punkten auf alle Bestellungen des Geworbenen, optionaler Willkommensbonus.
## 1.1.0 (10.11.2026)
- Punkte einlösen im Warenkorb: Box mit Kontostand, Eingabefeld und Schieberegler (ab 100 Punkten, einstellbar). Der Rabatt erscheint als eigene Position in Warenkorb und Bestellung.
- Die Punkte werden beim Bestellen abgebucht und bei Storno/Erstattung zurückgegeben. Eingelöste Punkte senken den Status nicht.
- Vorschau „Mit dieser Bestellung sammelst du X Punkte“ im Warenkorb, auch für nicht angemeldete Besucher (Hinweis auf Kundenkonto).
- Neue Einstellungen: Mindestmenge zum Einlösen, Höchstanteil am Warenwert (0 = keine Grenze).
- Fix: Einlösebuchungen verhindern nicht mehr die Gutschrift der Bestellpunkte.
## 1.0.1 (10.11.2026)
- Neu: „Wert eines Punktes in Euro“ (Standard 0,03 = 3 % Rückvergütung); die Seite „Meine Punkte“ zeigt das Guthaben in Euro.
- Standard Sperrfrist jetzt 0 Tage (Punkte sofort nutzbar nach „Bezahlt“), Level-Faktoren 1 / 1,15 / 1,3. Bei bestehender Installation die Werte in der Konfiguration selbst anpassen.
- Text „Punkt(e)“ korrigiert, Kachel „Ausstehend“ nur bei Sperrfrist.
## 1.0.0 (10.11.2026)
- Erste Version (Etappe 1): Punkte-Journal, Gutschrift bei bezahlter Bestellung mit Sperrfrist, Rückbuchung bei Storno/Erstattung, drei Level mit Multiplikator, Kundenkonto-Seite „Meine Punkte“, Befehle konto/buchen/abgleich.
