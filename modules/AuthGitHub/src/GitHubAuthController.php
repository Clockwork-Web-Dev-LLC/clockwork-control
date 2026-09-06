<?php

namespace Modules\AuthGitHub;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GitHubAuthController extends Controller
{
    public function redirect(GitHubAuthProvider $provider): Response
    {
        return $provider->handleRedirect();
    }

    public function callback(Request $request, GitHubAuthProvider $provider): RedirectResponse
    {
        return $provider->handleCallback($request);
    }
}
