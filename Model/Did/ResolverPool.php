<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Did;

use MSR\AgenticUcp\Api\Did\ResolverInterface;

/**
 * Dispatches DID resolution to the first resolver that claims support for the method.
 */
class ResolverPool
{
    /**
     * @param ResolverInterface[] $resolvers
     */
    public function __construct(private readonly array $resolvers = [])
    {
    }

    /**
     * @param string $did
     * @return string|null
     */
    public function resolvePublicKey(string $did): ?string
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($did)) {
                return $resolver->resolvePublicKey($did);
            }
        }
        return null;
    }
}
