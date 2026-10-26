<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\PaymentBundle\Migration\SettingsColumnsMigration;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use Nordwerk\PaymentBundle\Settings\SettingsException;
use PHPUnit\Framework\TestCase;

final class SettingsColumnsMigrationTest extends TestCase
{
    private Connection $db;

    /**
     * @var array<string, mixed>|false
     */
    private array|false $original;

    private SecretCipher $cipher;

    protected function setUp(): void
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $this->assertIsArray($parts);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
        $this->original = $this->db->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1');
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        $this->cipher = new SecretCipher((string) getenv('APP_SECRET'));
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('DELETE FROM tl_nw_payment_settings');
        if (false !== $this->original) {
            $this->db->insert('tl_nw_payment_settings', $this->original);
        }
        $this->db->close();
    }

    public function testAnEarlierInstallationKeepsWorkingAfterTheUpdate(): void
    {
        // The state a farm shop on an earlier release leaves behind: everything in the
        // JSON column.
        $legacy = [
            'bankEnabled' => false,
            'stripeEnabled' => true,
            'testMode' => true,
            'secretKey' => $this->cipher->encrypt('sk_test_local_fixture'),
            'webhookSecret' => $this->cipher->encrypt('whsec_local_fixture'),
            'endpointId' => 'we_local_fixture',
            'webhookIdentity' => str_repeat('a', 64),
            'webhookOperation' => str_repeat('b', 64),
            'onSiteEnabled' => true,
            'payPalMe' => 'hofladen',
        ];
        $json = json_encode($legacy, JSON_THROW_ON_ERROR);
        $this->db->insert('tl_nw_payment_settings', ['id' => 1, 'tstamp' => 1700000000, 'data' => $json]);
        $migration = new SettingsColumnsMigration($this->db);
        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());
        $this->assertFalse($migration->shouldRun(), 'The migration runs once.');

        $settings = new PaymentSettings($this->db, $this->cipher);
        $this->assertFalse($settings->enabled('bank_transfer'));
        $this->assertTrue($settings->enabled('stripe'));
        $this->assertTrue($settings->enabled('on_site'));
        $this->assertTrue($settings->testMode());
        $this->assertSame('sk_test_local_fixture', $settings->secretKey());
        $this->assertSame('whsec_local_fixture', $settings->webhookSecret());
        $this->assertSame('hofladen', $settings->payPalMe());
        $this->assertSame([], $settings->missing());
        $stored = array_intersect_key($settings->stored(), $legacy);
        ksort($stored);
        ksort($legacy);
        $this->assertSame($legacy, $stored);
        $this->assertSame($json, $this->db->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1'), 'The JSON column stays untouched for a rollback.');

        // A later change of the JSON column (e.g. by an old code path) is not copied again.
        $this->db->update('tl_nw_payment_settings', ['data' => '{"bankEnabled":true}'], ['id' => 1]);
        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($settings->enabled('bank_transfer'));
    }

    public function testUnreadableJsonFallsBackToTheDefaultsWithoutLosingIt(): void
    {
        $this->db->insert('tl_nw_payment_settings', ['id' => 1, 'tstamp' => 1700000000, 'data' => '{broken']);
        $migration = new SettingsColumnsMigration($this->db);
        $this->assertTrue($migration->shouldRun());
        $migration->run();
        $settings = new PaymentSettings($this->db, $this->cipher);
        $this->assertTrue($settings->enabled('bank_transfer'));
        $this->assertFalse($settings->enabled('stripe'));
        $this->assertSame('{broken', $this->db->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1'));
    }

    public function testNothingToDoWithoutARecord(): void
    {
        $this->assertFalse((new SettingsColumnsMigration($this->db))->shouldRun());
    }

    public function testTheBackendFormRulesMatchTheProgrammaticSave(): void
    {
        $settings = new PaymentSettings($this->db, $this->cipher);
        $settings->ensureRecord();
        $row = static fn (Connection $db): array => $db->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1') ?: [];

        $update = $settings->applyChanges($row($this->db), ['secretKey' => 'sk_test_local_fixture', 'tstamp' => 1]);
        $this->assertNotSame('sk_test_local_fixture', $update['secretKey'], 'Secrets are stored encrypted.');
        $this->assertSame('sk_test_local_fixture', $this->cipher->decrypt($update['secretKey']));
        $this->assertSame(1, $update['tstamp']);
        $this->db->update('tl_nw_payment_settings', $update, ['id' => 1]);

        foreach ([
            'stripeIncomplete' => ['stripeEnabled' => '1'],
            'modeChangeNeedsKey' => ['testMode' => ''],
            'keyModeMismatch' => ['testMode' => '', 'secretKey' => 'sk_test_local_fixture'],
            'invalidWebhookSecret' => ['webhookSecret' => 'nope'],
        ] as $reason => $changes) {
            try {
                $settings->applyChanges($row($this->db), $changes);
                $this->fail('Accepted invalid settings: '.$reason);
            } catch (SettingsException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }

        // A mode change with a matching key drops the webhook data of the other mode.
        $this->db->update('tl_nw_payment_settings', ['webhookSecret' => $this->cipher->encrypt('whsec_local_fixture'), 'endpointId' => 'we_local_fixture'], ['id' => 1]);
        $update = $settings->applyChanges($row($this->db), ['testMode' => '', 'secretKey' => 'sk_live_local_fixture']);
        $this->assertSame('', $update['webhookSecret']);
        $this->assertSame('', $update['endpointId']);
        $this->assertSame('', $update['testMode']);
    }
}
