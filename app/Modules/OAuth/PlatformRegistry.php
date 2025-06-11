<?php

namespace App\Modules\OAuth;

use App\Core\Context;

class PlatformRegistry {
    private $platforms = [];
    private $context;

    public function __construct(Context $context) {
        $this->context = $context;
//        $this->platforms[$platform] = $this->createHandler($platform);
    }

    public function getHandler(string $platform): OAuthHandlerInterface {
        $handlerClass = "\\App\\Modules\\OAuth\\" . ucfirst($platform) . "OAuthHandler";
        if (!class_exists($handlerClass)) {
            throw new \Exception("Handler for platform $platform not found.");
        }

        return new $handlerClass($this->context);
    }

    public function _getHandler(string $platform): OAuthHandlerInterface {
        if (!isset($this->platforms[$platform])) {
            throw new \Exception("Platform $platform not supported.");
        }
        return $this->platforms[$platform];
    }

    private function createHandler(string $platform): OAuthHandlerInterface {
        switch ($platform) {
            case 'clover':
                return new CloverOAuthHandler();
            case 'poynt':
                return new PoyntOAuthHandler($this->context);
            default:
                throw new \Exception("Handler for $platform not implemented.");
        }
    }
//    private function _createHandler(string $platform, array $config): OAuthHandlerInterface {
//        switch ($platform) {
//            case 'clover':
//                return new CloverOAuthHandler($config);
//            case 'poynt':
//                return new PoyntOAuthHandler($config);
//            default:
//                throw new \Exception("Handler for $platform not implemented.");
//        }
//    }
}
