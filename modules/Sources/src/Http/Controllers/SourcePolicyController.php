<?php

namespace Modules\Sources\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Http\Requests\DecideSourcePolicyRequest;
use Modules\Sources\Models\Source;

/**
 * The policy review (P1-31a): the owner or proposer submits a source; a director decides it. SourcePolicyService checks
 * permission, visibility (hidden sources are 404), status and the proposer rule under a lock on the source.
 */
class SourcePolicyController
{
    public function __construct(private readonly SourcePolicyService $policies) {}

    public function submit(Request $request, Source $source): RedirectResponse
    {
        $this->policies->submit($request->user() ?? abort(401), $source);

        return redirect()->route('sources.show', $source)->with('status', "Source {$source->name} submitted for policy review.");
    }

    public function store(DecideSourcePolicyRequest $request, Source $source): RedirectResponse
    {
        $policy = $this->policies->decide($request->user() ?? abort(401), $source, $request->decision());
        $outcome = match ($policy->decision) {
            'approved' => 'approved',
            'rejected' => 'rejected and returned to its proposer',
            default => 'returned to its proposer for more information',
        };

        return redirect()->route('sources.show', $source)->with('status', "Policy version {$policy->version}: {$source->name} $outcome.");
    }
}
