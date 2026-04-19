<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use MSR\AgenticUcp\Model\Config\Source\Capabilities;

/**
 * Writes UCP agent request outcomes to the audit log table.
 */
class Logger
{
    private const TABLE = 'ucp_audit_log';

    /**
     * @param ResourceConnection $resourceConnection
     * @param Capabilities $capabilities
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Capabilities $capabilities
    ) {
    }

    /**
     * Write an agent request outcome to the audit log table.
     *
     * @param string      $did        Agent DID
     * @param string      $path       Request path e.g. /V1/ucp/order
     * @param string      $outcome    ALLOWED | DENIED | RATE_LIMITED
     * @param string|null $capability Capability name if applicable
     * @return void
     */
    public function log(
        string  $did,
        string  $path,
        string  $outcome,
        ?string $capability = null
    ): void {
        try {
            $connection = $this->resourceConnection->getConnection();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore
            $connection->insert(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'agent_did'    => $did,
                    'request_path' => $path,
                    'outcome'      => $outcome,
                    'capability_label'  => $capability
                        ? $this->capabilities->getLabel($capability)
                        : null,
                    'ip_address'   => $ipAddress,
                ]
            );
        } catch (\Exception $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // Silently fail – audit logging should never break the request
        }
    }
}
