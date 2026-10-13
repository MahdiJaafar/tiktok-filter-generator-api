<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['sticker', 'overlay'])],
            'file' => [
                'bail',
                'required',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:5120',
                'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (mb_strlen($value->getClientOriginalName()) > 255) {
                        $fail('The file name must not exceed 255 characters.');
                    }
                },
            ],
            'user_id' => ['prohibited'],
        ];
    }
}
