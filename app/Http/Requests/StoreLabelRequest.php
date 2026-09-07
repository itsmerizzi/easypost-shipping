<?php

namespace App\Http\Requests;

use App\Support\UsStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->addressRules('from_address'),
            ...$this->addressRules('to_address'),
            'parcel' => ['required', 'array'],
            'parcel.weight_oz' => ['required', 'numeric', 'min:0.1', 'max:1120'], // USPS 70 lb limit
            'parcel.length_in' => ['required', 'numeric', 'min:0.1'],
            'parcel.width_in' => ['required', 'numeric', 'min:0.1'],
            'parcel.height_in' => ['required', 'numeric', 'min:0.1'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function addressRules(string $prefix): array
    {
        return [
            $prefix => ['required', 'array'],
            "{$prefix}.name" => ['required', 'string', 'max:255'],
            "{$prefix}.street1" => ['required', 'string', 'max:255'],
            "{$prefix}.street2" => ['nullable', 'string', 'max:255'],
            "{$prefix}.city" => ['required', 'string', 'max:100'],
            "{$prefix}.state" => ['required', 'string', Rule::in(UsStates::CODES)],
            "{$prefix}.zip" => ['required', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            "{$prefix}.country" => ['required', 'string', Rule::in(['US'])],
            "{$prefix}.phone" => ['nullable', 'string', 'max:20'],
        ];
    }
}
