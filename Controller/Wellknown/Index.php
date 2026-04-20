<?php
declare(strict_types=1);

namespace MSR\AgenticUcp\Controller\Wellknown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use MSR\AgenticUcp\Model\Config\AgentConfigProvider;

/**
 * Serves the /.well-known/ucp.json agent manifest endpoint.
 *
 * Structure follows the UCP specification (2026-04-08):
 *   ucp.services  — transport + auth endpoint
 *   ucp.capabilities — what this store supports (injectable via di.xml)
 *   ucp.payment_handlers — payment methods that support UCP (injectable via di.xml)
 *   ucp.agents — registered agents (from admin panel via AgentConfigProvider)
 */
class Index implements HttpGetActionInterface
{
    private const UCP_VERSION = '2026-04-08';

    /**
     * @param JsonFactory          $jsonFactory
     * @param StoreManagerInterface $storeManager
     * @param AgentConfigProvider  $configProvider   reads agents from admin panel DB
     * @param array                $capabilities     injected via di.xml — child modules add entries
     * @param array                $paymentHandlers  injected via di.xml — payment modules add entries
     */
    public function __construct(
        private readonly JsonFactory            $jsonFactory,
        private readonly StoreManagerInterface  $storeManager,
        private readonly AgentConfigProvider    $configProvider,
        private readonly array $capabilities    = [],
        private readonly array $paymentHandlers = [],
    ) {
    }

    /**
     * @return ResultInterface|ResponseInterface
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $result   = $this->jsonFactory->create();
        $storeUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');

        $manifest = [
            'ucp' => [
                'version' => self::UCP_VERSION,
                'services' => $this->buildServices($storeUrl),
                'capabilities' => $this->buildCapabilities(),
                'payment_handlers' => $this->buildPaymentHandlers(),
                'agents' => $this->buildAgents(),
            ],
        ];

        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setData($manifest);
        return $result;
    }

    /**
     * Services block — transport declaration and auth endpoint.
     * This is constant for all Magento UCP installs (REST transport).
     */
    private function buildServices(string $storeUrl): array
    {
        return [
            'dev.ucp.shopping' => [[
                'version' => self::UCP_VERSION,
                'spec' => 'https://ucp.dev/' . self::UCP_VERSION . '/specification/overview',
                'transport' => 'rest',
                'schema'    => 'https://ucp.dev/' . self::UCP_VERSION . '/services/shopping/rest.openapi.json',
                'base_url'  => $storeUrl . '/rest/V1/ucp',
                'auth' => [
                    'type' => 'did_jwt',
                    'endpoint' => $storeUrl . '/rest/V1/ucp/auth',
                ],
            ]],
        ];
    }

    /**
     * Capabilities block — built entirely from the injected $capabilities array.
     * Base module registers the four core capabilities in di.xml.
     * Child modules add their own entries via di.xml — no base module changes needed.
     *
     * Each injected item must have at minimum: 'id'
     * Optional: 'version', 'spec', 'schema', 'extends', 'config'
     */
    private function buildCapabilities(): array
    {
        $result  = [];
        $baseUrl = 'https://ucp.dev/' . self::UCP_VERSION;

        foreach ($this->capabilities as $cap) {
            $id = $cap['id'] ?? null;
            if (!$id) {
                continue;
            }

            // Build the spec/schema URLs from the capability id if not explicitly provided
            // e.g. "dev.ucp.shopping.checkout" → ".../specification/checkout"
            $slug  = basename(str_replace('.', '/', $id));
            $entry = [
                'version' => $cap['version'] ?? self::UCP_VERSION,
                'spec' => $cap['spec']    ?? ($baseUrl . '/specification/' . $slug),
                'schema' => $cap['schema']  ?? ($baseUrl . '/schemas/shopping/' . $slug . '.json'),
            ];

            if (!empty($cap['extends'])) {
                $entry['extends'] = $cap['extends'];
            }
            if (!empty($cap['config'])) {
                $entry['config'] = $cap['config'];
            }

            $result[$id] = [$entry];
        }

        return $result;
    }

    /**
     * Payment handlers block — built from the injected $paymentHandlers array.
     * Returns an empty JSON object {} when no payment modules have injected handlers.
     * Payment modules (Stripe, Google Pay etc) inject their entries via di.xml.
     *
     * Each injected item must have at minimum: 'id', 'handler_id'
     * Optional: 'version', 'spec', 'schema', 'config'
     */
    private function buildPaymentHandlers(): array|object
    {
        if (empty($this->paymentHandlers)) {
            return (object)[];
        }

        $result = [];
        foreach ($this->paymentHandlers as $handler) {
            $id = $handler['id'] ?? null;
            if (!$id) {
                continue;
            }

            $entry = [
                'id' => $handler['handler_id'] ?? $id,
                'version' => $handler['version']    ?? self::UCP_VERSION,
            ];

            if (!empty($handler['spec']))   $entry['spec']   = $handler['spec'];
            if (!empty($handler['schema'])) $entry['schema'] = $handler['schema'];
            if (!empty($handler['config'])) $entry['config'] = $handler['config'];

            $result[$id] = [$entry];
        }

        return $result ?: (object)[];
    }

    /**
     * Agents block — reads from admin panel database via AgentConfigProvider.
     * NOT from ucp.xml — agents are registered at runtime in the admin panel.
     * ucp.xml only contains capability profiles (profile-readonly, profile-shopping etc).
     */
    private function buildAgents(): array
    {
        $agents = $this->configProvider->getAllAgents();

        return array_values(array_map(fn(array $agent) => [
            'did' => $agent['identity']['did']         ?? '',
            'name' => $agent['identity']['name']        ?? '',
            'trust_level' => $agent['identity']['trust_level'] ?? 'provisional',
            'profile' => $agent['profile']                 ?? '',
        ], array_filter($agents, fn(array $a) => !empty($a['identity']['did']))));
    }
}

