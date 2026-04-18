<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Plugin\Webapi;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Webapi\Controller\Rest;
use MSR\AgenticUcp\Model\Audit\Logger as AuditLogger;
use MSR\AgenticUcp\Model\Config\AgentConfigProvider;
use MSR\AgenticUcp\Model\Config\Source\Capabilities;
use MSR\AgenticUcp\Model\Config\UcpReader;
use MSR\AgenticUcp\Model\Policy\HumanConfirmationGate;
use MSR\AgenticUcp\Model\Policy\OrderValueGuard;
use MSR\AgenticUcp\Model\Policy\RateLimiter;
use MSR\AgenticUcp\Model\Token\Validator as TokenValidator;

/**
 * Enforces UCP agent policies on all /V1/ucp/* REST routes.
 */
class AgentPolicyGuard
{
    /**
     * UCP routes that bypass the policy guard entirely.
     * Auth endpoint must always be open.
     */
    private const BYPASS_PATHS = [
        '/V1/ucp/auth',
    ];

    /**
     * Maps REST route prefixes to the required UCP capability.
     */
    private const CAPABILITY_MAP = [
        '/V1/ucp/catalog'   => 'catalog.browse',
        '/V1/ucp/search'    => 'catalog.search',
        '/V1/ucp/cart'      => 'cart.manage',
        '/V1/ucp/order'     => 'order.place',
        '/V1/ucp/track'     => 'order.track',
        '/V1/ucp/customer'  => 'customer.read',
        '/V1/ucp/inventory' => 'inventory.query',
        '/V1/ucp/payment'   => 'payment.initiate',
    ];

    /**
     * @param RequestInterface      $request
     * @param TokenValidator        $tokenValidator
     * @param RateLimiter           $rateLimiter
     * @param OrderValueGuard       $orderValueGuard
     * @param HumanConfirmationGate $confirmationGate
     * @param AuditLogger           $auditLogger
     * @param UcpReader             $ucpReader
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly TokenValidator $tokenValidator,
        private readonly RateLimiter $rateLimiter,
        private readonly OrderValueGuard $orderValueGuard,
        private readonly HumanConfirmationGate $confirmationGate,
        private readonly AuditLogger $auditLogger,
        private readonly AgentConfigProvider $configProvider,
        private readonly Capabilities $capabilities
    ) {
    }

    /**
     * Before plugin on Rest::dispatch().
     *
     * Runs all policy checks before any UCP route is executed.
     * Throws on violation — Magento converts these to 4xx HTTP responses.
     *
     * @param Rest             $subject
     * @param RequestInterface $request
     * @return array
     * @throws AuthorizationException
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeDispatch(Rest $subject, RequestInterface $request): array
    {
        $path = $request->getPathInfo();

        // Only guard /V1/ucp/* routes
        if (!str_starts_with($path, '/V1/ucp/')) {
            return [$request];
        }

        // Skip bypass paths (auth endpoint)
        foreach (self::BYPASS_PATHS as $bypass) {
            if (str_starts_with($path, $bypass)) {
                return [$request];
            }
        }

        // ── 1. Extract and validate Bearer token ──────────────────────────
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            throw new AuthorizationException(
                new Phrase('Missing UCP Bearer token. Authenticate via /rest/V1/ucp/auth first.')
            );
        }

        $claims      = $this->tokenValidator->validate($token);
        $did         = $claims['sub'];
        $grantedCaps = $claims['capabilities'] ?? [];
        $agentConfig = $this->loadAgentConfig($did);

        // ── 2. Capability check ───────────────────────────────────────────
        $requiredCap = $this->resolveRequiredCapability($path);
        if ($requiredCap !== null && !in_array($requiredCap, $grantedCaps, true)) {
            $this->auditLogger->log($did, $path, 'DENIED', $requiredCap);
            throw new AuthorizationException(
                new Phrase("Capability '%1' is not granted for this agent.", [$requiredCap])
            );
        }

        // ── 3. Rate limit check ───────────────────────────────────────────
        $rateLimit = (int)($agentConfig['policies']['rate_limit_per_minute'] ?? 0);
        if ($rateLimit > 0 && !$this->rateLimiter->allow($did, $rateLimit)) {
            $this->auditLogger->log($did, $path, 'RATE_LIMITED');
            throw new LocalizedException(
                new Phrase('Rate limit exceeded. Maximum %1 requests per minute.', [$rateLimit])
            );
        }

        // ── 4. Order value check (order.place routes only) ────────────────
        if ($requiredCap === 'order.place') {
            $maxValue = (float)($agentConfig['permissions']['max_order_value'] ?? 0);
            if ($maxValue > 0) {
                $orderTotal = $this->extractOrderTotal($request);
                $this->orderValueGuard->check($orderTotal, $maxValue, $did);
            }
        }

        // ── 5. Human confirmation gate (mutating requests only) ───────────
        $requiredCap    = $this->resolveRequiredCapability($path);
        $isHighRisk     = in_array($requiredCap,
            $this->capabilities->getHighRiskCapabilities(), true);
        $needsConfirm   = $isHighRisk
            || $this->configProvider->requiresHumanConfirmation($agentConfig);

        if ($needsConfirm && $this->isMutatingRequest($request)) {
            $this->confirmationGate->check($request, $did);
        }

        // ── 6. Audit log (ALLOWED) ────────────────────────────────────────
        $shouldAudit = (bool)($agentConfig['policies']['audit_log'] ?? true);
        if ($shouldAudit) {
            $this->auditLogger->log($did, $path, 'ALLOWED', $requiredCap);
        }

        return [$request];
    }

    /**
     * @param RequestInterface $request
     * @return string|null
     */
    private function extractBearerToken(RequestInterface $request): ?string
    {
        $header = $request->getHeader('Authorization') ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * @param string $path
     * @return string|null
     */
    private function resolveRequiredCapability(string $path): ?string
    {
        foreach (self::CAPABILITY_MAP as $prefix => $capability) {
            if (str_starts_with($path, $prefix)) {
                return $capability;
            }
        }
        return null;
    }

    /**
     * @param string $did
     * @return array
     */
    private function loadAgentConfig(string $did): array
    {
        return $this->configProvider->getAgentConfig($did);
    }

    /**
     * @param RequestInterface $request
     * @return float
     */
    private function extractOrderTotal(RequestInterface $request): float
    {
        $body = json_decode((string)$request->getContent(), true);
        return (float)(
            $body['order']['base_grand_total']
            ?? $body['grand_total']
            ?? 0.0
        );
    }

    /**
     * @param RequestInterface $request
     * @return bool
     */
    private function isMutatingRequest(RequestInterface $request): bool
    {
        return in_array(
            strtoupper((string)$request->getMethod()),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true
        );
    }
}
