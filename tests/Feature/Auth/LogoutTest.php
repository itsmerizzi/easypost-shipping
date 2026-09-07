<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();

        // Explicit guard: the "auth:sanctum" middleware switches the default guard to
        // "sanctum" for the rest of the request via Auth::shouldUse(), and Sanctum's
        // RequestGuard caches its resolved user for its own lifetime. A bare
        // assertGuest() would check the now-default "sanctum" guard and see that stale
        // cache, even though the "web" guard (which the controller logs out) is guest.
        $this->assertGuest('web');
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
