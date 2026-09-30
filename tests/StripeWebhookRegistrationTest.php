<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Provider\StripeClientInterface;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\TestCase;

final class StripeWebhookRegistrationTest extends TestCase
{
    public function testLostResponseAndRepeatedRegistrationKeepOneEndpointAndItsSecret(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $original = $db->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1');
        $db->executeStatement('DELETE FROM tl_nw_payment_settings');

        try {
            $settings = new PaymentSettings($db, new SecretCipher((string) getenv('APP_SECRET')));
            $settings->save(true, false, true, 'sk_test_local_fixture', '');
            $endpoints = [];
            $keys = [];
            $loseResponse = true;
            $client = $this->createMock(StripeClientInterface::class);
            $client
                ->method('request')
                ->willReturnCallback(
                    static function (string $method, string $path, array $parameters = [], string|null $key = null) use (&$endpoints, &$keys, &$loseResponse): array {
                        if ('post' === $method && '/v1/webhook_endpoints' === $path) {
                            $keys[] = $key;
                            $index = $key ?? bin2hex(random_bytes(8));
                            $endpoints[$index] ??= ['id' => 'we_'.bin2hex(random_bytes(8)), 'secret' => 'whsec_'.bin2hex(random_bytes(8)), 'livemode' => false, 'url' => $parameters['url']];
                            if ($loseResponse) {
                                $loseResponse = false;

                                throw new \RuntimeException('Lost response after provider created endpoint.');
                            }

                            return $endpoints[$index];
                        }
                        $endpoint = array_values($endpoints)[0];
                        unset($endpoint['secret']);

                        return $endpoint;
                    },
                )
            ;
            $stripe = new StripeProvider($client, $settings);

            try {
                $stripe->registerWebhook('https://example.test/_nw/payment/webhook/stripe');
                $this->fail('Expected lost response.');
            } catch (\RuntimeException) {
            }
            $stripe->registerWebhook('https://example.test/_nw/payment/webhook/stripe');
            $secret = $settings->webhookSecret();
            $endpoint = $settings->stored()['endpointId'];
            $stripe->registerWebhook('https://example.test/_nw/payment/webhook/stripe');
            $this->assertCount(1, $endpoints);
            $this->assertCount(2, $keys);
            $this->assertNotNull($keys[0]);
            $this->assertSame($keys[0], $keys[1]);
            $this->assertSame($secret, $settings->webhookSecret());
            $this->assertSame($endpoint, $settings->stored()['endpointId']);
        } finally {
            $db->executeStatement('DELETE FROM tl_nw_payment_settings');
            if (false !== $original) {
                $db->insert('tl_nw_payment_settings', ['id' => 1, 'tstamp' => time(), 'data' => $original]);
            }
            $db->close();
        }
    }
}
