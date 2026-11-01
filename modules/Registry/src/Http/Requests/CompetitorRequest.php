<?php

namespace Modules\Registry\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Models\Competitor;

/** A competitor's profile (P1-20); who may set what is decided in CompetitorService. */
class CompetitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:competitor.manage`; CompetitorService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && ! CompetitorService::isWebAddress(trim($value))) {
                    $fail('The website must be an http:// or https:// address.');
                }
            }],
            'hq_country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'tier' => ['nullable', 'string', Rule::in(Competitor::TIERS)],
            'analyst' => ['nullable', 'string', 'ulid'],
            'classification' => ['required', Rule::enum(Classification::class)],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
