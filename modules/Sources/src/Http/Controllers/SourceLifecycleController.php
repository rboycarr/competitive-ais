<?php

namespace Modules\Sources\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Modules\Sources\Actions\SourceLifecycleService;
use Modules\Sources\Http\Requests\ChangeSourceStatusRequest;
use Modules\Sources\Models\Source;

/**
 * Suspend, reinstate, retire (P1-31b). SourceLifecycleService checks permission, visibility (hidden sources are 404),
 * status and reason under a lock on the source.
 */
class SourceLifecycleController
{
    public function __construct(private readonly SourceLifecycleService $lifecycle) {}

    public function store(ChangeSourceStatusRequest $request, Source $source): RedirectResponse
    {
        $change = $this->lifecycle->change($request->user() ?? abort(401), $source, $request->input('action'), $request->input('reason'), $request->input('note'));
        $outcome = match ($change->action) {
            'suspend' => 'suspended: nobody may use it until a director approves it again',
            'reinstate' => 'sent for a new policy review',
            default => 'retired',
        };

        return redirect()->route('sources.show', $source)->with('status', "Source {$source->name} $outcome.");
    }
}
