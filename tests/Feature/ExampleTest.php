<?php

namespace Tests\Feature;

use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
