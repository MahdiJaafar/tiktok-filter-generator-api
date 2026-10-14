<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'bail', 'required', 'string', 'confirmed', 'min:8', 'max:4096', 'not_regex:/\x00/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Bcrypt silently ignores bytes beyond 72, including multibyte characters.
                    if (Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ];
    }
}
