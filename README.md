# Contao-Zahlungen

`nordwerk/contao-payment-bundle` für PHP 8.3 und Contao ab 5.7.12. Anbieterneutrale Zahlungsnaht mit Vorkasse, gehostetem Stripe Checkout, gehostetem Mollie Checkout und Bezahlung vor Ort. Kartendaten bleiben bei Stripe bzw. Mollie. Der Mini-Shop nutzt das Bundle; Seminar folgt in P2.

## Installation und Einrichtung

Das Paket im Contao Manager gemeinsam mit dem Mini-Shop installieren oder über Composer einbinden. Solange noch kein Remote besteht, ein Composer-Path-Repository auf dieses Verzeichnis verwenden. `make artifact VERSION=1.0.0-dev` erstellt ein Manager-ZIP mit einem Composer-Manifest im Archivwurzelverzeichnis. Es werden keine Tags oder Remotes angelegt.

Unter **Inhalte → Zahlung einrichten** (ein normaler, versionierter Contao-Datensatz im Standard-Bearbeitungsformular) Vorkasse, Stripe und/oder Mollie einschalten. Vorkasse ist voreingestellt. Für Stripe zuerst den Testmodus wählen, den passenden Test-Schlüssel eintragen und mit ausgeschaltetem Stripe speichern. Danach **Webhook bei Stripe anlegen** ausführen. Das zurückgegebene Webhook-Secret wird verschlüsselt gespeichert. Danach Stripe einschalten und speichern. Alternativ ein bereits vorhandenes Webhook-Secret eintragen. Beim Wechsel auf Live: ausstehende Testzahlungen und Zustellungen zunächst abschließen, Stripe ausschalten, Live-Modus und Live-Schlüssel speichern, Live-Webhook anlegen und anschließend Stripe einschalten. Wiederholte Webhook-Anlage verwendet denselben Endpunkt samt Secret weiter; eine geänderte Webhook-Adresse muss vor einem Wechsel geprüft werden. Für Livebetrieb benötigt die Website HTTPS und einen öffentlich erreichbaren Webhook.

Schlüssel werden mit authentifizierter Sodium-Verschlüsselung gespeichert, abgeleitet aus Symfony `APP_SECRET`; die Eingabefelder bleiben leer. Leere Felder behalten vorhandene Werte. Beim Wechsel des Modus (der Schalter **Testmodus** gilt für alle Online-Anbieter) die Schlüssel der Anbieter ersetzen, die einen gespeicherten Schlüssel haben; wer nur Mollie nutzt, wird nicht nach einem Stripe-Schlüssel gefragt und umgekehrt. APP_SECRET sichern: ein Verlust oder Wechsel macht vorhandene verschlüsselte Schlüssel unlesbar. Die Schlüssel sind von der Versionierung ausgenommen und erscheinen weder in der Detailansicht noch im Versionsvergleich; eine wiederhergestellte Version bringt also keinen ersetzten Schlüssel zurück.

**Rechte:** Administratoren haben vollen Zugriff. Anderen Benutzergruppen gibt man ausdrücklich das Modul **Zahlung einrichten**, für die Tabelle „Zahlungseinstellungen“ das Recht **Bearbeiten** (Contao 5.7, Tabellenrechte) und unter **Erlaubte Felder** die gewünschten Felder. So kann eine Gruppe etwa Vorkasse und Bezahlung vor Ort schalten, ohne Secret Key und Webhook-Secret zu sehen oder zu ersetzen; **Webhook bei Stripe anlegen** verlangt zusätzlich das Feldrecht für das Webhook-Secret. Nach dem Update hat keine Gruppe neu Zugriff erhalten.

Optionale Overrides: `NW_PAYMENT_STRIPE_SECRET_KEY`, `NW_PAYMENT_STRIPE_WEBHOOK_SECRET`, `NW_PAYMENT_STRIPE_TEST_MODE` (`1`/`0`). Overrides gehören in die Laufzeitkonfiguration, niemals ins Repository. Der API-Endpunkt-Override `NW_PAYMENT_STRIPE_API_BASE` ist ausschließlich im Testmodus zulässig. Für Mollie gelten `NW_PAYMENT_MOLLIE_API_KEY` und `NW_PAYMENT_MOLLIE_API_BASE` (Basis ohne `/v2`, ebenfalls nur im Testmodus).

**Bezahlung vor Ort** (bar oder PayPal) ist ausgeschaltet, bis sie unter **Zahlung einrichten** eingeschaltet wird. Der Mini-Shop bietet sie nur bei Abholung an. Mit einem PayPal.me-Link (z. B. `paypal.me/hofladen`) zeigen Bestätigungsmail und Danke-Seite einen QR-Code, der den Betrag schon enthält. Die Zahlung bleibt offen, bis sie unter **Zahlungen** oder in der Bestellung bestätigt wird; es gibt keine Zahlungserinnerung.

## Zahlungsablauf

Das nutzende Bundle implementiert `PayableResolverInterface` und registriert den Service mit Autoconfiguration. `supports()` identifiziert den Bezugstyp; `paid()` und `refunded()` werden nur bei neuen, akzeptierten Zustandsänderungen aufgerufen. Der Typ darf keine Anbieter- oder Bestelllogik ins Payment-Bundle bringen. Resolver müssen transaktional und wiederholbar arbeiten; Versand erfolgt über eine Outbox.

**Anbieter** sind austauschbar. Ein Anbieter besteht aus zwei Services, die per Autoconfiguration gefunden werden, auch aus anderen Bundles:

- `PaymentProviderInterface` (Tag `nordwerk.payment.provider`): `id()` (wird mit jeder Zahlung gespeichert und steht in der Webhook-Adresse), `priority()` (Reihenfolge an der Kasse), `name()` („Mollie“), `label()` (Wahl an der Kasse), `online()` (Geld läuft über einen Online-Anbieter: Zahlungen gehören zum Test- oder Live-Modus, Erstattungen werden vor dem Aufruf reserviert, der Cron gleicht ab; sonst manuelle Bestätigung im Backend), dazu `createCheckout()`, `parseWebhook()`, `refund()`, `fetchStatus()`. `Money` besteht aus Integer-Cent und einer dreistelligen ISO-Währung.
- `Settings\ProviderSettingsInterface` (Tag `nordwerk.payment.provider_settings`, gleiche ID): Spalten in `tl_nw_payment_settings` (Schalter, verschlüsselte Secrets mit Umgebungsvariable, weitere Werte), wann der Anbieter Zahlungen annimmt, was im Startklar-Check fehlt und welche Regeln beim Speichern gelten (Schlüssel passend zum Modus, Zurücksetzen bei Wechsel, keine offenen Zahlungen beim Schlüsselwechsel). Die Felder selbst sind normale DCA-Felder.

Fähigkeiten über zusätzliche Interfaces: `ResumableCheckoutInterface` (startet der Kunde denselben Bezug erneut, setzt `PaymentService::start()` die vorhandene Zahlung fort, statt eine zweite anzulegen), `PaymentSnapshotProviderInterface` (vollständiger Stand für den Abgleich), `RefundReconcilerInterface` (ausstehende Erstattungen abgleichen), `PolledProviderInterface` (Stand wird bei der Rückkehr, nach Erstattungen und für bezahlte Zahlungen regelmäßig abgefragt, weil Webhooks fehlen können) und `UnsignedWebhookInterface` (Webhook ohne Signatur: die Route prüft vor dem API-Aufruf, ob die Zahlung zu uns gehört). Eingebaut sind `bank_transfer`, `stripe`, `mollie` und `on_site`.

Nutzende Bundles kennen keine Anbieternamen: `PaymentService::methods()` liefert die eingeschalteten Zahlarten mit Label, `name()` den Namen für Mails, `online()`/`manual()` die Art, `PaymentSettings::missing()` die Meldungen für den Startklar-Check.

Abonnierte Stripe-Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed`. Ausstehende Erstattungen werden anhand ihrer Stripe-Refund-ID abgeglichen; `failed` und `canceled` geben die Reservierung frei.

Webhook: `POST /_nw/payment/webhook/{provider}`. Stripe-Signaturen werden über den unveränderten HTTP-Body mit 300 Sekunden Zeittoleranz geprüft. Betrag, Währung, Zahlungs-ID, Session-Referenz und Modus müssen passen. Kein CSRF, keine Session; falsche Signaturen ergeben HTTP 400. Bekannte, doppelte Events und korrekt signierte unbekannte Eventarten erhalten 200 (bis 1.2: 204). Fehler in der Verarbeitung bleiben wiederholbar.

Die Rückkehrseite `/_nw/payment/return/{token}` zeigt „Zahlung wird geprüft“ und lädt ohne JavaScript alle fünf Sekunden neu. Sie bestätigt niemals selbst eine Zahlung. Implementiert der Resolver des Bezugs zusätzlich `ReturnUrlProviderInterface`, leitet sie zur Seite des nutzenden Bundles weiter (Buchung, Bestellung), sobald die Zahlung entschieden oder abgebrochen ist, und verlinkt sie, solange sie prüft. Contao-Cron gleicht offene Zahlungen nach zehn Minuten beim Anbieter ab. Der Webserver muss Contao-Cron regelmäßig auslösen; alternativ das Contao-Cron-Framework über die CLI betreiben.

**Inhalte → Zahlungen** bietet eine lesbare Liste und das unveränderliche Ereignisprotokoll (UTC). Vorkasse und Bezahlung vor Ort werden dort manuell bestätigt. **Erstatten** nimmt ganze Cent, höchstens den verbliebenen Betrag. Bei Vorkasse dokumentiert die Aktion eine bereits extern überwiesene Erstattung; sie löst keine Banküberweisung aus. Stripe-Erstattungen werden über die API ausgeführt. Wiederholtes Absenden derselben Aktion erzeugt keine weitere Erstattung.

## Mollie

Mollie nimmt die Zahlung auf der gehosteten Mollie-Seite entgegen. Der Kunde wählt dort Karte, PayPal, SEPA, Klarna, Apple Pay u. a., je nachdem, was im Mollie-Konto aktiviert ist; das Bundle legt keine Zahlarten fest. Es nutzt die Mollie Payments API v2 über `symfony/http-client`, ohne Mollie-Paket.

**Einrichtung:** Im Mollie-Dashboard unter **Entwickler → API-Schlüssel** den Schlüssel kopieren (`test_…` für Tests, `live_…` für den Echtbetrieb). Unter **Inhalte → Zahlung einrichten** im Abschnitt **Mollie-Zugang** den Schlüssel eintragen und **Mollie aktiv** einschalten; ohne gespeicherten Schlüssel lässt sich Mollie nicht einschalten. Der Schlüssel wird wie der Stripe-Schlüssel verschlüsselt gespeichert, nie angezeigt und nicht versioniert. Ein Webhook muss nicht angelegt werden: Das Bundle übergibt Mollie die Adresse `<Domain>/_nw/payment/webhook/mollie` bei jeder Zahlung selbst.

**Testmodus:** Der Schalter **Testmodus** gilt für alle Online-Anbieter. Im Testmodus ist nur ein `test_`-Schlüssel zulässig, sonst nur `live_`; Mollie-Antworten im falschen Modus werden abgelehnt. Beim Wechsel des Modus muss der Mollie-Schlüssel neu eingetragen werden, sofern einer gespeichert ist (Stripe entsprechend). Modus- und Schlüsselwechsel werden abgelehnt, solange es Zahlungen des Anbieters im bisherigen Modus mit Status offen oder ausstehend oder unfertige Erstattungen gibt, denn der alte Schlüssel wird beim Wechsel verworfen. Diese Zahlungen zuerst abschließen lassen (Webhook, Rückkehrseite oder Cron). Eine vorhandene Zahlung eines Bezugs wird in einem anderen Modus weder wiederverwendet noch ersetzt: `PaymentService::start()` bricht mit einer Meldung ab (gilt auch für Stripe). Testzahlungen werden auf der Mollie-Testseite mit dem gewünschten Status abgeschlossen.

**Webhook-Verhalten:** Mollie sendet nur `id=tr_…` und signiert nichts. Das Bundle prüft die ID per Muster, ruft die Zahlung mit dem eigenen Schlüssel von der API ab und übernimmt nur diesen Stand. Zahlungs-ID in den Metadaten, Betrag, Währung, Referenz und Modus müssen zur gespeicherten Zahlung passen, sonst antwortet die Route mit HTTP 400. Vorher prüft die Route lokal, ob die ID zu einer gespeicherten Mollie-Zahlung gehört; unbekannte IDs werden ohne API-Aufruf mit 200 quittiert. Erfolg und Duplikate beantwortet sie mit 200 (seit 1.3 für alle Anbieter; Stripe akzeptiert jede 2xx-Antwort). Die Event-ID wird aus dem Zustand gebildet (`mollie:<tr_id>:<Status>:<erstattete Cent>`), wiederholte Zustellungen sind daher Duplikate. Die Webhook-Adresse besteht aus Schema, Host und Port des Ursprungs, den der Shop für die Rückkehr-URL übergibt, und dem Pfad des Symfony-Routers (mit Basis-Pfad bei Installationen in einem Unterverzeichnis); ist der Router-Kontext lokal, der übergebene Ursprung aber öffentlich, entfällt sie nicht. Mollie lehnt Webhook-Adressen ab, die es nicht erreichen kann; für `localhost`, `*.localhost`, `*.test`, Hostnamen ohne Punkt sowie private und Loopback-IPs wird deshalb keine Webhook-Adresse übergeben.

**Ohne Webhook (lokal, hinter Firewall):** Die Rückkehrseite ruft für Mollie-Zahlungen im Status offen oder ausstehend den Stand bei Mollie ab (höchstens alle fünf Sekunden je Zahlung) und wendet ihn an. Das ist sicher, weil der Stand von Mollie stammt, nicht aus dem Browser; die Seite bestätigt weiterhin nie selbst. Zusätzlich gleicht der Contao-Cron nach zehn Minuten ab. Außerdem liest er je Lauf höchstens 20 bezahlte oder teilerstattete Mollie-Zahlungen (nicht älter als 180 Tage, jede höchstens alle zehn Minuten, reihum) erneut, damit im Mollie-Dashboard ausgelöste Erstattungen auch ohne Webhook ankommen.

**Status:** offen → offen; ausstehend und autorisiert → ausstehend; bezahlt → bezahlt. Teil- bzw. vollständig erstattet gilt erst, wenn Erstattungen mit Mollie-Status `refunded` zusammen den Betrag ergeben (das Bundle liest dafür `GET /payments/{id}/refunds`; das Feld `amountRefunded` wird nicht verwendet, weil die Mollie-Doku nicht sagt, ob es noch ausstehende Erstattungen mitzählt); vom Kunden abgebrochen und abgelaufen → abgelaufen; fehlgeschlagen → fehlgeschlagen.

**Erstattung:** **Erstatten** legt über die API eine Mollie-Erstattung an (Idempotency-Key aus der Operations-ID, Wiederholung erzeugt keine zweite). Mollie führt Erstattungen teils verzögert aus (`queued`, `pending`, `processing`); sie bleiben dann ausstehend und blockieren weitere Erstattungen, bis Webhook oder Cron das Ergebnis abgleichen. Fehlgeschlagene oder abgebrochene Erstattungen geben den Betrag wieder frei. Lehnt Mollie eine Erstattung endgültig ab (HTTP 4xx), wird sie mit Grund als fehlgeschlagen gespeichert und blockiert nichts. Eindeutig abgelehnt sind nur klare Client-Fehler (zum Beispiel 422); 409 („wird noch verarbeitet“), 5xx und Zeitüberschreitungen lassen die Operation offen. Ist der Ausgang unklar (Antwort verloren), sendet das Bundle nie blind erneut: Es liest erst die Erstattungen der Zahlung und übernimmt die mit passendem `operation_token`; ist die Liste nicht lesbar (auch eine Antwort ohne Erstattungsfeld), bleibt die Operation zur Prüfung stehen. Eine bei Mollie abgebrochene Erstattung, die dort nicht mehr abrufbar ist, gibt den Betrag frei, sobald die vollständige Erstattungsliste sie als abgebrochen zeigt oder nicht mehr enthält. Erstattungen für Zahlungen eines anderen Modus werden abgelehnt. Der Cron arbeitet offene Erstattungen reihum nach letzter Prüfung ab.

**Grenzen:** Rückbuchungen (Chargebacks), Abonnements, Mollie-Bestellungen, Teilzahlungen und Währungen ohne Nachkommastellen (JPY, ISK) werden nicht abgebildet. Eine Rückbuchung zeigt sich nur im Mollie-Dashboard. Die Statusmaschine kennt kein Zurück von „abgelaufen“ oder „fehlgeschlagen“: Bezahlt der Kunde eine bei Mollie abgelaufene Zahlung nicht mehr, bleibt ein neuer Versuch P4. Ein echter Mollie-Testlauf mit öffentlicher HTTPS-Adresse wurde noch nicht durchgeführt.

## Lokale Prüfung ohne Stripe- und Mollie-Netz

Docker: PHP 8.3-FPM, Caddy auf 8084, MariaDB auf tmpfs, Mailpit auf 8029, Fake-Stripe auf 8095 und Fake-Mollie auf 8096. `.env.example` nach `.env` kopieren und zufällige lokale Administrator-Zugangsdaten sowie APP_SECRET setzen. Die Datenbank wird bei `make reset` verworfen.

```sh
make reset
make check
make artifact VERSION=1.0.0-dev
```

Playwright wird mit `vp` betrieben. Läuft auf dem Rechner bereits ein anderer Stack auf 8084 oder 8029, lassen sich die Host-Ports in einer unversionierten `compose.override.yaml` ändern (`ports: !override`); der Browser-Test liest die Adresse dann aus `PAYMENT_BASE_URL`. `make check` prüft Paketexport, ECS, Twig-CS-Fixer, Composer, Container, PHPStan, PHPUnit und Browserabläufe. Die CI erzeugt lokale Zugangsdaten und korrigiert nach dem Reset die Schreibrechte für PHP-FPM.

`docker/fake-stripe/server.py` ist ein lokaler HTTP-Stub im Compose-Netz. Er nimmt Checkout Sessions und Erstattungen an, zeigt **Bezahlen**, **Abbrechen** und **Session ablaufen lassen** und sendet signierte Events. Die Strings mit `local_fixture` sind ausschließlich öffentliche Testdaten. Der Shop nutzt einen eigenen Stub auf 8094. `docker/fake-mollie/server.py` ist das Gegenstück für Mollie: Zahlungen und Erstattungen anlegen und abrufen, Checkout-Seite mit **Bezahlen**, **Abbrechen** und **Ablaufen lassen** (ruft eine übergebene Webhook-Adresse per Formular-POST auf), `POST /_fixture/refund-mode` mit `{"mode": "pending"}` lässt Erstattungen ausstehend bleiben. Er akzeptiert nur `test_…`-Schlüssel (`test_local_fixture`). Es werden keine echten Stripe- oder Mollie-Schlüssel benötigt. Screenshots liegen unter `test-results/screenshots/`.

## Optional: echter Stripe-Testmodus

Für einen ausdrücklich manuell gestarteten Smoke-Test einen Schlüssel über `STRIPE_TEST_SECRET_KEY` in die Prozessumgebung injizieren; ihn nicht in Shell-Historie, Dateien oder Ausgaben schreiben. Der folgende Aufruf erzeugt eine echte **Testmodus**-Session und gibt nur deren gehostete Test-URL aus:

```sh
docker compose exec -T -e STRIPE_TEST_SECRET_KEY php php /workspace/scripts/stripe-test.php
```

Der CLI-Smoke-Test umgeht den lokalen Stub. Für einen vollständigen Browser-Test `NW_PAYMENT_STRIPE_API_BASE` im Compose-PHP-Service leer setzen, die Installation über eine öffentliche HTTPS-Testadresse bereitstellen, Testmodus und Test-Schlüssel im Backend speichern und dort den Webhook anlegen. Anschließend den Shop-Kauf, asynchrone Zahlarten und Erstattung im Stripe-Testkonto prüfen. Es wurden hier keine externen Testzahlungen angelegt.

## Grenzen und offene Entscheidungen

Das Paket speichert je Bezug zunächst einen Zahlvorgang; zusätzliche Bezahllinks/Versuche sind P4. Eine Rückkehr nach Abbruch bleibt offen, bis Stripe Ablauf oder Zahlung bestätigt. Eine vollständige Zahlungserstattung setzt den Shop-Bestellstatus nicht automatisch auf storniert und löst keinen automatischen Widerruf aus. Die Verknüpfung erfolgt über Zahlungs-ID und Bestellnummer.

Teilerstattungen bei einem Steuersatz erzeugen einen Storno-Beleg mit kumulativer Cent-Rundung; bei mehreren Steuersätzen ist eine fachliche Positionszuordnung erforderlich. Solche Erstattungen werden im Shop als „Steuerzuordnung erforderlich“ angezeigt. Die offene Produktentscheidung steht in [docs/roadmap.md](docs/roadmap.md). Zahlungsregeln: [docs/rules.md](docs/rules.md).

## Local bank transfer helpers

`BankTransfer\Iban::valid()` checks the mod-97 checksum using integer arithmetic;
`normalize()` removes spaces and uppercases the IBAN. `GiroCode::payload()` is a pure EPC069-12
002/UTF-8 function: beneficiary ≤70 characters, unstructured reference ≤140 characters,
maximum payload 331 bytes, positive cent amount formatted as `EUR12.34`, optional BIC.
Invalid or multiline input is rejected. `GiroCode::image()` produces a local PNG with
chillerlan/php-qrcode 5.x, error correction M and QR version ≤13, and returns null for other
currencies/methods or invalid legacy bank data. GD and mbstring are required; generation
makes no network requests. Consumers embed PNG bytes in MIME messages and PDFs.

Specification: [European Payments Council EPC069-12](https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/quick-response-code-guidelines-enable-data-capture-initiation).
Library: [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode/tree/v5.0.x).

Decided 2026-09-30: a full refund does not automatically cancel fulfillment. A withdrawal is
reviewed by the merchant before a refund; failed/expired payments are not automatically
restarted (new payment links belong to P4).
