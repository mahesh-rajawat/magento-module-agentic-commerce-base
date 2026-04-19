<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MSR\AgenticUcp\Model\Config\UcpReader;

/**
 * Provides agent profile options sourced from ucp.xml profile-* entries.
 */
class AgentProfiles implements OptionSourceInterface
{
    /**
     * @param UcpReader $ucpReader
     * @param Capabilities $capabilities
     */
    public function __construct(
        private readonly UcpReader    $ucpReader,
        private readonly Capabilities $capabilities,
    ) {
    }

    /**
     * Builds the dropdown from profile IDs in ucp.xml.
     *
     * Any module can add profiles by dropping a ucp.xml —
     * they appear here automatically.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $config  = $this->ucpReader->read();
        $options = [['value' => '', 'label' => '-- Select profile --']];

        foreach ($config['agent'] ?? [] as $id => $agent) {
            // Only show profiles (id starts with "profile-")
            // Real agents registered in DB don't appear here
            if (!str_starts_with($id, 'profile-')) {
                continue;
            }

            $enabledCaps = [];
            foreach ($agent['capabilities']['capability'] ?? [] as $name => $cap) {
                if ($cap['enabled'] ?? true) {
                    $enabledCaps[] = $this->capabilities->getLabel($name);
                }
            }

            $label = $this->humanizeProfileId($id);
            $hint  = empty($enabledCaps)
                ? 'No capabilities'
                : implode(', ', $enabledCaps);

            $options[] = [
                'value' => $id,
                'label' => $label . ' — ' . $hint,
            ];
        }

        return $options;
    }

    /**
     * Get the capabilities array for a given profile ID.
     *
     * Used by AgentConfigProvider when building the agent config.
     *
     * @param string $profileId
     * @return array
     */
    public function getProfileCapabilities(string $profileId): array
    {
        $config = $this->ucpReader->read();
        return $config['agent'][$profileId]['capabilities'] ?? [];
    }

    /**
     * Convert a profile ID to a human-readable label.
     *
     * @param string $id
     * @return string
     */
    private function humanizeProfileId(string $id): string
    {
        // "profile-shopping" → "Shopping agent"
        // "profile-full-access" → "Full access"
        $name = str_replace('profile-', '', $id);
        $name = str_replace('-', ' ', $name);
        return ucwords($name);
    }
}
