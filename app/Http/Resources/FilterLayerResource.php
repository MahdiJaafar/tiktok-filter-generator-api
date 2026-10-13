<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FilterLayerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'asset_id' => $this->asset_id,
            'layer_order' => $this->layer_order,
            'properties' => $this->properties,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset->only([
                'id', 'type', 'original_name', 'mime_type', 'file_size', 'width', 'height',
            ])),
        ];
    }
}
