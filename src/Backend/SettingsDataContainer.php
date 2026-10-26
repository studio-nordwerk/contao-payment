<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Backend;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Nordwerk\PaymentBundle\OnSite\PayPalMe;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SettingsException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Callbacks of the singleton settings record tl_nw_payment_settings (id 1).
 */
final readonly class SettingsDataContainer
{
    public function __construct(
        private PaymentSettings $settings,
        private RequestStack $requestStack,
        private RouterInterface $router,
        private Security $security,
    ) {
    }

    /**
     * Opens the one record in the standard edit form.
     */
    #[AsCallback(table: 'tl_nw_payment_settings', target: 'config.onload')]
    public function openRecord(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || 'nw_payment_settings' !== $request->query->get('do') || $request->query->has('key') || $request->isXmlHttpRequest()) {
            return;
        }
        $action = $request->query->get('act');
        if (null !== $action && 'edit' !== $action) {
            return;
        }
        $this->settings->ensureRecord();
        if (null === $action) {
            throw new RedirectResponseException($this->router->generate('contao_backend', ['do' => 'nw_payment_settings', 'act' => 'edit', 'id' => 1]));
        }
    }

    /**
     * Secrets are write-only: the form starts empty, and an empty field keeps the
     * stored value (doNotSaveEmpty).
     */
    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.secretKey.load')]
    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.webhookSecret.load')]
    public function hideSecret(): string
    {
        return '';
    }

    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.payPalMe.load')]
    public function showPayPalMe(mixed $value): string
    {
        return '' === (string) $value ? '' : 'paypal.me/'.$value;
    }

    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.payPalMe.save')]
    public function normalizePayPalMe(mixed $value): string
    {
        try {
            return PayPalMe::normalize((string) $value);
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_nw_payment_settings']['invalidPayPalMe']);
        }
    }

    /**
     * Rules that span several fields (mode and key, Stripe only with both secrets)
     * and encryption of the secrets. Nothing is stored if a rule fails.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_nw_payment_settings', target: 'config.onbeforesubmit')]
    public function applyRules(array $values, DataContainer $dc): array
    {
        try {
            $update = $this->settings->applyChanges($dc->getCurrentRecord() ?? [], $values);
            // Never let a submitted plaintext secret through; applyChanges() returns the
            // encrypted value.
            unset($values['secretKey'], $values['webhookSecret']);

            return [...$values, ...$update];
        } catch (SettingsException $exception) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_nw_payment_settings'][$exception->reason] ?? $exception->getMessage());
        }
    }

    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.stripeWebhook.input_field')]
    public function webhookLink(): string
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_nw_payment_settings::webhookSecret')) {
            return '';
        }
        $url = $this->router->generate('contao_backend', ['do' => 'nw_payment_settings', 'key' => 'webhook']);
        [$label, $help] = $GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeWebhook'];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="widget clr"><h3>'.$escape($label).'</h3><p><a class="tl_submit" href="'.$escape($url).'">'.$escape($GLOBALS['TL_LANG']['tl_nw_payment_settings']['createWebhook']).'</a></p><p class="tl_help tl_tip">'.$escape($help).'</p></div>';
    }
}
