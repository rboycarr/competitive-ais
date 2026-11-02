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
use Modules\Sources\Models\SourcePolicy;

/**
 * The policy review of a source (P1-31a, docs/04 §2, docs/03 Sources; PO decision O9: the CI Director decides):
 * - submit: the source's owner or proposer (source.propose) sends a `proposed` source to `policy_review`;
 * - decide: a source.approve holder (two-factor required, MfaRequirement) who is not the proposer records a new
 *   policy version: `approved` puts the source in force with that policy; `rejected` and `needs_info` return it to
 *   `proposed`, where it can be submitted again (PO decision 2026-11-03);
 * - every step locks the source row first, so steps on one source run one at a time, and checks the status again;
 *   a decision names the version it was based on, so a director never decides on a review that changed under them;
 * - HTTP fetch and headless browser sources are approved only with robots `allowed` (docs/04 §2);
 * - audited in the same transaction, with codes only (no terms, notes or URLs in the audit context).
 * The database holds the same rules (2026_11_16_000100 migration).
 */
class SourcePolicyService
{
    private const MAX_TEXT = 10000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
    ) {}

    public function submit(Authenticatable $actor, Source $source): Source
    {
        if (! $this->gate->forUser($actor)->allows('source.propose')) {
            throw new AuthorizationException('You are not allowed to submit sources for policy review.');
        }

        return $this->db->transaction(function () use ($actor, $source): Source {
            $source = $this->locked($actor, $source);
            if (! in_array((int) $actor->getAuthIdentifier(), [$source->owner_id, $source->proposed_by], true)) {
                throw new AuthorizationException("Only the source's owner or proposer can submit it for policy review.");
            }
            if ($source->status !== 'proposed') {
                throw ValidationException::withMessages(['source' => 'Only a proposed source can be submitted for policy review.']);
            }
            $source->status = 'policy_review';
            $source->save();
            $this->audit->record('source.submitted', $source, [], Actor::user((int) $actor->getAuthIdentifier()));

            return $source;
        });
    }

    /**
     * @param  array{decision: string, based_on_version: int, terms_url?: ?string, terms_summary?: ?string, terms_reviewed_on?: ?string, robots_decision: string, license?: ?string, allowed_cadence?: ?string, rate_budget_per_hour?: ?int, retention_days?: ?int, contains_personal_data: bool, personal_data_basis?: ?string, notes?: ?string}  $data
     */
    public function decide(Authenticatable $actor, Source $source, array $data): SourcePolicy
    {
        if (! $this->gate->forUser($actor)->allows('source.approve')) {
            throw new AuthorizationException('You are not allowed to decide source policies.');
        }
        $attributes = $this->attributes($data);
        $basedOn = $attributes['based_on_version'];
        unset($attributes['based_on_version']);
        $actorId = (int) $actor->getAuthIdentifier();

        return $this->db->transaction(function () use ($actor, $actorId, $source, $basedOn, $attributes): SourcePolicy {
            $source = $this->locked($actor, $source);
            if ($source->proposed_by === $actorId) {
                throw new AuthorizationException('You proposed this source, so another director must decide its policy.');
            }
            if ($source->status !== 'policy_review') {
                throw ValidationException::withMessages(['decision' => 'Only a source awaiting policy review can be decided.']);
            }
            $latest = (int) SourcePolicy::query()->where('source_id', $source->id)->max('version');
            if ($latest !== $basedOn) {
                throw ValidationException::withMessages(['decision' => "This source's policy changed while you were reviewing it; reload the page and review it again."]);
            }
            if ($attributes['decision'] === 'approved' && in_array($source->method, SourcePolicy::ROBOTS_BOUND_METHODS, true)
                && $attributes['robots_decision'] !== 'allowed') {
                throw ValidationException::withMessages(['robots_decision' => 'HTTP fetch and headless browser collection can only be approved where robots rules allow it.']);
            }

            $policy = new SourcePolicy;
            $policy->forceFill($attributes + [
                'source_id' => $source->id,
                'version' => $latest + 1,
                'proposed_by' => $source->proposed_by,
                'decided_by' => $actorId,
                'decided_at' => CarbonImmutable::now('UTC'),
            ])->save();

            if ($policy->decision === 'approved') {
                $source->status = 'approved';
                $source->current_policy_id = $policy->id;
            } else {
                $source->status = 'proposed';
            }
            $source->save();
            $this->audit->record('source.policy_decided', $source, [
                'decision' => $policy->decision,
                'version' => $policy->version,
                'robots_decision' => $policy->robots_decision,
                'contains_personal_data' => $policy->contains_personal_data,
            ], Actor::user($actorId));

            return $policy;
        });
    }

    /** The source row, locked for this transaction and visible to the actor (hidden sources are 404). */
    private function locked(Authenticatable $actor, Source $source): Source
    {
        $locked = Source::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
        $this->access->ensureVisible($actor, $locked);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $text = function (string $field, int $max = self::MAX_TEXT) use ($data): ?string {
            $value = trim((string) ($data[$field] ?? ''));
            if (mb_strlen($value) > $max) {
                throw ValidationException::withMessages([$field => "Use at most $max characters."]);
            }

            return $value === '' ? null : $value;
        };
        $number = function (string $field, int $max) use ($data): ?int {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (! is_int($value) || $value < 1 || $value > $max) {
                throw ValidationException::withMessages([$field => "Use a whole number from 1 to $max, or leave it empty."]);
            }

            return $value;
        };

        // Direct callers get validation errors too, never a type error (TASK-0059 #2).
        $decision = $data['decision'] ?? null;
        if (! in_array($decision, SourcePolicy::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Unknown decision.']);
        }
        $basedOn = $data['based_on_version'] ?? null;
        if (! is_int($basedOn) || $basedOn < 0) {
            throw ValidationException::withMessages(['decision' => 'The policy version this decision is based on is missing.']);
        }
        $robots = $data['robots_decision'] ?? null;
        if (! in_array($robots, SourcePolicy::ROBOTS, true)) {
            throw ValidationException::withMessages(['robots_decision' => 'Unknown robots decision.']);
        }
        $personal = $data['contains_personal_data'] ?? null;
        if (! is_bool($personal)) {
            throw ValidationException::withMessages(['contains_personal_data' => 'Say whether the source contains personal data.']);
        }
        foreach (['terms_url', 'terms_summary', 'terms_reviewed_on', 'license', 'allowed_cadence', 'personal_data_basis', 'notes'] as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                throw ValidationException::withMessages([$field => 'Give text.']);
            }
        }
        $termsUrl = $text('terms_url', 2048);
        if ($termsUrl !== null) {
            SourceService::host($termsUrl, 'terms_url', 'terms URL');
        }
        $reviewedOn = $text('terms_reviewed_on', 10);
        if ($reviewedOn !== null && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $reviewedOn, $day) !== 1
            || ! checkdate((int) $day[2], (int) $day[3], (int) $day[1]) || $reviewedOn > CarbonImmutable::today('UTC')->format('Y-m-d'))) {
            throw ValidationException::withMessages(['terms_reviewed_on' => 'Give the date the terms were reviewed (YYYY-MM-DD), not a future date.']);
        }
        $summary = $text('terms_summary');
        $notes = $text('notes');
        $basis = $personal ? $text('personal_data_basis') : null;

        $missing = match (true) {
            $decision === 'approved' && $summary === null => ['terms_summary' => 'An approval records a summary of the terms.'],
            $decision === 'approved' && $reviewedOn === null => ['terms_reviewed_on' => 'An approval records the date the terms were reviewed.'],
            $decision !== 'approved' && $notes === null => ['notes' => 'Say why, so the proposer knows what to change.'],
            $personal && $basis === null => ['personal_data_basis' => 'Give the lawful basis for collecting personal data from this source.'],
            default => null,
        };
        if ($missing !== null) {
            throw ValidationException::withMessages($missing);
        }

        return [
            'decision' => $decision,
            'based_on_version' => $basedOn,
            'terms_url' => $termsUrl,
            'terms_summary' => $summary,
            'terms_reviewed_on' => $reviewedOn,
            'robots_decision' => $robots,
            'license' => $text('license', 255),
            'allowed_cadence' => $text('allowed_cadence', 50),
            'rate_budget_per_hour' => $number('rate_budget_per_hour', 1000000),
            'retention_days' => $number('retention_days', 36500),
            'contains_personal_data' => $personal,
            'personal_data_basis' => $basis,
            'notes' => $notes,
        ];
    }
}
