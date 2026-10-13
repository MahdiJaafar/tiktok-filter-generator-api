<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Filter;
use App\Models\Like;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LikeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $creator;

    private Filter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->creator = User::factory()->create();
        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->filter = $this->creator->filters()->create([
            'name' => 'Retro Summer',
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_authenticated_user_can_like_another_users_published_filter_without_a_body(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->post('/api/filters/'.$this->filter->id.'/like')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['liked' => true, 'likes_count' => 1]);

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['user_id' => $this->user->id, 'filter_id' => $this->filter->id]);
        $this->assertTrue(Like::firstOrFail()->user->is($this->user));
    }

    public function test_repeated_like_requests_are_idempotent(): void
    {
        Sanctum::actingAs($this->user);
        $this->freezeSecond();

        $this->postJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => true, 'likes_count' => 1]);
        $like = Like::firstOrFail();
        $this->travel(1)->minutes();

        $this->postJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => true, 'likes_count' => 1]);

        $this->assertDatabaseCount('likes', 1);
        $this->assertSame($like->id, Like::firstOrFail()->id);
        $this->assertTrue($like->updated_at->equalTo($like->fresh()->updated_at));
    }

    public function test_multiple_users_can_like_the_same_filter_and_unlike_independently(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => true, 'likes_count' => 1]);

        Sanctum::actingAs($this->creator);
        $this->postJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => true, 'likes_count' => 2]);
        $this->assertDatabaseCount('likes', 2);

        Sanctum::actingAs($this->user);
        $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => false, 'likes_count' => 1]);

        $this->assertDatabaseMissing('likes', ['filter_id' => $this->filter->id, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('likes', ['filter_id' => $this->filter->id, 'user_id' => $this->creator->id]);

        Sanctum::actingAs($this->creator);
        $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => false, 'likes_count' => 0]);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_unlike_only_removes_current_users_like_on_the_target_filter(): void
    {
        Sanctum::actingAs($this->user);
        $otherFilter = $this->filter->replicate();
        $otherFilter->save();
        $this->filter->likes()->create(['user_id' => $this->user->id]);
        $this->filter->likes()->create(['user_id' => $this->creator->id]);
        $otherFilter->likes()->create(['user_id' => $this->user->id]);

        $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => false, 'likes_count' => 1]);

        $this->assertDatabaseCount('likes', 2);
        $this->assertDatabaseMissing('likes', ['filter_id' => $this->filter->id, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('likes', ['filter_id' => $this->filter->id, 'user_id' => $this->creator->id]);
        $this->assertDatabaseHas('likes', ['filter_id' => $otherFilter->id, 'user_id' => $this->user->id]);
    }

    public function test_unlike_without_a_like_is_idempotent_and_preserves_other_users_likes(): void
    {
        Sanctum::actingAs($this->user);
        $this->filter->likes()->create(['user_id' => $this->creator->id]);

        for ($i = 0; $i < 2; $i++) {
            $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
                ->assertExactJson(['liked' => false, 'likes_count' => 1]);
        }

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['filter_id' => $this->filter->id, 'user_id' => $this->creator->id]);
    }

    public function test_unlike_when_nobody_has_liked_the_filter_returns_zero(): void
    {
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk()
            ->assertExactJson(['liked' => false, 'likes_count' => 0]);

        $this->assertDatabaseCount('likes', 0);
    }

    #[DataProvider('likeMethods')]
    public function test_guests_cannot_like_or_unlike(string $method): void
    {
        $this->filter->likes()->create(['user_id' => $this->user->id]);

        $this->call($method, '/api/filters/'.$this->filter->id.'/like')->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('likes', 1);
    }

    #[DataProvider('likeMethods')]
    public function test_missing_filters_return_json_404(string $method): void
    {
        Sanctum::actingAs($this->user);

        $this->call($method, '/api/filters/999999/like')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('likes', 0);
    }

    public static function likeMethods(): array
    {
        return ['like' => ['POST'], 'unlike' => ['DELETE']];
    }

    #[DataProvider('unpublishedFilters')]
    public function test_unpublished_filters_cannot_be_liked_or_unliked(string $status, ?string $publishedAt): void
    {
        Sanctum::actingAs($this->user);
        $this->filter->update(['status' => $status, 'published_at' => $publishedAt]);

        $this->post('/api/filters/'.$this->filter->id.'/like')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
        $this->assertDatabaseCount('likes', 0);

        $like = $this->filter->likes()->create(['user_id' => $this->user->id]);
        $this->delete('/api/filters/'.$this->filter->id.'/like')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
        $this->assertDatabaseHas('likes', ['id' => $like->id]);
    }

    public static function unpublishedFilters(): array
    {
        return [
            'draft without timestamp' => ['draft', null],
            'draft with timestamp' => ['draft', '2026-10-13 15:00:00'],
            'published without timestamp' => ['published', null],
        ];
    }

    public function test_client_supplied_ids_cannot_override_the_authenticated_user_or_route_filter(): void
    {
        Sanctum::actingAs($this->user);
        $otherFilter = $this->filter->replicate();
        $otherFilter->save();
        $this->filter->likes()->create(['user_id' => $this->creator->id]);

        $spoofedIds = ['user_id' => $this->creator->id, 'filter_id' => $otherFilter->id];
        $this->postJson('/api/filters/'.$this->filter->id.'/like', $spoofedIds)->assertOk()
            ->assertExactJson(['liked' => true, 'likes_count' => 2]);

        $this->assertDatabaseHas('likes', ['user_id' => $this->user->id, 'filter_id' => $this->filter->id]);
        $this->assertSame(0, $otherFilter->likes()->count());

        $this->deleteJson('/api/filters/'.$this->filter->id.'/like', $spoofedIds)->assertOk()
            ->assertExactJson(['liked' => false, 'likes_count' => 1]);
        $this->assertDatabaseHas('likes', ['user_id' => $this->creator->id, 'filter_id' => $this->filter->id]);
        $this->assertDatabaseMissing('likes', ['user_id' => $this->user->id, 'filter_id' => $this->filter->id]);
    }

    public function test_public_gallery_list_and_details_include_correct_like_counts(): void
    {
        $this->filter->likes()->create(['user_id' => $this->user->id]);
        $this->filter->likes()->create(['user_id' => $this->creator->id]);
        $unlikedFilter = $this->filter->replicate();
        $unlikedFilter->save();

        $response = $this->getJson('/api/gallery')->assertOk()->assertJsonCount(2, 'data');
        $counts = collect($response->json('data'))->pluck('likes_count', 'id');
        $this->assertSame(2, $counts[$this->filter->id]);
        $this->assertSame(0, $counts[$unlikedFilter->id]);

        $this->getJson('/api/gallery/'.$this->filter->id)->assertOk()->assertJsonPath('data.likes_count', 2);
        $this->getJson('/api/gallery/'.$unlikedFilter->id)->assertOk()->assertJsonPath('data.likes_count', 0);

        Sanctum::actingAs($this->user);
        $this->deleteJson('/api/filters/'.$this->filter->id.'/like')->assertOk();
        $this->getJson('/api/gallery/'.$this->filter->id)->assertOk()->assertJsonPath('data.likes_count', 1);
    }
}
