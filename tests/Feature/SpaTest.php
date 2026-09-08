<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->get('/')->assertOk()->assertSee('id="app"', false);
    }

    public function test_deep_links_serve_the_spa_shell(): void
    {
        $this->get('/labels/new')->assertOk()->assertSee('id="app"', false);
        $this->get('/labels/42')->assertOk()->assertSee('id="app"', false);
    }

    public function test_unknown_api_routes_are_json_404_not_the_shell(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }
}
