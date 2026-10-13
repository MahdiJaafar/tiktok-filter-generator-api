<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets', ['visibility' => 'private']);
        Storage::fake('public');
        $this->user = User::factory()->create();
    }

    #[DataProvider('supportedUploads')]
    public function test_authenticated_user_can_upload_images_with_correct_metadata(string $type, string $extension, string $mimeType): void
    {
        Sanctum::actingAs($this->user);
        $image = UploadedFile::fake()->image('artwork.'.$extension, 300, 200);
        $file = new UploadedFile($image->path(), 'artwork.'.$extension, 'application/octet-stream', null, true);

        $response = $this->post('/api/assets', ['type' => $type, 'file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.original_name', 'artwork.'.$extension)
            ->assertJsonPath('data.mime_type', $mimeType)
            ->assertJsonPath('data.file_size', $file->getSize())
            ->assertJsonPath('data.width', 300)
            ->assertJsonPath('data.height', 200)
            ->assertJsonMissingPath('data.file_path')
            ->assertJsonMissingPath('data.url')
            ->assertJsonMissingPath('data.user');

        $asset = Asset::findOrFail($response->json('data.id'));
        $this->assertSame($this->user->id, $asset->user_id);
        $this->assertSame($type, $asset->type);
        $this->assertSame('artwork.'.$extension, $asset->original_name);
        $this->assertSame($mimeType, $asset->mime_type);
        $this->assertSame($file->getSize(), $asset->file_size);
        $this->assertSame(300, $asset->width);
        $this->assertSame(200, $asset->height);
        $this->assertMatchesRegularExpression('#^filter-assets/'.$this->user->id.'/[a-zA-Z0-9]{40}\.(png|jpg|jpeg|webp)$#', $asset->file_path);
        $this->assertDatabaseCount('assets', 1);
        Storage::disk('assets')->assertExists($asset->file_path);
        Storage::disk('public')->assertMissing($asset->file_path);
        $this->assertFalse(Route::has('storage.assets'));
        $this->assertSame(file_get_contents($file->path()), Storage::disk('assets')->get($asset->file_path));
    }

    public static function supportedUploads(): array
    {
        return [
            'PNG sticker' => ['sticker', 'png', 'image/png'],
            'PNG overlay' => ['overlay', 'png', 'image/png'],
            'JPG sticker' => ['sticker', 'jpg', 'image/jpeg'],
            'JPEG overlay' => ['overlay', 'jpeg', 'image/jpeg'],
            'WEBP overlay' => ['overlay', 'webp', 'image/webp'],
        ];
    }

    public function test_guest_cannot_upload_an_asset(): void
    {
        $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image('sticker.png'),
        ])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');

        $this->assertNoAssetsStored();
    }

    public function test_type_and_file_are_required(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets')->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['type', 'file']);

        $this->assertNoAssetsStored();
    }

    #[DataProvider('invalidAssetTypes')]
    public function test_invalid_asset_types_are_rejected(mixed $type): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => $type,
            'file' => UploadedFile::fake()->image('sticker.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertNoAssetsStored();
    }

    public static function invalidAssetTypes(): array
    {
        return [
            'adjustment' => ['adjustment'],
            'text' => ['text'],
            'array' => [['sticker']],
        ];
    }

    public function test_nonimage_content_with_a_spoofed_extension_and_mime_type_is_rejected(): void
    {
        Sanctum::actingAs($this->user);
        $content = UploadedFile::fake()->createWithContent('document.txt', 'This is not an image.');
        $file = new UploadedFile($content->path(), 'sticker.png', 'image/png', null, true);

        $this->post('/api/assets', ['type' => 'sticker', 'file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public function test_gif_images_are_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image('sticker.gif'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public function test_svg_uploads_are_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'overlay',
            'file' => UploadedFile::fake()->createWithContent('overlay.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public function test_files_over_five_megabytes_are_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'overlay',
            'file' => UploadedFile::fake()->image('overlay.png')->size(5121),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public function test_five_megabyte_files_are_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'overlay',
            'file' => UploadedFile::fake()->image('overlay.png')->size(5120),
        ])->assertCreated();

        Storage::disk('assets')->assertExists(Asset::firstOrFail()->file_path);
    }

    #[DataProvider('oversizedDimensions')]
    public function test_excessive_image_dimensions_are_rejected(int $width, int $height): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'overlay',
            'file' => UploadedFile::fake()->image('overlay.png', $width, $height),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public static function oversizedDimensions(): array
    {
        return [
            'too wide' => [4097, 1],
            'too tall' => [1, 4097],
        ];
    }

    public function test_original_name_cannot_exceed_the_database_column_limit(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image(str_repeat('a', 256).'.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertNoAssetsStored();
    }

    public function test_client_cannot_choose_the_asset_owner(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image('sticker.png'),
            'user_id' => User::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertNoAssetsStored();
    }

    public function test_client_metadata_and_paths_cannot_override_detected_metadata(): void
    {
        Sanctum::actingAs($this->user);
        $file = UploadedFile::fake()->image('sticker.png', 30, 20);

        $response = $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => $file,
            'file_path' => '../../public/unsafe.php',
            'original_name' => 'unsafe.php',
            'mime_type' => 'application/x-php',
            'file_size' => 1,
            'width' => 1,
            'height' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.original_name', 'sticker.png')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.file_size', $file->getSize())
            ->assertJsonPath('data.width', 30)
            ->assertJsonPath('data.height', 20);

        $asset = Asset::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('filter-assets/'.$this->user->id.'/', $asset->file_path);
        $this->assertStringNotContainsString('..', $asset->file_path);
        Storage::disk('assets')->assertExists($asset->file_path);
    }

    public function test_original_filenames_cannot_control_paths_or_overwrite_existing_uploads(): void
    {
        Sanctum::actingAs($this->user);

        foreach (['../../sticker.png', '..\\..\\sticker.png'] as $name) {
            $file = UploadedFile::fake()->image($name);
            $this->post('/api/assets', ['type' => 'sticker', 'file' => $file])->assertCreated();
        }

        $assets = Asset::all();
        $this->assertCount(2, $assets);
        $this->assertNotSame($assets[0]->file_path, $assets[1]->file_path);

        foreach ($assets as $asset) {
            $this->assertSame('sticker.png', $asset->original_name);
            $this->assertMatchesRegularExpression('#^filter-assets/'.$this->user->id.'/[a-zA-Z0-9]{40}\.png$#', $asset->file_path);
            Storage::disk('assets')->assertExists($asset->file_path);
        }
    }

    public function test_uploaded_asset_can_be_referenced_by_an_owned_filter_layer(): void
    {
        Sanctum::actingAs($this->user);
        $response = $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image('sticker.png'),
        ])->assertCreated();

        $category = Category::create(['name' => 'Retro', 'slug' => 'retro']);
        $assetId = $response->json('data.id');

        $this->postJson('/api/filters', [
            'name' => 'Sticker Filter',
            'category_id' => $category->id,
            'layers' => [[
                'type' => 'sticker',
                'asset_id' => $assetId,
                'layer_order' => 0,
                'properties' => ['opacity' => 1],
            ]],
        ])->assertCreated()->assertJsonPath('data.layers.0.asset.id', $assetId);
    }

    public function test_database_failure_rolls_back_metadata_and_removes_the_stored_file(): void
    {
        Sanctum::actingAs($this->user);
        Asset::created(function (): void {
            throw new RuntimeException('Simulated asset database failure');
        });

        try {
            $this->post('/api/assets', [
                'type' => 'sticker',
                'file' => UploadedFile::fake()->image('sticker.png'),
            ])->assertInternalServerError();
        } finally {
            Asset::flushEventListeners();
        }

        $this->assertNoAssetsStored();
    }

    public function test_storage_failure_does_not_create_an_asset_record(): void
    {
        Sanctum::actingAs($this->user);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()
            ->andThrow(UnableToWriteFile::atLocation('filter-assets/unavailable.png'));
        Storage::shouldReceive('disk')->with('assets')->once()->andReturn($disk);

        $this->post('/api/assets', [
            'type' => 'sticker',
            'file' => UploadedFile::fake()->image('sticker.png'),
        ])->assertInternalServerError();

        $this->assertDatabaseCount('assets', 0);
    }

    private function assertNoAssetsStored(): void
    {
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }
}
