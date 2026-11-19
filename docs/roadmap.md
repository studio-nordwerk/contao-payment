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

## Mollie (18.11.2026)

- [x] Mollie als Anbieter `mollie` (Payments API v2, gehostete Seite), Einstellungen mit verschlüsseltem API-Key, Testmodus für alle Online-Anbieter, Moduswechsel verlangt nur die Schlüssel vorhandener Anbieter.
- [x] Webhook ohne Signatur über API-Abruf, Status-Mapping, Erstattungen mit Cron-Abgleich, Abruf beim Rückkehren ohne Webhook, Fake-Mollie, Tests.
- [ ] Chargebacks, Abonnements und Zahlungen in Währungen ohne Nachkommastellen sind nicht abgebildet.
- [ ] Echter Mollie-Testmodus in öffentlich erreichbarer HTTPS-Testinstallation (Webhook, verzögerte Zahlarten, Erstattung).

## Bekannte Grenzen (Mollie, Moduswechsel)

- Zwischen dem Speichern der Einstellungen und einer gleichzeitig laufenden Kasse oder Erstattungsreservierung gibt es ein kurzes Zeitfenster: Die Sperre bei Modus- und Schlüsselwechsel prüft offene Zahlungen ohne Zeilensperre, eine Zahlung, die genau dann angelegt wird, kann übersehen werden.
- Ein Schlüsselwechsel wird gesperrt, solange offene Zahlungen oder Erstattungen des Anbieters bestehen. Ist der alte Schlüssel dabei gesperrt oder widerrufen, bleibt nur abzuwarten, bis die Zahlungen ablaufen, oder den neuen Schlüssel vorübergehend über den Env-Override (`NW_PAYMENT_MOLLIE_API_KEY`, `NW_PAYMENT_STRIPE_SECRET_KEY`) zu setzen.
- Das Contao-Formular (`DC_Table`) validiert die Einstellungen ohne Zeilensperre; zwei gleichzeitig gespeicherte Formulare können sich gegenseitig überschreiben.
- Der Cron arbeitet Erstattungen und Zahlungen reihum nach letzter Prüfung ab, überlappende Läufe sind nicht ausgeschlossen. Abgleiche sind idempotent (deterministische Event-IDs), können aber denselben Anbieteraufruf doppelt auslösen.
- Der rotierende Abgleich bezahlter Mollie-Zahlungen betrachtet nur Zahlungen der letzten 180 Tage: Eine im Dashboard ausgelöste Erstattung kurz vor dieser Grenze kann unbemerkt bleiben, wenn kein Webhook ankommt.
- Webhook- und Rückkehr-URL nutzen denselben öffentlichen Ursprung (den, den der Shop übergibt) und den Router-Pfad; liegt die Installation in einem Unterverzeichnis, muss der übergebene Ursprung selbst das Unterverzeichnis enthalten, damit die Rückkehr-URL stimmt.

## Offene Produktentscheidungen

- Wie sollen Teilbeträge bei Bestellungen mit verschiedenen Steuersätzen auf Positionen verteilt werden? Bis zur Entscheidung: finanzielle Erstattung zulässig, Storno-Beleg zur Steuerzuordnung markiert. Volle Erstattung kann den vollständigen Originalbeleg stornieren.
- Soll vollständige Erstattung automatisch den Bestellstatus ändern oder bleibt die logistische Stornierung eine separate Aktion?
- Soll ein zugeordneter Widerruf automatisch eine Erstattung auslösen oder erst nach Prüfung durch den Händler?
- Sollen abgebrochene/abgelaufene Zahlungen einen neuen Versuch/Bezahllink erhalten (P4)? Aktuell ein Zahlvorgang je Bezug.
- Für weitere Verbraucher-Bundles und zusätzliche Anbieter folgen P2/P3; Seminar und Widerruf bleiben in P1 unverändert.

- Schlüsselrotation (`APP_SECRET`): Sind künftig versionierte Schlüssel und eine Umverschlüsselung für unterbrechungsfreie Rotation nötig? Aktuell fordert der Startklar-Check bei nicht entschlüsselbaren Schlüsseln „Stripe-Schlüssel neu eingeben“; kein versioniertes Schlüsselmodell.

## Abnahme

- [ ] Echter Stripe-Testmodus in öffentlich erreichbarer HTTPS-Testinstallation, insbesondere verzögerte Zahlarten.
- [ ] Fachliche Freigabe gemischter Steuer-Teilerstattungen und der genannten Status-/Widerrufsentscheidungen.

P1-Erstprüfung am 30.09.2026: Payment `make reset && make check`, 12 PHPUnit-Tests / 47 Assertions und ein Playwright-Test; Shop 63 PHPUnit-Tests / 260 Assertions und 29 Playwright-Tests; gemeinsame Integration 16 Browser-Tests. Echter Stripe-Test wurde nicht gestartet.

## P1-Review behoben (30.09.2026)

- [x] Befund 1: dauerhafte Erstattungsoperation vor Stripe-Aufruf (`af912b6`).
- [x] Befund 2: vorhandene Checkout-Sessions wiederverwenden (`800ba40`).
- [x] Befund 4: konkrete Refund-IDs, alle Refund-Status und Events, Cron-Abgleich (`502fe0e`).
- [x] Befund 7: fehlgeschlagene asynchrone Zahlungen ohne Webhook erkennen (`0727731`).
- [x] Befund 8: dauerhafte Webhook-Anlage und Wiederverwendung des Endpunkts (`e80154c`).
- [x] Befund 10: eigene ID je Formular und Paralleltest gegen globale Token-Kollision (`b2c0bdb`).
- [x] Befund 12: deaktivierter Live-Einrichtungsschritt, danach Webhook und Freischaltung; Startklar-Hinweis nach APP_SECRET-Wechsel (`027928d`).

Shop-Befunde und endgültige Prüfzahlen: `../../ops/report-2026-09-30-contao-payment-p1-fixes.md`.

Abschluss nach den Review-Fixes: Payment 22 PHPUnit-Tests / 121 Assertions und 1 Browser-Test; Shop 72 PHPUnit-Tests / 303 Assertions und 29 Browser-Tests; gemeinsame Integration 16 Browser-Tests. Beide Repos nach Reset und vollständigem Check grün.
