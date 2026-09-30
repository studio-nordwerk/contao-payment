<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\StripeClientInterface;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class RefundLifecycleTest extends TestCase
{
    public function testRefundEventsAndCronResolveOnlyTheirExactRefundAndReleaseFailedReservations(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $settings = new PaymentSettings($db, new SecretCipher((string) getenv('APP_SECRET')));
        $settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $repository = new PaymentRepository($db);
        $refunds = [];
        $session = [];
        $client = $this->createMock(StripeClientInterface::class);
        $client
            ->method('request')
            ->willReturnCallback(
                static function (string $method, string $path, array $parameters) use (&$session, &$refunds): array {
                    if ('post' === $method && '/v1/checkout/sessions' === $path) {
                        $session = ['id' => 'cs_'.bin2hex(random_bytes(8)), 'url' => 'https://checkout.example.test', 'livemode' => false, 'metadata' => $parameters['metadata'], 'amount_total' => 1000, 'currency' => 'eur', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => ['latest_charge' => ['amount_refunded' => 0]]];
                    }
                    if ('post' === $method && '/v1/refunds' === $path) {
                        $id = 're_'.bin2hex(random_bytes(8));
                        $refunds[$id] = ['id' => $id, 'object' => 'refund', 'status' => 'pending', 'amount' => $parameters['amount'], 'currency' => 'eur', 'payment_intent' => 'pi_fixture', 'metadata' => $parameters['metadata']];

                        return $refunds[$id];
                    }
                    if (str_starts_with($path, '/v1/refunds/')) {
                        return $refunds[basename($path)];
                    }
                    if ('get' === $method && '/v1/checkout/sessions' === $path) {
                        return ['data' => [$session]];
                    }

                    return $session;
                },
            )
        ;
        $stripe = new StripeProvider($client, $settings);
        $service = new PaymentService($db, $repository, new BankTransferProvider(), $stripe, $settings, [], new NullLogger());
        $payable = bin2hex(random_bytes(8));
        $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Fixture');
        $payment = $repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $service->apply('stripe', new PaymentEvent('paid:'.$payable, $payment->id, $payment->reference, PaymentStatus::Paid, $payment->money));
        $operation = bin2hex(random_bytes(32));
        $pending = $service->refund($payment->id, new Money(100), $operation);
        $this->assertFalse($pending->succeeded);
        $apply = static function (string $type, array $refund) use ($stripe, $service): void {
            $raw = json_encode(['id' => 'evt_'.bin2hex(random_bytes(8)), 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => $refund]], JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'whsec_local_fixture');
            $service->apply('stripe', $stripe->parseWebhook(Request::create('/', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], content: $raw)));
        };
        $apply('refund.created', $refunds[$pending->reference]);
        $this->assertSame('pending', $this->refundStatus($db, $operation));
        // A different, external refund must never settle our pending refund by amount alone.
        $session['payment_intent']['latest_charge']['amount_refunded'] = 100;
        $service->apply('stripe', new PaymentEvent('other-refund:'.$payable, $payment->id, $payment->reference, PaymentStatus::PartiallyRefunded, $payment->money, 100));
        $this->assertSame('pending', $db->fetchOne('SELECT status FROM tl_nw_payment_refund WHERE operation_token = ? AND payment_id = ?', [$operation, $payment->id]));
        $this->assertSame('0', $db->fetchOne('SELECT succeeded FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]));
        $refunds[$pending->reference]['status'] = 'requires_action';
        $apply('refund.updated', $refunds[$pending->reference]);
        $this->assertSame('requires_action', $this->refundStatus($db, $operation));
        $refunds[$pending->reference]['status'] = 'failed';
        $apply('refund.failed', $refunds[$pending->reference]);
        $this->assertSame('failed', $this->refundStatus($db, $operation));
        // Delayed payload cannot resurrect a refund that the API already reports as failed.
        $stale = $refunds[$pending->reference];
        $stale['status'] = 'pending';
        $apply('refund.created', $stale);
        $this->assertSame('failed', $this->refundStatus($db, $operation));
        $secondOperation = bin2hex(random_bytes(32));
        $second = $service->refund($payment->id, new Money(100), $secondOperation);
        $refunds[$second->reference]['status'] = 'canceled';
        // No webhook: reconciliation must fetch the particular provider refund ID.
        $service->reconcile();
        $this->assertSame('canceled', $this->refundStatus($db, $secondOperation));
        $thirdOperation = bin2hex(random_bytes(32));
        $third = $service->refund($payment->id, new Money(100), $thirdOperation);
        $refunds[$third->reference]['status'] = 'succeeded';
        $session['payment_intent']['latest_charge']['amount_refunded'] = 200;
        $apply('refund.updated', $refunds[$third->reference]);
        $this->assertSame('succeeded', $this->refundStatus($db, $thirdOperation));
        $this->assertSame(200, $repository->find($payment->id)->refundedCents);
        $this->assertFalse($service->refund($payment->id, new Money(100), $operation)->succeeded);
        $this->assertSame('failed', $service->refund($payment->id, new Money(100), $operation)->status->value);
        $db->close();
    }

    /**
     * @phpstan-impure
     */
    private function refundStatus(Connection $db, string $operation): string
    {
        return (string) $db->fetchOne('SELECT status FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]);
    }
}
