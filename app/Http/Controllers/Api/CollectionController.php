<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Http\Resources\CollectionResource;
use App\Models\Collection;
use App\Models\Filter;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CollectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Collection::class);

        $collections = $request->user()->collections()
            ->withCount('filters')
            ->latest('id')
            ->paginate(15);

        return CollectionResource::collection($collections);
    }

    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $collection = $request->user()->collections()->create($request->safe()->only(['name', 'description']));

        return (new CollectionResource($collection->loadCount('filters')))->response()->setStatusCode(201);
    }

    public function show(Collection $collection): CollectionResource
    {
        Gate::authorize('view', $collection);

        $collection->loadCount('filters')->load([
            'filters' => fn (BelongsToMany $filters) => $filters
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->with(['user:id,name', 'category', 'tags', 'layers.asset'])
                ->withCount('likes')
                ->orderByPivot('created_at', 'desc')
                ->orderByDesc('filters.id'),
        ]);

        return new CollectionResource($collection);
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): CollectionResource
    {
        $collection->update($request->safe()->only(['name', 'description']));

        return new CollectionResource($collection->loadCount('filters'));
    }

    public function destroy(Collection $collection): JsonResponse
    {
        Gate::authorize('delete', $collection);

        $collection->delete();

        return response()->json(['message' => 'Collection deleted successfully']);
    }

    public function addFilter(Collection $collection, Filter $filter): JsonResponse
    {
        Gate::authorize('manageFilters', $collection);
        abort_unless($filter->status === 'published' && $filter->published_at !== null, 404);

        $count = DB::transaction(function () use ($collection, $filter): int {
            // Serialize membership changes so concurrent saves cannot insert duplicate pivots.
            $collection = Collection::query()->lockForUpdate()->findOrFail($collection->id);
            $collection->filters()->syncWithoutDetaching([$filter->id]);

            return $collection->filters()->count();
        });

        return response()->json(['saved' => true, 'filters_count' => $count]);
    }

    public function removeFilter(Collection $collection, Filter $filter): JsonResponse
    {
        Gate::authorize('manageFilters', $collection);

        $count = DB::transaction(function () use ($collection, $filter): int {
            $collection = Collection::query()->lockForUpdate()->findOrFail($collection->id);
            $collection->filters()->detach($filter->id);

            return $collection->filters()->count();
        });

        return response()->json(['saved' => false, 'filters_count' => $count]);
    }
}
