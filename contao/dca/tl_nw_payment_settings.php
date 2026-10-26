<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_nw_payment_settings'] = [
    'config' => ['dataContainer' => DC_Table::class, 'closed' => true, 'notEditable' => true, 'notDeletable' => true, 'notCopyable' => true, 'notCreatable' => true, 'sql' => ['keys' => ['id' => 'primary']]],
    'fields' => [
        'id' => ['sql' => 'int unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int unsigned NOT NULL default 0'],
        'data' => ['sql' => 'text NOT NULL'],
    ],
];
