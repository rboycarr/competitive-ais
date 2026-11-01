<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Adding a member to a team by the user's ULID (P1-13). */
class TeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:team.manage`; TeamService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['member' => ['required', 'string', 'size:26']];
    }
}
