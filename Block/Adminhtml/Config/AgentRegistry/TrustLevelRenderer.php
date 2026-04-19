<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\AgentRegistry;

use Magento\Framework\View\Element\Html\Select;

/**
 * Trust level dropdown renderer for the AgentRegistry grid.
 */
class TrustLevelRenderer extends Select
{
    /**
     * Set the input name attribute.
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): static
    {
        return $this->setName($value);
    }

    /**
     * Set the input ID attribute.
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): static
    {
        return $this->setId($value);
    }

    /**
     * Render the trust level dropdown HTML.
     *
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
