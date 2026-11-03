<?php

namespace Modules\Sources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sources\Models\SourceStatusChange;

/** A suspension, reinstatement or retirement (P1-31b); the status and reason rules are decided in SourceLifecycleService. */
class ChangeSourceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:source.manage`; SourceLifecycleService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(array_keys(SourceStatusChange::ACTIONS))],
            'reason' => ['required', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:10000'],
        ];
    }
}
