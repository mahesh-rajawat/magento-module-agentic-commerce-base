<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Capabilities implements OptionSourceInterface
{
    /**
     * Base capabilities defined by MSR_AgenticUcp.
     * Child modules inject additional capabilities via di.xml
     * using the $additionalCapabilities constructor argument.
     */
    private const BASE_CAPABILITIES = [
        [
            'value'   => 'catalog.browse',
            'label'   => 'Browse catalog',
            'comment' => 'View product listings and details',
            'risk'    => 'low',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'catalog.search',
            'label'   => 'Search catalog',
            'comment' => 'Search products by keyword',
            'risk'    => 'low',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'inventory.query',
            'label'   => 'Check inventory',
            'comment' => 'Check stock levels for any SKU',
            'risk'    => 'low',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'cart.manage',
            'label'   => 'Manage cart',
            'comment' => 'Add, remove, and update cart items',
            'risk'    => 'medium',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'order.track',
            'label'   => 'Track orders',
            'comment' => 'Read order status and tracking info',
            'risk'    => 'medium',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'customer.read',
            'label'   => 'Read customer data',
            'comment' => 'Read customer profile and address book',
            'risk'    => 'medium',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'order.place',
            'label'   => 'Place orders',
            'comment' => 'Submit orders on behalf of the customer',
            'risk'    => 'high',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'payment.initiate',
            'label'   => 'Initiate payments',
            'comment' => 'Trigger payment transactions directly',
            'risk'    => 'high',
            'module'  => 'MSR_AgenticUcp',
        ],
        [
            'value'   => 'negotiation.price',
            'label'   => 'Negotiate pricing',
            'comment' => 'Request and accept price adjustments',
            'risk'    => 'high',
            'module'  => 'MSR_AgenticUcp',
        ],
    ];

    /** @var array<int, array{value:string, label:string, comment:string, risk:string, module:string}> */
    private readonly array $allCapabilities;

    public function __construct(
        array $additionalCapabilities = []
    ) {
        // Merge base + injected, deduplicate by value (last wins)
        $merged = [];
        foreach (array_merge(self::BASE_CAPABILITIES, $additionalCapabilities) as $cap) {
            $merged[$cap['value']] = $cap;
        }
        $this->allCapabilities = array_values($merged);
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->allCapabilities as $cap) {
            $risk    = match($cap['risk']) {
                'high'   => '⚠ ',
                'medium' => '◆ ',
                default  => '● ',
            };
            $options[] = [
                'value' => $cap['value'],
                'label' => $risk . $cap['label'] . ' — ' . $cap['comment'],
            ];
        }
        return $options;
    }

    public function toGroupedOptionArray(): array
    {
        $groups = ['low' => [], 'medium' => [], 'high' => []];
        foreach ($this->allCapabilities as $cap) {
            $groups[$cap['risk']][] = [
                'value' => $cap['value'],
                'label' => $cap['label'],
            ];
        }
        return [
            ['label' => 'Read-only (safe)',
                'value' => $groups['low']],
            ['label' => 'Read + limited write',
                'value' => $groups['medium']],
            ['label' => '⚠ High risk — requires human confirmation',
                'value' => $groups['high']],
        ];
    }

    public function getLabel(string $code): string
    {
        foreach ($this->allCapabilities as $cap) {
            if ($cap['value'] === $code) {
                return $cap['label'];
            }
        }
        return $code; // fallback: return the code itself
    }

    public function getRisk(string $code): string
    {
        foreach ($this->allCapabilities as $cap) {
            if ($cap['value'] === $code) {
                return $cap['risk'];
            }
        }
        return 'low';
    }

    public function getHighRiskCapabilities(): array
    {
        return array_column(
            array_filter(
                $this->allCapabilities,
                fn($c) => $c['risk'] === 'high'
            ),
            'value'
        );
    }

    public function getAllValues(): array
    {
        return array_column($this->allCapabilities, 'value');
    }
}
