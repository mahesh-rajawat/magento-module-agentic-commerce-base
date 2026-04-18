<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Api;

use MSR\AgenticUcp\Api\Data\AuthRequestInterface;
use MSR\AgenticUcp\Api\Data\AuthTokenInterface;

/**
 * Agent authentication service contract.
 */
interface AgentAuthInterface
{
    /**
     * Authenticate an agent request and return an access token.
     *
     * @param AuthRequestInterface $request
     * @return AuthTokenInterface
     * @throws \Magento\Framework\Exception\AuthorizationException
     */
    public function authenticate(AuthRequestInterface $request): AuthTokenInterface;
}
