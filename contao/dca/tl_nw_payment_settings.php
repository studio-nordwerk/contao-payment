<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

/*
 * One settings record (id 1). The module "nw_payment_settings" opens it in the standard edit form; the Stripe
 * webhook is registered in its own view under the same module (key=webhook).
 *
 * Access: the module permission, the "update" permission for this table and the usual field permissions
 * ("Allowed fields"), so a group may switch payment methods without seeing or replacing the Stripe secrets.
 * None of this is granted by default; administrators have full access.
 *
 * Secrets are write-only: the form never shows them (load_callback), "show" and version diffs hide them
 * (hideInput), and they are excluded from versioning, so tl_version never holds them and restoring an old
 * version cannot bring back a replaced key or a webhook secret of another endpoint. Restoring a version is
 * only allowed to users who may edit every versioned field (SettingsDataContainer::openRecord()).
 */
$GLOBALS['TL_DCA']['tl_nw_payment_settings'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'enableVersioning' => true,
        'notCreatable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        // Only one record exists, so create and delete do not apply.
        'permissions' => ['update'],
        'sql' => ['keys' => ['id' => 'primary']],
    ],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_UNSORTED],
        'label' => ['fields' => ['id'], 'format' => '%s'],
        'operations' => ['edit', 'show'],
    ],
    'palettes' => [
        'default' => '{provider_legend},bankEnabled,stripeEnabled,testMode;{onsite_legend},onSiteEnabled,payPalMe;{stripe_legend},secretKey,webhookSecret,stripeWebhook',
    ],
    'fields' => [
        'id' => ['sql' => 'int unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int unsigned NOT NULL default 0'],
        // Former JSON blob, read once by SettingsColumnsMigration and no longer used. It
        // stays for one release so an update can be rolled back; remove the column in
        // the next minor release.
        'data' => ['eval' => ['versionize' => false, 'doNotShow' => true], 'sql' => 'text NULL'],
        'bankEnabled' => ['inputType' => 'checkbox', 'eval' => ['tl_class' => 'w50 m12'], 'sql' => "char(1) NOT NULL default '1'"],
        // Mode and Stripe switch belong to the current credentials (see applyChanges()),
        // so restoring an older version must not change them.
        'stripeEnabled' => ['inputType' => 'checkbox', 'eval' => ['versionize' => false, 'tl_class' => 'w50 m12'], 'sql' => "char(1) NOT NULL default ''"],
        'testMode' => ['inputType' => 'checkbox', 'eval' => ['versionize' => false, 'tl_class' => 'w50 m12'], 'sql' => "char(1) NOT NULL default '1'"],
        'onSiteEnabled' => ['inputType' => 'checkbox', 'eval' => ['tl_class' => 'w50 m12'], 'sql' => "char(1) NOT NULL default ''"],
        'payPalMe' => ['inputType' => 'text', 'eval' => ['maxlength' => 255, 'decodeEntities' => true, 'placeholder' => 'paypal.me/IhrName', 'tl_class' => 'w50'], 'sql' => "varchar(255) NOT NULL default ''"],
        'secretKey' => [
            'inputType' => 'text',
            'eval' => ['hideInput' => true, 'doNotSaveEmpty' => true, 'versionize' => false, 'autocomplete' => 'new-password', 'decodeEntities' => true, 'tl_class' => 'w50'],
            'sql' => 'text NULL',
        ],
        'webhookSecret' => [
            'inputType' => 'text',
            'eval' => ['hideInput' => true, 'doNotSaveEmpty' => true, 'versionize' => false, 'autocomplete' => 'new-password', 'decodeEntities' => true, 'tl_class' => 'w50'],
            'sql' => 'text NULL',
        ],
        // Link to the webhook view; not a stored value.
        'stripeWebhook' => ['exclude' => false],
        // Technical webhook state, maintained by the webhook registration.
        'endpointId' => ['eval' => ['versionize' => false], 'sql' => "varchar(255) NOT NULL default ''"],
        'webhookIdentity' => ['eval' => ['versionize' => false], 'sql' => "varchar(64) NOT NULL default ''"],
        'webhookOperation' => ['eval' => ['versionize' => false], 'sql' => "varchar(64) NOT NULL default ''"],
        // Set by SettingsColumnsMigration once the values of the JSON blob have been copied.
        'legacyMigrated' => ['eval' => ['versionize' => false], 'sql' => "char(1) NOT NULL default ''"],
    ],
];
