<?php

namespace Database\Factories;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShippingLabel>
 */
class ShippingLabelFactory extends Factory
{
    public function definition(): array
    {
        $shipmentId = 'shp_'.Str::lower(Str::random(32));

        return [
            'user_id' => User::factory(),
            'easypost_shipment_id' => $shipmentId,
            'tracking_code' => '9400100000000000000'.fake()->numerify('###'),
            'carrier' => 'USPS',
            'service' => 'GroundAdvantage',
            'rate' => '7.33',
            'currency' => 'USD',
            'from_address' => $this->address('CA', '94107'),
            'to_address' => $this->address('NY', '10001'),
            'parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
            'label_file_path' => 'labels/1/'.Str::uuid().'.pdf',
            'label_file_type' => 'application/pdf',
            'label_url' => 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/'.$shipmentId.'.pdf',
            'easypost_response' => ['id' => $shipmentId, 'object' => 'Shipment', 'mode' => 'test'],
        ];
    }

    private function address(string $state, string $zip): array
    {
        return [
            'name' => fake()->name(),
            'street1' => fake()->streetAddress(),
            'street2' => null,
            'city' => fake()->city(),
            'state' => $state,
            'zip' => $zip,
            'country' => 'US',
            'phone' => null,
        ];
    }
}
