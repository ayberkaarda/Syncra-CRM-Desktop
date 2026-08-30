<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Login is a public endpoint; authorization is the credential check itself.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? trim($this->input('email')) : $this->input('email'),
        ]);
    }

    /**
     * The credentials passed to the guard. Never includes `remember`.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->input('email'),
            'password' => (string) $this->input('password'),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
