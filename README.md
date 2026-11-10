# Bonus-System für Shopware 6 · Loyalty Bonus System

Treuepunkte für Kunden mit Konto: Punkte für Bestellungen, Stufen mit Faktor und Rabatt, Einlösen im Warenkorb, an der Kasse und im Off-Canvas, weitere Aktionen, Gratisprodukte, Empfehlungssystem und eine Verwaltung im Shopware-Admin.

Loyalty points for customers with an account: points for orders, levels with multiplier and discount, redeeming in the cart, checkout and off-canvas, extra actions, free gifts, referral program and an admin module. **Shopware 6.7**, PHP 8.3. License: **MIT**.

> **Status / Hinweis:** Das Plugin ist in einem Testshop mit Shopware 6.7 erprobt, aber noch nicht breit im Live-Betrieb. Bitte zuerst auf einem Testsystem prüfen und die Datenbank sichern. Nutzung auf eigene Verantwortung (siehe Lizenz). Steuerliche und datenschutzrechtliche Fragen (offene Punkte als Verbindlichkeit, Speicherung von Punktestand und Buchungen) musst du selbst klären.

## Funktionen
- Automatische Punkte bei bezahlten Bestellungen, optionale Sperrfrist (z. B. Widerrufsfrist), Rückbuchung bei Storno und Erstattung
- Stufen als Textliste (Name; Schwelle; Punkte-Faktor; Rabatt in %), Modelle lebenslang, rollierend 12 Monate oder aus
- Einlösen im Warenkorb, an der Kasse und im Off-Canvas (Eingabe oder Regler), Mindestmenge, Mindest-Warenwert, Höchstanteil, Treuerabatt kombinierbar oder nicht
- Teilnahme automatisch oder per Beitritt (Checkbox bei Registrierung und Kasse), Kundengruppen, Sperren einzelner Kunden
- Punkte für Registrierung, Newsletter, freigegebene Bewertung, Geburtstag
- Gratisprodukte ab Warenwert und Stufe (automatisch oder zur Auswahl)
- Empfehlungssystem mit dauerhafter Provision in Punkten
- Seite „Meine Punkte“ im Kundenkonto
- Admin-Modul „Bonuspunkte“ (Marketing): Kennzahlen, Kundenliste, Buchen, Sperren, Nachbuchen, CSV-Export und alle Einstellungen

## Installation
1. ZIP aus dem Release herunterladen (Ordner `MaxxpetBonus` im ZIP), im Admin unter Erweiterungen → Meine Erweiterungen → „Erweiterung hochladen“, installieren und aktivieren. Bei einem Update zusätzlich „Aktualisieren“.
2. Im Shop-Verzeichnis: `bin/console assets:install` und `bin/console cache:clear`.
3. Einstellungen im Admin unter Marketing → Bonuspunkte → Reiter „Einstellungen“ (oder in der Plugin-Konfiguration).
4. Prüfen: `bin/console lint:twig custom/plugins/MaxxpetBonus/src/Resources/views`, `bin/console lint:container`.

Der technische Name (Namensraum, Tabellen, Routen) lautet aus historischen Gründen `MaxxpetBonus` bzw. `maxxpet_bonus_*`; die Bezeichnung im Admin ist „Bonus-System (Treuepunkte)“.

## Wichtige Regeln
- Punkte entstehen, wenn die **Zahlung auf „Bezahlt“** wechselt. Basis ist der Warenwert ohne Versand und nach Rabatten.
- Storno/Erstattung bucht den Verdienst zurück und gibt eingelöste Punkte zurück. Teilerstattungen laufen nicht automatisch (manuelle Buchung).
- Gratisprodukte sind Positionen mit 0 €. Es wird kein Lagerbestand abgebucht.
- Empfehlungscode: Link `…/?bref=CODE` oder Eingabe bei der Registrierung. Bei Double-Opt-In in einer anderen Sitzung kann der Code verloren gehen.
- Das Admin-Skript trägt eine Versionsnummer in der Adresse (`.vite/entrypoints.json`), damit Browser nach einem Update die neue Oberfläche laden. Bei einer neuen Version die Nummer dort hochzählen.

## Befehle
```
bin/console maxxpet:bonus:konto kunde@example.de
bin/console maxxpet:bonus:buchen kunde@example.de 100 "Grund"
bin/console maxxpet:bonus:sperre kunde@example.de [--aufheben]
bin/console maxxpet:bonus:abgleich --seit=2026-01-01 [--anwenden]
```

## Anpassen
- Texte stehen in den Twig-Vorlagen (`src/Resources/views`) und im Admin-Skript (`src/Resources/public/administration/assets/maxxpet-bonus.js`, reines JavaScript ohne Build-Schritt).
- Farben (Blau `#0649a8`, Grün `#27832a`) sind in den Vorlagen und im Admin-Skript direkt hinterlegt und lassen sich dort ersetzen.

## Mitmachen
Fehler und Wünsche gern als Issue, Verbesserungen als Pull Request. Bitte immer mit Shopware-Version und, wenn möglich, Log-Ausschnitt.

## English summary
Install the ZIP via Extensions → My extensions → Upload, activate it, then run `bin/console assets:install && bin/console cache:clear`. Configure everything under Marketing → Bonus points → Settings. Points are credited when the payment state becomes "paid" and are reversed on cancellation or refund. Redeemed points become a discount line in the cart. Test on a staging system first; use at your own risk (MIT license).

---
Ideengeber und Hersteller: Markus Czarnetzki, Oberhausen, NRW · [macdata.de](https://macdata.de)
© 1999 macdata.de · Plugin © 2026 Markus Czarnetzki · MIT License
Programmierung: Markus Czarnetzki & Claude, KI-Assistent von Anthropic, im Auftrag von MAXXPET
