<?php

namespace Modules\Evidence\Lookup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Evidence\Contracts\EvidenceProvenance;
use Modules\Evidence\Contracts\EvidenceTrace;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;

/** EvidenceProvenance over the evidence tables (P1-45). */
class EloquentEvidenceProvenance implements EvidenceProvenance
{
    public function __construct(private readonly Gate $gate) {}

    public function traces(Authenticatable $viewer, array $ids): array
    {
        if ($ids === [] || ! $this->gate->forUser($viewer)->allows('evidence.view')) {
            return [];
        }

        return EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, $this->gate)->whereIn('id', $ids)
            ->with(['blob', 'custodyEvents' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id')])->get()
            ->mapWithKeys(fn (EvidenceAsset $a): array => [$a->id => new EvidenceTrace(
                id: $a->id,
                ulid: $a->ulid,
                title: $a->title,
                status: $a->status,
                classification: $a->classification->value,
                capturedAt: CarbonImmutable::instance($a->captured_at),
                sha256: $a->blob?->sha256,
                sizeBytes: $a->blob?->size_bytes,
                detectedMime: $a->blob?->detected_mime,
                integrityStatus: $a->blob?->integrity_status,
                sourceId: $a->source_id,
                sourcePolicyId: $a->source_policy_id,
                uploadedBy: $a->uploaded_by,
                custody: array_values($a->custodyEvents->map(fn (EvidenceCustodyEvent $e): array => [
                    'event' => $e->event, 'actorId' => $e->actor_id, 'occurredAt' => CarbonImmutable::instance($e->occurred_at),
                ])->all()),
            )])->all();
    }
}
