<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PayableResolverInterface;
use Nordwerk\PaymentBundle\Domain\Payment;
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
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\Request;

final class PaymentProcessingTest extends TestCase
{
    private Connection $db;
    private PaymentService $service;
    private PaymentRepository $repository;
    private PaymentSettings $settings;
    private StripeProvider $stripe;
    private RecordingResolver $resolver;

    protected function setUp(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        if (false === $parts) {
            throw new \RuntimeException('Invalid test database URL.');
        }
        $this->db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $this->db->beginTransaction();
        $this->repository = new PaymentRepository($this->db);
        $this->settings = new PaymentSettings($this->db, new SecretCipher('unit-test-app-secret'));
        $this->settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $client = $this->createMock(StripeClientInterface::class);
        $this->stripe = new StripeProvider($client, $this->settings);
        $this->resolver = new RecordingResolver();
        $this->service = new PaymentService($this->db, $this->repository, new BankTransferProvider(), $this->stripe, $this->settings, [$this->resolver], new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        $this->db->close();
    }

    public function testDuplicateAndLateEventsDoNotRepeatPaidCallback(): void
    {
        $payment = $this->create();
        $paid = $this->paidEvent($payment, 'paid-event');
        $this->assertTrue($this->service->apply('bank_transfer', $paid));
        $this->assertFalse($this->service->apply('bank_transfer', $paid));
        $this->assertFalse($this->service->apply('bank_transfer', new PaymentEvent('late-expiry', $payment->id, $payment->reference, PaymentStatus::Expired, $payment->money)));
        $this->assertSame(1, $this->resolver->paidCalls);
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_event WHERE payment_id = ?', [$payment->id]));
    }

    public function testMismatchedAmountIsRejectedWithoutAnAuditEntry(): void
    {
        $payment = $this->create();

        try {
            $this->service->apply('bank_transfer', new PaymentEvent('forged-amount', $payment->id, $payment->reference, PaymentStatus::Paid, new Money(1)));
            $this->fail('Amount mismatch was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_event WHERE payment_id = ?', [$payment->id]));
        }
    }

    public function testRefundRetryAndCumulativeRefundsAreIdempotent(): void
    {
        $payment = $this->create();
        $this->service->apply('bank_transfer', $this->paidEvent($payment, 'paid-event'));
        $operation = str_repeat('b', 64);
        $this->service->refund($payment->id, new Money(100), $operation);
        $this->service->refund($payment->id, new Money(100), $operation);
        $this->assertSame(100, $this->repository->find($payment->id)->refundedCents);
        $this->service->refund($payment->id, new Money(100), str_repeat('c', 64));
        $this->assertSame(200, $this->repository->find($payment->id)->refundedCents);
        $this->assertSame(2, $this->resolver->refundCalls);
        $this->service->refund($payment->id, new Money(800), str_repeat('d', 64));
        $this->assertSame(PaymentStatus::Refunded, $this->repository->find($payment->id)->status);
        $this->assertFalse($this->service->apply('bank_transfer', $this->paidEvent($payment, 'late-paid')));
    }

    public function testSignaturesRequireUntamperedPayloadAndRecentTimestamp(): void
    {
        $raw = json_encode(['id' => 'evt_fixture', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => ['id' => 'cs_fixture', 'object' => 'checkout.session', 'metadata' => ['payment_id' => '1'], 'amount_total' => 1000, 'currency' => 'eur', 'livemode' => false, 'payment_status' => 'unpaid']]], JSON_THROW_ON_ERROR);
        $request = $this->signed($raw, time());
        $this->assertSame(PaymentStatus::Pending, $this->stripe->parseWebhook($request)->status);

        foreach ([$this->signed($raw, time() - 601), $this->signed($raw, time() + 601), $this->signed($raw.' ', time(), $raw)] as $invalid) {
            try {
                $this->stripe->parseWebhook($invalid);
                $this->fail('Invalid signature was accepted.');
            } catch (SignatureVerificationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRefundBeforePaidEventStillDeliversBothResolverNotificationsOnce(): void
    {
        $payment = $this->create();
        $refund = new PaymentEvent('early-refund', $payment->id, $payment->reference, PaymentStatus::PartiallyRefunded, $payment->money, 100);
        $this->assertTrue($this->service->apply('bank_transfer', $refund));
        $this->assertSame(1, $this->resolver->paidCalls);
        $this->assertSame(1, $this->resolver->refundCalls);
        $this->assertFalse($this->service->apply('bank_transfer', $this->paidEvent($payment, 'delayed-paid')));
        $this->assertFalse($this->service->apply('bank_transfer', $refund));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $this->repository->find($payment->id)->status);
    }

    public function testEventAuditCannotBeModifiedEvenThroughSql(): void
    {
        $payment = $this->create();
        $this->service->apply('bank_transfer', $this->paidEvent($payment, 'audit-protection'));
        $this->expectException(DriverException::class);
        $this->db->executeStatement('UPDATE tl_nw_payment_event SET status = status WHERE payment_id = ?', [$payment->id]);
    }

    public function testCronConfirmsOldPaymentsAndLeavesFreshPaymentsAlone(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $session = [];
        $client
            ->method('request')
            ->willReturnCallback(
                static function (string $method, string $path, array $parameters, string|null $idempotencyKey) use (&$session): array {
                    if ('post' === $method) {
                        $session = ['id' => 'cs_fixture', 'url' => 'https://checkout.example.test', 'livemode' => false, 'metadata' => $parameters['metadata'], 'amount_total' => 1000, 'currency' => 'eur', 'status' => 'complete', 'payment_status' => 'paid'];
                        self::assertSame('payment', $parameters['mode']);
                        self::assertSame('checkout:'.$parameters['metadata']['payment_id'], $idempotencyKey);
                        self::assertArrayNotHasKey('automatic_payment_methods', $parameters);
                        self::assertArrayNotHasKey('payment_method_types', $parameters);
                    }

                    return $session;
                },
            )
        ;
        $service = new PaymentService($this->db, $this->repository, new BankTransferProvider(), new StripeProvider($client, $this->settings), $this->settings, [$this->resolver], new NullLogger());
        $payable = bin2hex(random_bytes(8));
        $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Fixture');
        $payment = $this->repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $service->reconcile();
        $this->assertSame(PaymentStatus::Open, $this->repository->find($payment->id)->status);
        $this->db->update('tl_nw_payment', ['created_at' => time() - 601], ['id' => $payment->id]);
        $service->reconcile();
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);
        $this->assertSame(1, $this->resolver->paidCalls);
    }

    public function testProviderStatusIncludesExternalPartialRefunds(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $client
            ->method('request')
            ->willReturn(['id' => 'cs_fixture', 'livemode' => false, 'metadata' => ['payment_id' => '1'], 'amount_total' => 1000, 'currency' => 'eur', 'payment_status' => 'paid', 'payment_intent' => ['latest_charge' => ['amount_refunded' => 100]]])
        ;
        $stripe = new StripeProvider($client, $this->settings);
        $payment = new Payment(1, 'fixture', 'external-refund', new Money(1000), 'stripe', 'cs_fixture', PaymentStatus::Paid);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $stripe->fetchStatus($payment));
        $this->assertSame(100, $stripe->fetchSnapshot($payment)->refundedCents);
    }

    public function testSignedAsyncCheckoutEventsUseTheirActualPaymentOutcome(): void
    {
        foreach (['checkout.session.async_payment_succeeded' => PaymentStatus::Paid, 'checkout.session.async_payment_failed' => PaymentStatus::Failed, 'checkout.session.expired' => PaymentStatus::Expired] as $type => $status) {
            $raw = json_encode(['id' => 'evt_'.$status->value, 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => ['id' => 'cs_fixture', 'object' => 'checkout.session', 'metadata' => ['payment_id' => '1'], 'amount_total' => 1000, 'currency' => 'eur', 'livemode' => false, 'payment_status' => 'unpaid']]], JSON_THROW_ON_ERROR);
            $this->assertSame($status, $this->stripe->parseWebhook($this->signed($raw, time()))->status);
        }
    }

    public function testCheckoutRetriesReuseReferenceEvenAfterProviderKeysExpire(): void
    {
        $client = $this->createMock(StripeClientInterface::class);
        $posts = 0;
        $session = [];
        $client
            ->method('request')
            ->willReturnCallback(
                static function (string $method, string $path, array $parameters) use (&$posts, &$session): array {
                    if ('post' === $method) {
                        ++$posts;
                        $session = ['id' => 'cs_retry_'.$posts, 'url' => 'https://checkout.example.test/'.$posts, 'livemode' => false, 'metadata' => $parameters['metadata'], 'amount_total' => 1000, 'currency' => 'eur', 'status' => 'open', 'payment_status' => 'unpaid'];
                    }

                    return $session;
                },
            )
        ;
        $service = new PaymentService($this->db, $this->repository, new BankTransferProvider(), new StripeProvider($client, $this->settings), $this->settings, [], new NullLogger());
        $payable = bin2hex(random_bytes(8));
        $first = $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Fixture');
        $retry = $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Fixture');
        $this->assertSame($first->reference, $retry->reference);
        $this->assertSame($first->redirectUrl, $retry->redirectUrl);
        $payment = $this->repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $this->db->update('tl_nw_payment', ['status' => 'pending', 'created_at' => time() - 259200], ['id' => $payment->id]);
        $pending = $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Fixture');
        $this->assertSame('https://example.test/_nw/payment/return/'.$payment->token, $pending->redirectUrl);
        $this->assertSame(1, $posts);
        $this->assertSame($first->reference, $this->repository->find($payment->id)->reference);
    }

    private function signed(string $raw, int $timestamp, string|null $signedRaw = null): Request
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.($signedRaw ?? $raw), 'whsec_local_fixture');

        return Request::create('/_nw/payment/webhook/stripe', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], content: $raw);
    }

    private function create(): Payment
    {
        $this->service->start('fixture', bin2hex(random_bytes(8)), new Money(1000), 'bank_transfer', 'https://example.test', 'Fixture');

        return $this->repository->find((int) $this->db->fetchOne("SELECT MAX(id) FROM tl_nw_payment WHERE payable_type = 'fixture'"));
    }

    private function paidEvent(Payment $payment, string $id): PaymentEvent
    {
        return new PaymentEvent($id, $payment->id, $payment->reference, PaymentStatus::Paid, $payment->money);
    }
}

final class RecordingResolver implements PayableResolverInterface
{
    public int $paidCalls = 0;
    public int $refundCalls = 0;

    public function supports(string $payableType): bool
    {
        return 'fixture' === $payableType;
    }

    public function paid(Payment $payment): void
    {
        ++$this->paidCalls;
    }

    public function refunded(Payment $payment, int $previousRefundedCents): void
    {
        ++$this->refundCalls;
    }
}
