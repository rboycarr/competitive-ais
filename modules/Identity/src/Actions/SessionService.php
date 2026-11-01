<?php

namespace Modules\Identity\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;

/**
 * A user's own sessions (P1-17), stored in the `sessions` table (PO decision 2026-11-01: database session driver).
 *
 * Session IDs are bearer secrets, so they never leave the server: the page gets an HMAC handle (app key) per session.
 * Revoking deletes the session row and also cycles the remember token, because the "keep me signed in" cookie of the
 * revoked device would otherwise sign it straight back in; this device keeps its session and signs in normally next
 * time. Every revocation is audited with its scope and count.
 */
class SessionService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {}

    /**
     * @return list<array{handle: string, ipAddress: ?string, userAgent: ?string, lastActiveAt: string, current: bool}>
     */
    public function list(User $user, string $currentId): array
    {
        return array_values($this->db->table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'handle' => $this->handle((string) $row->id),
                'ipAddress' => is_string($row->ip_address) ? $row->ip_address : null,
                'userAgent' => is_string($row->user_agent) ? Str::limit($row->user_agent, 200) : null,
                'lastActiveAt' => CarbonImmutable::createFromTimestampUTC((int) $row->last_activity)->toIso8601ZuluString(),
                'current' => hash_equals((string) $row->id, $currentId),
            ])->all());
    }

    /** Revoke one of the user's other sessions; false when the handle matches none of them. */
    public function revoke(User $user, string $handle, string $currentId): bool
    {
        return $this->db->transaction(function () use ($user, $handle, $currentId): bool {
            $ids = $this->otherSessionIds($user, $currentId);
            $match = array_values(array_filter($ids, fn (string $id): bool => hash_equals($this->handle($id), $handle)));
            if ($match === []) {
                return false;
            }

            $this->end($user, $match, 'one');

            return true;
        });
    }

    /** Revoke every session of the user except this one; returns how many ended. */
    public function revokeOthers(User $user, string $currentId): int
    {
        return $this->db->transaction(function () use ($user, $currentId): int {
            $ids = $this->otherSessionIds($user, $currentId);
            $this->end($user, $ids, 'others');

            return count($ids);
        });
    }

    private function handle(string $sessionId): string
    {
        return hash_hmac('sha256', 'session:'.$sessionId, (string) $this->config->get('app.key'));
    }

    /** @return list<string> */
    private function otherSessionIds(User $user, string $currentId): array
    {
        return array_values(array_map('strval', $this->db->table('sessions')->where('user_id', $user->id)
            ->where('id', '<>', $currentId)->lockForUpdate()->pluck('id')->all()));
    }

    /** @param list<string> $ids */
    private function end(User $user, array $ids, string $scope): void
    {
        $this->db->table('sessions')->where('user_id', $user->id)->whereIn('id', $ids)->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();
        $this->audit->record('session.revoked', $user, ['scope' => $scope, 'count' => count($ids)], Actor::user($user->id), Classification::Internal);
    }
}
