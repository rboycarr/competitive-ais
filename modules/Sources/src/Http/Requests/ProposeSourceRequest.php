<?php

namespace Modules\Sources\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;

/** A source proposal (P1-30); the flag, owner and classification rules are decided in SourceService. */
class ProposeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:source.propose`; SourceService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'string', Rule::in(Source::TYPES)],
            'base_url' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '') {
                    try {
                        SourceService::host(trim($value));
                    } catch (ValidationException $e) {
                        $fail((string) $e->validator->errors()->first('base_url'));
                    }
                }
            }],
            'method' => ['nullable', 'string', Rule::in(Source::METHODS)],
            'default_reliability' => ['nullable', 'string', Rule::in(Source::RELIABILITIES)],
            'owner' => ['nullable', 'string', 'ulid'],
            'classification' => ['required', Rule::enum(Classification::class)],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
