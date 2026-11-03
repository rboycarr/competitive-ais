<?php

namespace Modules\Sources\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Sources\Models\Source;
use Modules\Sources\Models\SourceStatusChange;

/**
 * Suspending, reinstating and retiring a source (P1-31b, docs/04 §2; PO decisions O31, 2026-11-03):
 * - source.manage holders who can see the source (hidden sources are 404);
 * - suspend: approved → suspended, at once; nobody may use the source while it is suspended;
 * - reinstate: suspended → policy_review, so a director who did not propose the source approves a new policy version
 *   (SourcePolicyService) before it is used again;
 * - retire: any status but retired → retired; final;
 * - every change has a reason code and a note, kept in the append-only source_status_changes table;
 * - the source row is locked first and its status checked again, so changes to one source run one at a time;
 * - audited in the same transaction, with codes only (the note stays in the history table).
 */
class SourceLifecycleService
{
    private const MAX_NOTE = 10000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    public function change(Authenticatable $actor, Source $source, mixed $action, mixed $reason, mixed $note): SourceStatusChange
    {
        if (! $this->gate->forUser($actor)->allows('source.manage')) {
            throw new AuthorizationException('You are not allowed to suspend, reinstate or retire sources.');
        }
        $step = is_string($action) ? (SourceStatusChange::ACTIONS[$action] ?? null) : null;
        if ($step === null) {
            throw ValidationException::withMessages(['action' => 'Unknown change.']);
        }
        if (! in_array($reason, $step['reasons'], true)) {
            throw ValidationException::withMessages(['reason' => 'Choose one of the reasons offered for this change.']);
        }
        $note = is_string($note) ? trim($note) : '';
        if ($note === '' || mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Say what happened, in at most '.self::MAX_NOTE.' characters.']);
        }
        $actorId = (int) $actor->getAuthIdentifier();
        $action = (string) $action; // both checked above
        $reason = (string) $reason;

        return $this->db->transaction(function () use ($actor, $actorId, $source, $action, $step, $reason, $note): SourceStatusChange {
            $source = Source::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
            $this->access->ensureVisible($actor, $source);
            if (! in_array($source->status, $step['from'], true)) {
                throw ValidationException::withMessages(['action' => match ($action) {
                    'suspend' => 'Only an approved source can be suspended.',
                    'reinstate' => 'Only a suspended source can be reinstated.',
                    default => 'This source is already retired.',
                }]);
            }

            $change = new SourceStatusChange;
            $change->forceFill([
                'source_id' => $source->id,
                'action' => $action,
                'from_status' => $source->status,
                'to_status' => $step['to'],
                'reason' => $reason,
                'note' => $note,
                'changed_by' => $actorId,
                'changed_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $source->status = $step['to'];
            $source->save();
            $this->audit->record(match ($action) {
                'suspend' => 'source.suspended',
                'reinstate' => 'source.reinstated',
                default => 'source.retired',
            }, $source, ['from_status' => $change->from_status, 'reason' => $reason], Actor::user($actorId));

            return $change;
        });
    }
}
