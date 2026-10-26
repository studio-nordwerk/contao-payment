<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Message;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * "do=nw_payment_settings&key=webhook": registers the Stripe webhook and stores
 * its secret. Contao dispatches the key only after the module permission check;
 * storing the secret additionally needs the permission for the webhook secret
 * field. GET shows a confirmation, POST (with Contao's request token) registers.
 */
#[Autoconfigure(public: true)]
final readonly class StripeWebhookView
{
    public function __construct(
        private StripeProvider $stripe,
        private RequestStack $requestStack,
        private RouterInterface $router,
        private Security $security,
        private ContaoCsrfTokenManager $tokenManager,
    ) {
    }

    public function run(): RedirectResponse|string
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_nw_payment_settings::webhookSecret')) {
            throw new AccessDeniedException('Not enough permissions to register the Stripe webhook.');
        }
        $lang = $GLOBALS['TL_LANG']['tl_nw_payment_settings'];
        $request = $this->requestStack->getCurrentRequest() ?? throw new \RuntimeException('No request.');
        $back = $this->router->generate('contao_backend', ['do' => 'nw_payment_settings', 'act' => 'edit', 'id' => 1]);
        $url = $request->getSchemeAndHttpHost().'/_nw/payment/webhook/stripe';

        if ($request->isMethod('POST')) {
            try {
                $this->stripe->registerWebhook($url);
                Message::addConfirmation($lang['webhookCreated']);
            } catch (\InvalidArgumentException $exception) {
                Message::addError($exception->getMessage());
            } catch (\Throwable) {
                Message::addError($lang['webhookFailed']);
            }

            return new RedirectResponse($back);
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="tl_formbody_edit"><div class="tl_tbox"><p>'.$escape(\sprintf($lang['webhookConfirm'], $url)).'</p></div></div>'
            .'<form data-turbo="false" class="tl_form" method="post"><input type="hidden" name="REQUEST_TOKEN" value="'.$escape($this->tokenManager->getDefaultTokenValue()).'">'
            .'<div class="tl_formbody_submit"><div class="tl_submit_container"><button class="tl_submit" type="submit">'.$escape($lang['createWebhook']).'</button> '
            .'<a class="tl_submit" href="'.$escape($back).'">'.$escape($lang['backToSettings']).'</a></div></div></form>';
    }
}
