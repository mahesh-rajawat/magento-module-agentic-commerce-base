<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Policy;

use Magento\Framework\App\CacheInterface;

/**
 * Sliding-window rate limiter for UCP agent requests backed by Magento cache.
 */
class RateLimiter
{
    private const CACHE_PREFIX = 'ucp_rate_';
    private const WINDOW       = 60; // 1-minute sliding window

    /**
     * @param CacheInterface $cache
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Returns true if the agent is within the allowed rate, false if exceeded.
     *
     * Uses Magento's cache backend — works with Redis or file cache.
     *
     * @param string $did            Agent DID
     * @param int    $limitPerMinute Max requests per 60 seconds
     * @return bool
     */
    public function allow(string $did, int $limitPerMinute): bool
    {
        $key   = self::CACHE_PREFIX . hash('sha256', $did);
        $count = (int)($this->cache->load($key) ?: 0);

        if ($count >= $limitPerMinute) {
            return false;
        }

        // Increment counter; TTL resets on first hit of each window
        $this->cache->save(
            (string)($count + 1),
            $key,
            ['UCP_RATE_LIMIT'],
            self::WINDOW
        );

        return true;
    }
}
