<?php

namespace Modules\Identity\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Mail\InvitationMail;
use Modules\Identity\Models\Invitation;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;

/**
 * Invitations are the only way to create an account (P1-10, docs/03 Identity, docs/07 Authentication).
 *
 * - The token is 32 random bytes, sent once by email and stored only as its SHA-256; it expires after 72 hours
 *   and works once. A new invitation for the same address revokes the open ones.
 * - An inviting user needs user.manage and cannot hand out more than they hold (assertInviterMayGrant). The console
 *   command (bootstrap) acts as the system.
 * - Accepting runs under a row lock, so two concurrent submissions cannot create two accounts.
 * - Both steps are audited in the same transaction (user.invited, user.activated). The email is sent after commit.
 */
class InvitationService
{
    public const VALID_HOURS = 72;

    private const TOKEN = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return Invitation the new invitation (its token exists only in the email)
     */
    public function invite(string $email, string $roleName, Classification $clearance, ?User $inviter = null): Invitation
    {
        $email = Str::lower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }
        if (! Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages(['role' => "Unknown role [$roleName]."]);
        }
        if ($inviter !== null) {
            $this->assertInviterMayGrant($inviter, $roleName, $clearance);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email address already exists.']);
        }

        $token = bin2hex(random_bytes(32));

        $invitation = $this->db->transaction(function () use ($email, $roleName, $clearance, $inviter, $token): Invitation {
            $now = CarbonImmutable::now('UTC');
            $replaced = Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', $now)->update(['revoked_at' => $now, 'updated_at' => $now]);

            $invitation = new Invitation;
            $invitation->forceFill([
                'ulid' => (string) Str::ulid(),
                'email' => $email,
                'role_name' => $roleName,
                'clearance' => $clearance,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter?->id,
                'expires_at' => $now->addHours(self::VALID_HOURS),
            ])->save();

            $this->audit->record('user.invited', $invitation, [
                'invitation_ulid' => $invitation->ulid,
                'role' => $roleName,
                'clearance' => $clearance->value,
                'replaced' => $replaced,
            ], $inviter === null ? Actor::system() : Actor::user($inviter->id), Classification::Internal);

            return $invitation;
        });

        // Sent directly once committed, not queued, so the plain token is never stored in a queue payload. If sending
        // fails, inviting again revokes this invitation and issues a new one.
        $expiresAt = $invitation->expires_at;
        $this->db->connection()->afterCommit(fn () => Mail::to($email)->send(new InvitationMail($token, $expiresAt)));

        return $invitation;
    }

    /**
     * An inviting user needs user.manage, cannot hand out a clearance above their own, and cannot hand out a role
     * with permissions they do not hold themselves (TASK-0017 #1). A super_admin, who manages the platform, may
     * invite any role; only a super_admin may invite a super_admin.
     */
    private function assertInviterMayGrant(User $inviter, string $roleName, Classification $clearance): void
    {
        if ($inviter->status !== 'active' || ! $inviter->hasPermissionTo('user.manage')) {
            throw ValidationException::withMessages(['role' => 'You are not allowed to invite users.']);
        }
        if (! $inviter->clearance->isAtLeast($clearance)) {
            throw ValidationException::withMessages(['clearance' => 'You cannot grant a clearance above your own.']);
        }
        if ($inviter->hasRole('super_admin')) {
            return;
        }
        if ($roleName === 'super_admin') {
            throw ValidationException::withMessages(['role' => 'Only a super admin can invite a super admin.']);
        }

        $roleGives = Role::findByName($roleName, 'web')->permissions->pluck('name')->all();
        $inviterHas = $inviter->getAllPermissions()->pluck('name')->all();
        if (array_diff($roleGives, $inviterHas) !== []) {
            throw ValidationException::withMessages(['role' => 'You cannot invite someone with permissions you do not hold.']);
        }
    }

    /**
     * The open invitation for a token, or null when it is unknown, malformed, used, revoked or expired. Callers show
     * the same answer for every null case, so the response does not reveal which one applies.
     */
    public function findOpen(string $token): ?Invitation
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation?->isOpen() === true ? $invitation : null;
    }

    /**
     * Creates the account and consumes the invitation. Null when the invitation is no longer open.
     */
    public function accept(string $token, string $name, string $password): ?User
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }

        return $this->db->transaction(function () use ($token, $name, $password): ?User {
            $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isOpen() || User::query()->where('email', $invitation->email)->exists()) {
                return null;
            }

            $user = new User;
            $user->forceFill([
                'ulid' => (string) Str::ulid(),
                'name' => $name,
                'email' => $invitation->email,
                'email_verified_at' => now('UTC'), // the token proves control of the address
                'password' => $password,
                'clearance' => $invitation->clearance,
                'status' => 'active',
            ])->save();
            $user->assignRole($invitation->role_name);

            $invitation->forceFill(['accepted_at' => CarbonImmutable::now('UTC'), 'accepted_user_id' => $user->id])->save();

            $this->audit->record('user.activated', $user, [
                'invitation_ulid' => $invitation->ulid,
                'role' => $invitation->role_name,
                'clearance' => $invitation->clearance->value,
            ], Actor::user($user->id), Classification::Internal);

            return $user;
        });
    }
}
