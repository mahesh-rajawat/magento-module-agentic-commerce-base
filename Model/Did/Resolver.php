<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Did;

use Magento\Framework\HTTP\Client\Curl;
use MSR\AgenticUcp\Api\Did\ResolverInterface;

/**
 * Resolves did:web DIDs to PEM public keys.
 */
class Resolver implements ResolverInterface
{
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
        return str_starts_with($did, 'did:web:');
    }

    /**
     * Resolve a did:web DID to a PEM-encoded public key.
     *
     * @param string $did e.g. "did:web:claude.anthropic.com:agents:shopping"
     * @return string|null PEM public key, or null on failure
     */
    public function resolvePublicKey(string $did): ?string
    {
        if (!$this->supports($did)) {
            return null;
        }

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
