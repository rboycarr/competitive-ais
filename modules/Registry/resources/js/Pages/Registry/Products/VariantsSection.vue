<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

export type Variant = { ulid: string; sku: string; gtin: string | null; name: string; packSize: string; packUnit: string; packCount: number; status: string };
type Fields = { sku: string; gtin: string; name: string; pack_size: string; pack_unit: string; pack_count: number; status: string };

const props = defineProps<{ productUlid: string; variants: Variant[]; units: string[]; statuses: string[]; canManage: boolean }>();

// "150.0000" → "150", "1.2500" → "1.25"
function size(variant: Variant): string {
    return variant.packSize.replace(/\.?0+$/, '');
}

const blank: Fields = { sku: '', gtin: '', name: '', pack_size: '', pack_unit: props.units[0] ?? 'g', pack_count: 1, status: 'active' };
const form = useForm<Fields>({ ...blank });
const editing = ref<string | null>(null);

function start(variant: Variant | null): void {
    Object.assign(form, variant === null ? blank : {
        sku: variant.sku, gtin: variant.gtin ?? '', name: variant.name, pack_size: size(variant), pack_unit: variant.packUnit, pack_count: variant.packCount, status: variant.status,
    });
    form.clearErrors();
    editing.value = variant?.ulid ?? 'new';
}

function save(): void {
    const done = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    if (editing.value === 'new') form.post(`/products/${props.productUlid}/variants`, done);
    else form.put(`/products/${props.productUlid}/variants/${editing.value}`, done);
}
</script>

<template>
    <section aria-labelledby="variants-heading" class="mb-6 max-w-3xl">
        <h2 id="variants-heading" class="mb-2 text-lg font-semibold">Variants</h2>
        <table v-if="props.variants.length > 0" class="mb-3 w-full text-left text-sm">
            <caption class="sr-only">Variants</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">SKU</th>
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2 pr-4">Pack</th>
                    <th scope="col" class="py-2 pr-4">GTIN</th>
                    <th scope="col" class="py-2 pr-4">Status</th>
                    <th v-if="props.canManage" scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="variant in props.variants" :key="variant.ulid" class="border-b border-slate-200">
                    <td class="py-2 pr-4">{{ variant.sku }}</td>
                    <td class="py-2 pr-4">{{ variant.name }}</td>
                    <td class="py-2 pr-4">{{ size(variant) }} {{ variant.packUnit }} × {{ variant.packCount }}</td>
                    <td class="py-2 pr-4">{{ variant.gtin ?? '—' }}</td>
                    <td class="py-2 pr-4">{{ variant.status }}</td>
                    <td v-if="props.canManage" class="py-2"><button type="button" class="underline" :aria-label="`Edit variant ${variant.sku}`" @click="start(variant)">Edit</button></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="mb-3 text-sm text-slate-600">No variants recorded.</p>

        <template v-if="props.canManage">
            <button v-if="editing === null" type="button" class="rounded border border-slate-300 px-3 py-1 text-sm" @click="start(null)">Add a variant</button>
            <form v-else class="grid gap-2 text-sm sm:grid-cols-3" novalidate @submit.prevent="save">
                <div>
                    <label for="variant-sku" class="block font-medium">SKU</label>
                    <input id="variant-sku" v-model="form.sku" type="text" required maxlength="100" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div class="sm:col-span-2">
                    <label for="variant-name" class="block font-medium">Name</label>
                    <input id="variant-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="variant-size" class="block font-medium">Pack size</label>
                    <input id="variant-size" v-model="form.pack_size" type="text" inputmode="decimal" required maxlength="20" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="variant-unit" class="block font-medium">Unit</label>
                    <select id="variant-unit" v-model="form.pack_unit" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option v-for="unit in props.units" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                </div>
                <div>
                    <label for="variant-count" class="block font-medium">Items per pack</label>
                    <input id="variant-count" v-model.number="form.pack_count" type="number" min="1" max="10000" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="variant-gtin" class="block font-medium">GTIN (barcode)</label>
                    <input id="variant-gtin" v-model="form.gtin" type="text" inputmode="numeric" maxlength="20" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="variant-status" class="block font-medium">Status</label>
                    <select id="variant-status" v-model="form.status" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option v-for="status in props.statuses" :key="status" :value="status">{{ status }}</option>
                    </select>
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" class="text-red-700 sm:col-span-3" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <div class="flex gap-2 sm:col-span-3">
                    <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">{{ editing === 'new' ? 'Add variant' : 'Save variant' }}</button>
                    <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                </div>
            </form>
        </template>
    </section>
</template>
