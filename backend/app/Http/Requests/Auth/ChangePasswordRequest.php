<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    /**
     * The route already sits behind auth:sanctum + active; a signed-in user is
     * always allowed to change their own password.
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
            // Required even when the flag forced the user here: this endpoint is
            // also the permanent contract for a voluntary change (Phase 10
            // profile screen), and it stops someone sitting at an unattended
            // screen - or holding a stolen session cookie - from taking the
            // account over permanently.
            'current_password' => ['required', 'string', 'current_password:web'],

            'password' => [
                'required',
                'string',
                'confirmed',
                // The temporary password must not simply be re-submitted.
                'different:current_password',
                Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised(),
            ],
        ];
    }
}
