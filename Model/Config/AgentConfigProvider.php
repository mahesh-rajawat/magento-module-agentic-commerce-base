<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use MSR\AgenticUcp\Model\Config\Source\AgentProfiles;

/**
 * Provides merged agent configuration from DB (admin panel) and ucp.xml defaults.
 */
class AgentConfigProvider
{
    private const XML_PATH_ENABLED        = 'msr_agentic_ucp/general/enabled';
    private const XML_PATH_REGISTRY       = 'msr_agentic_ucp/agents/registry';
    private const XML_PATH_HUMAN_CONFIRM  = 'msr_agentic_ucp/defaults/require_human_confirmation';
    private const XML_PATH_RATE_LIMIT     = 'msr_agentic_ucp/defaults/rate_limit_per_minute';
    private const XML_PATH_MAX_ORDER      = 'msr_agentic_ucp/defaults/max_order_value';
    private const XML_PATH_AUDIT_LOG      = 'msr_agentic_ucp/defaults/audit_log';
    private const XML_PATH_TTL            = 'msr_agentic_ucp/defaults/ttl_seconds';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     * @param UcpReader $ucpReader
     * @param AgentProfiles $agentProfiles
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly UcpReader $ucpReader,
        private readonly AgentProfiles $agentProfiles
    ) {
    }

    /**
     * Check whether the UCP integration is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Get merged agent config — DB config wins over ucp.xml defaults.
     *
     * This is the single method everything else calls.
     *
     * @param string $did
     * @return array|null
     */
    public function getAgentConfig(string $did): ?array
    {
        // DB agent record — set in admin panel
        $dbAgents = $this->getDbAgents();
        $dbAgent  = null;
        foreach ($dbAgents as $a) {
            if (($a['did'] ?? '') === $did && !empty($a['active'])) {
                $dbAgent = $a;
                break;
            }
        }

        if ($dbAgent === null) {
            return null;   // not registered at all
        }

        // Resolve capabilities from the assigned profile
        $profileId    = $dbAgent['profile'] ?? 'profile-shopping';
        $capabilities = $this->agentProfiles
            ->getProfileCapabilities($profileId);

        return [
            'active'   => true,
            'identity' => [
                'did'         => $did,
                'name'        => $dbAgent['name'] ?? '',
                'trust_level' => $dbAgent['trust_level'] ?? 'provisional',
            ],
            'capabilities' => $capabilities,
            'permissions'  => [
                'max_order_value'         => (float)($dbAgent['max_order_value']
                    ?? $this->scopeConfig->getValue(self::XML_PATH_MAX_ORDER)),
                'allowed_payment_methods' => !empty($dbAgent['allowed_payment_methods'])
                    ? array_map('trim', explode(',', $dbAgent['allowed_payment_methods']))
                    : [],
            ],
            'policies' => [
                'require_human_confirmation' =>
                    (bool)($dbAgent['require_human_confirmation']
                        ?? $this->scopeConfig->isSetFlag(self::XML_PATH_HUMAN_CONFIRM)),
                'rate_limit_per_minute' =>
                    (int)($dbAgent['rate_limit_per_minute']
                        ?? $this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT)),
                'audit_log'    => true,
                'ttl_seconds'  =>
                    (int)($dbAgent['ttl_seconds']
                        ?? $this->scopeConfig->getValue(self::XML_PATH_TTL)),
            ],
        ];
    }

    /**
     * Get all active agents from both sources for the manifest endpoint.
     *
     * @return array
     */
    public function getAllAgents(): array
    {
        $xmlConfig = $this->ucpReader->read();
        $xmlAgents = [];
        foreach ($xmlConfig['agent'] ?? [] as $id => $agent) {
            if (($agent['active'] ?? true) !== false) {
                $xmlAgents[$agent['identity']['did'] ?? $id] = $agent;
            }
        }

        // DB agents override or extend xml agents
        foreach ($this->getDbAgents() as $dbAgent) {
            $did = $dbAgent['did'] ?? '';
            if (empty($did)) {
                continue;
            }
            if (isset($xmlAgents[$did])) {
                $xmlAgents[$did] = $this->merge($xmlAgents[$did], $dbAgent);
            } elseif (!empty($dbAgent['active'])) {
                $xmlAgents[$did] = $this->buildAgentFromDb($dbAgent);
            }
        }

        return array_values($xmlAgents);
    }

    /**
     * Check whether human confirmation is required for an agent.
     *
     * @param array|null $agentConfig
     * @return bool
     */
    public function requiresHumanConfirmation(?array $agentConfig = null): bool
    {
        // Per-agent override takes priority
        if ($agentConfig !== null
            && isset($agentConfig['policies']['require_human_confirmation'])) {
            return (bool)$agentConfig['policies']['require_human_confirmation'];
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HUMAN_CONFIRM);
    }

    /**
     * Get the rate limit for an agent.
     *
     * @param array|null $agentConfig
     * @return int
     */
    public function getRateLimit(?array $agentConfig = null): int
    {
        if ($agentConfig !== null
            && isset($agentConfig['policies']['rate_limit_per_minute'])) {
            return (int)$agentConfig['policies']['rate_limit_per_minute'];
        }
        return (int)$this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT);
    }

    /**
     * Get the maximum order value for an agent.
     *
     * @param array|null $agentConfig
     * @return float
     */
    public function getMaxOrderValue(?array $agentConfig = null): float
    {
        if ($agentConfig !== null
            && isset($agentConfig['permissions']['max_order_value'])) {
            return (float)$agentConfig['permissions']['max_order_value'];
        }
        return (float)$this->scopeConfig->getValue(self::XML_PATH_MAX_ORDER);
    }

    /**
     * Check whether audit logging is enabled for an agent.
     *
     * @param array|null $agentConfig
     * @return bool
     */
    public function isAuditEnabled(?array $agentConfig = null): bool
    {
        if ($agentConfig !== null
            && isset($agentConfig['policies']['audit_log'])) {
            return (bool)$agentConfig['policies']['audit_log'];
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUDIT_LOG);
    }

    /**
     * Get the token TTL in seconds for an agent.
     *
     * @param array|null $agentConfig
     * @return int
     */
    public function getTtl(?array $agentConfig = null): int
    {
        if ($agentConfig !== null
            && isset($agentConfig['policies']['ttl_seconds'])) {
            return (int)$agentConfig['policies']['ttl_seconds'];
        }
        return (int)$this->scopeConfig->getValue(self::XML_PATH_TTL);
    }

    /**
     * Load and decode registered agents from admin panel config.
     *
     * @return array
     */
    private function getDbAgents(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_REGISTRY);
        if (empty($raw)) {
            return [];
        }
        try {
            return $this->json->unserialize($raw) ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Merge DB agent overrides into an xml-sourced agent config.
     *
     * @param array $base
     * @param array $db
     * @return array
     */
    private function merge(array $base, array $db): array
    {
        // DB overrides specific fields — never overwrites capabilities
        // (capabilities are always owned by ucp.xml / code)
        if (!empty($db['did'])) {
            $base['identity']['did'] = $db['did'];
        }
        if (!empty($db['trust_level'])) {
            $base['identity']['trust_level'] = $db['trust_level'];
        }
        if (!empty($db['name'])) {
            $base['identity']['name'] = $db['name'];
        }
        if (isset($db['max_order_value'])) {
            $base['permissions']['max_order_value'] = (float)$db['max_order_value'];
        }
        if (!empty($db['allowed_payment_methods'])) {
            $base['permissions']['allowed_payment_methods'] =
                array_map('trim', explode(',', $db['allowed_payment_methods']));
        }
        if (isset($db['rate_limit_per_minute'])) {
            $base['policies']['rate_limit_per_minute'] = (int)$db['rate_limit_per_minute'];
        }
        if (isset($db['require_human_confirmation'])) {
            $base['policies']['require_human_confirmation'] =
                (bool)$db['require_human_confirmation'];
        }
        if (isset($db['ttl_seconds'])) {
            $base['policies']['ttl_seconds'] = (int)$db['ttl_seconds'];
        }
        if (isset($db['active'])) {
            $base['active'] = (bool)$db['active'];
        }
        return $base;
    }

    /**
     * Build a full agent config array from a DB-only agent record.
     *
     * @param array $db
     * @return array
     */
    private function buildAgentFromDb(array $db): array
    {
        return [
            'active'       => (bool)($db['active'] ?? true),
            'identity'     => [
                'did'         => $db['did'] ?? '',
                'name'        => $db['name'] ?? '',
                'trust_level' => $db['trust_level'] ?? 'provisional',
            ],
            'capabilities' => ['capability' => []],
            'permissions'  => [
                'max_order_value'        => (float)($db['max_order_value'] ?? 0),
                'allowed_payment_methods'=> !empty($db['allowed_payment_methods'])
                    ? array_map('trim', explode(',', $db['allowed_payment_methods']))
                    : [],
            ],
            'policies' => [
                'require_human_confirmation' => (bool)($db['require_human_confirmation'] ?? true),
                'rate_limit_per_minute'      => (int)($db['rate_limit_per_minute'] ?? 30),
                'audit_log'                  => true,
                'ttl_seconds'                => (int)($db['ttl_seconds'] ?? 3600),
            ],
        ];
    }
}
