<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Provider\MollieClient;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use Nordwerk\PaymentBundle\Settings\SettingsException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MollieSettingsTest extends TestCase
{
    private Connection $db;
    private PaymentSettings $settings;
    private SecretCipher $cipher;
    /**
     * @var array<string, mixed>|false
     */
    private array|false $original;
    private false|string $apiBase;
    private false|string $apiKey;

    protected function setUp(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $this->original = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1');
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        $this->cipher = new SecretCipher((string) getenv('APP_SECRET'));
        $this->settings = new PaymentSettings($this->db, $this->cipher);
        $this->apiBase = getenv('NW_PAYMENT_MOLLIE_API_BASE');
        $this->apiKey = getenv('NW_PAYMENT_MOLLIE_API_KEY');
        putenv('NW_PAYMENT_MOLLIE_API_KEY');
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        if (false !== $this->original) {
            $this->db->insert('tl_nw_payment_settings', $this->original);
        }
        $this->db->close();
        putenv(false === $this->apiBase ? 'NW_PAYMENT_MOLLIE_API_BASE' : 'NW_PAYMENT_MOLLIE_API_BASE='.$this->apiBase);
        putenv(false === $this->apiKey ? 'NW_PAYMENT_MOLLIE_API_KEY' : 'NW_PAYMENT_MOLLIE_API_KEY='.$this->apiKey);
    }

    public function testMollieIsOffByDefaultAndNeedsAKeyToBeSwitchedOn(): void
    {
        $this->assertFalse($this->settings->enabled('mollie'));
        $this->assertFalse($this->settings->stored()['mollieEnabled']);
        $this->assertSame('', $this->settings->mollieApiKey());

        try {
            $this->settings->save(true, false, true, '', '', true);
            $this->fail('Mollie was switched on without a key.');
        } catch (SettingsException $exception) {
            $this->assertSame('mollieIncomplete', $exception->reason);
        }
        $this->assertFalse($this->settings->enabled('mollie'));

        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        $this->assertTrue($this->settings->enabled('mollie'));
        $this->assertSame('test_local_fixture', $this->settings->mollieApiKey());
        $this->assertNotSame('test_local_fixture', $this->db->fetchOne('SELECT mollieApiKey FROM tl_nw_payment_settings WHERE id = 1'), 'The key is stored encrypted.');
        // Leaving the field empty keeps the key.
        $this->settings->save(true, false, true, '', '');
        $this->assertTrue($this->settings->enabled('mollie'));
    }

    public function testTheKeyPrefixMustMatchTheMode(): void
    {
        foreach ([['live_abc', true], ['test_abc', false], ['sk_test_abc', true]] as [$key, $test]) {
            try {
                $this->settings->save(true, false, $test, '', '', false, $key);
                $this->fail('Accepted '.$key.' for test mode '.($test ? 'on' : 'off'));
            } catch (SettingsException $exception) {
                $this->assertSame('mollieKeyModeMismatch', $exception->reason);
            }
        }
        $this->settings->save(true, false, false, '', '', true, 'live_local_fixture');
        $this->assertTrue($this->settings->enabled('mollie'));
    }

    public function testAMollieOnlyShopIsNotAskedForAStripeKeyOnModeChange(): void
    {
        $this->settings->save(false, false, true, '', '', true, 'test_local_fixture');

        try {
            $this->settings->save(false, false, false, '', '');
            $this->fail('The mode changed without a new Mollie key.');
        } catch (SettingsException $exception) {
            $this->assertSame('modeChangeNeedsMollieKey', $exception->reason);
        }
        $this->assertTrue($this->settings->testMode());

        $this->settings->save(false, false, false, '', '', null, 'live_local_fixture');
        $this->assertFalse($this->settings->testMode());
        $this->assertSame('live_local_fixture', $this->settings->mollieApiKey());
    }

    public function testAStripeOnlyShopIsNotAskedForAMollieKeyOnModeChange(): void
    {
        $this->settings->save(true, false, true, 'sk_test_local_fixture', '');

        try {
            $this->settings->save(true, false, false, '', '');
            $this->fail('The mode changed without a new Stripe key.');
        } catch (SettingsException $exception) {
            $this->assertSame('modeChangeNeedsKey', $exception->reason);
        }
        $this->settings->save(true, false, false, 'sk_live_local_fixture', '');
        $this->assertFalse($this->settings->testMode());
        $this->assertSame('sk_live_local_fixture', $this->settings->secretKey());
    }

    public function testBothKeysAreRequiredWhenBothProvidersHaveOne(): void
    {
        $this->settings->save(true, false, true, 'sk_test_local_fixture', '', false, 'test_local_fixture');

        foreach ([['sk_live_local_fixture', '', 'modeChangeNeedsMollieKey'], ['', 'live_local_fixture', 'modeChangeNeedsKey']] as [$stripe, $mollie, $reason]) {
            try {
                $this->settings->save(true, false, false, $stripe, '', null, $mollie);
                $this->fail('Only one key was given.');
            } catch (SettingsException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
            $this->assertTrue($this->settings->testMode(), 'Nothing is stored when a rule fails.');
        }
        $this->settings->save(true, false, false, 'sk_live_local_fixture', '', null, 'live_local_fixture');
        $this->assertSame(['sk_live_local_fixture', 'live_local_fixture'], [$this->settings->secretKey(), $this->settings->mollieApiKey()]);
    }

    public function testModeChangeWithoutAnyStoredKeyIsAllowedAndOldKeysNeverSurvive(): void
    {
        $this->settings->save(true, false, false, '', '');
        $this->assertFalse($this->settings->testMode());

        $row = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1') ?: [];
        $update = $this->settings->applyChanges($row, ['mollieApiKey' => 'live_local_fixture']);
        $this->assertSame('live_local_fixture', $this->cipher->decrypt($update['mollieApiKey']));
    }

    public function testModeAndKeyCannotChangeWhileMolliePaymentsOrRefundsAreOpen(): void
    {
        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        $open = $this->insertPayment('mollie', 1, 'open');

        try {
            foreach ([['mode', fn () => $this->settings->save(true, false, false, '', '', null, 'live_local_fixture')], ['key', fn () => $this->settings->save(true, false, true, '', '', null, 'test_other_account')]] as [$what, $change]) {
                try {
                    $change();
                    $this->fail('The '.$what.' changed with an open payment.');
                } catch (SettingsException $exception) {
                    $this->assertSame('openPaymentsMollie', $exception->reason);
                }
            }
            $this->assertTrue($this->settings->testMode());
            $this->assertSame('test_local_fixture', $this->settings->mollieApiKey());

            // Settled payments do not matter, an unfinished refund does.
            $this->db->update('tl_nw_payment', ['status' => 'paid'], ['id' => $open]);
            $this->db->insert('tl_nw_payment_refund', ['tstamp' => time(), 'created_at' => time(), 'payment_id' => $open, 'operation_token' => bin2hex(random_bytes(32)), 'amount' => 100, 'provider_reference' => 're_pending1', 'status' => 'pending']);

            try {
                $this->settings->save(true, false, false, '', '', null, 'live_local_fixture');
                $this->fail('The mode changed with an unfinished refund.');
            } catch (SettingsException $exception) {
                $this->assertSame('openPaymentsMollie', $exception->reason);
            }
            $this->db->update('tl_nw_payment_refund', ['status' => 'succeeded', 'succeeded' => 1], ['payment_id' => $open]);
            $this->settings->save(true, false, false, '', '', null, 'live_local_fixture');
            $this->assertFalse($this->settings->testMode());
        } finally {
            $this->db->delete('tl_nw_payment_refund', ['payment_id' => $open]);
            $this->db->delete('tl_nw_payment', ['id' => $open]);
        }
    }

    public function testOpenPaymentsOfAnotherProviderOrModeDoNotBlock(): void
    {
        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        $stripe = $this->insertPayment('stripe', 1, 'open');
        $live = $this->insertPayment('mollie', 0, 'open');

        try {
            $this->settings->save(true, false, false, '', '', null, 'live_local_fixture');
            $this->assertFalse($this->settings->testMode());
        } finally {
            $this->db->delete('tl_nw_payment', ['id' => $stripe]);
            $this->db->delete('tl_nw_payment', ['id' => $live]);
        }
    }

    public function testStripeModeChangeIsBlockedByOpenStripePaymentsToo(): void
    {
        $this->settings->save(true, false, true, 'sk_test_local_fixture', '');
        $open = $this->insertPayment('stripe', 1, 'pending');

        try {
            $this->settings->save(true, false, false, 'sk_live_local_fixture', '');
            $this->fail('The mode changed with an open Stripe payment.');
        } catch (SettingsException $exception) {
            $this->assertSame('openPaymentsStripe', $exception->reason);
        } finally {
            $this->db->delete('tl_nw_payment', ['id' => $open]);
        }
    }

    private function insertPayment(string $provider, int $testMode, string $status): int
    {
        $this->db->insert('tl_nw_payment', ['tstamp' => time(), 'payable_type' => 'fixture', 'payable_id' => 'mollie-settings-'.bin2hex(random_bytes(6)), 'amount' => 1000, 'currency' => 'EUR', 'provider' => $provider, 'provider_reference' => 'tr_settings'.bin2hex(random_bytes(3)), 'status' => $status, 'created_at' => time(), 'updated_at' => time(), 'return_token' => bin2hex(random_bytes(32)), 'test_mode' => $testMode]);

        return (int) $this->db->lastInsertId();
    }

    public function testStartupCheckMentionsMollie(): void
    {
        $this->settings->save(false, false, true, '', '');
        $this->assertSame(['Zahlung: mindestens einen Anbieter einrichten'], $this->settings->missing());
        $this->settings->save(true, false, true, '', '');
        $this->assertSame([], $this->settings->missing());
        $this->settings->save(false, false, true, '', '', true, 'test_local_fixture');
        $this->assertSame([], $this->settings->missing(), 'Mollie alone is enough.');

        $this->db->update('tl_nw_payment_settings', ['mollieApiKey' => null], ['id' => 1]);
        $this->assertSame(['Zahlung: Mollie-API-Key ergänzen'], $this->settings->missing());
        $this->assertFalse($this->settings->enabled('mollie'));
    }

    public function testEnvironmentOverrides(): void
    {
        putenv('NW_PAYMENT_MOLLIE_API_KEY=test_from_environment');
        $this->assertSame('test_from_environment', $this->settings->mollieApiKey());
        putenv('NW_PAYMENT_MOLLIE_API_KEY=live_from_environment');

        try {
            $this->settings->mollieApiKey();
            $this->fail('A live key in test mode was accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->assertFalse($this->settings->enabled('mollie'));
    }

    public function testClientSendsBearerKeyAndIdempotencyKeyAndKeepsTheCustomEndpointToTestMode(): void
    {
        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        putenv('NW_PAYMENT_MOLLIE_API_BASE=http://fake-mollie:8080');
        /** @var array<string, mixed> $seen */
        $seen = [];
        $respond = static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => $options['body'] ?? ''];

            return new MockResponse('{"id":"tr_abcdef"}', ['response_headers' => ['content-type: application/json']]);
        };
        $http = new MockHttpClient($respond);
        $client = new MollieClient($this->settings, $http);
        $this->assertSame(['id' => 'tr_abcdef'], $client->request('post', '/payments', ['description' => 'x'], 'checkout:7'));
        $this->assertSame(['POST', 'http://fake-mollie:8080/v2/payments'], [$seen['method'], $seen['url']]);
        $headers = implode("\n", $seen['headers']);
        $this->assertStringContainsString('Authorization: Bearer test_local_fixture', $headers);
        $this->assertStringContainsString('Idempotency-Key: checkout:7', $headers);

        // A live key never talks to a replaced endpoint.
        $this->settings->save(true, false, false, '', '', null, 'live_local_fixture');
        $seen = ['url' => null];

        try {
            $client->request('get', '/payments/tr_abcdef');
            $this->fail('A custom endpoint was used in live mode.');
        } catch (\RuntimeException) {
            $this->assertSame(['url' => null], $seen, 'No request was sent.');
        }
        putenv('NW_PAYMENT_MOLLIE_API_BASE');
        $client->request('get', '/payments/tr_abcdef');
        $this->assertSame('https://api.mollie.com/v2/payments/tr_abcdef', $seen['url']);
    }

    public function testClientTellsRejectedRequestsFromOutages(): void
    {
        $this->settings->save(true, false, true, '', '', true, 'test_local_fixture');
        putenv('NW_PAYMENT_MOLLIE_API_BASE=http://fake-mollie:8080');
        $answer = fn (int $status): MollieClient => new MollieClient($this->settings, new MockHttpClient(new MockResponse('{"detail":"x"}', ['http_code' => $status, 'response_headers' => ['content-type: application/json']])));

        foreach ([404, 422] as $status) {
            try {
                $answer($status)->request('get', '/payments/tr_abcdef');
                $this->fail('No exception for '.$status);
            } catch (\InvalidArgumentException) {
            }
        }

        foreach ([401, 429, 500, 503] as $status) {
            try {
                $answer($status)->request('get', '/payments/tr_abcdef');
                $this->fail('No exception for '.$status);
            } catch (\InvalidArgumentException) {
                $this->fail('Status '.$status.' must stay retryable.');
            } catch (\RuntimeException) {
            }
        }
    }
}
