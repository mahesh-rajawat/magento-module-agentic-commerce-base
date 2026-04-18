<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Data;

use Magento\Framework\DataObject;
use MSR\AgenticUcp\Api\Data\AuthRequestInterface;

/**
 * UCP agent authentication request data model.
 */
class AuthRequest extends DataObject implements AuthRequestInterface
{
    /**
     * @return string
     */
    public function getDid(): string
    {
        return (string)$this->getData('did');
    }

    /**
     * @param string $did
     * @return static
     */
    public function setDid(string $did): static
    {
        return $this->setData('did', $did);
    }

    /**
     * @return string
     */
    public function getSignedJwt(): string
    {
        return (string)$this->getData('signed_jwt');
    }

    /**
     * @param string $signedJwt
     * @return static
     */
    public function setSignedJwt(string $signedJwt): static
    {
        return $this->setData('signed_jwt', $signedJwt);
    }

    /**
     * @return array
     */
    public function getRequestedCapabilities(): array
    {
        return (array)($this->getData('requested_capabilities') ?? []);
    }

    /**
     * @param array $capabilities
     * @return static
     */
    public function setRequestedCapabilities(array $capabilities): static
    {
        return $this->setData('requested_capabilities', $capabilities);
    }
}
