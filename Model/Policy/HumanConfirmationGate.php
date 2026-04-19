<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Policy;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Enforces one-time human confirmation tokens on mutating agent requests.
 */
class HumanConfirmationGate
{
    /**
     * The agent must include this header with a pre-obtained one-time token.
     * Your storefront issues this token when the human clicks "Approve".
     */
    private const CONFIRM_HEADER = 'X-UCP-Human-Confirmation';
    private const CACHE_PREFIX   = 'ucp_confirm_';
    private const TOKEN_TTL      = 300; // 5 minutes

    /**
     * @param CacheInterface $cache
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Checks for a valid human confirmation token on mutating requests.
     *
     * Throws a LocalizedException if confirmation is missing or invalid,
     * prompting the agent to pause and obtain approval.
     *
     * @param RequestInterface $request
     * @param string $did
     * @return void
     * @throws LocalizedException
     */
    public function check(RequestInterface $request, string $did): void
    {
        $token = $request->getHeader(self::CONFIRM_HEADER);

        if (empty($token)) {
            throw new LocalizedException(new Phrase(
                'Human confirmation required. ' .
                'Obtain a one-time confirmation token via POST /rest/V1/ucp/confirm ' .
                'and retry with header %1: <token>.',
                [self::CONFIRM_HEADER]
            ));
        }

        if (!$this->isValidToken($token, $did)) {
            throw new LocalizedException(
                new Phrase('UCP confirmation token is invalid or has already been used.')
            );
        }

        // One-time use: invalidate after successful check
        $this->invalidateToken($token, $did);
    }

    /**
     * Store a confirmation token (called by your storefront when human approves).
     *
     * @param string $token One-time token
     * @param string $did   Agent DID it is valid for
     * @return void
     */
    public function store(string $token, string $did): void
    {
        $key = self::CACHE_PREFIX . hash('sha256', $did . $token);
        $this->cache->save('1', $key, ['UCP_CONFIRM'], self::TOKEN_TTL);
    }

    /**
     * Check whether a confirmation token is valid for the given agent DID.
     *
     * @param string $token
     * @param string $did
     * @return bool
     */
    private function isValidToken(string $token, string $did): bool
    {
        $key = self::CACHE_PREFIX . hash('sha256', $did . $token);
        return (bool)$this->cache->load($key);
    }

    /**
     * Invalidate a confirmation token so it cannot be reused.
     *
     * @param string $token
     * @param string $did
     * @return void
     */
    private function invalidateToken(string $token, string $did): void
    {
        $key = self::CACHE_PREFIX . hash('sha256', $did . $token);
        $this->cache->remove($key);
    }
}
