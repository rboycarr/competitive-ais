<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Registry\Actions\CompetitorAliasService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\CompetitorAlias;

/** Aliases on a competitor's profile (P1-21); CompetitorAliasService decides who may change what. */
class CompetitorAliasController
{
    public function __construct(private readonly CompetitorAliasService $aliases) {}

    public function store(Request $request, Competitor $competitor): RedirectResponse
    {
        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:255'],
            'alias_type' => ['required', 'string', Rule::in(CompetitorAlias::TYPES)],
        ]);
        $this->aliases->add($request->user() ?? abort(401), $competitor, (string) $validated['alias'], (string) $validated['alias_type']);

        return back()->with('status', 'Alias added.');
    }

    public function destroy(Request $request, Competitor $competitor, CompetitorAlias $alias): RedirectResponse
    {
        $this->aliases->remove($request->user() ?? abort(401), $competitor, $alias);

        return back()->with('status', 'Alias removed.');
    }
}
