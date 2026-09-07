<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only starts a session for requests coming from a stateful frontend.
        $this->withHeader('Referer', config('app.url'));
    }
}
