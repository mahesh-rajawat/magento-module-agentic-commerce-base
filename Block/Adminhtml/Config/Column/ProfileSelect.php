<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config\Column;

use Magento\Framework\View\Element\Html\Select;
use MSR\AgenticUcp\Model\Config\Source\AgentProfiles;

/**
 * Capability profile dropdown column for the AgentRegistry grid.
 * Options are sourced from profile-* entries in ucp.xml — any installed
 * module that adds a profile-* agent to ucp.xml appears here automatically.
 */
class ProfileSelect extends Select
{
    public function __construct(
        private readonly AgentProfiles $agentProfiles,
        \Magento\Framework\View\Element\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

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
            $this->setOptions($this->agentProfiles->toOptionArray());
        }
        return parent::_toHtml();
    }
}
