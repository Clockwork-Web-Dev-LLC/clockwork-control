<?php

use Illuminate\Support\Facades\Route;

describe('Module Security Middleware', function () {
    it('ensures all module routes register the web middleware group for CSRF and session integrity', function () {
        $moduleRoutePrefixes = [
            'snippets.',
            'clients.',
            'sites.comments.',
            'sites.maintenance-mode.',
            'client-reports.',
            'ai-remedy.',
            'feedback.',
            'settings.bill-com.',
            'settings.mattermost.',
            'settings.slack.',
        ];

        $routes = Route::getRoutes();

        foreach ($moduleRoutePrefixes as $prefix) {
            $matchingRoutes = array_filter(
                $routes->getRoutes(),
                fn ($r) => str_starts_with((string) $r->getName(), $prefix)
            );

            expect(count($matchingRoutes))->toBeGreaterThan(0, "Expected routes with prefix {$prefix}");

            foreach ($matchingRoutes as $route) {
                $middleware = $route->gatherMiddleware();
                expect($middleware)->toContain('web');
            }
        }
    });

    it('enforces admin middleware on dangerous CodeSnippets mutation and execution routes', function () {
        $snippetsExecute = Route::getRoutes()->getByName('snippets.execute');
        expect($snippetsExecute)->not->toBeNull();
        expect($snippetsExecute?->gatherMiddleware())->toContain('admin');

        $snippetsStore = Route::getRoutes()->getByName('snippets.store');
        expect($snippetsStore)->not->toBeNull();
        expect($snippetsStore?->gatherMiddleware())->toContain('admin');
    });

    it('enforces rate limiting on the public client report link', function () {
        $publicReport = Route::getRoutes()->getByName('client-reports.public');
        expect($publicReport)->not->toBeNull();
        expect($publicReport?->gatherMiddleware())->toContain('throttle:60,1');
    });
});
