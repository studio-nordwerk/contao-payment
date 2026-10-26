<?php

declare(strict_types=1);

use Nordwerk\PaymentBundle\Backend\PaymentsModule;
use Nordwerk\PaymentBundle\Backend\StripeWebhookView;

$GLOBALS['BE_MOD']['content']['nw_payments'] = ['callback' => PaymentsModule::class];
// The settings record opens in the standard edit form; "key=webhook" registers
// the Stripe webhook under the same module permission.
$GLOBALS['BE_MOD']['content']['nw_payment_settings'] = ['tables' => ['tl_nw_payment_settings'], 'webhook' => [StripeWebhookView::class, 'run']];
