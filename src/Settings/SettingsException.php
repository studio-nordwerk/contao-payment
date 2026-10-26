<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * Invalid payment settings. The message stays German for existing callers; the backend
 * form shows the translation $GLOBALS['TL_LANG']['tl_nw_payment_settings'][$reason].
 */
final class SettingsException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
