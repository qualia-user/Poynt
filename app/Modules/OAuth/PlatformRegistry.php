<?php

namespace App\Modules\OAuth;

use App\Core\Context;

class PlatformRegistry {
    private $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function getHandler(string $platform): OAuthHandlerInterface {
        $handlerClass = "\\App\\Modules\\OAuth\\" . ucfirst($platform) . "OAuthHandler";
        if (!class_exists($handlerClass)) {
            throw new \InvalidArgumentException("Unsupported platform: $platform");
        }

        return new $handlerClass($this->context);
    }
}
