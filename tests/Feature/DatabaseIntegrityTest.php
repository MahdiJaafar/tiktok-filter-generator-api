<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Filter;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Filter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $this->filter = $this->user->filters()->create([
            'name' => 'Original', 'category_id' => $category->id, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    #[DataProvider('uniqueRelations')]
    public function test_database_prevents_duplicate_relationships(string $table): void
    {
        $row = match ($table) {
            'likes' => ['user_id' => $this->user->id, 'filter_id' => $this->filter->id],
            'filter_tag' => ['filter_id' => $this->filter->id, 'tag_id' => Tag::create(['name' => 'Warm', 'slug' => 'warm'])->id],
            'collection_filter' => ['filter_id' => $this->filter->id, 'collection_id' => $this->user->collections()->create(['name' => 'Saved'])->id],
        };
        DB::table($table)->insert($row);

        try {
            DB::transaction(fn () => DB::table($table)->insert($row));
            $this->fail('A duplicate relationship must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount($table, 1);
        }
    }

    public static function uniqueRelations(): array
    {
        return [['likes'], ['filter_tag'], ['collection_filter']];
    }

    public function test_deleting_an_asset_nulls_the_reference_without_deleting_the_layer(): void
    {
        $asset = $this->user->assets()->create([
            'type' => 'sticker', 'original_name' => 'sticker.png', 'file_path' => 'filter-assets/sticker.png',
            'mime_type' => 'image/png', 'file_size' => 100,
        ]);
        $layer = $this->filter->layers()->create([
            'type' => 'sticker', 'asset_id' => $asset->id, 'layer_order' => 0, 'properties' => ['scale' => 1],
        ]);
        $asset->delete();

        $this->assertDatabaseHas('filter_layers', ['id' => $layer->id, 'asset_id' => null]);
        $this->assertDatabaseHas('filters', ['id' => $this->filter->id]);
    }

    public function test_deleting_a_remix_source_nulls_the_parent_without_deleting_the_remix(): void
    {
        $remix = User::factory()->create()->filters()->create([
            'name' => 'Remix', 'category_id' => $this->filter->category_id,
            'parent_filter_id' => $this->filter->id, 'status' => 'draft',
        ]);
        $this->filter->delete();

        $this->assertDatabaseHas('filters', ['id' => $remix->id, 'parent_filter_id' => null]);
    }

    public function test_a_category_with_filters_cannot_be_deleted(): void
    {
        try {
            DB::transaction(fn () => $this->filter->category->delete());
            $this->fail('Deleting a category that still has filters must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('categories', ['id' => $this->filter->category_id]);
            $this->assertDatabaseHas('filters', ['id' => $this->filter->id]);
        }
    }

    public function test_deleting_a_user_cascades_owned_records_and_preserves_other_users_filters(): void
    {
        $otherFilter = User::factory()->create()->filters()->create([
            'name' => 'Other', 'category_id' => $this->filter->category_id, 'status' => 'draft',
        ]);
        $this->user->likes()->create(['filter_id' => $otherFilter->id]);
        $collection = $this->user->collections()->create(['name' => 'Saved']);
        $collection->filters()->attach($otherFilter->id);
        $this->user->assets()->create([
            'type' => 'sticker', 'original_name' => 'sticker.png', 'file_path' => 'filter-assets/sticker.png',
            'mime_type' => 'image/png', 'file_size' => 100,
        ]);
        $this->user->delete();

        foreach (['assets', 'likes', 'collections', 'collection_filter'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('filters', ['id' => $this->filter->id]);
        $this->assertDatabaseHas('filters', ['id' => $otherFilter->id]);
    }
}
