<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DidMethods implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'did:web',
                'label' => 'did:web — hosted on a domain (standard)',
            ],
            [
                'value' => 'did:key',
                'label' => 'did:key — self-contained in the identifier (no hosting needed)',
            ],
            [
                'value' => 'did:ethr',
                'label' => 'did:ethr — Ethereum-anchored (enterprise/blockchain)',
            ],
        ];
    }
}
