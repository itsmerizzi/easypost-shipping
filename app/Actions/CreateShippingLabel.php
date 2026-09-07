<?php

namespace App\Actions;

use App\Exceptions\LabelNotPersistedException;
use App\Exceptions\NoUspsRateException;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Services\EasyPost\EasyPostClient;
use App\Services\EasyPost\RateSelector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreateShippingLabel
{
    public function __construct(private readonly EasyPostClient $easyPost) {}

    /**
     * @param  array{from_address: array<string, mixed>, to_address: array<string, mixed>, parcel: array<string, mixed>}  $data  StoreLabelRequest::validated()
     */
    public function handle(User $user, array $data): ShippingLabel
    {
        $shipment = $this->easyPost->createShipment([
            'from_address' => $data['from_address'],
            'to_address' => $data['to_address'],
            'parcel' => [
                'weight' => $data['parcel']['weight_oz'],
                'length' => $data['parcel']['length_in'],
                'width' => $data['parcel']['width_in'],
                'height' => $data['parcel']['height_in'],
            ],
            'options' => ['label_format' => 'PDF'],
        ]);

        $rate = RateSelector::lowestUsps($shipment['rates'] ?? [])
            ?? throw new NoUspsRateException($shipment['id']);

        $bought = $this->easyPost->buyShipment($shipment['id'], $rate['id']);

        try {
            $path = sprintf('labels/%d/%s.pdf', $user->id, Str::uuid());
            Storage::disk('local')->put($path, $this->easyPost->downloadLabel($bought['postage_label']['label_url']));

            return $user->labels()->create([
                'easypost_shipment_id' => $bought['id'],
                'tracking_code' => $bought['tracking_code'] ?? null,
                'carrier' => $bought['selected_rate']['carrier'] ?? $rate['carrier'],
                'service' => $bought['selected_rate']['service'] ?? $rate['service'],
                'rate' => $bought['selected_rate']['rate'] ?? $rate['rate'],
                'currency' => $bought['selected_rate']['currency'] ?? $rate['currency'] ?? 'USD',
                'from_address' => $data['from_address'],
                'to_address' => $data['to_address'],
                'parcel' => $data['parcel'],
                'label_file_path' => $path,
                'label_file_type' => $bought['postage_label']['label_file_type'] ?? 'application/pdf',
                'label_url' => $bought['postage_label']['label_url'],
                'easypost_response' => $bought,
            ]);
        } catch (Throwable $e) {
            Log::error('Label bought at EasyPost but not persisted', [
                'user_id' => $user->id,
                'shipment_id' => $bought['id'],
                'label_url' => $bought['postage_label']['label_url'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            throw new LabelNotPersistedException($bought['id'], $e);
        }
    }
}
