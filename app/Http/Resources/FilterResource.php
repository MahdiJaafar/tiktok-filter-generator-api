<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FilterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'parent_filter_id' => $this->parent_filter_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'category' => $this->whenLoaded('category', fn () => $this->category->only(['id', 'name', 'slug'])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map->only(['id', 'name', 'slug'])),
            'layers' => FilterLayerResource::collection($this->whenLoaded('layers')),
        ];
    }
}
