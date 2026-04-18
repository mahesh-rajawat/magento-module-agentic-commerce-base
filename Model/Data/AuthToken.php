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
     * @return string
     */
    public function getAccessToken(): string
    {
        return (string)$this->getData(self::ACCESS_TOKEN);
    }

    /**
     * @param string $token
     * @return static
     */
    public function setAccessToken(string $token): static
    {
        return $this->setData(self::ACCESS_TOKEN, $token);
    }

    /**
     * @return int
     */
    public function getExpiresIn(): int
    {
        return (int)$this->getData(self::EXPIRES_IN);
    }

    /**
     * @param int $seconds
     * @return static
     */
    public function setExpiresIn(int $seconds): static
    {
        return $this->setData(self::EXPIRES_IN, $seconds);
    }

    /**
     * @return array
     */
    public function getGrantedCapabilities(): array
    {
        return (array)($this->getData(self::GRANTED_CAPABILITIES) ?? []);
    }

    /**
     * @param array $capabilities
     * @return static
     */
    public function setGrantedCapabilities(array $capabilities): static
    {
        return $this->setData(self::GRANTED_CAPABILITIES, $capabilities);
    }

    /**
     * @return string
     */
    public function getTokenType(): string
    {
        return (string)($this->getData(self::TOKEN_TYPE) ?? 'Bearer');
    }

    /**
     * @param string $type
     * @return static
     */
    public function setTokenType(string $type): static
    {
        return $this->setData(self::TOKEN_TYPE, $type);
    }
}
