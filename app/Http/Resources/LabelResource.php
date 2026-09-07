<?php

namespace App\Http\Resources;

use App\Models\ShippingLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShippingLabel
 */
class LabelResource extends JsonResource
{
    /**
     * Whitelist only. Never expose label_url, easypost_response or label_file_path.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'rate' => $this->rate,
            'currency' => $this->currency,
            'tracking_code' => $this->tracking_code,
            'from_address' => $this->from_address,
            'to_address' => $this->to_address,
            'parcel' => $this->parcel,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
