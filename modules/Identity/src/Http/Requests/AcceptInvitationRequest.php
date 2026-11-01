<?php

namespace Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Name and password for a new account (P1-10). Authorization is the invitation token itself, checked by the
 * controller. The password check against breached-password lists needs an external service and waits for the
 * Product Owner's decision (P1-11); length is enforced now.
 */
class AcceptInvitationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->max(128)],
        ];
    }
}
