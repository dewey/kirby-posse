<?php

namespace Notmyhostname\Posse\Middleware;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

class Auth
{
    public static function handle(App $kirby)
    {
        $config = new \Notmyhostname\Posse\Models\Config();
        $token = $config->option('auth_token');
        $enableTokenAuth = $config->option('enable_token_auth');
        
        // If token auth is enabled and token is set, use token authentication
        if ($enableTokenAuth && !empty($token)) {
            $requestToken = $kirby->request()->header('X-POSSE-Token');
            
            if (empty($requestToken) || $requestToken !== $token) {
                throw new PermissionException('Token authentication is enabled but the token is missing or invalid. Please use the X-POSSE-Token header as token authentication takes precedence over Basic Auth when enabled.');
            }
            return;
        }
        
        // Fall back to Basic Auth if no token is set or token auth is disabled
        if (!$kirby->option('api.basicAuth')) {
            throw new PermissionException('No authentication method configured. Please set up either a token or enable Basic Auth.');
        }
        
        $auth = $kirby->request()->auth();
        if (!$auth || !$auth->type() === 'basic') {
            throw new PermissionException('Basic Auth required');
        }
    }
} 