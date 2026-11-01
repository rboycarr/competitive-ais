<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** The complete set of roles for a user (P1-13); the rules are in RoleAssignmentService. */
class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:user.manage`; RoleAssignmentService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:20'],
            'roles.*' => ['string', 'max:125', 'distinct'],
        ];
    }
}
