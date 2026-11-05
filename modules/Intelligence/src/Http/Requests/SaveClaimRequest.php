<?php

namespace Modules\Intelligence\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Models\IntelligenceClaim;

/** A claim, new or edited (P1-40, ADR-0010); the item's edit rules are applied in IntelligenceClaimService. */
class SaveClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:intelligence.create`; IntelligenceClaimService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'string', 'max:'.IntelligenceClaimService::MAX_STATEMENT],
            'claim_type' => ['required', 'string', Rule::in(IntelligenceClaim::TYPES)],
            'source_reliability' => ['required', 'string', Rule::in(IntelligenceClaim::RELIABILITIES)],
            'info_credibility' => ['required', 'integer', Rule::in(IntelligenceClaim::CREDIBILITIES)],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
