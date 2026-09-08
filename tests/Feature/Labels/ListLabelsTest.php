<?php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_own_labels_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $older = ShippingLabel::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = ShippingLabel::factory()->for($user)->create(['created_at' => now()]);
        ShippingLabel::factory()->for($other)->create();

        $response = $this->actingAs($user)->getJson('/api/labels');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.label_url');
    }

    public function test_history_is_paginated_by_fifteen(): void
    {
        $user = User::factory()->create();
        ShippingLabel::factory()->for($user)->count(16)->create();

        $this->actingAs($user)->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($user)->getJson('/api/labels?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_cannot_list_labels(): void
    {
        $this->getJson('/api/labels')->assertUnauthorized();
    }
}
