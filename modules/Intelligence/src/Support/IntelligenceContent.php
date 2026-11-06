<?php

namespace Modules\Intelligence\Support;

use Carbon\CarbonInterface;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;

/**
 * An item's content as one SHA-256 (docs/02 §3.6 `content_hash`): title, summary, type, classification, impact, dates,
 * subjects, and every claim (withdrawn ones too: what the reviewer saw) with its ratings, status and citations. A
 * review binds to it, and publication (P1-44) recomputes it under the item's row lock: any content change, however
 * made, makes an earlier approval stale. Canonical JSON, lists in a fixed order, read fresh from the database (call it
 * holding the item's lock); "v" is the format, so a later format can never match an earlier hash by accident.
 */
final class IntelligenceContent
{
    public static function hash(IntelligenceItem $item): string
    {
        $date = fn (?CarbonInterface $d): ?string => $d?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $sorted = function (array $ids): array {
            sort($ids);

            return $ids;
        };
        $claims = $item->claims()->with('evidenceLinks')->orderBy('position')->orderBy('id')->get();

        $content = [
            'v' => 1,
            'title' => $item->title,
            'summary' => $item->summary,
            'item_type' => $item->item_type,
            'classification' => $item->classification->value,
            'high_impact' => $item->high_impact,
            'observed_at' => $date($item->observed_at),
            'published_at' => $date($item->published_at),
            'collected_at' => $date($item->collected_at),
            'competitors' => $sorted($item->competitorSubjects()->pluck('competitor_id')->map(fn ($id): int => (int) $id)->all()),
            'products' => $sorted($item->productSubjects()->pluck('product_id')->map(fn ($id): int => (int) $id)->all()),
            'markets' => $sorted($item->marketSubjects()->pluck('market_id')->map(fn ($id): int => (int) $id)->all()),
            'claims' => $claims->map(fn (IntelligenceClaim $c): array => [
                'ulid' => $c->ulid,
                'position' => $c->position,
                'statement' => $c->statement,
                'claim_type' => $c->claim_type,
                'source_reliability' => $c->source_reliability,
                'info_credibility' => (int) $c->info_credibility,
                'status' => $c->status,
                'evidence' => $c->evidenceLinks->sortBy([['evidence_asset_id', 'asc'], ['relation', 'asc']])->values()
                    ->map(fn (ClaimEvidenceLink $l): array => [
                        'asset' => $l->evidence_asset_id,
                        'relation' => $l->relation,
                        'locator' => $l->locator,
                        'excerpt' => $l->excerpt,
                    ])->all(),
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
