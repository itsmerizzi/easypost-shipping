<?php

namespace Tests\Unit;

use App\Services\EasyPost\EasyPostClient;
use App\Services\EasyPost\Exceptions\EasyPostException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EasyPostClientTest extends TestCase
{
    private function client(): EasyPostClient
    {
        return new EasyPostClient(apiKey: 'EZTK_test_key', baseUrl: 'https://api.easypost.com/v2', timeout: 15);
    }

    public function test_create_shipment_posts_to_shipments_with_basic_auth(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['id' => 'shp_1', 'rates' => []])]);

        $result = $this->client()->createShipment(['parcel' => ['weight' => 16]]);

        $this->assertSame('shp_1', $result['id']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.easypost.com/v2/shipments'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('EZTK_test_key:'))
                && $request->hasHeader('Accept', 'application/json')
                && $request['shipment']['parcel']['weight'] === 16;
        });
    }

    public function test_buy_shipment_posts_rate_id_to_buy_endpoint(): void
    {
        Http::fake(['api.easypost.com/v2/shipments/shp_1/buy' => Http::response(['id' => 'shp_1', 'tracking_code' => '9400'])]);

        $result = $this->client()->buyShipment('shp_1', 'rate_1');

        $this->assertSame('9400', $result['tracking_code']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.easypost.com/v2/shipments/shp_1/buy'
            && $request['rate']['id'] === 'rate_1');
    }

    public function test_download_label_returns_raw_body_without_easypost_credentials(): void
    {
        Http::fake(['files.example.com/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

        $bytes = $this->client()->downloadLabel('https://files.example.com/label.pdf');

        $this->assertSame('%PDF-1.4', $bytes);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_client_error_is_wrapped_with_easypost_message(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response([
            'error' => ['code' => 'ADDRESS.VERIFY.FAILURE', 'message' => 'Unable to verify address.', 'errors' => []],
        ], 422)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('Unable to verify address.', $e->getMessage());
            $this->assertTrue($e->isClientError());
        }

        Http::assertSentCount(1);
    }

    public function test_unauthorized_is_not_treated_as_a_client_error(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->isClientError());
        }
    }

    public function test_server_error_on_create_is_retried_twice_then_wrapped(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['error' => ['message' => 'boom']], 500)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(500, $e->status);
            $this->assertFalse($e->isClientError());
        }

        Http::assertSentCount(3);
    }

    public function test_server_error_on_buy_is_never_retried(): void
    {
        Http::fake(['api.easypost.com/v2/shipments/shp_1/buy' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->expectException(EasyPostException::class);

        try {
            $this->client()->buyShipment('shp_1', 'rate_1');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_failure_is_wrapped(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertNull($e->status);
            $this->assertFalse($e->isClientError());
        }
    }

    public function test_container_builds_client_from_config(): void
    {
        config(['services.easypost.key' => 'EZTK_from_config']);

        $this->assertInstanceOf(EasyPostClient::class, app(EasyPostClient::class));
    }

    public function test_empty_api_key_fails_fast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EASYPOST_API_KEY');

        new EasyPostClient(apiKey: '', baseUrl: 'https://api.easypost.com/v2', timeout: 15);
    }
}
