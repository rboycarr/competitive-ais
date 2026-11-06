<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ageLabel, statusLabel } from './labels';

type Row = {
    ulid: string; title: string; status: string; classification: string; highImpact: boolean; competitors: string[];
    reviewer: string | null; mine: boolean; submittedAt: string | null;
};

const props = defineProps<{
    items: Row[]; nextPage: string | null;
    filters: { assigned?: string; status?: string; classification?: string; competitor?: string; high_impact?: string };
    statuses: string[]; filterCompetitors: { ulid: string; name: string }[];
}>();

const filter = reactive({
    assigned: props.filters.assigned ?? '', status: props.filters.status ?? '', classification: props.filters.classification ?? '',
    competitor: props.filters.competitor ?? '', high_impact: props.filters.high_impact ?? '',
});

function applyFilters(): void {
    router.get('/verification', Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== '')), { preserveScroll: true });
}

const now = new Date();
</script>

<template>
    <AppLayout title="Verification queue">
        <p class="mb-3 max-w-3xl text-sm text-slate-700">Items waiting for a reviewer or under review, oldest submission first. Only the assigned reviewer decides; a director assigns and reassigns.</p>
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label for="filter-assigned" class="block text-sm font-medium">Assigned</label>
                <select id="filter-assigned" v-model="filter.assigned" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Anyone</option>
                    <option value="mine">To me</option>
                    <option value="unassigned">Nobody yet</option>
                </select>
            </div>
            <div>
                <label for="filter-status" class="block text-sm font-medium">Status</label>
                <select id="filter-status" v-model="filter.status" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="status in props.statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
                </select>
            </div>
            <div>
                <label for="filter-classification" class="block text-sm font-medium">Classification</label>
                <select id="filter-classification" v-model="filter.classification" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="level in ['public', 'internal', 'confidential', 'restricted']" :key="level" :value="level">{{ level }}</option>
                </select>
            </div>
            <div>
                <label for="filter-competitor" class="block text-sm font-medium">Competitor</label>
                <select id="filter-competitor" v-model="filter.competitor" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option v-for="competitor in props.filterCompetitors" :key="competitor.ulid" :value="competitor.ulid">{{ competitor.name }}</option>
                </select>
            </div>
            <div>
                <label for="filter-impact" class="block text-sm font-medium">Impact</label>
                <select id="filter-impact" v-model="filter.high_impact" class="mt-1 rounded border border-slate-300 px-2 py-1">
                    <option value="">Any</option>
                    <option value="1">High impact</option>
                    <option value="0">Not high impact</option>
                </select>
            </div>
            <button type="submit" class="rounded bg-slate-900 px-4 py-1.5 text-white">Filter</button>
        </form>

        <p v-if="props.items.length === 0" class="text-sm text-slate-700">Nothing is waiting for review.</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-300">
                        <th scope="col" class="py-1 pr-3">Title</th>
                        <th scope="col" class="py-1 pr-3">Status</th>
                        <th scope="col" class="py-1 pr-3">Reviewer</th>
                        <th scope="col" class="py-1 pr-3">Waiting</th>
                        <th scope="col" class="py-1 pr-3">Competitors</th>
                        <th scope="col" class="py-1">Classification</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in props.items" :key="item.ulid" class="border-b border-slate-100">
                        <td class="py-1 pr-3">
                            <Link :href="`/intelligence/${item.ulid}`" class="underline">{{ item.title }}</Link>
                            <span v-if="item.highImpact" class="ml-2 rounded border border-amber-700 px-1 text-xs text-amber-800">High impact</span>
                        </td>
                        <td class="py-1 pr-3">{{ statusLabel(item.status) }}</td>
                        <td class="py-1 pr-3">{{ item.mine ? 'You' : (item.reviewer ?? 'Not assigned') }}</td>
                        <td class="py-1 pr-3">
                            <time v-if="item.submittedAt" :datetime="item.submittedAt" :title="new Date(item.submittedAt).toLocaleString()">{{ ageLabel(item.submittedAt, now) }}</time>
                            <template v-else>—</template>
                        </td>
                        <td class="py-1 pr-3">{{ item.competitors.join(', ') || '—' }}</td>
                        <td class="py-1">{{ item.classification }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="props.nextPage" class="mt-2"><Link :href="props.nextPage" class="underline">More</Link></p>
        </div>
    </AppLayout>
</template>
