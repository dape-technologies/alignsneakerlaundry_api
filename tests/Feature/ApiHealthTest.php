<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_api_health_endpoint_is_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'message' => 'Go Fresh Shoe Laundry API is connected.',
            ])
            ->assertJsonStructure(['timestamp']);
    }
}
