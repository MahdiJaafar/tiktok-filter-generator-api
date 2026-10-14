<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'filters_count' => $this->whenCounted('filters'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'filters' => FilterResource::collection($this->whenLoaded('filters')),
        ];
    }
}
