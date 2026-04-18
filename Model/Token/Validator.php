<?php


declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  LOCAL DEV MODE — MODIFIED FOR TESTING                   ║
 * ║                                                          ║
 * ╠══════════════════════════════════════════════════════════╣
 * ║  What changed:                                           ║
 * ║    Constructor falls back to a hardcoded dev secret      ║
 * ║    if ucp/token_secret is missing from env.php,          ║
 * ║    instead of throwing a RuntimeException.               ║
 * ║                                                          ║
 * ║  To revert for production commit:                        ║
 * ║    1. Delete the // DEV MODE block in __construct()      ║
 * ║    2. Uncomment all lines prefixed with // ORIGINAL:     ║
 * ║    3. Remove this file-level comment                     ║
 * ║    4. Run: php bin/magento cache:flush                   ║
 * ╚══════════════════════════════════════════════════════════╝
 */

namespace MSR\AgenticUcp\Model\Token;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;

/**
 * Validates UCP access tokens and extracts their payload.
 */
class Validator
    /**
     * @var string
     */
{
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

        // ── DEV MODE ─────────────────────────────────────────────────────────
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
        // ── END DEV MODE ──────────────────────────────────────────────────────
    }

    /**
     * Validate a UCP access token and return its payload.
     * @param string $token
     * @return array
     *
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
