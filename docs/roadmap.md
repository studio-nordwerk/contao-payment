# Roadmap

## P1 — Zahlungsnaht und Stripe

- [ ] Bundle, Geldobjekte, neutrale Anbieter- und Resolver-Schnittstellen.
- [ ] Daten, Statusmaschine, unveränderliches Audit, Idempotenz und signierter stateless Webhook.
- [ ] Vorkasse, Stripe Checkout und Rückkehrseite ohne JavaScript.
- [ ] Contao-Cron für Zahlungen älter als zehn Minuten.
- [ ] Native Backend-Liste und Einrichtung in Hell/Dunkel, verschlüsselte Schlüssel, API-Webhook-Anlage.
- [ ] Voll- und Teilerstattung mit API- und Formular-Idempotenz.
- [ ] Lokaler Fake-Stripe, PHPUnit, Playwright, CI und Manager-Artefakt.
- [ ] Mini-Shop angebunden und Integration mit Seminar/Widerruf weiterhin grün.

## Offene Produktentscheidungen

- Wie sollen Teilbeträge bei Bestellungen mit verschiedenen Steuersätzen auf Positionen verteilt werden? Bis zur Entscheidung: finanzielle Erstattung zulässig, Storno-Beleg zur Steuerzuordnung markiert. Volle Erstattung kann den vollständigen Originalbeleg stornieren.
- Soll vollständige Erstattung automatisch den Bestellstatus ändern oder bleibt die logistische Stornierung eine separate Aktion?
- Soll ein zugeordneter Widerruf automatisch eine Erstattung auslösen oder erst nach Prüfung durch den Händler?
- Sollen abgebrochene/abgelaufene Zahlungen einen neuen Versuch/Bezahllink erhalten (P4)? Aktuell ein Zahlvorgang je Bezug.
- Für weitere Verbraucher-Bundles und zusätzliche Anbieter folgen P2/P3; Seminar und Widerruf bleiben in P1 unverändert.

## Abnahme

- [ ] Echter Stripe-Testmodus in öffentlich erreichbarer HTTPS-Testinstallation, insbesondere verzögerte Zahlarten.
- [ ] Fachliche Freigabe gemischter Steuer-Teilerstattungen und der genannten Status-/Widerrufsentscheidungen.
