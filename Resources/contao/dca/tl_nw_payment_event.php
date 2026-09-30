<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_nw_payment_event'] = [
    'config' => ['dataContainer' => DC_Table::class, 'closed' => true, 'notEditable' => true, 'notDeletable' => true, 'notCopyable' => true, 'notCreatable' => true, 'sql' => ['keys' => ['id' => 'primary', 'provider,provider_event_id' => 'unique', 'payment_id' => 'index']]],
    'fields' => [
        'id' => ['sql' => 'int unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int unsigned NOT NULL default 0'],
        'payment_id' => ['sql' => 'int unsigned NOT NULL'],
        'provider' => ['sql' => 'varchar(32) NOT NULL'],
        'provider_event_id' => ['sql' => 'varchar(255) NOT NULL'],
        'status' => ['sql' => 'varchar(32) NOT NULL'],
        'refunded_amount' => ['sql' => 'bigint unsigned NOT NULL default 0'],
        'received_at' => ['sql' => 'bigint unsigned NOT NULL'],
        'applied' => ['sql' => "char(1) NOT NULL default ''"],
    ],
];
