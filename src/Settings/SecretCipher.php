<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

final readonly class SecretCipher
{
    public function __construct(private string $appSecret)
    {
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, hash('sha256', $this->appSecret, true)));
    }

    public function decrypt(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if (false === $bytes || \strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Payment secret cannot be decrypted.');
        }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', $this->appSecret, true));
        if (false === $plain) {
            throw new \RuntimeException('Payment secret cannot be decrypted.');
        }

        return $plain;
    }
}
