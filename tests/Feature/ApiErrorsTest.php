<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_api_route_is_a_json_404(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The requested resource was not found.', 'code' => 'not_found']);
    }

    public function test_a_wrong_http_method_is_a_json_405(): void
    {
        $this->signIn()->deleteJson('/api/requests/1')
            ->assertStatus(405)
            ->assertJson(['code' => 'method_not_allowed'])
            ->assertHeader('Allow');
    }

    public function test_errors_are_json_even_when_the_client_does_not_ask_for_json(): void
    {
        $response = $this->get('/api/requests');

        $response->assertStatus(401)->assertJson(['code' => 'unauthenticated']);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_every_error_has_a_message_and_a_machine_readable_code(): void
    {
        $user = User::factory()->employee()->create();

        $responses = [
            $this->getJson('/api/requests'),
            $this->signIn($user)->getJson('/api/requests/12345'),
            $this->postJson('/api/requests', []),
            $this->signIn(User::factory()->operations()->create())->postJson('/api/requests', []),
        ];

        $this->assertSame([401, 404, 422, 403], array_map(fn ($r) => $r->status(), $responses));
        foreach ($responses as $response) {
            $response->assertJsonStructure(['message', 'code']);
            $this->assertIsString($response->json('code'));
        }
    }

    public function test_the_api_rate_limit_returns_a_json_429_with_retry_after(): void
    {
        config(['service_requests.rate_limit.api' => 3]);
        $user = User::factory()->employee()->create();
        $this->signIn($user);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/requests')->assertOk();
        }

        $this->getJson('/api/requests')
            ->assertStatus(429)
            ->assertJson(['code' => 'too_many_requests'])
            ->assertHeader('Retry-After');
    }

    private function signIn(?User $user = null): static
    {
        Sanctum::actingAs($user ?? User::factory()->employee()->create());

        return $this;
    }
}
