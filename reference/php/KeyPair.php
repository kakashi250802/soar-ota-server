<?php

namespace App\Ota;

use InvalidArgumentException;

/**
 * An ed25519 key as `soar keygen` writes it: the secret file holds the 32-byte seed as 64
 * lowercase hex characters, the public file the 32-byte public key as 64 lowercase hex.
 */
final class KeyPair
{
    private function __construct(
        private readonly string $secretKey, // libsodium 64-byte secret key (seed || public)
        public readonly string $publicKey,  // 32 raw bytes
    ) {
    }

    public static function fromSeedHex(string $seedHex): self
    {
        $seed = Hex::decode(trim($seedHex), 32);
        $pair = sodium_crypto_sign_seed_keypair($seed);

        return new self(sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair));
    }

    public static function generate(): array
    {
        $seed = random_bytes(32);

        return [self::fromSeedHex(bin2hex($seed)), bin2hex($seed)];
    }

    public function publicHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /** Detached signature, 64 raw bytes. Ed25519 is deterministic: same input, same bytes. */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /** Checks a detached signature against a public key given as lowercase hex. */
    public static function verify(string $message, string $signature, string $publicKeyHex): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            $pub = Hex::decode($publicKeyHex, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        } catch (InvalidArgumentException) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $pub);
    }
}
