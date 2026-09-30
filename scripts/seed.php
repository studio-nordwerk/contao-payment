<?php

declare(strict_types=1);

require __DIR__.'/../app/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$parts = parse_url((string) getenv('DATABASE_URL'));
$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'], 'user' => $parts['user'], 'password' => $parts['pass'], 'dbname' => ltrim($parts['path'], '/')]);
$email = (string) getenv('CONTAO_ADMIN_EMAIL');
$password = (string) getenv('CONTAO_ADMIN_PASSWORD');
if ('' === $password || 'change-me' === $password) {
    throw new RuntimeException('Configure disposable admin credentials first.');
}
if (!$db->fetchOne('SELECT id FROM tl_user WHERE username = ?', [$email])) {
    $db->insert('tl_user', ['tstamp' => time(), 'username' => $email, 'name' => 'Payment Demo Admin', 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT), 'admin' => 1, 'dateAdded' => time(), 'language' => 'de']);
}
$db->executeStatement("INSERT IGNORE INTO tl_nw_payment (id, tstamp, payable_type, payable_id, amount, currency, provider, provider_reference, status, created_at, updated_at, return_token, test_mode) VALUES (1, ?, 'demo', 'demo-1', 1500, 'EUR', 'bank_transfer', 'manual:1', 'open', ?, ?, ?, 1)", [time(), time(), time(), str_repeat('a', 64)]);
echo "Payment demo seeded.\n";
