<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_nw_payment_settings']['provider_legend'] = 'Anbieter';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['onsite_legend'] = 'Bezahlung vor Ort';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripe_legend'] = 'Stripe-Zugang';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['bankEnabled'] = ['Vorkasse aktiv', 'Überweisung vor Lieferung; die Zahlung wird unter „Zahlungen“ bestätigt.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeEnabled'] = ['Stripe aktiv', 'Erst einschalten, wenn Secret Key und Webhook-Secret gespeichert sind.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['testMode'] = ['Stripe-Testmodus', 'Beim Moduswechsel den passenden Secret Key neu eintragen; Webhook danach neu anlegen.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['onSiteEnabled'] = ['Bezahlung vor Ort aktiv (bar oder PayPal)', 'Der Mini-Shop bietet sie nur bei Abholung an. Die Zahlung bestätigen Sie beim Abholen in der Bestellung.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['payPalMe'] = ['PayPal.me-Link (optional)', 'Zum Beispiel paypal.me/hofladen. Bestätigungsmail und Danke-Seite zeigen dann einen QR-Code mit dem Betrag.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['secretKey'] = ['Secret Key', 'Wird verschlüsselt gespeichert und nie angezeigt. Leer lassen, um den gespeicherten Schlüssel zu behalten.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookSecret'] = ['Webhook-Secret', 'Wird verschlüsselt gespeichert und nie angezeigt. Leer lassen, um das gespeicherte Secret zu behalten, oder den Webhook unten anlegen lassen.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeWebhook'] = ['Stripe-Webhook', 'Einrichtung und Moduswechsel: Stripe ausschalten, Modus und passenden Schlüssel speichern, Webhook anlegen, anschließend Stripe einschalten.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['createWebhook'] = 'Webhook bei Stripe anlegen';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['backToSettings'] = 'Zurück zu den Einstellungen';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookConfirm'] = 'Bei Stripe wird ein Webhook für %s angelegt (bzw. der vorhandene wiederverwendet) und sein Secret verschlüsselt gespeichert.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookCreated'] = 'Webhook bei Stripe angelegt und Secret verschlüsselt gespeichert.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookFailed'] = 'Stripe-Einrichtung fehlgeschlagen. Schlüssel, Modus und Verbindung prüfen.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhook'] = ['Stripe-Webhook', 'Webhook anlegen'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['edit'] = 'Einstellungen bearbeiten';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['show'] = 'Einstellungen anzeigen';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['modeChangeNeedsKey'] = 'Beim Moduswechsel den passenden Stripe-Schlüssel neu eintragen.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['keyModeMismatch'] = 'Der Stripe-Schlüssel passt nicht zum Testmodus (sk_test_… bzw. sk_live_…).';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['invalidWebhookSecret'] = 'Ungültiges Webhook-Secret (beginnt mit whsec_).';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeIncomplete'] = 'Stripe zunächst ausgeschaltet speichern, dann Webhook anlegen und Stripe einschalten.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['invalidPayPalMe'] = 'Bitte einen PayPal.me-Link wie paypal.me/IhrName eingeben.';
