<?php

namespace Modules\Identity\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Actions\AccessGrantService;
use Modules\Identity\Http\Requests\Admin\AccessGrantRequest;
use Modules\Identity\Models\AccessGrant;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;

/** Admin › Access grants (P1-14, grant.manage): who may see what beyond their roles, until when; revoke, never delete. */
class AccessGrantController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('Identity/Admin/AccessGrants', [
            'grants' => AccessGrant::query()->with(['user:id,ulid,name', 'team:id,ulid,name', 'grantor:id,name', 'revoker:id,name'])
                ->orderByDesc('id')->get()
                ->map(fn (AccessGrant $grant): array => [
                    'ulid' => $grant->ulid,
                    'granteeType' => $grant->user_id !== null ? 'user' : 'team',
                    'grantee' => $grant->user->name ?? $grant->team?->name,
                    'scope' => $grant->scope_type,
                    'maxClassification' => $grant->max_classification->value,
                    'canExport' => $grant->can_export,
                    'reason' => $grant->reason,
                    'grantedBy' => $grant->grantor?->name,
                    'grantedAt' => $grant->created_at?->toIso8601ZuluString(),
                    'expiresAt' => $grant->expires_at?->toIso8601ZuluString(),
                    'revokedBy' => $grant->revoker?->name,
                    'revokedAt' => $grant->revoked_at?->toIso8601ZuluString(),
                    'status' => $grant->status(),
                ])->all(),
            // Grantees to choose from; the service refuses the actor and inactive users again.
            'users' => User::query()->where('status', 'active')->whereKeyNot($actor->id)->orderBy('name')->get(['id', 'ulid', 'name'])
                ->map(fn (User $user): array => ['ulid' => $user->ulid, 'name' => $user->name])->all(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'ulid', 'name'])
                ->map(fn (Team $team): array => ['ulid' => $team->ulid, 'name' => $team->name])->all(),
            'classifications' => array_values(array_map(
                fn (Classification $c): string => $c->value,
                array_filter(Classification::cases(), fn (Classification $c): bool => $actor->clearance->isAtLeast($c)),
            )),
            'canGrantExport' => $actor->checkPermissionTo('export.run'),
        ]);
    }

    public function store(AccessGrantRequest $request, AccessGrantService $grants): RedirectResponse
    {
        $ulid = (string) $request->validated('grantee');
        $grantee = $request->validated('grantee_type') === 'user'
            ? User::query()->where('ulid', $ulid)->first()
            : Team::query()->where('ulid', $ulid)->first();
        if ($grantee === null) {
            throw ValidationException::withMessages(['grantee' => 'Unknown user or team.']);
        }
        $expiresAt = $request->validated('expires_at');

        $grants->grant(
            $this->actor($request),
            $grantee,
            Classification::from((string) $request->validated('max_classification')),
            (bool) $request->validated('can_export', false),
            (string) $request->validated('reason'),
            is_string($expiresAt) && $expiresAt !== '' ? CarbonImmutable::parse($expiresAt)->utc() : null,
        );

        return back()->with('status', "Access granted to {$grantee->name}.");
    }

    public function revoke(Request $request, AccessGrant $grant, AccessGrantService $grants): RedirectResponse
    {
        $grants->revoke($this->actor($request), $grant);

        return back()->with('status', 'Access grant revoked.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
