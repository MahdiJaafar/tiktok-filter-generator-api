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

class RemixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $creator;

    private Filter $source;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->creator = User::factory()->create();
        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->source = $this->creator->filters()->create([
            'name' => 'Retro Summer',
            'description' => 'Warm summer colors',
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $this->source->tags()->attach([
            Tag::create(['name' => 'Summer', 'slug' => 'summer'])->id,
            Tag::create(['name' => 'Warm', 'slug' => 'warm'])->id,
        ]);
        $this->asset = $this->creator->assets()->create([
            'type' => 'sticker',
            'original_name' => 'sun.png',
            'file_path' => 'filter-assets/'.$this->creator->id.'/sun.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'width' => 100,
            'height' => 100,
        ]);
        $this->source->layers()->createMany([
            ['type' => 'text', 'layer_order' => 7, 'properties' => ['text' => 'SUMMER']],
            ['type' => 'sticker', 'asset_id' => $this->asset->id, 'layer_order' => 3,
                'properties' => ['position' => ['x' => 0.5, 'y' => 0.25], 'scale' => 2]],
            ['type' => 'adjustment', 'layer_order' => 0, 'properties' => ['brightness' => 10]],
        ]);
    }

    public function test_authenticated_user_can_remix_a_published_filter_with_independent_records(): void
    {
        $original = $this->source->fresh()->getAttributes();
        $originalLayers = $this->source->layers->map->getAttributes()->all();
        $this->source->likes()->create(['user_id' => $this->creator->id]);
        $collection = $this->creator->collections()->create(['name' => 'Favorites']);
        $collection->filters()->attach($this->source->id);
        $token = $this->user->createToken('remix-test')->plainTextToken;

        $response = $this->withToken($token)->postJson($this->endpoint())
            ->assertCreated()
            ->assertJsonPath('data.user_id', $this->user->id)
            ->assertJsonPath('data.parent_filter_id', $this->source->id)
            ->assertJsonPath('data.name', 'Remix of Retro Summer')
            ->assertJsonPath('data.description', $this->source->description)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.category.id', $this->source->category_id)
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(3, 'data.layers')
            ->assertJsonPath('data.layers.0.layer_order', 0)
            ->assertJsonPath('data.layers.1.asset.id', $this->asset->id)
            ->assertJsonPath('data.layers.1.properties.position.x', 0.5)
            ->assertJsonMissingPath('data.layers.1.asset.file_path');

        $remix = Filter::findOrFail($response->json('data.id'));
        $this->assertNotSame($this->source->id, $remix->id);
        $this->assertSame($this->user->id, $remix->user_id);
        $this->assertSame($this->source->id, $remix->parent_filter_id);
        $this->assertSame('draft', $remix->status);
        $this->assertNull($remix->published_at);
        $this->assertEqualsCanonicalizing($this->source->tags->modelKeys(), $remix->tags->modelKeys());
        foreach ($this->source->layers as $index => $layer) {
            $copy = $remix->layers[$index];
            $this->assertNotSame($layer->id, $copy->id);
            $this->assertSame($remix->id, $copy->filter_id);
            $this->assertSame($layer->only(['type', 'asset_id', 'layer_order', 'properties']),
                $copy->only(['type', 'asset_id', 'layer_order', 'properties']));
        }
        $this->assertSame($original, $this->source->fresh()->getAttributes());
        $this->assertSame($originalLayers, $this->source->fresh()->layers->map->getAttributes()->all());
        $this->assertSame(0, $remix->likes()->count());
        $this->assertSame(0, $remix->collections()->count());
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('filters', 2);
        $this->assertDatabaseCount('filter_layers', 6);
        $this->assertDatabaseCount('filter_tag', 4);
    }

    public function test_editing_a_remix_preserves_inherited_assets_without_changing_the_source(): void
    {
        Sanctum::actingAs($this->user);
        $original = $this->source->fresh()->getAttributes();
        $originalLayers = $this->source->layers->map->getAttributes()->all();
        $originalTags = $this->source->tags->modelKeys();
        $remixId = $this->postJson($this->endpoint())->assertCreated()->json('data.id');
        $category = Category::create(['name' => 'Modern', 'slug' => 'modern']);
        $tag = Tag::create(['name' => 'New', 'slug' => 'new']);

        $this->patchJson('/api/filters/'.$remixId, [
            'name' => 'My version', 'description' => 'Different colors', 'category_id' => $category->id,
            'tags' => [$tag->id],
            'layers' => [['type' => 'overlay', 'asset_id' => $this->asset->id, 'layer_order' => 9,
                'properties' => ['position' => ['x' => 1, 'y' => 0], 'scale' => 3]]],
        ])->assertOk()
            ->assertJsonPath('data.name', 'My version')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonCount(1, 'data.layers')
            ->assertJsonPath('data.layers.0.asset_id', $this->asset->id)
            ->assertJsonPath('data.layers.0.properties.scale', 3);

        $this->assertSame($original, $this->source->fresh()->getAttributes());
        $this->assertSame($originalLayers, $this->source->fresh()->layers->map->getAttributes()->all());
        $this->assertSame($originalTags, $this->source->fresh()->tags->modelKeys());
        Sanctum::actingAs($this->creator);
        $this->patchJson('/api/filters/'.$remixId, ['name' => 'Not mine'])->assertForbidden();
    }

    public function test_remix_cannot_acquire_unrelated_assets_owned_by_the_source_creator(): void
    {
        Sanctum::actingAs($this->user);
        $remixId = $this->postJson($this->endpoint())->assertCreated()->json('data.id');
        $unrelated = $this->asset->replicate();
        $unrelated->file_path = 'filter-assets/'.$this->creator->id.'/other.png';
        $unrelated->save();

        $this->patchJson('/api/filters/'.$remixId, [
            'layers' => [['type' => 'sticker', 'asset_id' => $unrelated->id, 'layer_order' => 0,
                'properties' => ['scale' => 1]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('layers.0.asset_id');
        $this->assertSame(3, Filter::findOrFail($remixId)->layers()->count());
    }

    public function test_inherited_asset_access_is_scoped_to_the_filter_that_references_it(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint())->assertCreated();
        $other = $this->user->filters()->create([
            'name' => 'Unrelated', 'category_id' => $this->source->category_id, 'status' => 'draft',
        ]);

        $this->patchJson('/api/filters/'.$other->id, [
            'layers' => [['type' => 'sticker', 'asset_id' => $this->asset->id, 'layer_order' => 0,
                'properties' => ['scale' => 1]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('layers.0.asset_id');
    }

    public function test_source_with_no_tags_layers_or_description_can_be_remixed(): void
    {
        Sanctum::actingAs($this->user);
        $this->source->tags()->detach();
        $this->source->layers()->delete();
        $this->source->update(['description' => null]);

        $this->postJson($this->endpoint())->assertCreated()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.layers', []);
    }

    #[DataProvider('validNames')]
    public function test_optional_custom_name(mixed $name, string $expected): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint(), ['name' => $name])->assertCreated()
            ->assertJsonPath('data.name', $expected);
    }

    public static function validNames(): array
    {
        return [
            'custom' => ['My Summer', 'My Summer'],
            'maximum length' => [str_repeat('x', 255), str_repeat('x', 255)],
            'null' => [null, 'Remix of Retro Summer'],
            'empty' => ['', 'Remix of Retro Summer'],
        ];
    }

    public function test_default_name_fits_the_column_even_for_a_long_unicode_source_name(): void
    {
        Sanctum::actingAs($this->user);
        $this->source->update(['name' => str_repeat('é', 255)]);
        $this->postJson($this->endpoint())->assertCreated()
            ->assertJsonPath('data.name', 'Remix of '.str_repeat('é', 246));
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_custom_name_does_not_create_a_remix(mixed $name): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint(), ['name' => $name])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('filters', 1);
    }

    public static function invalidNames(): array
    {
        return ['too long' => [str_repeat('x', 256)], 'array' => [['invalid']], 'number' => [123]];
    }

    public function test_client_cannot_choose_the_remix_owner(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint(), ['user_id' => $this->creator->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('filters', 1);
    }

    public function test_unvalidated_input_cannot_override_copied_or_protected_fields(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint(), [
            'parent_filter_id' => 99999, 'category_id' => 99999,
            'status' => 'published', 'published_at' => now()->toISOString(),
            'description' => 'Override', 'tags' => [], 'layers' => [],
        ])->assertCreated()
            ->assertJsonPath('data.parent_filter_id', $this->source->id)
            ->assertJsonPath('data.category_id', $this->source->category_id)
            ->assertJsonPath('data.description', $this->source->description)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonCount(3, 'data.layers');
    }

    public function test_guest_cannot_remix_even_without_an_accept_header(): void
    {
        $this->post($this->endpoint())->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->assertDatabaseCount('filters', 1);
    }

    #[DataProvider('unpublishedSources')]
    public function test_unpublished_source_returns_json_not_found(string $status, ?string $publishedAt): void
    {
        Sanctum::actingAs($this->user);
        $this->source->update(['status' => $status, 'published_at' => $publishedAt]);
        $this->post($this->endpoint())->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->assertDatabaseCount('filters', 1);
    }

    public static function unpublishedSources(): array
    {
        return [
            'draft' => ['draft', null],
            'draft with date' => ['draft', '2026-01-01 12:00:00'],
            'published without date' => ['published', null],
        ];
    }

    public function test_missing_source_returns_not_found(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/filters/999999/remix')->assertNotFound();
    }

    public function test_layer_copy_failure_rolls_back_the_filter_tags_and_already_copied_layers(): void
    {
        Sanctum::actingAs($this->user);
        $this->withoutExceptionHandling();
        $copiedLayers = 0;
        FilterLayer::creating(function (FilterLayer $layer) use (&$copiedLayers): void {
            if ($layer->filter_id !== $this->source->id && ++$copiedLayers === 2) {
                throw new RuntimeException('Simulated layer copy failure');
            }
        });

        try {
            $this->postJson($this->endpoint());
            $this->fail('Expected the layer copy to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated layer copy failure', $exception->getMessage());
        } finally {
            FilterLayer::flushEventListeners();
        }

        $this->assertSame(2, $copiedLayers);
        $this->assertDatabaseCount('filters', 1);
        $this->assertDatabaseCount('filter_layers', 3);
        $this->assertDatabaseCount('filter_tag', 2);
        $this->assertSame(0, $this->user->filters()->count());
        $this->assertSame('published', $this->source->fresh()->status);
    }

    private function endpoint(): string
    {
        return '/api/filters/'.$this->source->id.'/remix';
    }
}
