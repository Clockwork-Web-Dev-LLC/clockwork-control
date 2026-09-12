<?php

use App\Mail\SiteVulnerabilityReportMail;
use App\Models\CisaKevEntry;
use App\Models\PluginVulnerability;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('CISA KEV actively exploited badge', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->server = Server::factory()->create(['name' => 'prod-server-01']);
    });

    it('renders the Actively exploited (CISA KEV) badge on /issues when an installed plugin matches a KEV CVE', function () {
        CisaKevEntry::factory()->create([
            'cve' => 'CVE-2024-5555',
            'vendor_project' => 'Vulnerable Co',
            'product' => 'Sample Addon',
        ]);

        PluginVulnerability::factory()->create([
            'slug' => 'sample-addon',
            'software_type' => 'plugin',
            'cve' => 'CVE-2024-5555',
            'from_version' => null,
            'to_version' => '2.0.0',
            'to_inclusive' => false,
            'patched_in' => '2.0.0',
            'title' => 'Critical Remote Code Execution in Sample Addon',
            'cvss_score' => 9.8,
            'cvss_severity' => 'critical',
        ]);

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'cisa-target.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'counts' => [
                        'total' => 1,
                        'updates_available' => 1,
                    ],
                    'plugins' => [
                        [
                            'slug' => 'sample-addon/sample-addon.php',
                            'name' => 'Sample Addon',
                            'version' => '1.5.0',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->get(route('issues.index'));

        $response->assertOk();
        $response->assertSee('cisa-target.example.com');
        $response->assertSee('CVE-2024-5555');
        $response->assertSee('Actively exploited (CISA KEV)');
    });

    it('does NOT render the CISA KEV badge when the CVE is not in the KEV catalog', function () {
        PluginVulnerability::factory()->create([
            'slug' => 'regular-vuln-plugin',
            'software_type' => 'plugin',
            'cve' => 'CVE-2024-1111',
            'from_version' => null,
            'to_version' => '2.0.0',
            'to_inclusive' => false,
            'patched_in' => '2.0.0',
            'title' => 'Moderate CSRF',
            'cvss_score' => 5.4,
            'cvss_severity' => 'medium',
        ]);

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'regular-vuln.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'counts' => [
                        'total' => 1,
                        'updates_available' => 1,
                    ],
                    'plugins' => [
                        [
                            'slug' => 'regular-vuln-plugin/regular-vuln-plugin.php',
                            'name' => 'Regular Vuln Plugin',
                            'version' => '1.2.0',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->get(route('issues.index'));

        $response->assertOk();
        $response->assertSee('regular-vuln.example.com');
        $response->assertSee('CVE-2024-1111');
        $response->assertDontSee('Actively exploited (CISA KEV)');
    });

    it('does NOT render any vulnerability or badge when installed plugin is patched / out of affected range', function () {
        CisaKevEntry::factory()->create(['cve' => 'CVE-2024-9999']);

        PluginVulnerability::factory()->create([
            'slug' => 'patched-plugin',
            'software_type' => 'plugin',
            'cve' => 'CVE-2024-9999',
            'from_version' => null,
            'to_version' => '2.0.0',
            'to_inclusive' => false,
            'patched_in' => '2.0.0',
            'title' => 'Pre-auth RCE',
        ]);

        $site = Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'patched-site.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'counts' => [
                        'total' => 1,
                        'updates_available' => 1,
                    ],
                    'plugins' => [
                        [
                            'slug' => 'patched-plugin/patched-plugin.php',
                            'name' => 'Patched Plugin',
                            'version' => '2.1.0', // Above patched_in
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->get(route('issues.index'));

        $response->assertOk();
        $response->assertDontSee('CVE-2024-9999');
        $response->assertDontSee('Actively exploited (CISA KEV)');
    });

    it('renders the Actively exploited (CISA KEV) badge in SiteVulnerabilityReportMail', function () {
        CisaKevEntry::factory()->create(['cve' => 'CVE-2024-8888']);

        $vulnInKev = PluginVulnerability::factory()->create([
            'slug' => 'exploit-plugin',
            'cve' => 'CVE-2024-8888',
            'title' => 'Known Exploited Vuln',
            'patched_in' => '3.0.0',
        ]);

        $vulnNotInKev = PluginVulnerability::factory()->create([
            'slug' => 'normal-plugin',
            'cve' => 'CVE-2024-7777',
            'title' => 'Standard Vuln',
            'patched_in' => '1.5.0',
        ]);

        $site = Site::factory()->create(['domain' => 'mail-test.example.com']);

        $vulns = [
            [
                'plugin_slug' => 'exploit-plugin',
                'plugin_name' => 'Exploit Plugin',
                'current_version' => '2.0.0',
                'active' => true,
                'patch_available' => true,
                'vulnerability' => $vulnInKev,
            ],
            [
                'plugin_slug' => 'normal-plugin',
                'plugin_name' => 'Normal Plugin',
                'current_version' => '1.0.0',
                'active' => true,
                'patch_available' => true,
                'vulnerability' => $vulnNotInKev,
            ],
        ];

        $mail = new SiteVulnerabilityReportMail($site, $vulns);
        $rendered = $mail->render();

        expect($rendered)->toContain('CVE-2024-8888')
            ->and($rendered)->toContain('CVE-2024-7777')
            ->and($rendered)->toContain('Actively exploited (CISA KEV)');
    });
});
