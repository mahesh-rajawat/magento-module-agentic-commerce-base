<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\Column;

use Magento\Framework\View\Element\Html\Select;

class TrustLevelSelect extends Select
{
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

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
