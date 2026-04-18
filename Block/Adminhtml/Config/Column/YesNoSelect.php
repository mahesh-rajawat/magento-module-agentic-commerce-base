<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\Column;

use Magento\Framework\View\Element\Html\Select;

class YesNoSelect extends Select
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
                ['value' => '1', 'label' => __('Yes')],
                ['value' => '0', 'label' => __('No')],
            ]);
        }
        return parent::_toHtml();
    }
}
