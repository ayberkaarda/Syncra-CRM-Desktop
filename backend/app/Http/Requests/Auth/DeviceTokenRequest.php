<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/auth/device` — the desktop client's one and only credential
 * exchange (SYNCDESKTOP §4.3).
 *
 * Public route (no `auth:sanctum`), so `authorize()` is true and every
 * decision is a rule or a service-level check.
 *
 * `device_fingerprint` is `size:64` + hex because it is the KEY of the
 * one-token-per-device rule: the service deletes any existing token carrying
 * the same value. A free-form string there would let a client wipe another
 * device's token by guessing (or simply copying) its fingerprint, so the shape
 * is pinned to what a sha256 digest can be. It is scoped per user anyway - the
 * delete is always filtered by tokenable_id.
 */
class DeviceTokenRequest extends FormRequest
{
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
            'device_name' => ['required', 'string', 'max:120'],
            'device_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'platform' => ['required', Rule::in(['windows', 'macos', 'linux'])],
            'app_version' => ['required', 'string', 'max:32'],
        ];
    }
}
