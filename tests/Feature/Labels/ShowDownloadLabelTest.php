<?php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowDownloadLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_label_details(): void
    {
        $user = User::factory()->create();
        $label = ShippingLabel::factory()->for($user)->create(['service' => 'Priority']);

        $this->actingAs($user)->getJson("/api/labels/{$label->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $label->id)
            ->assertJsonPath('data.service', 'Priority')
            ->assertJsonMissingPath('data.label_url')
            ->assertJsonMissingPath('data.label_file_path');
    }

    public function test_owner_can_download_the_pdf_inline(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $label = ShippingLabel::factory()->for($user)->create(['label_file_path' => "labels/{$user->id}/test.pdf"]);
        Storage::disk('local')->put($label->label_file_path, '%PDF-1.4 fake');

        $response = $this->actingAs($user)->get("/api/labels/{$label->id}/download");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("label-{$label->id}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake', $response->streamedContent());
    }

    public function test_download_is_404_when_the_stored_file_is_missing(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $label = ShippingLabel::factory()->for($user)->create(['label_file_path' => "labels/{$user->id}/missing.pdf"]);

        $this->actingAs($user)->getJson("/api/labels/{$label->id}/download")->assertNotFound();
    }

    public function test_another_user_gets_403(): void
    {
        Storage::fake('local');
        $label = ShippingLabel::factory()->create();
        Storage::disk('local')->put($label->label_file_path, '%PDF');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson("/api/labels/{$label->id}")->assertForbidden();
        $this->actingAs($intruder)->get("/api/labels/{$label->id}/download")->assertForbidden();
    }

    public function test_unknown_label_is_404(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/labels/999')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/labels/999/download')->assertNotFound();
    }

    public function test_guest_is_401(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->getJson("/api/labels/{$label->id}")->assertUnauthorized();
        $this->getJson("/api/labels/{$label->id}/download")->assertUnauthorized();
    }

    public function test_guest_browser_navigation_to_download_is_redirected_to_login(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->get("/api/labels/{$label->id}/download")->assertRedirect('/login');
    }

    public function test_guest_json_request_to_download_is_401(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->getJson("/api/labels/{$label->id}/download")->assertUnauthorized();
    }
}
