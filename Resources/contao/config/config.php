<?php

declare(strict_types=1);

use Nordwerk\PaymentBundle\Backend\PaymentsModule;
use Nordwerk\PaymentBundle\Backend\SettingsModule;

$GLOBALS['BE_MOD']['content']['nw_payments'] = ['callback' => PaymentsModule::class];
$GLOBALS['BE_MOD']['content']['nw_payment_settings'] = ['callback' => SettingsModule::class];
