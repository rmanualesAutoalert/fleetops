<?php

namespace Tests\Unit;

use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_that_true_is_true()
    {
        $this->assertTrue(true);
    }
}
