<?php

namespace App\Services\EasyPost;

class RateSelector
{
    /**
     * @param  array<int, array<string, mixed>>  $rates  EasyPost Shipment rates[]
     * @return array<string, mixed>|null
     */
    public static function lowestUsps(array $rates): ?array
    {
        $usps = array_values(array_filter(
            $rates,
            fn (array $rate): bool => ($rate['carrier'] ?? null) === 'USPS' && isset($rate['rate']),
        ));

        if ($usps === []) {
            return null;
        }

        // usort is stable since PHP 8.0, so the first rate wins on ties.
        usort($usps, fn (array $a, array $b): int => (float) $a['rate'] <=> (float) $b['rate']);

        return $usps[0];
    }
}
