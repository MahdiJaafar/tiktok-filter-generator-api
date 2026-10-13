<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFilterRequest;
use App\Http\Requests\UpdateFilterRequest;
use App\Http\Resources\FilterResource;
use App\Models\Filter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FilterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Filter::class);

        $filters = $request->user()->filters()
            ->with(['category', 'tags', 'layers.asset'])
            ->latest('id')
            ->paginate(15);

        return FilterResource::collection($filters);
    }

    public function store(StoreFilterRequest $request): JsonResponse
    {
        $filter = DB::transaction(function () use ($request): Filter {
            $data = $request->validated();
            $filter = $request->user()->filters()->create([
                ...Arr::only($data, ['name', 'description', 'category_id']),
                'status' => 'draft',
                'published_at' => null,
            ]);

            $filter->tags()->sync($data['tags'] ?? []);
            $filter->layers()->createMany($data['layers'] ?? []);

            return $filter->load(['category', 'tags', 'layers.asset']);
        });

        return (new FilterResource($filter))->response()->setStatusCode(201);
    }

    public function show(Filter $filter): FilterResource
    {
        Gate::authorize('view', $filter);

        return new FilterResource($filter->load(['category', 'tags', 'layers.asset']));
    }

    public function update(UpdateFilterRequest $request, Filter $filter): FilterResource
    {
        $filter = DB::transaction(function () use ($request, $filter): Filter {
            $filter = Filter::query()->lockForUpdate()->findOrFail($filter->id);
            $data = $request->validated();

            $filter->update(Arr::only($data, ['name', 'description', 'category_id']));

            if (array_key_exists('tags', $data)) {
                $filter->tags()->sync($data['tags']);
            }

            if (array_key_exists('layers', $data)) {
                $filter->layers()->delete();
                $filter->layers()->createMany($data['layers']);
            }

            return $filter->load(['category', 'tags', 'layers.asset']);
        });

        return new FilterResource($filter);
    }

    public function publish(Filter $filter): FilterResource
    {
        Gate::authorize('publish', $filter);

        $filter = DB::transaction(function () use ($filter): Filter {
            $filter = Filter::query()->lockForUpdate()->findOrFail($filter->id);

            if ($filter->status !== 'published') {
                $filter->update([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            }

            return $filter->load(['category', 'tags', 'layers.asset']);
        });

        return new FilterResource($filter);
    }

    public function destroy(Filter $filter): JsonResponse
    {
        Gate::authorize('delete', $filter);

        $filter->delete();

        return response()->json(['message' => 'Filter deleted successfully']);
    }
}
