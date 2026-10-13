<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Filter;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Category $category;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::factory()->create();
        $this->category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->tag = Tag::create(['name' => 'Summer', 'slug' => 'summer']);
    }

    public function test_guests_see_only_published_filters_with_a_publication_date(): void
    {
        $published = $this->createFilter();
        $this->createFilter(['name' => 'Private draft', 'status' => 'draft', 'published_at' => null]);
        $this->createFilter(['name' => 'Draft with timestamp', 'status' => 'draft']);
        $this->createFilter(['name' => 'Missing publication date', 'published_at' => null]);

        $this->get('/api/gallery')->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.creator', ['id' => $this->creator->id, 'name' => $this->creator->name])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_authenticated_browsing_keeps_gallery_publication_rules_and_owned_filter_behavior(): void
    {
        $owned = $this->createFilter();
        $draft = $this->createFilter(['status' => 'draft', 'published_at' => null]);
        $otherPublished = $this->createFilter(owner: User::factory()->create());
        Sanctum::actingAs($this->creator);

        $gallery = $this->getJson('/api/gallery')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$owned->id, $otherPublished->id], $gallery->json('data.*.id'));

        $ownedFilters = $this->getJson('/api/filters')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.creator');
        $this->assertEqualsCanonicalizing([$owned->id, $draft->id], $ownedFilters->json('data.*.id'));

        $this->getJson('/api/filters/'.$otherPublished->id)->assertForbidden();
        $this->getJson('/api/gallery/'.$draft->id)->assertNotFound();
    }

    public function test_gallery_orders_by_publication_date_then_id_descending(): void
    {
        $newest = $this->createFilter(['published_at' => '2026-10-13 15:00:00']);
        $oldest = $this->createFilter(['published_at' => '2026-10-11 15:00:00']);
        $tied = $this->createFilter(['published_at' => '2026-10-13 15:00:00']);

        $response = $this->getJson('/api/gallery')->assertOk();

        $this->assertSame([$tied->id, $newest->id, $oldest->id], $response->json('data.*.id'));
    }

    public function test_gallery_paginates_and_preserves_search_category_and_tag_in_links(): void
    {
        $ids = [];

        for ($i = 0; $i < 16; $i++) {
            $filter = $this->createFilter(['name' => 'Retro '.$i, 'published_at' => now()->subMinutes($i)]);
            $filter->tags()->attach($this->tag);
            $ids[] = $filter->id;
        }

        $query = ['search' => 'Retro', 'category_id' => $this->category->id, 'tag_id' => $this->tag->id];
        $firstPage = $this->getJson('/api/gallery?'.http_build_query($query))->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        $this->assertSame(array_slice($ids, 0, 15), $firstPage->json('data.*.id'));
        parse_str(parse_url($firstPage->json('links.next'), PHP_URL_QUERY), $nextPageQuery);
        $this->assertEquals([...$query, 'page' => 2], $nextPageQuery);

        $this->getJson($firstPage->json('links.next'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[15])
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('links.next', null);
    }

    public function test_search_matches_name_and_description_but_excludes_unrelated_and_private_filters(): void
    {
        $nameMatch = $this->createFilter(['name' => 'Retro Summer']);
        $descriptionMatch = $this->createFilter(['name' => 'Warm Colors', 'description' => 'A retro effect']);
        $this->createFilter(['name' => 'Modern', 'description' => 'Cool tones']);
        $this->createFilter(['name' => 'Draft', 'description' => 'retro', 'status' => 'draft', 'published_at' => null]);
        $this->createFilter(['name' => 'Undated', 'description' => 'retro', 'published_at' => null]);

        $response = $this->getJson('/api/gallery?search=retro')->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([$nameMatch->id, $descriptionMatch->id], $response->json('data.*.id'));
    }

    public function test_search_for_zero_is_applied(): void
    {
        $matching = $this->createFilter(['name' => 'Film 0']);
        $this->createFilter(['name' => 'Film One']);

        $this->getJson('/api/gallery?search=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_sql_like_search_input_is_treated_as_a_bound_value(): void
    {
        $this->createFilter();
        $this->createFilter(['status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/gallery?'.http_build_query(['search' => "' OR 1=1 --"]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_query_parameters_cannot_override_publication_requirements(): void
    {
        $published = $this->createFilter();
        $this->createFilter(['status' => 'draft', 'published_at' => null]);
        $this->createFilter(['published_at' => null]);

        $this->getJson('/api/gallery?'.http_build_query([
            'search' => '%',
            'status' => 'draft',
            'published_at' => null,
            'user_id' => $this->creator->id,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id);
    }

    public function test_category_filter_excludes_other_categories(): void
    {
        $matching = $this->createFilter();
        $otherCategory = Category::create(['name' => 'Modern', 'slug' => 'modern']);
        $this->createFilter(['category_id' => $otherCategory->id]);

        $this->getJson('/api/gallery?category_id='.$this->category->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_tag_filter_returns_only_filters_with_that_tag(): void
    {
        $matching = $this->createFilter();
        $matching->tags()->attach($this->tag);
        $otherTag = Tag::create(['name' => 'Winter', 'slug' => 'winter']);
        $this->createFilter()->tags()->attach($otherTag);
        $this->createFilter();

        $this->getJson('/api/gallery?tag_id='.$this->tag->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_search_category_and_tag_are_combined_with_and(): void
    {
        $matching = $this->createFilter(['description' => 'retro']);
        $matching->tags()->attach($this->tag);
        $otherCategory = Category::create(['name' => 'Modern', 'slug' => 'modern']);
        $this->createFilter(['description' => 'retro', 'category_id' => $otherCategory->id])->tags()->attach($this->tag);
        $this->createFilter(['description' => 'retro']);
        $this->createFilter(['description' => 'unrelated'])->tags()->attach($this->tag);
        $this->createFilter(['description' => 'retro', 'status' => 'draft'])->tags()->attach($this->tag);

        $this->getJson('/api/gallery?'.http_build_query([
            'search' => 'retro',
            'category_id' => $this->category->id,
            'tag_id' => $this->tag->id,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_empty_optional_filters_are_allowed(): void
    {
        $this->createFilter();

        $this->getJson('/api/gallery?search=&category_id=&tag_id=')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guest_can_view_published_details_with_safe_relationship_data(): void
    {
        $filter = $this->createFilterWithRelationships();
        $filter->layers()->create([
            'type' => 'text', 'layer_order' => 1, 'properties' => ['text' => 'SUMMER'],
        ]);

        $response = $this->get('/api/gallery/'.$filter->id)->assertOk()
            ->assertJsonPath('data.id', $filter->id)
            ->assertJsonPath('data.creator', ['id' => $this->creator->id, 'name' => $this->creator->name])
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.tags.0.id', $this->tag->id)
            ->assertJsonCount(2, 'data.layers')
            ->assertJsonPath('data.layers.0.asset.type', 'sticker')
            ->assertJsonPath('data.layers.0.properties.opacity', 1)
            ->assertJsonPath('data.layers.1.asset', null)
            ->assertJsonMissingPath('data.creator.email')
            ->assertJsonMissingPath('data.creator.password')
            ->assertJsonMissingPath('data.creator.remember_token')
            ->assertJsonMissingPath('data.layers.0.asset.file_path')
            ->assertJsonMissingPath('data.layers.0.asset.url')
            ->assertJsonMissingPath('data.tags.0.pivot');

        $this->assertStringNotContainsString($this->creator->email, $response->getContent());
        $this->assertStringNotContainsString($this->creator->password, $response->getContent());
    }

    #[DataProvider('unpublishedStates')]
    public function test_unpublished_details_return_json_404(string $status, ?string $publishedAt): void
    {
        $filter = $this->createFilter(['status' => $status, 'published_at' => $publishedAt]);

        $this->get('/api/gallery/'.$filter->id)->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonMissingPath('data');
    }

    public static function unpublishedStates(): array
    {
        return [
            'draft without date' => ['draft', null],
            'draft with date' => ['draft', '2026-10-13 15:00:00'],
            'published without date' => ['published', null],
        ];
    }

    public function test_missing_or_invalid_filter_ids_return_json_404(): void
    {
        $filter = $this->createFilter();

        foreach (['999999', $filter->id.'invalid'] as $id) {
            $this->get('/api/gallery/'.$id)->assertNotFound()->assertHeader('Content-Type', 'application/json');
        }
    }

    #[DataProvider('invalidQueryParameters')]
    public function test_invalid_gallery_filters_return_json_validation_errors(array $parameters, string $field): void
    {
        $this->get('/api/gallery?'.http_build_query($parameters))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryParameters(): array
    {
        return [
            'unknown category' => [['category_id' => 999999], 'category_id'],
            'nonnumeric category' => [['category_id' => 'invalid'], 'category_id'],
            'unknown tag' => [['tag_id' => 999999], 'tag_id'],
            'array tag' => [['tag_id' => [1]], 'tag_id'],
            'long search' => [['search' => str_repeat('a', 256)], 'search'],
            'array search' => [['search' => ['retro']], 'search'],
            'negative page' => [['page' => -1], 'page'],
            'array page' => [['page' => [1]], 'page'],
        ];
    }

    public function test_gallery_eager_loads_relationships_without_more_queries_per_filter(): void
    {
        $this->createFilterWithRelationships()->likes()->create(['user_id' => $this->creator->id]);
        DB::enableQueryLog();

        try {
            $this->getJson('/api/gallery')->assertOk()
                ->assertJsonPath('data.0.creator.id', $this->creator->id)
                ->assertJsonPath('data.0.likes_count', 1)
                ->assertJsonPath('data.0.layers.0.asset.type', 'sticker');
            $singleFilterQueryCount = count(DB::getQueryLog());

            for ($i = 0; $i < 4; $i++) {
                $this->createFilterWithRelationships()->likes()->create(['user_id' => $this->creator->id]);
            }

            DB::flushQueryLog();
            $response = $this->getJson('/api/gallery')->assertOk()->assertJsonCount(5, 'data');
            $this->assertSame($singleFilterQueryCount, count(DB::getQueryLog()));

            foreach ($response->json('data') as $filter) {
                $this->assertSame($this->creator->id, $filter['creator']['id']);
                $this->assertSame(1, $filter['likes_count']);
                $this->assertSame($this->category->id, $filter['category']['id']);
                $this->assertSame($this->tag->id, $filter['tags'][0]['id']);
                $this->assertSame('sticker', $filter['layers'][0]['asset']['type']);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function createFilter(array $attributes = [], ?User $owner = null): Filter
    {
        return ($owner ?? $this->creator)->filters()->create(array_merge([
            'name' => 'Summer Filter',
            'description' => 'Warm colors',
            'category_id' => $this->category->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    private function createFilterWithRelationships(): Filter
    {
        $filter = $this->createFilter();
        $filter->tags()->attach($this->tag);
        $asset = $this->creator->assets()->create([
            'type' => 'sticker',
            'original_name' => 'sticker.png',
            'file_path' => 'filter-assets/private/sticker.png',
            'mime_type' => 'image/png',
            'file_size' => 128,
            'width' => 10,
            'height' => 10,
        ]);
        $filter->layers()->create([
            'type' => 'sticker',
            'asset_id' => $asset->id,
            'layer_order' => 0,
            'properties' => ['opacity' => 1],
        ]);

        return $filter;
    }
}
