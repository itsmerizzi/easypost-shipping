<?php

namespace Tests\Feature\Labels;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesEasyPost;
use Tests\TestCase;

class CreateLabelFailureTest extends TestCase
{
    use FakesEasyPost;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    public function test_no_usps_rate_returns_422_and_buys_nothing(): void
    {
        $created = $this->easyPostFixture('shipment_created.json');
        $created['rates'] = array_values(array_filter($created['rates'], fn (array $rate) => $rate['carrier'] !== 'USPS'));
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response($created)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No USPS rate available for this shipment.');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/buy'));
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_easypost_client_error_is_returned_as_422_with_its_message(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response([
            'error' => ['code' => 'ADDRESS.VERIFY.FAILURE', 'message' => 'Unable to verify address.', 'errors' => []],
        ], 422)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to verify address.');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_easypost_server_error_is_retried_then_returned_as_502(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Shipping provider unavailable, please try again.');

        Http::assertSentCount(3);
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_invalid_api_key_is_our_problem_not_the_users(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $response = $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload());

        $response->assertStatus(502);
        $this->assertStringNotContainsString('API key', $response->getContent());
    }

    public function test_buy_failure_is_never_retried_and_nothing_is_stored(): void
    {
        $this->fakeEasyPost([self::EASYPOST_BUY => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload())->assertStatus(502);

        Http::assertSentCount(2); // 1 create + 1 buy, no retry on buy
        $this->assertDatabaseCount('shipping_labels', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_download_failure_after_buy_is_logged_and_returns_502(): void
    {
        Log::spy();
        $this->fakeEasyPost([self::EASYPOST_LABEL => Http::response('nope', 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->validLabelPayload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'The label was purchased but could not be saved. Please contact support.');

        $this->assertDatabaseCount('shipping_labels', 0);
        // The exception handler also logs the reported exception, so match our call by its context.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => ($context['shipment_id'] ?? null) === 'shp_test0001'
                && ($context['user_id'] ?? null) === $this->user->id
                && str_contains($context['label_url'] ?? '', 'shp_test0001.pdf'))
            ->once();
    }
}
