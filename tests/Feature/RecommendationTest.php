<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Filter;
use App\Models\Tag;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $creator;

    private Category $category;

    private Category $otherCategory;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.llm' => ['provider' => 'anthropic', 'api_key' => null, 'model' => 'test-model', 'base_url' => null]]);
        $this->user = User::factory()->create();
        $this->creator = User::factory()->create();
        $this->category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->otherCategory = Category::create(['name' => 'Modern', 'slug' => 'modern']);
        $this->tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
    }

    public function test_guests_receive_json_401_without_calling_a_provider(): void
    {
        $this->get('/api/recommendations')->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized']);
        Http::assertNothingSent();
    }

    public function test_liked_categories_rank_a_matching_filter_ahead_of_a_newer_unrelated_one(): void
    {
        $this->likeHistory();
        $match = $this->filter(['published_at' => now()->subDays(10)]);
        $this->filter(['category_id' => $this->otherCategory->id]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()
            ->assertJsonPath('meta.source', 'fallback')->assertJsonPath('data.0.id', $match->id);
        Http::assertNothingSent();
    }

    public function test_liked_tags_rank_matching_filters_within_a_category(): void
    {
        $history = $this->likeHistory();
        $history->tags()->attach($this->tag);
        $match = $this->filter(['published_at' => now()->subDays(10)]);
        $match->tags()->attach($this->tag);
        $this->filter();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('data.0.id', $match->id);
    }

    public function test_saved_filters_influence_preferences_without_likes(): void
    {
        $history = $this->filter();
        $history->tags()->attach($this->tag);
        $this->user->collections()->create(['name' => 'Favorites'])->filters()->attach($history);
        $match = $this->filter(['published_at' => now()->subDays(10)]);
        $match->tags()->attach($this->tag);
        $this->filter(['category_id' => $this->otherCategory->id]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('data.0.id', $match->id);
    }

    public function test_other_users_history_does_not_influence_preferences(): void
    {
        $old = $this->filter(['published_at' => now()->subDays(5)]);
        $this->creator->collections()->create(['name' => 'Private'])->filters()->attach($old);
        $new = $this->filter(['category_id' => $this->otherCategory->id]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('data.0.id', $new->id);
    }

    public function test_new_user_gets_popularity_then_recency_then_id_without_an_llm_call(): void
    {
        $this->enableAi();
        $popular = $this->filter(['published_at' => now()->subDays(30)]);
        $popular->likes()->create(['user_id' => $this->creator->id]);
        $time = now()->startOfSecond();
        $first = $this->filter(['published_at' => $time]);
        $second = $this->filter(['published_at' => $time]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'fallback')
            ->assertJsonPath('data.0.id', $popular->id)->assertJsonPath('data.0.likes_count', 1)
            ->assertJsonPath('data.1.id', $second->id)->assertJsonPath('data.2.id', $first->id);
        Http::assertNothingSent();
    }

    public function test_fresh_alternatives_exclude_owned_and_previously_liked_or_saved_filters(): void
    {
        $history = $this->likeHistory();
        $saved = $this->filter();
        foreach (['One', 'Two'] as $name) {
            $this->user->collections()->create(['name' => $name])->filters()->attach([$history->id, $saved->id]);
        }
        $owned = $this->filter(['user_id' => $this->user->id]);
        $fresh = [];
        for ($i = 0; $i < 6; $i++) {
            $fresh[] = $this->filter()->id;
        }
        Sanctum::actingAs($this->user);

        $ids = $this->getJson('/api/recommendations')->assertOk()->assertJsonCount(5, 'data')->json('data.*.id');
        $this->assertCount(5, array_unique($ids));
        $this->assertSame([], array_diff($ids, $fresh));
        $this->assertNotContains($history->id, $ids);
        $this->assertNotContains($saved->id, $ids);
        $this->assertNotContains($owned->id, $ids);
    }

    public function test_sparse_catalog_fills_from_history_then_owned_filters_without_duplicates(): void
    {
        $history = $this->likeHistory();
        $this->user->collections()->create(['name' => 'Saved'])->filters()->attach($history);
        $owned = $this->filter(['user_id' => $this->user->id]);
        $fresh = $this->filter();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $fresh->id)->assertJsonPath('data.1.id', $history->id)
            ->assertJsonPath('data.2.id', $owned->id);
    }

    public function test_drafts_and_null_publication_dates_are_never_candidates_or_history_in_the_prompt(): void
    {
        $this->enableAi();
        $this->likeHistory();
        $draft = $this->filter(['name' => 'PRIVATE DRAFT', 'status' => 'draft']);
        $undated = $this->filter(['name' => 'PRIVATE UNDATED', 'published_at' => null]);
        $draft->likes()->create(['user_id' => $this->user->id]);
        $this->user->collections()->create(['name' => 'Saved'])->filters()->attach($undated);
        $valid = $this->filter();
        Http::fake(['api.anthropic.com/*' => $this->aiResponse([$draft->id, $undated->id, $valid->id])]);
        Sanctum::actingAs($this->user);

        $ids = $this->getJson('/api/recommendations')->assertOk()->json('data.*.id');
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($undated->id, $ids);
        Http::assertSent(fn (Request $request) => ! str_contains($request->body(), 'PRIVATE'));
    }

    public function test_successful_ai_ranking_uses_valid_ids_ignores_bad_ids_and_fills_missing_slots(): void
    {
        $this->enableAi();
        $history = $this->likeHistory();
        $history->tags()->attach($this->tag);
        $candidates = [];
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = $this->filter();
        }
        Http::fake(['api.anthropic.com/*' => $this->aiResponse([
            999999, $history->id, $candidates[0]->id, $candidates[0]->id,
            (string) $candidates[1]->id, -1, true, ['id' => $candidates[1]->id], $candidates[2]->id,
        ])]);
        $token = $this->user->createToken('recommendations-test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/recommendations')->assertOk()
            ->assertJsonPath('meta.source', 'ai')->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $candidates[0]->id)->assertJsonPath('data.1.id', $candidates[2]->id)
            ->assertJsonPath('data.0.creator', ['id' => $this->creator->id, 'name' => $this->creator->name])
            ->assertJsonMissingPath('data.0.creator.email')->assertJsonMissingPath('data.0.matching_tags_count');
        $ids = $response->json('data.*.id');
        $this->assertCount(5, array_unique($ids));
        $this->assertNotContains($history->id, $ids);
        $this->assertNotContains(999999, $ids);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($token, $history): bool {
            $context = json_decode($request['messages'][0]['content'], true);
            $this->assertContains($this->category->name, $context['preferences']['preferred_categories']);
            $this->assertContains($this->tag->name, $context['preferences']['preferred_tags']);
            $this->assertContains($history->name, $context['preferences']['liked_or_saved_filter_names']);
            foreach ([$this->user->email, $this->user->password, $this->creator->email, $token] as $private) {
                $this->assertStringNotContainsString($private, $request->body());
            }
            foreach ($context['candidates'] as $candidate) {
                $this->assertSame(['id', 'name', 'category', 'tags'], array_keys($candidate));
            }

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'fake-test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    }

    public function test_candidate_pool_is_bounded_and_published_ids_outside_it_are_rejected(): void
    {
        $this->enableAi();
        $this->likeHistory();
        $outside = $this->filter(['published_at' => now()->subYears(10)]);
        for ($i = 0; $i < 51; $i++) {
            $this->filter();
        }
        Http::fake(['api.anthropic.com/*' => $this->aiResponse([$outside->id])]);
        Sanctum::actingAs($this->user);

        $ids = $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'fallback')
            ->assertJsonCount(5, 'data')->json('data.*.id');
        $this->assertNotContains($outside->id, $ids);
        Http::assertSent(fn (Request $request) => count(json_decode($request['messages'][0]['content'], true)['candidates']) === 50);
    }

    public function test_mistral_adapter_uses_bearer_auth_and_chat_completion_json(): void
    {
        $this->enableAi();
        config(['services.llm.provider' => 'mistral']);
        $this->likeHistory();
        $candidate = $this->filter();
        Http::fake(['api.mistral.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['filter_ids' => [$candidate->id]])]]],
        ])]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'ai')
            ->assertJsonPath('data.0.id', $candidate->id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.mistral.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer fake-test-key')
            && $request['response_format'] === ['type' => 'json_object']);
    }

    #[DataProvider('badProviderResponses')]
    public function test_provider_failures_and_bad_output_use_the_same_deterministic_fallback(mixed $body, int $status): void
    {
        $this->likeHistory();
        for ($i = 0; $i < 6; $i++) {
            $this->filter();
        }
        Sanctum::actingAs($this->user);
        $expected = $this->getJson('/api/recommendations')->assertOk()->json('data.*.id');
        $this->enableAi();
        Http::fake(['api.anthropic.com/*' => Http::response($body, $status)]);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'fallback')
            ->assertJsonPath('data.*.id', $expected);
    }

    public static function badProviderResponses(): array
    {
        $envelope = fn ($text) => ['content' => [['type' => 'text', 'text' => $text]]];

        return [
            'HTTP error' => [['error' => 'private provider error'], 500],
            'provider rate limit' => [['error' => 'private provider error'], 429],
            'redirect' => ['', 302],
            'invalid envelope' => ['not JSON', 200],
            'nontext block' => [['content' => [['type' => 'tool_use']]], 200],
            'malformed JSON' => [$envelope('{bad json'), 200],
            'markdown wrapper' => [$envelope('```json {"filter_ids":[1]} ```'), 200],
            'missing key' => [$envelope('{"other":[]}'), 200],
            'wrong type' => [$envelope('{"filter_ids":"1"}'), 200],
            'object rather than list' => [$envelope('{"filter_ids":{"id":1}}'), 200],
            'empty list' => [$envelope('{"filter_ids":[]}'), 200],
            'invalid IDs' => [$envelope('{"filter_ids":[999999,-1,"1",true,null]}'), 200],
            'oversized list' => [$envelope(json_encode(['filter_ids' => range(1, 51)])), 200],
        ];
    }

    public function test_timeout_uses_fallback_without_exposing_provider_errors(): void
    {
        $this->enableAi();
        $this->likeHistory();
        $this->filter();
        Http::fake(['api.anthropic.com/*' => Http::failedConnection('Simulated timeout with private details')]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta', ['source' => 'fallback'])
            ->assertDontSee('private details')->assertDontSee('fake-test-key');
    }

    #[DataProvider('disabledConfiguration')]
    public function test_incomplete_or_unsafe_provider_configuration_makes_no_http_call(array $config): void
    {
        $this->enableAi();
        config($config);
        $this->likeHistory();
        $this->filter();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'fallback');
        Http::assertNothingSent();
    }

    public static function disabledConfiguration(): array
    {
        return [
            [['services.llm.api_key' => '']],
            [['services.llm.model' => '']],
            [['services.llm.provider' => 'unsupported']],
            [['services.llm.base_url' => 'http://example.test/v1']],
            [['services.llm.base_url' => 'https://user:password@example.test/v1']],
        ];
    }

    public function test_publication_is_rechecked_after_the_provider_call(): void
    {
        $this->enableAi();
        $this->likeHistory();
        $candidate = $this->filter();
        $replacement = $this->filter();
        Http::fake(function () use ($candidate) {
            $candidate->update(['status' => 'draft']);

            return $this->aiResponse([$candidate->id]);
        });
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('meta.source', 'fallback');
        $this->assertNotContains($candidate->id, $response->json('data.*.id'));
        $this->assertContains($replacement->id, $response->json('data.*.id'));
    }

    public function test_ai_cannot_put_history_ahead_of_unseen_filters_in_a_sparse_catalog(): void
    {
        $this->enableAi();
        $history = $this->likeHistory();
        $fresh = $this->filter();
        Http::fake(['api.anthropic.com/*' => $this->aiResponse([$history->id, $fresh->id])]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('data.0.id', $fresh->id);
    }

    public function test_empty_catalog_returns_an_empty_fallback_list(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/recommendations')->assertOk()->assertExactJson(['data' => [], 'meta' => ['source' => 'fallback']]);
        Http::assertNothingSent();
    }

    public function test_recommendations_are_limited_per_user(): void
    {
        Sanctum::actingAs($this->user);
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/recommendations')->assertOk();
        }
        $this->getJson('/api/recommendations')->assertTooManyRequests()->assertHeader('Retry-After');
        Sanctum::actingAs($this->creator);
        $this->getJson('/api/recommendations')->assertOk();
    }

    public function test_eager_loading_does_not_add_queries_for_each_recommendation(): void
    {
        Sanctum::actingAs($this->user);
        $this->likeHistory();
        $this->filter();
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/recommendations')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $small = $countQueries();
        for ($i = 0; $i < 10; $i++) {
            $this->filter();
        }
        $large = $countQueries();
        $this->assertLessThanOrEqual($small, $large);
    }

    private function filter(array $attributes = []): Filter
    {
        return Filter::create([
            'user_id' => $this->creator->id,
            'name' => 'Retro Filter', 'category_id' => $this->category->id,
            'status' => 'published', 'published_at' => now(), ...$attributes,
        ]);
    }

    private function likeHistory(): Filter
    {
        $filter = $this->filter(['name' => 'Liked Retro Summer']);
        $filter->likes()->create(['user_id' => $this->user->id]);

        return $filter;
    }

    private function enableAi(): void
    {
        config(['services.llm.api_key' => 'fake-test-key']);
    }

    private function aiResponse(array $ids): PromiseInterface
    {
        return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['filter_ids' => $ids])]]]);
    }
}
