<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Filter;
use App\Models\FilterLayer;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
    }

    public function test_owner_can_create_a_draft_with_tags_and_ordered_layers(): void
    {
        Sanctum::actingAs($this->user);
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $asset = $this->createAsset($this->user);
        $layers = [
            self::layerData(['type' => 'text', 'layer_order' => 3, 'properties' => ['text' => 'SUMMER', 'x' => 100]]),
            self::layerData(['type' => 'overlay', 'asset_id' => $asset->id, 'layer_order' => 2]),
            self::layerData(['type' => 'sticker', 'asset_id' => null, 'layer_order' => 1]),
            self::layerData(['layer_order' => 0, 'properties' => ['brightness' => 10, 'contrast' => 15]]),
        ];

        $response = $this->postJson('/api/filters', [
            'name' => 'Retro Summer',
            'description' => 'Warm retro summer preset',
            'category_id' => $this->category->id,
            'tags' => [$tag->id],
            'layers' => $layers,
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $this->user->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonCount(4, 'data.layers')
            ->assertJsonPath('data.layers.0.layer_order', 0)
            ->assertJsonPath('data.layers.0.properties.brightness', 10)
            ->assertJsonPath('data.layers.2.asset.id', $asset->id)
            ->assertJsonPath('data.layers.3.properties.text', 'SUMMER')
            ->assertJsonMissingPath('data.layers.2.asset.file_path')
            ->assertJsonMissingPath('data.tags.0.pivot');

        $filter = Filter::findOrFail($response->json('data.id'));
        $this->assertTrue($filter->user->is($this->user));
        $this->assertSame('draft', $filter->status);
        $this->assertNull($filter->published_at);
        $this->assertSame($layers[3]['properties'], $filter->layers->first()->properties);
        $this->assertDatabaseHas('filter_tag', ['filter_id' => $filter->id, 'tag_id' => $tag->id]);
        $this->assertDatabaseCount('filter_layers', 4);
    }

    public function test_filter_can_be_created_without_optional_fields(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/filters', [
            'name' => 'Minimal Filter',
            'category_id' => $this->category->id,
        ])->assertCreated()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.parent_filter_id', null)
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.layers', []);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guests_cannot_access_filter_endpoints(string $method, string $path): void
    {
        $filter = $this->createFilter();

        $this->json($method, str_replace('{id}', (string) $filter->id, $path))
            ->assertUnauthorized();
    }

    public static function protectedEndpoints(): array
    {
        return [
            'create' => ['POST', '/api/filters'],
            'list' => ['GET', '/api/filters'],
            'show' => ['GET', '/api/filters/{id}'],
            'put' => ['PUT', '/api/filters/{id}'],
            'patch' => ['PATCH', '/api/filters/{id}'],
            'publish' => ['POST', '/api/filters/{id}/publish'],
            'delete' => ['DELETE', '/api/filters/{id}'],
        ];
    }

    #[DataProvider('invalidFilterData')]
    public function test_create_validates_filter_input(array $overrides, array $errors): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/filters', array_merge([
            'name' => 'New Filter',
            'category_id' => $this->category->id,
        ], $overrides))->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('filters', 0);
        $this->assertDatabaseCount('filter_layers', 0);
        $this->assertDatabaseCount('filter_tag', 0);
    }

    #[DataProvider('invalidFilterData')]
    public function test_update_validates_input_without_changing_the_filter(array $overrides, array $errors): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $layer = $filter->layers()->create(self::layerData());

        $this->patchJson('/api/filters/'.$filter->id, $overrides)
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertSame('Original Filter', $filter->fresh()->name);
        $this->assertDatabaseHas('filter_layers', ['id' => $layer->id, 'filter_id' => $filter->id]);
    }

    public static function invalidFilterData(): array
    {
        return [
            'required name' => [['name' => null], ['name']],
            'name must be string' => [['name' => ['invalid']], ['name']],
            'name too long' => [['name' => str_repeat('x', 256)], ['name']],
            'description must be string' => [['description' => ['invalid']], ['description']],
            'required category' => [['category_id' => null], ['category_id']],
            'missing category' => [['category_id' => 999999], ['category_id']],
            'tags must be array' => [['tags' => 'invalid'], ['tags']],
            'missing tag' => [['tags' => [999999]], ['tags.0']],
            'layers must be array' => [['layers' => 'invalid'], ['layers']],
            'missing layer fields' => [['layers' => [[]]], ['layers.0.type', 'layers.0.layer_order', 'layers.0.properties']],
            'unsupported layer type' => [['layers' => [self::layerData(['type' => 'unknown'])]], ['layers.0.type']],
            'missing asset' => [['layers' => [self::layerData(['asset_id' => 999999])]], ['layers.0.asset_id']],
            'negative layer order' => [['layers' => [self::layerData(['layer_order' => -1])]], ['layers.0.layer_order']],
            'noninteger layer order' => [['layers' => [self::layerData(['layer_order' => 1.5])]], ['layers.0.layer_order']],
            'properties must be array' => [['layers' => [self::layerData(['properties' => 'invalid'])]], ['layers.0.properties']],
            'nested ownership injection' => [['layers' => [self::layerData(['filter_id' => 999999])]], ['layers.0']],
        ];
    }

    #[DataProvider('protectedFields')]
    public function test_clients_cannot_set_protected_filter_fields(string $field, mixed $value): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/filters', [
            'name' => 'New Filter',
            'category_id' => $this->category->id,
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('filters', 0);

        $filter = $this->createFilter();
        $this->patchJson('/api/filters/'.$filter->id, [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $filter->refresh();
        $this->assertSame($this->user->id, $filter->user_id);
        $this->assertNull($filter->parent_filter_id);
        $this->assertSame('draft', $filter->status);
        $this->assertNull($filter->published_at);
    }

    public static function protectedFields(): array
    {
        return [
            'owner' => ['user_id', 999999],
            'parent' => ['parent_filter_id', 999999],
            'status' => ['status', 'published'],
            'publication date' => ['published_at', '2026-01-01 00:00:00'],
        ];
    }

    public function test_other_users_assets_cannot_be_attached_on_create_or_update(): void
    {
        Sanctum::actingAs($this->user);
        $asset = $this->createAsset(User::factory()->create());
        $data = [
            'name' => 'New Filter',
            'category_id' => $this->category->id,
            'layers' => [self::layerData(['asset_id' => $asset->id])],
        ];

        $this->postJson('/api/filters', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('layers.0.asset_id');

        $filter = $this->createFilter();
        $this->patchJson('/api/filters/'.$filter->id, $data)
            ->assertUnprocessable()->assertJsonValidationErrors('layers.0.asset_id');

        $this->assertDatabaseCount('filter_layers', 0);
        $this->assertSame('Original Filter', $filter->fresh()->name);
    }

    public function test_list_contains_only_owned_filters_with_their_relationships(): void
    {
        Sanctum::actingAs($this->user);
        $first = $this->createFilter();
        $second = $this->createFilter(attributes: ['name' => 'Published', 'status' => 'published', 'published_at' => now()]);
        $other = $this->createFilter(User::factory()->create(), ['name' => 'Private Filter']);
        $asset = $this->createAsset($this->user);
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);

        foreach ([$first, $second] as $filter) {
            $filter->tags()->attach($tag);
            $filter->layers()->create(self::layerData(['asset_id' => $asset->id]));
        }

        $this->getJson('/api/filters')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.category.id', $this->category->id)
            ->assertJsonPath('data.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.0.layers.0.asset.id', $asset->id)
            ->assertJsonPath('data.1.layers.0.asset.id', $asset->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['name' => $other->name]);
    }

    public function test_owner_can_view_a_filter_with_its_relationships(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $asset = $this->createAsset($this->user);
        $filter->tags()->attach($tag);
        $filter->layers()->create(self::layerData(['asset_id' => $asset->id]));

        $this->getJson('/api/filters/'.$filter->id)->assertOk()
            ->assertJsonPath('data.id', $filter->id)
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.layers.0.asset.id', $asset->id)
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.layers.0.asset.file_path');
    }

    #[DataProvider('ownerOnlyEndpoints')]
    public function test_nonowners_receive_json_403_without_changing_the_filter(string $method, string $suffix, string $status): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter(User::factory()->create(), ['status' => $status]);

        $this->call($method, '/api/filters/'.$filter->id.$suffix, ['name' => 'Unauthorized change'])
            ->assertForbidden()->assertHeader('Content-Type', 'application/json');

        $filter->refresh();
        $this->assertSame('Original Filter', $filter->name);
        $this->assertSame($status, $filter->status);
    }

    public static function ownerOnlyEndpoints(): array
    {
        return [
            'view draft' => ['GET', '', 'draft'],
            'view published' => ['GET', '', 'published'],
            'put' => ['PUT', '', 'draft'],
            'patch' => ['PATCH', '', 'draft'],
            'publish' => ['POST', '/publish', 'draft'],
            'delete' => ['DELETE', '', 'draft'],
        ];
    }

    public function test_owner_can_update_fields_replace_layers_and_change_tags(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $oldLayer = $filter->layers()->create(self::layerData());
        $oldTag = Tag::create(['name' => 'Old', 'slug' => 'old']);
        $newTag = Tag::create(['name' => 'New', 'slug' => 'new']);
        $filter->tags()->attach($oldTag);
        $category = Category::create(['name' => 'Modern', 'slug' => 'modern']);
        $newLayers = [self::layerData(['type' => 'text', 'properties' => ['text' => 'Updated']])];

        $this->putJson('/api/filters/'.$filter->id, [
            'name' => 'Updated Filter',
            'description' => 'Updated description',
            'category_id' => $category->id,
            'tags' => [$newTag->id],
            'layers' => $newLayers,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Filter')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $newTag->id)
            ->assertJsonCount(1, 'data.layers')
            ->assertJsonPath('data.layers.0.properties.text', 'Updated');

        $this->assertDatabaseMissing('filter_layers', ['id' => $oldLayer->id]);
        $this->assertDatabaseMissing('filter_tag', ['filter_id' => $filter->id, 'tag_id' => $oldTag->id]);
        $this->assertDatabaseHas('filter_tag', ['filter_id' => $filter->id, 'tag_id' => $newTag->id]);
        $this->assertSame($newLayers[0]['properties'], $filter->layers()->first()->properties);
    }

    public function test_partial_update_preserves_omitted_relations_and_publication_state(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter(attributes: ['description' => 'Old description', 'status' => 'published', 'published_at' => now()]);
        $layer = $filter->layers()->create(self::layerData());
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $filter->tags()->attach($tag);

        $this->patchJson('/api/filters/'.$filter->id, ['description' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Original Filter')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.layers.0.id', $layer->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.status', 'published');

        $this->assertTrue($filter->published_at->equalTo($filter->fresh()->published_at));
    }

    public function test_explicit_empty_arrays_clear_layers_and_tags(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $filter->layers()->create(self::layerData());
        $filter->tags()->attach(Tag::create(['name' => 'Summer', 'slug' => 'summer']));

        $this->patchJson('/api/filters/'.$filter->id, ['layers' => [], 'tags' => []])
            ->assertOk()->assertJsonPath('data.layers', [])->assertJsonPath('data.tags', []);

        $this->assertDatabaseCount('filter_layers', 0);
        $this->assertDatabaseCount('filter_tag', 0);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_owner_can_publish_and_republishing_preserves_the_original_timestamp(): void
    {
        Sanctum::actingAs($this->user);
        $this->freezeSecond();
        $filter = $this->createFilter();

        $this->postJson('/api/filters/'.$filter->id.'/publish')->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', now()->toJSON());

        $filter->refresh();
        $publishedAt = $filter->published_at;
        $updatedAt = $filter->updated_at;
        $this->travel(1)->hours();

        $this->postJson('/api/filters/'.$filter->id.'/publish')->assertOk()
            ->assertJsonPath('data.published_at', $publishedAt->toJSON());

        $this->assertTrue($filter->fresh()->updated_at->equalTo($updatedAt));
    }

    public function test_delete_cascades_related_records_and_preserves_referenced_entities(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $asset = $this->createAsset($this->user);
        $filter->layers()->create(self::layerData(['asset_id' => $asset->id]));
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $filter->tags()->attach($tag);
        $filter->likes()->create(['user_id' => $this->user->id]);
        $collection = $this->user->collections()->create(['name' => 'Favorites']);
        $collection->filters()->attach($filter);

        $this->deleteJson('/api/filters/'.$filter->id)->assertOk()
            ->assertExactJson(['message' => 'Filter deleted successfully']);

        foreach (['filters', 'filter_layers', 'likes', 'filter_tag', 'collection_filter'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
        $this->assertDatabaseHas('collections', ['id' => $collection->id]);
        $this->assertDatabaseHas('categories', ['id' => $this->category->id]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_missing_filter_returns_json_404(): void
    {
        Sanctum::actingAs($this->user);

        $this->get('/api/filters/999999')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_failed_layer_creation_rolls_back_filter_and_tag_writes(): void
    {
        Sanctum::actingAs($this->user);
        $tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
        $this->failTextLayerWrites();

        try {
            $this->postJson('/api/filters', [
                'name' => 'New Filter',
                'category_id' => $this->category->id,
                'tags' => [$tag->id],
                'layers' => [self::layerData(), self::layerData(['type' => 'text'])],
            ])->assertInternalServerError();
        } finally {
            FilterLayer::flushEventListeners();
        }

        $this->assertDatabaseCount('filters', 0);
        $this->assertDatabaseCount('filter_layers', 0);
        $this->assertDatabaseCount('filter_tag', 0);
    }

    public function test_failed_layer_replacement_restores_filter_tags_and_original_layers(): void
    {
        Sanctum::actingAs($this->user);
        $filter = $this->createFilter();
        $layer = $filter->layers()->create(self::layerData());
        $oldTag = Tag::create(['name' => 'Old', 'slug' => 'old']);
        $newTag = Tag::create(['name' => 'New', 'slug' => 'new']);
        $filter->tags()->attach($oldTag);
        $this->failTextLayerWrites();

        try {
            $this->patchJson('/api/filters/'.$filter->id, [
                'name' => 'Updated Filter',
                'tags' => [$newTag->id],
                'layers' => [self::layerData(), self::layerData(['type' => 'text'])],
            ])->assertInternalServerError();
        } finally {
            FilterLayer::flushEventListeners();
        }

        $this->assertSame('Original Filter', $filter->fresh()->name);
        $this->assertDatabaseCount('filter_layers', 1);
        $this->assertDatabaseHas('filter_layers', ['id' => $layer->id]);
        $this->assertDatabaseHas('filter_tag', ['filter_id' => $filter->id, 'tag_id' => $oldTag->id]);
        $this->assertDatabaseMissing('filter_tag', ['filter_id' => $filter->id, 'tag_id' => $newTag->id]);
    }

    private function createFilter(?User $owner = null, array $attributes = []): Filter
    {
        return ($owner ?? $this->user)->filters()->create(array_merge([
            'name' => 'Original Filter',
            'category_id' => $this->category->id,
            'status' => 'draft',
        ], $attributes));
    }

    private function createAsset(User $owner): Asset
    {
        return $owner->assets()->create([
            'type' => 'image',
            'original_name' => 'sticker.png',
            'file_path' => 'assets/sticker.png',
            'mime_type' => 'image/png',
            'file_size' => 128,
        ]);
    }

    private static function layerData(array $overrides = []): array
    {
        return array_merge([
            'type' => 'adjustment',
            'layer_order' => 0,
            'properties' => ['brightness' => 10],
        ], $overrides);
    }

    private function failTextLayerWrites(): void
    {
        FilterLayer::creating(function (FilterLayer $layer): void {
            if ($layer->type === 'text') {
                throw new RuntimeException('Simulated layer write failure');
            }
        });
    }
}
