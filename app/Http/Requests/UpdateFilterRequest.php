<?php

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class UpdateFilterRequest extends StoreFilterRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('filter'));
    }

    /** @return array<string, array> */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['category_id'] = ['sometimes', 'required', 'integer', 'exists:categories,id'];

        // A remix may retain inherited assets, but cannot acquire unrelated users' assets.
        $rules['layers.*.asset_id'] = [
            'nullable',
            'integer',
            Rule::exists('assets', 'id')->where(fn (Builder $assets) => $assets->where(
                fn (Builder $allowed) => $allowed->where('user_id', $this->user()->id)
                    ->orWhereIn('id', fn (Builder $layers) => $layers->select('asset_id')
                        ->from('filter_layers')
                        ->where('filter_id', $this->route('filter')->id)),
            )),
        ];

        return $rules;
    }
}
