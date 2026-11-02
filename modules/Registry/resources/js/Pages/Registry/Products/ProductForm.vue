<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

export type ProductFields = {
    competitor: string; name: string; brand: string; category: string; status: string; launched_on: string; discontinued_on: string;
    classification: string; description: string;
};
export type BrandOption = { ulid: string; name: string; owner: string | null };
export type CategoryOption = { id: number; name: string; depth: number };
type Option = { ulid: string; name: string };

// The form owns its state; the page passes the starting values and where to send them. The owner is chosen only
// when creating; brands are offered for the chosen owner only (the server checks the same).
const props = defineProps<{
    initial: ProductFields; method: 'post' | 'put'; url: string; idPrefix: string; creating: boolean; competitors: Option[]; brands: BrandOption[];
    categories: CategoryOption[]; statuses: string[]; classifications: string[]; submitLabel: string;
}>();
const emit = defineEmits<{ saved: [] }>();
const form = useForm<ProductFields>({ ...props.initial });
const ownerBrands = computed(() => props.brands.filter((b) => (b.owner ?? '') === form.competitor));

function submit(): void {
    if (!ownerBrands.value.some((b) => b.ulid === form.brand)) form.brand = '';
    form.transform((data) => (props.creating ? data : { ...data, competitor: undefined }))
        .submit(props.method, props.url, { preserveScroll: true, onSuccess: () => emit('saved') });
}
</script>

<template>
    <form class="space-y-3" novalidate @submit.prevent="submit">
        <div v-if="creating">
            <label :for="`${idPrefix}-owner`" class="block font-medium">Owner</label>
            <select :id="`${idPrefix}-owner`" v-model="form.competitor" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <option value="">Our own product</option>
                <option v-for="competitor in competitors" :key="competitor.ulid" :value="competitor.ulid">{{ competitor.name }}</option>
            </select>
        </div>
        <div>
            <label :for="`${idPrefix}-name`" class="block font-medium">Name</label>
            <input :id="`${idPrefix}-name`" v-model="form.name" type="text" required maxlength="255" :aria-invalid="form.errors.name ? 'true' : undefined" :aria-describedby="`${idPrefix}-errors`" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label :for="`${idPrefix}-brand`" class="block font-medium">Brand</label>
                <select :id="`${idPrefix}-brand`" v-model="form.brand" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option value="">No brand</option>
                    <option v-for="brand in ownerBrands" :key="brand.ulid" :value="brand.ulid">{{ brand.name }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-category`" class="block font-medium">Category</label>
                <select :id="`${idPrefix}-category`" v-model="form.category" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option value="">No category</option>
                    <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ '— '.repeat(category.depth) }}{{ category.name }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-status`" class="block font-medium">Status</label>
                <select :id="`${idPrefix}-status`" v-model="form.status" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-classification`" class="block font-medium">Classification</label>
                <select :id="`${idPrefix}-classification`" v-model="form.classification" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option v-for="classification in classifications" :key="classification" :value="classification">{{ classification }}</option>
                </select>
            </div>
            <div>
                <label :for="`${idPrefix}-launched`" class="block font-medium">Launched on</label>
                <input :id="`${idPrefix}-launched`" v-model="form.launched_on" type="date" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label :for="`${idPrefix}-discontinued`" class="block font-medium">Discontinued on</label>
                <input :id="`${idPrefix}-discontinued`" v-model="form.discontinued_on" type="date" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
        </div>
        <div>
            <label :for="`${idPrefix}-description`" class="block font-medium">Description</label>
            <textarea :id="`${idPrefix}-description`" v-model="form.description" maxlength="10000" rows="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" />
        </div>
        <ul v-if="Object.keys(form.errors).length > 0" :id="`${idPrefix}-errors`" class="text-sm text-red-700" role="alert">
            <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
        <button type="submit" :disabled="form.processing" class="rounded bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">{{ submitLabel }}</button>
    </form>
</template>
