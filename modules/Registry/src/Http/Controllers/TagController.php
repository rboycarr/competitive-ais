<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\TagService;
use Modules\Registry\Models\Tag;

/**
 * Admin › Tags (P1-23b) for taxonomy.manage holders; TagService checks again. No usage counts: they would include
 * competitors the manager may not see.
 */
class TagController
{
    public function __construct(private readonly TagService $tags) {}

    public function index(): Response
    {
        return Inertia::render('Registry/Admin/Tags', [
            'tags' => Tag::query()->orderBy('taxonomy')->orderBy('name')->get()->map(fn (Tag $t): array => [
                'id' => $t->id, 'taxonomy' => $t->taxonomy, 'name' => $t->name,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->tags->create($request->user() ?? abort(401), (string) $validated['taxonomy'], (string) $validated['name']);

        return back()->with('status', 'Tag added.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->tags->update($request->user() ?? abort(401), $tag, (string) $validated['taxonomy'], (string) $validated['name']);

        return back()->with('status', 'Tag saved.');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        $this->tags->remove($request->user() ?? abort(401), $tag);

        return back()->with('status', 'Tag removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'taxonomy' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
