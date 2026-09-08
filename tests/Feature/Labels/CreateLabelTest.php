<?php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesEasyPost;
use Tests\TestCase;

class CreateLabelTest extends TestCase
{
    use FakesEasyPost;
    use RefreshDatabase;

    public function test_user_buys_the_cheapest_usps_label_and_it_is_stored(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/labels', $this->validLabelPayload());

        $response->assertCreated()
            ->assertJsonPath('data.carrier', 'USPS')
            ->assertJsonPath('data.service', 'GroundAdvantage')
            ->assertJsonPath('data.rate', '7.33')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.tracking_code', '9400100000000000000012')
            ->assertJsonPath('data.to_address.city', 'New York')
            ->assertJsonPath('data.parcel.weight_oz', 16)
            ->assertJsonMissingPath('data.label_url')
            ->assertJsonMissingPath('data.easypost_response')
            ->assertJsonMissingPath('data.label_file_path');

        $this->assertStringNotContainsString('amazonaws', $response->getContent());

        $label = ShippingLabel::sole();
        $this->assertSame($user->id, $label->user_id);
        $this->assertSame('shp_test0001', $label->easypost_shipment_id);
        $this->assertSame('application/pdf', $label->label_file_type);
        $this->assertStringStartsWith("labels/{$user->id}/", $label->label_file_path);
        $this->assertStringEndsWith('.pdf', $label->label_file_path);
        Storage::disk('local')->assertExists($label->label_file_path);
        $this->assertSame('shp_test0001', $label->easypost_response['id']);
    }

    public function test_shipment_is_created_as_pdf_and_the_cheapest_usps_rate_is_bought(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())->postJson('/api/labels', $this->validLabelPayload())->assertCreated();

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/v2/shipments')
                && $request['shipment']['options']['label_format'] === 'PDF'
                && $request['shipment']['parcel'] === ['weight' => 16, 'length' => 10, 'width' => 8, 'height' => 4]
                && $request['shipment']['to_address']['zip'] === '10118'
                && $request['shipment']['from_address']['country'] === 'US';
        });

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/shipments/shp_test0001/buy')
            && $request['rate']['id'] === 'rate_usps_ground');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/20260907/shp_test0001.pdf');

        Http::assertSentCount(3);
    }

    public function test_guest_cannot_create_labels(): void
    {
        $this->fakeEasyPost();

        $this->postJson('/api/labels', $this->validLabelPayload())->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_non_us_destination_is_rejected_before_calling_easypost(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validLabelPayload(['to_address' => ['country' => 'BR']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_address.country']);

        Http::assertNothingSent();
    }

    public function test_invalid_zip_and_state_are_rejected(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validLabelPayload([
                'to_address' => ['zip' => 'ABCDE'],
                'from_address' => ['state' => 'XX'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_address.zip', 'from_address.state']);

        Http::assertNothingSent();
    }

    public function test_zip_plus_four_is_accepted(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validLabelPayload(['to_address' => ['zip' => '10118-0110']]))
            ->assertCreated();
    }

    public function test_parcel_must_have_positive_dimensions_and_weight(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validLabelPayload(['parcel' => ['weight_oz' => 0, 'length_in' => -1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parcel.weight_oz', 'parcel.length_in']);

        Http::assertNothingSent();
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'from_address.name', 'from_address.street1', 'from_address.city', 'from_address.state', 'from_address.zip', 'from_address.country',
                'to_address.name', 'to_address.street1', 'to_address.city', 'to_address.state', 'to_address.zip', 'to_address.country',
                'parcel.weight_oz', 'parcel.length_in', 'parcel.width_in', 'parcel.height_in',
            ]);

        Http::assertNothingSent();
    }
}
