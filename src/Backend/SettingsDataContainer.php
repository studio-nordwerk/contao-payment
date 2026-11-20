<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Backend;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Exception\AccessDeniedException;
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
        // Restoring a version writes all versioned fields without the field permission
        // checks of the form.
        if ($request->isMethod('POST') && 'tl_version' === $request->request->get('FORM_SUBMIT')) {
            foreach ($GLOBALS['TL_DCA']['tl_nw_payment_settings']['fields'] as $field => $config) {
                if (isset($config['inputType']) && false !== ($config['eval']['versionize'] ?? true) && !$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_nw_payment_settings::'.$field)) {
                    throw new AccessDeniedException('Restoring a version of the payment settings requires access to all versioned fields.');
                }
            }
        }
        $this->settings->ensureRecord();
        if (null === $action) {
            throw new RedirectResponseException($this->router->generate('contao_backend', ['do' => 'nw_payment_settings', 'act' => 'edit', 'id' => 1]));
        }
    }

    /**
     * Secrets of every provider are write-only, also those of providers from other
     * bundles: the form starts empty, an empty field keeps the stored value
     * (doNotSaveEmpty), and neither the show view, the version diff nor tl_version
     * contains them.
     */
    #[AsHook('loadDataContainer')]
    public function protectSecrets(string $table): void
    {
        if ('tl_nw_payment_settings' !== $table) {
            return;
        }

        foreach ($this->settings->secretFields() as $field) {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field])) {
                continue;
            }
            $config = &$GLOBALS['TL_DCA'][$table]['fields'][$field];
            $config['eval'] = [...$config['eval'] ?? [], 'hideInput' => true, 'doNotSaveEmpty' => true, 'versionize' => false, 'autocomplete' => 'new-password'];
            $config['load_callback'] = [...$config['load_callback'] ?? [], static fn (): string => ''];
            unset($config);
        }
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
     * Rules that span several fields (mode and keys, each provider only with its
     * credentials; see the provider sections) and encryption of the secrets. Nothing
     * is stored if a rule fails.
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
            foreach ($this->settings->secretFields() as $field) {
                unset($values[$field]);
            }

            return [...$values, ...$update];
        } catch (SettingsException $exception) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_nw_payment_settings'][$exception->reason] ?? $exception->getMessage());
        }
    }

    #[AsCallback(table: 'tl_nw_payment_settings', target: 'fields.stripeWebhook.input_field')]
    public function webhookLink(): string
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_OPERATE_ON_TABLE, 'tl_nw_payment_settings::update') || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_nw_payment_settings::webhookSecret')) {
            return '';
        }
        $url = $this->router->generate('contao_backend', ['do' => 'nw_payment_settings', 'key' => 'webhook']);
        [$label, $help] = $GLOBALS['TL_LANG']['tl_nw_payment_settings']['stripeWebhook'];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="widget clr"><h3>'.$escape($label).'</h3><p><a class="tl_submit" href="'.$escape($url).'">'.$escape($GLOBALS['TL_LANG']['tl_nw_payment_settings']['createWebhook']).'</a></p><p class="tl_help tl_tip">'.$escape($help).'</p></div>';
    }
}
