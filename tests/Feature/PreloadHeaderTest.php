<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The preload Link header is capped. Uncapped, it lists every asset with the full address, and
 * a page's response headers outgrew Nginx's FastCGI buffer — every page answered 502 (1.4.0 on a
 * long domain name, 1.6.0 on this computer's own address).
 */
class PreloadHeaderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_page_preloads_at_most_twelve_assets(): void
    {
        $link = (string) $this->get(route('login'))->assertOk()->headers->get('Link');

        $this->assertLessThanOrEqual(12, $link === '' ? 0 : substr_count($link, 'rel="'));
    }
}
