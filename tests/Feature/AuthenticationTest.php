<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_bearer_token_and_the_user(): void
    {
        $user = User::factory()->manager()->create(['email' => 'maya@example.com']);

        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'password', 'device_name' => 'postman'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'role', 'manager_id']]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'manager')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_the_issued_token_authenticates_later_calls(): void
    {
        User::factory()->create(['email' => 'eli@example.com']);
        $token = $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'password'])->json('data.token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'eli@example.com');
    }

    public function test_wrong_password_and_unknown_email_get_the_same_401(): void
    {
        User::factory()->create(['email' => 'eli@example.com']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'password']);

        $wrongPassword->assertStatus(401)->assertJson(['code' => 'invalid_credentials']);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json(), 'must not reveal which e-mails exist');
    }

    public function test_login_validates_its_input(): void
    {
        $this->postJson('/api/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_repeated_login_attempts_are_rate_limited(): void
    {
        config(['service_requests.rate_limit.login' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'wrong'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJson(['code' => 'too_many_requests'])
            ->assertHeader('Retry-After');
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedEndpoints(): iterable
    {
        yield 'me' => ['GET', '/api/me'];
        yield 'list' => ['GET', '/api/requests'];
        yield 'create' => ['POST', '/api/requests'];
        yield 'show' => ['GET', '/api/requests/1'];
        yield 'update' => ['PUT', '/api/requests/1'];
        yield 'submit' => ['POST', '/api/requests/1/submit'];
        yield 'decision' => ['POST', '/api/requests/1/decision'];
        yield 'assign' => ['POST', '/api/requests/1/assign'];
        yield 'status' => ['POST', '/api/requests/1/status'];
        yield 'comment' => ['POST', '/api/requests/1/comments'];
        yield 'activities' => ['GET', '/api/requests/1/activities'];
        yield 'notifications' => ['GET', '/api/notifications'];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_every_endpoint_except_login_requires_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withToken('1|not-a-real-token')->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'eli@example.com']);
        $token = $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'password'])->json('data.token');

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        $this->app['auth']->forgetGuards(); // drop the user cached on the guard from the previous call
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_tokens_expire(): void
    {
        config(['sanctum.expiration' => 60]);
        User::factory()->create(['email' => 'eli@example.com']);
        $token = $this->postJson('/api/login', ['email' => 'eli@example.com', 'password' => 'password'])->json('data.token');

        $this->travel(61)->minutes();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }
}
