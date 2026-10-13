<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Resources\AssetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AssetController extends Controller
{
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $file = $request->file('file');
        [$width, $height] = $file->dimensions();
        $path = $file->store('filter-assets/'.$request->user()->id, 'assets');

        try {
            $asset = DB::transaction(fn () => $request->user()->assets()->create([
                'type' => $request->validated('type'),
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
            ]));
        } catch (Throwable $exception) {
            Storage::disk('assets')->delete($path);

            throw $exception;
        }

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }
}
