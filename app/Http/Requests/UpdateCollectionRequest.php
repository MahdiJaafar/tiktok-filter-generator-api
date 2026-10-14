<?php

namespace App\Http\Requests;

class UpdateCollectionRequest extends StoreCollectionRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('collection'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];

        return $rules;
    }
}
