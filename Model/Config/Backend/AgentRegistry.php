<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Serializes and validates the AgentRegistry grid rows.
 * Stored as a JSON array in core_config_data.
 * Each row: name, did, trust_level, profile, active,
 *           max_order_value, rate_limit_per_minute,
 *           allowed_payment_methods
 */
class AgentRegistry extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Json $json,
        private readonly ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context, $registry, $config,
            $cacheTypeList, $resource, $resourceCollection, $data
        );
    }

    /**
     * Deserialize JSON string to array before displaying in grid.
     */
    public function afterLoad(): self
    {
        $value = $this->getValue();
        if (is_string($value) && !empty($value)) {
            try {
                $this->setValue($this->json->unserialize($value));
            } catch (\Exception) {
                $this->setValue([]);
            }
        }
        return $this;
    }

    /**
     * Validate and serialize grid rows to JSON before saving.
     */
    public function beforeSave(): self
    {
        $value = $this->getValue();

        if (!is_array($value)) {
            $this->setValue('');
            return $this;
        }

        // Remove the Magento grid placeholder row
        unset($value['__empty']);

        $cleaned = [];
        foreach ($value as $row) {
            // DID is required — skip rows without it
            $did = trim($row['did'] ?? '');
            if (empty($did)) {
                continue;
            }

            // Validate DID format
            if (!str_starts_with($did, 'did:')) {
                $this->messageManager->addWarningMessage(
                    __('Skipped agent "%1" — DID must start with "did:" (got: %2)',
                        $row['name'] ?? 'unnamed', $did)
                );
                continue;
            }

            // Profile is required
            if (empty($row['profile'])) {
                $this->messageManager->addWarningMessage(
                    __('Skipped agent "%1" — please select a capability profile.',
                        $row['name'] ?? $did)
                );
                continue;
            }

            $cleaned[] = [
                'name'                       => trim($row['name'] ?? ''),
                'did'                        => $did,
                'trust_level'                => $row['trust_level'] ?? 'provisional',
                'profile'                    => $row['profile'],
                'active'                     => (bool)($row['active'] ?? true),
                'max_order_value'            => is_numeric($row['max_order_value'] ?? '')
                    ? (float)$row['max_order_value'] : null,
                'rate_limit_per_minute'      => is_numeric($row['rate_limit_per_minute'] ?? '')
                    ? (int)$row['rate_limit_per_minute'] : null,
                'allowed_payment_methods'    => trim($row['allowed_payment_methods'] ?? ''),
            ];
        }

        $this->setValue($this->json->serialize($cleaned));
        return $this;
    }
}
