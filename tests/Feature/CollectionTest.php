<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Filter;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Collection $collection;

    private Filter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->collection = $this->user->collections()->create(['name' => 'Favorites', 'description' => 'Saved filters']);
        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->filter = $this->otherUser->filters()->create([
            'name' => 'Retro Summer',
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_authenticated_user_can_create_a_collection(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/collections', [
            'name' => 'Summer', 'description' => 'Warm color presets',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Summer')
            ->assertJsonPath('data.description', 'Warm color presets')
            ->assertJsonPath('data.filters_count', 0)
            ->assertJsonMissingPath('data.user');

        $this->assertDatabaseHas('collections', [
            'id' => $response->json('data.id'), 'user_id' => $this->user->id, 'name' => 'Summer',
        ]);
    }

    public function test_description_is_optional_on_creation(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/collections', ['name' => 'Minimal'])->assertCreated()
            ->assertJsonPath('data.description', null)->assertJsonPath('data.filters_count', 0);
    }

    #[DataProvider('collectionEndpoints')]
    public function test_all_collection_endpoints_require_authentication(string $method, string $path): void
    {
        $this->call($method, $this->endpoint($path))->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('collections', 1);
        $this->assertDatabaseCount('collection_filter', 0);
    }

    public static function collectionEndpoints(): array
    {
        return [
            'list' => ['GET', '/api/collections'],
            'create' => ['POST', '/api/collections'],
            ...self::ownerEndpoints(),
        ];
    }

    public static function ownerEndpoints(): array
    {
        return [
            'show' => ['GET', '/api/collections/{collection}'],
            'put' => ['PUT', '/api/collections/{collection}'],
            'patch' => ['PATCH', '/api/collections/{collection}'],
            'delete' => ['DELETE', '/api/collections/{collection}'],
            'add filter' => ['POST', '/api/collections/{collection}/filters/{filter}'],
            'remove filter' => ['DELETE', '/api/collections/{collection}/filters/{filter}'],
        ];
    }

    #[DataProvider('invalidCollectionData')]
    public function test_create_and_update_validate_input(array $data, string $field): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/collections', array_merge(['name' => 'New collection'], $data))
            ->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertHeader('Content-Type', 'application/json');

        $this->patchJson($this->endpoint('/api/collections/{collection}'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('collections', 1);
        $this->assertSame('Favorites', $this->collection->fresh()->name);
        $this->assertSame('Saved filters', $this->collection->fresh()->description);
    }

    public static function invalidCollectionData(): array
    {
        return [
            'empty name' => [['name' => null], 'name'],
            'nonstring name' => [['name' => ['invalid']], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'nonstring description' => [['description' => ['invalid']], 'description'],
            'long description' => [['description' => str_repeat('x', 10001)], 'description'],
        ];
    }

    public function test_create_requires_a_name(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/collections', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('collections', 1);
    }

    public function test_client_cannot_assign_or_change_collection_ownership(): void
    {
        Sanctum::actingAs($this->user);
        $data = ['name' => 'Stolen', 'user_id' => $this->otherUser->id];

        $this->postJson('/api/collections', $data)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson($this->endpoint('/api/collections/{collection}'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('collections', 1);
        $this->assertSame($this->user->id, $this->collection->fresh()->user_id);
        $this->assertSame('Favorites', $this->collection->fresh()->name);
    }

    public function test_list_returns_only_owned_collections_and_correct_counts(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);
        $empty = $this->user->collections()->create(['name' => 'Empty']);
        $private = $this->otherUser->collections()->create(['name' => 'Private collection']);
        $private->filters()->attach($this->filter);

        $response = $this->getJson('/api/collections')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['name' => 'Private collection'])
            ->assertJsonMissingPath('data.0.filters');

        $counts = collect($response->json('data'))->pluck('filters_count', 'id');
        $this->assertSame(1, $counts[$this->collection->id]);
        $this->assertSame(0, $counts[$empty->id]);
    }

    public function test_owner_can_view_saved_filters_with_safe_metadata(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $this->filter->tags()->attach($tag);
        $this->filter->likes()->create(['user_id' => $this->user->id]);
        $asset = $this->otherUser->assets()->create([
            'type' => 'sticker', 'original_name' => 'sticker.png', 'file_path' => 'filter-assets/private.png',
            'mime_type' => 'image/png', 'file_size' => 128,
        ]);
        $this->filter->layers()->create([
            'type' => 'sticker', 'asset_id' => $asset->id, 'layer_order' => 0, 'properties' => ['opacity' => 1],
        ]);

        $this->getJson($this->endpoint('/api/collections/{collection}'))->assertOk()
            ->assertJsonPath('data.id', $this->collection->id)
            ->assertJsonPath('data.filters_count', 1)
            ->assertJsonPath('data.filters.0.id', $this->filter->id)
            ->assertJsonPath('data.filters.0.creator', ['id' => $this->otherUser->id, 'name' => $this->otherUser->name])
            ->assertJsonPath('data.filters.0.category.id', $this->filter->category_id)
            ->assertJsonPath('data.filters.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.filters.0.layers.0.asset.id', $asset->id)
            ->assertJsonPath('data.filters.0.likes_count', 1)
            ->assertJsonMissingPath('data.filters.0.creator.email')
            ->assertJsonMissingPath('data.filters.0.creator.password')
            ->assertJsonMissingPath('data.filters.0.layers.0.asset.file_path')
            ->assertJsonMissingPath('data.filters.0.pivot');
    }

    #[DataProvider('ownerEndpoints')]
    public function test_nonowners_receive_403_and_cannot_modify_collection_or_memberships(string $method, string $path): void
    {
        Sanctum::actingAs($this->otherUser);
        $this->collection->filters()->attach($this->filter);

        $this->call($method, $this->endpoint($path), ['name' => 'Unauthorized'])
            ->assertForbidden()->assertHeader('Content-Type', 'application/json');

        $this->assertSame('Favorites', $this->collection->fresh()->name);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $this->filter->id]);
    }

    public function test_owner_can_update_and_partially_update_a_collection(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);

        $this->putJson($this->endpoint('/api/collections/{collection}'), [
            'name' => 'Updated', 'description' => 'New description',
        ])->assertOk()->assertJsonPath('data.name', 'Updated')
            ->assertJsonPath('data.description', 'New description')->assertJsonPath('data.filters_count', 1);

        $this->patchJson($this->endpoint('/api/collections/{collection}'), ['description' => null])
            ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $this->filter->id]);
    }

    public function test_deleting_a_collection_cascades_only_its_pivots_and_preserves_filters(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);
        $otherCollection = $this->otherUser->collections()->create(['name' => 'Other']);
        $otherCollection->filters()->attach($this->filter);

        $this->deleteJson($this->endpoint('/api/collections/{collection}'))->assertOk()
            ->assertExactJson(['message' => 'Collection deleted successfully']);

        $this->assertDatabaseMissing('collections', ['id' => $this->collection->id]);
        $this->assertDatabaseMissing('collection_filter', ['collection_id' => $this->collection->id]);
        $this->assertDatabaseHas('filters', ['id' => $this->filter->id]);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $otherCollection->id, 'filter_id' => $this->filter->id]);
    }

    public function test_owner_can_save_a_published_filter_and_duplicate_saves_preserve_pivot_timestamp(): void
    {
        Sanctum::actingAs($this->user);
        $this->freezeSecond();
        $url = $this->endpoint('/api/collections/{collection}/filters/{filter}');

        $this->postJson($url)->assertOk()->assertExactJson(['saved' => true, 'filters_count' => 1]);
        $createdAt = DB::table('collection_filter')->value('created_at');
        $this->assertNotNull($createdAt);
        $this->travel(1)->minutes();
        $this->postJson($url)->assertOk()->assertExactJson(['saved' => true, 'filters_count' => 1]);

        $this->assertDatabaseCount('collection_filter', 1);
        $this->assertSame($createdAt, DB::table('collection_filter')->value('created_at'));
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $this->filter->id]);
    }

    #[DataProvider('unpublishedFilters')]
    public function test_unpublished_filters_cannot_be_saved(string $status, ?string $publishedAt): void
    {
        Sanctum::actingAs($this->user);
        $this->filter->update(['status' => $status, 'published_at' => $publishedAt]);

        $this->post($this->endpoint('/api/collections/{collection}/filters/{filter}'))
            ->assertNotFound()->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('collection_filter', 0);
    }

    public static function unpublishedFilters(): array
    {
        return [
            'draft' => ['draft', null],
            'draft with timestamp' => ['draft', '2026-10-13 15:00:00'],
            'published without timestamp' => ['published', null],
        ];
    }

    public function test_add_does_not_detach_existing_filters_or_modify_another_collection(): void
    {
        Sanctum::actingAs($this->user);
        $existingFilter = $this->filter->replicate();
        $existingFilter->save();
        $this->collection->filters()->attach($existingFilter);
        $otherCollection = $this->otherUser->collections()->create(['name' => 'Other']);

        $this->postJson($this->endpoint('/api/collections/{collection}/filters/{filter}'), [
            'collection_id' => $otherCollection->id, 'user_id' => $this->otherUser->id, 'filter_id' => $existingFilter->id,
        ])->assertOk()->assertExactJson(['saved' => true, 'filters_count' => 2]);

        $this->assertSame(0, $otherCollection->filters()->count());
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $existingFilter->id]);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $this->filter->id]);
    }

    public function test_remove_is_idempotent_and_affects_only_the_selected_membership(): void
    {
        Sanctum::actingAs($this->user);
        $remainingFilter = $this->filter->replicate();
        $remainingFilter->save();
        $this->collection->filters()->attach([$this->filter->id, $remainingFilter->id]);
        $otherCollection = $this->user->collections()->create(['name' => 'Second']);
        $otherCollection->filters()->attach($this->filter);
        $foreignCollection = $this->otherUser->collections()->create(['name' => 'Private']);
        $foreignCollection->filters()->attach($this->filter);

        for ($i = 0; $i < 2; $i++) {
            $this->deleteJson($this->endpoint('/api/collections/{collection}/filters/{filter}'))
                ->assertOk()->assertExactJson(['saved' => false, 'filters_count' => 1]);
        }

        $this->assertDatabaseMissing('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $this->filter->id]);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $this->collection->id, 'filter_id' => $remainingFilter->id]);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $otherCollection->id, 'filter_id' => $this->filter->id]);
        $this->assertDatabaseHas('collection_filter', ['collection_id' => $foreignCollection->id, 'filter_id' => $this->filter->id]);
        $this->assertDatabaseHas('filters', ['id' => $this->filter->id]);
    }

    public function test_removing_an_absent_filter_from_an_empty_collection_succeeds(): void
    {
        Sanctum::actingAs($this->user);

        $this->deleteJson($this->endpoint('/api/collections/{collection}/filters/{filter}'))->assertOk()
            ->assertExactJson(['saved' => false, 'filters_count' => 0]);
        $this->assertDatabaseCount('collection_filter', 0);
    }

    public function test_saved_filters_can_be_removed_after_becoming_unpublished(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);
        $this->filter->update(['status' => 'draft', 'published_at' => null]);

        $this->deleteJson($this->endpoint('/api/collections/{collection}/filters/{filter}'))->assertOk()
            ->assertExactJson(['saved' => false, 'filters_count' => 0]);
        $this->assertDatabaseCount('collection_filter', 0);
    }

    public function test_missing_collections_and_filters_return_json_404(): void
    {
        Sanctum::actingAs($this->user);

        $this->get('/api/collections/999999')->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->post('/api/collections/'.$this->collection->id.'/filters/999999')->assertNotFound();
        $this->delete('/api/collections/'.$this->collection->id.'/filters/999999')->assertNotFound();
    }

    public function test_collection_counts_do_not_add_queries_per_collection(): void
    {
        Sanctum::actingAs($this->user);
        $this->collection->filters()->attach($this->filter);
        DB::enableQueryLog();

        try {
            $this->getJson('/api/collections')->assertOk()->assertJsonPath('data.0.filters_count', 1);
            $initialQueryCount = count(DB::getQueryLog());

            for ($i = 0; $i < 4; $i++) {
                $this->user->collections()->create(['name' => 'Collection '.$i])->filters()->attach($this->filter);
            }

            DB::flushQueryLog();
            $response = $this->getJson('/api/collections')->assertOk()->assertJsonCount(5, 'data');
            $this->assertSame($initialQueryCount, count(DB::getQueryLog()));
            $this->assertSame([1, 1, 1, 1, 1], $response->json('data.*.filters_count'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function endpoint(string $path): string
    {
        return str_replace(['{collection}', '{filter}'], [$this->collection->id, $this->filter->id], $path);
    }
}
