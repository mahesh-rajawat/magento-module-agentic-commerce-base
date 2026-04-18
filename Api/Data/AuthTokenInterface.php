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
     * @return string
     */
    public function getAccessToken(): string;

    /**
     * @param string $token
     * @return $this
     */
    public function setAccessToken(string $token): static;

    /**
     * @return int
     */
    public function getExpiresIn(): int;

    /**
     * @param int $seconds
     * @return $this
     */
    public function setExpiresIn(int $seconds): static;

    /**
     * @return string[]
     */
    public function getGrantedCapabilities(): array;

    /**
     * @param string[] $capabilities
     * @return $this
     */
    public function setGrantedCapabilities(array $capabilities): static;

    /**
     * @return string
     */
    public function getTokenType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setTokenType(string $type): static;
}
