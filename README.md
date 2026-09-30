# Contao-Zahlungen

`nordwerk/contao-payment-bundle` für PHP 8.3 und Contao ab 5.7.12. Anbieterneutrale Zahlungsnaht mit Vorkasse und gehostetem Stripe Checkout. Kartendaten bleiben bei Stripe. Der Mini-Shop nutzt das Bundle; Seminar folgt in P2.

## Installation und Einrichtung

Das Paket im Contao Manager gemeinsam mit dem Mini-Shop installieren oder über Composer einbinden. Solange noch kein Remote besteht, ein Composer-Path-Repository auf dieses Verzeichnis verwenden. `make artifact VERSION=1.0.0-dev` erstellt ein Manager-ZIP mit einem Composer-Manifest im Archivwurzelverzeichnis. Es werden keine Tags oder Remotes angelegt.

Unter **Inhalte → Zahlung einrichten** Vorkasse und/oder Stripe einschalten. Vorkasse ist voreingestellt. Für Stripe zuerst den Testmodus wählen, den passenden Test-Schlüssel eintragen und mit ausgeschaltetem Stripe speichern. Danach **Webhook bei Stripe anlegen** ausführen. Das zurückgegebene Webhook-Secret wird verschlüsselt gespeichert. Danach Stripe einschalten und speichern. Alternativ ein bereits vorhandenes Webhook-Secret eintragen. Beim Wechsel auf Live: ausstehende Testzahlungen und Zustellungen zunächst abschließen, Stripe ausschalten, Live-Modus und Live-Schlüssel speichern, Live-Webhook anlegen und anschließend Stripe einschalten. Wiederholte Webhook-Anlage verwendet denselben Endpunkt samt Secret weiter; eine geänderte Webhook-Adresse muss vor einem Wechsel geprüft werden. Für Livebetrieb benötigt die Website HTTPS und einen öffentlich erreichbaren Webhook.

Schlüssel werden mit authentifizierter Sodium-Verschlüsselung gespeichert, abgeleitet aus Symfony `APP_SECRET`; die Eingabefelder bleiben leer. Leere Felder behalten vorhandene Werte. Bei Wechsel des Modus beide Schlüssel ersetzen. APP_SECRET sichern: ein Verlust oder Wechsel macht vorhandene verschlüsselte Schlüssel unlesbar. Die Einrichtung ist Administratoren vorbehalten.

Optionale Overrides: `NW_PAYMENT_STRIPE_SECRET_KEY`, `NW_PAYMENT_STRIPE_WEBHOOK_SECRET`, `NW_PAYMENT_STRIPE_TEST_MODE` (`1`/`0`). Overrides gehören in die Laufzeitkonfiguration, niemals ins Repository. Der API-Endpunkt-Override `NW_PAYMENT_STRIPE_API_BASE` ist ausschließlich im Testmodus zulässig.

## Zahlungsablauf

Das nutzende Bundle implementiert `PayableResolverInterface` und registriert den Service mit Autoconfiguration. `supports()` identifiziert den Bezugstyp; `paid()` und `refunded()` werden nur bei neuen, akzeptierten Zustandsänderungen aufgerufen. Der Typ darf keine Anbieter- oder Bestelllogik ins Payment-Bundle bringen. Resolver müssen transaktional und wiederholbar arbeiten; Versand erfolgt über eine Outbox.

`PaymentProviderInterface`: `createCheckout(PaymentRequest)`, `parseWebhook(Request)`, `refund(Payment, Money)`, `fetchStatus(Payment)`. `Money` besteht aus Integer-Cent und einer dreistelligen ISO-Währung. Anbieter sind `bank_transfer` und `stripe`.

Abonnierte Stripe-Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed`. Ausstehende Erstattungen werden anhand ihrer Stripe-Refund-ID abgeglichen; `failed` und `canceled` geben die Reservierung frei.

Webhook: `POST /_nw/payment/webhook/{provider}`. Stripe-Signaturen werden über den unveränderten HTTP-Body mit 300 Sekunden Zeittoleranz geprüft. Betrag, Währung, Zahlungs-ID, Session-Referenz und Modus müssen passen. Kein CSRF, keine Session; falsche Signaturen ergeben HTTP 400. Bekannte, doppelte Events und korrekt signierte unbekannte Eventarten erhalten 204. Fehler in der Verarbeitung bleiben wiederholbar.

Die Rückkehrseite `/_nw/payment/return/{token}` zeigt „Zahlung wird geprüft“ und lädt ohne JavaScript alle fünf Sekunden neu. Sie bestätigt niemals selbst eine Zahlung. Contao-Cron gleicht offene Zahlungen nach zehn Minuten beim Anbieter ab. Der Webserver muss Contao-Cron regelmäßig auslösen; alternativ das Contao-Cron-Framework über die CLI betreiben.

**Inhalte → Zahlungen** bietet eine lesbare Liste und das unveränderliche Ereignisprotokoll (UTC). Vorkasse wird dort manuell bestätigt. **Erstatten** nimmt ganze Cent, höchstens den verbliebenen Betrag. Bei Vorkasse dokumentiert die Aktion eine bereits extern überwiesene Erstattung; sie löst keine Banküberweisung aus. Stripe-Erstattungen werden über die API ausgeführt. Wiederholtes Absenden derselben Aktion erzeugt keine weitere Erstattung.

## Lokale Prüfung ohne Stripe-Netz

Docker: PHP 8.3-FPM, Caddy auf 8084, MariaDB auf tmpfs, Mailpit auf 8029 und Fake-Stripe auf 8095. `.env.example` nach `.env` kopieren und zufällige lokale Administrator-Zugangsdaten sowie APP_SECRET setzen. Die Datenbank wird bei `make reset` verworfen.

```sh
make reset
make check
make artifact VERSION=1.0.0-dev
```

Playwright wird mit `vp` betrieben. `make check` prüft Paketexport, ECS, Twig-CS-Fixer, Composer, Container, PHPStan, PHPUnit und Browserabläufe. Die CI erzeugt lokale Zugangsdaten und korrigiert nach dem Reset die Schreibrechte für PHP-FPM.

`docker/fake-stripe/server.py` ist ein lokaler HTTP-Stub im Compose-Netz. Er nimmt Checkout Sessions und Erstattungen an, zeigt **Bezahlen**, **Abbrechen** und **Session ablaufen lassen** und sendet signierte Events. Die Strings mit `local_fixture` sind ausschließlich öffentliche Testdaten. Der Shop nutzt einen eigenen Stub auf 8094. Es werden keine echten Stripe-Schlüssel benötigt. Screenshots liegen unter `test-results/screenshots/`.

## Optional: echter Stripe-Testmodus

Für einen ausdrücklich manuell gestarteten Smoke-Test einen Schlüssel über `STRIPE_TEST_SECRET_KEY` in die Prozessumgebung injizieren; ihn nicht in Shell-Historie, Dateien oder Ausgaben schreiben. Der folgende Aufruf erzeugt eine echte **Testmodus**-Session und gibt nur deren gehostete Test-URL aus:

```sh
docker compose exec -T -e STRIPE_TEST_SECRET_KEY php php /workspace/scripts/stripe-test.php
```

Der CLI-Smoke-Test umgeht den lokalen Stub. Für einen vollständigen Browser-Test `NW_PAYMENT_STRIPE_API_BASE` im Compose-PHP-Service leer setzen, die Installation über eine öffentliche HTTPS-Testadresse bereitstellen, Testmodus und Test-Schlüssel im Backend speichern und dort den Webhook anlegen. Anschließend den Shop-Kauf, asynchrone Zahlarten und Erstattung im Stripe-Testkonto prüfen. Es wurden hier keine externen Testzahlungen angelegt.

## Grenzen und offene Entscheidungen

Das Paket speichert je Bezug zunächst einen Zahlvorgang; zusätzliche Bezahllinks/Versuche sind P4. Eine Rückkehr nach Abbruch bleibt offen, bis Stripe Ablauf oder Zahlung bestätigt. Eine vollständige Zahlungserstattung setzt den Shop-Bestellstatus nicht automatisch auf storniert und löst keinen automatischen Widerruf aus. Die Verknüpfung erfolgt über Zahlungs-ID und Bestellnummer.

Teilerstattungen bei einem Steuersatz erzeugen einen Storno-Beleg mit kumulativer Cent-Rundung; bei mehreren Steuersätzen ist eine fachliche Positionszuordnung erforderlich. Solche Erstattungen werden im Shop als „Steuerzuordnung erforderlich“ angezeigt. Die offene Produktentscheidung steht in [docs/roadmap.md](docs/roadmap.md). Zahlungsregeln: [docs/rules.md](docs/rules.md).
