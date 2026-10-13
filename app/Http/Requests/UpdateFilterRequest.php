<?php

namespace App\Http\Requests;

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

        return $rules;
    }
}
