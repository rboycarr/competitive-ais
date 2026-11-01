<?php

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Actions\InvitationService;
use Modules\Identity\Http\Requests\AcceptInvitationRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Accept-invitation pages (P1-10). Unknown, malformed, used, revoked and expired tokens all get the same 404 page.
 */
class InvitationController
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $token): Response|HttpResponse
    {
        $invitation = $this->invitations->findOpen($token);
        if ($invitation === null) {
            return $this->unavailable($request);
        }

        return Inertia::render('Identity/AcceptInvitation', [
            'email' => $invitation->email,
            'expiresAt' => $invitation->expires_at->toIso8601ZuluString(),
            'token' => $token,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse|HttpResponse
    {
        $user = $this->invitations->accept($token, (string) $request->validated('name'), (string) $request->validated('password'));
        if ($user === null) {
            return $this->unavailable($request);
        }

        return redirect()->route('invitations.accepted');
    }

    public function accepted(): Response
    {
        return Inertia::render('Identity/InvitationAccepted');
    }

    private function unavailable(Request $request): HttpResponse
    {
        return Inertia::render('Identity/InvitationUnavailable')->toResponse($request)->setStatusCode(404);
    }
}
