<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Data;

use Magento\Framework\DataObject;
use MSR\AgenticUcp\Api\Data\AuthTokenInterface;

/**
 * UCP agent access token data model.
 */
class AuthToken extends DataObject implements AuthTokenInterface
{
    /**
     * Get the access token value.
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return (string)$this->getData(self::ACCESS_TOKEN);
    }

    /**
     * Set the access token value.
     *
     * @param string $token
     * @return static
     */
    public function setAccessToken(string $token): static
    {
        return $this->setData(self::ACCESS_TOKEN, $token);
    }

    /**
     * Get the token expiry in seconds.
     *
     * @return int
     */
    public function getExpiresIn(): int
    {
        return (int)$this->getData(self::EXPIRES_IN);
    }

    /**
     * Set the token expiry in seconds.
     *
     * @param int $seconds
     * @return static
     */
    public function setExpiresIn(int $seconds): static
    {
        return $this->setData(self::EXPIRES_IN, $seconds);
    }

    /**
     * Get the list of granted capabilities.
     *
     * @return array
     */
    public function getGrantedCapabilities(): array
    {
        return (array)($this->getData(self::GRANTED_CAPABILITIES) ?? []);
    }

    /**
     * Set the list of granted capabilities.
     *
     * @param array $capabilities
     * @return static
     */
    public function setGrantedCapabilities(array $capabilities): static
    {
        return $this->setData(self::GRANTED_CAPABILITIES, $capabilities);
    }

    /**
     * Get the token type.
     *
     * @return string
     */
    public function getTokenType(): string
    {
        return (string)($this->getData(self::TOKEN_TYPE) ?? 'Bearer');
    }

    /**
     * Set the token type.
     *
     * @param string $type
     * @return static
     */
    public function setTokenType(string $type): static
    {
        return $this->setData(self::TOKEN_TYPE, $type);
    }
}
