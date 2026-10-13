<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GalleryFilterRequest;
use App\Http\Resources\FilterResource;
use App\Models\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GalleryController extends Controller
{
    public function index(GalleryFilterRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = $this->publishedFilters();

        if (isset($data['search'])) {
            $pattern = '%'.$data['search'].'%';

            $query->where(fn (Builder $search) => $search
                ->whereLike('name', $pattern)
                ->orWhereLike('description', $pattern));
        }

        if (isset($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }

        if (isset($data['tag_id'])) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($data['tag_id']));
        }

        $filters = $query->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->appends($request->safe()->only(['search', 'category_id', 'tag_id']));

        return FilterResource::collection($filters);
    }

    public function show(string $filter): FilterResource
    {
        return new FilterResource($this->publishedFilters()->whereKey($filter)->firstOrFail());
    }

    private function publishedFilters(): Builder
    {
        return Filter::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->withCount('likes')
            ->with(['user:id,name', 'category', 'tags', 'layers.asset']);
    }
}
