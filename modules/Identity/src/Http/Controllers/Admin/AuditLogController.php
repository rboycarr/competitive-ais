<?php

namespace Modules\Identity\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Http\Requests\Admin\AuditSearchRequest;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditEvent;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;

/**
 * Admin › Audit log (P1-16, audit.view): a read-only search of the audit trail. Rows are filtered by AccessScope
 * like any record (classification within clearance; restricted rows need a grant), newest first, 50 per page.
 * Network data (IP address) is not shown. Every search is itself audited (`audit.searched`).
 */
class AuditLogController
{
    private const PER_PAGE = 50;

    public function index(AuditSearchRequest $request, AuditActions $actions, AuditLogger $audit, DatabaseManager $db): Response
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);
        $filters = array_filter($request->safe()->except('cursor'), fn ($value) => is_string($value) && $value !== '');

        $query = AuditEvent::query()->visibleTo($viewer);
        if (isset($filters['actor'])) {
            // An unknown ULID matches nothing rather than being ignored.
            $query->where('actor_id', User::query()->where('ulid', strtoupper($filters['actor']))->value('id') ?? 0);
        }
        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['subject'])) {
            $query->where('subject_ulid', strtoupper($filters['subject']));
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('occurred_at', $operator, CarbonImmutable::parse($filters[$key])->utc()->format('Y-m-d H:i:s.u'));
            }
        }

        $page = $query->orderByDesc('id')->cursorPaginate(self::PER_PAGE)->withQueryString();
        $events = collect($page->items());
        $actors = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique()->values())->pluck('name', 'id');

        // Filter names only, never their values (TASK-0027 #3: both keys always present).
        $db->transaction(fn () => $audit->record('audit.searched', null, [
            'filters' => array_keys($filters),
            'next_page' => $request->filled('cursor'),
        ], Actor::user($viewer->id), Classification::Internal));

        return Inertia::render('Identity/Admin/AuditLog', [
            'events' => $events->map(fn (AuditEvent $event): array => [
                'ulid' => $event->ulid,
                'occurredAt' => $event->occurred_at->toIso8601ZuluString('microsecond'),
                'actor' => $event->actor_id !== null ? ($actors[$event->actor_id] ?? 'Unknown user') : $event->actor_type,
                'action' => $event->action,
                'subjectType' => $event->subject_type !== null ? class_basename($event->subject_type) : null,
                'subjectUlid' => $event->subject_ulid,
                'classification' => $event->classification->value,
                'requestId' => $event->request_id,
                'context' => $event->context ?? (object) [],
            ])->all(),
            'nextUrl' => $page->nextPageUrl(),
            'previousUrl' => $page->previousPageUrl(),
            'filters' => $filters,
            'actions' => $actions->names(),
            // The actor filter lists every account, inactive ones too (past actions stay searchable), and only for
            // viewers who may see the user directory anyway (TASK-0027 #2).
            'users' => $viewer->hasPermissionTo('user.view')
                ? User::query()->orderBy('name')->get(['id', 'ulid', 'name'])
                    ->map(fn (User $user): array => ['ulid' => $user->ulid, 'name' => $user->name])->all()
                : [],
        ]);
    }
}
