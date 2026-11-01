<?php

namespace Modules\Identity\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Actions\TeamService;
use Modules\Identity\Http\Requests\Admin\TeamMemberRequest;
use Modules\Identity\Http\Requests\Admin\TeamRequest;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;

/** Admin › Teams (P1-13, team.manage): teams, their lead and members. */
class TeamController
{
    public function index(): Response
    {
        return Inertia::render('Identity/Admin/Teams', [
            'teams' => Team::query()->with(['members:id,ulid,name', 'lead:id,ulid,name'])->orderBy('name')->get()
                ->map(fn (Team $team): array => [
                    'ulid' => $team->ulid,
                    'name' => $team->name,
                    'description' => $team->description,
                    'lead' => $team->lead?->ulid,
                    'members' => $team->members->map(fn (User $user): array => ['ulid' => $user->ulid, 'name' => $user->name])->values()->all(),
                ])->all(),
            'users' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'ulid', 'name'])
                ->map(fn (User $user): array => ['ulid' => $user->ulid, 'name' => $user->name])->all(),
        ]);
    }

    public function store(TeamRequest $request, TeamService $teams): RedirectResponse
    {
        $team = $teams->create($this->actor($request), (string) $request->validated('name'), $this->description($request), $this->lead($request));

        return back()->with('status', "Team {$team->name} created.");
    }

    public function update(TeamRequest $request, Team $team, TeamService $teams): RedirectResponse
    {
        $teams->update($this->actor($request), $team, (string) $request->validated('name'), $this->description($request), $this->lead($request));

        return back()->with('status', "Team {$team->name} updated.");
    }

    public function addMember(TeamMemberRequest $request, Team $team, TeamService $teams): RedirectResponse
    {
        $member = User::query()->where('ulid', (string) $request->validated('member'))->first()
            ?? throw ValidationException::withMessages(['member' => 'Unknown user.']);
        $teams->addMember($this->actor($request), $team, $member);

        return back()->with('status', "{$member->name} added to {$team->name}.");
    }

    public function removeMember(Request $request, Team $team, User $user, TeamService $teams): RedirectResponse
    {
        $teams->removeMember($this->actor($request), $team, $user);

        return back()->with('status', "{$user->name} removed from {$team->name}.");
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function description(TeamRequest $request): ?string
    {
        $description = $request->validated('description');

        return is_string($description) ? $description : null;
    }

    private function lead(TeamRequest $request): ?User
    {
        $ulid = $request->validated('lead');
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return User::query()->where('ulid', $ulid)->first() ?? throw ValidationException::withMessages(['lead' => 'Unknown user.']);
    }
}
