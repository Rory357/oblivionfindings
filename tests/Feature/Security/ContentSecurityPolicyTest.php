<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App-wide Content-Security-Policy header (defence-in-depth) emitted by
 * {@see AddContentSecurityPolicy}. Verifies the report-only
 * default, the enforce + disable toggles, and that the inline bootstrap script
 * carries the same nonce the policy whitelists.
 *
 * withoutVite() stubs the @vite directive so the blade renders without a built
 * manifest; the inline-script nonce (a direct Vite::cspNonce() call) and the
 * middleware header are unaffected.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_responses_get_a_report_only_csp_when_not_enforcing(): void
    {
        $this->withoutVite();
        config(['app.csp_enabled' => true, 'app.csp_enforce' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp, 'Expected a report-only CSP on the HTML response.');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.bunny.net", $csp);
        $this->assertStringContainsString("connect-src 'self' https://fonts.bunny.net", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_csp_is_enforced_when_configured(): void
    {
        $this->withoutVite();
        config(['app.csp_enabled' => true, 'app.csp_enforce' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_csp_can_be_disabled(): void
    {
        $this->withoutVite();
        config(['app.csp_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_inline_bootstrap_script_carries_the_policy_nonce(): void
    {
        $this->withoutVite();
        config(['app.csp_enabled' => true, 'app.csp_enforce' => true]);

        $response = $this->get('/login');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $matches));

        // The same nonce the policy whitelists must be stamped on the inline
        // <script> in the document head, or that script would be blocked.
        $response->assertSee('nonce="'.$matches[1].'"', false);
    }
}
