<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// markets: how many markets use it as their default currency; its decimal places are then fixed (the server checks).
type Currency = { code: string; name: string; minorUnits: number; markets: number };

const props = defineProps<{ currencies: Currency[] }>();
const form = useForm({ code: '', name: '', minor_units: 2 });

function add(): void {
    form.post('/admin/currencies', { preserveScroll: true, onSuccess: () => form.reset() });
}

const editing = ref<string | null>(null);
const edit = useForm({ name: '', minor_units: 0 });

function startEdit(currency: Currency): void {
    edit.name = currency.name;
    edit.minor_units = currency.minorUnits;
    edit.clearErrors();
    editing.value = currency.code;
}

function save(currency: Currency): void {
    edit.put(`/admin/currencies/${currency.code}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}
</script>

<template>
    <AppLayout title="Currencies">
        <table class="mb-8 w-full max-w-3xl text-left text-sm">
            <caption class="sr-only">Currencies</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">Code</th>
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2 pr-4">Decimal places</th>
                    <th scope="col" class="py-2 pr-4">Markets</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="currency in props.currencies" :key="currency.code" class="border-b border-slate-200 align-top">
                    <td class="py-2 pr-4">{{ currency.code }}</td>
                    <template v-if="editing === currency.code">
                        <td class="py-2 pr-4">
                            <label :for="`edit-name-${currency.code}`" class="sr-only">Name of {{ currency.code }}</label>
                            <input :id="`edit-name-${currency.code}`" v-model="edit.name" type="text" required maxlength="100" class="w-full rounded border border-slate-300 px-2 py-1">
                        </td>
                        <td class="py-2 pr-4">
                            <label :for="`edit-units-${currency.code}`" class="sr-only">Decimal places of {{ currency.code }}</label>
                            <input :id="`edit-units-${currency.code}`" v-model.number="edit.minor_units" type="number" min="0" max="4" :disabled="currency.markets > 0" class="w-16 rounded border border-slate-300 px-2 py-1 disabled:bg-slate-100">
                        </td>
                        <td class="py-2 pr-4">{{ currency.markets }}</td>
                        <td class="py-2">
                            <form class="flex flex-wrap gap-2" novalidate @submit.prevent="save(currency)">
                                <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                                <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                            </form>
                            <ul v-if="Object.keys(edit.errors).length > 0" class="mt-1 text-red-700" role="alert">
                                <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                            </ul>
                        </td>
                    </template>
                    <template v-else>
                        <td class="py-2 pr-4">{{ currency.name }}</td>
                        <td class="py-2 pr-4">{{ currency.minorUnits }}</td>
                        <td class="py-2 pr-4">{{ currency.markets }}</td>
                        <td class="py-2"><button type="button" class="underline" :aria-label="`Edit currency ${currency.code}`" @click="startEdit(currency)">Edit</button></td>
                    </template>
                </tr>
            </tbody>
        </table>

        <section aria-labelledby="add-currency-heading" class="max-w-2xl">
            <h2 id="add-currency-heading" class="mb-2 text-lg font-semibold">Add a currency</h2>
            <p class="mb-2 text-sm text-slate-600">Use the ISO 4217 code. The code is fixed once saved, and currencies are never deleted.</p>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="currency-code" class="block font-medium">Code</label>
                    <input id="currency-code" v-model="form.code" type="text" required maxlength="3" autocapitalize="characters" class="mt-1 w-20 rounded border border-slate-300 px-2 py-1 uppercase">
                </div>
                <div>
                    <label for="currency-name" class="block font-medium">Name</label>
                    <input id="currency-name" v-model="form.name" type="text" required maxlength="100" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="currency-units" class="block font-medium">Decimal places</label>
                    <input id="currency-units" v-model.number="form.minor_units" type="number" min="0" max="4" required class="mt-1 w-16 rounded border border-slate-300 px-2 py-1">
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add currency</button>
                <ul v-if="Object.keys(form.errors).length > 0" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
