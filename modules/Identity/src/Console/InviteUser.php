<?php

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Actions\InvitationService;
use Modules\Platform\Classification;

/**
 * Invites a user from the command line (P1-10): for the first super admin, and for operators until the admin
 * screens exist (P1-13). Acts as the system; audited as user.invited. The token goes only into the email.
 */
class InviteUser extends Command
{
    protected $signature = 'identity:invite {email} {--role= : Role name, e.g. super_admin or ci_analyst} {--clearance=internal : public, internal, confidential or restricted}';

    protected $description = 'Invite a user by email (no self-registration)';

    public function handle(InvitationService $invitations): int
    {
        $clearance = Classification::tryFrom((string) $this->option('clearance'));
        if ($clearance === null || ! is_string($this->option('role')) || $this->option('role') === '') {
            $this->components->error('Give --role and a valid --clearance (public, internal, confidential, restricted).');

            return self::FAILURE;
        }

        try {
            $invitation = $invitations->invite((string) $this->argument('email'), $this->option('role'), $clearance);
        } catch (ValidationException $e) {
            $this->components->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        }

        $this->components->info("Invitation {$invitation->ulid} sent to {$invitation->email}; it expires {$invitation->expires_at->format('Y-m-d H:i')} UTC.");

        return self::SUCCESS;
    }
}
