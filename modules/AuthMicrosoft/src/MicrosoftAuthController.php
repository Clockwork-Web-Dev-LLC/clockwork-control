<?php

namespace Modules\AuthMicrosoft;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MicrosoftAuthController extends Controller
{
    public function redirect(MicrosoftAuthProvider $provider): Response
    {
        return $provider->handleRedirect();
    }

    public function callback(Request $request, MicrosoftAuthProvider $provider): RedirectResponse
    {
        return $provider->handleCallback($request);
    }
}
