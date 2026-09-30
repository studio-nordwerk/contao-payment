<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

final class EventAuditGuardMigration extends AbstractMigration
{
    private const TABLES = ['tl_nw_payment_event'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        if (!$this->connection->createSchemaManager()->tablesExist(self::TABLES)) {
            return false;
        }

        return \count($this->installed()) < \count(self::TABLES) * 2;
    }

    public function run(): MigrationResult
    {
        $installed = $this->installed();

        foreach (self::TABLES as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_no_'.strtolower($operation);
                if (\in_array($name, $installed, true)) {
                    continue;
                }
                $this->connection->executeStatement("CREATE TRIGGER `$name` BEFORE $operation ON `$table` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment event records are immutable'");
            }
        }

        return $this->createResult(true);
    }

    /**
     * @return list<string>
     */
    private function installed(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'tl_nw_payment_event%_no_%'",
        );
    }
}
