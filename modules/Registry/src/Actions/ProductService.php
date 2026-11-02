<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Access\ProductAccess;
use Modules\Registry\Models\Brand;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductCategory;
use Modules\Registry\Support\Dates;

/**
 * Products (P1-22b, docs/03 Registry):
 * - creating and editing need product.manage and a product the actor can see (ProductAccess, PO decision O25); the
 *   owner (ours, or a competitor the actor can see) is fixed at creation and checked again under a shared lock;
 * - the brand must belong to the same owner (also enforced by the composite foreign key) and the category must exist,
 *   both read under shared locks so a concurrent removal waits;
 * - nobody classifies a product above what they can see, and lowering a classification needs intelligence.publish
 *   (docs/07 §3), as for competitors;
 * - every change is audited with the higher classification of the product and its competitor.
 */
class ProductService
{
    /** Fields an edit may change; the owner never changes. */
    private const FIELDS = ['name', 'brand_id', 'category_id', 'status', 'launched_on', 'discontinued_on', 'classification', 'description'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly ProductAccess $products,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array<string, ?string>  $data  competitor (ULID; empty for ours), name, brand (ULID), category (ID), status,
     *                                        launched_on, discontinued_on, classification, description
     */
    public function create(Authenticatable $actor, array $data): Product
    {
        $this->assertMayManage($actor);
        $ownerUlid = strtoupper(trim((string) ($data['competitor'] ?? '')));
        $owner = $ownerUlid === '' ? null : (Competitor::query()->visibleTo($actor)->where('ulid', $ownerUlid)->first()
            ?? throw ValidationException::withMessages(['competitor' => 'Choose a competitor you can see.']));

        return $this->db->transaction(function () use ($actor, $data, $owner): Product {
            $owner = $owner === null ? null : $this->lockVisible($actor, $owner);
            $product = new Product;
            $product->forceFill([
                'ulid' => (string) Str::ulid(),
                'owner_kind' => $owner === null ? 'own' : 'competitor',
                'competitor_id' => $owner?->id,
                'created_by' => $actor->getAuthIdentifier(),
            ] + $this->attributes($data, $owner));
            $this->assertVisibleAfterChange($actor, $product);
            $product->save();
            $this->audit->record('product.created', $product, array_filter([
                'owner' => $product->owner_kind, 'competitor' => $owner?->ulid,
            ]) + ['status' => $product->status, 'classification' => $product->classification->value], $this->actor($actor), ProductAccess::auditClassification($product, $owner));

            return $product;
        });
    }

    /**
     * @param  array<string, ?string>  $data  name, brand, category, status, launched_on, discontinued_on, classification, description
     */
    public function update(Authenticatable $actor, Product $product, array $data): void
    {
        $this->assertMayManage($actor);
        $this->products->ensureVisible($actor, $product);

        $this->db->transaction(function () use ($actor, $product, $data): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $owner = $locked->competitor_id === null ? null : $this->lockVisible($actor, Competitor::query()->findOrFail($locked->competitor_id));
            $this->products->ensureVisible($actor, $locked);
            $before = $locked->classification;
            $locked->forceFill($this->attributes($data, $owner));
            $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
            if ($changed === []) {
                return;
            }
            if ($before->rank() > $locked->classification->rank() && ! $this->gate->forUser($actor)->allows('intelligence.publish')) {
                throw ValidationException::withMessages(['classification' => 'Only a CI Director can lower a classification.']);
            }
            $this->assertVisibleAfterChange($actor, $locked);

            $locked->save();
            $this->audit->record('product.updated', $locked, ['fields' => $changed], $this->actor($actor), ProductAccess::auditClassification($locked, $owner));
            $product->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Inside the write transaction: the brand and category are read under shared locks.
     *
     * @param  array<string, ?string>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Competitor $owner): array
    {
        $text = fn (?string $value): ?string => ($value = trim((string) $value)) === '' ? null : $value;
        $name = $text($data['name'] ?? null) ?? throw ValidationException::withMessages(['name' => 'A name is required.']);
        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A name is at most 255 characters.']);
        }
        $status = $data['status'] ?? 'active';
        if (! in_array($status, Product::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        $launched = Dates::parse($data['launched_on'] ?? null, 'launched_on');
        $discontinued = Dates::parse($data['discontinued_on'] ?? null, 'discontinued_on');
        if ($launched !== null && $discontinued !== null && $discontinued->lt($launched)) {
            throw ValidationException::withMessages(['discontinued_on' => 'A product cannot be discontinued before its launch.']);
        }

        $brandUlid = strtoupper(trim((string) ($data['brand'] ?? '')));
        $brand = $brandUlid === '' ? null : Brand::query()->where('ulid', $brandUlid)->sharedLock()->first();
        if ($brandUlid !== '' && ($brand === null || $brand->competitor_id !== $owner?->id)) {
            throw ValidationException::withMessages(['brand' => 'Choose one of the owner\'s brands.']);
        }
        $categoryId = trim((string) ($data['category'] ?? ''));
        $category = $categoryId === '' ? null : ProductCategory::query()->whereKey((int) $categoryId)->sharedLock()->first();
        if ($categoryId !== '' && $category === null) {
            throw ValidationException::withMessages(['category' => 'Choose an existing category.']);
        }

        return [
            'name' => preg_replace('/\s+/u', ' ', $name),
            'brand_id' => $brand?->id,
            'category_id' => $category?->id,
            'status' => $status,
            'launched_on' => $launched,
            'discontinued_on' => $discontinued,
            'classification' => Classification::tryFrom((string) ($data['classification'] ?? '')) ?? throw ValidationException::withMessages(['classification' => 'Unknown classification.']),
            'description' => $text($data['description'] ?? null),
        ];
    }

    private function lockVisible(Authenticatable $actor, Competitor $competitor): Competitor
    {
        $locked = Competitor::query()->whereKey($competitor->id)->sharedLock()->firstOrFail();
        $this->access->ensureVisible($actor, $locked);

        return $locked;
    }

    /** An actor may not create or leave a product they could not see themselves. */
    private function assertVisibleAfterChange(Authenticatable $actor, Product $product): void
    {
        if (! $this->access->allows($actor, $product)) {
            throw ValidationException::withMessages(['classification' => 'You cannot classify a product above what you can see.']);
        }
    }

    private function assertMayManage(Authenticatable $actor): void
    {
        if (! $this->gate->forUser($actor)->allows('product.manage')) {
            throw new AuthorizationException('You are not allowed to manage products.');
        }
    }

    private function actor(Authenticatable $actor): Actor
    {
        return Actor::user((int) $actor->getAuthIdentifier());
    }
}
