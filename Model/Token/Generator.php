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

        if (empty($secret)) {
            throw new \RuntimeException(
                'UCP token secret not configured. ' .
                'Add "ucp" => ["token_secret" => "<random-string>"] to app/etc/env.php'
            );
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
