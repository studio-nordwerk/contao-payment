<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;

/**
 * Copies the settings of earlier releases from the JSON column "data" into the
 * regular columns of tl_nw_payment_settings. Runs once per record (marker
 * "legacyMigrated"), after the schema update has added the columns. Encrypted
 * secrets are copied unchanged, so they stay readable with the same APP_SECRET.
 * The JSON column itself is left untouched; it is removed in a later release.
 */
final class SettingsColumnsMigration extends AbstractMigration
{
    private const TABLE = 'tl_nw_payment_settings';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist([self::TABLE])) {
            return false;
        }
        $table = $schema->introspectTable(self::TABLE);

        foreach (['data', 'legacyMigrated', ...array_keys(PaymentSettings::SWITCHES), ...PaymentSettings::VALUES] as $column) {
            if (!$table->hasColumn($column)) {
                return false;
            }
        }

        return false !== $this->connection->fetchOne('SELECT id FROM tl_nw_payment_settings WHERE id = 1 AND legacyMigrated = ?', ['']);
    }

    public function run(): MigrationResult
    {
        $this->connection->transactional(
            function (): void {
                $row = $this->connection->fetchAssociative('SELECT data, legacyMigrated FROM tl_nw_payment_settings WHERE id = 1 FOR UPDATE');
                if (false === $row || '' !== (string) $row['legacyMigrated']) {
                    return;
                }
                $values = ['legacyMigrated' => '1'];

                foreach (self::decode($row['data']) as $key => $value) {
                    if (\array_key_exists($key, PaymentSettings::SWITCHES) && \is_bool($value)) {
                        $values[$key] = $value ? '1' : '';
                    } elseif (\in_array($key, PaymentSettings::VALUES, true) && \is_string($value)) {
                        $values[$key] = $value;
                    }
                }
                $this->connection->update(self::TABLE, $values, ['id' => 1]);
            },
        );

        return $this->createResult(true, 'Payment settings moved from the JSON column to regular fields.');
    }

    /**
     * Unreadable or non-object JSON yields no values; the original stays in "data".
     *
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        if (!\is_string($json) || '' === $json) {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) && !array_is_list($data) ? $data : [];
    }
}
