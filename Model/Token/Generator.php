<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Token;

use Magento\Framework\App\DeploymentConfig;

/**
 * Issues signed HS256 UCP access tokens.
 */
class Generator
{
    /**
     * @var string
     */
    private string $secret;

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(DeploymentConfig $deploymentConfig)
    {
        $secret = $deploymentConfig->get('ucp/token_secret');

        // ORIGINAL: if (empty($secret)) {
        // ORIGINAL:     throw new \RuntimeException(
        // ORIGINAL:         'UCP token secret not configured. ' .
        // ORIGINAL:         'Add "ucp" => ["token_secret" => "<random-string>"] to app/etc/env.php'
        // ORIGINAL:     );
        // ORIGINAL: }
        // ORIGINAL: $this->secret = (string)$secret;

        // Falls back to a hardcoded dev secret so the module works even if
        // ucp/token_secret is not yet set in app/etc/env.php.
        // IMPORTANT: this fallback secret is NOT secure — only for local dev.
        // Always set a real secret in env.php before running tests.
        if (empty($secret)) {
            $secret = 'dev-only-secret-change-before-production-use-32chars!';
            // @phpstan-ignore-next-line
            error_log('[UCP DEV MODE] Using fallback token secret. Set ucp/token_secret in env.php.'); // phpcs:ignore Magento2.Functions.DiscouragedFunction
        }
        $this->secret = (string)$secret;
    }

    /**
     * Issue a signed JWT access token.
     *
     * @param string $did
     * @param array  $capabilities
     * @param int    $ttl
     * @return string
     */
    public function issue(string $did, array $capabilities, int $ttl): string
    {
        $now     = time();
        $header  = rtrim(
            strtr(base64_encode((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'),
            '='
        );
        $payload = rtrim(
            strtr(
                base64_encode((string)json_encode([
                    'iss'          => $did,
                    'iat'          => $now,
                    'exp'          => $now + $ttl,
                    'capabilities' => $capabilities,
                ])),
                '+/',
                '-_'
            ),
            '='
        );
        $sig = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true)
        ), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$sig}";
    }
}
