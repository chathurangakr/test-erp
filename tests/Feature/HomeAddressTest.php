<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bare_address_opens_the_dashboard(): void
    {
        $this->get(route('home'))->assertRedirect('/dashboard');
    }

    public function test_a_guest_following_it_reaches_the_sign_in_page(): void
    {
        $this->followingRedirects()
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_a_signed_in_user_following_it_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }
}
