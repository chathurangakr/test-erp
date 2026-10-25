<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pages tell the browser to run only the ERP's own scripts; the one inline script carries the
 * response's nonce, so it still runs while anything slipped into a page does not.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_page_allows_only_its_own_scripts_and_nonces_the_inline_one(): void
    {
        $response = $this->get(route('login'))->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+';/", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', $response->getContent());
    }
}
