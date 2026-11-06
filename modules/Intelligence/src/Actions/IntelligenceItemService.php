<?php

namespace Modules\Intelligence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\IntelligenceItemCompetitor;
use Modules\Intelligence\Models\IntelligenceItemMarket;
use Modules\Intelligence\Models\IntelligenceItemProduct;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\ProductLookup;
use Modules\Registry\Contracts\ProductSummary;
use Throwable;

/**
 * Writing an intelligence item (P1-40, docs/03 Intelligence, docs/02 §3.6):
 * - intelligence.create to write; an item starts as a `draft`, version 1, with the writer as author and owner;
 * - content (title, summary, type, classification, impact, dates, subjects) changes only while the item is editable
 *   (draft, changes requested), only by its author or owner who can see it, and only from the version they read
 *   (`version`, checked under the item's row lock): a stale form never overwrites a newer edit. Every change raises the
 *   version, so approvals of earlier versions never count (P1-44);
 * - subjects are competitors the writer can see, products the writer can see (a competitor's product brings its
 *   competitor in, so an item about a product is scoped like one about its maker) and markets; at least one, at most
 *   MAX_SUBJECTS of each. A competitor is named only while it is not archived, checked under a share lock on its row
 *   (CompetitorLookup::lockUnarchived), which archiving waits for. A new competitor or market becomes a scope of every
 *   piece of evidence the item's claims cite (P1-36b); removing a subject leaves those scopes;
 * - nobody writes an item they could not read back (classification and every subject, all-of), and lowering a
 *   classification needs intelligence.publish (docs/07 §3);
 * - the summary is Markdown source (at most MAX_SUMMARY characters), shown only through SafeMarkdown;
 * - dates (observed, published by the source, collected) are optional, in UTC, never in the future;
 * - audited in the same transaction with codes only (never the title, summary or statements).
 */
class IntelligenceItemService
{
    public const MAX_SUBJECTS = 20;

    public const MAX_SUMMARY = 20000;

    /** Fields an edit may change, as audited; `subjects` covers competitors, products and markets. */
    private const FIELDS = ['title', 'summary', 'item_type', 'classification', 'high_impact', 'observed_at', 'published_at', 'collected_at'];

    private const DATES = ['observed_at', 'published_at', 'collected_at'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly CompetitorLookup $competitors,
        private readonly ProductLookup $products,
        private readonly MarketLookup $markets,
        private readonly EvidenceLinking $evidence,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, summary?, item_type, classification, high_impact?, observed_at?, published_at?, collected_at?, competitors?, products?, markets? (ULIDs)
     */
    public function create(Authenticatable $actor, array $data): IntelligenceItem
    {
        $this->assertMayWrite($actor);
        $actorId = (int) $actor->getAuthIdentifier();
        $item = new IntelligenceItem;
        $item->forceFill($this->attributes($data) + [
            'ulid' => (string) Str::ulid(),
            'status' => 'draft',
            'version' => 1,
            'author_id' => $actorId,
            'owner_id' => $actorId,
        ]);
        $subjects = $this->subjects($actor, $data);
        $this->assertReadable($actor, $item, $subjects);

        return $this->db->transaction(function () use ($actor, $item, $subjects): IntelligenceItem {
            $this->lockCompetitors($subjects['competitors']);
            $item->save();
            $this->addSubjects($item, $subjects);
            $this->audit->record('intelligence.created', $item, [
                'item_type' => $item->item_type,
                'high_impact' => $item->high_impact,
                'competitors' => count($subjects['competitors']),
                'products' => count($subjects['products']),
                'markets' => count($subjects['markets']),
            ], Actor::user((int) $actor->getAuthIdentifier()));

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data  as for create(), plus version (the one the form was read at)
     */
    public function update(Authenticatable $actor, IntelligenceItem $item, array $data): IntelligenceItem
    {
        $this->assertMayWrite($actor);
        $item->ensureSeenBy($actor, $this->gate, $this->access);
        $attributes = $this->attributes($data);
        $subjects = $this->subjects($actor, $data);

        return $this->db->transaction(function () use ($actor, $item, $data, $attributes, $subjects): IntelligenceItem {
            $locked = IntelligenceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->ensureSeenBy($actor, $this->gate, $this->access); // as it is now
            self::assertMayEdit($actor, $locked, $data['version'] ?? null);

            $before = $locked->classification;
            $previous = ['competitors' => $locked->competitorIds(), 'products' => $locked->productIds(), 'markets' => $locked->marketIds()];
            // A form only lists what its writer can see: saving it would silently drop a subject hidden from them.
            if (count($this->competitors->summaries($actor, $previous['competitors'])) !== count($previous['competitors'])
                || count($this->products->summaries($actor, $previous['products'])) !== count($previous['products'])) {
                throw ValidationException::withMessages(['subjects' => 'This item is about a competitor or product you cannot see, so you cannot edit it.']);
            }
            $locked->forceFill($attributes);
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $locked->isDirty($field)));
            $subjectsChanged = false;
            foreach ($subjects as $kind => $ids) {
                $subjectsChanged = $subjectsChanged || self::sorted($ids) !== self::sorted($previous[$kind]);
            }
            if ($changed === [] && ! $subjectsChanged) {
                return $locked; // nothing to record; the version stays
            }
            if ($before->rank() > $locked->classification->rank() && ! $this->gate->forUser($actor)->allows('intelligence.publish')) {
                throw ValidationException::withMessages(['classification' => 'Lowering a classification is a director decision.']);
            }
            $this->assertReadable($actor, $locked, $subjects);
            $this->lockCompetitors(array_values(array_diff($subjects['competitors'], $previous['competitors'])));

            $locked->version++;
            $locked->save();
            if ($subjectsChanged) {
                $locked->competitorSubjects()->whereNotIn('competitor_id', $subjects['competitors'])->delete();
                $locked->productSubjects()->whereNotIn('product_id', $subjects['products'])->delete();
                $locked->marketSubjects()->whereNotIn('market_id', $subjects['markets'])->delete();
                $this->addSubjects($locked, [
                    'competitors' => array_values(array_diff($subjects['competitors'], $previous['competitors'])),
                    'products' => array_values(array_diff($subjects['products'], $previous['products'])),
                    'markets' => array_values(array_diff($subjects['markets'], $previous['markets'])),
                ]);
                $changed[] = 'subjects';
                if (array_diff($subjects['competitors'], $previous['competitors']) !== [] || array_diff($subjects['markets'], $previous['markets']) !== []) {
                    $this->inheritToCitedEvidence($actor, $locked, $subjects);
                }
            }
            // The higher of the two classifications: whoever could read the item before or after may see the event.
            $this->audit->record('intelligence.updated', $locked, ['fields' => $changed, 'version' => $locked->version],
                Actor::user((int) $actor->getAuthIdentifier()), $before->rank() > $locked->classification->rank() ? $before : $locked->classification);
            $item->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Content may change only while the item is editable, by its author or owner, from the current version (the
     * caller holds the item's row lock). Shared with the claim service.
     */
    public static function assertMayEdit(Authenticatable $actor, IntelligenceItem $locked, mixed $version): void
    {
        $actorId = (int) $actor->getAuthIdentifier();
        if (! in_array($actorId, [$locked->author_id, $locked->owner_id], true)) {
            throw new AuthorizationException('Only the author or owner may edit this item.');
        }
        if (! $locked->isEditable()) {
            throw ValidationException::withMessages(['item' => 'This item can no longer be edited.']);
        }
        if (! is_numeric($version) || (int) $version !== $locked->version) {
            throw ValidationException::withMessages(['version' => 'Someone changed this item since you opened it. Reload it and make your change again.']);
        }
    }

    /**
     * P1-36b: evidence the item's claims cite (withdrawn claims included: their citations stay on record) inherits a
     * new competitor or market as a scope, under the asset rows' locks (after the item's, in ID order). Evidence the
     * editor saw must stay visible to them, or the change is refused (as when citing it).
     *
     * @param  array{competitors: list<int>, products: list<int>, markets: list<int>}  $subjects
     */
    private function inheritToCitedEvidence(Authenticatable $actor, IntelligenceItem $locked, array $subjects): void
    {
        $assetIds = array_values(array_map('intval', ClaimEvidenceLink::query()
            ->whereIn('intelligence_claim_id', $locked->claims()->select('id'))->distinct()->orderBy('evidence_asset_id')->pluck('evidence_asset_id')->all()));
        if ($assetIds === []) {
            return;
        }
        $seen = $this->evidence->lockSeen($actor, $assetIds);
        foreach ($assetIds as $id) {
            $this->evidence->inherit($actor, $id, $locked->ulid, $subjects['competitors'], $subjects['markets']);
        }
        if (array_diff($seen, $this->evidence->lockSeen($actor, $assetIds)) !== []) {
            throw ValidationException::withMessages(['subjects' => 'Evidence this item cites would take these subjects as scopes, and you could no longer see it.']);
        }
    }

    private function assertMayWrite(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('intelligence.create')) {
            throw new AuthorizationException('You are not allowed to write intelligence.');
        }
    }

    /**
     * The writer must be able to see the item as it will be: its classification and every subject (all-of).
     *
     * @param  array{competitors: list<int>, products: list<int>, markets: list<int>}  $subjects
     */
    private function assertReadable(Authenticatable $actor, IntelligenceItem $item, array $subjects): void
    {
        $probe = new IntelligenceItem;
        $probe->forceFill(['classification' => $item->classification]);
        $probe->setRelation('competitorSubjects', new Collection(array_map(fn (int $id): IntelligenceItemCompetitor => (new IntelligenceItemCompetitor)->forceFill(['competitor_id' => $id]), $subjects['competitors'])));
        $probe->setRelation('marketSubjects', new Collection(array_map(fn (int $id): IntelligenceItemMarket => (new IntelligenceItemMarket)->forceFill(['market_id' => $id]), $subjects['markets'])));
        if (! $this->access->allows($actor, $probe)) {
            throw ValidationException::withMessages(['classification' => 'At this classification, you need access to every competitor and market the item is about.']);
        }
    }

    /** @param  list<int>  $ids */
    private function lockCompetitors(array $ids): void
    {
        if (count($this->competitors->lockUnarchived($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['competitors' => 'An archived competitor cannot be the subject of new intelligence.']);
        }
    }

    /** @param  array{competitors: list<int>, products: list<int>, markets: list<int>}  $subjects */
    private function addSubjects(IntelligenceItem $item, array $subjects): void
    {
        foreach ($subjects['competitors'] as $id) {
            (new IntelligenceItemCompetitor)->forceFill(['intelligence_item_id' => $item->id, 'competitor_id' => $id])->save();
        }
        foreach ($subjects['products'] as $id) {
            (new IntelligenceItemProduct)->forceFill(['intelligence_item_id' => $item->id, 'product_id' => $id])->save();
        }
        foreach ($subjects['markets'] as $id) {
            (new IntelligenceItemMarket)->forceFill(['intelligence_item_id' => $item->id, 'market_id' => $id])->save();
        }
    }

    /**
     * Subjects by ULID: competitors and products the writer can see, markets that exist; a product's competitor is
     * added to the competitors. At least one subject; at most MAX_SUBJECTS of each kind; duplicates ignored.
     *
     * @param  array<string, mixed>  $data
     * @return array{competitors: list<int>, products: list<int>, markets: list<int>}
     */
    private function subjects(Authenticatable $actor, array $data): array
    {
        $ids = ['competitors' => [], 'products' => [], 'markets' => []];
        foreach (array_keys($ids) as $field) {
            $ulids = $data[$field] ?? [];
            if (! is_array($ulids) || count($ulids) > self::MAX_SUBJECTS) {
                throw ValidationException::withMessages([$field => 'Choose at most '.self::MAX_SUBJECTS.'.']);
            }
            foreach ($ulids as $ulid) {
                $found = ! is_string($ulid) || ! Str::isUlid($ulid) ? null : match ($field) {
                    'competitors' => $this->competitors->findVisible($actor, $ulid),
                    'products' => $this->products->findVisible($actor, $ulid),
                    'markets' => $this->markets->find($ulid),
                };
                if ($found === null) {
                    throw ValidationException::withMessages([$field => match ($field) {
                        'competitors' => 'Choose competitors you can see.',
                        'products' => 'Choose products you can see.',
                        'markets' => 'Choose known markets.',
                    }]);
                }
                $ids[$field][] = $found->id;
                if ($found instanceof ProductSummary && $found->competitorId !== null) {
                    $ids['competitors'][] = $found->competitorId;
                }
            }
        }
        $ids = array_map(fn (array $list): array => self::sorted($list), $ids);
        if ($ids['competitors'] === [] && $ids['products'] === [] && $ids['markets'] === []) {
            throw ValidationException::withMessages(['subjects' => 'Choose at least one competitor, product or market the item is about.']);
        }
        if (count($ids['competitors']) > self::MAX_SUBJECTS) {
            throw ValidationException::withMessages(['competitors' => 'Choose at most '.self::MAX_SUBJECTS.', counting the competitors of the products.']);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $title = self::text($data, 'title', 255) ?? throw ValidationException::withMessages(['title' => 'A title is required.']);
        $type = $data['item_type'] ?? null;
        if (! in_array($type, IntelligenceItem::TYPES, true)) {
            throw ValidationException::withMessages(['item_type' => 'Choose a known item type.']);
        }
        $classification = is_string($data['classification'] ?? null) ? Classification::tryFrom($data['classification']) : null;
        if ($classification === null) {
            throw ValidationException::withMessages(['classification' => 'Choose a known classification.']);
        }
        $attributes = [
            'title' => (string) preg_replace('/\s+/u', ' ', $title),
            'summary' => self::text($data, 'summary', self::MAX_SUMMARY, multiline: true) ?? '',
            'item_type' => $type,
            'classification' => $classification,
            'high_impact' => filter_var($data['high_impact'] ?? false, FILTER_VALIDATE_BOOL),
        ];
        foreach (self::DATES as $field) {
            $attributes[$field] = self::date($data[$field] ?? null, $field);
        }

        return $attributes;
    }

    /**
     * Trimmed text of at most $max characters, or null when empty; malformed UTF-8 is refused. Single-line text has
     * its whitespace collapsed by the caller; multi-line text keeps its line breaks (Windows ones normalized).
     *
     * @param  array<string, mixed>  $data
     */
    public static function text(array $data, string $field, int $max, bool $multiline = false): ?string
    {
        $value = $data[$field] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages([$field => 'Give text.']);
        }
        if ($value !== null && ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages([$field => 'This text contains characters that cannot be read.']);
        }
        $value = trim((string) $value);
        if ($multiline) {
            $value = str_replace("\r\n", "\n", $value);
        }
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => "Use at most $max characters."]);
        }

        return $value === '' ? null : $value;
    }

    /** An optional date and time in UTC, not in the future (a minute of clock skew allowed). */
    private static function date(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        try {
            $at = is_string($value) ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
        } catch (Throwable) {
            $at = null;
        }
        if ($at === null || $at->isAfter(CarbonImmutable::now('UTC')->addMinute())) {
            throw ValidationException::withMessages([$field => 'Give a date and time in UTC that is not in the future.']);
        }

        return $at;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
