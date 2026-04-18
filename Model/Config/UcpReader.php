<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config;

use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\Reader\Filesystem;
use Magento\Framework\Config\ValidationStateInterface;

/**
 * Reads and merges ucp.xml configuration files from all modules.
 */
class UcpReader extends Filesystem
{
    /**
     * @var array
     */
    protected $_idAttributes = [
        '/config/agent'                                            => 'id',
        '/config/agent/capabilities/capability'                    => 'name',
        '/config/agent/permissions/allowed_payment_methods/method' => 'code',
        '/config/agent/permissions/customer_segments/segment'      => 'code',
    ];

    /**
     * @param FileResolverInterface $fileResolver
     * @param Converter $converter
     * @param SchemaLocator $schemaLocator
     * @param ValidationStateInterface $validationState
     * @param string $fileName
     * @param array $idAttributes
     * @param string $domDocumentClass
     * @param string $defaultScope
     */
    public function __construct(
        FileResolverInterface    $fileResolver,
        Converter                $converter,
        SchemaLocator            $schemaLocator,
        ValidationStateInterface $validationState,
        string                   $fileName = 'ucp.xml',
        array                    $idAttributes = [],
        string                   $domDocumentClass = \Magento\Framework\Config\Dom::class,
        string                   $defaultScope = 'global',
    ) {
        parent::__construct(
            $fileResolver,
            $converter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes ?: $this->_idAttributes,
            $domDocumentClass,
            $defaultScope,
        );
    }
}
