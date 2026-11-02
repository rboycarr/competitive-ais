<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Market = { ulid: string; code: string; name: string; country: string; currency: string };
// Depth first, siblings by name (the server orders them).
type Region = { id: number; name: string; level: string; depth: number };

const props = defineProps<{ market: Market; regions: Region[]; levels: string[]; currencies: { code: string; name: string }[] }>();
// A refused removal (subregions left) comes back as a page error.
const page = usePage<{ errors?: Record<string, string> }>();
const removeError = computed(() => page.props.errors?.region ?? null);

const details = useForm({ name: props.market.name, country: props.market.country, currency: props.market.currency });

function saveDetails(): void {
    details.put(`/admin/markets/${props.market.ulid}`, { preserveScroll: true });
}

// A child must be at a deeper level than its parent; the server and the database check the same.
const rank = (level: string): number => props.levels.indexOf(level);
const form = useForm<{ name: string; level: string; parent: number | '' }>({ name: '', level: props.levels[0] ?? '', parent: '' });
const parents = computed(() => props.regions.filter((r) => rank(r.level) < props.levels.length - 1));
const allowedLevels = computed(() => {
    const parent = props.regions.find((r) => r.id === form.parent);

    return parent === undefined ? props.levels : props.levels.filter((level) => rank(level) > rank(parent.level));
});

function add(): void {
    if (!allowedLevels.value.includes(form.level)) form.level = allowedLevels.value[0] ?? '';
    form.transform((data) => ({ name: data.name, level: data.level, parent: data.parent === '' ? null : data.parent }))
        .post(`/admin/markets/${props.market.ulid}/regions`, { preserveScroll: true, onSuccess: () => form.reset('name') });
}

const renaming = ref<number | null>(null);
const rename = useForm({ name: '' });

function startRename(region: Region): void {
    rename.name = region.name;
    rename.clearErrors();
    renaming.value = region.id;
}

function saveName(region: Region): void {
    rename.put(`/admin/markets/${props.market.ulid}/regions/${region.id}`, { preserveScroll: true, onSuccess: () => { renaming.value = null; } });
}

function remove(region: Region): void {
    if (window.confirm(`Remove the region “${region.name}”?`)) {
        router.delete(`/admin/markets/${props.market.ulid}/regions/${region.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout :title="`${props.market.code} · ${props.market.name}`">
        <p class="mb-4 text-sm"><Link href="/admin/markets" class="underline">All markets</Link></p>

        <section aria-labelledby="details-heading" class="mb-6 max-w-2xl">
            <h2 id="details-heading" class="mb-2 text-lg font-semibold">Details</h2>
            <form class="grid gap-2 text-sm sm:grid-cols-3" novalidate @submit.prevent="saveDetails">
                <div>
                    <label for="details-name" class="block font-medium">Name</label>
                    <input id="details-name" v-model="details.name" type="text" required maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="details-country" class="block font-medium">Country (ISO code)</label>
                    <input id="details-country" v-model="details.country" type="text" required maxlength="2" class="mt-1 w-24 rounded border border-slate-300 px-2 py-1 uppercase">
                </div>
                <div>
                    <label for="details-currency" class="block font-medium">Default currency</label>
                    <select id="details-currency" v-model="details.currency" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        <option v-for="currency in props.currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.name }}</option>
                    </select>
                </div>
                <ul v-if="Object.keys(details.errors).length > 0" class="text-red-700 sm:col-span-3" role="alert">
                    <li v-for="(message, field) in details.errors" :key="field">{{ message }}</li>
                </ul>
                <div class="sm:col-span-3">
                    <button type="submit" :disabled="details.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save details</button>
                </div>
            </form>
        </section>

        <section aria-labelledby="regions-heading" class="mb-6 max-w-2xl">
            <h2 id="regions-heading" class="mb-2 text-lg font-semibold">Regions</h2>
            <p v-if="removeError" class="mb-2 text-sm text-red-700" role="alert">{{ removeError }}</p>
            <ul v-if="props.regions.length > 0" class="space-y-1 text-sm">
                <li v-for="region in props.regions" :key="region.id" :style="{ paddingLeft: `${region.depth * 1.5}rem` }">
                    <form v-if="renaming === region.id" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="saveName(region)">
                        <label :for="`rename-${region.id}`" class="sr-only">New name for {{ region.name }}</label>
                        <input :id="`rename-${region.id}`" v-model="rename.name" type="text" required maxlength="255" class="rounded border border-slate-300 px-2 py-1">
                        <button type="submit" :disabled="rename.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Save</button>
                        <button type="button" class="rounded border border-slate-300 px-3 py-1" @click="renaming = null">Cancel</button>
                        <p v-if="rename.errors.name" class="w-full text-red-700" role="alert">{{ rename.errors.name }}</p>
                    </form>
                    <div v-else class="flex items-center gap-3">
                        <span>{{ region.name }}</span>
                        <span class="text-slate-600">{{ region.level }}</span>
                        <button type="button" class="underline" :aria-label="`Rename region ${region.name}`" @click="startRename(region)">Rename</button>
                        <button type="button" class="underline" :aria-label="`Remove region ${region.name}`" @click="remove(region)">Remove</button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-slate-600">No regions yet.</p>
        </section>

        <section aria-labelledby="add-region-heading" class="max-w-2xl">
            <h2 id="add-region-heading" class="mb-2 text-lg font-semibold">Add a region</h2>
            <form class="flex flex-wrap items-end gap-2 text-sm" novalidate @submit.prevent="add">
                <div>
                    <label for="region-name" class="block font-medium">Name</label>
                    <input id="region-name" v-model="form.name" type="text" required maxlength="255" class="mt-1 rounded border border-slate-300 px-2 py-1">
                </div>
                <div>
                    <label for="region-parent" class="block font-medium">Part of</label>
                    <select id="region-parent" v-model="form.parent" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option value="">The whole market</option>
                        <option v-for="parent in parents" :key="parent.id" :value="parent.id">{{ '— '.repeat(parent.depth) }}{{ parent.name }} ({{ parent.level }})</option>
                    </select>
                </div>
                <div>
                    <label for="region-level" class="block font-medium">Level</label>
                    <select id="region-level" v-model="form.level" class="mt-1 rounded border border-slate-300 px-2 py-1">
                        <option v-for="level in allowedLevels" :key="level" :value="level">{{ level }}</option>
                    </select>
                </div>
                <button type="submit" :disabled="form.processing" class="rounded border border-slate-300 px-3 py-1 disabled:opacity-50">Add region</button>
                <ul v-if="Object.keys(form.errors).length > 0" class="w-full text-red-700" role="alert">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </form>
        </section>
    </AppLayout>
</template>
