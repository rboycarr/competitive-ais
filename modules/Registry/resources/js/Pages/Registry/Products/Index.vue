<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductForm, { type BrandOption, type CategoryOption, type ProductFields } from './ProductForm.vue';

type Option = { ulid: string; name: string };
type Product = {
    ulid: string; name: string; owner: Option | null; brand: Option | null; category: { id: number; name: string } | null; status: string; classification: string;
};

const props = defineProps<{
    products: Product[]; filters: { owner?: string; status?: string; category?: number }; canManage: boolean; statuses: string[];
    competitors: Option[]; categories: CategoryOption[]; brands: BrandOption[]; classifications: string[];
}>();

const filter = reactive({ owner: props.filters.owner ?? '', status: props.filters.status ?? '', category: props.filters.category ? String(props.filters.category) : '' });

function applyFilters(): void {
    router.get('/products', Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '')), { preserveState: true, replace: true });
}

const blank: ProductFields = {
    competitor: '', name: '', brand: '', category: '', status: 'active', launched_on: '', discontinued_on: '', classification: 'internal', description: '',
};
</script>

<template>
    <AppLayout title="Products">
        <form class="mb-4 flex flex-wrap items-end gap-3 text-sm" @submit.prevent="applyFilters">
            <div>
                <label for="filter-owner" class="block font-medium">Owner</label>
                <select id="filter-owner" v-model="filter.owner" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Anyone</option>
                    <option value="own">Ours</option>
                    <option v-for="competitor in props.competitors" :key="competitor.ulid" :value="competitor.ulid">{{ competitor.name }}</option>
                </select>
            </div>
            <div>
                <label for="filter-status" class="block font-medium">Status</label>
                <select id="filter-status" v-model="filter.status" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="status in props.statuses" :key="status" :value="status">{{ status }}</option>
                </select>
            </div>
            <div>
                <label for="filter-category" class="block font-medium">Category</label>
                <select id="filter-category" v-model="filter.category" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="category in props.categories" :key="category.id" :value="String(category.id)">{{ '— '.repeat(category.depth) }}{{ category.name }}</option>
                </select>
            </div>
            <button type="submit" class="rounded border border-slate-300 px-3 py-1">Filter</button>
        </form>

        <table v-if="props.products.length > 0" class="mb-8 w-full max-w-4xl text-left text-sm">
            <caption class="sr-only">Products</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2 pr-4">Owner</th>
                    <th scope="col" class="py-2 pr-4">Brand</th>
                    <th scope="col" class="py-2 pr-4">Category</th>
                    <th scope="col" class="py-2 pr-4">Status</th>
                    <th scope="col" class="py-2">Classification</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="product in props.products" :key="product.ulid" class="border-b border-slate-200">
                    <td class="py-2 pr-4"><Link :href="`/products/${product.ulid}`" class="underline">{{ product.name }}</Link></td>
                    <td class="py-2 pr-4">{{ product.owner?.name ?? 'Ours' }}</td>
                    <td class="py-2 pr-4">{{ product.brand?.name ?? '—' }}</td>
                    <td class="py-2 pr-4">{{ product.category?.name ?? '—' }}</td>
                    <td class="py-2 pr-4">{{ product.status }}</td>
                    <td class="py-2">{{ product.classification }}</td>
                </tr>
            </tbody>
        </table>
        <p v-else class="mb-8 text-sm text-slate-600">No products match.</p>

        <section v-if="props.canManage" aria-labelledby="add-product-heading" class="max-w-2xl">
            <h2 id="add-product-heading" class="mb-2 text-lg font-semibold">Add a product</h2>
            <ProductForm :initial="blank" method="post" url="/products" id-prefix="new" creating :competitors="props.competitors" :brands="props.brands" :categories="props.categories" :statuses="props.statuses" :classifications="props.classifications" submit-label="Add product" />
        </section>
    </AppLayout>
</template>
