<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_working_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $user = User::where('email', 'user@example.com')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath('message', 'User registered successfully')
            ->assertJsonPath('user', $user->only(['id', 'name', 'email']))
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertTrue(PersonalAccessToken::findToken($response->json('token'))->tokenable->is($user));
        $this->assertNotSame($response->json('token'), $user->tokens()->first()->token);

        $this->withToken($response->json('token'))->getJson('/api/user')
            ->assertOk()
            ->assertExactJson($user->only(['id', 'name', 'email']));
    }

    #[DataProvider('invalidRegistrationData')]
    public function test_registration_rejects_invalid_input(array $overrides, array $errors): void
    {
        $this->postJson('/api/register', array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ], $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidRegistrationData(): array
    {
        return [
            'required fields' => [['name' => null, 'email' => null, 'password' => null], ['name', 'email', 'password']],
            'name must be a string' => [['name' => ['invalid']], ['name']],
            'name too long' => [['name' => str_repeat('a', 256)], ['name']],
            'invalid email' => [['email' => 'invalid-email'], ['email']],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'password must be a string' => [['password' => ['invalid']], ['password']],
            'missing confirmation' => [['password_confirmation' => null], ['password']],
            'confirmation mismatch' => [['password_confirmation' => 'different-password'], ['password']],
        ];
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Another User',
            'email' => 'user@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_login_and_receive_a_working_token(): void
    {
        $user = User::factory()->create(['password' => 'secure-password']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secure-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('user', $user->only(['id', 'name', 'email']))
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($response->json('token'))->getJson('/api/user')
            ->assertOk()
            ->assertExactJson($user->only(['id', 'name', 'email']));
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_return_401(string $email, string $password): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'secure-password',
        ]);

        $this->postJson('/api/login', compact('email', 'password'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidCredentials(): array
    {
        return [
            'incorrect password' => ['user@example.com', 'wrong-password'],
            'unknown email' => ['unknown@example.com', 'secure-password'],
        ];
    }

    #[DataProvider('invalidLoginData')]
    public function test_login_validates_input(array $data, array $errors): void
    {
        $this->postJson('/api/login', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidLoginData(): array
    {
        return [
            'missing credentials' => [[], ['email', 'password']],
            'invalid email' => [['email' => 'invalid-email', 'password' => 'secure-password'], ['email']],
            'invalid password type' => [['email' => 'user@example.com', 'password' => ['invalid']], ['password']],
        ];
    }

    public function test_guest_cannot_access_current_user(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_can_access_current_user_with_a_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertExactJson($user->only(['id', 'name', 'email']));
    }

    public function test_invalid_bearer_token_is_rejected(): void
    {
        $this->withToken('invalid-token')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_logout_revokes_only_current_token_and_prevents_its_reuse(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device');

        $this->withToken($currentToken->plainTextToken)->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out successfully']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        // Resolve a fresh guard for each request, as separate API requests would.
        Auth::forgetGuards();
        $this->withToken($currentToken->plainTextToken)->getJson('/api/user')->assertUnauthorized();

        Auth::forgetGuards();
        $this->withToken($otherToken->plainTextToken)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_api_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/user')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->post('/api/register', [])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }
}
