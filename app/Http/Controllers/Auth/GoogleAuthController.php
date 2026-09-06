<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\AuthGoogle\GoogleAuthProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google OAuth controller delegating to Modules\AuthGoogle\GoogleAuthProvider.
 */
class GoogleAuthController extends Controller
{
    public function redirect(GoogleAuthProvider $provider): Response
    {
        return $provider->handleRedirect();
    }

    public function callback(Request $request, GoogleAuthProvider $provider): RedirectResponse
    {
        return $provider->handleCallback($request);
    }
}
