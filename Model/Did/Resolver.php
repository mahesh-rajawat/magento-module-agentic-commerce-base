<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  LOCAL DEV MODE — MODIFIED FOR TESTING                   ║
 * ║  File: Model/Did/Resolver.php                            ║
 * ╠══════════════════════════════════════════════════════════╣
 * ║  What changed:                                           ║
 * ║    resolvePublicKey() returns a hardcoded local key      ║
 * ║    instead of fetching the DID document over HTTP.       ║
 * ║                                                          ║
 * ║  To revert for production commit:                        ║
 * ║    1. Delete the // DEV MODE block                       ║
 * ║    2. Uncomment all lines prefixed with // ORIGINAL:     ║
 * ║    3. Remove this file-level comment                     ║
 * ║    4. Run: php bin/magento cache:flush                   ║
 * ╚══════════════════════════════════════════════════════════╝
 */

namespace MSR\AgenticUcp\Model\Did;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Resolves a DID document to a PEM public key.
 */
class Resolver
{
    /**
     * @param Curl $curl
     */
    public function __construct(private readonly Curl $curl)
    {
    }

    /**
     * Resolve a did:web DID to a PEM-encoded public key.
     *
     * @param string $did e.g. "did:web:claude.anthropic.com:agents:shopping"
     * @return string|null PEM public key, or null on failure
     */
    public function resolvePublicKey(string $did): ?string
    {
        // ── DEV MODE ─────────────────────────────────────────────────────────────
        // Hardcoded local key map — bypasses HTTP DID document resolution.
        // Add your generated public key below (output of: openssl ec -pubout).
        // To generate: openssl ecparam -name prime256v1 -genkey -noout -out /tmp/ucp-private.pem
        //              openssl ec -in /tmp/ucp-private.pem -pubout -out /tmp/ucp-public.pem
        $localDevKeys = [
            'did:web:default.freshm2.test:agents:test' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEKvQvvN3+IowV2hWqovvQNUs2h65V
dUdGryVI4+Dpcornh1ZJIEYRF3h514nMrpNR37OqSm3/lDWijhHN5k+o8Q==
-----END PUBLIC KEY-----
PEM,
        ];

        if (isset($localDevKeys[$did])) {
            $key = trim($localDevKeys[$did]);
            if (!str_contains($key, 'REPLACE_THIS')) {
                return $key;
            }
            // Key placeholder not replaced yet — fall through to HTTP resolution
        }
        // ── END DEV MODE ──────────────────────────────────────────────────────────

        // ORIGINAL: if (!str_starts_with($did, 'did:web:')) {
        if (!str_starts_with($did, 'did:web:')) {
            return null;
        }

        // ORIGINAL: $host = str_replace('did:web:', '', $did);
        // ORIGINAL: $host = str_replace(':', '/', $host);
        // ORIGINAL: $url  = "https://{$host}/.well-known/did.json";
        $host = str_replace('did:web:', '', $did);
        $host = str_replace(':', '/', $host);
        $url  = "https://{$host}/.well-known/did.json";

        try {
            $this->curl->setTimeout(5);
            $this->curl->get($url);

            if ($this->curl->getStatus() !== 200) {
                return null;
            }

            $doc = json_decode($this->curl->getBody(), true);

            foreach ($doc['verificationMethod'] ?? [] as $method) {
                if (!empty($method['publicKeyPem'])) {
                    return $method['publicKeyPem'];
                }
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }
}
