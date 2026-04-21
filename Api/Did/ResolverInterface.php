<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Api\Did;

interface ResolverInterface
{
    /**
     * Return true if this resolver handles the given DID method.
     *
     * @param string $did
     * @return bool
     */
    public function supports(string $did): bool;

    /**
     * Resolve a DID to its PEM-encoded public key.
     *
     * @param string $did
     * @return string|null
     */
    public function resolvePublicKey(string $did): ?string;
}
