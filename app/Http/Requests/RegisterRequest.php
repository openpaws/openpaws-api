<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\ReservedUsernames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => [
                'required', 'string', 'regex:/^[a-z0-9_-]{3,30}$/',
                function ($attr, $value, $fail) {
                    if (ReservedUsernames::contains($value)) {
                        $fail('This username is reserved.');
                    }
                },
                function ($attr, $value, $fail) {
                    if (User::whereRaw('LOWER(username) = ?', [$value])->exists()) {
                        $fail('This username is already taken.');
                    }
                },
            ],
            'display_name' => ['required', 'string', 'min:1', 'max:50'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
