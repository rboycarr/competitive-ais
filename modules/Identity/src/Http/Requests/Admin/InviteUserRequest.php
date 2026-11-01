<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Classification;

/** Inviting a user from the admin screen (P1-13); the invitation rules themselves are in InvitationService. */
class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:user.manage`; InvitationService checks the inviter again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:125'],
            'clearance' => ['required', Rule::enum(Classification::class)],
        ];
    }
}
