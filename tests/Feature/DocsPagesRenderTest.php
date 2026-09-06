<?php

use App\Models\Server;
use App\Models\User;

describe('the CloudProvider vs HostingProvider docs pages', function () {
    beforeEach(function () {
        // AppServiceProvider's layouts.app view composer needs a Server to
        // exist or RedirectToSetupIfFreshInstall would apply to the dashboard
        // — not relevant to /docs, but IssueCounter::total() still runs on
        // every authenticated page render regardless of route.
        Server::factory()->create();
        mockIssueCounterZero();

        $this->actingAs(User::factory()->create());
    });

    it('renders the concepts page explaining the two independent axes', function () {
        $response = $this->get('/docs/concepts/server-site-care-plan');

        $response->assertOk();
        $response->assertSee('Two independent axes');
        $response->assertSee('Hetzner');
        $response->assertSee('Azure');
    });

    it('renders the integrations overview split into cloud vs hosting provider groups', function () {
        $response = $this->get('/docs/integrations/overview');

        $response->assertOk();
        $response->assertSee('Cloud / server providers');
        $response->assertSee('WordPress hosting providers');
    });

    it('renders the 3 new hosting-provider integration pages with their honesty-about-assumptions sections', function () {
        $wpEngine = $this->get('/docs/integrations/wp-engine');
        $wpEngine->assertOk();
        $wpEngine->assertSee('has not yet been exercised against a live WP Engine account');

        $kinsta = $this->get('/docs/integrations/kinsta');
        $kinsta->assertOk();
        $kinsta->assertSee('biggest unverified assumption', false);

        $cloudways = $this->get('/docs/integrations/cloudways');
        $cloudways->assertOk();
        $cloudways->assertSee('SSH/sudo model is the largest structural risk', false);
    });

    it('renders the contributing page with the vibe-coding and PR workflow', function () {
        $response = $this->get('/docs/getting-started/contributing');

        $response->assertOk();
        $response->assertSee('Contributing &amp; Module Testing', false);
        $response->assertSee('Vibe Code w/Claude');
        $response->assertSee('Modules Looking for Testers');
        $response->assertSee('Submit a Pull Request');
    });

    it('renders the integrations overview with the real-world testing status matrix', function () {
        $response = $this->get('/docs/integrations/overview');

        $response->assertOk();
        $response->assertSee('Real-world testing status matrix');
        $response->assertSee('Verified in Production');
        $response->assertSee('Looking for Testers');
    });

    it('renders the module directory documentation page', function () {
        $response = $this->get('/docs/features/module-directory');

        $response->assertOk();
        $response->assertSee('Module Directory');
        $response->assertSee('https://clockworkcontrol.com/api/modules.json');
        $response->assertSee('ModuleDirectoryClient');
    });
});
