<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Api\Data;

/**
 * UCP agent authentication request data interface.
 */
interface AuthRequestInterface
{
    /**
     * @return string
     */
    public function getDid(): string;

    /**
     * @return string
     */
    public function getSignedJwt(): string;

    /**
     * @return string[]
     */
    public function getRequestedCapabilities(): array;

    /**
     * @param string $did
     * @return $this
     */
    public function setDid(string $did): static;

    /**
     * @param string $signedJwt
     * @return $this
     */
    public function setSignedJwt(string $signedJwt): static;

    /**
     * @param string[] $capabilities
     * @return $this
     */
    public function setRequestedCapabilities(array $capabilities): static;
}
