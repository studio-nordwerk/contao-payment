<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Backend;

use Contao\BackendModule;
use Contao\BackendTemplate;
use Contao\BackendUser;
use Contao\Input;
use Contao\System;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @property BackendTemplate $Template
 */
final class SettingsModule extends BackendModule
{
    protected $strTemplate = 'be_nw_payment_settings';

    protected function compile(): void
    {
        /** @var BackendUser $user */
        $user = BackendUser::getInstance();
        if (!$user->isAdmin) {
            throw new \RuntimeException('Nur Administratoren dürfen Zahlungsanbieter einrichten.');
        }
        /** @var PaymentSettings $settings */
        $settings = System::getContainer()->get(PaymentSettings::class);
        $error = '';
        $notice = '';
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            try {
                if ('webhook' === Input::post('action')) {
                    /** @var StripeProvider $stripe */
                    $stripe = System::getContainer()->get(StripeProvider::class);
                    /** @var RequestStack $stack */
                    $stack = System::getContainer()->get('request_stack');
                    $request = $stack->getCurrentRequest();
                    if (null === $request) {
                        throw new \RuntimeException('No request.');
                    }
                    $stripe->registerWebhook($request->getSchemeAndHttpHost().'/_nw/payment/webhook/stripe');
                    $notice = 'Webhook bei Stripe angelegt und Secret verschlüsselt gespeichert.';
                } else {
                    $key = Input::post('secretKey');
                    $secret = Input::post('webhookSecret');
                    $settings->save('1' === Input::post('bankEnabled'), '1' === Input::post('stripeEnabled'), '1' === Input::post('testMode'), \is_string($key) ? trim($key) : '', \is_string($secret) ? trim($secret) : '');
                    $notice = 'Einstellungen gespeichert.';
                }
            } catch (\Throwable $exception) {
                $error = $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Stripe-Einrichtung fehlgeschlagen. Schlüssel, Modus und Verbindung prüfen.';
            }
        }
        $data = $settings->stored();
        // Never pass ciphertext or plaintext secrets to the backend template.
        $this->Template->setData(['values' => ['bankEnabled' => $data['bankEnabled'] ?? true, 'stripeEnabled' => $data['stripeEnabled'] ?? false, 'testMode' => $data['testMode'] ?? true], 'error' => $error, 'notice' => $notice]);
    }
}
