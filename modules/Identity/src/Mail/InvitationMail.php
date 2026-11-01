<?php

namespace Modules\Identity\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation email (P1-10). Carries the only copy of the token; plain text, no tracking, no remote content.
 */
class InvitationMail extends Mailable
{
    public function __construct(
        public readonly string $token,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your invitation to Competitive-AIS');
    }

    public function content(): Content
    {
        return new Content(text: 'identity::mail.invitation', with: [
            'url' => route('invitations.show', ['token' => $this->token]),
            'expiresAt' => $this->expiresAt->format('Y-m-d H:i').' UTC',
        ]);
    }
}
