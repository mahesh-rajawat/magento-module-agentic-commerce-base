<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use MSR\AgenticUcp\Api\AgentAuthInterface;
use MSR\AgenticUcp\Api\Data\AuthRequestInterface;
use MSR\AgenticUcp\Api\Data\AuthTokenInterface;
use MSR\AgenticUcp\Model\Config\AgentConfigProvider;
use MSR\AgenticUcp\Model\Did\ResolverPool as DidResolverPool;
use MSR\AgenticUcp\Model\Token\Generator as TokenGenerator;
use Psr\Log\LoggerInterface;

/**
 * Authenticates incoming agent requests against registered DID identities.
 */
class AgentAuth implements AgentAuthInterface
{
    /**
     * @param AgentConfigProvider $configProvider
     * @param DidResolverPool $didResolver
     * @param TokenGenerator $tokenGenerator
     * @param ObjectManagerInterface $objectManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AgentConfigProvider $configProvider,
        private readonly DidResolverPool $didResolver,
        private readonly TokenGenerator $tokenGenerator,
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function authenticate(AuthRequestInterface $request): AuthTokenInterface
    {
        $did = $request->getDid();
        $agentConfig = $this->findAgentConfig($did);
        if ($agentConfig === null) {
            $this->logger->warning('UCP auth rejected: unknown DID', ['did' => $did]);
            throw new AuthorizationException(new Phrase('Agent not registered.'));
        }
        $publicKey = $this->didResolver->resolvePublicKey($did);
        if ($publicKey === null) {
            throw new AuthorizationException(
                new Phrase('Could not resolve DID document for agent.')
            );
        }
        $this->verifyJwt($request->getSignedJwt(), $publicKey, $did);
        $granted = $this->resolveGrantedCapabilities(
            $agentConfig,
            $request->getRequestedCapabilities()
        );
        if (empty($granted)) {
            throw new AuthorizationException(
                new Phrase('No capabilities granted for this agent.')
            );
        }
        $ttl = (int)($agentConfig['policies']['ttl_seconds'] ?? 3600);
        $accessToken = $this->tokenGenerator->issue($did, $granted, $ttl);
        $this->logger->info('UCP agent authenticated', [
            'did' => $did,
            'granted' => $granted,
            'ttl' => $ttl,
        ]);
        /** @var AuthTokenInterface $token */
        $token = $this->objectManager->create(AuthTokenInterface::class, [
            'data' => [
                AuthTokenInterface::ACCESS_TOKEN => $accessToken,
                AuthTokenInterface::EXPIRES_IN => $ttl,
                AuthTokenInterface::GRANTED_CAPABILITIES => $granted,
                AuthTokenInterface::TOKEN_TYPE => 'Bearer',
            ],
        ]);
        return $token;
    }

    /**
     * Find the agent config entry matching the given DID.
     *
     * @param string $did
     * @return array|null
     */
    private function findAgentConfig(string $did): ?array
    {
        return $this->configProvider->getAgentConfig($did);
    }

    /**
     * Verify the signed JWT presented by the agent.
     *
     * @param string $jwt
     * @param string $publicKeyPem
     * @param string $expectedIssuer
     * @return void
     * @throws AuthorizationException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function verifyJwt(string $jwt, string $publicKeyPem, string $expectedIssuer): void
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new AuthorizationException(new Phrase('Malformed agent JWT.'));
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        // phpcs:enable Magento2.Functions.DiscouragedFunction

        if (($payload['iss'] ?? '') !== $expectedIssuer) {
            throw new AuthorizationException(new Phrase('JWT issuer mismatch.'));
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new AuthorizationException(new Phrase('Agent JWT has expired.'));
        }

        if (($payload['iat'] ?? 0) > time() + 30) {
            throw new AuthorizationException(new Phrase('JWT issued in the future.'));
        }

        $alg = strtoupper($header['alg'] ?? 'ES256');
        $signingInput = "{$headerB64}.{$payloadB64}";
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $signature = base64_decode(strtr($sigB64, '-_', '+/'));

        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            throw new AuthorizationException(new Phrase('Could not parse agent public key.'));
        }

        // ES256K and EdDSA are added for did:ethr (secp256k1) and did:key (Ed25519).
        // EdDSA uses digest NID 0 — OpenSSL determines the hash from the key type itself.
        $opensslAlgo = match ($alg) {
            'ES256', 'RS256', 'ES256K' => OPENSSL_ALGO_SHA256,
            'ES384', 'RS384' => OPENSSL_ALGO_SHA384,
            'ES512', 'RS512' => OPENSSL_ALGO_SHA512,
            'EDDSA' => 0,
            default => throw new AuthorizationException(
                new Phrase('Unsupported JWT algorithm.')
            ),
        };

        $valid = openssl_verify($signingInput, $signature, $pubKey, $opensslAlgo);
        if ($valid !== 1) {
            throw new AuthorizationException(
                new Phrase('Agent JWT signature verification failed.')
            );
        }
    }

    /**
     * Resolve the capabilities granted to the agent based on config and requested list.
     *
     * @param array $agentConfig
     * @param array $requested
     * @return array
     */
    private function resolveGrantedCapabilities(array $agentConfig, array $requested): array
    {
        $allowed = [];
        foreach ($agentConfig['capabilities']['capability'] ?? [] as $name => $cap) {
            if ($cap['enabled'] ?? true) {
                $allowed[] = $name;
            }
        }
        if (!empty($requested)) {
            return array_values(array_intersect($requested, $allowed));
        }
        return $allowed;
    }
}
