<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesEasyPost
{
    protected const EASYPOST_CREATE = 'api.easypost.com/v2/shipments';

    protected const EASYPOST_BUY = 'api.easypost.com/v2/shipments/*/buy';

    protected const EASYPOST_LABEL = 'easypost-files.s3.us-west-2.amazonaws.com/*';

    protected function fixturePath(string $file): string
    {
        return base_path("tests/Fixtures/easypost/{$file}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function easyPostFixture(string $file): array
    {
        return json_decode(file_get_contents($this->fixturePath($file)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Happy-path fakes for create, buy and label download. Pass overrides keyed by the
     * EASYPOST_* constants to replace a step (e.g. a 500 on buy).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeEasyPost(array $overrides = []): void
    {
        Http::preventStrayRequests();

        Http::fake($overrides + [
            self::EASYPOST_CREATE => Http::response($this->easyPostFixture('shipment_created.json')),
            self::EASYPOST_BUY => Http::response($this->easyPostFixture('shipment_bought.json')),
            self::EASYPOST_LABEL => Http::response(
                file_get_contents($this->fixturePath('label.pdf')),
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    /**
     * A valid POST /api/labels body (SF -> NYC, 16 oz box). Override nested keys as needed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validLabelPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'from_address' => [
                'name' => 'Ada Lovelace',
                'street1' => '417 Montgomery St',
                'street2' => 'Floor 5',
                'city' => 'San Francisco',
                'state' => 'CA',
                'zip' => '94104',
                'country' => 'US',
                'phone' => null,
            ],
            'to_address' => [
                'name' => 'Grace Hopper',
                'street1' => '350 5th Ave',
                'street2' => null,
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10118',
                'country' => 'US',
                'phone' => null,
            ],
            'parcel' => [
                'weight_oz' => 16,
                'length_in' => 10,
                'width_in' => 8,
                'height_in' => 4,
            ],
        ], $overrides);
    }
}
