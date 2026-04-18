<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\AgentRegistry;

use Magento\Framework\View\Element\Html\Select;

class TrustLevelRenderer extends Select
{
    /**
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): static
    {
        return $this->setName($value);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): static
    {
        return $this->setId($value);
    }

    /**
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['value' => 'trusted',     'label' => __('Trusted')],
                ['value' => 'provisional', 'label' => __('Provisional')],
                ['value' => 'low',         'label' => __('Low')],
            ]);
        }
        return parent::_toHtml();
    }
}
