<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test: the app boots, routes load, and `/` sends people to the till.
     */
    public function test_the_root_redirects_to_the_till(): void
    {
        $this->get('/')->assertRedirect('/till');
    }
}
