<?php

namespace App\Models;

use Database\Factories\ShippingLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'easypost_shipment_id',
    'tracking_code',
    'carrier',
    'service',
    'rate',
    'currency',
    'from_address',
    'to_address',
    'parcel',
    'label_file_path',
    'label_file_type',
    'label_url',
    'easypost_response',
])]
#[Hidden(['label_url', 'easypost_response', 'label_file_path'])]
class ShippingLabel extends Model
{
    /** @use HasFactory<ShippingLabelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'from_address' => 'array',
            'to_address' => 'array',
            'parcel' => 'array',
            'easypost_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
