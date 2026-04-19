<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Api\Data;

/**
 * UCP agent authentication request data interface.
 */
interface AuthRequestInterface
{
    /**
     * Get agent DID.
     *
     * @return string
     */
    public function getDid(): string;

    /**
     * Get signed JWT for verification.
     *
     * @return string
     */
    public function getSignedJwt(): string;

    /**
     * Get the list of requested capabilities.
     *
     * @return string[]
     */
    public function getRequestedCapabilities(): array;

    /**
     * Set agent DID.
     *
     * @param string $did
     * @return $this
     */
    public function setDid(string $did): static;

    /**
     * Set the signed JWT.
     *
     * @param string $signedJwt
     * @return $this
     */
    public function setSignedJwt(string $signedJwt): static;

    /**
     * Set the requested capabilities.
     *
     * @param string[] $capabilities
     * @return $this
     */
    public function setRequestedCapabilities(array $capabilities): static;
}
