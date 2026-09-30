<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\StripeClient;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use Psr\Log\NullLogger;

require __DIR__.'/../../app/vendor/autoload.php';
$parts = parse_url((string) getenv('DATABASE_URL'));
if (!is_array($parts)) {
    throw new RuntimeException('Invalid fixture database URL.');
}
$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $parts['host'] ?? 'db', 'user' => $parts['user'] ?? 'contao', 'password' => $parts['pass'] ?? 'contao', 'dbname' => ltrim($parts['path'] ?? '/contao', '/')]);
$settings = new PaymentSettings($db, new SecretCipher((string) getenv('APP_SECRET')));
$service = new PaymentService($db, new PaymentRepository($db), new BankTransferProvider(), new StripeProvider(new StripeClient($settings), $settings), $settings, [], new NullLogger());
echo "ready\n";

try {
    $service->refund((int) $argv[1], new Money(100), $argv[2]);
    echo "completed\n";
} catch (InvalidArgumentException|RetryableException|UniqueConstraintViolationException) {
    echo "rejected\n";
}
