<?php

namespace Modules\Identity\Directory;

use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Contracts\UserSummary;
use Modules\Identity\Models\User;

/** UserDirectory over the users table (P1-20). */
class EloquentUserDirectory implements UserDirectory
{
    public function activeHolders(string $permission): array
    {
        // hasPermissionTo, not a query scope: it applies the two-factor rule for privileged permissions (P1-12).
        return array_values(User::query()->where('status', 'active')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo($permission))
            ->map(fn (User $user): UserSummary => $this->summary($user))
            ->all());
    }

    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->get(['id', 'ulid', 'name', 'status'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $this->summary($user)])
            ->all();
    }

    private function summary(User $user): UserSummary
    {
        return new UserSummary($user->id, $user->ulid, $user->name, $user->status === 'active');
    }
}
