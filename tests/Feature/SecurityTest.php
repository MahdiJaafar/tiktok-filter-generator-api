<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_throttles_normalized_email_and_ip_and_recovers_after_a_minute(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secure-password']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->post('/api/login', ['email' => 'USER@EXAMPLE.COM', 'password' => 'secure-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Too Many Requests']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->travel(61)->seconds();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secure-password'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_limits_are_scoped_to_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
        $this->postJson('/api/login', ['email' => 'other@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized();
    }

    public function test_rotating_emails_cannot_bypass_the_login_ip_cap(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/login', ['email' => 'user'.$attempt.'@example.com', 'password' => 'wrong-password'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'another@example.com', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_malformed_email_still_returns_validation_errors_before_reaching_login(): void
    {
        $this->postJson('/api/login', ['email' => ['bad'], 'password' => 'secure-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_is_limited_by_ip_across_different_emails(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/register', $this->registrationData('user'.$attempt.'@example.com'))->assertCreated();
        }
        $this->post('/api/register', $this->registrationData('sixth@example.com'))
            ->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Requests']);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('personal_access_tokens', 5);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/register', $this->registrationData('sixth@example.com'))->assertCreated();
    }

    public function test_upload_limit_applies_per_user_across_tokens_and_ips_without_writing_extra_files(): void
    {
        Storage::fake('assets');
        $user = User::factory()->create();
        $token = $user->createToken('first')->plainTextToken;
        $this->withToken($token);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/api/assets', [
                'type' => 'sticker', 'file' => UploadedFile::fake()->image('sticker.png', 10, 10),
            ])->assertCreated();
        }

        Auth::forgetGuards();
        $this->withToken($user->createToken('second')->plainTextToken)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post('/api/assets', [
                'type' => 'sticker', 'file' => UploadedFile::fake()->image('sticker.png', 10, 10),
            ])->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Requests']);
        $this->assertDatabaseCount('assets', 10);
        $this->assertCount(10, Storage::disk('assets')->allFiles());

        Auth::forgetGuards();
        $other = User::factory()->create();
        $this->withToken($other->createToken('other')->plainTextToken)
            ->post('/api/assets', [
                'type' => 'overlay', 'file' => UploadedFile::fake()->image('overlay.png', 10, 10),
            ])->assertCreated();
        $this->assertSame(1, $other->assets()->count());
    }

    public function test_registration_hashes_even_passwords_that_look_like_existing_hashes(): void
    {
        $password = Hash::make('a-different-password');
        $this->postJson('/api/register', [
            ...$this->registrationData(), 'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();
        $user = User::firstOrFail();
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('a-different-password', $user->password));
    }

    #[DataProvider('unsafeRegistrationPasswords')]
    public function test_registration_rejects_passwords_that_bcrypt_cannot_safely_store(string $password): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $this->postJson('/api/register', [
            ...$this->registrationData(), 'password' => $password, 'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public static function unsafeRegistrationPasswords(): array
    {
        return [
            'over 72 bytes' => [str_repeat('a', 73)],
            'unicode over 72 bytes' => [str_repeat('é', 37)],
            'null byte' => ["password\0suffix"],
        ];
    }

    public function test_a_72_byte_unicode_password_can_register_and_login(): void
    {
        $password = str_repeat('é', 36);
        $this->postJson('/api/register', [
            ...$this->registrationData(), 'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();
        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => $password])->assertOk();
    }

    public function test_api_server_errors_hide_internal_details_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/security-test-error', function (): never {
            throw new RuntimeException('Simulated sensitive SQL and filesystem details');
        });

        $this->get('/api/security-test-error')->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Internal Server Error']);
        $this->assertTrue(config('app.debug'));
    }

    #[DataProvider('errorStatusCodes')]
    public function test_api_http_errors_keep_status_and_headers_without_exception_details(int $status): void
    {
        config(['app.debug' => true]);
        Route::get('/api/security-test-error', fn () => abort($status, 'Internal implementation detail', ['X-Test' => 'kept']));

        $this->get('/api/security-test-error')->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')->assertHeader('X-Test', 'kept')
            ->assertExactJson(['message' => Response::$statusTexts[$status]]);
    }

    public static function errorStatusCodes(): array
    {
        return [[401], [403], [404], [405], [429]];
    }

    public function test_validation_errors_remain_useful_without_debug_fields(): void
    {
        config(['app.debug' => true]);
        $this->post('/api/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password'])
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace')->assertJsonMissingPath('file');
    }

    public function test_upload_failure_cleans_up_and_does_not_disclose_the_exception(): void
    {
        config(['app.debug' => true]);
        Storage::fake('assets');
        Sanctum::actingAs(User::factory()->create());
        Asset::created(fn () => throw new RuntimeException('Simulated private database details'));

        try {
            $this->post('/api/assets', [
                'type' => 'sticker', 'file' => UploadedFile::fake()->image('sticker.png', 10, 10),
            ])->assertInternalServerError()->assertExactJson(['message' => 'Internal Server Error']);
        } finally {
            Asset::flushEventListeners();
        }

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    #[DataProvider('unpublishedFilters')]
    public function test_saved_filters_stop_exposing_content_when_they_become_unpublished(string $status, ?string $publishedAt): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $collection = $owner->collections()->create(['name' => 'Saved']);
        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $filter = User::factory()->create()->filters()->create([
            'name' => 'Private content', 'category_id' => $category->id, 'status' => 'published', 'published_at' => now(),
        ]);
        $collection->filters()->attach($filter->id);
        $filter->update(['status' => $status, 'published_at' => $publishedAt]);

        $this->getJson('/api/collections/'.$collection->id)->assertOk()->assertJsonPath('data.filters', []);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $collection->id, 'filter_id' => $filter->id]);
        $this->deleteJson('/api/collections/'.$collection->id.'/filters/'.$filter->id)->assertOk();
    }

    public static function unpublishedFilters(): array
    {
        return [['draft', null], ['draft', '2026-01-01 12:00:00'], ['published', null]];
    }

    private function registrationData(string $email = 'user@example.com'): array
    {
        return ['name' => 'Test User', 'email' => $email, 'password' => 'secure-password', 'password_confirmation' => 'secure-password'];
    }
}
