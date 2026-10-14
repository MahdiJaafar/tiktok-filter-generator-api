<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RemixRequest;
use App\Http\Resources\FilterResource;
use App\Models\Filter;
use App\Models\FilterLayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemixController extends Controller
{
    public function store(RemixRequest $request, Filter $filter): JsonResponse
    {
        $remix = DB::transaction(function () use ($request, $filter): Filter {
            // Keep the source consistent with the existing filter update/delete operations.
            $source = Filter::query()->whereKey($filter->id)
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->sharedLock()
                ->with(['tags', 'layers'])
                ->firstOrFail();

            $remix = $request->user()->filters()->create([
                'parent_filter_id' => $source->id,
                'category_id' => $source->category_id,
                'name' => $request->validated('name') ?? Str::substr('Remix of '.$source->name, 0, 255),
                'description' => $source->description,
                'status' => 'draft',
                'published_at' => null,
            ]);

            $remix->tags()->attach($source->tags->modelKeys());
            $remix->layers()->createMany($source->layers->map(
                fn (FilterLayer $layer) => $layer->only(['asset_id', 'type', 'layer_order', 'properties']),
            ));

            return $remix->load(['category', 'tags', 'layers.asset']);
        });

        return (new FilterResource($remix))->response()->setStatusCode(201);
    }
}
