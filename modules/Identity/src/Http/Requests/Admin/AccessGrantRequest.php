<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Classification;

/** A new access grant (P1-14); the rules on who may grant what are in AccessGrantService. */
class AccessGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:grant.manage`; AccessGrantService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'grantee_type' => ['required', 'string', Rule::in(['user', 'team'])],
            'grantee' => ['required', 'string', 'size:26'],
            'max_classification' => ['required', Rule::enum(Classification::class)],
            'can_export' => ['boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // An instant with its offset (the page sends UTC); the service checks it is in the future.
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
