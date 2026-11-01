<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CompetitorForm, { type CompetitorFields } from './CompetitorForm.vue';

type Option = { ulid: string; name: string };
type Row = { ulid: string; name: string; lifecycle: string; tier: string | null; hqCountry: string | null; classification: string; analyst: string | null };

const props = defineProps<{
    competitors: Row[]; filters: { lifecycle?: string; tier?: string; analyst?: string };
    canManage: boolean; tiers: string[]; analysts: Option[]; classifications: string[];
}>();

const lifecycles = ['active', 'watch', 'dormant', 'archived'];
const filter = reactive({ lifecycle: props.filters.lifecycle ?? '', tier: props.filters.tier ?? '', analyst: props.filters.analyst ?? '' });
const blank: CompetitorFields = {
    name: '', legal_name: '', website: '', hq_country: '', tier: '', analyst: '', classification: props.classifications.includes('internal') ? 'internal' : (props.classifications[0] ?? ''), description: '',
};

function applyFilters(): void {
    const query = Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== ''));
    router.get('/competitors', query, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Competitors">
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label for="filter-lifecycle" class="block text-sm font-medium">Lifecycle</label>
                <select id="filter-lifecycle" v-model="filter.lifecycle" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Not archived</option>
                    <option v-for="lifecycle in lifecycles" :key="lifecycle" :value="lifecycle">{{ lifecycle }}</option>
                </select>
            </div>
            <div>
                <label for="filter-tier" class="block text-sm font-medium">Tier</label>
                <select id="filter-tier" v-model="filter.tier" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="tier in props.tiers" :key="tier" :value="tier">{{ tier }}</option>
                </select>
            </div>
            <div>
                <label for="filter-analyst" class="block text-sm font-medium">Analyst</label>
                <select id="filter-analyst" v-model="filter.analyst" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Anyone</option>
                    <option v-for="analyst in props.analysts" :key="analyst.ulid" :value="analyst.ulid">{{ analyst.name }}</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <p v-if="props.competitors.length === 0" class="mb-8 text-sm text-slate-700">No competitors match.</p>
        <div v-else class="mb-8 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Name</th>
                        <th scope="col" class="py-1 pr-3">Lifecycle</th>
                        <th scope="col" class="py-1 pr-3">Tier</th>
                        <th scope="col" class="py-1 pr-3">HQ</th>
                        <th scope="col" class="py-1 pr-3">Analyst</th>
                        <th scope="col" class="py-1">Classification</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="competitor in props.competitors" :key="competitor.ulid" class="border-b border-slate-100">
                        <td class="py-1 pr-3"><Link :href="`/competitors/${competitor.ulid}`" class="underline">{{ competitor.name }}</Link></td>
                        <td class="py-1 pr-3">{{ competitor.lifecycle }}</td>
                        <td class="py-1 pr-3">{{ competitor.tier ?? '—' }}</td>
                        <td class="py-1 pr-3">{{ competitor.hqCountry ?? '—' }}</td>
                        <td class="py-1 pr-3">{{ competitor.analyst ?? '—' }}</td>
                        <td class="py-1">{{ competitor.classification }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-if="props.canManage" aria-labelledby="new-competitor-heading" class="max-w-2xl">
            <h2 id="new-competitor-heading" class="mb-2 text-lg font-semibold">Register a competitor</h2>
            <CompetitorForm :initial="blank" method="post" url="/competitors" id-prefix="new" :tiers="props.tiers" :analysts="props.analysts" :classifications="props.classifications" submit-label="Register" />
        </section>
    </AppLayout>
</template>
