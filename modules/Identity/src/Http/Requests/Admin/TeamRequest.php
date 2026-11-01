<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A team's name, description and lead (P1-13); the rules are in TeamService. */
class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:team.manage`; TeamService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'lead' => ['nullable', 'string', 'size:26'],
        ];
    }
}
