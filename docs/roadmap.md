# Roadmap

## P1 — Zahlungsnaht und Stripe

- [x] Bundle, Geldobjekte, neutrale Anbieter- und Resolver-Schnittstellen.
- [x] Daten, Statusmaschine, unveränderliches Audit, Idempotenz und signierter stateless Webhook.
- [x] Vorkasse, Stripe Checkout und Rückkehrseite ohne JavaScript.
- [x] Contao-Cron für Zahlungen älter als zehn Minuten.
- [x] Native Backend-Liste und Einrichtung in Hell/Dunkel, verschlüsselte Schlüssel, API-Webhook-Anlage.
- [x] Voll- und Teilerstattung mit API- und Formular-Idempotenz.
- [x] Lokaler Fake-Stripe, PHPUnit, Playwright, CI und Manager-Artefakt.
- [x] Mini-Shop angebunden und Integration mit Seminar/Widerruf weiterhin grün.

## Offene Produktentscheidungen

- Wie sollen Teilbeträge bei Bestellungen mit verschiedenen Steuersätzen auf Positionen verteilt werden? Bis zur Entscheidung: finanzielle Erstattung zulässig, Storno-Beleg zur Steuerzuordnung markiert. Volle Erstattung kann den vollständigen Originalbeleg stornieren.
- Soll vollständige Erstattung automatisch den Bestellstatus ändern oder bleibt die logistische Stornierung eine separate Aktion?
- Soll ein zugeordneter Widerruf automatisch eine Erstattung auslösen oder erst nach Prüfung durch den Händler?
- Sollen abgebrochene/abgelaufene Zahlungen einen neuen Versuch/Bezahllink erhalten (P4)? Aktuell ein Zahlvorgang je Bezug.
- Für weitere Verbraucher-Bundles und zusätzliche Anbieter folgen P2/P3; Seminar und Widerruf bleiben in P1 unverändert.

## Abnahme

- [ ] Echter Stripe-Testmodus in öffentlich erreichbarer HTTPS-Testinstallation, insbesondere verzögerte Zahlarten.
- [ ] Fachliche Freigabe gemischter Steuer-Teilerstattungen und der genannten Status-/Widerrufsentscheidungen.

Prüfung am 30.09.2026: Payment `make reset && make check`, 12 PHPUnit-Tests / 47 Assertions und ein Playwright-Test; Shop 63 PHPUnit-Tests / 260 Assertions und 29 Playwright-Tests; gemeinsame Integration 16 Browser-Tests. Echter Stripe-Test wurde nicht gestartet.
