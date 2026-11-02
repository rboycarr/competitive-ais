<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Registry\Actions\CompetitorTagService;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Tag;

/** Tags on a competitor's profile (P1-23b); CompetitorTagService decides who may change what. */
class CompetitorTagController
{
    public function __construct(private readonly CompetitorTagService $tags) {}

    public function store(Request $request, Competitor $competitor): RedirectResponse
    {
        $validated = $request->validate(['tag' => ['required', 'integer', 'min:1']]);
        $this->tags->tag($request->user() ?? abort(401), $competitor, (int) $validated['tag']);

        return back()->with('status', 'Tag added.');
    }

    public function destroy(Request $request, Competitor $competitor, Tag $tag): RedirectResponse
    {
        $this->tags->untag($request->user() ?? abort(401), $competitor, $tag);

        return back()->with('status', 'Tag removed.');
    }
}
