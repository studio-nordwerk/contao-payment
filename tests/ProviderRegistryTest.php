<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\CheckoutResult;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentRequest;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundResult;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\OnSiteProvider;
use Nordwerk\PaymentBundle\Settings\BankTransferSettings;
use Nordwerk\PaymentBundle\Settings\OnSiteSettings;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\ProviderSettingsInterface;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use Nordwerk\PaymentBundle\Settings\SettingsChange;
use Nordwerk\PaymentBundle\Settings\SettingsException;
use Nordwerk\PaymentBundle\Settings\SettingsView;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * A further provider only needs its provider class and its settings section: the
 * checkout, the setup check and the rules of a change pick it up.
 */
final class ProviderRegistryTest extends TestCase
{
    public function testAnAdditionalProviderIsOfferedCheckedAndValidatedWithoutChangesElsewhere(): void
    {
        $cipher = new SecretCipher('registry-test');
        $row = ['testMode' => '1', 'bankEnabled' => '', 'onSiteEnabled' => '1', 'fakeEnabled' => '1', 'fakeKey' => $cipher->encrypt('fake_test_1')];
        $db = $this->createStub(Connection::class);
        $db
            ->method('fetchAssociative')
            ->willReturn($row)
        ;
        $settings = new PaymentSettings($db, $cipher, [new OnSiteSettings(), new FakeSettings(), new BankTransferSettings()]);
        $service = new PaymentService($db, new PaymentRepository($db), $settings, [new OnSiteProvider(), new FakeProvider(), new BankTransferProvider()], [], new NullLogger());

        $this->assertSame(['fake' => 'Online bezahlen (Fake)', 'on_site' => 'Bezahlung vor Ort'], $service->methods());
        $this->assertSame('Fake', $service->name('fake'));
        $this->assertTrue($service->online('fake'));
        $this->assertFalse($service->manual('fake'));
        $this->assertSame([], $settings->missing());
        $this->assertContains('fakeKey', $settings->secretFields());

        try {
            $settings->applyChanges($row, ['fakeKey' => 'fake_live_1']);
            $this->fail('A key of the other mode was accepted.');
        } catch (SettingsException $exception) {
            $this->assertSame('fakeKeyMode', $exception->reason);
        }
        $update = $settings->applyChanges($row, ['fakeKey' => 'fake_test_2']);
        $this->assertSame('fake_test_2', $cipher->decrypt($update['fakeKey']));

        // Payment on site alone is no complete setup.
        $without = $row;
        $without['fakeEnabled'] = '';
        $db = $this->createStub(Connection::class);
        $db
            ->method('fetchAssociative')
            ->willReturn($without)
        ;
        $this->assertSame(['Zahlung: mindestens einen Anbieter einrichten'], (new PaymentSettings($db, $cipher, [new OnSiteSettings(), new FakeSettings(), new BankTransferSettings()]))->missing());
    }

    public function testTheSetupCheckReadsTheRecordOnce(): void
    {
        $cipher = new SecretCipher('registry-test');
        $db = $this->createMock(Connection::class);
        $db
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['stripeEnabled' => '1', 'mollieEnabled' => '1', 'secretKey' => 'invalid'])
        ;

        $this->assertSame(['Zahlung: Stripe-Schlüssel neu eingeben', 'Zahlung: Mollie-API-Key ergänzen'], (new PaymentSettings($db, $cipher))->missing());
    }

    public function testUnreadableOrWrongModeKeysAreReportedAlsoWhileTheProviderIsOff(): void
    {
        $cipher = new SecretCipher('registry-test');
        $db = $this->createStub(Connection::class);
        $db
            ->method('fetchAssociative')
            ->willReturn(['testMode' => '1', 'mollieApiKey' => $cipher->encrypt('live_key'), 'webhookSecret' => 'invalid'])
        ;

        $this->assertSame(['Zahlung: Stripe-Schlüssel neu eingeben', 'Zahlung: Mollie-API-Key oder Testmodus prüfen'], (new PaymentSettings($db, $cipher))->missing());
    }

    public function testColumnsWithoutProviderSectionAreNeverStored(): void
    {
        $settings = new PaymentSettings($this->createStub(Connection::class), new SecretCipher('registry-test'), [new BankTransferSettings()]);

        $this->expectException(\LogicException::class);
        $settings->applyChanges([], ['secretKey' => 'sk_test_plaintext']);
    }

    public function testEnvironmentOverridesWorkWithoutReadingTheTable(): void
    {
        $db = $this->createStub(Connection::class);
        $db
            ->method('fetchAssociative')
            ->willThrowException(new \RuntimeException('Table unavailable.'))
        ;
        $settings = new PaymentSettings($db, new SecretCipher('registry-test'));
        putenv('NW_PAYMENT_STRIPE_TEST_MODE=1');
        putenv('NW_PAYMENT_STRIPE_SECRET_KEY=sk_test_override');

        try {
            $this->assertTrue($settings->testMode());
            $this->assertSame('sk_test_override', $settings->secret('secretKey'));
        } finally {
            putenv('NW_PAYMENT_STRIPE_TEST_MODE');
            putenv('NW_PAYMENT_STRIPE_SECRET_KEY');
        }
    }
}

final class FakeProvider implements PaymentProviderInterface
{
    public static function id(): string
    {
        return 'fake';
    }

    public static function priority(): int
    {
        return 10;
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function label(): string
    {
        return 'Online bezahlen (Fake)';
    }

    public function online(): bool
    {
        return true;
    }

    public function createCheckout(PaymentRequest $request): CheckoutResult
    {
        return new CheckoutResult('fake:'.$request->payment->id, 'https://fake.test/pay');
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        throw new \InvalidArgumentException('No webhook.');
    }

    public function refund(Payment $payment, Money $money, string $operation): RefundResult
    {
        throw new \InvalidArgumentException('No refunds.');
    }

    public function fetchStatus(Payment $payment): PaymentStatus
    {
        return $payment->status;
    }
}

final class FakeSettings implements ProviderSettingsInterface
{
    public static function provider(): string
    {
        return 'fake';
    }

    public static function priority(): int
    {
        return 10;
    }

    public function switches(): array
    {
        return ['fakeEnabled' => false];
    }

    public function secrets(): array
    {
        return ['fakeKey' => ''];
    }

    public function values(): array
    {
        return [];
    }

    public function checkSecret(string $field, string $value, bool $testMode): void
    {
        if ('' !== $value && !str_starts_with($value, $testMode ? 'fake_test_' : 'fake_live_')) {
            throw new \InvalidArgumentException('Mode mismatch.');
        }
    }

    public function enabled(SettingsView $settings): bool
    {
        return $settings->switch('fakeEnabled') && '' !== $settings->secret('fakeKey');
    }

    public function standalone(): bool
    {
        return true;
    }

    public function missing(SettingsView $settings): array
    {
        return [];
    }

    public function apply(SettingsChange $change): array
    {
        $key = $change->submitted('fakeKey');

        try {
            $this->checkSecret('fakeKey', $key, $change->testMode());
        } catch (\InvalidArgumentException) {
            throw new SettingsException('fakeKeyMode', 'Fake key does not fit the mode.');
        }

        return '' === $key ? [] : ['fakeKey' => $key];
    }
}
