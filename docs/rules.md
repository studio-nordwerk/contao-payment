# Zahlungsregeln P1

- Beträge ausschließlich als Integer in Cent plus ISO-Währung; keine Floats. Betrag positiv beim Checkout und bei Erstattungen.
- Je Bezugstyp/Bezug ein Zahlungsdatensatz. Checkout-Idempotenz: `checkout:{paymentId}`. Zahlungs- und Erstattungsaktionen sperren die betroffene Zeile.
- `open` → `pending`/`paid`/`failed`/`expired`; `pending` → `paid`/`failed`/`expired`; `paid` → `partially_refunded`/`refunded`; `partially_refunded` → `refunded`. Weitere kumulative Teilerstattungen sind zulässig, niemals eine Abnahme des Erstattungsbetrags. Terminale Status werden nicht zurückgesetzt.
- Eine vor dem Zahlungsevent eintreffende signierte Erstattung beweist den vorausgehenden Zahlungseingang. Der Resolver erhält zuerst bezahlt, danach erstattet. Der Rückkehrlink liefert keinen Zahlungsnachweis.
- Stripe Checkout läuft im Modus `payment`, gehostet. Dynamische Zahlarten sind bei Sessions automatisch aktiv: weder eine feste `payment_method_types`-Liste noch der nur auf PaymentIntent gültige Parameter `automatic_payment_methods` werden übergeben. Zahlungs-ID in Session- und PaymentIntent-Metadaten.
- Signaturprüfung vor jeder Verarbeitung, unveränderter Body, mehrere v1-Signaturen unterstützt, Zeittoleranz ±300 Sekunden. Test- und Live-Modus müssen passen.
- Session-ID, Metadaten-ID, Betrag und Währung müssen mit dem Zahlungsdatensatz übereinstimmen. `completed` mit noch unbezahlter asynchroner Zahlart bedeutet `pending`, erst `async_payment_succeeded` bedeutet `paid`.
- Event-ID pro Anbieter eindeutig; doppelte Events liefern 204 und wiederholen keine Rechnung/Mail. Ein neues verspätetes Event wird protokolliert, aber kann den Zustand nicht zurücksetzen. Audit-Datensätze sind im Backend und durch SQL-Trigger vor Änderung/Löschung geschützt.
- Erstattung maximal Restbetrag, gleiche Währung, positive ganze Cent. Eine Formular-Operations-ID verhindert Wiederholungen, der API-Key ist aus Zahlung, bisheriger Erstattung und Betrag abgeleitet. Ausstehende Erstattungen sperren weitere Anforderungen bis zum bestätigenden Webhook.
- Vorkasse: Bestätigung nach tatsächlichem Eingang; Erstattung dokumentiert eine bereits ausgeführte Banküberweisung.
- UTC: Unix-Zeitstempel für Anlage, Änderungen, Abgleich und Eventempfang; Anzeige des Ereignisprotokolls mit UTC.
- Schlüssel authentifiziert verschlüsselt mit APP_SECRET als Basis; nie als Formularwert, Logkontext oder Fehlermeldung ausgeben. Umgebungsvariablen sind optionale Overrides. Lokaler API-Override nur im Testmodus.

Primärquellen: [Checkout Session API](https://docs.stripe.com/api/checkout/sessions/create), [Webhook-Signaturen](https://docs.stripe.com/webhooks/signature), [Webhook-Anlage](https://docs.stripe.com/api/webhook_endpoints/create), [Erstattungen](https://docs.stripe.com/api/refunds/create). Keine neuen Rechts- oder Preisentscheidungen in P1.
