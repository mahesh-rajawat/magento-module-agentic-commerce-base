<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the UCP token secret configuration status hint in admin.
 */
class TokenSecretHint extends Field
{
    /**
     * @param DeploymentConfig $deploymentConfig
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array $data
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render the token secret configuration status HTML.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $secret      = $this->deploymentConfig->get('ucp/token_secret');
        $isConfigured = !empty($secret);

        if ($isConfigured) {
            $status = '
            <span style="color:#185B00;font-weight:500">
                ✓ Configured
            </span>
            <span style="color:#666;font-size:12px;margin-left:8px">
                Set in app/etc/env.php — value hidden for security
            </span>';
        } else {
            $status = '
            <span style="color:#b30000;font-weight:500">
                ✗ Not configured
            </span>
            <span style="color:#666;font-size:12px;margin-left:8px">
                Add to app/etc/env.php:
            </span>
            <br>
            <code style="
                display:inline-block;
                margin-top:6px;
                padding:6px 10px;
                background:#f5f5f5;
                border:1px solid #e0e0e0;
                border-radius:3px;
                font-size:12px">
                \'ucp\' =&gt; [\'token_secret\' =&gt; \'&lt;run: php -r "echo bin2hex(random_bytes(32));"&gt;\']
            </code>';
        }

        return "<div style='padding:4px 0'>{$status}</div>";
    }
}
