<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Did;

use Magento\Framework\HTTP\Client\Curl;
use MSR\AgenticUcp\Api\Did\ResolverInterface;

/**
 * Resolves did:ethr DIDs to PEM public keys via the DIF Universal Resolver.
 *
 * did:ethr DIDs represent Ethereum-based identities whose DID documents live
 * on-chain. The Universal Resolver endpoint fetches and returns the DID document
 * over HTTPS so no Ethereum node is required locally.
 *
 * Default endpoint: https://dev.uniresolver.io/1.0/identifiers/{did}
 * Override via the UNIVERSAL_RESOLVER_URL constant or extend this class.
 */
class EthrResolver implements ResolverInterface
{
    private const UNIVERSAL_RESOLVER_URL = 'https://dev.uniresolver.io/1.0/identifiers/';
    private const TIMEOUT_SECONDS        = 5;

    /**
     * @param Curl $curl
     */
    public function __construct(private readonly Curl $curl)
    {
    }

    /**
     * @param string $did
     * @return bool
     */
    public function supports(string $did): bool
    {
        return str_starts_with($did, 'did:ethr:');
    }

    /**
     * @param string $did
     * @return string|null
     */
    public function resolvePublicKey(string $did): ?string
    {
        try {
            $this->curl->setTimeout(self::TIMEOUT_SECONDS);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get(self::UNIVERSAL_RESOLVER_URL . urlencode($did));

            if ($this->curl->getStatus() !== 200) {
                return null;
            }

            $response = json_decode($this->curl->getBody(), true);
            $doc      = $response['didDocument'] ?? $response;

            foreach ($doc['verificationMethod'] ?? [] as $method) {
                if (!empty($method['publicKeyPem'])) {
                    return $method['publicKeyPem'];
                }
                if (!empty($method['publicKeyHex'])) {
                    return $this->hexToPem($method['publicKeyHex']);
                }
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /**
     * Convert a hex-encoded secp256k1 public key to SubjectPublicKeyInfo PEM.
     *
     * Accepts both compressed (33 bytes / 66 hex chars) and uncompressed (65 bytes / 130 hex chars).
     *
     * @param string $hex
     * @return string|null
     */
    private function hexToPem(string $hex): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $key = hex2bin($hex);
        if ($key === false) {
            return null;
        }

        $algorithmSeq = "\x30\x10"
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"  // OID ecPublicKey
            . "\x06\x05\x2b\x81\x04\x00\x0a";           // OID secp256k1

        $der = match (strlen($key)) {
            33 => "\x30\x36" . $algorithmSeq . "\x03\x22\x00" . $key, // compressed
            65 => "\x30\x56" . $algorithmSeq . "\x03\x42\x00" . $key, // uncompressed
            default => null,
        };

        if ($der === null) {
            return null;
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
