<?php

namespace Modules\Identity\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
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
            // A forged cursor without an integer `id` would make the paginator throw (TASK-0027 #1).
            'cursor' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                $cursor = Cursor::fromEncoded($value);
                $fields = $cursor?->toArray() ?? [];
                if (array_keys($fields) !== ['id', '_pointsToNextItems'] || ! is_int($fields['id']) || ! is_bool($fields['_pointsToNextItems'])) {
                    $fail('This page link is not valid. Start a new search.');
                }
            }],
        ];
    }
}
