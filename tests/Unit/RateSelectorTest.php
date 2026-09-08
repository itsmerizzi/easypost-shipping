<?php

namespace Tests\Unit;

use App\Services\EasyPost\RateSelector;
use PHPUnit\Framework\TestCase;

class RateSelectorTest extends TestCase
{
    public function test_picks_the_cheapest_usps_rate_and_ignores_other_carriers(): void
    {
        $rates = [
            ['id' => 'rate_ups', 'carrier' => 'UPSDAP', 'service' => 'Ground', 'rate' => '6.10'],
            ['id' => 'rate_priority', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '9.83'],
            ['id' => 'rate_ground', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '7.33'],
            ['id' => 'rate_express', 'carrier' => 'USPS', 'service' => 'Express', 'rate' => '31.40'],
        ];

        $this->assertSame('rate_ground', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_compares_rates_numerically_not_as_strings(): void
    {
        $rates = [
            ['id' => 'rate_a', 'carrier' => 'USPS', 'service' => 'A', 'rate' => '10.00'],
            ['id' => 'rate_b', 'carrier' => 'USPS', 'service' => 'B', 'rate' => '9.50'],
        ];

        $this->assertSame('rate_b', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_first_rate_wins_on_ties(): void
    {
        $rates = [
            ['id' => 'rate_first', 'carrier' => 'USPS', 'service' => 'A', 'rate' => '7.33'],
            ['id' => 'rate_second', 'carrier' => 'USPS', 'service' => 'B', 'rate' => '7.33'],
        ];

        $this->assertSame('rate_first', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_returns_null_when_there_is_no_usps_rate(): void
    {
        $rates = [
            ['id' => 'rate_ups', 'carrier' => 'UPSDAP', 'service' => 'Ground', 'rate' => '6.10'],
        ];

        $this->assertNull(RateSelector::lowestUsps($rates));
    }

    public function test_returns_null_for_empty_list(): void
    {
        $this->assertNull(RateSelector::lowestUsps([]));
    }
}
