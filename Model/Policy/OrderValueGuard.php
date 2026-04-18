<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Policy;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Validates that an agent order total does not exceed the configured maximum.
 */
class OrderValueGuard
{
    /**
     * Throws if $orderTotal exceeds $maxAllowed.
     *
     * @param float  $orderTotal Order grand total
     * @param float  $maxAllowed max_order_value from ucp.xml
     * @param string $did        Agent DID (for error context)
     * @return void
     * @throws LocalizedException
     */
    public function check(float $orderTotal, float $maxAllowed, string $did): void
    {
        if ($orderTotal > $maxAllowed) {
            throw new LocalizedException(new Phrase(
                'Order value %1 exceeds the agent limit of %2.',
                [
                    number_format($orderTotal, 2),
                    number_format($maxAllowed, 2),
                ]
            ));
        }
    }
}
