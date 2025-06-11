<?php

namespace App\Services;

class HelperService {

    public static function validateTokenResponse($tokenResponse): bool
    {
        // Validate token response
        if (!isset($tokenResponse['accessToken'], $tokenResponse['refreshToken'])) {
            return false;

//            $this->context->log->error("Error: Failed to exchange JWT for tokens");
//            Api::response(Response::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Invalid token response.']);
//            exit;
        }
//        $this->tokenResponse = $tokenResponse;
        return true;
    }
}

