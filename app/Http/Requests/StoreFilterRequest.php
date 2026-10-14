<?php

namespace App\Http\Requests;

use App\Models\Filter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Filter::class);
    }

    /** @return array<string, array> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'tags' => ['sometimes', 'array', 'list', 'max:50'],
            'tags.*' => ['required', 'integer', 'distinct', 'exists:tags,id'],
            'layers' => ['sometimes', 'array', 'list', 'max:100'],
            'layers.*' => ['required', 'array:type,asset_id,layer_order,properties'],
            'layers.*.type' => ['required', Rule::in(['adjustment', 'sticker', 'overlay', 'text'])],
            'layers.*.asset_id' => [
                'nullable',
                'integer',
                Rule::exists('assets', 'id')->where('user_id', $this->user()->id),
            ],
            'layers.*.layer_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'layers.*.properties' => ['required', 'array'],
            'user_id' => ['prohibited'],
            'parent_filter_id' => ['prohibited'],
            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
        ];
    }
}
