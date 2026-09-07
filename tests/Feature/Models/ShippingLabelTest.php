<?php

namespace Tests\Feature\Models;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_label_owned_by_a_user(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->assertDatabaseCount('shipping_labels', 1);
        $this->assertInstanceOf(User::class, $label->user);
        $this->assertTrue($label->user->labels()->whereKey($label->id)->exists());
    }

    public function test_sensitive_columns_are_hidden_from_serialization(): void
    {
        $label = ShippingLabel::factory()->create();

        $array = $label->toArray();

        $this->assertArrayNotHasKey('label_url', $array);
        $this->assertArrayNotHasKey('easypost_response', $array);
        $this->assertArrayNotHasKey('label_file_path', $array);
        $this->assertArrayHasKey('tracking_code', $array);
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $label = ShippingLabel::factory()->create([
            'parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
        ]);

        $fresh = $label->fresh();

        $this->assertIsArray($fresh->from_address);
        $this->assertIsArray($fresh->to_address);
        $this->assertSame(16, $fresh->parcel['weight_oz']);
        $this->assertIsArray($fresh->easypost_response);
        $this->assertSame('7.33', $fresh->rate);
    }
}
