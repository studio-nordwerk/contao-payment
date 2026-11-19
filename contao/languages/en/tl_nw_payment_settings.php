<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_nw_payment_settings']['provider_legend'] = 'Payment methods';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['onsite_legend'] = 'Payment on site';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripe_legend'] = 'Stripe access';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['mollie_legend'] = 'Mollie access';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['mollieEnabled'] = ['Mollie enabled', 'Customers pick card, PayPal, SEPA, Klarna, Apple Pay etc. on the Mollie page (depending on your Mollie account). Only enable with a saved API key.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['mollieApiKey'] = ['Mollie API key', 'In the Mollie dashboard under Developers → API keys. test_… in test mode, live_… otherwise. Stored encrypted and never shown; leave empty to keep the stored key.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['modeChangeNeedsMollieKey'] = 'When switching modes, enter the matching Mollie API key again.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['mollieKeyModeMismatch'] = 'The Mollie API key does not match the test mode (test_… or live_…).';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['mollieIncomplete'] = 'Mollie can only be enabled with a saved API key.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['openPaymentsStripe'] = 'Stripe: there are still open payments or refunds in the current mode or with the current key. Let them finish first (wait for the webhook or the cron), then change the mode or key.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['openPaymentsMollie'] = 'Mollie: there are still open payments or refunds in the current mode or with the current API key. Let them finish first (wait for the webhook, the return page or the cron), then change the mode or key.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['bankEnabled'] = ['Bank transfer in advance', 'Transfer before delivery; the payment is confirmed under "Payments".'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeEnabled'] = ['Stripe enabled', 'Only enable once the secret key and the webhook secret are saved.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['testMode'] = ['Test mode (Stripe and Mollie)', 'Applies to all online providers. When switching modes, enter the saved keys of the providers again (Stripe: create the webhook again afterwards).'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['onSiteEnabled'] = ['Payment on site (cash or PayPal)', 'The mini shop only offers it for pickup. Confirm the payment in the order on pickup.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['payPalMe'] = ['PayPal.me link (optional)', 'For example paypal.me/farmshop. The confirmation mail and thank-you page then show a QR code with the amount.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['secretKey'] = ['Secret key', 'Stored encrypted and never shown. Leave empty to keep the stored key.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookSecret'] = ['Webhook secret', 'Stored encrypted and never shown. Leave empty to keep the stored secret, or create the webhook below.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeWebhook'] = ['Stripe webhook', 'Setup and mode change: disable Stripe, save the mode and the matching key, create the webhook, then enable Stripe.'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['createWebhook'] = 'Create webhook at Stripe';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['backToSettings'] = 'Back to the settings';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookConfirm'] = 'A webhook for %s is created at Stripe (or the existing one is reused) and its secret is stored encrypted.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookCreated'] = 'Webhook created at Stripe and its secret stored encrypted.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhookFailed'] = 'Stripe setup failed. Check the key, the mode and the connection.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['webhook'] = ['Stripe webhook', 'Create webhook'];
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['edit'] = 'Edit settings';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['show'] = 'Show settings';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['modeChangeNeedsKey'] = 'When switching modes, enter the matching Stripe key again.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['keyModeMismatch'] = 'The Stripe key does not match the test mode (sk_test_… or sk_live_…).';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['invalidWebhookSecret'] = 'Invalid webhook secret (starts with whsec_).';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeIncomplete'] = 'Save with Stripe disabled first, then create the webhook and enable Stripe.';
$GLOBALS['TL_LANG']['tl_nw_payment_settings']['invalidPayPalMe'] = 'Please enter a PayPal.me link such as paypal.me/YourName.';
