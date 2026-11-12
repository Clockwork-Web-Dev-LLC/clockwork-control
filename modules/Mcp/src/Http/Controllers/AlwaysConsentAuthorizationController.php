<?php

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;

/**
 * Passport skips the consent page when the person already holds a live
 * token for the client. Here it is shown every time, so connecting Claude
 * to Control is always something a person saw and clicked, never something
 * a link did silently.
 */
class AlwaysConsentAuthorizationController extends AuthorizationController
{
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
