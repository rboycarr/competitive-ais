<?php

namespace Modules\Intelligence\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Http\Requests\SaveClaimRequest;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceItem;

/**
 * Claims of an item (P1-40): add, edit, withdraw and move; their evidence (P1-41): cite and stop citing.
 * IntelligenceClaimService applies the item's edit rules.
 */
class IntelligenceClaimController
{
    public function __construct(private readonly IntelligenceClaimService $claims) {}

    public function store(SaveClaimRequest $request, IntelligenceItem $item): RedirectResponse
    {
        $this->claims->add($request->user() ?? abort(401), $item, $request->validated());

        return $this->back($item, 'Claim added.');
    }

    public function update(SaveClaimRequest $request, IntelligenceItem $item, string $claim): RedirectResponse
    {
        $this->claims->update($request->user() ?? abort(401), $item, $claim, $request->validated());

        return $this->back($item, 'Claim saved.');
    }

    public function withdraw(Request $request, IntelligenceItem $item, string $claim): RedirectResponse
    {
        $this->claims->withdraw($request->user() ?? abort(401), $item, $claim, $request->validate(['version' => ['required', 'integer', 'min:1']]));

        return $this->back($item, 'Claim withdrawn. It stays on record.');
    }

    public function move(Request $request, IntelligenceItem $item, string $claim): RedirectResponse
    {
        $this->claims->move($request->user() ?? abort(401), $item, $claim, $request->validate([
            'direction' => ['required', 'in:up,down'],
            'version' => ['required', 'integer', 'min:1'],
        ]));

        return $this->back($item, 'Claim moved.');
    }

    public function link(Request $request, IntelligenceItem $item, string $claim): RedirectResponse
    {
        $this->claims->link($request->user() ?? abort(401), $item, $claim, $request->validate([
            'evidence' => ['required', 'string', 'ulid'],
            'relation' => ['required', Rule::in(ClaimEvidenceLink::RELATIONS)],
            'locator' => ['nullable', 'string', 'max:'.IntelligenceClaimService::MAX_LOCATOR],
            'excerpt' => ['nullable', 'string', 'max:'.IntelligenceClaimService::MAX_EXCERPT],
            'version' => ['required', 'integer', 'min:1'],
        ]));

        return $this->back($item, 'Evidence cited.');
    }

    public function unlink(Request $request, IntelligenceItem $item, string $claim): RedirectResponse
    {
        $this->claims->unlink($request->user() ?? abort(401), $item, $claim, $request->validate([
            'evidence' => ['required', 'string', 'ulid'],
            'relation' => ['required', Rule::in(ClaimEvidenceLink::RELATIONS)],
            'version' => ['required', 'integer', 'min:1'],
        ]));

        return $this->back($item, 'Evidence no longer cited.');
    }

    private function back(IntelligenceItem $item, string $status): RedirectResponse
    {
        return redirect()->route('intelligence.show', $item)->with('status', $status);
    }
}
