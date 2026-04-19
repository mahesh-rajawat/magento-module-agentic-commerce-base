<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Api\Data;

/**
 * UCP agent access token data interface.
 */
interface AuthTokenInterface
{
    public const ACCESS_TOKEN         = 'access_token';
    public const EXPIRES_IN           = 'expires_in';
    public const GRANTED_CAPABILITIES = 'granted_capabilities';
    public const TOKEN_TYPE           = 'token_type';

    /**
     * Get the access token value.
     *
     * @return string
     */
    public function getAccessToken(): string;

    /**
     * Set the access token value.
     *
     * @param string $token
     * @return $this
     */
    public function setAccessToken(string $token): static;

    /**
     * Get the token expiry in seconds.
     *
     * @return int
     */
    public function getExpiresIn(): int;

    /**
     * Set the token expiry in seconds.
     *
     * @param int $seconds
     * @return $this
     */
    public function setExpiresIn(int $seconds): static;

    /**
     * Get the list of granted capabilities.
     *
     * @return string[]
     */
    public function getGrantedCapabilities(): array;

    /**
     * Set the list of granted capabilities.
     *
     * @param string[] $capabilities
     * @return $this
     */
    public function setGrantedCapabilities(array $capabilities): static;

    /**
     * Get the token type.
     *
     * @return string
     */
    public function getTokenType(): string;

    /**
     * Set the token type.
     *
     * @param string $type
     * @return $this
     */
    public function setTokenType(string $type): static;
}
