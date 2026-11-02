<script setup lang="ts">
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// market: the market's code; channel: the channel's ID.
type Retailer = { ulid: string; name: string; website: string | null; market: string | null; channel: number };
type Market = { ulid: string; code: string; name: string };
type Channel = { id: number; name: string };

const props = defineProps<{ retailers: Retailer[]; markets: Market[]; channels: Channel[]; filters: { market?: string; channel?: string } }>();

const channelName = (id: number): string => props.channels.find((c) => c.id === id)?.name ?? '—';
// The server accepts only http(s) addresses; checked again here so no other scheme becomes a link.
const safeWebsite = (website: string | null): string | null => (website !== null && /^https?:\/\//i.test(website) ? website : null);

const filter = reactive({ market: props.filters.market ?? '', channel: props.filters.channel ?? '' });

function applyFilters(): void {
    router.get('/admin/retailers', Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '')), { preserveScroll: true });
}

const form = useForm<{ market: string; channel: number | ''; name: string; website: string }>({ market: props.filters.market ?? '', channel: '', name: '', website: '' });

function add(): void {
    form.post('/admin/retailers', { preserveScroll: true, onSuccess: () => form.reset('name', 'website') });
}

const editing = ref<string | null>(null);
const edit = useForm<{ name: string; channel: number | ''; website: string }>({ name: '', channel: '', website: '' });

function startEdit(retailer: Retailer): void {
    edit.name = retailer.name;
    edit.channel = retailer.channel;
    edit.website = retailer.website ?? '';
    edit.clearErrors();
    editing.value = retailer.ulid;
}

function save(retailer: Retailer): void {
    edit.put(`/admin/retailers/${retailer.ulid}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

function remove(retailer: Retailer): void {
    if (window.confirm(`Remove the retailer “${retailer.name}”?`)) {
        router.delete(`/admin/retailers/${retailer.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Retailers">
        <form class="mb-4 flex flex-wrap items-end gap-3 text-sm" @submit.prevent="applyFilters">
            <div>
                <label for="filter-market" class="block font-medium">Market</label>
                <select id="filter-market" v-model="filter.market" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="market in props.markets" :key="market.ulid" :value="market.ulid">{{ market.code }} · {{ market.name }}</option>
                </select>
            </div>
            <div>
                <label for="filter-channel" class="block font-medium">Channel</label>
                <select id="filter-channel" v-model="filter.channel" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="channel in props.channels" :key="channel.id" :value="String(channel.id)">{{ channel.name }}</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <div v-if="props.retailers.length > 0" class="mb-8 overflow-x-auto">
            <table class="w-full max-w-4xl text-left text-sm">
                <caption class="sr-only">Retailers</caption>
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-2 pr-4">Name</th>
                        <th scope="col" class="py-2 pr-4">Market</th>
                        <th scope="col" class="py-2 pr-4">Channel</th>
                        <th scope="col" class="py-2 pr-4">Website</th>
                        <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="retailer in props.retailers" :key="retailer.ulid" class="border-b border-slate-200 align-top">
                        <template v-if="editing === retailer.ulid">
                            <td class="py-2 pr-4">
                                <label :for="`edit-name-${retailer.ulid}`" class="sr-only">New name for {{ retailer.name }}</label>
                                <input :id="`edit-name-${retailer.ulid}`" v-model="edit.name" type="text" required maxlength="255" class="w-full rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="py-2 pr-4">{{ retailer.market ?? '—' }}</td>
                            <td class="py-2 pr-4">
                                <label :for="`edit-channel-${retailer.ulid}`" class="sr-only">Channel of {{ retailer.name }}</label>
                                <select :id="`edit-channel-${retailer.ulid}`" v-model="edit.channel" class="rounded border border-slate-300 px-2 py-1">
                                    <option v-for="channel in props.channels" :key="channel.id" :value="channel.id">{{ channel.name }}</option>
                                </select>
                            </td>
                            <td class="py-2 pr-4">
                                <label :for="`edit-website-${retailer.ulid}`" class="sr-only">Website of {{ retailer.name }}</label>
                                <input :id="`edit-website-${retailer.ulid}`" v-model="edit.website" type="url" maxlength="2048" class="w-full rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="py-2">
                                <form class="flex flex-wrap gap-2" novalidate @submit.prevent="save(retailer)">
                                    <button type="submit" :disabled="edit.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                                    <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="editing = null">Cancel</button>
                                </form>
                                <ul v-if="Object.keys(edit.errors).length > 0" class="mt-1 text-red-700" role="alert">
                                    <li v-for="(message, field) in edit.errors" :key="field">{{ message }}</li>
                                </ul>
                            </td>
                        </template>
                        <template v-else>
                            <td class="py-2 pr-4">{{ retailer.name }}</td>
                            <td class="py-2 pr-4">{{ retailer.market ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ channelName(retailer.channel) }}</td>
                            <td class="py-2 pr-4">
                                <a v-if="safeWebsite(retailer.website)" :href="safeWebsite(retailer.website) ?? undefined" rel="noopener noreferrer nofollow" target="_blank" class="break-all underline">{{ retailer.website }}</a>
                                <template v-else>—</template>
                            </td>
                            <td class="flex gap-3 py-2">
                                <button type="button" class="underline" :aria-label="`Edit retailer ${retailer.name}`" @click="startEdit(retailer)">Edit</button>
                                <button type="button" class="underline" :aria-label="`Remove retailer ${retailer.name}`" @click="remove(retailer)">Remove</button>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="mb-8 text-sm text-slate-600">No retailers match.</p>

        <section aria-labelledby="add-retailer-heading" class="max-w-2xl">
            <h2 id="add-retailer-heading" class="mb-2 text-lg font-semibold">Add a retailer</h2>
            <p v-if="props.markets.length === 0 || props.channels.length === 0" class="text-sm text-slate-600">Add a market and a channel first.</p>
            <form v-else class="grid gap-2 text-sm sm:grid-cols-2" novalidate @submit.prevent="add">
                <div>
                    <label for="retailer-market" class="block font-medium">Market (fixed once saved)</label>
                    <select id="retailer-market" v-model="form.market" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option value="" disabled>Choose…</option>
                        <option v-for="market in props.markets" :key="market.ulid" :value="market.ulid">{{ market.code }} · {{ market.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="retailer-channel" class="block font-medium">Channel</label>
                    <select id="retailer-channel" v-model="form.channel" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option value="" disabled>Choose…</option>
                        <option v-for="channel in props.channels" :key="channel.id" :value="channel.id">{{ channel.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="retailer-name" class="block font-medium">Name</label>
                    <input id="retailer-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="retailer-website" class="block font-medium">Website (optional)</label>
                    <input id="retailer-website" v-model="form.website" type="url" maxlength="2048" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <ul v-if="Object.keys(form.errors).length > 0" class="text-red-700 sm:col-span-2" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
                <div class="sm:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add retailer</button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
