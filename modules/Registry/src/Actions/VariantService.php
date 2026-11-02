<?php

namespace Modules\Registry\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Registry\Access\ProductAccess;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductVariant;
use Modules\Registry\Support\Names;

/**
 * Product variants (P1-22b, docs/03 Registry):
 * - adding and editing need product.manage and the product visible to the actor (ProductAccess), checked again under
 *   a shared lock on the product row;
 * - the SKU is in the registry name normal form and unique per product, ignoring case and accents (the UNIQUE index);
 *   a GTIN has 8, 12, 13 or 14 digits and a valid check digit; the pack size is positive with at most four decimals;
 * - every change is audited with the product's audit classification.
 */
class VariantService
{
    private const FIELDS = ['sku', 'gtin', 'name', 'pack_size', 'pack_unit', 'pack_count', 'status'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly ProductAccess $products,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array<string, ?string>  $data  sku, gtin, name, pack_size, pack_unit, pack_count, status
     */
    public function add(Authenticatable $actor, Product $product, array $data): ProductVariant
    {
        $this->assertMayManage($actor);
        $this->products->ensureVisible($actor, $product);
        $attributes = $this->attributes($data);

        try {
            return $this->db->transaction(function () use ($actor, $product, $attributes): ProductVariant {
                $locked = $this->lockVisible($actor, $product);
                $variant = new ProductVariant;
                $variant->forceFill($attributes + ['ulid' => (string) Str::ulid(), 'product_id' => $locked->id])->save();
                $this->audit->record('variant.created', $variant, ['product' => $locked->ulid], $this->actor($actor), $this->classification($locked));

                return $variant;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->skuTaken();
        }
    }

    /**
     * @param  array<string, ?string>  $data  sku, gtin, name, pack_size, pack_unit, pack_count, status
     */
    public function update(Authenticatable $actor, Product $product, ProductVariant $variant, array $data): void
    {
        $this->assertMayManage($actor);
        $this->products->ensureVisible($actor, $product);
        if ($variant->product_id !== $product->id) {
            abort(404);
        }
        $attributes = $this->attributes($data);

        try {
            $this->db->transaction(function () use ($actor, $product, $variant, $attributes): void {
                $owner = $this->lockVisible($actor, $product);
                $locked = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->first() ?? abort(404);
                $locked->forceFill($attributes);
                $changed = array_values(array_intersect(self::FIELDS, array_keys($locked->getDirty())));
                if ($changed === []) {
                    return;
                }
                $locked->save();
                $this->audit->record('variant.updated', $locked, ['fields' => $changed], $this->actor($actor), $this->classification($owner));
                $variant->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->skuTaken();
        }
    }

    /** GS1 check digit (GTIN-8, -12, -13, -14). */
    public static function isValidGtin(string $gtin): bool
    {
        if (preg_match('/^(\d{8}|\d{12,14})$/', $gtin) !== 1) {
            return false;
        }
        $digits = array_map('intval', str_split($gtin));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $sku = Names::normalize((string) ($data['sku'] ?? ''));
        if ($sku === '' || mb_strlen($sku) > 100) {
            throw ValidationException::withMessages(['sku' => 'A SKU is 1 to 100 characters.']);
        }
        $gtin = preg_replace('/\s+/', '', (string) ($data['gtin'] ?? '')) ?? '';
        if ($gtin !== '' && ! self::isValidGtin($gtin)) {
            throw ValidationException::withMessages(['gtin' => 'A GTIN has 8, 12, 13 or 14 digits and a valid check digit.']);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A name is 1 to 255 characters.']);
        }
        $size = trim((string) ($data['pack_size'] ?? ''));
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/', $size, $m) !== 1 || ($size = (ltrim($m[1], '0') ?: '0').'.'.str_pad($m[2] ?? '', 4, '0')) === '0.0000') {
            throw ValidationException::withMessages(['pack_size' => 'A pack size is a positive number with at most four decimals.']);
        }
        $unit = (string) ($data['pack_unit'] ?? '');
        if (! in_array($unit, ProductVariant::UNITS, true)) {
            throw ValidationException::withMessages(['pack_unit' => 'Unknown unit.']);
        }
        $count = (string) ($data['pack_count'] ?? '1');
        if (preg_match('/^\d{1,5}$/', $count) !== 1 || (int) $count < 1 || (int) $count > 10000) {
            throw ValidationException::withMessages(['pack_count' => 'A pack count is a whole number from 1 to 10,000.']);
        }
        $status = $data['status'] ?? 'active';
        if (! in_array($status, ProductVariant::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }

        return [
            'sku' => $sku, 'gtin' => $gtin === '' ? null : $gtin, 'name' => $name,
            'pack_size' => $size, 'pack_unit' => $unit, 'pack_count' => (int) $count, 'status' => $status,
        ];
    }

    private function lockVisible(Authenticatable $actor, Product $product): Product
    {
        $locked = Product::query()->whereKey($product->id)->sharedLock()->first() ?? abort(404);
        $this->products->ensureVisible($actor, $locked);

        return $locked;
    }

    private function classification(Product $product): Classification
    {
        return ProductAccess::auditClassification($product, $product->competitor_id === null ? null : Competitor::query()->find($product->competitor_id));
    }

    private function skuTaken(): ValidationException
    {
        return ValidationException::withMessages(['sku' => 'This product already has a variant with this SKU.']);
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
