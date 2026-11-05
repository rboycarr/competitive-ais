<?php

namespace Modules\Intelligence\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;

/** An intelligence item, new or edited (P1-40); subjects, visibility, dates and versions are decided in IntelligenceItemService. */
class SaveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:intelligence.create`; IntelligenceItemService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = IntelligenceItemService::MAX_SUBJECTS;

        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:'.IntelligenceItemService::MAX_SUMMARY],
            'item_type' => ['required', 'string', Rule::in(IntelligenceItem::TYPES)],
            'classification' => ['required', Rule::enum(Classification::class)],
            'high_impact' => ['boolean'],
            'observed_at' => ['nullable', 'string', 'max:40'],
            'published_at' => ['nullable', 'string', 'max:40'],
            'collected_at' => ['nullable', 'string', 'max:40'],
            'competitors' => ['array', "max:$max"],
            'competitors.*' => ['string', 'ulid'],
            'products' => ['array', "max:$max"],
            'products.*' => ['string', 'ulid'],
            'markets' => ['array', "max:$max"],
            'markets.*' => ['string', 'ulid'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }
}
