<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Registry\Actions\CompetitorRelationshipService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorRelationship;

/** Corporate relationships on a competitor's profile (P1-21); CompetitorRelationshipService decides who may change what. */
class CompetitorRelationshipController
{
    public function __construct(private readonly CompetitorRelationshipService $relationships) {}

    public function store(Request $request, Competitor $competitor): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['parent', 'child'])],
            'other' => ['required', 'string', 'ulid'],
            'relation_type' => ['required', 'string', Rule::in(CompetitorRelationship::TYPES)],
        ] + $this->periodRules());
        $this->relationships->add($request->user() ?? abort(401), $competitor, (string) $validated['role'], (string) $validated['other'], $this->period($validated) + ['relation_type' => (string) $validated['relation_type']]);

        return back()->with('status', 'Relationship added.');
    }

    public function update(Request $request, Competitor $competitor, CompetitorRelationship $relationship): RedirectResponse
    {
        $validated = $request->validate($this->periodRules());
        $this->relationships->update($request->user() ?? abort(401), $competitor, $relationship, $this->period($validated));

        return back()->with('status', 'Relationship updated.');
    }

    public function destroy(Request $request, Competitor $competitor, CompetitorRelationship $relationship): RedirectResponse
    {
        $this->relationships->remove($request->user() ?? abort(401), $competitor, $relationship);

        return back()->with('status', 'Relationship removed.');
    }

    /** @return array<string, mixed> */
    private function periodRules(): array
    {
        return [
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_from_precision' => ['required', Rule::in(CompetitorRelationship::PRECISIONS)],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{valid_from: string, valid_from_precision: string, valid_to: ?string, notes: ?string}
     */
    private function period(array $validated): array
    {
        return [
            'valid_from' => (string) $validated['valid_from'],
            'valid_from_precision' => (string) $validated['valid_from_precision'],
            'valid_to' => isset($validated['valid_to']) ? (string) $validated['valid_to'] : null,
            'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
        ];
    }
}
