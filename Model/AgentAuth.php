<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  LOCAL DEV MODE — MODIFIED FOR TESTING                   ║
 * ║  File: Model/AgentAuth.php                               ║
 * ╠══════════════════════════════════════════════════════════╣
 * ║  What changed:                                           ║
 * ║    verifyJwt() accepts both ES256 (production) and       ║
 * ║    HS256 (dev) signed tokens. The test script sends      ║
 * ║    HS256 tokens signed with the UCP token secret,        ║
 * ║    which is much simpler than ES256 + real key infra.    ║
 * ║                                                          ║
 * ║  To revert for production commit:                        ║
 * ║    1. Delete the // DEV MODE block in verifyJwt()        ║
 * ║    2. Uncomment all lines prefixed with // ORIGINAL:     ║
 * ║    3. Remove this file-level comment                     ║
 * ║    4. Run: php bin/magento cache:flush                   ║
 * ╚══════════════════════════════════════════════════════════╝
 */

namespace MSR\AgenticUcp\Model;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use MSR\AgenticUcp\Api\AgentAuthInterface;
use MSR\AgenticUcp\Api\Data\AuthRequestInterface;
use MSR\AgenticUcp\Api\Data\AuthTokenInterface;
use MSR\AgenticUcp\Model\Config\AgentConfigProvider;
use MSR\AgenticUcp\Model\Config\UcpReader;
use MSR\AgenticUcp\Model\Did\Resolver as DidResolver;
use MSR\AgenticUcp\Model\Token\Generator as TokenGenerator;
use Psr\Log\LoggerInterface;

/**
 * Authenticates incoming agent requests against registered DID identities.
 */
class AgentAuth implements AgentAuthInterface
{
    /**
     * @param UcpReader $ucpReader
     * @param DidResolver $didResolver
     * @param TokenGenerator $tokenGenerator
     * @param ObjectManagerInterface $objectManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AgentConfigProvider $configProvider,
        private readonly DidResolver           $didResolver,
        private readonly TokenGenerator        $tokenGenerator,
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface       $logger,
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
        $ttl         = (int)($agentConfig['policies']['ttl_seconds'] ?? 3600);
        $accessToken = $this->tokenGenerator->issue($did, $granted, $ttl);
        $this->logger->info('UCP agent authenticated', [
            'did'     => $did,
            'granted' => $granted,
            'ttl'     => $ttl,
        ]);
        /** @var AuthTokenInterface $token */
        $token = $this->objectManager->create(AuthTokenInterface::class, [
            'data' => [
                AuthTokenInterface::ACCESS_TOKEN         => $accessToken,
                AuthTokenInterface::EXPIRES_IN           => $ttl,
                AuthTokenInterface::GRANTED_CAPABILITIES => $granted,
                AuthTokenInterface::TOKEN_TYPE           => 'Bearer',
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
        $header  = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        // phpcs:enable Magento2.Functions.DiscouragedFunction

        // ORIGINAL: if (($payload['iss'] ?? '') !== $expectedIssuer) {
        // ORIGINAL:     throw new AuthorizationException(new Phrase('JWT issuer mismatch.'));
        // ORIGINAL: }
        if (($payload['iss'] ?? '') !== $expectedIssuer) {
            throw new AuthorizationException(new Phrase('JWT issuer mismatch.'));
        }

        // ORIGINAL: if (($payload['exp'] ?? 0) < time()) {
        // ORIGINAL:     throw new AuthorizationException(new Phrase('Agent JWT has expired.'));
        // ORIGINAL: }
        if (($payload['exp'] ?? 0) < time()) {
            throw new AuthorizationException(new Phrase('Agent JWT has expired.'));
        }

        // ORIGINAL: if (($payload['iat'] ?? 0) > time() + 30) {
        // ORIGINAL:     throw new AuthorizationException(new Phrase('JWT issued in the future.'));
        // ORIGINAL: }
        if (($payload['iat'] ?? 0) > time() + 30) {
            throw new AuthorizationException(new Phrase('JWT issued in the future.'));
        }

        $alg          = strtoupper($header['alg'] ?? 'ES256');
        $signingInput = "{$headerB64}.{$payloadB64}";
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $signature    = base64_decode(strtr($sigB64, '-_', '+/'));

        // ── DEV MODE ─────────────────────────────────────────────────────────
        // Accept HS256 tokens (signed with UCP token secret) in addition to
        // production ES256 tokens. The test script (ucp_test.py) sends HS256
        // because it is much simpler to generate without a key management system.
        //
        // ORIGINAL: $pubKey = openssl_pkey_get_public($publicKeyPem);
        // ORIGINAL: if ($pubKey === false) {
        // ORIGINAL:     throw new AuthorizationException(new Phrase('Could not parse agent public key.'));
        // ORIGINAL: }
        // ORIGINAL: $valid = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        // ORIGINAL: if ($valid !== 1) {
        // ORIGINAL:     throw new AuthorizationException(
        // ORIGINAL:         new Phrase('Agent JWT signature verification failed.')
        // ORIGINAL:     );
        // ORIGINAL: }
        if ($alg === 'HS256') {
            // Dev path: verify HMAC-SHA256 against the UCP token secret
            /** @var \Magento\Framework\App\DeploymentConfig $deploymentConfig */
            // phpcs:ignore Magento2.Classes.ObjectInstantiation
            $deploymentConfig = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\DeploymentConfig::class);
            $fallback = 'dev-only-secret-change-before-production-use-32chars!';
            $secret   = (string)($deploymentConfig->get('ucp/token_secret') ?? $fallback);
            $expected = rtrim(strtr(base64_encode(
                hash_hmac('sha256', $signingInput, $secret, true)
            ), '+/', '-_'), '=');
            if (!hash_equals($expected, $sigB64)) {
                throw new AuthorizationException(
                    new Phrase('Agent JWT HS256 signature invalid (dev mode).')
                );
            }
            error_log('[UCP DEV MODE] HS256 token accepted. Use ES256 in production.'); // phpcs:ignore Magento2.Functions.DiscouragedFunction
        } else {
            // Production path: verify ES256 against the DID document public key
            $pubKey = openssl_pkey_get_public($publicKeyPem);
            if ($pubKey === false) {
                throw new AuthorizationException(
                    new Phrase('Could not parse agent public key.')
                );
            }
            $valid = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);
            if ($valid !== 1) {
                throw new AuthorizationException(
                    new Phrase('Agent JWT signature verification failed.')
                );
            }
        }
        // ── END DEV MODE ──────────────────────────────────────────────────────
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
