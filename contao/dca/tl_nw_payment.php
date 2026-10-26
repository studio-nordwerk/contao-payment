<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_nw_payment'] = [
    'config' => ['dataContainer' => DC_Table::class, 'closed' => true, 'notEditable' => true, 'notDeletable' => true, 'notCopyable' => true, 'notCreatable' => true, 'sql' => ['keys' => ['id' => 'primary', 'payable_type,payable_id' => 'unique', 'return_token' => 'unique', 'status,created_at' => 'index']]],
    'fields' => [
        'id' => ['sql' => 'int unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int unsigned NOT NULL default 0'],
        'payable_type' => ['sql' => "varchar(64) NOT NULL default ''"],
        'payable_id' => ['sql' => "varchar(128) NOT NULL default ''"],
        'amount' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'currency' => ['sql' => "varchar(3) NOT NULL default 'EUR'"],
        'provider' => ['sql' => "varchar(32) NOT NULL default ''"],
        'provider_reference' => ['sql' => "varchar(255) NOT NULL default ''"],
        'status' => ['sql' => "varchar(32) NOT NULL default 'open'"],
        'created_at' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'checked_at' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'updated_at' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'refunded_amount' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'return_token' => ['sql' => "varchar(64) NOT NULL default ''"],
        'test_mode' => ['sql' => "char(1) NOT NULL default '1'"],
    ],
];
