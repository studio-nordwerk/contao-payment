<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Controller\WebhookController;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundStatus;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\MollieClientInterface;
use Nordwerk\PaymentBundle\Provider\MollieProvider;
use Nordwerk\PaymentBundle\Provider\StripeClientInterface;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class MollieProviderTest extends TestCase
{
    private Connection $db;
    private PaymentSettings $settings;
    private PaymentRepository $repository;
    private PaymentService $service;
    private MollieProvider $mollie;
    private ScriptedMollie $client;
    private RequestContext $context;
    /**
     * @var array<string, mixed>|false
     */
    private array|false $original;

    protected function setUp(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $this->original = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1');
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        $this->settings = new PaymentSettings($this->db, new SecretCipher((string) getenv('APP_SECRET')));
        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        $this->repository = new PaymentRepository($this->db);
        $this->client = new ScriptedMollie();
        $routes = new RouteCollection();
        $routes->add('nw_payment_webhook', new Route('/_nw/payment/webhook/{provider}'));
        $this->context = new RequestContext('', 'GET', 'shop.example', 'https');
        $this->mollie = new MollieProvider($this->client, $this->settings, new UrlGenerator($routes, $this->context));
        $this->service = new PaymentService($this->db, $this->repository, new BankTransferProvider(), new StripeProvider($this->createMock(StripeClientInterface::class), $this->settings), $this->settings, [], new NullLogger(), mollie: $this->mollie);
    }

    protected function tearDown(): void
    {
        // The events stay (the event log is immutable by trigger); payments and refunds
        // of the tests go.
        $this->db->executeStatement("DELETE FROM tl_nw_payment_refund WHERE payment_id IN (SELECT id FROM tl_nw_payment WHERE payable_id LIKE 'mollie-%')");
        $this->db->executeStatement("DELETE FROM tl_nw_payment WHERE payable_id LIKE 'mollie-%'");
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        if (false !== $this->original) {
            $this->db->insert('tl_nw_payment_settings', $this->original);
        }
        $this->db->close();
    }

    public function testCheckoutRequestAndRedirect(): void
    {
        $payment = $this->start(1234);
        $request = $this->client->requests[0];
        $this->assertSame(['post', '/payments', 'checkout:'.$payment->id], [$request['method'], $request['path'], $request['key']]);
        $body = $request['parameters'];
        $this->assertSame(['currency' => 'EUR', 'value' => '12.34'], $body['amount']);
        $this->assertSame('https://shop.example/_nw/payment/return/'.$payment->token, $body['redirectUrl']);
        $this->assertSame($body['redirectUrl'].'?canceled=1', $body['cancelUrl']);
        $this->assertSame(['payment_id' => (string) $payment->id], $body['metadata']);
        $this->assertSame('https://shop.example/_nw/payment/webhook/mollie', $body['webhookUrl']);
        $this->assertNotSame('', $body['description']);
        $this->assertSame('tr_'.$this->client->lastId, $payment->reference);
    }

    public function testWebhookUrlComesFromTheRouterWithItsBasePath(): void
    {
        $this->context->setBaseUrl('/shop');
        $this->start(1234);
        $this->assertSame('https://shop.example/shop/_nw/payment/webhook/mollie', $this->client->requests[0]['parameters']['webhookUrl']);
    }

    public function testNoWebhookUrlForLocalOrigin(): void
    {
        $this->context->setHost('127.0.0.1');
        $this->context->setScheme('http');
        $this->start(1234, 'http://127.0.0.1:8084');
        $this->assertArrayNotHasKey('webhookUrl', $this->client->requests[0]['parameters']);
    }

    public function testCheckoutLinkIsTheRedirectAndModeMismatchIsRefused(): void
    {
        $payable = 'mollie-'.bin2hex(random_bytes(6));
        $result = $this->service->start('fixture', $payable, new Money(500), 'mollie', 'https://shop.example', 'Test');
        $this->assertSame('https://checkout.example/'.$this->client->lastId, $result->redirectUrl);

        $this->client->mode = 'live';
        $payable = 'mollie-'.bin2hex(random_bytes(6));

        try {
            $this->service->start('fixture', $payable, new Money(500), 'mollie', 'https://shop.example', 'Test');
            $this->fail('A live payment must not be accepted in test mode.');
        } catch (\RuntimeException) {
            $this->assertSame('', $this->repository->forPayable('fixture', $payable)->reference ?? '', 'Nothing is stored after a refused checkout.');
        }
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>, PaymentStatus, int}>
     */
    public static function statuses(): iterable
    {
        yield 'open' => ['open', [], PaymentStatus::Open, 0];
        yield 'pending' => ['pending', [], PaymentStatus::Pending, 0];
        yield 'authorized' => ['authorized', [], PaymentStatus::Pending, 0];
        yield 'paid' => ['paid', [], PaymentStatus::Paid, 0];
        yield 'paid, partly refunded' => ['paid', [['5.00', 'refunded']], PaymentStatus::PartiallyRefunded, 500];
        yield 'paid, refunded in two steps' => ['paid', [['3.00', 'refunded'], ['2.00', 'refunded']], PaymentStatus::PartiallyRefunded, 500];
        yield 'paid, fully refunded' => ['paid', [['12.34', 'refunded']], PaymentStatus::Refunded, 1234];
        // Money is only back with status "refunded".
        yield 'refund queued' => ['paid', [['5.00', 'queued']], PaymentStatus::Paid, 0];
        yield 'refund pending' => ['paid', [['5.00', 'pending']], PaymentStatus::Paid, 0];
        yield 'refund processing' => ['paid', [['12.34', 'processing']], PaymentStatus::Paid, 0];
        yield 'refund failed' => ['paid', [['5.00', 'failed']], PaymentStatus::Paid, 0];
        yield 'refund canceled' => ['paid', [['5.00', 'canceled']], PaymentStatus::Paid, 0];
        yield 'one refunded, one pending' => ['paid', [['3.00', 'refunded'], ['2.00', 'pending']], PaymentStatus::PartiallyRefunded, 300];
        yield 'one refunded, one failed' => ['paid', [['12.34', 'refunded'], ['1.00', 'failed']], PaymentStatus::Refunded, 1234];
        yield 'canceled by the customer' => ['canceled', [], PaymentStatus::Expired, 0];
        yield 'expired' => ['expired', [], PaymentStatus::Expired, 0];
        yield 'failed' => ['failed', [], PaymentStatus::Failed, 0];
    }

    /**
     * @param list<array{string, string}> $refunds
     */
    #[DataProvider('statuses')]
    public function testStatusMapping(string $mollieStatus, array $refunds, PaymentStatus $expected, int $cents): void
    {
        $payment = $this->start(1234);
        $this->client->status = $mollieStatus;

        foreach ($refunds as [$value, $status]) {
            $this->client->addRefund($value, $status);
        }
        $event = $this->mollie->fetchSnapshot($payment);
        $this->assertSame($expected, $event->status);
        $this->assertSame($cents, $event->refundedCents);
        $this->assertSame($expected, $this->mollie->fetchStatus($payment));
    }

    public function testRefundsAboveTotalOrInAnotherCurrencyAreRefused(): void
    {
        $payment = $this->start(1234);
        $other = $this->start(1234);
        $this->client->status = 'paid';
        $this->client->addRefund('12.00', 'refunded');
        $this->client->addRefund('0.35', 'refunded');

        try {
            $this->mollie->fetchSnapshot($payment);
            $this->fail('Refunds above the payment amount were accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->client->refunds = [];
        $this->client->addRefund('1.00', 'refunded', 'USD');
        $this->expectException(\InvalidArgumentException::class);
        $this->mollie->fetchSnapshot($other);
    }

    public function testRefundListIsReadPageByPage(): void
    {
        $payment = $this->start(1234);
        $this->client->status = 'paid';
        $this->client->pageSize = 2;

        foreach (['1.00', '1.00', '1.00', '1.00', '1.00'] as $value) {
            $this->client->addRefund($value, 'refunded');
        }
        $this->assertSame(500, $this->mollie->fetchSnapshot($payment)->refundedCents);
    }

    public function testPendingRefundKeepsThePaymentPaidUntilMollieHasRefundedIt(): void
    {
        $payment = $this->paid(1234);
        $this->client->refundStatus = 'pending';
        $this->service->refund($payment->id, new Money(500), bin2hex(random_bytes(32)));
        // The payment already shows the amount as refunded, the status must not follow yet.
        $this->assertSame('5.00', $this->client->payment($payment->reference)['amountRefunded']['value']);
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));
        $this->assertSame([PaymentStatus::Paid, 0], [$this->repository->find($payment->id)->status, $this->repository->find($payment->id)->refundedCents]);

        $this->client->settleRefunds('refunded');
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));
        $stored = $this->repository->find($payment->id);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500], [$stored->status, $stored->refundedCents]);
    }

    public function testRefundThatFailsLaterNeverShowsAsRefunded(): void
    {
        $payment = $this->paid(1234);
        $this->client->refundStatus = 'pending';
        $this->service->refund($payment->id, new Money(1234), bin2hex(random_bytes(32)));
        $this->client->settleRefunds('failed');
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);
    }

    public function testSnapshotOfAnotherPaymentOrAmountIsRefused(): void
    {
        $payment = $this->start(1234);
        $this->client->amount = '12.35';
        $this->expectException(\InvalidArgumentException::class);
        $this->mollie->fetchSnapshot($payment);
    }

    public function testWebhookReadsTheStateFromTheApiAndIsIdempotent(): void
    {
        $payment = $this->start(1234);
        $this->client->status = 'paid';
        $request = static fn (): Request => Request::create('/_nw/payment/webhook/mollie', 'POST', ['id' => $payment->reference]);
        $event = $this->mollie->parseWebhook($request());
        $this->assertSame('mollie:'.$payment->reference.':paid:0', $event->eventId);
        $this->assertSame($event->eventId, $this->mollie->parseWebhook($request())->eventId);
        $this->assertTrue($this->service->apply('mollie', $event));
        $this->assertFalse($this->service->apply('mollie', $this->mollie->parseWebhook($request())), 'A repeated delivery is a duplicate.');
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);
        // The id from the body is only a pointer: the API call carries it, nothing else
        // from the request is trusted.
        $this->assertSame('/payments/'.$payment->reference, $this->client->last()['path']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badIds(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong prefix' => ['re_12345678'];
        yield 'no body' => [''];
        yield 'path traversal' => ['tr_x/../../v2/profiles'];
        yield 'query' => ['tr_abcdef?x=1'];
        yield 'array' => [['tr_abcdef']];
        yield 'too short' => ['tr_ab'];
    }

    #[DataProvider('badIds')]
    public function testWebhookWithInvalidIdIsRefusedWithoutApiCall(mixed $id): void
    {
        $request = Request::create('/_nw/payment/webhook/mollie', 'POST', null === $id ? [] : ['id' => $id]);
        $before = \count($this->client->requests);

        try {
            $this->mollie->parseWebhook($request);
            $this->fail('Invalid id accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertCount($before, $this->client->requests);
        }
    }

    public function testWebhookOfTheWrongModeIsRefused(): void
    {
        $payment = $this->start(1234);
        $this->client->mode = 'live';
        $this->expectException(\InvalidArgumentException::class);
        $this->mollie->parseWebhook(Request::create('/_nw/payment/webhook/mollie', 'POST', ['id' => $payment->reference]));
    }

    public function testWebhookForAnotherPaymentOrAmountChangesNothing(): void
    {
        $first = $this->start(1234);
        $second = $this->start(1234);
        // A Mollie payment (of the same account) that carries the id of another
        // stored payment.
        $this->client->status = 'paid';
        $this->client->metadataPaymentId = $second->id;
        $foreign = $this->mollie->parseWebhook(Request::create('/', 'POST', ['id' => $first->reference]));
        $this->assertRejected($foreign);

        $this->client->metadataPaymentId = null;
        $this->client->amount = '0.01';
        $cheap = $this->mollie->parseWebhook(Request::create('/', 'POST', ['id' => $first->reference]));
        $this->assertRejected($cheap);

        $this->client->amount = null;
        $this->client->currency = 'USD';
        $this->assertRejected($this->mollie->parseWebhook(Request::create('/', 'POST', ['id' => $first->reference])));

        $this->client->currency = null;
        $this->client->metadataPaymentId = 999999999;
        $this->assertRejected($this->mollie->parseWebhook(Request::create('/', 'POST', ['id' => $first->reference])));
        $this->assertSame(PaymentStatus::Open, $this->repository->find($first->id)->status);
        $this->assertSame(PaymentStatus::Open, $this->repository->find($second->id)->status);
    }

    public function testRefundSendsAmountMetadataAndAStableIdempotencyKey(): void
    {
        $payment = $this->paid(1234);
        $operation = bin2hex(random_bytes(32));
        $result = $this->service->refund($payment->id, new Money(500), $operation);
        $request = $this->client->last();
        $this->assertSame(['post', '/payments/'.$payment->reference.'/refunds', 'refund:'.$operation], [$request['method'], $request['path'], $request['key']]);
        $this->assertSame(['currency' => 'EUR', 'value' => '5.00'], $request['parameters']['amount']);
        $this->assertSame(['payment_id' => (string) $payment->id, 'operation_token' => $operation], $request['parameters']['metadata']);
        $this->assertTrue($result->succeeded);
        $stored = $this->repository->find($payment->id);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500], [$stored->status, $stored->refundedCents]);

        // Submitting the same form again must not create a second refund.
        $calls = \count($this->client->requests);
        $again = $this->service->refund($payment->id, new Money(500), $operation);
        $this->assertSame($result->reference, $again->reference);
        $this->assertSame($calls, \count($this->client->requests));
        $this->assertSame(500, $this->repository->find($payment->id)->refundedCents);
    }

    /**
     * @return iterable<string, array{string, RefundStatus}>
     */
    public static function refundStatuses(): iterable
    {
        yield 'queued' => ['queued', RefundStatus::Pending];
        yield 'pending' => ['pending', RefundStatus::Pending];
        yield 'processing' => ['processing', RefundStatus::Pending];
        yield 'refunded' => ['refunded', RefundStatus::Succeeded];
        yield 'failed' => ['failed', RefundStatus::Failed];
        yield 'canceled' => ['canceled', RefundStatus::Canceled];
    }

    #[DataProvider('refundStatuses')]
    public function testRefundStatusMapping(string $mollieStatus, RefundStatus $expected): void
    {
        $payment = $this->paid(1234);
        $this->client->refundStatus = $mollieStatus;
        $result = $this->mollie->refund($payment, new Money(100), bin2hex(random_bytes(32)));
        $this->assertSame($expected, $result->status);
        $this->assertSame(RefundStatus::Succeeded === $expected, $result->succeeded);
    }

    public function testRefundsAreRefusedInsideAnOpenTransaction(): void
    {
        $payment = $this->paid(1234);
        $this->db->beginTransaction();

        try {
            $this->expectException(\LogicException::class);
            $this->service->refund($payment->id, new Money(100), bin2hex(random_bytes(32)));
        } finally {
            $this->db->rollBack();
        }
    }

    public function testPendingRefundBlocksAnotherUntilCronSettlesIt(): void
    {
        $payment = $this->paid(1234);
        $this->client->refundStatus = 'pending';
        $operation = bin2hex(random_bytes(32));
        $result = $this->service->refund($payment->id, new Money(500), $operation);
        $this->assertFalse($result->succeeded);
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);

        try {
            $this->service->refund($payment->id, new Money(100), bin2hex(random_bytes(32)));
            $this->fail('A second refund must wait for the pending one.');
        } catch (\InvalidArgumentException) {
        }

        // Mollie settles the refund; the next cron run applies it without a webhook.
        $this->client->settleRefunds('refunded');
        $this->service->reconcile();
        $row = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]);
        $this->assertIsArray($row);
        $this->assertSame(['refunded' => 1, 'status' => 'succeeded'], ['refunded' => (int) $row['succeeded'], 'status' => $row['status']]);
        $stored = $this->repository->find($payment->id);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500], [$stored->status, $stored->refundedCents]);
    }

    public function testFailedRefundFreesTheReservation(): void
    {
        $payment = $this->paid(1234);
        $this->client->refundStatus = 'pending';
        $this->service->refund($payment->id, new Money(500), bin2hex(random_bytes(32)));
        $this->client->settleRefunds('failed');
        $this->service->reconcile();
        $this->client->refundStatus = 'refunded';
        $this->assertTrue($this->service->refund($payment->id, new Money(1234), bin2hex(random_bytes(32)))->succeeded);
        $this->assertSame(PaymentStatus::Refunded, $this->repository->find($payment->id)->status);
    }

    public function testReturnPageReadsTheStateFromMollieAtMostEveryFiveSeconds(): void
    {
        $payment = $this->start(1234);
        $this->client->status = 'open';
        $calls = fn (): int => \count(array_filter($this->client->requests, static fn (array $r): bool => 'get' === $r['method']));
        $this->assertSame(PaymentStatus::Open, $this->service->refreshFromProvider($payment)->status);
        $this->assertSame(1, $calls());
        $this->client->status = 'paid';
        $this->assertSame(PaymentStatus::Open, $this->service->refreshFromProvider($payment)->status, 'Within five seconds the API is not asked again.');
        $this->assertSame(1, $calls());
        $this->db->update('tl_nw_payment', ['checked_at' => time() - 6], ['id' => $payment->id]);
        $this->assertSame(PaymentStatus::Paid, $this->service->refreshFromProvider($payment)->status);
        // Settled payments are not asked again.
        $settled = $this->repository->find($payment->id);
        $this->db->update('tl_nw_payment', ['checked_at' => 0], ['id' => $payment->id]);
        $before = $calls();
        $this->service->refreshFromProvider($settled);
        $this->assertSame($before, $calls());
    }

    public function testReturnPageIgnoresAnInconsistentAnswer(): void
    {
        $payment = $this->start(1234);
        $this->client->status = 'paid';
        $this->client->amount = '0.01';
        $this->assertSame(PaymentStatus::Open, $this->service->refreshFromProvider($payment)->status);
        $this->assertSame(PaymentStatus::Open, $this->repository->find($payment->id)->status);
    }

    public function testStartingAgainResumesTheOpenMollieCheckout(): void
    {
        $payable = 'mollie-'.bin2hex(random_bytes(6));
        $first = $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
        $payment = $this->repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $posts = \count(array_filter($this->client->requests, static fn (array $r): bool => 'post' === $r['method']));
        $again = $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
        $this->assertSame($first->redirectUrl, $again->redirectUrl);
        $this->assertSame($posts, \count(array_filter($this->client->requests, static fn (array $r): bool => 'post' === $r['method'])), 'No second Mollie payment is created.');

        // Mollie already closed it, locally it is still open: back to the return page.
        $this->client->status = 'expired';
        $closed = $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
        $this->assertSame('https://shop.example/_nw/payment/return/'.$payment->token, $closed->redirectUrl);

        $this->client->metadataPaymentId = $payment->id + 1;
        $this->expectException(\InvalidArgumentException::class);
        $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
    }

    public function testALostRefundResponseIsAdoptedInsteadOfPostedAgain(): void
    {
        $payment = $this->paid(1234);
        $operation = bin2hex(random_bytes(32));
        // Mollie created the refund earlier, the answer got lost, and the idempotency
        // key is gone.
        $this->client->addRefund('5.00', 'refunded', 'EUR', ['payment_id' => (string) $payment->id, 'operation_token' => $operation]);
        $posts = $this->posts();
        $result = $this->service->refund($payment->id, new Money(500), $operation);
        $this->assertSame($this->client->refunds[0]['id'], $result->reference);
        $this->assertSame($posts, $this->posts(), 'No second refund is posted.');
        $stored = $this->repository->find($payment->id);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500], [$stored->status, $stored->refundedCents]);
    }

    public function testNothingIsPostedWhileTheRefundListCannotBeRead(): void
    {
        $payment = $this->paid(1234);
        $operation = bin2hex(random_bytes(32));
        $this->client->failList = true;
        $posts = $this->posts();

        try {
            $this->service->refund($payment->id, new Money(500), $operation);
            $this->fail('The refund was sent although it is unclear whether it already exists.');
        } catch (\RuntimeException) {
        }
        $this->service->reconcile();
        $this->assertSame($posts, $this->posts());
        $row = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]);
        $this->assertIsArray($row);
        $this->assertSame(['', 'pending'], [$row['provider_reference'], $row['status']], 'The operation stays for review.');

        // Once the list is readable and holds nothing for this operation, it is created, once.
        $this->client->failList = false;
        $this->service->reconcile();
        $this->assertSame($posts + 1, $this->posts());
        $this->assertSame(500, $this->repository->find($payment->id)->refundedCents);
    }

    public function testADefinitivelyRejectedRefundIsRecordedAndDoesNotBlockTheNextOne(): void
    {
        $payment = $this->paid(1234);
        $operation = bin2hex(random_bytes(32));
        $this->client->rejectRefunds = true;

        try {
            $this->service->refund($payment->id, new Money(500), $operation);
            $this->fail('Rejection was not reported.');
        } catch (\InvalidArgumentException) {
        }
        $row = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]);
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertNotSame('', $row['reason']);
        $posts = $this->posts();

        try {
            $this->service->refund($payment->id, new Money(500), $operation);
            $this->fail('The rejected operation was repeated.');
        } catch (\InvalidArgumentException) {
        }
        $this->service->reconcile();
        $this->assertSame($posts, $this->posts());

        $this->client->rejectRefunds = false;
        $this->assertTrue($this->service->refund($payment->id, new Money(500), bin2hex(random_bytes(32)))->succeeded);
    }

    public function testStuckRefundsDoNotCrowdOutTheOthersInTheCron(): void
    {
        $payment = $this->start(1234);
        $stuck = $this->start(1234);
        $this->client->status = 'paid';
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($stuck));
        $this->client->refundStatus = 'pending';
        $this->service->refund($payment->id, new Money(500), $operation = bin2hex(random_bytes(32)));

        for ($i = 0; $i < 100; ++$i) {
            $this->db->insert('tl_nw_payment_refund', ['tstamp' => 1, 'created_at' => 1, 'payment_id' => $stuck->id, 'operation_token' => bin2hex(random_bytes(32)), 'amount' => 1, 'provider_reference' => 're_gone'.$i, 'status' => 'pending', 'checked_at' => time()]);
        }
        $this->client->settleRefunds('refunded');
        $this->service->reconcile();
        $this->assertSame('succeeded', $this->db->fetchOne('SELECT status FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]), 'The refund that was never looked at comes first.');
    }

    public function testPaymentOfTheOtherModeIsNeitherReusedNorReplaced(): void
    {
        $payable = 'mollie-'.bin2hex(random_bytes(6));
        $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
        $payment = $this->repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $this->client->status = 'paid';
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));
        $this->settings->save(true, false, false, '', '', null, 'live_local_fixture');

        try {
            $this->service->start('fixture', $payable, new Money(1234), 'mollie', 'https://shop.example', 'Test');
            $this->fail('A paid test payment was reused in live mode.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment WHERE payable_id = ?', [$payable]));
        }
    }

    public function testStripePaymentOfTheOtherModeIsNotReusedEither(): void
    {
        $payable = 'mollie-stripe-'.bin2hex(random_bytes(6));
        $this->settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $this->db->insert('tl_nw_payment', ['tstamp' => time(), 'payable_type' => 'fixture', 'payable_id' => $payable, 'amount' => 1234, 'currency' => 'EUR', 'provider' => 'stripe', 'provider_reference' => 'cs_live_fixture', 'status' => 'paid', 'created_at' => time(), 'updated_at' => time(), 'return_token' => bin2hex(random_bytes(32)), 'test_mode' => 0]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->start('fixture', $payable, new Money(1234), 'stripe', 'https://shop.example', 'Test');
    }

    public function testRefundsMadeInTheMollieDashboardAreFoundWithoutAWebhook(): void
    {
        $payment = $this->paid(1234);
        $this->db->update('tl_nw_payment', ['checked_at' => time() - 3600], ['id' => $payment->id]);
        $this->client->addRefund('5.00', 'refunded');
        $this->service->reconcile();
        $stored = $this->repository->find($payment->id);
        $this->assertSame([PaymentStatus::PartiallyRefunded, 500], [$stored->status, $stored->refundedCents]);

        // Recently looked at payments are left alone.
        $calls = \count($this->client->requests);
        $this->service->reconcile();
        $this->assertSame($calls, \count($this->client->requests));
    }

    public function testWebhookOfAnUnknownPaymentIsAcknowledgedWithoutCallingMollie(): void
    {
        $controller = new WebhookController($this->service);
        $calls = \count($this->client->requests);
        $response = $controller(Request::create('/', 'POST', ['id' => 'tr_stranger123']), 'mollie');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($calls, \count($this->client->requests));
        $this->assertSame(400, $controller(Request::create('/', 'POST', ['id' => 'nonsense']), 'mollie')->getStatusCode());
    }

    public function testWebhookOfOurPaymentAnswers200AlsoWhenRepeated(): void
    {
        $payment = $this->start(1234);
        $this->client->status = 'paid';
        $controller = new WebhookController($this->service);
        $request = static fn (): Request => Request::create('/', 'POST', ['id' => $payment->reference]);
        $this->assertSame(200, $controller($request(), 'mollie')->getStatusCode());
        $this->assertSame(PaymentStatus::Paid, $this->repository->find($payment->id)->status);
        $this->assertSame(200, $controller($request(), 'mollie')->getStatusCode());
    }

    private function posts(): int
    {
        return \count(array_filter($this->client->requests, static fn (array $r): bool => 'post' === $r['method']));
    }

    public function testMethodsListMollieOnlyWhenSetUp(): void
    {
        $this->assertSame('Online bezahlen (Mollie)', $this->service->methods()['mollie'] ?? null);
        $this->settings->save(true, false, true, '', '', false);
        $this->assertArrayNotHasKey('mollie', $this->service->methods());
    }

    private function assertRejected(PaymentEvent $event): void
    {
        try {
            $this->service->apply('mollie', $event);
            $this->fail('A payment event that does not match the stored payment was applied.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_event WHERE provider = ? AND provider_event_id = ?', ['mollie', $event->eventId]));
        }
    }

    private function start(int $cents, string $origin = 'https://shop.example'): Payment
    {
        $payable = 'mollie-'.bin2hex(random_bytes(6));
        $this->service->start('fixture', $payable, new Money($cents), 'mollie', $origin, 'Bestellung');
        $payment = $this->repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);

        return $payment;
    }

    private function paid(int $cents): Payment
    {
        $payment = $this->start($cents);
        $this->client->status = 'paid';
        $this->service->apply('mollie', $this->mollie->fetchSnapshot($payment));

        return $this->repository->find($payment->id);
    }
}

/**
 * Stands in for Mollie: remembers the requests and answers in the shape of the
 * API v2 from a few scriptable fields.
 */
final class ScriptedMollie implements MollieClientInterface
{
    /**
     * @var list<array{method: string, path: string, parameters: array<string, mixed>, key: string|null}>
     */
    public array $requests = [];
    public string $lastId = '';
    public string $mode = 'test';
    public string $status = 'open';
    public int|null $pageSize = null;
    public bool $failList = false;
    public bool $rejectRefunds = false;
    /**
     * @var list<array<string, mixed>>
     */
    public array $refunds = [];
    public string|null $amount = null;
    public string|null $currency = null;
    public int|null $metadataPaymentId = null;
    public string $refundStatus = 'refunded';
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $payments = [];
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $byKey = [];

    /**
     * @return array{method: string, path: string, parameters: array<string, mixed>, key: string|null}
     */
    public function last(): array
    {
        return $this->requests[\count($this->requests) - 1];
    }

    public function request(string $method, string $path, array $parameters = [], string|null $idempotencyKey = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'parameters' => $parameters, 'key' => $idempotencyKey];
        if (null !== $idempotencyKey && isset($this->byKey[$idempotencyKey])) {
            return $this->byKey[$idempotencyKey];
        }
        if ('post' === $method && '/payments' === $path) {
            $this->lastId = bin2hex(random_bytes(5));
            $this->payments['tr_'.$this->lastId] = ['amount' => $parameters['amount'], 'metadata' => $parameters['metadata']];
            $response = $this->payment('tr_'.$this->lastId);
        } elseif ('post' === $method && 1 === preg_match('#^/payments/(tr_\w+)/refunds$#', $path)) {
            if ($this->rejectRefunds) {
                throw new \InvalidArgumentException('Mollie rejected the request (422).');
            }
            $response = ['id' => 're_'.bin2hex(random_bytes(5)), 'status' => $this->refundStatus, 'amount' => $parameters['amount'], 'metadata' => $parameters['metadata']];
            $this->refunds[] = $response;
        } elseif ('get' === $method && 1 === preg_match('#^/payments/(tr_\w+)/refunds$#', $path)) {
            if ($this->failList) {
                throw new \RuntimeException('Mollie unreachable.');
            }
            $from = $parameters['from'] ?? null;
            $rest = $this->refunds;
            if (null !== $from) {
                $index = array_search($from, array_column($rest, 'id'), true);
                $rest = \array_slice($rest, false === $index ? 0 : $index + 1);
            }
            $page = null === $this->pageSize ? $rest : \array_slice($rest, 0, $this->pageSize);
            $response = ['count' => \count($page), '_embedded' => ['refunds' => $page], '_links' => []];
            if (\count($page) < \count($rest)) {
                $response['_links']['next'] = ['href' => 'https://api.mollie.com/v2/payments/x/refunds?from='.$page[\count($page) - 1]['id']];
            }
        } elseif ('get' === $method && 1 === preg_match('#^/payments/(tr_\w+)/refunds/(re_\w+)$#', $path, $match)) {
            $found = array_values(array_filter($this->refunds, static fn (array $r): bool => $r['id'] === $match[2]));
            $response = $found[0] ?? throw new \InvalidArgumentException('Unknown refund');
        } elseif ('get' === $method && 1 === preg_match('#^/payments/(tr_\w+)$#', $path, $match)) {
            $response = $this->payment($match[1]);
        } else {
            throw new \InvalidArgumentException('Unexpected Mollie request '.$method.' '.$path);
        }
        if (null !== $idempotencyKey) {
            $this->byKey[$idempotencyKey] = $response;
        }

        return $response;
    }

    /**
     * @param array<string, string> $metadata
     */
    public function addRefund(string $value, string $status, string $currency = 'EUR', array $metadata = []): void
    {
        $this->refunds[] = ['id' => 're_'.bin2hex(random_bytes(5)), 'status' => $status, 'amount' => ['currency' => $currency, 'value' => $value], 'metadata' => $metadata];
    }

    /**
     * Moves every refund that is still on its way to $status.
     */
    public function settleRefunds(string $status): void
    {
        foreach ($this->refunds as $index => $refund) {
            if (\in_array($refund['status'], ['queued', 'pending', 'processing'], true)) {
                $this->refunds[$index]['status'] = $status;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $id): array
    {
        $stored = $this->payments[$id] ?? throw new \InvalidArgumentException('Unknown payment');
        $amount = ['currency' => $this->currency ?? $stored['amount']['currency'], 'value' => $this->amount ?? $stored['amount']['value']];
        $metadata = null === $this->metadataPaymentId ? $stored['metadata'] : ['payment_id' => (string) $this->metadataPaymentId];
        $payment = ['resource' => 'payment', 'id' => $id, 'mode' => $this->mode, 'status' => $this->status, 'amount' => $amount, 'metadata' => $metadata];
        if ([] !== $this->refunds) {
            // Like the real API (presumably): queued and pending refunds already count.
            $cents = 0;

            foreach ($this->refunds as $refund) {
                if (!\in_array($refund['status'], ['failed', 'canceled'], true)) {
                    $cents += (int) str_replace('.', '', $refund['amount']['value']);
                }
            }
            $payment['amountRefunded'] = ['currency' => $amount['currency'], 'value' => \sprintf('%d.%02d', intdiv($cents, 100), $cents % 100)];
            $payment['_links']['refunds'] = ['href' => 'https://api.mollie.com/v2/payments/'.$id.'/refunds'];
        }
        if ('open' === $this->status) {
            $payment['_links']['checkout'] = ['href' => 'https://checkout.example/'.substr($id, 3), 'type' => 'text/html'];
        }

        return $payment;
    }
}
