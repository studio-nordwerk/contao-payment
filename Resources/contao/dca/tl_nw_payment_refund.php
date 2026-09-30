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
        'amount' => ['sql' => 'bigint unsigned NOT NULL'],
        'provider_reference' => ['sql' => 'varchar(255) NOT NULL'],
        'succeeded' => ['sql' => "char(1) NOT NULL default ''"],
    ],
];
