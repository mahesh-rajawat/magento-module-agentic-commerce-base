<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\Column;

use Magento\Framework\View\Element\Html\Select;

/**
 * Yes/No dropdown column for the AgentRegistry grid.
 */
class YesNoSelect extends Select
{
    /**
     * Set the input name attribute.
     *
     * @param string $value
     * @return self
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * Set the input ID attribute.
     *
     * @param string $value
     * @return self
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * Render the Yes/No dropdown HTML.
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['value' => '1', 'label' => __('Yes')],
                ['value' => '0', 'label' => __('No')],
            ]);
        }
        return parent::_toHtml();
    }
}
