<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_nw_payment_refund'] = [
    'config' => ['dataContainer' => DC_Table::class, 'closed' => true, 'notEditable' => true, 'notDeletable' => true, 'notCopyable' => true, 'notCreatable' => true, 'sql' => ['keys' => ['id' => 'primary', 'operation_token' => 'unique', 'payment_id' => 'index']]],
    'fields' => [
        'id' => ['sql' => 'int unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int unsigned NOT NULL default 0'],
        'payment_id' => ['sql' => 'int unsigned NOT NULL'],
        'operation_token' => ['sql' => 'varchar(64) NOT NULL'],
        'base_refunded_amount' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'created_at' => ['sql' => 'int unsigned NOT NULL default 0'],
        'amount' => ['sql' => 'bigint unsigned NOT NULL'],
        'provider_reference' => ['sql' => 'varchar(255) NOT NULL'],
        'status' => ['sql' => "varchar(32) NOT NULL default 'pending'"],
        // Last look of the cron at this operation, so that stuck operations do not crowd
        // out the others.
        'checked_at' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        // Why the provider definitively rejected the refund (status failed without
        // a reference).
        'reason' => ['sql' => "varchar(255) NOT NULL default ''"],
        'succeeded' => ['sql' => "char(1) NOT NULL default ''"],
    ],
];
