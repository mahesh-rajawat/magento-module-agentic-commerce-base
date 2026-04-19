<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config;

use Magento\Framework\Config\SchemaLocatorInterface;

/**
 * Locates the XSD schema for ucp.xml validation.
 */
class SchemaLocator implements SchemaLocatorInterface
{
    /**
     * Get the path to the full XSD schema.
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return BP . '/app/code/MSR/AgenticUcp/etc/ucp.xsd';
    }

    /**
     * Get the path to the per-file XSD schema.
     *
     * @return string|null
     */
    public function getPerFileSchema(): ?string
    {
        return $this->getSchema();
    }
}
