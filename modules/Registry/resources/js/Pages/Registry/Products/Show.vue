<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProductForm, { type BrandOption, type CategoryOption, type ProductFields } from './ProductForm.vue';
import VariantsSection, { type Variant } from './VariantsSection.vue';

type Option = { ulid: string; name: string };
type Product = {
    ulid: string; name: string; owner: Option | null; brand: Option | null; category: { id: number; name: string } | null; status: string;
    launchedOn: string | null; discontinuedOn: string | null; classification: string; description: string | null;
};

const props = defineProps<{
    product: Product; variants: Variant[]; units: string[]; variantStatuses: string[]; canManage: boolean; statuses: string[];
    competitors: Option[]; categories: CategoryOption[]; brands: BrandOption[]; classifications: string[];
}>();

const editing = ref(false);
const current = computed<ProductFields>(() => ({
    competitor: props.product.owner?.ulid ?? '', name: props.product.name, brand: props.product.brand?.ulid ?? '',
    category: props.product.category ? String(props.product.category.id) : '', status: props.product.status, launched_on: props.product.launchedOn ?? '',
    discontinued_on: props.product.discontinuedOn ?? '', classification: props.product.classification, description: props.product.description ?? '',
}));
</script>

<template>
    <AppLayout :title="props.product.name">
        <p class="mb-4 text-sm"><Link href="/products" class="underline">All products</Link></p>

        <dl class="mb-6 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="font-medium">Owner</dt>
            <dd>
                <Link v-if="props.product.owner" :href="`/competitors/${props.product.owner.ulid}`" class="underline">{{ props.product.owner.name }}</Link>
                <template v-else>Ours</template>
            </dd>
            <dt class="font-medium">Brand</dt><dd>{{ props.product.brand?.name ?? '—' }}</dd>
            <dt class="font-medium">Category</dt><dd>{{ props.product.category?.name ?? '—' }}</dd>
            <dt class="font-medium">Status</dt><dd>{{ props.product.status }}</dd>
            <dt class="font-medium">Launched on</dt><dd>{{ props.product.launchedOn ?? '—' }}</dd>
            <dt class="font-medium">Discontinued on</dt><dd>{{ props.product.discontinuedOn ?? '—' }}</dd>
            <dt class="font-medium">Classification</dt><dd>{{ props.product.classification }}</dd>
            <dt class="font-medium">Description</dt><dd class="whitespace-pre-line">{{ props.product.description ?? '—' }}</dd>
        </dl>

        <VariantsSection :product-ulid="props.product.ulid" :variants="props.variants" :units="props.units" :statuses="props.variantStatuses" :can-manage="props.canManage" />

        <section v-if="props.canManage" aria-labelledby="edit-heading" class="max-w-2xl">
            <h2 id="edit-heading" class="mb-2 text-lg font-semibold">Product</h2>
            <button v-if="!editing" type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = true">Edit product</button>
            <ProductForm v-else :initial="current" method="put" :url="`/products/${props.product.ulid}`" id-prefix="edit" :creating="false" :competitors="props.competitors" :brands="props.brands" :categories="props.categories" :statuses="props.statuses" :classifications="props.classifications" submit-label="Save changes" @saved="editing = false" />
        </section>
    </AppLayout>
</template>
