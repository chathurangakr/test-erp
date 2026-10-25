<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        // Closed unless switched on (the default since 1.30.0); these tests open it.
        config(['fortify.self_registration' => true]);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_the_sign_in_page_offers_sign_up_while_it_is_open()
    {
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('canRegister', true));
    }

    public function test_closed_sign_up_refuses_the_page_and_the_form()
    {
        config(['fortify.self_registration' => false]);

        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('canRegister', false));
        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }
}
