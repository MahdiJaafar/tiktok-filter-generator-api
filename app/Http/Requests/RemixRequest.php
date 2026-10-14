<?php

namespace App\Http\Requests;

use App\Models\Filter;
use Illuminate\Foundation\Http\FormRequest;

class RemixRequest extends FormRequest
{
    public function authorize(): bool
    {
        $filter = $this->route('filter');
        abort_unless($filter->status === 'published' && $filter->published_at !== null, 404);

        return $this->user()->can('create', Filter::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'user_id' => ['prohibited'],
        ];
    }
}
