<?php
namespace App\Core;

use Phroute;
use League;

class RouterResolver implements Phroute\Phroute\HandlerResolverInterface
{
    private $container;

    public function __construct(League\Container\Container $container)
    {
        $this->container = $container;
    }

    public function resolve($handler)
    {
        if(is_array($handler) and is_string($handler[0]))
        {
            $handler[0] = $this->container->get($handler[0]);
        }

        return $handler;
    }
}
