<?php

namespace Modules\Core\Contracts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface implemented by authentication modules (e.g. Google, GitHub, Microsoft).
 */
interface AuthProvider
{
    /**
     * Unique identifier for this provider (e.g. 'google', 'github', 'microsoft').
     */
    public function id(): string;

    /**
     * Human-friendly label (e.g. 'Google', 'GitHub', 'Microsoft').
     */
    public function name(): string;

    /**
     * Font Awesome icon class (e.g. 'fa-brands fa-google').
     */
    public function icon(): string;

    /**
     * Button display text (e.g. 'Sign in with Google').
     */
    public function buttonLabel(): string;

    /**
     * Route name or URL for starting the OAuth redirect.
     */
    public function redirectUrl(): string;

    /**
     * Route name for the OAuth callback.
     */
    public function callbackUrl(): string;

    /**
     * Whether the provider has required credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Handle the redirect to the third-party OAuth authorization page.
     */
    public function handleRedirect(): Response;

    /**
     * Handle the OAuth callback, validating the code exchange and logging in via the unified allowlist handler.
     */
    public function handleCallback(Request $request): RedirectResponse;
}
