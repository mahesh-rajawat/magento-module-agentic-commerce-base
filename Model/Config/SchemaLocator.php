<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * Locates the XSD schema for ucp.xml validation.
 */
class SchemaLocator implements SchemaLocatorInterface
{
    public const CONFIG_FILE_SCHEMA = 'ucp.xsd';

    /**
     * Path to corresponding XSD file with validation rules for merged config
     *
     * @var string
     */
    protected $schema = null;
    /**
     * Path to corresponding XSD file with validation rules for separate config files
     * @var string
     */
    protected $perFileSchema = null;

    /**
     * @param Reader $moduleReader
     */
    public function __construct(Reader $moduleReader)
    {
        $configDir = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'MSR_AgenticUcp');
        $this->schema = $configDir . DIRECTORY_SEPARATOR . self::CONFIG_FILE_SCHEMA;
        $this->perFileSchema = $configDir . DIRECTORY_SEPARATOR . self::CONFIG_FILE_SCHEMA;
    }

    /**
     * Get the path to the full XSD schema.
     *
     * @return string|null
     */
    public function getSchema(): ?string
    {
        return $this->schema;
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
