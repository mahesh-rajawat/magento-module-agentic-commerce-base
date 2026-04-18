<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use MSR\AgenticUcp\Controller\Wellknown\Index as WellknownIndex;

/**
 * Routes /.well-known/ucp.json requests to the UCP manifest controller.
 */
class Router implements RouterInterface
{
    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = trim($request->getPathInfo(), '/');

        if ($path !== '.well-known/ucp.json') {
            return null;
        }

        $request->setModuleName('ucpwellknown')
            ->setControllerName('wellknown')
            ->setActionName('index');

        return $this->actionFactory->create(
            WellknownIndex::class
        );
    }
}
