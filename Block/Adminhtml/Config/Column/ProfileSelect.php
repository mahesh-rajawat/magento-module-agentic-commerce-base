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
    /**
     * @param AgentProfiles $agentProfiles
     * @param \Magento\Framework\View\Element\Context $context
     * @param array $data
     */
    public function __construct(
        private readonly AgentProfiles $agentProfiles,
        \Magento\Framework\View\Element\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

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
     * Render the profile dropdown HTML.
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->agentProfiles->toOptionArray());
        }
        return parent::_toHtml();
    }
}
