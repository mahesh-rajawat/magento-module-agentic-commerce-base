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
     * Get agent DID.
     *
     * @return string
     */
    public function getDid(): string
    {
        return (string)$this->getData('did');
    }

    /**
     * Set agent DID.
     *
     * @param string $did
     * @return static
     */
    public function setDid(string $did): static
    {
        return $this->setData('did', $did);
    }

    /**
     * Get signed JWT for verification.
     *
     * @return string
     */
    public function getSignedJwt(): string
    {
        return (string)$this->getData('signed_jwt');
    }

    /**
     * Set the signed JWT.
     *
     * @param string $signedJwt
     * @return static
     */
    public function setSignedJwt(string $signedJwt): static
    {
        return $this->setData('signed_jwt', $signedJwt);
    }

    /**
     * Get the list of requested capabilities.
     *
     * @return array
     */
    public function getRequestedCapabilities(): array
    {
        return (array)($this->getData('requested_capabilities') ?? []);
    }

    /**
     * Set the requested capabilities.
     *
     * @param array $capabilities
     * @return static
     */
    public function setRequestedCapabilities(array $capabilities): static
    {
        return $this->setData('requested_capabilities', $capabilities);
    }
}
