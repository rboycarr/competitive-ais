<?php

namespace Modules\Identity\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Audit\AuditActions;

/** Audit viewer filters (P1-16): actor, action, subject and a time range; all optional. */
class AuditSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:audit.view`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'string', 'ulid'],
            'action' => ['nullable', 'string', Rule::in(app(AuditActions::class)->names())],
            'subject' => ['nullable', 'string', 'ulid'],
            // Instants with their offset (the page sends UTC).
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
