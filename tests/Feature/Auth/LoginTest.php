<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_guest_cannot_read_current_user(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_read_current_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }
}
