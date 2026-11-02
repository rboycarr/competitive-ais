<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Registry\Actions\VariantService;
use Modules\Registry\Models\Product;
use Modules\Registry\Models\ProductVariant;

/** Variants on a product's page (P1-22b); VariantService decides who may change what. */
class VariantController
{
    public function __construct(private readonly VariantService $variants) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        $this->variants->add($request->user() ?? abort(401), $product, $this->validated($request));

        return back()->with('status', 'Variant added.');
    }

    public function update(Request $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->variants->update($request->user() ?? abort(401), $product, $variant, $this->validated($request));

        return back()->with('status', 'Variant saved.');
    }

    /** @return array<string, ?string> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'gtin' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'pack_size' => ['required', 'string', 'max:20'],
            'pack_unit' => ['required', Rule::in(ProductVariant::UNITS)],
            'pack_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'status' => ['required', Rule::in(ProductVariant::STATUSES)],
        ]);

        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $validated);
    }
}
