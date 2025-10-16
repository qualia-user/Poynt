<?php
namespace App\Core;

use Phroute;
use League\Container\Container;

class RouterResolver implements Phroute\Phroute\HandlerResolverInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param $handler
     * @return array
     */
    public function resolve($handler)
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler[0] = $this->container->get($handler[0]);
        }

        return $handler;
    }
}
