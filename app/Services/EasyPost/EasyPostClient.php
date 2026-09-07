<?php

namespace App\Services\EasyPost;

use App\Services\EasyPost\Exceptions\EasyPostException;
use Closure;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EasyPostClient
{
    private const RETRY_TIMES = 3;

    private const RETRY_SLEEP_MS = 200;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('EASYPOST_API_KEY is not set.');
        }
    }

    /**
     * @param  array<string, mixed>  $shipment  to_address, from_address, parcel, options
     * @return array<string, mixed> EasyPost Shipment object
     */
    public function createShipment(array $shipment): array
    {
        return $this->send(fn () => $this->api()
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, $this->retryWhen(), throw: false)
            ->post('/shipments', ['shipment' => $shipment])
        )->json();
    }

    /**
     * Never retried: a lost response followed by a retry would buy the label twice.
     *
     * @return array<string, mixed> EasyPost Shipment object with postage_label
     */
    public function buyShipment(string $shipmentId, string $rateId): array
    {
        return $this->send(fn () => $this->api()
            ->post("/shipments/{$shipmentId}/buy", ['rate' => ['id' => $rateId]])
        )->json();
    }

    /**
     * The label URL points at EasyPost's file storage, not the API, so no credentials are sent.
     */
    public function downloadLabel(string $url): string
    {
        return $this->send(fn () => Http::timeout($this->timeout)
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, $this->retryWhen(), throw: false)
            ->get($url)
        )->body();
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function retryWhen(): Closure
    {
        return fn (Exception $exception): bool => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw EasyPostException::transport($e);
        }

        if ($response->failed()) {
            throw EasyPostException::fromResponse($response);
        }

        return $response;
    }
}
