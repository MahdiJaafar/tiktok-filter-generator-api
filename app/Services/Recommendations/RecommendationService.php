<?php

namespace App\Services\Recommendations;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class RecommendationService
{
    private const LIMIT = 5;

    private const CANDIDATE_LIMIT = 50;

    public function __construct(private LlmRecommendationService $llm) {}

    /** @return array{filters: Collection<int, Filter>, source: string} */
    public function recommend(User $user): array
    {
        // Distinct public history only; repeated saves of one filter do not amplify its preferences.
        $history = $this->published()->where(fn (Builder $query) => $this->history($query, $user))
            ->with(['category:id,name', 'tags' => fn ($query) => $query->orderBy('tags.id')->limit(50)])
            ->orderByDesc('published_at')->orderByDesc('id')->limit(50)->get();
        $categoryIds = $history->pluck('category_id')->unique()->values()->all();
        $tags = $history->flatMap->tags->unique('id');
        $tagIds = $tags->pluck('id')->values()->all();

        $ranked = fn () => $this->rankedCandidates($categoryIds, $tagIds);
        $candidates = $ranked()->where('user_id', '!=', $user->id)
            ->whereDoesntHave('likes', fn (Builder $query) => $query->where('user_id', $user->id))
            ->whereDoesntHave('collections', fn (Builder $query) => $query->where('user_id', $user->id))
            ->limit(self::CANDIDATE_LIMIT)->get();
        $tiers = array_fill_keys($candidates->modelKeys(), 0);

        if ($candidates->count() < self::LIMIT) {
            $seen = $ranked()->where('user_id', '!=', $user->id)
                ->where(fn (Builder $query) => $this->history($query, $user))
                ->limit(self::CANDIDATE_LIMIT - $candidates->count())->get();
            $tiers += array_fill_keys($seen->modelKeys(), 1);
            $candidates = $candidates->merge($seen);
        }

        if ($candidates->count() < self::LIMIT) {
            $owned = $ranked()->where('user_id', $user->id)
                ->limit(self::CANDIDATE_LIMIT - $candidates->count())->get();
            $tiers += array_fill_keys($owned->modelKeys(), 2);
            $candidates = $candidates->merge($owned);
        }

        $fallbackIds = $candidates->modelKeys();
        $aiIds = [];
        if ($history->isNotEmpty() && $candidates->isNotEmpty()) {
            $preferences = [
                'preferred_categories' => $history->pluck('category.name')->unique()->take(10)
                    ->map(fn ($name) => Str::limit($name, 80, ''))->values()->all(),
                'preferred_tags' => $tags->take(20)->map(fn ($tag) => Str::limit($tag->name, 40, ''))->values()->all(),
                'liked_or_saved_filter_names' => $history->take(10)
                    ->map(fn ($filter) => Str::limit($filter->name, 80, ''))->values()->all(),
            ];
            $promptCandidates = $candidates->map(fn (Filter $filter) => [
                'id' => $filter->id,
                'name' => Str::limit($filter->name, 80, ''),
                'category' => Str::limit($filter->category->name, 80, ''),
                'tags' => $filter->tags->take(10)->map(fn ($tag) => Str::limit($tag->name, 40, ''))->values()->all(),
            ])->all();
            $aiIds = array_values(array_intersect($this->llm->rank($preferences, $promptCandidates) ?? [], $fallbackIds));
        }

        // AI may rank within each tier; it cannot displace unseen alternatives with owned/history filters.
        $orderedIds = collect(array_merge($aiIds, $fallbackIds))->unique()
            ->sortBy(fn ($id) => $tiers[$id])->values();
        // A provider call can take seconds. Recheck publication after it, and fill gaps from the same pool.
        $available = $this->published()->whereIn('id', $orderedIds)->withCount('likes')
            ->limit(self::CANDIDATE_LIMIT)->get()->keyBy('id');
        $selected = new Collection($orderedIds->filter(fn ($id) => $available->has($id))
            ->take(self::LIMIT)->map(fn ($id) => $available->get($id))->all());
        $selected->load(['user:id,name', 'category', 'tags', 'layers.asset']);

        return [
            'filters' => $selected,
            'source' => array_intersect($aiIds, $selected->modelKeys()) ? 'ai' : 'fallback',
        ];
    }

    private function published(): Builder
    {
        return Filter::query()->where('status', 'published')->whereNotNull('published_at');
    }

    private function history(Builder $query, User $user): void
    {
        $query->whereHas('likes', fn (Builder $likes) => $likes->where('user_id', $user->id))
            ->orWhereHas('collections', fn (Builder $collections) => $collections->where('user_id', $user->id));
    }

    private function rankedCandidates(array $categoryIds, array $tagIds): Builder
    {
        $query = $this->published()->withCount('likes')
            ->withCount(['tags as matching_tags_count' => fn (Builder $tags) => $tags->whereIn('tags.id', $tagIds)])
            ->with(['category:id,name', 'tags' => fn ($tags) => $tags->orderBy('tags.id')->limit(50)]);

        if ($categoryIds) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $query->orderByRaw("CASE WHEN category_id IN ($placeholders) THEN 1 ELSE 0 END DESC", $categoryIds);
        }

        return $query->orderByDesc('matching_tags_count')->orderByDesc('likes_count')
            ->orderByDesc('published_at')->orderByDesc('id');
    }
}
