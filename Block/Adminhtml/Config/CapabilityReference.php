<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Block\Adminhtml\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MSR\AgenticUcp\Model\Config\Source\Capabilities;

class CapabilityReference extends Field
{
    public function __construct(
        private readonly Capabilities $capabilities,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $rows = '';
        foreach ($this->capabilities->toGroupedOptionArray() as $group) {
            $rows .= "<tr><td colspan='3' style='
                padding:8px 12px 4px;
                font-weight:600;
                font-size:12px;
                color:#666;
                border-top:1px solid #e3e3e3;
                background:#f8f8f8'>
                {$group['label']}
            </td></tr>";

            foreach ($group['value'] as $cap) {
                $source = $this->capabilities->toOptionArray();
                $comment = '';
                foreach ($source as $opt) {
                    if ($opt['value'] === $cap['value']) {
                        $comment = $opt['label'];
                        break;
                    }
                }
                $rows .= "
                <tr>
                    <td style='padding:6px 12px;width:180px'>
                        <code style='
                            font-size:11px;
                            background:#f0f0f0;
                            padding:2px 6px;
                            border-radius:3px'>
                            {$cap['value']}
                        </code>
                    </td>
                    <td style='padding:6px 12px;font-weight:500'>{$cap['label']}</td>
                    <td style='padding:6px 12px;color:#666;font-size:12px'>
                        " . str_replace($cap['value'] . ' — ', '', (string)$comment) . "
                    </td>
                </tr>";
            }
        }

        return "
        <div style='margin-top:8px'>
            <table style='
                width:100%;
                border-collapse:collapse;
                border:1px solid #e3e3e3;
                border-radius:4px;
                overflow:hidden;
                font-size:13px'>
                <thead>
                    <tr style='background:#f5f5f5'>
                        <th style='padding:8px 12px;text-align:left;
                                   border-bottom:1px solid #e3e3e3;
                                   font-size:11px;text-transform:uppercase;
                                   color:#999'>Code</th>
                        <th style='padding:8px 12px;text-align:left;
                                   border-bottom:1px solid #e3e3e3;
                                   font-size:11px;text-transform:uppercase;
                                   color:#999'>Name</th>
                        <th style='padding:8px 12px;text-align:left;
                                   border-bottom:1px solid #e3e3e3;
                                   font-size:11px;text-transform:uppercase;
                                   color:#999'>What it allows</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            <p style='margin-top:8px;font-size:12px;color:#999'>
                ⚠ High-risk capabilities always require human confirmation
                regardless of the policy setting above.
            </p>
        </div>";
    }
}
