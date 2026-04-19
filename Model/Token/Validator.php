<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Token;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;

/**
 * Validates UCP access tokens and extracts their payload.
 */
class Validator
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
     * Validate a UCP access token and return its payload.
     *
     * @param string $token
     * @return array
     * @throws AuthorizationException
     */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthorizationException(new Phrase('Malformed UCP access token.'));
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true)
        ), '+/', '-_'), '=');

        if (!hash_equals($expected, $sigB64)) {
            throw new AuthorizationException(new Phrase('Invalid UCP access token signature.'));
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

        if (($payload['exp'] ?? 0) < time()) {
            throw new AuthorizationException(new Phrase('UCP access token has expired.'));
        }

        return $payload;
    }
}
