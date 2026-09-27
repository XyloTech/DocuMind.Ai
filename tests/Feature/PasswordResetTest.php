<?php

namespace Tests\Feature;

use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_password_recovery_routes_are_unavailable(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'user@example.test'])->assertNotFound();
        $this->get('/reset-password/not-a-token')->assertNotFound();
        $this->post('/reset-password', [])->assertNotFound();
    }
}
