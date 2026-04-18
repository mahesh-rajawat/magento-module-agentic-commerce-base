<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Controller\Wellknown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use MSR\AgenticUcp\Model\Config\UcpReader;

/**
 * Serves the /.well-known/ucp.json agent manifest endpoint.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param JsonFactory $jsonFactory
     * @param UcpReader $ucpReader
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly JsonFactory           $jsonFactory,
        private readonly UcpReader             $ucpReader,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $result   = $this->jsonFactory->create();
        $config   = $this->ucpReader->read();
        $storeUrl = $this->storeManager->getStore()->getBaseUrl();
        $manifest = [
            'version'              => '1.0',
            'store_url'            => rtrim($storeUrl, '/'),
            'auth_endpoint'        => '/rest/V1/ucp/auth',
            'api_base'             => '/rest/V1/ucp',
            'agents'               => $this->buildAgentManifest($config),
            'capabilities_offered' => $this->buildCapabilityIndex($config),
        ];
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setData($manifest);
        return $result;
    }

    /**
     * Build the agent manifest array from config.
     *
     * @param array $config
     * @return array
     */
    private function buildAgentManifest(array $config): array
    {
        $agents = [];
        foreach ($config['agent'] ?? [] as $id => $agent) {
            if (($agent['active'] ?? true) === false) {
                continue;
            }
            $enabledCaps = [];
            foreach ($agent['capabilities']['capability'] ?? [] as $name => $cap) {
                if ($cap['enabled'] ?? true) {
                    $enabledCaps[] = $name;
                }
            }
            $agents[] = [
                'id'           => $id,
                'name'         => $agent['identity']['name'] ?? $id,
                'did'          => $agent['identity']['did'] ?? null,
                'trust_level'  => $agent['identity']['trust_level'] ?? 'provisional',
                'capabilities' => $enabledCaps,
            ];
        }
        return $agents;
    }

    /**
     * Build a flat list of all enabled capabilities across all agents.
     *
     * @param array $config
     * @return array
     */
    private function buildCapabilityIndex(array $config): array
    {
        $all = [];
        foreach ($config['agent'] ?? [] as $agent) {
            if (($agent['active'] ?? true) === false) {
                continue;
            }
            foreach ($agent['capabilities']['capability'] ?? [] as $name => $cap) {
                if ($cap['enabled'] ?? true) {
                    $all[$name] = true;
                }
            }
        }
        return array_keys($all);
    }
}
