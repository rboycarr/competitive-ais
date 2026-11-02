<?php

namespace Modules\Sources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sources\Models\SourcePolicy;

/** A policy decision (P1-31a); the review rules (status, proposer, required fields, robots) are decided in SourcePolicyService. */
class DecideSourcePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:source.approve`; SourcePolicyService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(SourcePolicy::DECISIONS)],
            'based_on_version' => ['required', 'integer', 'min:0', 'max:65535'],
            'terms_url' => ['nullable', 'string', 'max:2048'],
            'terms_summary' => ['nullable', 'string', 'max:10000'],
            'terms_reviewed_on' => ['nullable', 'date_format:Y-m-d'],
            'robots_decision' => ['required', 'string', Rule::in(SourcePolicy::ROBOTS)],
            'license' => ['nullable', 'string', 'max:255'],
            'allowed_cadence' => ['nullable', 'string', 'max:50'],
            'rate_budget_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'contains_personal_data' => ['required', 'boolean'],
            'personal_data_basis' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * The validated input in the service's types.
     *
     * @return array{decision: string, based_on_version: int, terms_url: ?string, terms_summary: ?string, terms_reviewed_on: ?string, robots_decision: string, license: ?string, allowed_cadence: ?string, rate_budget_per_hour: ?int, retention_days: ?int, contains_personal_data: bool, personal_data_basis: ?string, notes: ?string}
     */
    public function decision(): array
    {
        $valid = $this->validated();
        $string = fn (string $key): ?string => isset($valid[$key]) ? (string) $valid[$key] : null;
        $int = fn (string $key): ?int => isset($valid[$key]) && $valid[$key] !== '' ? (int) $valid[$key] : null;

        return [
            'decision' => (string) $valid['decision'],
            'based_on_version' => (int) $valid['based_on_version'],
            'terms_url' => $string('terms_url'),
            'terms_summary' => $string('terms_summary'),
            'terms_reviewed_on' => $string('terms_reviewed_on'),
            'robots_decision' => (string) $valid['robots_decision'],
            'license' => $string('license'),
            'allowed_cadence' => $string('allowed_cadence'),
            'rate_budget_per_hour' => $int('rate_budget_per_hour'),
            'retention_days' => $int('retention_days'),
            'contains_personal_data' => $this->boolean('contains_personal_data'),
            'personal_data_basis' => $string('personal_data_basis'),
            'notes' => $string('notes'),
        ];
    }
}
