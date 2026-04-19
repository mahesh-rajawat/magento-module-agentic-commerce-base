<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\Column;

use Magento\Framework\View\Element\Html\Select;

/**
 * Trust level dropdown column for the AgentRegistry grid.
 */
class TrustLevelSelect extends Select
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
     * Render the trust level dropdown HTML.
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['value' => 'verified',    'label' => __('Verified — identity confirmed externally')],
                ['value' => 'trusted',     'label' => __('Trusted — known and reliable agent')],
                ['value' => 'provisional', 'label' => __('Provisional — testing or new agent')],
                ['value' => 'untrusted',   'label' => __('Untrusted — monitor closely')],
            ]);
        }
        return parent::_toHtml();
    }
}
