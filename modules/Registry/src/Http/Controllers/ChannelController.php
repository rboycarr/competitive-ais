<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\ChannelService;
use Modules\Registry\Models\Channel;
use Modules\Registry\Models\Retailer;

/** Admin › Channels (P1-23b) for taxonomy.manage holders; ChannelService checks again. */
class ChannelController
{
    public function __construct(private readonly ChannelService $channels) {}

    public function index(): Response
    {
        $used = Retailer::query()->selectRaw('channel_id, COUNT(*) AS retailers')->groupBy('channel_id')->pluck('retailers', 'channel_id');

        return Inertia::render('Registry/Admin/Channels', [
            'channels' => Channel::query()->orderBy('name')->get()->map(fn (Channel $c): array => [
                'id' => $c->id, 'name' => $c->name, 'type' => $c->channel_type, 'retailers' => (int) ($used[$c->id] ?? 0),
            ])->all(),
            'types' => Channel::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->channels->create($request->user() ?? abort(401), (string) $validated['name'], (string) $validated['channel_type']);

        return back()->with('status', 'Channel added.');
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->channels->update($request->user() ?? abort(401), $channel, (string) $validated['name'], (string) $validated['channel_type']);

        return back()->with('status', 'Channel saved.');
    }

    public function destroy(Request $request, Channel $channel): RedirectResponse
    {
        $this->channels->remove($request->user() ?? abort(401), $channel);

        return back()->with('status', 'Channel removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'channel_type' => ['required', 'string', Rule::in(Channel::TYPES)],
        ];
    }
}
