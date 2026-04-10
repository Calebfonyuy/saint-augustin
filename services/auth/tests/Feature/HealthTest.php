<?php

test('health endpoint returns ok', function () {
    $response = $this->getJson('/health');

    $response->assertOk();
});

test('auth status endpoint returns service info', function () {
    $response = $this->getJson('/api/auth/status');

    $response
        ->assertOk()
        ->assertJson([
            'service' => 'auth-service',
            'status'  => 'ok',
        ]);
});
