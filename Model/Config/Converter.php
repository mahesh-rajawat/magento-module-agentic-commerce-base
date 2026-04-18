<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * Converts ucp.xml DOM document to a PHP array keyed by agent id.
 */
class Converter implements ConverterInterface
{
    /**
     * Convert DOM document to array keyed by agent id.
     *
     * @param mixed $source
     * @return array
     */
    public function convert($source): array
    {
        $output = ['agent' => []];

        /** @var \DOMNodeList $agents */
        $agents = $source->getElementsByTagName('agent');

        foreach ($agents as $agentNode) {
            $id     = $agentNode->getAttribute('id');
            $active = $agentNode->getAttribute('active');

            $output['agent'][$id] = [
                'id'           => $id,
                'active'       => $active === '' ? true : filter_var($active, FILTER_VALIDATE_BOOLEAN),
                'identity'     => $this->parseIdentity($agentNode),
                'capabilities' => $this->parseCapabilities($agentNode),
                'permissions'  => $this->parsePermissions($agentNode),
                'policies'     => $this->parsePolicies($agentNode),
            ];
        }

        return $output;
    }

    /**
     * @param \DOMElement $agent
     * @return array
     */
    private function parseIdentity(\DOMElement $agent): array
    {
        $identity = [];
        $node     = $this->getChildElement($agent, 'identity');
        if (!$node) {
            return $identity;
        }

        foreach (['name', 'did', 'public_key', 'trust_level'] as $field) {
            $el = $this->getChildElement($node, $field);
            if ($el) {
                $identity[$field] = trim($el->nodeValue);
            }
        }

        return $identity;
    }

    /**
     * @param \DOMElement $agent
     * @return array
     */
    private function parseCapabilities(\DOMElement $agent): array
    {
        $capabilities = ['capability' => []];
        $node         = $this->getChildElement($agent, 'capabilities');
        if (!$node) {
            return $capabilities;
        }

        foreach ($node->getElementsByTagName('capability') as $cap) {
            $name    = $cap->getAttribute('name');
            $enabled = $cap->getAttribute('enabled');
            $capabilities['capability'][$name] = [
                'name'    => $name,
                'enabled' => $enabled === '' ? true : filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $capabilities;
    }

    /**
     * @param \DOMElement $agent
     * @return array
     */
    private function parsePermissions(\DOMElement $agent): array
    {
        $permissions = [];
        $node        = $this->getChildElement($agent, 'permissions');
        if (!$node) {
            return $permissions;
        }

        $maxOrder = $this->getChildElement($node, 'max_order_value');
        if ($maxOrder) {
            $permissions['max_order_value'] = (float)trim($maxOrder->nodeValue);
        }

        $methods = $this->getChildElement($node, 'allowed_payment_methods');
        if ($methods) {
            $permissions['allowed_payment_methods'] = [];
            foreach ($methods->getElementsByTagName('method') as $method) {
                // Read 'code' attribute instead of nodeValue
                $code = $method->getAttribute('code');
                if ($code) {
                    $permissions['allowed_payment_methods'][] = $code;
                }
            }
        }

        $segments = $this->getChildElement($node, 'customer_segments');
        if ($segments) {
            $permissions['customer_segments'] = [];
            foreach ($segments->getElementsByTagName('segment') as $segment) {
                $code = $segment->getAttribute('code');
                if ($code) {
                    $permissions['customer_segments'][] = $code;
                }
            }
        }

        return $permissions;
    }

    /**
     * @param \DOMElement $agent
     * @return array
     */
    private function parsePolicies(\DOMElement $agent): array
    {
        $policies = [];
        $node     = $this->getChildElement($agent, 'policies');
        if (!$node) {
            return $policies;
        }

        $boolFields = ['require_human_confirmation', 'audit_log'];
        $intFields  = ['rate_limit_per_minute', 'ttl_seconds'];

        foreach ($boolFields as $field) {
            $el = $this->getChildElement($node, $field);
            if ($el) {
                $policies[$field] = filter_var(trim($el->nodeValue), FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach ($intFields as $field) {
            $el = $this->getChildElement($node, $field);
            if ($el) {
                $policies[$field] = (int)trim($el->nodeValue);
            }
        }

        return $policies;
    }

    /**
     * @param \DOMElement $parent
     * @param string $tagName
     * @return \DOMElement|null
     */
    private function getChildElement(\DOMElement $parent, string $tagName): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $tagName) {
                return $child;
            }
        }
        return null;
    }
}
