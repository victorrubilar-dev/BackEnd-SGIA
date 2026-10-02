<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_preflight_request_allowed_for_web_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, Accept, X-Requested-With',
        ])->options('/api/login');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_request_allowed_for_expo_mobile_web_dev_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:8081',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/user');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8081');
    }

    public function test_preflight_request_rejected_for_unauthorized_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://unauthorized-domain.com',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/login');

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_actual_request_includes_cors_header_for_allowed_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ])->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'secret',
            'device_name' => 'web',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
