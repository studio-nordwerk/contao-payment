<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\StripeClient;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiConnectionException;
use Symfony\Component\Process\Process;

final class DurableRefundTest extends TestCase
{
    public function testConcurrentTokenReuseIsBoundBeforeEitherProviderCall(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $settings = new PaymentSettings($db, new SecretCipher((string) getenv('APP_SECRET')));
        $settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $repository = new PaymentRepository($db);
        $service = new PaymentService($db, $repository, new BankTransferProvider(), new StripeProvider(new StripeClient($settings), $settings), $settings, [], new NullLogger());
        $base = (string) getenv('NW_PAYMENT_STRIPE_API_BASE');
        $payments = [];

        for ($i = 0; $i < 2; ++$i) {
            $payable = 'parallel-'.bin2hex(random_bytes(8));
            $checkout = $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Parallel refund fixture');
            $payment = $repository->forPayable('fixture', $payable);
            $this->assertNotNull($payment);
            file_get_contents($base.'/checkout/'.$checkout->reference, false, stream_context_create(['http' => ['method' => 'POST', 'content' => 'action=pay', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'follow_location' => 0]]));
            $service->apply('stripe', new PaymentEvent('parallel-paid:'.$payable, $payment->id, $payment->reference, PaymentStatus::Paid, $payment->money));
            $payments[] = $payment;
        }
        $operation = bin2hex(random_bytes(32));
        $db->beginTransaction();
        $children = [];

        try {
            foreach ($payments as $payment) {
                $repository->find($payment->id, true);
                $child = new Process([PHP_BINARY, __DIR__.'/Support/refund.php', (string) $payment->id, $operation]);
                $child->start();
                $child->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
                $children[] = $child;
            }
        } finally {
            $db->commit();
        }
        $completed = 0;

        foreach ($children as $child) {
            $child->wait();
            $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $completed += str_contains($child->getOutput(), 'completed') ? 1 : 0;
        }
        $this->assertSame(1, $completed);
        $moved = 0;

        foreach ($payments as $payment) {
            $session = json_decode((string) file_get_contents($base.'/_fixture/session/'.$payment->reference), true, 512, JSON_THROW_ON_ERROR);
            $moved += (int) ($session['amount_refunded'] ?? 0);
        }
        $this->assertSame(100, $moved);
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]));
        $db->close();
    }

    public function testLostRefundResponseSurvivesRollbackAndWebhookBeforeRetry(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $settings = new PaymentSettings($db, new SecretCipher((string) getenv('APP_SECRET')));
        $settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $repository = new PaymentRepository($db);
        $stripe = new StripeProvider(new StripeClient($settings), $settings);
        $service = new PaymentService($db, $repository, new BankTransferProvider(), $stripe, $settings, [], new NullLogger());
        $payable = 'timeout-'.bin2hex(random_bytes(8));
        $checkout = $service->start('fixture', $payable, new Money(1000), 'stripe', 'https://example.test', 'Timeout fixture');
        $payment = $repository->forPayable('fixture', $payable);
        $this->assertNotNull($payment);
        $base = (string) getenv('NW_PAYMENT_STRIPE_API_BASE');
        $post = static function (string $path, array $data) use ($base): void {
            file_get_contents($base.$path, false, stream_context_create(['http' => ['method' => 'POST', 'content' => http_build_query($data), 'header' => 'Content-Type: application/x-www-form-urlencoded', 'follow_location' => 0]]));
        };
        $post('/checkout/'.$checkout->reference, ['action' => 'pay']);
        $service->apply('stripe', new PaymentEvent('test-paid:'.$payable, $payment->id, $payment->reference, PaymentStatus::Paid, $payment->money));
        $post('/_fixture/refund-timeout', ['payment_id' => $payment->id]);
        $operation = bin2hex(random_bytes(32));

        try {
            $service->refund($payment->id, new Money(100), $operation);
            $this->fail('The fixture must lose the refund response.');
        } catch (ApiConnectionException) {
            $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_refund WHERE operation_token = ?', [$operation]), 'Operation must survive the API transaction rollback.');
        }
        // The charge webhook arrives before the merchant repeats the original POST.
        $service->apply('stripe', new PaymentEvent('test-refunded:'.$payable, $payment->id, $payment->reference, PaymentStatus::PartiallyRefunded, $payment->money, 100));
        $this->assertTrue($service->refund($payment->id, new Money(100), $operation)->succeeded);
        $session = json_decode((string) file_get_contents($base.'/_fixture/session/'.$checkout->reference), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(100, $session['amount_refunded']);
        $this->assertSame(100, $repository->find($payment->id)->refundedCents);
        $db->close();
    }
}
