<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Market = { ulid: string; code: string; name: string; country: string; currency: string; regions: number };
type Currency = { code: string; name: string };

const props = defineProps<{ markets: Market[]; currencies: Currency[] }>();
const form = useForm({ code: '', name: '', country: '', currency: '' });

function add(): void {
    form.post('/admin/markets', { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Markets">
        <table v-if="props.markets.length > 0" class="mb-8 w-full max-w-3xl text-left text-sm">
            <caption class="sr-only">Markets</caption>
            <thead>
                <tr class="border-b border-slate-300">
                    <th scope="col" class="py-2 pr-4">Code</th>
                    <th scope="col" class="py-2 pr-4">Name</th>
                    <th scope="col" class="py-2 pr-4">Country</th>
                    <th scope="col" class="py-2 pr-4">Currency</th>
                    <th scope="col" class="py-2">Regions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="market in props.markets" :key="market.ulid" class="border-b border-slate-200">
                    <td class="py-2 pr-4"><Link :href="`/admin/markets/${market.ulid}`" class="underline">{{ market.code }}</Link></td>
                    <td class="py-2 pr-4">{{ market.name }}</td>
                    <td class="py-2 pr-4">{{ market.country }}</td>
                    <td class="py-2 pr-4">{{ market.currency }}</td>
                    <td class="py-2">{{ market.regions }}</td>
                </tr>
            </tbody>
        </table>
        <p v-else class="mb-8 text-sm text-slate-600">No markets yet.</p>

        <section aria-labelledby="add-market-heading" class="max-w-2xl">
            <h2 id="add-market-heading" class="mb-2 text-lg font-semibold">Add a market</h2>
            <form class="grid gap-2 text-sm sm:grid-cols-2" novalidate @submit.prevent="add">
                <div>
                    <label for="market-code" class="block font-medium">Code (fixed once saved)</label>
                    <input id="market-code" v-model="form.code" type="text" required maxlength="20" autocapitalize="characters" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 uppercase">
                </div>
                <div>
                    <label for="market-name" class="block font-medium">Name</label>
                    <input id="market-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="market-country" class="block font-medium">Country (ISO code)</label>
                    <input id="market-country" v-model="form.country" type="text" required maxlength="2" autocapitalize="characters" class="mt-1 w-24 rounded border border-slate-300 px-2 py-1 uppercase">
                </div>
                <div>
                    <label for="market-currency" class="block font-medium">Default currency</label>
                    <select id="market-currency" v-model="form.currency" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option value="" disabled>Choose…</option>
                        <option v-for="currency in props.currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.name }}</option>
                    </select>
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" class="text-red-700 sm:col-span-2" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <div class="sm:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add market</button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
