<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ApiCorsTest extends TestCase
{
    public function test_preflight_allows_only_the_configured_frontend_origin(): void
    {
        config(['cors.allowed_origins' => [
            'https://frontend.example.test',
            'https://preview.example.test',
        ]]);

        $this->call('OPTIONS', '/api/v1/books', [], [], [], [
            'HTTP_ORIGIN' => 'https://frontend.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example.test');

        $response = $this->call('OPTIONS', '/api/v1/books', [], [], [], [
            'HTTP_ORIGIN' => 'https://untrusted.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
