<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A role's name and permissions (P1-13); the rules are in RoleDefinitionService. */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:role.manage`; RoleDefinitionService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'max:50'],
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['string', 'max:125', 'distinct'],
        ];
    }
}
