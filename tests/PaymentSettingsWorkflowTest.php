<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Provider\StripeClientInterface;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\TestCase;

final class PaymentSettingsWorkflowTest extends TestCase
{
    private Connection $db;
    private PaymentSettings $settings;
    private mixed $original;

    protected function setUp(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $this->original = $this->db->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1');
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        $this->settings = new PaymentSettings($this->db, new SecretCipher((string) getenv('APP_SECRET')));
        $this->settings->save(true, true, true, 'sk_test_local_fixture', 'whsec_local_fixture');
        $this->settings->saveWebhook('whsec_local_fixture', 'we_test_fixture');
        $this->settings->prepareWebhook('https://example.test/_nw/payment/webhook/stripe');
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        if (false !== $this->original) {
            $this->db->insert('tl_nw_payment_settings', ['id' => 1, 'tstamp' => time(), 'data' => $this->original]);
        }
        $this->db->close();
    }

    public function testDisabledLiveSetupCanCreateWebhookAndThenEnableStripe(): void
    {
        $this->settings->save(true, false, false, 'sk_live_local_fixture', '');
        $this->assertSame('sk_live_local_fixture', $this->settings->secretKey());
        $this->assertSame('', $this->settings->webhookSecret());
        $this->assertArrayNotHasKey('endpointId', $this->settings->stored());
        $this->assertArrayNotHasKey('webhookOperation', $this->settings->stored());
        $this->assertFalse($this->settings->enabled('stripe'));
        $client = $this->createMock(StripeClientInterface::class);
        $client
            ->expects($this->once())
            ->method('request')
            ->with('post', '/v1/webhook_endpoints', ['url' => 'https://example.test/_nw/payment/webhook/stripe', 'enabled_events' => StripeProvider::EVENTS], $this->isType('string'))
            ->willReturn(['id' => 'we_live_fixture', 'secret' => 'whsec_live_fixture', 'livemode' => true])
        ;
        (new StripeProvider($client, $this->settings))->registerWebhook('https://example.test/_nw/payment/webhook/stripe');
        $this->settings->save(true, true, false, '', '');
        $this->assertTrue($this->settings->enabled('stripe'));
        $this->assertSame('we_live_fixture', $this->settings->stored()['endpointId']);
    }

    public function testIncompleteLiveSetupCannotEnableStripeOrRetainTestSecrets(): void
    {
        try {
            $this->settings->save(true, true, false, 'sk_live_local_fixture', '');
            $this->fail('Incomplete live Stripe setup was activated.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue($this->settings->testMode());
            $this->assertSame('whsec_local_fixture', $this->settings->webhookSecret());
        }
    }

    public function testReadinessRequestsKeyReentryAfterAppSecretRotation(): void
    {
        $rotated = new PaymentSettings($this->db, new SecretCipher('rotated-app-secret-fixture'));
        $this->assertFalse($rotated->enabled('stripe'));
        $this->assertContains('Zahlung: Stripe-Schlüssel neu eingeben', $rotated->missing());
    }
}
