<?php

namespace Modules\Platform\Audit;

use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Ed25519 signatures over audit manifests (P1-50, ADR-0006), with PHP's sodium extension.
 *
 * The key comes from a 32-byte seed in `audit.anchor.signing_seed`, which only the auditor service holds (PO decision
 * O43). Its key ID is the first 16 hex characters of SHA-256 over the public key. Verification trusts the current key
 * and the public keys listed in `audit.anchor.trusted_keys` (earlier keys, kept after a rotation; docs/10 §6), never a
 * key named only by the manifest. The secret key exists only while a signature is made.
 */
final class AuditSigner
{
    public function __construct(private readonly Config $config) {}

    public function configured(): bool
    {
        return (string) $this->config->get('audit.anchor.signing_seed') !== '';
    }

    public function keyId(): string
    {
        return self::keyIdOf($this->publicKey());
    }

    /** @return string the signature, 128 hex characters */
    public function sign(string $message): string
    {
        $pair = sodium_crypto_sign_seed_keypair($this->seed());
        $secret = sodium_crypto_sign_secretkey($pair);
        try {
            return bin2hex(sodium_crypto_sign_detached($message, $secret));
        } finally {
            sodium_memzero($secret);
            sodium_memzero($pair);
        }
    }

    /** Whether $signature (hex) is a valid signature of $message by the trusted key with ID $keyId. */
    public function verify(string $message, string $keyId, string $signature): bool
    {
        $publicKey = $this->trustedKeys()[$keyId] ?? null;
        $bytes = preg_match('/^[0-9a-f]{128}$/', $signature) === 1 ? hex2bin($signature) : false;
        if ($publicKey === null || $bytes === false || $bytes === '') {
            return false;
        }

        return sodium_crypto_sign_verify_detached($bytes, $message, $publicKey);
    }

    /**
     * Binary public keys by key ID: the current key (where the seed is configured) and the configured earlier ones.
     * Verification needs only these, never the seed (TASK-0096 #4).
     *
     * @return array<string, non-empty-string>
     */
    public function trustedKeys(): array
    {
        $keys = [];
        if ($this->configured()) {
            $current = $this->publicKey();
            $keys[self::keyIdOf($current)] = $current;
        }
        foreach (array_filter(array_map('trim', explode(',', (string) $this->config->get('audit.anchor.trusted_keys')))) as $hex) {
            $key = preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? hex2bin($hex) : false;
            if ($key === false || $key === '') {
                throw new RuntimeException('AUDIT_TRUSTED_KEYS must list public keys of 64 lowercase hex characters, separated by commas.');
            }
            $keys[self::keyIdOf($key)] = $key;
        }

        return $keys;
    }

    /** The current public key, 64 hex characters (for the rotation runbook). */
    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey());
    }

    public static function keyIdOf(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** @return non-empty-string */
    private function publicKey(): string
    {
        $pair = sodium_crypto_sign_seed_keypair($this->seed());
        try {
            return sodium_crypto_sign_publickey($pair);
        } finally {
            sodium_memzero($pair);
        }
    }

    /** @return non-empty-string */
    private function seed(): string
    {
        $hex = (string) $this->config->get('audit.anchor.signing_seed');
        if ($hex === '') {
            throw new RuntimeException('No audit signing key here: audit manifests are signed and verified by the auditor service (docker compose exec auditor php artisan ...).');
        }
        $seed = preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? hex2bin($hex) : false;
        if ($seed === false || $seed === '') {
            throw new RuntimeException('AUDIT_SIGNING_SEED must be 64 lowercase hex characters.');
        }

        return $seed;
    }
}
