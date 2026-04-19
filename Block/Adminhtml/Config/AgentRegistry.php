<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use MSR\AgenticUcp\Block\Adminhtml\Config\Column\ProfileSelect;
use MSR\AgenticUcp\Block\Adminhtml\Config\Column\TrustLevelSelect;
use MSR\AgenticUcp\Block\Adminhtml\Config\Column\YesNoSelect;

/**
 * Renders the agent registry as a dynamic add/remove grid in Magento admin config.
 *
 * Each row represents one registered AI agent:
 *   - Agent name       (free text — human label e.g. "Claude Shopping Agent")
 *   - DID              (free text — e.g. did:web:claude.anthropic.com:agents:shopping)
 *   - Trust level      (dropdown — verified / trusted / provisional / untrusted)
 *   - Profile          (dropdown — sourced from ucp.xml profile-* entries)
 *   - Active           (yes/no dropdown)
 *   - Max order value  (number — overrides default, blank = use default)
 *   - Rate limit/min   (number — overrides default, blank = use default)
 *   - Human confirm    (yes/no/inherit dropdown)
 *   - Allowed payments (text — comma-separated codes e.g. "checkmo,stripe")
 */
class AgentRegistry extends AbstractFieldArray
{
    /**
     * @var ProfileSelect|null
     */
    private ?ProfileSelect $profileRenderer = null;

    /**
     * @var TrustLevelSelect|null
     */
    private ?TrustLevelSelect $trustRenderer = null;

    /**
     * @var YesNoSelect|null
     */
    private ?YesNoSelect $yesNoRenderer = null;

    /**
     * Prepare the grid columns for rendering.
     *
     * @return void
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('name', [
            'label'    => __('Agent name'),
            'title'    => __('Human-readable label e.g. Claude Shopping Agent'),
            'style'    => 'width:140px',
            'renderer' => false,
        ]);

        $this->addColumn('did', [
            'label'    => __('DID'),
            'title'    => __('Decentralized Identifier provided by the agent owner e.g. did:web:claude.anthropic.com'),
            'style'    => 'width:240px',
            'renderer' => false,
        ]);

        $this->addColumn('trust_level', [
            'label'    => __('Trust level'),
            'title'    => __('How much you trust this agent'),
            'style'    => 'width:110px',
            'renderer' => $this->getTrustRenderer(),
        ]);

        $this->addColumn('profile', [
            'label'    => __('Capability profile'),
            'title'    => __('Which actions this agent is allowed to perform'),
            'style'    => 'width:160px',
            'renderer' => $this->getProfileRenderer(),
        ]);

        $this->addColumn('active', [
            'label'    => __('Active'),
            'title'    => __('Enable or disable this agent without deleting it'),
            'style'    => 'width:80px',
            'renderer' => $this->getYesNoRenderer(),
        ]);

        $this->addColumn('max_order_value', [
            'label'    => __('Max order (leave blank for default)'),
            'title'    => __('Maximum order value for this agent. Leave blank to use the default policy.'),
            'style'    => 'width:120px',
            'class'    => 'validate-number validate-zero-or-greater',
            'renderer' => false,
        ]);

        $this->addColumn('rate_limit_per_minute', [
            'label'    => __('Rate limit/min (blank = default)'),
            'title'    => __('Max requests per minute for this agent. Leave blank to use the default policy.'),
            'style'    => 'width:100px',
            'class'    => 'validate-number validate-zero-or-greater',
            'renderer' => false,
        ]);

        $this->addColumn('allowed_payment_methods', [
            'label'    => __('Allowed payments (comma-separated)'),
            'title'    => __('e.g. checkmo,stripe — leave blank to allow all active methods'),
            'style'    => 'width:160px',
            'renderer' => false,
        ]);

        $this->_addAfter       = false;
        $this->_addButtonLabel = (string)__('Add agent');
    }

    /**
     * Populate dropdown values when loading saved rows.
     *
     * @param DataObject $row
     * @return void
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];

        $profile = $row->getData('profile');
        if ($profile) {
            $key           = 'option_' . $this->getProfileRenderer()->calcOptionHash($profile);
            $options[$key] = 'selected="selected"';
        }

        $trustLevel = $row->getData('trust_level');
        if ($trustLevel) {
            $key           = 'option_' . $this->getTrustRenderer()->calcOptionHash($trustLevel);
            $options[$key] = 'selected="selected"';
        }

        $active = $row->getData('active');
        if ($active !== null) {
            $key           = 'option_' . $this->getYesNoRenderer()->calcOptionHash($active);
            $options[$key] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Get or create the profile dropdown renderer.
     *
     * @return ProfileSelect
     */
    private function getProfileRenderer(): ProfileSelect
    {
        if ($this->profileRenderer === null) {
            $this->profileRenderer = $this->getLayout()->createBlock(
                ProfileSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->profileRenderer;
    }

    /**
     * Get or create the trust level dropdown renderer.
     *
     * @return TrustLevelSelect
     */
    private function getTrustRenderer(): TrustLevelSelect
    {
        if ($this->trustRenderer === null) {
            $this->trustRenderer = $this->getLayout()->createBlock(
                TrustLevelSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->trustRenderer;
    }

    /**
     * Get or create the Yes/No dropdown renderer.
     *
     * @return YesNoSelect
     */
    private function getYesNoRenderer(): YesNoSelect
    {
        if ($this->yesNoRenderer === null) {
            $this->yesNoRenderer = $this->getLayout()->createBlock(
                YesNoSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->yesNoRenderer;
    }
}
